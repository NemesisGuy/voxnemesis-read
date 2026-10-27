# VoxNemesis Read

> Your articles. Your browser. Your voice.


A WordPress plugin that lets readers hear your posts, with the speech generated in **their own browser**.

No server inference. No API key. No per-request cost. The text never leaves the reader's machine.

## How it works

The reader presses **Listen**. The browser fetches the [Phonon](https://huggingface.co/gradium/phonon-31m-en) model once — about 64 MB, cached by the browser afterwards — and runs it in a WebAssembly worker. The audio goes straight into the page's own audio graph.

Your server serves a player, a few assets and, if you host your own model, a static file. It never computes anything and never sees the audio.

## What the reader gets

- A listen button, and a player with play/pause, stop, previous and next paragraph.
- A paragraph slider: drag it to jump to a paragraph, and it doubles as the progress bar.
- The paragraph being read is marked in the page, and clicking any paragraph starts from there.
- A voice picker (about a hundred voices), a speed control and a volume control.
- A clock, and a "Paragraph 3 of 12" readout.
- A live level meter while it reads, and a download bar while the model arrives.
- Light, dark or auto styling, pulled from the theme with an accent colour setting.

Each of those controls can be switched off on the settings screen, so a site can keep the
player to a bare play button if it wants one.

## What you get

- **Settings, Read**:
  - enable or disable the player;
  - which post types get one (posts, pages, or any public type);
  - where it goes;
  - the default voice and speed;
  - which controls the reader sees.
- **A model setting.** The default is the public Phonon 31m checkpoint, which speaks English. For another language, host the checkpoint yourself and point the plugin at it.

## Appearance settings

- **Style:** auto (follows the site theme), light or dark. Pinned modes override the theme entirely.
- **Accent colour:** the play button, the paragraph slider's filled track and the highlight ring.
- **Accent 2 colour:** the secondary colour in gradients and the live level meter.

The player measures what the theme actually paints behind it — composited backgrounds, gradient stops and all — and picks the matching palette. A theme that writes its text with a transparency no longer dissolves the muted "Paragraph X of Y" text into nothing. Borders, the clock and the paragraph highlight stay readable on light, dark, or semi-transparent themes.

The player is built for small screens: a full-width listen button, 44 px touch targets and slider thumbs sized for a thumb.

## Where the player goes

| Setting | What happens |
|---|---|
| At the top of the post, under the title | Placed before the content |
| At the end of the post | Placed after the content |
| A floating button, bottom right | Fixed to the corner, panel opens above it |
| Only where I put the shortcode | Nothing is added automatically |

The shortcode is `[voxnemesis_read]`. Use it when the theme puts the content somewhere unexpected, or when you want the button halfway down an article.

## Hosting your own model

The multilingual checkpoints are gated on Hugging Face, and a browser has no token to send. Hosting them yourself solves that: the files are static, and the reader's browser fetches them from your site, so no token is needed.

```
config.json
tokenizer.json
model.q8.gguf
```

Put them somewhere your server can reach and set the model option to that URL:

```
https://your-site.test/models/phonon-40m-es/
```

Your rack serves static files; the reader's browser does the work. This is the shape of the
thing if you have no compute: a shared host or an old server is enough, because nothing on
it ever runs the model.

The 40m checkpoints are CC BY-NC 4.0. The 31m one is Apache-2.0.

## The CPU, on purpose

The plugin generates on the CPU. The current Phonon runtime's WebGPU path computes different numbers from the CPU path — it does not quantise the activations the way the CPU does — and the model is calibrated against the CPU's. The result on WebGPU is an utterance roughly twice as long as it should be, and noise from the second paragraph on.

The CPU path is the one the native runtimes use and the one the model was built for, so that is what the plugin uses. The fix is upstream, in [`gradium-ai/xn`](https://github.com/gradium-ai/xn); see [xn#123](https://github.com/NemesisGuy/xn/issues/123) and [xn-ptts#248](https://github.com/gradium-ai/xn-ptts/issues/248).

## Installing

1. Copy the directory into `wp-content/plugins/voxnemesis-read`, or upload the zip from the plugins screen.
2. Activate it.
3. Go to **Settings, Read**. The player is on by default.

There is no build step and nothing to compile.

## Development

```bash
php tests/run.php          # the plugin, against a WordPress stand-in
php tests/make-fixture.php
node tests/reader.test.js  # the reader, in a real browser
node tests/screenshot.js   # regenerates assets/screenshot.png
```

`tests/wp-stubs.php` is a small WordPress stand-in: it stubs the functions the plugin calls and lets the tests render the player and inspect the result.

`tests/reader.test.js` serves the plugin's assets next to a page built from the plugin's **own markup**, so the browser test cannot drift from what the plugin actually renders. It drives the reader for real: the model loads, the audio plays, the clock advances, the paragraphs are marked, the controls do what they say.

The browser tests need Playwright:

```bash
npm install --no-save playwright
node tests/reader.test.js
```

### What the stand-in does not catch

It does not load `wp-admin/includes`, and it does not run WordPress's script printing. Both of
those produced bugs in 0.1.0 that the stand-in cheerfully passed:

- `add_settings_section()` on `init` fataled every request, because the front end never loads
  the file that defines it. Hook is now `admin_init`.
- `wp_add_inline_script()` data is concatenated into the script tag before `script_loader_tag`
  runs. Rebuilding that tag dropped the config. The tag is now edited in place.

So the plugin was also run against a real WordPress with the SQLite drop-in, and the position
settings, the shortcode, the settings save and the reader were all exercised there. That is
the test that matters, and it is the one to repeat before a release.

## The layout

```
voxnemesis-read.php     the plugin header and the bootstrap
includes/
  class-voxnemesis-read.php  hooks, assets, the wasm endpoint, the settings
  class-settings.php                reading and sanitising the options
  class-player.php                  the markup, the paragraph markers, the shortcode
assets/
  css/reader.css                    the styles, on the NemesisNet tokens
  js/reader.js                      the reader, as an ES module
  vendor/phonon-tts/                the Phonon runtime, vendored
tests/                            the stand-in, the fixture and the browser tests
```

### The wasm and its content type

A browser refuses to compile a WebAssembly module that was not served as `application/wasm`, and not every host is configured for that. The plugin therefore serves the wasm itself, from `includes/class-voxnemesis-read.php`, with the right header and a long cache lifetime. The rest of the assets are served by the web server as usual.

## Links

- **NemesisNet** — [nemesisnet.co.za](https://nemesisnet.co.za)
- **Brand guide** — [brand.nemesisnet.co.za](https://brand.nemesisnet.co.za)
- **GitHub** — [NemesisGuy/voxnemesis-read](https://github.com/NemesisGuy/voxnemesis-read)

## Licence

MIT for the plugin. The vendored runtime in `assets/vendor/phonon-tts/` is MIT OR Apache-2.0, copyright Gradium. The model weights are not part of the plugin and carry their own licences: `phonon-31m-en` is Apache-2.0, the Phonon 40m checkpoints are CC BY-NC 4.0.
