# Changelog

## 0.1.0
- First release.
- A listen button on posts and pages, and a small player: play, stop, a progress bar and a clock.
- The paragraph being read is marked in the page, and clicking a paragraph starts from there.
- A voice picker, filled from the Gradium catalog, and a speed control.
- Settings under Settings, Read-Aloud: enable it, the default voice, the default speed, and whether the button sits at the end of the post or floats.
- A model setting, so a site can host its own checkpoint and serve another language. The browser fetches the weights from wherever the site points it, so no token is needed and the gated checkpoints work.
- The Phonon runtime is vendored, so the plugin has no build step and no runtime dependency.
- The wasm is served by the plugin with the content type a browser insists on, so it works on hosts that are not configured for `.wasm`.
- The speech is generated on the CPU, on purpose: the current runtime's WebGPU path computes different numbers from the CPU's, and the model is calibrated against the CPU's.
