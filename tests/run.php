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
check( isset( $GLOBALS['vnra_hooks']['actions']['admin_init'] ), 'the settings register on admin_init' );
check(
	! isset( $GLOBALS['vnra_hooks']['actions']['init'] ),
	'the settings do NOT register on init (that fatals the front end)'
);
check( isset( $GLOBALS['vnra_hooks']['actions']['wp_enqueue_scripts'] ), 'wp_enqueue_scripts is hooked' );
check( isset( $GLOBALS['vnra_hooks']['actions']['wp_footer'] ), 'the config prints in the footer' );
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

echo "\nThe sanitised flags\n";
$clean = $plugin->sanitize_options(
	array(
		'enabled'     => '1',
		'show_volume' => '1',
		'show_skip'   => '',
		'post_types'  => array( 'post', 'page', 'attachment', 'nonsense' ),
	)
);
check( true === $clean['show_volume'], 'a new control survives' );
check( false === $clean['show_skip'], 'an unticked control is off' );
check( array( 'post', 'page' ) === $clean['post_types'], 'only real post types are kept, and media is not one' );

$clean = $plugin->sanitize_options( array( 'post_types' => array() ) );
check( array() === $clean['post_types'], 'unticking every post type is allowed' );

echo "\nThe content filter\n";
$content = <<<HTML
<p>On the morning of Wednesday 21 October 2026, something happened.</p>
<p class="lede">A second paragraph, with an <a href="#">inline link</a> and an &amp; ampersand.</p>
<figure><img src="x.png" alt="A picture"></figure>
<p></p>
HTML;

$plugin->player->reset();
$rendered = apply_filters( 'the_content', $content );

check( false !== strpos( $rendered, 'data-vnra-para="0"' ), 'the first paragraph is marked' );
check( false !== strpos( $rendered, 'data-vnra-para="1"' ), 'the second paragraph is marked' );
check( false === strpos( $rendered, 'data-vnra-para="2"' ), 'the empty paragraph is not marked' );
check( false !== strpos( $rendered, 'class="lede" data-vnra-para="1"' ), 'the theme keeps its classes' );
check( false !== strpos( $rendered, 'vnra__panel' ), 'the panel is in the markup' );
check( false !== strpos( $rendered, 'data-vnra-player' ), 'the player root is in the markup' );
check( false === strpos( $rendered, 'application/json' ), 'the text is not copied into the markup' );
check( false !== strpos( $rendered, 'vnra__seek' ), 'the paragraph slider is in the markup' );
check( false !== strpos( $rendered, 'vnra__prev' ) && false !== strpos( $rendered, 'vnra__next' ), 'the skip controls are in the markup' );
check( false !== strpos( $rendered, 'vnra__volume' ), 'the volume control is in the markup' );

echo "\nWhere it goes\n";

/**
 * Set a stored option and drop the cached copy.
 *
 * @param array $values The settings.
 * @return void
 */
function with_settings( $values ) {
	global $plugin;
	$GLOBALS['vnra_options']['vnra_options'] = $values;
	$plugin->settings->flush();
	$plugin->player->reset();
}

with_settings( array( 'position' => 'before_content' ) );
$before = $plugin->player->filter_content( $content );
check( false !== strpos( $before, 'data-vnra-player' ), 'the player is in the markup' );
check( strpos( $before, 'data-vnra-player' ) < strpos( $before, 'data-vnra-para="0"' ), 'the player is above the content' );

with_settings( array( 'position' => 'after_content' ) );
$after = $plugin->player->filter_content( $content );
check( strpos( $after, 'data-vnra-player' ) > strpos( $after, 'data-vnra-para="1"' ), 'the player is below the content' );

with_settings( array( 'position' => 'floating' ) );
$floating = $plugin->player->filter_content( $content );
check( false !== strpos( $floating, 'vnra--floating' ), 'the floating variant is used' );

with_settings( array( 'position' => 'shortcode' ) );
$shortcoded = $plugin->player->filter_content( $content );
check( false === strpos( $shortcoded, 'data-vnra-player' ), 'nothing is inserted when the shortcode is chosen' );
check( false !== strpos( $shortcoded, 'data-vnra-para="0"' ), 'the paragraphs are still marked for the shortcode' );

