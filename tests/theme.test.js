/**
 * The theme test: does the player stay legible on a light theme and a dark one?
 *
 *   node tests/theme.test.js
 *
 * Loads the plugin's own markup, puts a light, a dark and a sepia page around it, and
 * measures the rendered colours. It does not load the model: this is about painting, not
 * speaking.
 *
 * The colours are read out of the browser, through a one-pixel canvas, because that is the
 * only way to get the truth out of `color-mix()` — the computed style is a `color(srgb ...)`
 * expression, and comparing those by hand is how you convince yourself a contrast problem is
 * fixed when it is not.
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');

const ROOT = path.resolve(__dirname, '..');
const FIXTURE = path.join(__dirname, 'fixtures', 'reader.html');
const OUT = path.join(__dirname, 'fixtures');

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.json': 'application/json',
    '.css': 'text/css; charset=utf-8',
    '.wasm': 'application/wasm',
    '.png': 'image/png',
    '.svg': 'image/svg+xml'
};

const THEMES = [
    { name: 'light', background: '#ffffff', text: '#1a1a1a' },
    { name: 'dark', background: '#0a0e27', text: '#f4f4f4' },
    { name: 'sepia', background: '#f6f1e7', text: '#2b2118' },
    // The nasty one: a theme that writes its text with an alpha. color-mix() compounds
    // that alpha into nothing, which is exactly the complaint this test exists to catch.
    { name: 'alpha', background: '#eef6f9', text: 'rgba(19, 21, 50, 0.5)' }
];

function serve() {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://127.0.0.1');

        if (url.pathname === '/' || url.pathname === '/reader.html') {
            res.writeHead(200, { 'Content-Type': MIME['.html'] });
            res.end(fs.readFileSync(FIXTURE));
            return;
        }

        if (url.pathname.startsWith('/plugin/')) {
            const rel = url.pathname.slice('/plugin/'.length);
            const file = path.join(ROOT, rel.split('/').join(path.sep));
            if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
                res.writeHead(404);
                res.end('not found');
                return;
            }
            res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
            fs.createReadStream(file).pipe(res);
            return;
        }

        res.writeHead(404);
        res.end('not found');
    });

    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server)));
}

/** Run in the page. Returns the measured colours and the ratios they produce. */
function measure(input) {
    const { background, text } = input;
    const parse = (value) => {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#000000';
        ctx.fillStyle = value;
        ctx.fillRect(0, 0, 1, 1);
        const data = ctx.getImageData(0, 0, 1, 1).data;
        return [data[0], data[1], data[2], data[3] / 255];
    };

    const over = (fg, bg) => {
        const a = fg[3];
        return [
            fg[0] * a + bg[0] * (1 - a),
            fg[1] * a + bg[1] * (1 - a),
            fg[2] * a + bg[2] * (1 - a),
            1
        ];
    };

    const luminance = (rgb) => {
        const channel = (value) => {
            const c = value / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);
    };

    const contrast = (a, b) => {
        const la = luminance(a);
        const lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    };

    const page = parse(background);
    const panelEl = document.querySelector('.vnra__panel');
    const panelBg = over(parse(getComputedStyle(panelEl).backgroundColor), page);

    const read = (selector) => {
        const el = document.querySelector(selector);
        if (!el) {
            return null;
        }
        const colour = over(parse(getComputedStyle(el).color), panelBg);
        return {
            contrast: Number(contrast(colour, panelBg).toFixed(2))
        };
    };

    // ...highlight: the accent variables have to reach the paragraph, which sits outside .vnra.
    const highlightDelta = (() => {
        const reading = document.querySelector('.vnra-para--reading');
        if (!reading) {
            return -1;
        }
        const own = over(parse(getComputedStyle(reading).backgroundColor), page);
        return Number(
            Math.max(
                Math.abs(own[0] - page[0]),
                Math.abs(own[1] - page[1]),
                Math.abs(own[2] - page[2])
            ).toFixed(1)
        );
    })();

    const border = (selector, base) => {
        const el = document.querySelector(selector);
        const colour = over(parse(getComputedStyle(el).borderTopColor), base);
        return {
            distance: Number(
                Math.max(
                    Math.abs(colour[0] - base[0]),
                    Math.abs(colour[1] - base[1]),
                    Math.abs(colour[2] - base[2])
                ).toFixed(1)
            )
        };
    };

    return {
        page: `${background} on ${text}`,
        panelDistance: Number(
            Math.max(
                Math.abs(panelBg[0] - page[0]),
                Math.abs(panelBg[1] - page[1]),
                Math.abs(panelBg[2] - page[2])
            ).toFixed(1)
        ),
        status: read('.vnra__status'),
        count: read('.vnra__seek-count'),
        option: read('.vnra__option-label'),
        time: read('.vnra__time--current'),
        highlightDelta,
        panelBorder: border('.vnra__panel', page),
        buttonBorder: border('.vnra__button', panelBg)
    };
}

