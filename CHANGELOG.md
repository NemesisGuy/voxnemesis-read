# Changelog

## 0.1.2

Appearance settings, theme integration and mobile.

### Added
- **Style setting:** auto (follows the site theme), light or dark. A pinned mode overrides the theme entirely.
- **Accent and accent-2 colours:** the play button, the paragraph slider's filled track, the highlight ring and the live level meter pick them up.
- **Theme resolver:** the reader measures the page behind the player — composited backgrounds, gradient stops and all — and declares which palette the theme is actually in. A theme that writes its text with an alpha no longer dissolves the muted "Paragraph X of Y" text into nothing.
- **Loading spinner and determinate download bar:** the spinner shows while the model is on its way; the bar shows the download progress fed from the fetch progress.
- **Live level meter:** twelve bars driven by an `AnalyserNode` tapped in-chain while the audio plays.
- **Mobile:** full-width listen button on small screens, 44 px touch targets and slider thumbs sized for a thumb.

### Fixed
- Custom accent colours lost to `.vnra.vnra` specificity; the inline stylesheet now uses the same doubled selector.
- The screen-reader-text fallback for "Jump to a paragraph" compiled into the stylesheet; the stray brace from the earlier edit is gone.

## 0.1.1
Two bugs, both found by running the plugin in real WordPress rather than against the test
stand-in.

### Fixed
- **The settings screen fataled the whole site.** `register_settings()` was hooked on
  `init`, but `add_settings_section()` and friends live in `wp-admin/includes`, which the
  front end never loads. Every request — front end included — died with a fatal error.
  The hook is now `admin_init`.
- **The config never reached the reader.** It was attached with `wp_add_inline_script()`,
  which WordPress concatenates into the script tag before `script_loader_tag` runs. The
  filter rebuilt that tag from scratch to add `type="module"`, so the config was thrown
  away and the runtime fell back to its own defaults. The tag is now edited in place, and
  the config prints on its own.

### Added
- Previous and next paragraph.
- A paragraph slider that also shows position, with a "Paragraph 3 of 12" readout.
- Volume.
- Play and pause share a button and show the right icon for the state.
- Post type selection: posts, pages, or both.
- "At the top of the post" and "only where I put the shortcode" positions.
- `[voxnemesis_read]` shortcode.
- Per-control toggles on the settings screen.

### Changed
- The reader takes the paragraphs from the page instead of a JSON copy in the markup, so
  the text it speaks and the text on screen cannot drift apart.
- Stop now silences audio that has already been scheduled, not just the generation. It did
  not, before: pressing stop cancelled the job and left a queue of buffers playing.
- The paragraph being highlighted is the one that is sounding, not the one being generated.
  The generation runs ahead, and highlighting the future is worse than no highlight.

## 0.1.0
- First release.
- A listen button on posts and pages, and a small player: play, stop, a progress bar and a clock.
- The paragraph being read is marked in the page, and clicking a paragraph starts from there.
- A voice picker, filled from the Gradium catalog, and a speed control.
- Settings under Settings, Read: enable it, the default voice, the default speed, and whether the button sits at the end of the post or floats.
- A model setting, so a site can host its own checkpoint and serve another language. The browser fetches the weights from wherever the site points it, so no token is needed and the gated checkpoints work.
- The Phonon runtime is vendored, so the plugin has no build step and no runtime dependency.
- The wasm is served by the plugin with the content type a browser insists on, so it works on hosts that are not configured for `.wasm`.
- The speech is generated on the CPU, on purpose: the current runtime's WebGPU path computes different numbers from the CPU's, and the model is calibrated against the CPU's.
