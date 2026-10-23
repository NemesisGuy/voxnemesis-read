/**
 * The reader's browser test.
 *
 *   node tests/reader.test.js
 *
 * Serves the plugin's assets and a page built from the plugin's own markup, then drives the
 * reader in a real browser: it loads the model, reads the post, and the audio plays.
 *
 * The wasm is served with the content type a browser insists on, which is what the plugin's
 * own endpoint does.
 */

const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const ROOT = path.resolve(__dirname, '..');
const FIXTURE = path.join(__dirname, 'fixtures', 'reader.html');

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json',
    '.css': 'text/css; charset=utf-8',
    '.wasm': 'application/wasm',
    '.png': 'image/png',
    '.svg': 'image/svg+xml'
};

function serve() {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1');
        const query = url.searchParams;

        // The page.
        if (url.pathname === '/' || url.pathname === '/reader.html') {
            const body = fs.readFileSync(FIXTURE);
            res.writeHead(200, { 'Content-Type': MIME['.html'] });
            res.end(body);
            return;
        }

        // The plugin's assets, and the wasm the way the plugin's endpoint serves it.
        if (url.pathname.startsWith('/plugin/')) {
            const rel = url.pathname.slice('/plugin/'.length);

            // The plugin's own endpoint: the wasm is served by the plugin, with the content
            // type a browser insists on, rather than straight off disk.
            const variant = query.get('vnra_wasm');
            if (variant) {
                const names = {
                    relaxed: 'phonon_tts_bg.wasm',
                    simd: 'phonon_tts_simd_bg.wasm'
                };
                const name = names[variant];
                if (!name) {
                    res.writeHead(404);
                    res.end('not found');
                    return;
                }
                const file = path.join(ROOT, 'assets', 'vendor', 'phonon-tts', 'wasm', name);
                const body = fs.readFileSync(file);
                res.writeHead(200, {
                    'Content-Type': 'application/wasm',
                    'Content-Length': body.length
                });
                res.end(body);
                return;
            }

            const file = path.join(ROOT, rel.split('/').join(path.sep));

            if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
                res.writeHead(404);
                res.end('not found');
                return;
            }

            const type = MIME[path.extname(file)] || 'application/octet-stream';
            res.writeHead(200, {
                'Content-Type': type,
                'Content-Length': fs.statSync(file).size,
                'Cache-Control': 'no-store'
            });
            fs.createReadStream(file).pipe(res);
            return;
        }

        res.writeHead(404);
        res.end('not found');
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

