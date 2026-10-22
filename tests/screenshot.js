/**
 * The plugin's screenshot, for the readme and the plugins screen.
 *
 *   node tests/screenshot.js
 *
 * Renders a post with the player open and reading, and writes assets/screenshot.png.
 */

const http = require('http');
const fs = require('fs');
const os = require('os');
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

const POST = `
<article>
  <h1>The emergency patch nobody was expecting</h1>
  <p class="vnra-lede" data-vnra-para="0" style="background:rgba(0,194,168,.14);box-shadow:inset 3px 0 0 #00c2a8;">On the morning of Wednesday 21 October 2026, the PHP project published an emergency security release fixing two critical remote code execution vulnerabilities in the language that runs a large share of the web.</p>
  <p data-vnra-para="1">By 14:00 UTC the patches were live, Linux distributions had them in advance, and security agencies were telling operators to patch immediately or take their sites offline. If your site runs PHP, and if you run WordPress, it runs PHP, this is the week to check what you are actually serving.</p>
  <div class="vnra vnra--inline" data-vnra-player>
    <button type="button" class="vnra__listen" aria-expanded="true">
      <svg class="vnra__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4 9v6h4l5 4V5L8 9H4z"/></svg>
      <span class="vnra__listen-text">Listen to this post</span>
    </button>
    <div class="vnra__panel">
      <p class="vnra__status" role="status">Reading</p>
      <div class="vnra__bar"><span class="vnra__bar-fill" style="width:38%"></span></div>
      <div class="vnra__controls">
        <button type="button" class="vnra__play"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M8 5v14l11-7z"/></svg></button>
        <button type="button" class="vnra__stop"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6 6h12v12H6z"/></svg></button>
        <span class="vnra__time vnra__time--current">0:05</span>
        <span class="vnra__time vnra__time--duration">0:13</span>
      </div>
      <div class="vnra__options">
        <label class="vnra__option"><span class="vnra__option-label">Voice</span>
          <select class="vnra__voice"><option>Easton</option><option>Alba</option><option>Freya</option></select></label>
        <label class="vnra__option"><span class="vnra__option-label">Speed</span>
          <input type="range" class="vnra__speed" min="0.5" max="2" step="0.05" value="1"><output class="vnra__speed-value">1.00</output></label>
      </div>
    </div>
    <script type="application/json" class="vnra__text">${JSON.stringify([
        'On the morning of Wednesday 21 October 2026, the PHP project published an emergency security release fixing two critical remote code execution vulnerabilities in the language that runs a large share of the web.',
        'By 14:00 UTC the patches were live, Linux distributions had them in advance, and security agencies were telling operators to patch immediately or take their sites offline. If your site runs PHP, and if you run WordPress, it runs PHP, this is the week to check what you are actually serving.'
    ])}</script>
  </div>
</article>
`;

const CONFIG = {
    model: 'hf://gradium/phonon-31m-en@8c64b55ec301728e2d1695bfea87a08a267d0c2d',
    catalogUrl: 'https://huggingface.co/gradium/phonon-voices/resolve/main/voices.jsonl',
    voice: 'default',
    speed: 1,
    lang: 'en',
    showVoice: true,
    showSpeed: true,
    highlight: true,
    wasmUrl: '/plugin/?vnra_wasm=relaxed',
    simdWasmUrl: '/plugin/?vnra_wasm=simd',
    workerUrl: '/plugin/assets/vendor/phonon-tts/worker.js',
    strings: {
        listen: 'Listen to this post',
        loading: 'Fetching the voice model. This is a one-off download of about 64 MB, kept in your browser afterwards.',
        ready: 'Ready to read.',
        playing: 'Reading',
        paused: 'Paused',
        finished: 'Finished.',
        unsupported: 'Your browser cannot run this.',
        failed: 'That did not work.'
    }
};

const PAGE = `<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>VoxNemesis Read-Aloud</title>
<link rel="stylesheet" href="/plugin/assets/css/reader.css">
<style>
  body { margin:0; background:#0a0e27; color:#f4f4f4;
         font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif; }
  .wrap { max-width: 46rem; margin: 0 auto; padding: 3rem 1.5rem 6rem; }
  h1 { font-size: 2.1rem; font-weight:800; line-height:1.2; margin:0 0 1.4rem;
       background:linear-gradient(90deg,#2979ff,#00c2a8); -webkit-background-clip:text;
       background-clip:text; -webkit-text-fill-color:transparent; }
  p { line-height:1.75; color:rgba(244,244,244,.86); margin:0 0 1.2rem; }
  .vnra-lede { font-size:1.08rem; color:#f4f4f4; }
  .vnra { --vnra-glass:rgba(255,255,255,.05); }
  .vnra-para--reading { background:rgba(0,194,168,.14); box-shadow:inset 3px 0 0 #00c2a8; }
</style>
</head>
<body><div class="wrap">
${POST}
</div>
<script>window.voxnemesisReadAloud = ${JSON.stringify(CONFIG)};</script>
<script type="module" src="/plugin/assets/js/reader.js"></script>
</body></html>`;

function serve() {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1');

        if (url.pathname === '/' || url.pathname === '/reader.html') {
            res.writeHead(200, { 'Content-Type': MIME['.html'] });
            res.end(PAGE);
            return;
        }

        if (url.pathname.startsWith('/plugin/')) {
            const rel = url.pathname.slice('/plugin/'.length);
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
    const port = server.address().port;
    const base = `http://127.0.0.1:${port}`;

    const browser = await chromium.launch({
        headless: false,
        args: ['--autoplay-policy=no-user-gesture-required', '--no-first-run']
    });
    const page = await browser.newPage();
    await page.setViewportSize({ width: 1200, height: 800 });
    await page.goto(base);
    await page.waitForTimeout(600);

    // The player is in the page; the module found it and wired it up.
    const found = await page.evaluate(() => {
        const root = document.querySelector('[data-vnra-player]');
        return {
            hasReader: Boolean(root && root.vnraReader),
            paragraphs: document.querySelectorAll('[data-vnra-para]').length
        };
    });
    console.log('  ', JSON.stringify(found));

    // Freeze the player in the state the screenshot wants: open, reading, one paragraph
    // marked.
    await page.evaluate(() => {
        const root = document.querySelector('[data-vnra-player]');
        const reader = root.vnraReader;
        reader.panel.removeAttribute('hidden');
        reader.listenButton.setAttribute('aria-expanded', 'true');
        reader.say('Reading');
        reader.setBar(0.38);
        reader.highlight(0);
        if (reader.timeCurrent) reader.timeCurrent.textContent = '0:05';
        if (reader.timeDuration) reader.timeDuration.textContent = '0:13';
    });
    await page.waitForTimeout(300);

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