async function main() {
    if (!fs.existsSync(FIXTURE)) {
        console.error(`No fixture at ${FIXTURE}. Run: php tests/make-fixture.php`);
        process.exit(1);
    }

    const server = await serve();
    const base = `http://127.0.0.1:${server.address().port}`;

    const browser = await chromium.launch({ headless: false });
    const page = await browser.newPage();
    await page.setViewportSize({ width: 900, height: 760 });

    const failures = [];

    for (const theme of THEMES) {
        await page.goto(base);

        await page.addStyleTag({
            content: `body { background: ${theme.background}; color: ${theme.text}; ${theme.name === 'dark' ? 'color-scheme: dark;' : 'color-scheme: light;'} }
                      main { max-width: 44rem; margin: 0 auto; padding: 2.5rem 1.5rem; }
                      h1 { font-size: 2rem; margin: 0 0 1.5rem; }
                      p { line-height: 1.7; }`
        });

        // Open the panel without touching the model.
        await page.evaluate(() => {
            const reader = document.querySelector('[data-vnra-player]').vnraReader;
            reader.panel.removeAttribute('hidden');
            reader.listenButton.setAttribute('aria-expanded', 'true');
            reader.say('Reading');
            reader.highlight(0);
            reader.setProgress(0.42);
            reader.showCount(0);
            const time = document.querySelector('.vnra__time--current');
            const duration = document.querySelector('.vnra__time--duration');
            if (time) {
                time.textContent = '0:42';
            }
            if (duration) {
                duration.textContent = '1:38';
            }
        });

        await page.waitForTimeout(180);

        const result = await page.evaluate(measure, { background: theme.background, text: theme.text });
        console.log(`\n${theme.name}: ${result.page}`);
        console.log('  ', JSON.stringify(result));

        const check = (label, value, minimum) => {
            const pass = value >= minimum;
            console.log(`  ${pass ? 'ok  ' : 'FAIL'} ${label}: ${value} (needs ${minimum})`);
            if (!pass) {
                failures.push(`${theme.name}: ${label} ${value} < ${minimum}`);
            }
        };

        // WCAG AA for body text. The muted text is small, so it has to clear the higher bar.
        check('status contrast', result.status.contrast, 4.5);
        check('paragraph count contrast', result.count.contrast, 4.5);
        check('option label contrast', result.option.contrast, 4.5);
        check('clock contrast', result.time.contrast, 4.5);

        // The paragraph being read has to be visibly marked, in every theme.
        check('highlight renders', result.highlightDelta, 8);

        // A border nobody can see is not a border. 12/255 is a visible hairline.
        check('panel border visibility', result.panelBorder.distance, 12);
        check('button border visibility', result.buttonBorder.distance, 12);

        // The panel has to sit apart from the page, or it is a floating pile of words.
        // The light palette paints the panel white on purpose, so the border carries the
        // separation on light pages.
        const separation = Math.max(result.panelDistance, result.panelBorder.distance);
        check('panel separation', separation, 12);

        const shot = await page.screenshot({ path: path.join(OUT, `theme-${theme.name}.png`) });
        console.log(`  wrote ${path.basename(path.join(OUT, `theme-${theme.name}.png`))} (${shot.length} bytes)`);
    }

    await browser.close();
    server.close();

    if (failures.length) {
        console.error('\nFAILED');
        failures.forEach((line) => console.error(`  ${line}`));
        process.exit(1);
    }

    console.log('\nall themes legible');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
