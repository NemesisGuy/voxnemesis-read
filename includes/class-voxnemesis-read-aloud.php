<?php
/**
 * The plugin: hooks, assets, the wasm endpoint and the settings.
 *
 * @package VoxNemesis_Read_Aloud
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * VoxNemesis Read-Aloud.
 *
 * The speech is generated in the reader's own browser, by the Phonon model running in
 * WebAssembly. Nothing is sent to this site, and nothing is sent to a third party either:
 * the model weights are fetched once from a public host and kept in the browser's cache.
 */
final class VoxNemesis_Read_Aloud {

	/**
	 * The one instance.
	 *
	 * @var VoxNemesis_Read_Aloud|null
	 */
	private static $instance = null;

	/**
	 * The settings.
	 *
	 * @var VoxNemesis_Read_Aloud_Settings
	 */
	public $settings;

	/**
	 * The front end.
	 *
	 * @var VoxNemesis_Read_Aloud_Player
	 */
	public $player;

	/**
	 * The instance.
	 *
	 * @return VoxNemesis_Read_Aloud
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
		$this->settings = new VoxNemesis_Read_Aloud_Settings();
		$this->player   = new VoxNemesis_Read_Aloud_Player( $this->settings );

		add_action( 'init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_vnra_clear_cache', array( $this, 'handle_clear_cache' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_wasm' ), 0 );

		add_filter( 'the_content', array( $this->player, 'filter_content' ), 999 );
		add_filter( 'script_loader_tag', array( $this, 'module_script_tag' ), 10, 3 );
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
			__( 'Read-aloud', 'voxnemesis-read-aloud' ),
			array( $this, 'render_section_intro' ),
			'vnra-settings'
		);

		add_settings_field(
			'vnra_enabled',
			__( 'Enable the player', 'voxnemesis-read-aloud' ),
			array( $this, 'render_field_enabled' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_model',
			__( 'Model', 'voxnemesis-read-aloud' ),
			array( $this, 'render_field_model' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_voice',
			__( 'Voice', 'voxnemesis-read-aloud' ),
			array( $this, 'render_field_voice' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_speed',
			__( 'Speed', 'voxnemesis-read-aloud' ),
			array( $this, 'render_field_speed' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_position',
			__( 'Where the button goes', 'voxnemesis-read-aloud' ),
			array( $this, 'render_field_position' ),
			'vnra-settings',
			'vnra_general'
		);

		add_settings_field(
			'vnra_options_ui',
			__( 'Reader controls', 'voxnemesis-read-aloud' ),
			array( $this, 'render_field_options_ui' ),
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
			__( 'VoxNemesis Read-Aloud', 'voxnemesis-read-aloud' ),
			__( 'Read-Aloud', 'voxnemesis-read-aloud' ),
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
			<h1><?php esc_html_e( 'VoxNemesis Read-Aloud', 'voxnemesis-read-aloud' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'The speech is generated in the reader\'s browser. This site serves the player and, at most, a model file. It never runs the model and never sees the audio.', 'voxnemesis-read-aloud' ); ?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'vnra_settings_group' );
				do_settings_sections( 'vnra-settings' );
				submit_button();
				?>
			</form>
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
			<?php esc_html_e( 'The default model is the public Phonon 31m checkpoint, which speaks English. It is about 64 MB and is downloaded once per browser, then kept in the reader\'s cache.', 'voxnemesis-read-aloud' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'For another language, host the checkpoint yourself and point the model setting at it. The files are static; nothing on this site computes anything.', 'voxnemesis-read-aloud' ); ?>
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
			<?php esc_html_e( 'Show the listen button on posts and pages', 'voxnemesis-read-aloud' ); ?>
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
			<?php esc_html_e( 'A Hugging Face repo as hf://owner/name@revision, or the base URL of a checkpoint you host, ending in a slash.', 'voxnemesis-read-aloud' ); ?>
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
			<?php esc_html_e( 'A name from the voice catalog, or the checkpoint\'s own default. The catalog has about a hundred voices.', 'voxnemesis-read-aloud' ); ?>
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
		<?php
	}

	/**
	 * Which reader controls to show.
	 *
	 * @return void
	 */
	public function render_field_options_ui() {
		?>
		<label style="margin-right: 18px;">
			<input
				type="checkbox"
				name="vnra_options[show_voice_picker]"
				value="1"
				<?php checked( $this->settings->show_voice_picker() ); ?>
			/>
			<?php esc_html_e( 'Voice picker', 'voxnemesis-read-aloud' ); ?>
		</label>
		<label style="margin-right: 18px;">
			<input
				type="checkbox"
				name="vnra_options[show_speed]"
				value="1"
				<?php checked( $this->settings->show_speed() ); ?>
			/>
			<?php esc_html_e( 'Speed', 'voxnemesis-read-aloud' ); ?>
		</label>
		<label>
			<input
				type="checkbox"
				name="vnra_options[highlight]"
				value="1"
				<?php checked( $this->settings->highlight() ); ?>
			/>
			<?php esc_html_e( 'Highlight the paragraph being read', 'voxnemesis-read-aloud' ); ?>
		</label>
		<?php
	}

