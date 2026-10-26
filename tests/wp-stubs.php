<?php
/**
 * A WordPress stand-in, so the plugin can be exercised without a WordPress install.
 *
 * It stubs the functions the plugin calls, records the hooks that were registered, and
 * lets the harness render the player and inspect the result.
 *
 * @package VoxNemesis_Read_Aloud
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

/** The hooks the plugin registered. */
$GLOBALS['vnra_hooks'] = array(
	'actions' => array(),
	'filters' => array(),
);

/** The options. */
$GLOBALS['vnra_options'] = array();

/** The queue of the current loop. */
$GLOBALS['vnra_in_loop'] = true;
$GLOBALS['vnra_is_singular'] = true;
$GLOBALS['vnra_is_main_query'] = true;
$GLOBALS['vnra_post_type'] = 'post';

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['vnra_hooks']['actions'][ $hook ][] = $callback;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['vnra_hooks']['filters'][ $hook ][] = $callback;
	return true;
}

function do_action( $hook ) {
	$args = array_slice( func_get_args(), 1 );
	if ( empty( $GLOBALS['vnra_hooks']['actions'][ $hook ] ) ) {
		return;
	}
	foreach ( $GLOBALS['vnra_hooks']['actions'][ $hook ] as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 2 );
	if ( empty( $GLOBALS['vnra_hooks']['filters'][ $hook ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['vnra_hooks']['filters'][ $hook ] as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
	}
	return $value;
}

function register_setting( ...$args ) {}
function add_settings_section( ...$args ) {}
function add_settings_field( ...$args ) {}
function add_options_page( ...$args ) {}
function settings_fields( ...$args ) {}
function do_settings_sections( ...$args ) {}
function submit_button( ...$args ) {}
function current_user_can( ...$args ) {
	return true;
}
function wp_doing_ajax() {
	return false;
}
function is_admin() {
	return false;
}
function is_singular() {
	return (bool) $GLOBALS['vnra_is_singular'];
}
function in_the_loop() {
	return (bool) $GLOBALS['vnra_in_loop'];
}
function is_main_query() {
	return (bool) $GLOBALS['vnra_is_main_query'];
}

function get_post_type( $post = null ) {
	return $GLOBALS['vnra_post_type'];
}

function add_shortcode( $tag, $callback ) {
	$GLOBALS['vnra_hooks']['shortcodes'][ $tag ] = $callback;
}

function do_shortcode_tag( $tag ) {
	if ( empty( $GLOBALS['vnra_hooks']['shortcodes'][ $tag ] ) ) {
		return '';
	}
	return call_user_func( $GLOBALS['vnra_hooks']['shortcodes'][ $tag ], array(), '', $tag );
}

function get_post_types( $args = array(), $output = 'names' ) {
	$types = array();

	foreach ( array( 'post' => 'Post', 'page' => 'Page', 'attachment' => 'Media' ) as $name => $label ) {
		$type                 = new stdClass();
		$type->name           = $name;
		$type->labels         = new stdClass();
		$type->labels->singular_name = $label;
		$types[ $name ]       = $type;
	}

	return $types;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['vnra_options'] ) ? $GLOBALS['vnra_options'][ $name ] : $default;
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function plugins_url( $path, $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/' . ltrim( $path, '/' );
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function add_query_arg( $args, $url ) {
	$parts = parse_url( $url );
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}
	foreach ( (array) $args as $key => $value ) {
		$query[ $key ] = $value;
	}
	return ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
		. ( isset( $parts['host'] ) ? $parts['host'] : '' )
		. ( isset( $parts['path'] ) ? $parts['path'] : '' )
		. '?' . http_build_query( $query );
}

function wp_strip_all_tags( $string, $remove_breaks = false ) {
	$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
	$string = strip_tags( $string );
	if ( $remove_breaks ) {
		$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
	}
	return trim( $string );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function __( $text, $domain = '' ) {
	return $text;
}

function _e( $text, $domain = '' ) {
	echo $text;
}

function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}

function esc_html_e( $text, $domain = '' ) {
	echo esc_html( $text );
}

function esc_attr__( $text, $domain = '' ) {
	return esc_attr( $text );
}

function esc_attr_e( $text, $domain = '' ) {
	echo esc_attr( $text );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags | JSON_UNESCAPED_SLASHES, $depth );
}

function checked( $checked, $current = true, $echo = true ) {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}

function selected( $selected, $current = true, $echo = true ) {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_enqueue_style( ...$args ) {
	$GLOBALS['vnra_hooks']['styles'][ $args[0] ] = $args;
}

function wp_enqueue_script( ...$args ) {
	$GLOBALS['vnra_hooks']['scripts'][ $args[0] ] = $args;
	$GLOBALS['vnra_enqueued'][ $args[0] ]   = true;
}

function wp_add_inline_style( $handle, $data ) {
	$GLOBALS['vnra_hooks']['inline_styles'][ $handle ][] = $data;
}

function wp_script_is( $handle, $status = 'enqueued' ) {
	return ! empty( $GLOBALS['vnra_enqueued'][ $handle ] );
}

function wp_print_inline_script_tag( $data, $attributes = array() ) {
	$id = isset( $attributes['id'] ) ? ' id="' . esc_attr( $attributes['id'] ) . '"' : '';
	echo '<script' . $id . '>' . $data . "</script>\n";
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['vnra_hooks']['inline'][ $handle ][ $position ][] = $data;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function status_header( $code ) {}
function nocache_headers() {}
