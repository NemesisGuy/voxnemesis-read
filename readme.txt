=== VoxNemesis Read-Aloud ===
Contributors: nemesisnet
Tags: text to speech, tts, read aloud, accessibility, phonon
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Let readers hear your posts. The speech is generated in their own browser, so there is no server cost, no API key and nothing to pass on.

== Description ==

VoxNemesis Read-Aloud puts a listen button on your posts and pages. When a reader presses it, the speech is generated in **their own browser**, by the Gradium Phonon model running in WebAssembly.

That means:

* **No per-request cost.** Your site never runs the model, never calls a speech API and never sees the audio.
* **Nothing leaves the reader's machine.** The text is not sent anywhere, and no audio is produced on a server.
* **It works on your cheap hosting.** The site serves a player and, at most, a static model file. An old rack, a shared host, a static site behind a CDN: all fine.

The first press downloads the model, about 64 MB, and the browser keeps it. After that it is a cache hit.

= What the reader gets =

* A listen button, and a player with play and pause, stop, and previous and next paragraph.
* A paragraph slider that doubles as the progress bar. Drag it to jump to a paragraph.
* The paragraph being read is marked in the page. Clicking any paragraph starts from there.
* About a hundred voices, in five languages, a speed control and a volume control.
* A clock, and a "Paragraph 3 of 12" readout.

Every one of those controls can be switched off on the settings screen, down to a bare play button.

= What you get =

* Settings under Settings, Read-Aloud: enable it, choose which post types get a player, where the button goes, the default voice and speed, and which controls the reader sees.
* Four placements: at the top of the post, at the end, a floating button in the corner, or only where you put the `[voxnemesis_read_aloud]` shortcode.
* The default model is the public Phonon 31m checkpoint, which speaks English.
* For another language, host the checkpoint yourself and point the plugin at it. The files are static. Your rack serves them; the reader's browser does the work.
* Nothing is stored about the reader. No accounts, no cookies, no analytics.

= A note on the WebGPU path =

The plugin generates speech on the CPU, on purpose. The current Phonon runtime's WebGPU path computes different numbers from the CPU path, and the model is calibrated against the CPU's: the audio comes out roughly twice as long as it should be, and from the second paragraph on it is noise. The CPU path is the one the native runtimes use, and the one the model was built for. The fix is upstream.

== Installation ==

1. Upload the plugin to `wp-content/plugins/` and activate it, or install it from the plugins screen.
2. Go to Settings, Read-Aloud. The player is on by default.
3. Read a post and press Listen.

The first press downloads the model. It is kept in the reader's browser afterwards.

== Frequently Asked Questions ==

= Does this send my posts to a third party? =

No. The text is read in the reader's browser and never leaves it. The only network request the reader makes is for the model weights, from a public host, and the voice catalog, from the same host.

= Why is the download 64 MB? =

It is the model. A 31 million parameter speech model, quantised to 8 bits, is about 64 MB. It is fetched once and cached by the browser, the same way a large image or a font is.

= Can I use a different language? =

Yes, if you host the checkpoint yourself. Download the Phonon 40m checkpoint for your language from Hugging Face, put the files somewhere your server can reach, and set the model option to that URL. The files are static: `config.json`, `tokenizer.json` and `model.q8.gguf`. The reader's browser fetches them from your site, so no token is needed and the multilingual checkpoints, which are gated, work.

The multilingual checkpoints are CC BY-NC 4.0, so they are for non-commercial use unless you have a licence.

= Where does the button go, and why is it not where I expected? =

You have four choices. Two of them place the player in the content, which is where a theme usually renders the post body: "at the top of the post" puts it before the content and "at the end" puts it after. If your theme renders the content somewhere unusual, or you want the button further down an article, choose "only where I put the shortcode" and place `[voxnemesis_read_aloud]` yourself.

= Does it work with my theme? =

The player is appended to the post content and is styled with plain CSS, using the NemesisNet brand tokens. It inherits your fonts and colours. If it needs to fit in better, the CSS is one file, `assets/css/reader.css`.

= Can a reader start from the middle of a post? =

Yes. Click any paragraph, or drag the paragraph slider.

= What about accessibility? =

The button is a real button, the player is labelled, the progress is announced through a live region, the slider is keyboard operable, and the player can be driven from the keyboard. The paragraph being read is marked visually.

== Screenshots ==

1. The player, open, reading a post.
2. The settings screen.

== Changelog ==

= 0.1.1 =
* Fixed: the settings screen fataled every request, front end included. `register_settings()` ran on `init`, but the Settings API functions live in `wp-admin/includes`, which the front end never loads. It runs on `admin_init` now.
* Fixed: the reader's configuration never reached the page. It was attached with `wp_add_inline_script()`, which WordPress concatenates into the script tag before `script_loader_tag` runs; the filter rebuilt that tag and dropped it. The tag is edited in place now, and the config prints on its own.
* Fixed: stop left already-scheduled audio playing. It now silences the queue.
* Fixed: the highlight followed the generator rather than the audio. It now follows what is sounding.
* Added: previous and next paragraph, a paragraph slider, and volume.
* Added: the play button becomes a pause button, with the matching icon.
* Added: post type selection.
* Added: "at the top of the post" and "only where I put the shortcode" placements, and the `[voxnemesis_read_aloud]` shortcode.
* Added: per-control toggles on the settings screen.
* Changed: the reader takes the paragraphs from the page rather than a JSON copy in the markup, so the spoken text and the visible text cannot drift apart.

= 0.1.0 =
* First release.
* The listen button, the player, the voice picker and the speed control.
* Paragraph marking, and click a paragraph to start from there.
* A model setting, for hosting a checkpoint yourself.

== Upgrade Notice ==

= 0.1.1 =
Fixes a fatal error on activation that took the whole site down, and a bug that stopped the reader working at all. Upgrade.

= 0.1.0 =
First release.
