<?php
/**
 * The front end: the markup, and the paragraphs the reader speaks.
 *
 * @package voxnemasis_read
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * VoxNemasis Read player.
 */
final class voxnemasis_read_Player {

	/**
	 * The settings.
	 *
	 * @var voxnemasis_read_Settings
	 */
	private $settings;

	/**
	 * A counter, so two players on one page have different ids.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Whether the player has already been placed on this request.
	 *
	 * @var bool
	 */
	private $placed = false;

	/**
	 * Forget that a player was placed, so another one can be.
	 *
	 * @return void
	 */
	public function reset() {
		$this->placed = false;
	}

	/**
	 * Constructor.
	 *
	 * @param voxnemasis_read_Settings $settings The settings.
	 */
	public function __construct( voxnemasis_read_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Wrap the paragraphs so the reader knows what to speak, and add the player.
	 *
	 * The paragraphs are not copied into the markup. The reader reads them from the page
	 * itself, so the text it speaks and the text on the screen cannot drift apart, and a
	 * player placed by shortcode works exactly like an inserted one.
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

		$post_type = get_post_type();
		if ( $post_type && ! $this->settings->applies_to( $post_type ) ) {
			return $content;
		}

		$marked = $this->mark_paragraphs( $content );

		if ( 'shortcode' === $this->settings->position() ) {
			return $marked;
		}
		if ( $this->placed ) {
			return $marked;
		}

		$player = $this->markup( 'floating' === $this->settings->position() ? 'floating' : 'inline' );

		if ( 'before_content' === $this->settings->position() ) {
			return $player . $marked;
		}

		return $marked . $player;
	}

	/**
	 * The shortcode: place the player wherever the author asks for it.
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( ! $this->settings->enabled() || $this->placed ) {
			return '';
		}

		return $this->markup( 'inline' );
	}

	/**
	 * Add the paragraph markers.
	 *
	 * @param string $content The content.
	 * @return string
	 */
	private function mark_paragraphs( $content ) {
		$index = 0;

		$marked = preg_replace_callback(
			'/<p\b([^>]*)>(.*?)<\/p>/is',
			function ( $matches ) use ( &$index ) {
				$text = $this->paragraph_text( $matches[2] );
				if ( '' === $text ) {
					return $matches[0];
				}

				$attrs = $matches[1];
				if ( false === strpos( $attrs, 'data-vnra-para' ) ) {
					$attrs .= sprintf( ' data-vnra-para="%d"', $index );
				}

				$index++;

				return '<p' . $attrs . '>' . $matches[2] . '</p>';
			},
			$content
		);

		return null === $marked ? $content : $marked;
	}

