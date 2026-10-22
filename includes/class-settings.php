<?php
/**
 * The settings, read from the options table.
 *
 * @package VoxNemesis_Read_Aloud
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * VoxNemesis Read-Aloud settings.
 */
final class VoxNemesis_Read_Aloud_Settings {

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
			'enabled'          => true,
			'model'            => VoxNemesis_Read_Aloud::DEFAULT_MODEL,
			'voice'            => 'default',
			'speed'            => 1.0,
			'lang'             => 'en',
			'position'         => 'after_content',
			'show_voice_picker' => true,
			'show_speed'       => true,
			'highlight'        => true,
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
			$stored        = get_option( self::OPTION, array() );
			$this->values = is_array( $stored ) ? array_merge( $this->defaults(), $stored ) : $this->defaults();
		}

		if ( ! array_key_exists( $key, $this->values ) ) {
			return $default;
		}

		return $this->values[ $key ];
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
	 * Where the button goes.
	 *
	 * @return string
	 */
	public function position() {
		return (string) $this->get( 'position' );
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
	 * Whether the paragraph being read is highlighted.
	 *
	 * @return bool
	 */
	public function highlight() {
		return (bool) $this->get( 'highlight' );
	}

	/**
	 * The positions the button can go.
	 *
	 * @return array
	 */
	public function positions() {
		return array(
			'after_content' => __( 'At the end of the post', 'voxnemesis-read-aloud' ),
			'floating'      => __( 'A floating button, bottom right', 'voxnemesis-read-aloud' ),
		);
	}
}