	/**
	 * Clean the settings on the way in.
	 *
	 * @param mixed $input The raw value.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$clean   = $this->settings->defaults();

		$clean['enabled']         = ! empty( $input['enabled'] );
		$clean['show_voice_picker'] = ! empty( $input['show_voice_picker'] );
		$clean['show_speed']      = ! empty( $input['show_speed'] );
		$clean['highlight']       = ! empty( $input['highlight'] );

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

		return $clean;
	}

	/**
	 * The settings, for the scripts and the markup.
	 *
	 * @return array
	 */
	public function script_config() {
		return array(
			'model'        => $this->settings->model(),
			'catalogUrl'   => self::CATALOG_URL,
			'voice'        => $this->settings->voice(),
			'speed'        => $this->settings->speed(),
			'lang'         => $this->settings->lang(),
			'showVoice'    => $this->settings->show_voice_picker(),
			'showSpeed'    => $this->settings->show_speed(),
			'highlight'    => $this->settings->highlight(),
			'wasmUrl'      => $this->wasm_url( 'relaxed' ),
			'simdWasmUrl'  => $this->wasm_url( 'simd' ),
			'workerUrl'    => $this->asset_url( 'assets/vendor/phonon-tts/worker.js' ),
			'strings'      => array(
				'listen'      => __( 'Listen to this post', 'voxnemesis-read-aloud' ),
				'stop'        => __( 'Stop', 'voxnemesis-read-aloud' ),
				'loading'     => __( 'Fetching the voice model. This is a one-off download of about 64 MB, kept in your browser afterwards.', 'voxnemesis-read-aloud' ),
				'ready'       => __( 'Ready to read.', 'voxnemesis-read-aloud' ),
				'playing'     => __( 'Reading', 'voxnemesis-read-aloud' ),
				'paused'      => __( 'Paused', 'voxnemesis-read-aloud' ),
				'finished'    => __( 'Finished.', 'voxnemesis-read-aloud' ),
				'unsupported' => __( 'Your browser cannot run this. It needs WebAssembly, and WebAssembly SIMD.', 'voxnemesis-read-aloud' ),
				'failed'      => __( 'That did not work.', 'voxnemesis-read-aloud' ),
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

		wp_enqueue_script(
			'vnra-reader',
			$this->asset_url( 'assets/js/reader.js' ),
			array(),
			VNRA_VERSION,
			true
		);

		wp_add_inline_script(
			'vnra-reader',
			'window.voxnemesisReadAloud = ' . wp_json_encode( $this->script_config() ) . ';',
			'before'
		);
	}

	/**
	 * Make the reader script a module.
	 *
	 * The runtime is plain ES modules, and the worker it starts is a module worker, so the
	 * script that imports it has to be a module too.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 * @param string $src    The script source.
	 * @return string
	 */
	public function module_script_tag( $tag, $handle, $src ) {
		if ( 'vnra-reader' !== $handle ) {
			return $tag;
		}
		return sprintf( '<script type="module" src="%s" id="%s-js"></script>' . "\n", esc_url( $src ), esc_attr( $handle ) );
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
