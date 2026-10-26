/**
 * The plugin's screenshot, for the readme and the plugins screen.
 *
 *   node tests/screenshot.js
 *
 * Renders a post with the player open and reading, and writes assets/screenshot.png.
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'assets', 'screenshot.png');

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.wasm': 'application/wasm'
};

const CONFIG = {
    model: 'hf://gradium/phonon-31m-en@8c64b55ec301728e2d1695bfea87a08a267d0c2d',
    catalogUrl: 'https://huggingface.co/gradium/phonon-voices/resolve/main/voices.jsonl',
    voice: 'Easton',
    speed: 1,
    lang: 'en',
    showVoice: true,
    showSpeed: true,
    showVolume: true,
    showSkip: true,
    showSeek: true,
    highlight: true,
    wasmUrl: '/plugin/?vnra_wasm=relaxed',
    simdWasmUrl: '/plugin/?vnra_wasm=simd',
    workerUrl: '/plugin/assets/vendor/phonon-tts/worker.js',
    strings: {
        listen: 'Listen to this post',
        loading: 'Fetching the voice model.',
        downloading: 'Fetching the model',
        ready: 'Ready to read.',
        playing: 'Reading',
        paused: 'Paused',
        finished: 'Finished.',
        unsupported: 'Your browser cannot run this.',
        failed: 'That did not work.',
        paragraph: 'Paragraph %1$s of %2$s'
    }
};

const svg = {
    speaker:
        '<path fill="currentColor" d="M4 9v6h4l5 4V5L8 9H4zm12.5 3a3.5 3.5 0 0 0-2-3.16v6.32a3.5 3.5 0 0 0 2-3.16zM14 3.23v2.06a6 6 0 0 1 0 11.42v2.06a8 8 0 0 0 0-15.54z"/>',
    play: '<path fill="currentColor" d="M8 5v14l11-7z"/>',
    pause: '<path fill="currentColor" d="M7 5h4v14H7zm6 0h4v14h-4z"/>',
    stop: '<path fill="currentColor" d="M6 6h12v12H6z"/>',
    prev: '<path fill="currentColor" d="M6 5h2v14H6zM20 5v14L9 12z"/>',
    next: '<path fill="currentColor" d="M16 5h2v14h-2zM4 5v14l11-7z"/>'
};

const PAGE = `<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>VoxNemesis Read</title>
<link rel="stylesheet" href="/plugin/assets/css/reader.css">
<style>
  body { margin:0; background:#0a0e27; color:#f4f4f4;
         font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif; }
  .wrap { max-width: 48rem; margin: 0 auto; padding: 3.5rem 1.5rem 6rem; }
  h1 { font-size: 2.15rem; font-weight:800; line-height:1.2; margin:0 0 1.5rem;
       background:linear-gradient(90deg,#2979ff,#00c2a8); -webkit-background-clip:text;
       background-clip:text; -webkit-text-fill-color:transparent; }
  p { line-height:1.75; color:rgba(244,244,244,.86); margin:0 0 1.25rem; font-size:1.02rem; }
  p:first-of-type { font-size:1.1rem; color:#f4f4f4; }
  .vnra { --vnra-glass:rgba(255,255,255,.05); }
</style>
</head>
<body><div class="wrap">
<article>
  <h1>The emergency patch nobody was expecting</h1>
  <p data-vnra-para="0">On the morning of Wednesday 21 October 2026, the PHP project published an emergency security release fixing two critical remote code execution vulnerabilities in the language that runs a large share of the web.</p>
  <p data-vnra-para="1">By 14:00 UTC the patches were live, Linux distributions had them in advance, and security agencies were telling operators to patch immediately or take their sites offline.</p>

  <div class="vnra vnra--inline" data-vnra-player data-playing="true">
    <button type="button" class="vnra__listen" aria-expanded="true">
      <svg class="vnra__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">${svg.speaker}</svg>
      <span class="vnra__listen-text">Listen to this post</span>
    </button>
    <div class="vnra__panel">
      <p class="vnra__status" role="status">Reading</p>
      <label class="vnra__seek-wrap">
        <span class="screen-reader-text">Jump to a paragraph</span>
        <input type="range" class="vnra__seek" min="0" max="1" step="1" value="0" style="--vnra-progress:38%" />
        <span class="vnra__seek-count">Paragraph 1 of 2</span>
      </label>
      <div class="vnra__controls">
        <button type="button" class="vnra__button vnra__prev" aria-label="Previous paragraph"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">${svg.prev}</svg></button>
        <button type="button" class="vnra__button vnra__button--primary vnra__play" aria-label="Pause">
          <svg class="vnra__icon-play" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">${svg.play}</svg>
          <svg class="vnra__icon-pause" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">${svg.pause}</svg>
        </button>
        <button type="button" class="vnra__button vnra__next" aria-label="Next paragraph"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">${svg.next}</svg></button>
        <button type="button" class="vnra__button vnra__stop" aria-label="Stop"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">${svg.stop}</svg></button>
        <span class="vnra__time vnra__time--current">0:05</span>
        <span class="vnra__time vnra__time--duration">0:14</span>
      </div>
      <div class="vnra__options">
        <label class="vnra__option"><span class="vnra__option-label">Voice</span>
          <select class="vnra__voice"><option>Easton</option><option>Alba</option><option>Freya</option></select></label>
        <label class="vnra__option"><span class="vnra__option-label">Speed</span>
          <input type="range" class="vnra__speed" min="0.5" max="2" step="0.05" value="1"><output class="vnra__speed-value">1.00</output></label>
        <label class="vnra__option"><span class="vnra__option-label">Volume</span>
          <input type="range" class="vnra__volume" min="0" max="1" step="0.05" value="1"></label>
      </div>
    </div>
  </div>
</article>
</div>
<script>window.voxnemesisRead = ${JSON.stringify(CONFIG)};</script>
<script type="module" src="/plugin/assets/js/reader.js"></script>
</body></html>`;

const POST_TEXT = `By 14:00 UTC the patches were live, and security agencies were telling operators to patch immediately.`;

function serve() {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1');

        if (url.pathname === '/' || url.pathname === '/reader.html') {
            res.writeHead(200, { 'Content-Type': MIME['.html'] });
            res.end(PAGE);
            return;
        }

        if (url.pathname.startsWith('/plugin/')) {
            const variant = url.searchParams.get('vnra_wasm');
            if (variant) {
                const names = { relaxed: 'phonon_tts_bg.wasm', simd: 'phonon_tts_simd_bg.wasm' };
                const file = path.join(ROOT, 'assets', 'vendor', 'phonon-tts', 'wasm', names[variant]);
                if (!names[variant] || !fs.existsSync(file)) {
                    res.writeHead(404);
                    res.end('not found');
                    return;
                }
                const body = fs.readFileSync(file);
                res.writeHead(200, { 'Content-Type': 'application/wasm', 'Content-Length': body.length });
                res.end(body);
                return;
            }

            const rel = url.pathname.slice('/plugin/'.length);
            const file = path.join(ROOT, rel.split('/').join(path.sep));
            if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
                res.writeHead(404);
                res.end('not found');
                return;
            }
            res.writeHead(200, {
                'Content-Type': MIME[path.extname(file)] || 'application/octet-stream',
                'Content-Length': fs.statSync(file).size
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
    const server = await serve();
    const base = `http://127.0.0.1:${server.address().port}`;

    const browser = await chromium.launch({ headless: false });
    const page = await browser.newPage();
    await page.setViewportSize({ width: 1200, height: 810 });
    await page.goto(base);
    await page.waitForTimeout(700);

    const found = await page.evaluate(() => {
        const root = document.querySelector('[data-vnra-player]');
        return { hasReader: Boolean(root && root.vnraReader) };
    });
    console.log('  ', JSON.stringify(found));

    // Freeze the state the screenshot wants. The player would otherwise be idle.
    await page.evaluate(() => {
        const reader = document.querySelector('[data-vnra-player]').vnraReader;
        reader.panel.removeAttribute('hidden');
        reader.listenButton.setAttribute('aria-expanded', 'true');
        reader.root.dataset.playing = 'true';
        reader.say('Reading');
        reader.highlight(0);
        reader.setProgress(0.38);
    });
    await page.waitForTimeout(250);

    fs.mkdirSync(path.dirname(OUT), { recursive: true });
    await page.screenshot({ path: OUT });
    console.log(`Wrote ${OUT}`);

    await browser.close();
    server.close();
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
