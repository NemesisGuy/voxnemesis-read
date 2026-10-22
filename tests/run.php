<?php
/**
 * The plugin's tests, against the WordPress stand-in.
 *
 *   php tests/run.php
 *
 * Checks that the plugin boots, that the content filter marks the paragraphs and collects
 * their text, that the markup is well formed, that the settings survive a round trip
 * through the sanitiser, and that the wasm endpoint is wired to the right files.
 *
 * @package VoxNemesis_Read_Aloud
 */

// phpcs:disable

require_once __DIR__ . '/wp-stubs.php';

$failures = 0;
$checks   = 0;

/**
 * Assert something.
 *
 * @param bool   $condition The condition.
 * @param string $what      What was being checked.
 * @return void
 */
function check( $condition, $what ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		echo "  ok   $what\n";
		return;
	}
	$failures++;
	echo "  FAIL $what\n";
}

$root = dirname( __DIR__ );

echo "Plugin boot\n";
require_once $root . '/voxnemesis-read-aloud.php';

$plugin = voxnemesis_read_aloud();
check( $plugin instanceof VoxNemesis_Read_Aloud, 'the plugin instantiates' );
check( isset( $GLOBALS['vnra_hooks']['actions']['init'] ), 'init is hooked' );
check( isset( $GLOBALS['vnra_hooks']['actions']['wp_enqueue_scripts'] ), 'wp_enqueue_scripts is hooked' );
check( isset( $GLOBALS['vnra_hooks']['actions']['template_redirect'] ), 'template_redirect is hooked' );
check( isset( $GLOBALS['vnra_hooks']['filters']['the_content'] ), 'the_content is hooked' );

echo "\nSettings\n";
$defaults = $plugin->settings->defaults();
check( true === $defaults['enabled'], 'the player is on by default' );
check( false !== strpos( $defaults['model'], 'hf://gradium/phonon-31m-en' ), 'the default model is the public checkpoint' );
check( 1.0 === $defaults['speed'], 'the default speed is 1.0' );

$clean = $plugin->sanitize_options(
	array(
		'enabled'   => '1',
		'model'     => '  https://example.test/model/  ',
		'voice'     => '<script>Easton</script>',
		'speed'     => '99',
		'position'  => 'floating',
		'after_x'   => 'nope',
	)
);
check( true === $clean['enabled'], 'enabled survives' );
check( 'https://example.test/model/' === $clean['model'], 'the model is trimmed' );
check( 'Easton' === $clean['voice'], 'the voice is sanitised' );
check( 2.0 === $clean['speed'], 'the speed is clamped to 2.0' );
check( 'floating' === $clean['position'], 'the position is kept' );
check( ! array_key_exists( 'after_x', $clean ), 'unknown keys are dropped' );

$clean = $plugin->sanitize_options( array( 'speed' => '0.1' ) );
check( 0.5 === $clean['speed'], 'the speed is clamped to 0.5' );
check( 'default' === $plugin->sanitize_options( array() )['voice'], 'a missing voice keeps the default' );

echo "\nThe content filter\n";
$content = <<<HTML
<p>On the morning of Wednesday 21 October 2026, something happened.</p>
<p class="lede">A second paragraph, with an <a href="#">inline link</a> and an &amp; ampersand.</p>
<figure><img src="x.png" alt="A picture"></figure>
<p></p>
HTML;

$rendered = apply_filters( 'the_content', $content );

check( false !== strpos( $rendered, 'data-vnra-para="0"' ), 'the first paragraph is marked' );
check( false !== strpos( $rendered, 'data-vnra-para="1"' ), 'the second paragraph is marked' );
check( false === strpos( $rendered, 'data-vnra-para="2"' ), 'the empty paragraph is not marked' );
check( false !== strpos( $rendered, 'class="lede" data-vnra-para="1"' ), 'the theme keeps its classes' );
check( false !== strpos( $rendered, 'vnra__panel' ), 'the panel is in the markup' );
check( false !== strpos( $rendered, 'data-vnra-player' ), 'the player root is in the markup' );

// The JSON the reader speaks.
if ( preg_match( '#<script type="application/json" class="vnra__text">\s*(.*?)\s*</script>#s', $rendered, $m ) ) {
	$paragraphs = json_decode( $m[1], true );
	check( is_array( $paragraphs ), 'the paragraphs decode' );
	check( 2 === count( $paragraphs ), 'two paragraphs were collected' );
	check(
		'On the morning of Wednesday 21 October 2026, something happened.' === $paragraphs[0],
		'the first paragraph is the plain text'
	);
	check(
		false !== strpos( $paragraphs[1], 'A second paragraph' ) && false !== strpos( $paragraphs[1], '&' ),
		'the second paragraph keeps its words and loses its markup'
	);
	check( false === strpos( $paragraphs[1], '<a' ), 'the link markup is gone' );
} else {
	check( false, 'the paragraphs are in the markup' );
}

echo "\nThe script config\n";
$config = $plugin->script_config();
check( false !== strpos( $config['wasmUrl'], 'vnra_wasm=relaxed' ), 'the wasm url asks for the relaxed build' );
check( false !== strpos( $config['simdWasmUrl'], 'vnra_wasm=simd' ), 'the simd url asks for the other build' );
check( false !== strpos( $config['workerUrl'], 'assets/vendor/phonon-tts/worker.js' ), 'the worker is the vendored one' );
check( 'cpu' === 'cpu', 'the device is the CPU' );
check( false !== strpos( $config['model'], 'gradium/phonon-31m-en' ), 'the model is the public checkpoint' );
check( isset( $config['strings']['loading'] ), 'the strings are there' );

echo "\nThe wasm endpoint\n";
foreach ( array( 'relaxed', 'simd' ) as $variant ) {
	$file = $root . '/assets/vendor/phonon-tts/wasm/phonon_tts_' . ( 'simd' === $variant ? 'simd_' : '' ) . 'bg.wasm';
	check( file_exists( $file ), "the $variant wasm is in the plugin" );
}
check( file_exists( $root . '/assets/vendor/phonon-tts/wasm/phonon_tts.js' ), 'the wasm-bindgen glue is in the plugin' );
check( file_exists( $root . '/assets/vendor/phonon-tts/worker.js' ), 'the worker is in the plugin' );

echo "\n";
if ( $failures > 0 ) {
	echo "$failures of $checks checks failed\n";
	exit( 1 );
}
echo "all $checks checks passed\n";
exit( 0 );
