# BUGLOG

Surprising bugs and the reasons behind them. Symptom plus cause, not just symptom.

## 0.1.2

### Play/pause button showed both icons at once
**Symptom:** On the player, the play triangle and the pause bars were both visible at the same time, on every theme.
**Cause:** `.vnra__button svg { display: block; }` (specificity 0,3,1) outranked `.vnra__icon-pause { display: none; }` (0,3,0) because the `svg` element selector added a specificity point. The `display: none` never won.
**Fix:** Add `svg` to the icon selectors (`.vnra__button svg.vnra__icon-play`) so they sit at 0,4,1 and beat the generic rule.

### Custom accent colours lost to the doubled selectors
**Symptom:** Setting a custom accent in the plugin settings did nothing; the play button stayed the default blue.
**Cause:** The stylesheet declares the default accents on `.vnra.vnra` (doubled, for theme-proofing). The inline stylesheet used `.vnra,.vnra-para` (0,1,0), which lost to `.vnra.vnra` (0,2,0).
**Fix:** Inline stylesheet now uses `.vnra.vnra,.vnra-para` to match specificity.

### "Jump to a paragraph" leaked visibly on some themes
**Symptom:** The screen-reader-text label for the seek slider showed as visible text on themes that do not ship their own `.screen-reader-text` fallback.
**Cause:** The CSS rewrite dropped the fallback block that the previous version had.
**Fix:** Restored the `.vnra .screen-reader-text` / `.vnra.vnra .screen-reader-text` fallback (with a corrected brace count — the first insertion emitted a stray `}`).

### Theme resolver silently disabled itself
**Symptom:** The resolver never set `data-vnra-resolved`; every player fell through to the OS preference.
**Cause:** `parse()` set `ctx.fillStyle` but never called `ctx.fillRect`, so `getImageData` read an untouched canvas whose alpha was 0. Every colour parsed as transparent and every branch bailed.
**Fix:** Added `ctx.fillRect(0, 0, 1, 1)` and `willReadFrequently: true`.

### Favicon 404 in the browser test
**Symptom:** `tests/reader.test.js` reported one 404 console error on every run.
**Cause:** Headed Chromium requests `/favicon.ico`; the test server answered 404 for any path outside `/plugin/`. Not a plugin bug.
**Fix:** Test server now answers `/favicon.ico` with 204. The response listener in the test names the failing resource instead of logging a bare "404".

## 0.1.1

### Settings screen fataled the whole site
**Symptom:** Every request — front end included — died with a fatal error after activation.
**Cause:** `register_settings()` hooked on `init`, but `add_settings_section()` and friends live in `wp-admin/includes`, which the front end never loads.
**Fix:** Hook on `admin_init`.

### Config never reached the reader
**Symptom:** The reader fell back to its own defaults; nothing from the settings screen reached the page.
**Cause:** `wp_add_inline_script()` data is concatenated into the script tag before `script_loader_tag` runs. The filter rebuilt the tag from scratch to add `type="module"` and dropped the config.
**Fix:** Tag is edited in place, and the config prints on its own.

### Stop left already-scheduled audio playing
**Symptom:** Pressing stop cancelled the generation but a queue of buffers kept playing.
**Cause:** Stop cancelled the job but did not silence the already-scheduled `AudioBufferSourceNode`s.
**Fix:** Stop now silences the queue.

### Highlight followed the generator, not the audio
**Symptom:** The paragraph highlight ran ahead of what was sounding.
**Cause:** Highlight was keyed to the generation pointer, which runs ahead of the audio clock.
**Fix:** Highlight now follows `this.marks` against the audio clock.
EOF