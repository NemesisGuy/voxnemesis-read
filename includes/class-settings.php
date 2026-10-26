<?php
/**
 * The settings, read from the options table.
 *
 * @package voxnemesis_read
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * VoxNemesis Read settings.
 */
final class voxnemesis_read_Settings {

	/**
	 * The option name.
	 */
	const OPTION = 'vnra_options';

	/**
	 * The cached values.
	 *
	 * @var array|null
	 */
	private $values = null;

	/**
	 * The defaults.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'enabled'           => true,
			'post_types'        => array( 'post' ),
			'model'             => voxnemesis_read::DEFAULT_MODEL,
			'voice'             => 'default',
			'speed'             => 1.0,
			'lang'              => 'en',
			'position'          => 'after_content',
			'style'             => 'auto',
			'accent'            => '#2979ff',
			'accent_2'          => '#00c2a8',
			'show_voice_picker' => true,
			'show_speed'        => true,
			'show_volume'       => true,
			'show_skip'         => true,
			'show_seek'         => true,
			'highlight'         => true,
			'show_credit'       => false,
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key     The key.
	 * @param mixed  $default The value to use when the key is not set.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = is_array( $stored ) ? array_merge( $this->defaults(), $stored ) : $this->defaults();
		}

		if ( ! array_key_exists( $key, $this->values ) ) {
			return $default;
		}

		return $this->values[ $key ];
	}

	/**
	 * Reset the cache, after a save.
	 *
	 * @return void
	 */
	public function flush() {
		$this->values = null;
	}

	/**
	 * Whether the player is on.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->get( 'enabled' );
	}

	/**
	 * The post types the player is added to.
	 *
	 * @return array
	 */
	public function post_types() {
		$types = $this->get( 'post_types' );
		return is_array( $types ) ? $types : array();
	}

	/**
	 * Whether this post type gets a player.
	 *
	 * @param string $type The post type.
	 * @return bool
	 */
	public function applies_to( $type ) {
		return in_array( $type, $this->post_types(), true );
	}

	/**
	 * The model.
	 *
	 * @return string
	 */
	public function model() {
		return (string) $this->get( 'model' );
	}

	/**
	 * The default voice.
	 *
	 * @return string
	 */
	public function voice() {
		return (string) $this->get( 'voice' );
	}

	/**
	 * The default speed.
	 *
	 * @return float
	 */
	public function speed() {
		return (float) $this->get( 'speed' );
	}

	/**
	 * The language the text is normalized as.
	 *
	 * @return string
	 */
	public function lang() {
		return (string) $this->get( 'lang' );
	}

	/**
	 * Where the player goes.
	 *
	 * @return string
	 */
	public function position() {
		return (string) $this->get( 'position' );
	}

	/**
	 * How the player takes its colours: 'auto', 'light' or 'dark'.
	 *
	 * 'auto' is not a palette. It derives every neutral from the page's own text colour, so
	 * the player follows the theme, including a theme's own light/dark toggle.
	 *
	 * @return string
	 */
	public function style() {
		$style = (string) $this->get( 'style' );
		return array_key_exists( $style, $this->styles() ) ? $style : 'auto';
	}

	/**
	 * The first accent colour.
	 *
	 * @return string
	 */
	public function accent() {
		return $this->colour( 'accent' );
	}

	/**
	 * The second accent colour.
	 *
	 * @return string
	 */
	public function accent_2() {
		return $this->colour( 'accent_2' );
	}

	/**
	 * A stored colour, or the default when it is not a colour.
	 *
	 * @param string $key The setting.
	 * @return string
	 */
	private function colour( $key ) {
		$value     = (string) $this->get( $key );
		$defaults  = $this->defaults();
		$fallback  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '#2979ff';
		$sanitised = self::sanitize_colour( $value );

		return $sanitised ? $sanitised : $fallback;
	}

	/**
	 * A hex colour, or an empty string.
	 *
	 * Deliberately narrow: three or six hex digits. Nothing else goes into a stylesheet.
	 *
	 * @param mixed $value The candidate.
	 * @return string
	 */
	public static function sanitize_colour( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $value ) ) {
			return strtolower( $value );
		}

		return '';
	}

	/**
	 * The colour modes, for the settings screen.
	 *
	 * @return array
	 */
	public function styles() {
		return array(
			'auto'  => __( 'Follow the theme', 'voxnemesis-read' ),
			'light' => __( 'Always light', 'voxnemesis-read' ),
			'dark'  => __( 'Always dark', 'voxnemesis-read' ),
		);
	}

	/**
	 * Whether the voice picker is shown.
	 *
	 * @return bool
	 */
	public function show_voice_picker() {
		return (bool) $this->get( 'show_voice_picker' );
	}

	/**
	 * Whether the speed control is shown.
	 *
	 * @return bool
	 */
	public function show_speed() {
		return (bool) $this->get( 'show_speed' );
	}

	/**
	 * Whether the volume control is shown.
	 *
	 * @return bool
	 */
	public function show_volume() {
		return (bool) $this->get( 'show_volume' );
	}

	/**
	 * Whether the skip controls are shown.
	 *
	 * @return bool
	 */
	public function show_skip() {
		return (bool) $this->get( 'show_skip' );
	}

	/**
	 * Whether the paragraph slider is shown.
	 *
	 * @return bool
	 */
	public function show_seek() {
		return (bool) $this->get( 'show_seek' );
	}

	/**
	 * Whether the paragraph being read is highlighted.
	 *
	 * @return bool
	 */
	public function highlight() {
		return (bool) $this->get( 'highlight' );
	}

	/**
	 * Whether the opt-in "Powered by VoxNemesis Read" credit is shown.
	 *
	 * Off by default, per the WordPress.org plugin guidelines.
	 *
	 * @return bool
	 */
	public function show_credit() {
		return (bool) $this->get( 'show_credit' );
	}

	/**
	 * The positions the player can go.
	 *
	 * @return array
	 */
	public function positions() {
		return array(
			'before_content' => __( 'At the top of the post, under the title', 'voxnemesis-read' ),
			'after_content'  => __( 'At the end of the post', 'voxnemesis-read' ),
			'floating'       => __( 'A floating button, bottom right', 'voxnemesis-read' ),
			'shortcode'      => __( 'Only where I put the shortcode', 'voxnemesis-read' ),
		);
	}

	/**
	 * The public post types, for the settings screen.
	 *
	 * @return array
	 */
	public function available_post_types() {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$out = array();
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$out[ $type->name ] = $type->labels->singular_name;
		}

		return $out;
	}
}
