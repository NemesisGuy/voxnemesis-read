# VoxNemesis Read-Aloud

A WordPress plugin that lets readers hear your posts, with the speech generated in **their own browser**.

No server inference. No API key. No per-request cost. The text never leaves the reader's machine.

## How it works

The reader presses **Listen**. The browser fetches the [Phonon](https://huggingface.co/gradium/phonon-31m-en) model once — about 64 MB, cached by the browser afterwards — and runs it in a WebAssembly worker. The audio goes straight into the page's own audio graph.

Your server serves a player, a few assets and, if you host your own model, a static file. It never computes anything and never sees the audio. That is the whole point: a shared host or an old rack is enough.

## Why that matters for your hosting

A typical speech API costs per character or per minute, and the bill scales with your traffic. This plugin's cost to you is the same whether a hundred people read a post or a hundred thousand: a few assets and a cached model.

## What the reader gets

- A listen button, and a small player: play, stop, a progress bar and a clock.
- The paragraph being read is marked in the page. Clicking any paragraph starts from there.
- About a hundred voices, in five languages, and a speed control.
- Nothing is remembered on the server. No accounts, no cookies, no analytics.

## What you get

- **Settings, Read-Aloud**: enable it, pick the default voice, the default speed, and where the button goes (at the end of the post, or floating in the corner).
- **A model setting.** The default is the public Phonon 31m checkpoint, which speaks English. For another language, host the checkpoint yourself and point the plugin at it.

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

The reader's browser then fetches `config.json`, `tokenizer.json` and `model.q8.gguf` from there. Your rack serves static files; the reader's browser does the work.

The 40m checkpoints are CC BY-NC 4.0. The 31m one is Apache-2.0.

## The CPU, on purpose

The plugin generates on the CPU. The current Phonon runtime's WebGPU path computes different numbers from the CPU path — it does not quantise the activations the way the CPU does — and the model is calibrated against the CPU's. The result on WebGPU is an utterance roughly twice as long as it should be, and noise from the second paragraph on.

The CPU path is the one the native runtimes use and the one the model was built for, so that is what the plugin uses. The fix is upstream, in [`gradium-ai/xn`](https://github.com/gradium-ai/xn); see [xn#123](https://github.com/NemesisGuy/xn/issues/123) and [xn-ptts#248](https://github.com/gradium-ai/xn-ptts/issues/248).

## Installing

1. Copy the directory into `wp-content/plugins/voxnemesis-read-aloud`.
2. Activate it from the plugins screen.
3. Go to **Settings, Read-Aloud**. The player is on by default.

That is the whole installation. There is no build step and nothing to compile.

## Development

```bash
php tests/run.php        # the plugin, against a WordPress stand-in
php tests/make-fixture.php
node tests/reader.test.js  # the reader, in a real browser
```

`tests/wp-stubs.php` is a small WordPress stand-in: it stubs the functions the plugin calls and lets the tests render the player and inspect the result. `tests/reader.test.js` serves the plugin's assets next to a page built from the plugin's **own markup**, so the browser test cannot drift from what the plugin actually renders.

The browser test needs Playwright:

```bash
npm install --no-save playwright
node tests/reader.test.js
```

## The layout

```
voxnemesis-read-aloud.php     the plugin header and the bootstrap
includes/
  class-voxnemesis-read-aloud.php  hooks, assets, the wasm endpoint, the settings
  class-settings.php                reading and sanitising the options
  class-player.php                  the markup, and the paragraphs it reads
assets/
  css/reader.css                    the styles, on the NemesisNet tokens
  js/reader.js                      the reader, as an ES module
  vendor/phonon-tts/                the Phonon runtime, vendored
tests/                            the stand-in, the fixture and the browser test
```

### The wasm and its content type

A browser refuses to compile a WebAssembly module that was not served as `application/wasm`, and not every host is configured for that. The plugin therefore serves the wasm itself, from `includes/class-voxnemesis-read-aloud.php`, with the right header and a long cache lifetime. The rest of the assets are served by the web server as usual.

## Licence

MIT for the plugin. The vendored runtime in `assets/vendor/phonon-tts/` is MIT OR Apache-2.0, copyright Gradium. The model weights are not part of the plugin and carry their own licences: `phonon-31m-en` is Apache-2.0, the Phonon 40m checkpoints are CC BY-NC 4.0.
