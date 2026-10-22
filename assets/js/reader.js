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

/** The speeds the reader can pick, and the conditioner each one maps to. */
function durationDelta(speed) {
    const factor = Math.min(2, Math.max(0.5, Number(speed) || 1));
    const delta = Math.min(0.8, Math.max(-0.8, -(factor - 1)));
    return delta === 0 ? 0 : delta;
}

function formatTime(seconds) {
    const total = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;
    const minutes = Math.floor(total / 60);
    const rest = total % 60;
    return `${minutes}:${String(rest).padStart(2, '0')}`;
}

/** One player. A page can hold several. */
class Reader {
    constructor(root) {
        this.root = root;
        this.panel = root.querySelector('.vnra__panel');
        this.listenButton = root.querySelector('.vnra__listen');
        this.listenText = root.querySelector('.vnra__listen-text');
        this.status = root.querySelector('.vnra__status');
        this.barFill = root.querySelector('.vnra__bar-fill');
        this.playButton = root.querySelector('.vnra__play');
        this.stopButton = root.querySelector('.vnra__stop');
        this.timeCurrent = root.querySelector('.vnra__time--current');
        this.timeDuration = root.querySelector('.vnra__time--duration');
        this.voiceSelect = root.querySelector('.vnra__voice');
        this.speedInput = root.querySelector('.vnra__speed');
        this.speedOutput = root.querySelector('.vnra__speed-value');

        let raw = [];
        try {
            raw = JSON.parse(root.querySelector('.vnra__text').textContent || '[]');
        } catch (err) {
            raw = [];
        }
        this.paragraphs = Array.isArray(raw) ? raw.filter((p) => typeof p === 'string' && p.trim() !== '') : [];

        this.tts = null;
        this.loading = null;
        this.ctx = null;
        this.gain = null;
        this.nextStartTime = 0;
        this.startedAt = 0;
        this.elapsed = 0;
        this.duration = 0;
        this.playing = false;
        this.paused = false;
        this.stream = null;
        this.abort = null;
        this.voice = config.voice || 'default';
        this.speed = Number(config.speed) || 1;
        this.currentParagraph = -1;
        this.timer = null;
        this.catalog = null;

        this.bind();
    }

    bind() {
        this.listenButton.addEventListener('click', () => this.toggle());
        this.playButton.addEventListener('click', () => this.togglePlayback());
        this.stopButton.addEventListener('click', () => this.stop());

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

        if (this.voiceSelect) {
            this.voiceSelect.addEventListener('change', () => {
                this.voice = this.voiceSelect.value;
                this.reloadModel();
            });
        }

        document.querySelectorAll('[data-vnra-para]').forEach((node) => {
            node.classList.add('vnra-para');
            node.addEventListener('click', () => {
                const index = Number(node.getAttribute('data-vnra-para'));
                if (Number.isFinite(index)) {
                    this.playFrom(index);
                }
            });
        });
    }

    say(message, isError = false) {
        if (!this.status) {
            return;
        }
        this.status.textContent = message;
        this.status.classList.toggle('vnra__status--error', Boolean(isError));
    }

