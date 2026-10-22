<?php
/**
 * The front end: the markup, and the paragraphs the reader speaks.
 *
 * @package VoxNemesis_Read_Aloud
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * VoxNemesis Read-Aloud player.
 */
final class VoxNemesis_Read_Aloud_Player {

	/**
	 * The settings.
	 *
	 * @var VoxNemesis_Read_Aloud_Settings
	 */
	private $settings;

	/**
	 * A counter, so two players on one page have different ids.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Constructor.
	 *
	 * @param VoxNemesis_Read_Aloud_Settings $settings The settings.
	 */
	public function __construct( VoxNemesis_Read_Aloud_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Add the player to the content, and mark the paragraphs it reads.
	 *
	 * The paragraphs are taken from the final HTML, in the same pass that marks them, so the
	 * text the reader speaks and the paragraphs in the page can never drift apart.
	 *
	 * @param string $content The post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! $this->settings->enabled() ) {
			return $content;
		}

		$paragraphs = array();

		$marked = preg_replace_callback(
			'/<p\b([^>]*)>(.*?)<\/p>/is',
			function ( $matches ) use ( &$paragraphs ) {
				$text = $this->paragraph_text( $matches[2] );
				if ( '' === $text ) {
					return $matches[0];
				}

				$attrs = $matches[1];
				if ( false === strpos( $attrs, 'data-vnra-para' ) ) {
					$attrs .= sprintf( ' data-vnra-para="%d"', count( $paragraphs ) );
				}

				$paragraphs[] = $text;

				return '<p' . $attrs . '>' . $matches[2] . '</p>';
			},
			$content
		);

		if ( null === $marked ) {
			return $content;
		}

		if ( empty( $paragraphs ) ) {
			return $marked;
		}

		if ( 'floating' === $this->settings->position() ) {
			return $marked . $this->markup( $paragraphs, 'floating' );
		}

		return $marked . $this->markup( $paragraphs, 'inline' );
	}

	/**
	 * The plain text of a paragraph.
	 *
	 * @param string $html The paragraph's inner HTML.
	 * @return string
	 */
	private function paragraph_text( $html ) {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * The player's markup.
	 *
	 * @param array  $paragraphs The paragraphs to read.
	 * @param string $variant   Either 'inline' or 'floating'.
	 * @return string
	 */
	private function markup( $paragraphs, $variant = 'inline' ) {
		self::$instance++;
		$id = 'vnra-' . self::$instance;

		ob_start();
		?>
		<div
			class="vnra vnra--<?php echo esc_attr( $variant ); ?>"
			id="<?php echo esc_attr( $id ); ?>"
			data-vnra-player
		>
			<button
				type="button"
				class="vnra__listen"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $id ); ?>-panel"
			>
				<svg class="vnra__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
					<path fill="currentColor" d="M4 9v6h4l5 4V5L8 9H4zm12.5 3a3.5 3.5 0 0 0-2-3.16v6.32a3.5 3.5 0 0 0 2-3.16zM14 3.23v2.06a6 6 0 0 1 0 11.42v2.06a8 8 0 0 0 0-15.54z"/>
				</svg>
				<span class="vnra__listen-text"><?php esc_html_e( 'Listen to this post', 'voxnemesis-read-aloud' ); ?></span>
			</button>

			<div class="vnra__panel" id="<?php echo esc_attr( $id ); ?>-panel" hidden>
				<p class="vnra__status" role="status" aria-live="polite"></p>

				<div class="vnra__bar" aria-hidden="true"><span class="vnra__bar-fill"></span></div>

				<div class="vnra__controls">
					<button type="button" class="vnra__play" aria-label="<?php esc_attr_e( 'Play', 'voxnemesis-read-aloud' ); ?>">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>
					</button>
					<button type="button" class="vnra__stop" aria-label="<?php esc_attr_e( 'Stop', 'voxnemesis-read-aloud' ); ?>">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 6h12v12H6z"/></svg>
					</button>
					<span class="vnra__time vnra__time--current">0:00</span>
					<span class="vnra__time vnra__time--duration">0:00</span>
				</div>

				<div class="vnra__options">
					<?php if ( $this->settings->show_voice_picker() ) : ?>
						<label class="vnra__option">
							<span class="vnra__option-label"><?php esc_html_e( 'Voice', 'voxnemesis-read-aloud' ); ?></span>
							<select class="vnra__voice">
								<option value="default"><?php esc_html_e( 'The default voice', 'voxnemesis-read-aloud' ); ?></option>
							</select>
						</label>
					<?php endif; ?>

					<?php if ( $this->settings->show_speed() ) : ?>
						<label class="vnra__option">
							<span class="vnra__option-label"><?php esc_html_e( 'Speed', 'voxnemesis-read-aloud' ); ?></span>
							<input type="range" class="vnra__speed" min="0.5" max="2" step="0.05" value="<?php echo esc_attr( $this->settings->speed() ); ?>" />
							<output class="vnra__speed-value"><?php echo esc_html( number_format_i18n( $this->settings->speed(), 2 ) ); ?></output>
						</label>
					<?php endif; ?>
				</div>
			</div>

			<script type="application/json" class="vnra__text">
				<?php
				echo wp_json_encode(
					array_values( $paragraphs ),
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
				);
				?>
			</script>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
