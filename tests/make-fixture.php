<?php
/**
 * Writes the browser test page, using the markup the plugin really produces.
 *
 *   php tests/make-fixture.php
 *
 * The page is written to tests/fixtures/reader.html. The browser test serves it next to the
 * plugin's assets, so the reader is exercised against the real markup rather than a copy of
 * it that can drift.
 *
 * @package voxnemesis_read
 */

// phpcs:disable

require_once __DIR__ . '/wp-stubs.php';

$root = dirname( __DIR__ );
require_once $root . '/voxnemesis-read.php';

$plugin = voxnemesis_read();

$content = <<<HTML
<h1>A post about something</h1>
<p>On the morning of Wednesday 21 October 2026, the PHP project published an emergency security release.</p>
<p>By fourteen hundred hours the patches were live, and operators were told to patch immediately.</p>
HTML;

$rendered = apply_filters( 'the_content', $content );

$config             = $plugin->script_config();
$config['wasmUrl']     = '/plugin/?vnra_wasm=relaxed';
$config['simdWasmUrl'] = '/plugin/?vnra_wasm=simd';
$config['workerUrl']   = '/plugin/assets/vendor/phonon-tts/worker.js';

// The site's accent colours ride on an inline stylesheet, as they do on a real page.
$plugin->enqueue_assets();
$inline = implode( "\n", $GLOBALS['vnra_hooks']['inline_styles']['vnra-reader'] ?? array() );

$dir = __DIR__ . '/fixtures';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}

$html = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n"
	. "<title>Reader test</title>\n"
	. "<link rel=\"stylesheet\" href=\"/plugin/assets/css/reader.css\">\n"
	. ( '' !== $inline ? "<style>" . $inline . "</style>\n" : '' )
	. "<script>window.voxnemesisRead = " . wp_json_encode( $config ) . ";</script>\n"
	. "</head>\n<body>\n"
	. "<main>\n" . $rendered . "\n</main>\n"
	. "<script type=\"module\" src=\"/plugin/assets/js/reader.js\"></script>\n"
	. "</body>\n</html>\n";

file_put_contents( $dir . '/reader.html', $html );

echo "Wrote " . $dir . "/reader.html\n";
echo "  paragraphs: " . substr_count( $rendered, 'data-vnra-para=' ) . "\n";