    /** Open or close the panel. */
    toggle() {
        const open = this.panel.hasAttribute('hidden');
        if (open) {
            this.panel.removeAttribute('hidden');
            this.listenButton.setAttribute('aria-expanded', 'true');
            this.prepare();
        } else {
            this.panel.setAttribute('hidden', '');
            this.listenButton.setAttribute('aria-expanded', 'false');
            this.stop();
        }
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
        this.say(config.strings && config.strings.loading ? config.strings.loading : 'Loading the model...');

        if (typeof WebAssembly === 'undefined') {
            throw new Error('this browser has no WebAssembly');
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
                const percent = total ? Math.round((loaded / total) * 100) : null;
                this.say(
                    `${file || 'model'}${percent !== null ? ` ${percent}%` : ''}`
                );
            }
        });

        this.say((config.strings && config.strings.ready) || 'Ready to read.');
        this.setBar(0);

        await this.fillVoices();
    }

    /** Load the model again, for a different voice or speed. */
    async reloadModel() {
        if (!this.tts) {
            return;
        }
        const wasPlaying = this.playing;
        this.stop();

        try {
            this.tts.dispose();
        } catch (err) {
            // Nothing to do: the model is going away anyway.
        }
        this.tts = null;

        try {
            await this.loadModel();
        } catch (err) {
            this.say((config.strings && config.strings.failed) || String(err), true);
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
                    const text = await response.text();
                    this.catalog = text
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
        defaultOption.textContent = 'The default voice';
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
        if (!this.tts) {
            return 'default';
        }
        if (this.tts.voices.includes(this.voice)) {
            return this.voice;
        }
        const entry = (this.catalog || []).find((e) => e && e.name === this.voice);
        if (!entry) {
            return 'default';
        }
        const url = `https://huggingface.co/gradium/phonon-voices/resolve/main/${entry.filename}`;
        await this.tts.addVoice(entry.name, url);
        return entry.name;
    }

    /** The audio graph. */
    audio() {
        if (!this.ctx) {
            this.ctx = new AudioContext({ sampleRate: 24000 });
            this.gain = this.ctx.createGain();
            this.gain.connect(this.ctx.destination);
        }
        return this.ctx;
    }

    /** Play or pause, depending on what is going on. */
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
                this.say((config.strings && config.strings.unsupported) || String(err), true);
                return;
            }
        }

        this.stop();
        await this.resumeAudio();

        this.currentParagraph = index;
        this.elapsed = 0;
        this.nextStartTime = this.ctx.currentTime;
        this.startedAt = this.ctx.currentTime;
        this.playing = true;
        this.paused = false;
        this.setBar(0);
        this.tick();

        try {
            const voice = await this.ensureVoice();
            for (let i = index; i < this.paragraphs.length; i += 1) {
                if (!this.playing) {
                    break;
                }
                this.currentParagraph = i;
                this.highlight(i);
                await this.speakParagraph(this.paragraphs[i], voice);
            }
        } catch (err) {
            if (this.playing) {
                this.say((config.strings && config.strings.failed) || String(err), true);
            }
        }

        if (this.playing) {
            this.playing = false;
            this.paused = false;
            this.setBar(1);
            if (this.timeCurrent) {
                this.timeCurrent.textContent = formatTime(this.duration);
            }
            if (this.timeDuration) {
                this.timeDuration.textContent = formatTime(this.duration);
            }
            this.say((config.strings && config.strings.finished) || 'Finished.');
        }
    }

    /** Generate one paragraph and schedule it. */
    async speakParagraph(text, voice) {
        const controller = new AbortController();
        this.abort = controller;

        const speech = this.tts.stream(text, {
            voice,
            temperature: 0.3,
            signal: controller.signal
        });
        this.stream = speech;

        for await (const pcm of speech) {
            if (!this.playing) {
                break;
            }
            this.schedule(pcm);
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
    }

    /** Pause. */
    pause() {
        if (!this.playing) {
            return;
        }
        this.playing = false;
        this.paused = true;
        this.elapsed = this.ctx.currentTime - this.startedAt;
        if (this.ctx.state === 'running') {
            this.ctx.suspend();
        }
        this.say((config.strings && config.strings.paused) || 'Paused.');
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
        this.say((config.strings && config.strings.playing) || 'Reading.');
        this.tick();
    }

    /** Stop, and forget the position. */
    stop() {
        this.playing = false;
        this.paused = false;
        this.currentParagraph = -1;
        this.elapsed = 0;
        this.duration = 0;

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
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        this.highlight(-1);
        this.setBar(0);
        if (this.timeCurrent) {
            this.timeCurrent.textContent = '0:00';
        }
        if (this.timeDuration) {
            this.timeDuration.textContent = '0:00';
        }
    }

    /** The audio context, running. */
    async resumeAudio() {
        const ctx = this.audio();
        if (ctx.state === 'suspended') {
            try {
                await ctx.resume();
            } catch (err) {
                // The browser blocked it. The click that started this counts, so it should not
                // have, but a blocked context is worth reporting rather than hiding.
                this.say('The browser blocked the audio. Press play again.', true);
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
            this.setBar(this.duration > 0 ? elapsed / this.duration : 0);
        }, 200);
    }

    /** The progress bar. */
    setBar(fraction) {
        if (this.barFill) {
            this.barFill.style.width = `${Math.min(100, Math.max(0, fraction * 100))}%`;
        }
    }

    /** Mark the paragraph being read. */
    highlight(index) {
        document.querySelectorAll('.vnra-para--reading').forEach((node) => {
            node.classList.remove('vnra-para--reading');
        });
        if (index < 0) {
            return;
        }
        const node = document.querySelector(`[data-vnra-para="${index}"]`);
        if (node) {
            node.classList.add('vnra-para--reading');
        }
    }
}

function boot() {
    const players = document.querySelectorAll('[data-vnra-player]');
    players.forEach((root) => {
        const reader = new Reader(root);
        root.vnraReader = reader;
    });
    document.documentElement.classList.add('vnra-ready');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