async function main() {
    if (!fs.existsSync(FIXTURE)) {
        console.error(`No fixture at ${FIXTURE}. Run: php tests/make-fixture.php`);
        process.exit(1);
    }

    const server = await serve();
    const port = server.address().port;
    const base = `http://127.0.0.1:${port}`;

    const browser = await chromium.launch({
        headless: false,
        args: ['--autoplay-policy=no-user-gesture-required', '--no-first-run']
    });

    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            errors.push(msg.text());
        }
    });

    await page.goto(base);
    console.log('page loaded');

    const results = [];

    // The button is there and the panel is closed.
    const before = await page.evaluate(() => ({
        listen: !!document.querySelector('.vnra__listen'),
        panelHidden: document.querySelector('.vnra__panel').hasAttribute('hidden'),
        paragraphs: document.querySelectorAll('[data-vnra-para]').length,
        hasSeek: !!document.querySelector('.vnra__seek'),
        hasSkip: !!document.querySelector('.vnra__prev') && !!document.querySelector('.vnra__next'),
        hasVolume: !!document.querySelector('.vnra__volume'),
        seekMax: document.querySelector('.vnra__seek') ? document.querySelector('.vnra__seek').max : null
    }));
    results.push(['the listen button is present', before.listen]);
    results.push(['the panel starts closed', before.panelHidden]);
    results.push(['the paragraphs are marked', before.paragraphs === 2]);
    results.push(['the paragraph slider is present', before.hasSeek]);
    results.push(['the skip controls are present', before.hasSkip]);
    results.push(['the volume control is present', before.hasVolume]);
    results.push(['the slider is bounded by the paragraphs', before.seekMax === '1']);
    console.log('  markup:', JSON.stringify(before));

    // Open the panel and load the model. The first run downloads about 64 MB.
    await page.click('.vnra__listen');
    console.log('clicked listen, loading the model...');

    const ready = await page
        .waitForFunction(
            () => {
                const status = document.querySelector('.vnra__status');
                return status && /ready/i.test(status.textContent || '');
            },
            { timeout: 300000 }
        )
        .then(() => true)
        .catch(() => false);
    results.push(['the model loads', ready]);
    console.log(`  model loaded: ${ready}`);

    if (!ready) {
        const status = await page.textContent('.vnra__status');
        console.log(`  status was: ${status}`);
    }

    // Play it.
    await page.click('.vnra__play');
    await page.waitForTimeout(2500);

    const playing = await page.evaluate(() => ({
        time: document.querySelector('.vnra__time--current').textContent,
        progress: document.querySelector('.vnra__seek').style.getPropertyValue('--vnra-progress'),
        value: document.querySelector('.vnra__seek').value,
        count: document.querySelector('.vnra__seek-count').textContent,
        reading: document.querySelectorAll('.vnra-para--reading').length,
        state: document.querySelector('[data-vnra-player]').dataset.playing,
        pauseIcon: getComputedStyle(document.querySelector('.vnra__icon-pause')).display
    }));
    results.push(['the clock advances', playing.time !== '0:00']);
    results.push(['the slider fills', parseFloat(playing.progress || '0') > 0]);
    results.push(['the paragraph count is shown', /Paragraph 1 of 2/.test(playing.count)]);
    results.push(['a paragraph is marked as being read', playing.reading === 1]);
    results.push(['the player reports that it is playing', playing.state === 'true']);
    results.push(['the button shows a pause icon', playing.pauseIcon !== 'none']);
    console.log('  playback:', JSON.stringify(playing));

    // Pause and resume.
    await page.click('.vnra__play');
    await page.waitForTimeout(400);
    const paused = await page.evaluate(() => ({
        state: document.querySelector('[data-vnra-player]').dataset.playing,
        status: document.querySelector('.vnra__status').textContent
    }));
    results.push(['pausing updates the state', paused.state === 'false']);
    results.push(['pausing says so', /paused/i.test(paused.status)]);

    await page.click('.vnra__play');
    await page.waitForTimeout(600);
    const resumed = await page.evaluate(() => document.querySelector('[data-vnra-player]').dataset.playing);
    results.push(['resuming updates the state', resumed === 'true']);

    // Skip forward a paragraph.
    await page.click('.vnra__next');
    await page.waitForTimeout(2500);
    const skipped = await page.evaluate(() => ({
        reading: document.querySelectorAll('.vnra-para--reading').length,
        value: document.querySelector('.vnra__seek').value,
        count: document.querySelector('.vnra__seek-count').textContent
    }));
    results.push(['next moves to the second paragraph', skipped.reading === 1 && skipped.value === '1']);
    results.push(['the slider follows', /Paragraph 2 of 2/.test(skipped.count)]);
    console.log('  after next:', JSON.stringify(skipped));

    // Volume.
    await page.evaluate(() => {
        const input = document.querySelector('.vnra__volume');
        input.value = '0.4';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const volume = await page.evaluate(() => document.querySelector('.vnra__volume').value);
    results.push(['the volume control takes a value', volume === '0.4']);

    // Let it finish, and check it settles.
    await page.waitForTimeout(8000);
    const finished = await page.evaluate(() => ({
        time: document.querySelector('.vnra__time--current').textContent,
        duration: document.querySelector('.vnra__time--duration').textContent,
        state: document.querySelector('[data-vnra-player]').dataset.playing
    }));
    results.push(['the duration is shown when it ends', finished.duration !== '0:00']);
    results.push(['it stops when it is done', finished.state === 'false']);
    console.log('  finished:', JSON.stringify(finished));

    // Stop it.
    await page.click('.vnra__stop');
    await page.waitForTimeout(500);
    const stopped = await page.evaluate(() => ({
        time: document.querySelector('.vnra__time--current').textContent,
        value: document.querySelector('.vnra__seek').value,
        reading: document.querySelectorAll('.vnra-para--reading').length
    }));
    results.push(['stop resets the clock', stopped.time === '0:00']);
    results.push(['stop resets the slider', stopped.value === '0']);
    results.push(['stop clears the highlight', stopped.reading === 0]);

    console.log('');
    let failed = 0;
    for (const [what, ok] of results) {
        console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${what}`);
        if (!ok) {
            failed += 1;
        }
    }

    if (errors.length) {
        console.log('\n  console errors:');
        errors.slice(0, 8).forEach((e) => console.log(`    ${e}`));
    }

    await browser.close();
    server.close();

    console.log(failed ? `\n${failed} checks failed` : '\nall checks passed');
    process.exit(failed ? 1 : 0);
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
