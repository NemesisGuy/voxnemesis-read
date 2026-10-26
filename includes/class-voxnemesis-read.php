<?php
/**
 * The plugin: hooks, assets, the wasm endpoint and the settings.
 *
 * @package voxnemesis_read
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * VoxNemesis Read.
 *
 * The speech is generated in the reader's own browser, by the Phonon model running in
 * WebAssembly. Nothing is sent to this site, and nothing is sent to a third party either:
 * the model weights are fetched once from a public host and kept in the browser's cache.
 */
final class voxnemesis_read {

	/**
	 * The one instance.
	 *
	 * @var voxnemesis_read|null
	 */
	private static $instance = null;

	/**
	 * The settings.
	 *
	 * @var voxnemesis_read_Settings
	 */
	public $settings;

	/**
	 * The front end.
	 *
	 * @var voxnemesis_read_Player
	 */
	public $player;

	/**
	 * The instance.
	 *
	 * @return voxnemesis_read
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the hooks.
	 *
	 * @return void
	 */
	public function boot() {
		$this->settings = new voxnemesis_read_Settings();
		$this->player   = new voxnemesis_read_Player( $this->settings );

		// The Settings API lives in wp-admin/includes, which the front end never loads.
		// Registering on init fataled the whole site: add_settings_section() does not exist
		// out here. admin_init is the hook that fires once those files are in.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// The config has to exist before the module runs. Modules are deferred, and this
		// prints during parsing, so it is always early enough.
		add_action( 'wp_footer', array( $this, 'print_config' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_serve_wasm' ), 0 );

		add_filter( 'the_content', array( $this->player, 'filter_content' ), 999 );
		add_filter( 'script_loader_tag', array( $this, 'module_script_tag' ), 10, 3 );

		add_shortcode( 'voxnemesis_read', array( $this->player, 'shortcode' ) );
	}

	/**
	 * The model the plugin uses by default: Phonon 31m, English, Apache-2.0, public.
	 *
	 * Pinned to a commit so a reader never gets a different model than the one the site
	 * was tested against, and so every file URL is immutable.
	 */
	const DEFAULT_MODEL = 'hf://gradium/phonon-31m-en@8c64b55ec301728e2d1695bfea87a08a267d0c2d';

	/**
	 * Where the voice catalog lives.
	 */
	const CATALOG_URL = 'https://huggingface.co/gradium/phonon-voices/resolve/main/voices.jsonl';

	/**
	 * Register the settings, their section and their fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'vnra_settings_group',
			'vnra_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->settings->defaults(),
			)
		);

		add_settings_section(
			'vnra_general',
			__( 'Read', 'voxnemesis-read' ),
			array( $this, 'render_section_intro' ),
			'vnra-settings'
		);

		add_settings_field(
			'vnra_enabled',
			__( 'Enable the player', 'voxnemesis-read' ),
			array( $this, 'render_field_enabled' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_post_types',
			__( 'Show on', 'voxnemesis-read' ),
			array( $this, 'render_field_post_types' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_model',
			__( 'Model', 'voxnemesis-read' ),
			array( $this, 'render_field_model' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_voice',
			__( 'Voice', 'voxnemesis-read' ),
			array( $this, 'render_field_voice' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_speed',
			__( 'Speed', 'voxnemesis-read' ),
			array( $this, 'render_field_speed' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_position',
			__( 'Where the button goes', 'voxnemesis-read' ),
			array( $this, 'render_field_position' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_appearance',
			__( 'Colours', 'voxnemesis-read' ),
			array( $this, 'render_field_appearance' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_options_ui',
			__( 'Reader controls', 'voxnemesis-read' ),
			array( $this, 'render_field_options_ui' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_show_credit',
			__( 'Credit', 'voxnemesis-read' ),
			array( $this, 'render_field_show_credit' ),
			'vnra-settings',
			'vnra_general'
		);
	}

	/**
	 * The settings page.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'VoxNemesis Read', 'voxnemesis-read' ),
			__( 'Read', 'voxnemesis-read' ),
			'manage_options',
			'vnra-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * The settings page itself.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VoxNemesis Read', 'voxnemesis-read' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'The speech is generated in the reader\'s browser. This site serves the player and, at most, a model file. It never runs the model and never sees the audio.', 'voxnemesis-read' ); ?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'vnra_settings_group' );
				do_settings_sections( 'vnra-settings' );
				submit_button();
				?>
			</form>

			<hr style="margin: 2rem 0 1rem; border: none; border-top: 1px solid #ccd0d4;" />
			<p style="color: #787c82; font-size: 13px; margin: 0;">
				<strong>VoxNemesis Read</strong> <?php echo esc_html( VNRA_VERSION ); ?>
				&middot; Made by <a href="<?php echo esc_url( VNRA_CREDIT_URL ); ?>" target="_blank" rel="noopener">NemesisNet</a>
				&middot; <a href="https://github.com/NemesisGuy/voxnemesis-read" target="_blank" rel="noopener">Documentation</a>
				&middot; <a href="https://nemesisnet.co.za" target="_blank" rel="noopener">Support</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The section's introduction.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		?>
		<p>
			<?php esc_html_e( 'The default model is the public Phonon 31m checkpoint, which speaks English. It is about 64 MB and is downloaded once per browser, then kept in the reader\'s cache.', 'voxnemesis-read' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'For another language, host the checkpoint yourself and point the model setting at it. The files are static; nothing on this site computes anything.', 'voxnemesis-read' ); ?>
		</p>
		<?php
	}

	/**
	 * Whether the player is on.
	 *
	 * @return void
	 */
	public function render_field_enabled() {
		?>
		<label>
			<input
				type="checkbox"
				name="vnra_options[enabled]"
				value="1"
				<?php checked( $this->settings->enabled() ); ?>
			/>
			<?php esc_html_e( 'Show the listen button on posts and pages', 'voxnemesis-read' ); ?>
		</label>
		<?php
	}

	/**
	 * The model.
	 *
	 * @return void
	 */
	public function render_field_model() {
		?>
		<input
			type="text"
			class="regular-text code"
			name="vnra_options[model]"
			value="<?php echo esc_attr( $this->settings->model() ); ?>"
		/>
		<p class="description">
			<?php esc_html_e( 'A Hugging Face repo as hf://owner/name@revision, or the base URL of a checkpoint you host, ending in a slash.', 'voxnemesis-read' ); ?>
		</p>
		<?php
	}

	/**
	 * The default voice.
	 *
	 * @return void
	 */
	public function render_field_voice() {
		?>
		<input
			type="text"
			class="regular-text"
			name="vnra_options[voice]"
			value="<?php echo esc_attr( $this->settings->voice() ); ?>"
			placeholder="Easton"
		/>
		<p class="description">
			<?php esc_html_e( 'A name from the voice catalog, or the checkpoint\'s own default. The catalog has about a hundred voices.', 'voxnemesis-read' ); ?>
		</p>
		<?php
	}

	/**
	 * The default speed.
	 *
	 * @return void
	 */
	public function render_field_speed() {
		?>
		<input
			type="range"
			min="0.5"
			max="2"
			step="0.05"
			name="vnra_options[speed]"
			value="<?php echo esc_attr( $this->settings->speed() ); ?>"
		/>
		<output><?php echo esc_html( $this->settings->speed() ); ?></output>
		<?php
	}

	/**
	 * Where the button goes.
	 *
	 * @return void
	 */
	public function render_field_position() {
		?>
		<select name="vnra_options[position]">
			<?php foreach ( $this->settings->positions() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->settings->position(), $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the shortcode. */
				esc_html__( 'For the last option, place %s in the post, or use a block that runs shortcodes.', 'voxnemesis-read' ),
				'<code>[voxnemesis_read]</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * How the player takes its colours.
	 *
	 * @return void
	 */
	public function render_field_appearance() {
		?>
		<select name="vnra_options[style]">
			<?php foreach ( $this->settings->styles() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->settings->style(), $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Follow the theme is the right answer unless your theme does something unusual. The player takes its text, borders and background from the page, so a light theme gives it dark text and a dark theme gives it light text, and a theme with its own light/dark switch carries the player with it.', 'voxnemesis-read' ); ?>
		</p>

		<p style="margin-top:12px;">
			<label style="margin-right:18px;">
				<?php esc_html_e( 'Accent', 'voxnemesis-read' ); ?><br />
				<input type="color" name="vnra_options[accent]" value="<?php echo esc_attr( $this->settings->accent() ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Second accent', 'voxnemesis-read' ); ?><br />
				<input type="color" name="vnra_options[accent_2]" value="<?php echo esc_attr( $this->settings->accent_2() ); ?>" />
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'The two colours of the gradient: the play button, the progress fill and the level meter. The defaults are the NemesisNet blue and teal.', 'voxnemesis-read' ); ?>
		</p>
		<?php
	}

	/**
	 * Which reader controls to show.
	 *
	 * @return void
	 */
	public function render_field_options_ui() {
		$toggles = array(
			'show_voice_picker' => __( 'Voice picker', 'voxnemesis-read' ),
			'show_speed'        => __( 'Speed', 'voxnemesis-read' ),
			'show_volume'       => __( 'Volume', 'voxnemesis-read' ),
			'show_skip'         => __( 'Previous and next paragraph', 'voxnemesis-read' ),
			'show_seek'         => __( 'Paragraph slider', 'voxnemesis-read' ),
			'highlight'         => __( 'Highlight the paragraph being read', 'voxnemesis-read' ),
		);

		echo '<fieldset>';
		foreach ( $toggles as $key => $label ) {
			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%s" value="1" %s /> %s</label>',
				esc_attr( 'vnra_options[' . $key . ']' ),
				checked( (bool) $this->settings->get( $key ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Whether the opt-in credit is shown.
	 *
	 * @return void
	 */
	public function render_field_show_credit() {
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( 'vnra_options[show_credit]' ),
			checked( $this->settings->show_credit(), true, false ),
			esc_html__( 'Show "Powered by VoxNemesis Read" credit under the player', 'voxnemesis-read' )
		);
		echo '<p class="description">' . esc_html__( 'Optional. Adds a small credit link below the player. Off by default. Thank you if you enable it.', 'voxnemesis-read' ) . '</p>';
	}

	/**
	 * Which post types get a player.
	 *
	 * @return void
	 */
	public function render_field_post_types() {
		echo '<fieldset>';
		foreach ( $this->settings->available_post_types() as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="vnra_options[post_types][]" value="%s" %s /> %s</label>',
				esc_attr( $value ),
				checked( $this->settings->applies_to( $value ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">';
		esc_html_e( 'Where the listen button is added automatically.', 'voxnemesis-read' );
		echo '</p>';
	}

	/**
	 * Clean the settings on the way in.
	 *
	 * @param mixed $input The raw value.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$clean = $this->settings->defaults();

		$clean['enabled'] = ! empty( $input['enabled'] );

		$flags = array( 'show_voice_picker', 'show_speed', 'show_volume', 'show_skip', 'show_seek', 'highlight', 'show_credit' );
		foreach ( $flags as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		// Post types: only the public ones this site actually has, and never an empty list
		// by accident (a form that posts no boxes means none were ticked, which is a choice).
		$clean['post_types'] = array();
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$available = array_keys( $this->settings->available_post_types() );
			foreach ( $input['post_types'] as $type ) {
				$type = sanitize_key( $type );
				if ( in_array( $type, $available, true ) ) {
					$clean['post_types'][] = $type;
				}
			}
		}

		if ( isset( $input['model'] ) ) {
			$model = trim( (string) $input['model'] );
			if ( '' !== $model ) {
				$clean['model'] = $model;
			}
		}

		if ( isset( $input['voice'] ) ) {
			$clean['voice'] = sanitize_text_field( (string) $input['voice'] );
		}

		if ( isset( $input['speed'] ) ) {
			$clean['speed'] = (float) min( 2, max( 0.5, (float) $input['speed'] ) );
		}

		if ( isset( $input['position'] ) && array_key_exists( $input['position'], $this->settings->positions() ) ) {
			$clean['position'] = $input['position'];
		}

		if ( isset( $input['style'] ) && array_key_exists( $input['style'], $this->settings->styles() ) ) {
			$clean['style'] = $input['style'];
		}

		foreach ( array( 'accent', 'accent_2' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$colour = voxnemesis_read_Settings::sanitize_colour( $input[ $key ] );
			if ( '' !== $colour ) {
				$clean[ $key ] = $colour;
			}
		}

		return $clean;
	}

	/**
	 * The site's two accent colours, as custom properties.
	 *
	 * Only the accents are printed. Every neutral is derived in the stylesheet from the
	 * page's own text colour, so there is nothing here to get out of step with the theme.
	 *
	 * @return string
	 */
	public function inline_styles() {
		$accent   = voxnemesis_read_Settings::sanitize_colour( $this->settings->accent() );
		$accent_2 = voxnemesis_read_Settings::sanitize_colour( $this->settings->accent_2() );

		if ( '' === $accent || '' === $accent_2 ) {
			return '';
		}

		// The stylesheet declares the default accents on the doubled selector
		// (.vnra.vnra) so theme rules cannot knock them over. These custom values
		// have to match that specificity or the owner's colours never win.
		return sprintf(
			'.vnra.vnra,.vnra-para{--vnra-accent:%1$s;--vnra-accent-2:%2$s;}',
			$accent,
			$accent_2
		);
	}

	/**
	 * The settings, for the scripts and the markup.
	 *
	 * @return array
	 */
	public function script_config() {
		return array(
			'model'       => $this->settings->model(),
			'catalogUrl'  => self::CATALOG_URL,
			'voice'       => $this->settings->voice(),
			'speed'       => $this->settings->speed(),
			'lang'        => $this->settings->lang(),
			'showVoice'   => $this->settings->show_voice_picker(),
			'showSpeed'   => $this->settings->show_speed(),
			'showVolume'  => $this->settings->show_volume(),
			'showSkip'    => $this->settings->show_skip(),
			'showSeek'    => $this->settings->show_seek(),
			'highlight'   => $this->settings->highlight(),
			'wasmUrl'     => $this->wasm_url( 'relaxed' ),
			'simdWasmUrl' => $this->wasm_url( 'simd' ),
			'workerUrl'   => $this->asset_url( 'assets/vendor/phonon-tts/worker.js' ),
			'strings'     => array(
				'listen'      => __( 'Listen to this post', 'voxnemesis-read' ),
				'stop'        => __( 'Stop', 'voxnemesis-read' ),
				'loading'     => __( 'Fetching the voice model. About 64 MB, once. Your browser keeps it afterwards.', 'voxnemesis-read' ),
				'downloading' => __( 'Fetching the model', 'voxnemesis-read' ),
				'ready'       => __( 'Ready to read.', 'voxnemesis-read' ),
				'playing'     => __( 'Reading', 'voxnemesis-read' ),
				'paused'      => __( 'Paused', 'voxnemesis-read' ),
				'finished'    => __( 'Finished.', 'voxnemesis-read' ),
				'paragraph'   => __( 'Paragraph %1$s of %2$s', 'voxnemesis-read' ),
				'unsupported' => __( 'Your browser cannot run this. It needs WebAssembly, and WebAssembly SIMD.', 'voxnemesis-read' ),
				'failed'      => __( 'That did not work.', 'voxnemesis-read' ),
			),
		);
	}

	/**
	 * The URL of an asset in the plugin.
	 *
	 * @param string $path The path, from the plugin root.
	 * @return string
	 */
	public function asset_url( $path ) {
		return plugins_url( $path, VNRA_FILE );
	}

	/**
	 * The URL of a wasm build.
	 *
	 * The wasm is served by the plugin rather than straight off disk, because a browser
	 * refuses to compile a module that was not served as application/wasm, and not every
	 * host is configured for that.
	 *
	 * @param string $variant Either 'relaxed' or 'simd'.
	 * @return string
	 */
	private function wasm_url( $variant ) {
		return add_query_arg(
			array(
				'vnra_wasm' => $variant,
				'ver'        => VNRA_VERSION,
			),
			home_url( '/' )
		);
	}

	/**
	 * Enqueue the player.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->settings->enabled() ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		wp_enqueue_style(
			'vnra-reader',
			$this->asset_url( 'assets/css/reader.css' ),
			array(),
			VNRA_VERSION
		);

		$inline = $this->inline_styles();
		if ( '' !== $inline ) {
			wp_add_inline_style( 'vnra-reader', $inline );
		}

		wp_enqueue_script(
			'vnra-reader',
			$this->asset_url( 'assets/js/reader.js' ),
			array(),
			VNRA_VERSION,
			true
		);
	}

	/**
	 * The config the module reads.
	 *
	 * Printed on its own rather than with wp_add_inline_script(): that data is prepended to
	 * the script tag, and the script tag is filtered below to make it a module. Rebuilding
	 * the tag there silently dropped the config, and the module fell back to the runtime's
	 * defaults. A separate tag cannot be swallowed by the filter.
	 *
	 * @return void
	 */
	public function print_config() {
		if ( ! wp_script_is( 'vnra-reader', 'enqueued' ) ) {
			return;
		}

		wp_print_inline_script_tag(
			'window.voxnemesisRead = ' . wp_json_encode( $this->script_config() ) . ';',
			array( 'id' => 'vnra-reader-config' )
		);
	}

	/**
	 * Make the reader script a module.
	 *
	 * The runtime is plain ES modules, and the worker it starts is a module worker, so the
	 * script that imports it has to be a module too.
	 *
	 * The tag is edited in place. Rebuilding it was a mistake worth writing down: WordPress
	 * concatenates the translations and any inline scripts into the same string before this
	 * filter runs, so returning a fresh tag throws them away.
	 *
	 * @param string $tag    The script tag, and anything WordPress put in front of it.
	 * @param string $handle The script handle.
	 * @param string $src    The script source.
	 * @return string
	 */
	public function module_script_tag( $tag, $handle, $src ) {
		if ( 'vnra-reader' !== $handle ) {
			return $tag;
		}

		$quoted = preg_quote( $src, '~' );
		// esc_url() may have encoded the ampersands in the query string.
		$quoted = str_replace( '&', '(?:&|&#0?38;|&amp;)', $quoted );

		$replaced = preg_replace(
			'~<script(?=[^>]*\ssrc=["\']' . $quoted . '["\'])~i',
			'<script type="module"',
			$tag,
			1
		);

		return null === $replaced ? $tag : $replaced;
	}

	/**
	 * Serve the wasm, if that is what was asked for.
	 *
	 * @return void
	 */
	public function maybe_serve_wasm() {
		if ( ! isset( $_GET['vnra_wasm'] ) ) {
			return;
		}

		$variant = sanitize_key( wp_unslash( $_GET['vnra_wasm'] ) );
		$files   = array(
			'relaxed' => 'assets/vendor/phonon-tts/wasm/phonon_tts_bg.wasm',
			'simd'    => 'assets/vendor/phonon-tts/wasm/phonon_tts_simd_bg.wasm',
		);

		if ( ! isset( $files[ $variant ] ) ) {
			status_header( 404 );
			exit;
		}

		$path = VNRA_PATH . $files[ $variant ];
		if ( ! file_exists( $path ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/wasm' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path );
		exit;
	}
}