	/**
	 * The plain text of a paragraph, to decide whether it is worth speaking.
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
	 * @param string $variant Either 'inline' or 'floating'.
	 * @return string
	 */
	private function markup( $variant = 'inline' ) {
		$this->placed = true;

		self::$instance++;
		$id = 'vnra-' . self::$instance;

		ob_start();
		?>
		<div
			class="vnra vnra--<?php echo esc_attr( $variant ); ?>"
			id="<?php echo esc_attr( $id ); ?>"
			data-vnra-player
			data-playing="false"
			data-busy="false"
			data-vnra-style="<?php echo esc_attr( $this->settings->style() ); ?>"
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
				<span class="vnra__listen-text"><?php esc_html_e( 'Listen to this post', 'voxnemasis-read' ); ?></span>
			</button>

			<div class="vnra__panel" id="<?php echo esc_attr( $id ); ?>-panel" hidden>
				<p class="vnra__status" role="status" aria-live="polite">
					<span class="vnra__spinner" aria-hidden="true"></span>
					<span class="vnra__status-text"></span>
				</p>

				<div class="vnra__download" aria-hidden="true"><span class="vnra__download-fill"></span></div>

				<div class="vnra__meter" aria-hidden="true">
					<?php for ( $bar = 0; $bar < 12; $bar++ ) : ?>
						<span></span>
					<?php endfor; ?>
				</div>

				<?php if ( $this->settings->show_seek() ) : ?>
					<label class="vnra__seek-wrap">
						<span class="screen-reader-text"><?php esc_html_e( 'Jump to a paragraph', 'voxnemasis-read' ); ?></span>
						<input
							type="range"
							class="vnra__seek"
							min="0"
							max="0"
							step="1"
							value="0"
							disabled
							aria-label="<?php esc_attr_e( 'Jump to a paragraph', 'voxnemasis-read' ); ?>"
						/>
						<span class="vnra__seek-count"></span>
					</label>
				<?php endif; ?>

				<div class="vnra__controls">
					<?php if ( $this->settings->show_skip() ) : ?>
						<button type="button" class="vnra__prev vnra__button" aria-label="<?php esc_attr_e( 'Previous paragraph', 'voxnemasis-read' ); ?>">
							<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 5h2v14H6zM20 5v14L9 12z"/></svg>
						</button>
					<?php endif; ?>

					<button type="button" class="vnra__play vnra__button vnra__button--primary" aria-label="<?php esc_attr_e( 'Play', 'voxnemasis-read' ); ?>">
						<svg class="vnra__icon-play" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>
						<svg class="vnra__icon-pause" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 5h4v14H7zm6 0h4v14h-4z"/></svg>
					</button>

					<?php if ( $this->settings->show_skip() ) : ?>
						<button type="button" class="vnra__next vnra__button" aria-label="<?php esc_attr_e( 'Next paragraph', 'voxnemasis-read' ); ?>">
							<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg>
						</button>
					<?php endif; ?>

					<button type="button" class="vnra__stop vnra__button" aria-label="<?php esc_attr_e( 'Stop', 'voxnemasis-read' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 6h12v12H6z"/></svg>
					</button>

					<span class="vnra__time vnra__time--current">0:00</span>
					<span class="vnra__time vnra__time-separator" aria-hidden="true">/</span>
					<span class="vnra__time vnra__time--duration">0:00</span>
				</div>

				<div class="vnra__options">
					<?php if ( $this->settings->show_voice_picker() ) : ?>
						<label class="vnra__option">
							<span class="vnra__option-label"><?php esc_html_e( 'Voice', 'voxnemasis-read' ); ?></span>
							<select class="vnra__voice">
								<option value="default"><?php esc_html_e( 'The default voice', 'voxnemasis-read' ); ?></option>
							</select>
						</label>
					<?php endif; ?>

					<?php if ( $this->settings->show_speed() ) : ?>
						<label class="vnra__option">
							<span class="vnra__option-label"><?php esc_html_e( 'Speed', 'voxnemasis-read' ); ?></span>
							<input type="range" class="vnra__speed" min="0.5" max="2" step="0.05" value="<?php echo esc_attr( $this->settings->speed() ); ?>" />
							<output class="vnra__speed-value"><?php echo esc_html( number_format_i18n( $this->settings->speed(), 2 ) ); ?></output>
						</label>
					<?php endif; ?>

					<?php if ( $this->settings->show_volume() ) : ?>
						<label class="vnra__option">
							<span class="vnra__option-label"><?php esc_html_e( 'Volume', 'voxnemasis-read' ); ?></span>
							<input type="range" class="vnra__volume" min="0" max="1" step="0.05" value="1" />
						</label>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $this->settings->show_credit() ) : ?>
				<?php
				$credit_html = sprintf(
					'<p class="vnra__credit"><a href="%s" target="_blank" rel="nofollow noopener">%s</a></p>',
					esc_url( VNRA_CREDIT_URL ),
					esc_html__( 'Powered by VoxNemasis Read', 'voxnemasis-read' )
				);
				echo apply_filters( 'vnra_credit_html', $credit_html );
				?>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
