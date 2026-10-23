/**
 * VoxNemesis Read-Aloud.
 *
 * The speech is generated in the reader's browser: the Phonon model runs in a WebAssembly
 * worker and the audio is played straight into the page. Nothing is sent to this site, and
 * nothing is sent anywhere else either. The model weights are fetched once and kept in the
 * browser's cache.
 */

import { PhononTTS } from '../vendor/phonon-tts/index.js';

const config = window.voxnemesisReadAloud || {};

function text(key, fallback) {
    return (config.strings && config.strings[key]) || fallback;
}

/** The speed is a conditioner on the model, expressed as a change in duration. */
function durationDelta(speed) {
    const factor = Math.min(2, Math.max(0.5, Number(speed) || 1));
    return Math.min(0.8, Math.max(-0.8, -(factor - 1)));
}

function formatTime(seconds) {
    const total = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** One player. A page can hold several. */
class Reader {
    constructor(root) {
        this.root = root;
        this.panel = root.querySelector('.vnra__panel');
        this.listenButton = root.querySelector('.vnra__listen');
        this.status = root.querySelector('.vnra__status');
        this.playButton = root.querySelector('.vnra__play');
        this.prevButton = root.querySelector('.vnra__prev');
        this.nextButton = root.querySelector('.vnra__next');
        this.stopButton = root.querySelector('.vnra__stop');
        this.seek = root.querySelector('.vnra__seek');
        this.seekCount = root.querySelector('.vnra__seek-count');
        this.timeCurrent = root.querySelector('.vnra__time--current');
        this.timeDuration = root.querySelector('.vnra__time--duration');
        this.voiceSelect = root.querySelector('.vnra__voice');
        this.speedInput = root.querySelector('.vnra__speed');
        this.speedOutput = root.querySelector('.vnra__speed-value');
        this.volumeInput = root.querySelector('.vnra__volume');

        this.paragraphNodes = this.collectParagraphs();
        this.paragraphs = this.paragraphNodes.map((node) => node.textContent.replace(/\s+/g, ' ').trim());

        this.tts = null;
        this.loading = null;
        this.ctx = null;
        this.gain = null;
        this.sources = new Set();

        this.nextStartTime = 0;
        this.startedAt = 0;
        this.elapsed = 0;
        this.duration = 0;

        this.playing = false;
        this.paused = false;

        this.stream = null;
        this.abort = null;
        this.run = 0;
        this.marks = [];
        this.soundingIndex = -1;
        this.currentParagraph = -1;

        this.timer = null;
        this.catalog = null;
        this.voice = config.voice || 'default';
        this.speed = Number(config.speed) || 1;
        this.volume = 1;

        this.bind();
        this.updateSeekBounds();
    }

    /** The paragraphs, in the order they appear in the page. */
    collectParagraphs() {
        const nodes = Array.from(root_document().querySelectorAll('[data-vnra-para]'));
        return nodes.sort((a, b) => {
            const ia = Number(a.getAttribute('data-vnra-para'));
            const ib = Number(b.getAttribute('data-vnra-para'));
            return (Number.isFinite(ia) ? ia : 0) - (Number.isFinite(ib) ? ib : 0);
        });
    }

    bind() {
        this.listenButton.addEventListener('click', () => this.toggle());
        this.playButton.addEventListener('click', () => this.togglePlayback());
        this.stopButton.addEventListener('click', () => this.stop(true));

        if (this.prevButton) {
            this.prevButton.addEventListener('click', () => this.previous());
        }
        if (this.nextButton) {
            this.nextButton.addEventListener('click', () => this.next());
        }

        if (this.seek) {
            this.seek.addEventListener('input', () => {
                this.showCount(Number(this.seek.value));
            });
            this.seek.addEventListener('change', () => {
                const index = Number(this.seek.value);
                if (Number.isFinite(index)) {
                    this.playFrom(index);
                }
            });
        }

        if (this.speedInput) {
            this.speedInput.addEventListener('input', () => {
                this.speed = Number(this.speedInput.value);
                if (this.speedOutput) {
                    this.speedOutput.textContent = this.speed.toFixed(2);
                }
            });
            // The speaking rate is a conditioner on the model, so changing it means loading
            // the model again. The weights are already cached, so that is quick.
            this.speedInput.addEventListener('change', () => this.reloadModel());
        }

        if (this.volumeInput) {
            this.volumeInput.addEventListener('input', () => {
                this.volume = Number(this.volumeInput.value);
                if (this.gain) {
                    this.gain.gain.value = this.volume;
                }
            });
        }

        if (this.voiceSelect) {
            this.voiceSelect.addEventListener('change', () => {
                this.voice = this.voiceSelect.value;
                this.reloadModel();
            });
        }

        this.paragraphNodes.forEach((node, index) => {
            node.classList.add('vnra-para');
            node.addEventListener('click', (event) => {
                // A link is a link, and selecting text is selecting text. Neither should
                // start the reader.
                if (event.target.closest('a, button, input, select, textarea')) {
                    return;
                }
                const selection = window.getSelection();
                if (selection && String(selection).length > 0) {
                    return;
                }
                this.playFrom(index);
            });
        });
    }

    showCount(index) {
        if (!this.seekCount) {
            return;
        }
        const total = this.paragraphs.length;
        if (!total) {
            this.seekCount.textContent = '';
            return;
        }
        this.seekCount.textContent = String(
            text('paragraph', 'Paragraph %1$s of %2$s')
                .replace('%1$s', String(index + 1))
                .replace('%2$s', String(total))
        );
    }

    updateSeekBounds() {
        if (!this.seek) {
            return;
        }
        const max = Math.max(0, this.paragraphs.length - 1);
        this.seek.max = String(max);
        this.seek.disabled = this.paragraphs.length < 2;
        this.seek.value = '0';
        this.showCount(0);
    }

    say(message, isError = false) {
        if (!this.status) {
            return;
        }
        this.status.textContent = message;
        this.status.classList.toggle('vnra__status--error', Boolean(isError));
    }

    setState() {
        this.root.dataset.playing = this.playing ? 'true' : 'false';
        if (this.playButton) {
            this.playButton.setAttribute('aria-label', this.playing ? text('pause', 'Pause') : text('play', 'Play'));
        }
    }

    /** Open or close the panel. */
    toggle() {
        const closed = this.panel.hasAttribute('hidden');
        if (closed) {
            this.panel.removeAttribute('hidden');
            this.listenButton.setAttribute('aria-expanded', 'true');
            this.prepare().catch((err) => this.fail(err));
        } else {
            this.panel.setAttribute('hidden', '');
            this.listenButton.setAttribute('aria-expanded', 'false');
            this.stop(true);
        }
    }

    fail(err) {
        const message = err && err.message ? err.message : String(err);
        this.say(/webassembly|simd/i.test(message) ? text('unsupported', message) : `${text('failed', 'That did not work.')} ${message}`, true);
    }

    /** Load the model, and fill the voice picker. */
    async prepare() {
        if (this.tts) {
            return;
        }
        if (this.loading) {
            return this.loading;
        }

        this.loading = this.loadModel();
        try {
            await this.loading;
        } finally {
            this.loading = null;
        }
    }

    async loadModel() {
        this.say(text('loading', 'Loading the model...'));

        if (typeof WebAssembly === 'undefined') {
            throw new Error(text('unsupported', 'this browser has no WebAssembly'));
        }

        const conditions = {};
        const delta = durationDelta(this.speed);
        if (delta !== 0) {
            conditions.duration_delta = delta;
        }

        this.tts = await PhononTTS.load({
            lang: config.lang || 'en',
            model: config.model,
            // The CPU, deliberately. The WebGPU path in the current runtime computes
            // different numbers from the CPU's, and the model is calibrated against the
            // CPU's: the audio comes out twice as long and, from the second paragraph on,
            // it is noise.
            device: 'cpu',
            workerUrl: config.workerUrl,
            wasmUrl: config.wasmUrl,
            simdWasmUrl: config.simdWasmUrl,
            conditions,
            onProgress: ({ file, loaded, total, cached }) => {
                if (cached) {
                    return;
                }
                const percent = total ? ` ${Math.round((loaded / total) * 100)}%` : '';
                this.say(`${text('downloading', 'Fetching the model')} ${file || ''}${percent}`.trim());
            }
        });

        this.say(text('ready', 'Ready to read.'));
        await this.fillVoices();
    }

    /** Load the model again, for a different voice or speed. */
    async reloadModel() {
        if (!this.tts) {
            return;
        }
        const wasPlaying = this.playing;
        this.stop(false);

        try {
            this.tts.dispose();
        } catch (err) {
            // Nothing to do: the model is going away anyway.
        }
        this.tts = null;

        try {
            await this.loadModel();
        } catch (err) {
            this.fail(err);
            return;
        }

        if (wasPlaying) {
            this.playFrom(0);
        }
    }

    /** The voice catalog, and the voices the checkpoint itself has. */
    async fillVoices() {
        if (!this.voiceSelect) {
            return;
        }

        const names = new Set(this.tts.voices || []);

        try {
            if (!this.catalog) {
                const response = await fetch(config.catalogUrl, { credentials: 'omit' });
                if (response.ok) {
                    const body = await response.text();
                    this.catalog = body
                        .split('\n')
                        .map((line) => line.trim())
                        .filter(Boolean)
                        .map((line) => {
                            try {
                                return JSON.parse(line);
                            } catch (err) {
                                return null;
                            }
                        })
                        .filter(Boolean);
                }
            }
            (this.catalog || []).forEach((entry) => {
                if (entry && entry.name) {
                    names.add(entry.name);
                }
            });
        } catch (err) {
            // The catalog is a nicety. The checkpoint's own voices are enough.
        }

        const previous = this.voice;
        this.voiceSelect.innerHTML = '';

        const defaultOption = document.createElement('option');
        defaultOption.value = 'default';
        defaultOption.textContent = text('default_voice', 'The default voice');
        this.voiceSelect.appendChild(defaultOption);

        Array.from(names)
            .sort((a, b) => a.localeCompare(b))
            .forEach((name) => {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                this.voiceSelect.appendChild(option);
            });

        if (Array.from(this.voiceSelect.options).some((o) => o.value === previous)) {
            this.voiceSelect.value = previous;
        }
    }

    /** Make sure the voice is registered on the model. */
    async ensureVoice() {
        if (!this.tts || this.voice === 'default') {
            return 'default';
        }
        if (this.tts.voices.includes(this.voice)) {
            return this.voice;
        }
        const entry = (this.catalog || []).find((e) => e && e.name === this.voice);
        if (!entry || !entry.filename) {
            return 'default';
        }
        await this.tts.addVoice(entry.name, `https://huggingface.co/gradium/phonon-voices/resolve/main/${entry.filename}`);
        return entry.name;
    }

    /** The audio graph. */
    audio() {
        if (!this.ctx) {
            this.ctx = new AudioContext({ sampleRate: 24000 });
            this.gain = this.ctx.createGain();
            this.gain.gain.value = this.volume;
            this.gain.connect(this.ctx.destination);
        }
        return this.ctx;
    }

    /** Play, pause or resume, depending on where things are. */
    togglePlayback() {
        if (this.playing) {
            this.pause();
        } else if (this.paused) {
            this.resume();
        } else {
            this.playFrom(0);
        }
    }

    /** Read the post, from a paragraph. */
    async playFrom(index) {
        if (!this.tts) {
            try {
                await this.prepare();
            } catch (err) {
                this.fail(err);
                return;
            }
        }

        this.stop(false);
        const token = ++this.run;

        await this.resumeAudio();
        if (token !== this.run) {
            return;
        }

        const from = Math.min(Math.max(0, index), Math.max(0, this.paragraphs.length - 1));

        this.marks = [];
        this.soundingIndex = -1;
        this.currentParagraph = from;
        this.elapsed = 0;
        this.duration = 0;
        this.nextStartTime = this.ctx.currentTime;
        this.startedAt = this.ctx.currentTime;
        this.playing = true;
        this.paused = false;
        this.setState();
        this.say(text('playing', 'Reading.'));
        this.tick();

        let voice = 'default';
        try {
            voice = await this.ensureVoice();
        } catch (err) {
            // A voice that will not load is not worth stopping the post for.
        }

        for (let i = from; i < this.paragraphs.length; i += 1) {
            if (token !== this.run) {
                return;
            }
            this.currentParagraph = i;
            try {
                await this.speakParagraph(this.paragraphs[i], voice, i, token);
            } catch (err) {
                if (token === this.run) {
                    this.fail(err);
                }
                return;
            }
        }

        // Generation runs ahead of the play head, so wait for what is queued to finish.
        while (token === this.run && this.ctx.currentTime < this.nextStartTime) {
            await sleep(120);
        }
        if (token !== this.run) {
            return;
        }

        this.playing = false;
        this.paused = false;
        this.setState();
        this.setProgress(1);
        if (this.timeCurrent) {
            this.timeCurrent.textContent = formatTime(this.duration);
        }
        this.say(text('finished', 'Finished.'));
    }

    /** Generate one paragraph and schedule it. */
    async speakParagraph(content, voice, index, token) {
        const controller = new AbortController();
        this.abort = controller;

        const speech = this.tts.stream(content, {
            voice,
            temperature: 0.3,
            signal: controller.signal
        });
        this.stream = speech;

        const mark = { index, start: this.nextStartTime - this.startedAt, end: 0 };
        this.marks.push(mark);

        for await (const pcm of speech) {
            if (token !== this.run) {
                break;
            }
            this.schedule(pcm);
            mark.end = this.nextStartTime - this.startedAt;
        }

        this.stream = null;
        this.abort = null;
    }

    /** Put a chunk of audio on the clock. */
    schedule(pcm) {
        const ctx = this.ctx;
        const buffer = ctx.createBuffer(1, pcm.length, ctx.sampleRate);
        buffer.copyToChannel(pcm, 0);

        const source = ctx.createBufferSource();
        source.buffer = buffer;
        source.connect(this.gain);

        // The queue, not the play head: a chunk that arrives late starts now, and one that
        // arrives early waits its turn.
        const startAt = Math.max(this.nextStartTime, ctx.currentTime);
        source.start(startAt);
        this.nextStartTime = startAt + buffer.duration;
        this.duration = Math.max(this.duration, this.nextStartTime - this.startedAt);

        this.sources.add(source);
        source.onended = () => this.sources.delete(source);
    }

    /** Stop everything already on the clock. Cancelling the generation is not enough. */
    silence() {
        this.sources.forEach((source) => {
            try {
                source.stop();
            } catch (err) {
                // It had already finished.
            }
        });
        this.sources.clear();
    }

    /** Pause. */
    pause() {
        if (!this.playing) {
            return;
        }
        this.playing = false;
        this.paused = true;
        this.elapsed = this.ctx.currentTime - this.startedAt;
        this.setState();
        if (this.ctx.state === 'running') {
            this.ctx.suspend();
        }
        this.say(text('paused', 'Paused.'));
    }

    /** Resume. */
    async resume() {
        if (!this.paused) {
            return;
        }
        await this.resumeAudio();
        this.paused = false;
        this.playing = true;
        // The clock stopped while paused, so the start moves with it.
        this.startedAt = this.ctx.currentTime - this.elapsed;
        this.nextStartTime = Math.max(this.nextStartTime, this.ctx.currentTime);
        this.setState();
        this.say(text('playing', 'Reading.'));
        this.tick();
    }

    /** Back a paragraph, or back to the start of this one. */
    previous() {
        if (!this.playing && !this.paused) {
            return;
        }
        const index = this.soundingIndex >= 0 ? this.soundingIndex : this.currentParagraph;
        const mark = this.marks.find((m) => m.index === index);
        const intoParagraph = mark ? this.elapsed - mark.start : 0;

        if (intoParagraph > 3) {
            this.playFrom(index);
            return;
        }
        this.playFrom(Math.max(0, index - 1));
    }

    /** Forward a paragraph. */
    next() {
        if (!this.playing && !this.paused) {
            return;
        }
        const index = this.soundingIndex >= 0 ? this.soundingIndex : this.currentParagraph;
        this.playFrom(Math.min(this.paragraphs.length - 1, index + 1));
    }

    /**
     * Stop.
     *
     * @param {boolean} reset Whether to put the position back to the start as well.
     */
    stop(reset = true) {
        this.run += 1;
        this.playing = false;
        this.paused = false;
        this.soundingIndex = -1;
        this.currentParagraph = -1;
        this.setState();

        if (this.stream) {
            try {
                this.stream.cancel();
            } catch (err) {
                // It is already gone.
            }
            this.stream = null;
        }
        if (this.abort) {
            this.abort.abort();
            this.abort = null;
        }
        this.silence();

        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        this.highlight(-1);

        if (reset) {
            this.marks = [];
            this.elapsed = 0;
            this.duration = 0;
            this.setProgress(0);
            if (this.timeCurrent) {
                this.timeCurrent.textContent = '0:00';
            }
            if (this.timeDuration) {
                this.timeDuration.textContent = '0:00';
            }
            if (this.seek) {
                this.seek.value = '0';
                this.showCount(0);
            }
        }
    }

    /** The audio context, running. */
    async resumeAudio() {
        const ctx = this.audio();
        if (ctx.state === 'suspended') {
            try {
                await ctx.resume();
            } catch (err) {
                this.say(text('blocked', 'The browser blocked the audio. Press play again.'), true);
            }
        }
        return ctx;
    }

    /** The clock, for the progress bar. */
    tick() {
        if (this.timer) {
            clearInterval(this.timer);
        }
        this.timer = setInterval(() => {
            if (!this.playing) {
                clearInterval(this.timer);
                this.timer = null;
                return;
            }

            const elapsed = Math.max(0, this.ctx.currentTime - this.startedAt);
            this.elapsed = elapsed;

            if (this.timeCurrent) {
                this.timeCurrent.textContent = formatTime(elapsed);
            }
            if (this.timeDuration) {
                this.timeDuration.textContent = formatTime(this.duration);
            }
            this.setProgress(this.duration > 0 ? elapsed / this.duration : 0);

            // Highlight what is sounding, not what is being generated. The generation runs
            // ahead, and highlighting the future is worse than not highlighting at all.
            const mark = this.marks.find((m) => elapsed >= m.start && elapsed < m.end);
            if (mark && mark.index !== this.soundingIndex) {
                this.soundingIndex = mark.index;
                this.highlight(mark.index);
                if (this.seek) {
                    this.seek.value = String(mark.index);
                    this.showCount(mark.index);
                }
            }
        }, 200);
    }

    setProgress(fraction) {
        if (!this.seek) {
            return;
        }
        this.seek.style.setProperty('--vnra-progress', `${Math.min(100, Math.max(0, fraction * 100))}%`);
    }

    /** Mark the paragraph being read. */
    highlight(index) {
        if (!config.highlight) {
            return;
        }
        this.paragraphNodes.forEach((node) => node.classList.remove('vnra-para--reading'));

        if (index < 0) {
            return;
        }
        const node = this.paragraphNodes[index];
        if (node) {
            node.classList.add('vnra-para--reading');
        }
    }
}

/** The document the player lives in. A hook, so the tests can stand one up. */
function root_document() {
    return document;
}

function boot() {
    document.querySelectorAll('[data-vnra-player]').forEach((root) => {
        root.vnraReader = new Reader(root);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

export { Reader, formatTime, durationDelta };