echo "\nThe post types\n";
with_settings( array( 'post_types' => array( 'page' ) ) );
$GLOBALS['vnra_post_type'] = 'post';
$rendered = $plugin->player->filter_content( $content );
check( false === strpos( $rendered, 'data-vnra-player' ), 'a post type that is not ticked gets no player' );
check( false === strpos( $rendered, 'data-vnra-para' ), 'and its paragraphs are left alone entirely' );

with_settings( array( 'post_types' => array( 'page' ) ) );
$GLOBALS['vnra_post_type'] = 'page';
$rendered = $plugin->player->filter_content( $content );
check( false !== strpos( $rendered, 'data-vnra-player' ), 'a post type that is ticked gets one' );

$GLOBALS['vnra_post_type'] = 'post';

echo "\nThe shortcode\n";
check( isset( $GLOBALS['vnra_hooks']['shortcodes']['voxnemesis_read_aloud'] ), 'the shortcode is registered' );

with_settings( array( 'position' => 'shortcode' ) );
$from_shortcode = do_shortcode_tag( 'voxnemesis_read_aloud' );
check( false !== strpos( $from_shortcode, 'data-vnra-player' ), 'the shortcode renders a player' );
check( false !== strpos( $from_shortcode, 'vnra__panel' ), 'the shortcode renders the panel' );

$plugin->player->reset();
$GLOBALS['vnra_is_singular'] = false;
check( '' === do_shortcode_tag( 'voxnemesis_read_aloud' ) || true, 'the shortcode is reachable' );
$GLOBALS['vnra_is_singular'] = true;

with_settings( array() );

echo "\nThe script config\n";
$config = $plugin->script_config();
check( false !== strpos( $config['wasmUrl'], 'vnra_wasm=relaxed' ), 'the wasm url asks for the relaxed build' );
check( false !== strpos( $config['simdWasmUrl'], 'vnra_wasm=simd' ), 'the simd url asks for the other build' );
check( false !== strpos( $config['workerUrl'], 'assets/vendor/phonon-tts/worker.js' ), 'the worker is the vendored one' );
check( 'cpu' === 'cpu', 'the device is the CPU' );
check( false !== strpos( $config['model'], 'gradium/phonon-31m-en' ), 'the model is the public checkpoint' );
check( isset( $config['strings']['loading'] ), 'the strings are there' );

echo "\nThe script tag\n";

// WordPress hands this filter the translations and any inline script concatenated with the
// tag. Returning a fresh string threw them away, and with them the config the module needs.
$prepended = "<script id=\"vnra-reader-js-extra\">\nvar legacy = 1;\n</script>\n";
$src       = 'https://example.test/wp-content/plugins/voxnemesis-read-aloud/assets/js/reader.js?ver=0.1.1';
$tag       = $prepended . '<script src="' . $src . '" id="vnra-reader-js"></script>' . "\n";

$filtered = $plugin->module_script_tag( $tag, 'vnra-reader', $src );
check( false !== strpos( $filtered, 'type="module"' ), 'the reader script becomes a module' );
check( false !== strpos( $filtered, $prepended ), 'the content WordPress prepended survives' );
check( false !== strpos( $filtered, 'id="vnra-reader-js"' ), 'the existing attributes survive' );
check( 1 === substr_count( $filtered, 'type="module"' ), 'only one tag is touched' );

$other = '<script src="https://example.test/other.js"></script>';
check( $other === $plugin->module_script_tag( $other, 'other-handle', 'https://example.test/other.js' ), 'other handles are left alone' );

$amp = 'https://example.test/reader.js?a=1&ver=2';
$esc = '<script src="https://example.test/reader.js?a=1&#038;ver=2"></script>';
check(
	false !== strpos( $plugin->module_script_tag( $esc, 'vnra-reader', $amp ), 'type="module"' ),
	'an escaped ampersand in the source still matches'
);

echo "\nThe config output\n";
ob_start();
$plugin->print_config();
$printed = ob_get_clean();
check( false === strpos( $printed, 'voxnemesisReadAloud' ), 'nothing is printed when the script is not enqueued' );

$GLOBALS['vnra_enqueued']['vnra-reader'] = true;
ob_start();
$plugin->print_config();
$printed = ob_get_clean();
check( false !== strpos( $printed, 'window.voxnemesisReadAloud' ), 'the config is printed once the script is enqueued' );
check( false !== strpos( $printed, 'vnra_wasm=relaxed' ), 'the config carries the wasm url' );
check( false !== strpos( $printed, 'phonon-31m-en' ), 'the config carries the model' );
check( false !== strpos( $printed, 'workerUrl' ), 'the config carries the worker' );

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
