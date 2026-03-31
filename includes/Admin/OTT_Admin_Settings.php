<?php
/**
 * Admin settings page for Open Tongue Translations.
 *
 * Registers a top-level "Open Tongue" menu entry and renders five tabbed
 * panels: Dashboard (system health), Translation Engine, Connectivity,
 * Exclusions & Glossary, and Performance.
 *
 * All option persistence goes through the WordPress Settings API.
 * Exclusion-rule CRUD uses admin_post_ handlers with wp_nonce_field / check_admin_referer.
 *
 * @package OpenToungeTranslations\Admin
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Admin;

use OpenToungeTranslations\Compat\CloudflareCompat;
use OpenToungeTranslations\Compat\WpRocketCompat;
use OpenToungeTranslations\Exclusion\ExclusionRule;
use OpenToungeTranslations\Exclusion\ExclusionRuleRepository;

/**
 * Class OTT_Admin_Settings
 *
 * Singleton. Instantiate once via get_instance()->init() on plugins_loaded.
 */
final class OTT_Admin_Settings {

	/** @var self|null */
	private static ?self $instance = null;

	/** Admin page / menu slug. */
	private const PAGE_SLUG = 'open-tongue';

	/** Ordered tab definitions: slug => display label. */
	private const TABS = [
		'dashboard'  => 'Dashboard',
		'engine'     => 'Translation Engine',
		'connectivity' => 'Connectivity',
		'exclusions' => 'Exclusions & Glossary',
		'performance'  => 'Performance',
		'manual_edits' => 'Manual Edits',
		'integrity'    => 'Integrity Monitor',
	];

	/**
	 * BCP-47 language codes supported by LibreTranslate (common subset).
	 * Administrators may still type any valid code directly.
	 *
	 * @var array<string,string>
	 */
	private const LANGUAGES = [
		'ar' => 'Arabic',
		'az' => 'Azerbaijani',
		'zh' => 'Chinese',
		'cs' => 'Czech',
		'da' => 'Danish',
		'nl' => 'Dutch',
		'en' => 'English',
		'fi' => 'Finnish',
		'fr' => 'French',
		'de' => 'German',
		'el' => 'Greek',
		'he' => 'Hebrew',
		'hi' => 'Hindi',
		'hu' => 'Hungarian',
		'id' => 'Indonesian',
		'it' => 'Italian',
		'ja' => 'Japanese',
		'ko' => 'Korean',
		'ms' => 'Malay',
		'nb' => 'Norwegian',
		'fa' => 'Persian',
		'pl' => 'Polish',
		'pt' => 'Portuguese',
		'ro' => 'Romanian',
		'ru' => 'Russian',
		'sk' => 'Slovak',
		'es' => 'Spanish',
		'sv' => 'Swedish',
		'th' => 'Thai',
		'tr' => 'Turkish',
		'uk' => 'Ukrainian',
		'vi' => 'Vietnamese',
	];

	/** Prevent direct instantiation. */
	private function __construct() {}

	/**
	 * Return or lazily create the singleton instance.
	 */
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire all WordPress hooks.
	 * Call once from plugins_loaded (with is_admin() guard in bootstrap).
	 */
	public function init(): void {
		add_action( 'admin_menu',    [ $this, 'register_menu' ] );
		add_action( 'admin_init',    [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_ott_save_exclusion_rule',   [ $this, 'handle_save_exclusion_rule' ] );
		add_action( 'admin_post_ott_delete_exclusion_rule', [ $this, 'handle_delete_exclusion_rule' ] );
		add_action( 'admin_post_ott_toggle_exclusion_rule', [ $this, 'handle_toggle_exclusion_rule' ] );
		add_action( 'admin_post_ott_update_manual_edit',    [ $this, 'handle_update_manual_edit' ] );
	}

	// =========================================================================
	// Asset enqueueing
	// =========================================================================

	/**
	 * Enqueue the batch progress JS only on the Open Tongue settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		$plugin_url = plugin_dir_url( dirname( __DIR__, 1 ) . '/open-tounge-translations.php' );
		$js_path    = plugin_dir_path( dirname( __DIR__, 1 ) . '/open-tounge-translations.php' ) . 'assets/js/ott-admin-batch.js';

		if ( ! file_exists( $js_path ) ) {
			return;
		}

		wp_enqueue_script(
			'ott-admin-batch',
			$plugin_url . 'assets/js/ott-admin-batch.js',
			[],
			(string) filemtime( $js_path ),
			true
		);

		// Resolve active job for auto-resume on page reload.
		$active_job_id = (string) ( get_transient( 'ott_batch_latest_job' ) ?: '' );

		wp_localize_script(
			'ott-admin-batch',
			'ottBatch',
			[
				'restBase'    => rest_url(),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'targetLang'  => (string) get_option( 'ltp_target_lang', 'en' ),
				'activeJobId' => $active_job_id,
				'i18n'        => [
					'start'   => __( 'Run Batch Translation', 'open-tongue-translations' ),
					'running' => __( 'Running…', 'open-tongue-translations' ),
					'done'    => __( 'Complete ✓', 'open-tongue-translations' ),
					'idle'    => __( 'Idle', 'open-tongue-translations' ),
					'eta'     => __( 'ETA: %s', 'open-tongue-translations' ),
				],
			]
		);
	}

	// =========================================================================
	// Menu
	// =========================================================================

	/**
	 * Register the top-level admin menu entry.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Open Tongue Translations', 'open-tongue-translations' ),
			__( 'Open Tongue', 'open-tongue-translations' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-translation',
			81
		);
	}

	// =========================================================================
	// Settings API registration
	// =========================================================================

	/**
	 * Register all settings, sections, and fields for the three Settings-API tabs.
	 */
	public function register_settings(): void {

		// ── Translation Engine ───────────────────────────────────────────────

		register_setting(
			'ott_engine',
			'ltp_target_lang',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_lang_tag' ],
				'default'           => 'en',
			]
		);

		register_setting(
			'ott_engine',
			'ltp_detect_browser_locale',
			[
				'type'              => 'integer',
				'sanitize_callback' => 'intval',
				'default'           => 0,
			]
		);

		add_settings_section( 'ott_engine_main', '', '__return_false', 'ott_engine' );

		add_settings_field(
			'ltp_target_lang',
			__( 'Target Language', 'open-tongue-translations' ),
			[ $this, 'field_target_lang' ],
			'ott_engine',
			'ott_engine_main'
		);

		add_settings_field(
			'ltp_detect_browser_locale',
			__( 'Detect Browser Locale', 'open-tongue-translations' ),
			[ $this, 'field_detect_browser_locale' ],
			'ott_engine',
			'ott_engine_main'
		);

		// ── Connectivity ─────────────────────────────────────────────────────

		$connectivity_options = [
			'ltp_connection_mode' => [ 'string',  [ $this, 'sanitize_connection_mode' ], 'localhost'                 ],
			'ltp_localhost_host'  => [ 'string',  'sanitize_text_field',                '127.0.0.1'                 ],
			'ltp_localhost_port'  => [ 'integer', [ $this, 'sanitize_port' ],            5000                       ],
			'ltp_socket_path'     => [ 'string',  'sanitize_text_field',                '/tmp/libretranslate.sock'  ],
			'ltp_vpc_ip'          => [ 'string',  [ $this, 'sanitize_ip' ],              ''                         ],
			'ltp_vpc_port'        => [ 'integer', [ $this, 'sanitize_port' ],            5000                       ],
			'ltp_vpc_api_key'     => [ 'string',  'sanitize_text_field',                ''                         ],
		];

		foreach ( $connectivity_options as $option_name => [ $type, $sanitize, $default ] ) {
			register_setting(
				'ott_connectivity',
				$option_name,
				[
					'type'              => $type,
					'sanitize_callback' => $sanitize,
					'default'           => $default,
				]
			);
		}

		// ── Performance ──────────────────────────────────────────────────────

		register_setting(
			'ott_performance',
			'ltp_prune_days',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ $this, 'sanitize_prune_days' ],
				'default'           => 90,
			]
		);

		register_setting(
			'ott_performance',
			'ltp_compat_wprocket',
			[
				'type'              => 'integer',
				'sanitize_callback' => 'intval',
				'default'           => 1,
			]
		);

		register_setting(
			'ott_performance',
			'ltp_compat_cloudflare',
			[
				'type'              => 'integer',
				'sanitize_callback' => 'intval',
				'default'           => 1,
			]
		);

		add_settings_section( 'ott_performance_main', '', '__return_false', 'ott_performance' );

		add_settings_field(
			'ltp_prune_days',
			__( 'Prune stale rows after', 'open-tongue-translations' ),
			[ $this, 'field_prune_days' ],
			'ott_performance',
			'ott_performance_main'
		);

		add_settings_field(
			'ltp_compat_wprocket',
			__( 'WP Rocket compatibility', 'open-tongue-translations' ),
			[ $this, 'field_compat_wprocket' ],
			'ott_performance',
			'ott_performance_main'
		);

		add_settings_field(
			'ltp_compat_cloudflare',
			__( 'Cloudflare compatibility', 'open-tongue-translations' ),
			[ $this, 'field_compat_cloudflare' ],
			'ott_performance',
			'ott_performance_main'
		);
	}

	// =========================================================================
	// Page renderer
	// =========================================================================

	/**
	 * Main page callback — renders the full settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Open Tongue Translations', 'open-tongue-translations' ); ?></h1>

			<?php $this->render_privacy_guard(); ?>

			<nav class="nav-tab-wrapper" style="margin-bottom:0;">
				<?php foreach ( self::TABS as $slug => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"
					   class="nav-tab<?php echo $active_tab === $slug ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ott-tab-content" style="margin-top:20px;">
				<?php
				match ( $active_tab ) {
					'engine'       => $this->render_engine_tab(),
					'connectivity' => $this->render_connectivity_tab(),
					'exclusions'   => $this->render_exclusions_tab(),
					'performance'  => $this->render_performance_tab(),
					'manual_edits' => $this->render_manual_edits_tab(),
					'integrity'    => $this->render_integrity_tab(),
					default        => $this->render_dashboard_tab(),
				};
				?>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// Privacy Guard
	// =========================================================================

	/**
	 * Render the persistent privacy-status banner beneath the page heading.
	 *
	 * Green when all data stays on the host (localhost / socket / RFC1918 VPC).
	 * Red when the configured VPC IP is publicly routable.
	 */
	private function render_privacy_guard(): void {
		$is_local = $this->is_data_local();
		$mode     = esc_html( (string) get_option( 'ltp_connection_mode', 'localhost' ) );

		if ( $is_local ) {
			$color   = '#00a32a';
			$icon    = '&#10003;';
			$message = sprintf(
				/* translators: %s: connection mode identifier */
				__( 'Privacy: Data stays local — routing via <strong>%s</strong> mode.', 'open-tongue-translations' ),
				$mode
			);
		} else {
			$color   = '#d63638';
			$icon    = '&#9888;';
			$message = sprintf(
				/* translators: %s: connection mode identifier */
				__( 'Privacy Warning: <strong>Data may leave this server.</strong> The <strong>%s</strong> VPC IP is not a private (RFC1918) address. Verify your network topology.', 'open-tongue-translations' ),
				$mode
			);
		}
		?>
		<div style="
			display:flex;
			align-items:center;
			gap:10px;
			padding:10px 14px;
			margin:12px 0 4px;
			border-left:4px solid <?php echo esc_attr( $color ); ?>;
			background:#fff;
			box-shadow:0 1px 1px rgba(0,0,0,.04);
			font-size:13px;
		">
			<span style="font-size:17px;color:<?php echo esc_attr( $color ); ?>;"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe HTML entity ?></span>
			<span><?php echo wp_kses( $message, [ 'strong' => [] ] ); ?></span>
		</div>
		<?php
	}

	// =========================================================================
	// Dashboard tab
	// =========================================================================

	private function render_dashboard_tab(): void {
		$checks = $this->build_health_checks();
		$active_lang = esc_attr( (string) get_option( 'ltp_target_lang', 'en' ) );
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'System Health', 'open-tongue-translations' ); ?></h2>
		<div class="card" style="max-width:820px;padding:0;">
			<table class="widefat striped" style="border:none;">
				<thead>
					<tr>
						<th style="width:36px;text-align:center;"><?php esc_html_e( 'Status', 'open-tongue-translations' ); ?></th>
						<th style="width:200px;"><?php esc_html_e( 'System', 'open-tongue-translations' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'open-tongue-translations' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $checks as $check ) : ?>
						<?php
						[ $icon, $color ] = match ( $check['status'] ) {
							'ok'   => [ '&#10003;', '#00a32a' ],
							'warn' => [ '&#33;',    '#dba617' ],
							default => [ '&#10007;', '#d63638' ],
						};
						?>
						<tr>
							<td style="text-align:center;">
								<span style="font-weight:bold;color:<?php echo esc_attr( $color ); ?>;"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe HTML entity ?></span>
							</td>
							<td><?php echo esc_html( $check['system'] ); ?></td>
							<td><?php echo esc_html( $check['detail'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<h2><?php esc_html_e( 'Batch Translation', 'open-tongue-translations' ); ?></h2>
		<div class="card" style="max-width:600px;padding:20px;">
			<p class="description" style="margin-bottom:12px;">
				<?php
				printf(
					/* translators: %s: current target locale */
					wp_kses( __( 'Pre-translate all uncached strings into the current target locale (%s). Progress updates every few seconds.', 'open-tongue-translations' ), [ 'strong' => [] ] ),
					'<strong>' . esc_html( (string) get_option( 'ltp_target_lang', 'en' ) ) . '</strong>'
				);
				?>
			</p>

			<button id="ott-batch-start-btn" class="button button-primary">
				<?php esc_html_e( 'Run Batch Translation', 'open-tongue-translations' ); ?>
			</button>
			<span id="ott-batch-status" style="margin-left:12px;vertical-align:middle;"></span>

			<div id="ott-batch-bar-wrap" hidden style="margin-top:14px;">
				<div role="progressbar"
				     aria-valuemin="0"
				     aria-valuemax="100"
				     aria-valuenow="0"
				     style="background:#e0e0e0;border-radius:4px;height:18px;overflow:hidden;">
					<div id="ott-batch-bar"
					     style="background:#00a32a;height:100%;width:0;transition:width .4s ease;"></div>
				</div>
				<div style="display:flex;justify-content:space-between;font-size:12px;margin-top:4px;">
					<span>
						<span id="ott-batch-done">0</span>
						<?php esc_html_e( 'done', 'open-tongue-translations' ); ?>
						&middot;
						<span id="ott-batch-failed">0</span>
						<?php esc_html_e( 'failed', 'open-tongue-translations' ); ?>
					</span>
					<span>
						<strong id="ott-batch-pct">0%</strong>
						<span id="ott-batch-eta" hidden style="margin-left:6px;color:#666;"></span>
					</span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Run all subsystem health checks and return a structured result set.
	 *
	 * @return array<int, array{status:string, system:string, detail:string}>
	 */
	private function build_health_checks(): array {
		global $wpdb;
		$checks = [];

		// 1. DB tables --------------------------------------------------------
		foreach ( [ $wpdb->prefix . 'libre_translations', $wpdb->prefix . 'ott_exclusion_rules' ] as $table ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			) === $table;

			$checks[] = [
				'status' => $exists ? 'ok' : 'fail',
				'system' => __( 'DB Table', 'open-tongue-translations' ),
				'detail' => $table . ( $exists ? __( ' (exists)', 'open-tongue-translations' ) : __( ' — MISSING', 'open-tongue-translations' ) ),
			];
		}

		// 2. Translation API --------------------------------------------------
		$api      = $this->check_api_reachable();
		$checks[] = [
			'status' => $api['status'],
			'system' => __( 'Translation API', 'open-tongue-translations' ),
			'detail' => $api['message'],
		];

		// 3. Object cache (L1) ------------------------------------------------
		$l1_active = wp_using_ext_object_cache();
		$checks[]  = [
			'status' => $l1_active ? 'ok' : 'warn',
			'system' => __( 'Object Cache (L1)', 'open-tongue-translations' ),
			'detail' => $l1_active
				? __( 'Persistent backend active (Redis / Memcached)', 'open-tongue-translations' )
				: __( 'No persistent object cache — cache misses fall through to DB on every request', 'open-tongue-translations' ),
		];

		// 4. Database cache (L2) — always active once schema exists -----------
		$checks[] = [
			'status' => 'ok',
			'system' => __( 'Database Cache (L2)', 'open-tongue-translations' ),
			'detail' => __( 'Always active — translations persisted in libre_translations table', 'open-tongue-translations' ),
		];

		// 5. WP-Cron ----------------------------------------------------------
		$cron_ok  = (bool) wp_next_scheduled( 'ltp_prune_translations' );
		$checks[] = [
			'status' => $cron_ok ? 'ok' : 'fail',
			'system' => __( 'WP-Cron', 'open-tongue-translations' ),
			'detail' => $cron_ok
				? __( 'ltp_prune_translations — scheduled', 'open-tongue-translations' )
				: __( 'ltp_prune_translations — NOT scheduled (deactivate and reactivate the plugin)', 'open-tongue-translations' ),
		];

		// 6. Required PHP extensions ------------------------------------------
		foreach ( [ 'curl', 'mbstring', 'dom' ] as $ext ) {
			$loaded   = extension_loaded( $ext );
			$checks[] = [
				'status' => $loaded ? 'ok' : 'fail',
				/* translators: %s: PHP extension name */
				'system' => sprintf( __( 'PHP ext: %s', 'open-tongue-translations' ), $ext ),
				'detail' => $loaded
					? __( 'loaded', 'open-tongue-translations' )
					: __( 'MISSING — install this extension', 'open-tongue-translations' ),
			];
		}

		return $checks;
	}

	/**
	 * Ping the configured translation endpoint (GET /languages) with a short timeout.
	 * Returns a tri-state status: 'ok', 'warn', or 'fail'.
	 *
	 * @return array{status:string, message:string}
	 */
	private function check_api_reachable(): array {
		$mode = (string) get_option( 'ltp_connection_mode', 'localhost' );

		if ( $mode === 'socket' ) {
			return [
				'status'  => 'warn',
				'message' => __( 'Unix socket — cannot probe from admin UI', 'open-tongue-translations' ),
			];
		}

		if ( $mode === 'localhost' ) {
			$host = (string) get_option( 'ltp_localhost_host', '127.0.0.1' );
			$port = absint( get_option( 'ltp_localhost_port', 5000 ) );
		} else {
			$host = (string) get_option( 'ltp_vpc_ip', '' );
			$port = absint( get_option( 'ltp_vpc_port', 5000 ) );
		}

		if ( $host === '' ) {
			return [
				'status'  => 'fail',
				'message' => __( 'Host/IP is not configured', 'open-tongue-translations' ),
			];
		}

		$url      = sprintf( 'http://%s:%d/languages', $host, $port );
		$response = wp_remote_get(
			$url,
			[
				'timeout'   => 3,
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'status'  => 'fail',
				/* translators: %s: WP_Error message */
				'message' => sprintf( __( 'Unreachable — %s', 'open-tongue-translations' ), $response->get_error_message() ),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code === 200
			? [ 'status' => 'ok',   'message' => __( 'LibreTranslate reachable', 'open-tongue-translations' ) ]
			: [ 'status' => 'fail', 'message' => sprintf( 'HTTP %d', $code ) ];
	}

	// =========================================================================
	// Translation Engine tab
	// =========================================================================

	private function render_engine_tab(): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'ott_engine' ); ?>
			<table class="form-table" role="presentation">
				<?php do_settings_fields( 'ott_engine', 'ott_engine_main' ); ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Settings field: ltp_target_lang — select dropdown.
	 */
	public function field_target_lang(): void {
		$current = (string) get_option( 'ltp_target_lang', 'en' );
		echo '<select name="ltp_target_lang" id="ltp_target_lang">';
		foreach ( self::LANGUAGES as $code => $label ) {
			printf(
				'<option value="%s"%s>%s (%s)</option>',
				esc_attr( $code ),
				selected( $current, $code, false ),
				esc_html( $label ),
				esc_html( $code )
			);
		}
		echo '</select>';
		echo '<p class="description">'
			. esc_html__( 'The BCP-47 language tag all front-end content will be translated into.', 'open-tongue-translations' )
			. '</p>';
	}

	/**
	 * Settings field: ltp_detect_browser_locale — toggle checkbox.
	 */
	public function field_detect_browser_locale(): void {
		$checked = (bool) get_option( 'ltp_detect_browser_locale', 0 );
		?>
		<label>
			<input type="checkbox" name="ltp_detect_browser_locale" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( "Use the visitor's Accept-Language header to select the target locale automatically.", 'open-tongue-translations' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, the Target Language above is used only as a fallback for unsupported locales.', 'open-tongue-translations' ); ?>
		</p>
		<?php
	}

	// =========================================================================
	// Connectivity tab
	// =========================================================================

	private function render_connectivity_tab(): void {
		$mode = (string) get_option( 'ltp_connection_mode', 'localhost' );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'ott_connectivity' ); ?>
			<table class="form-table" role="presentation">

				<!-- Connection Mode radio -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Mode', 'open-tongue-translations' ); ?></th>
					<td>
						<?php
						$modes = [
							'localhost' => __( 'Localhost REST (127.x / ::1)', 'open-tongue-translations' ),
							'socket'    => __( 'Unix Socket (same host)', 'open-tongue-translations' ),
							'vpc'       => __( 'Private VPC / Remote host', 'open-tongue-translations' ),
						];
						foreach ( $modes as $value => $label ) :
							?>
							<label style="display:block;margin-bottom:6px;">
								<input type="radio"
								       name="ltp_connection_mode"
								       value="<?php echo esc_attr( $value ); ?>"
								       <?php checked( $mode, $value ); ?>
								       class="ott-mode-radio" />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>

				<!-- Localhost fields -->
				<tr class="ott-mode-row ott-mode-localhost"<?php echo $mode !== 'localhost' ? ' style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ltp_localhost_host"><?php esc_html_e( 'Host', 'open-tongue-translations' ); ?></label>
					</th>
					<td>
						<input type="text"
						       name="ltp_localhost_host"
						       id="ltp_localhost_host"
						       value="<?php echo esc_attr( (string) get_option( 'ltp_localhost_host', '127.0.0.1' ) ); ?>"
						       class="regular-text" />
					</td>
				</tr>

				<tr class="ott-mode-row ott-mode-localhost"<?php echo $mode !== 'localhost' ? ' style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ltp_localhost_port"><?php esc_html_e( 'Port', 'open-tongue-translations' ); ?></label>
					</th>
					<td>
						<input type="number"
						       name="ltp_localhost_port"
						       id="ltp_localhost_port"
						       value="<?php echo esc_attr( (string) absint( get_option( 'ltp_localhost_port', 5000 ) ) ); ?>"
						       min="1" max="65535" class="small-text" />
					</td>
				</tr>

				<!-- Socket fields -->
				<tr class="ott-mode-row ott-mode-socket"<?php echo $mode !== 'socket' ? ' style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ltp_socket_path"><?php esc_html_e( 'Socket Path', 'open-tongue-translations' ); ?></label>
					</th>
					<td>
						<input type="text"
						       name="ltp_socket_path"
						       id="ltp_socket_path"
						       value="<?php echo esc_attr( (string) get_option( 'ltp_socket_path', '/tmp/libretranslate.sock' ) ); ?>"
						       class="regular-text" />
						<p class="description">
							<?php esc_html_e( 'Absolute path to the LibreTranslate Unix socket file on this host.', 'open-tongue-translations' ); ?>
						</p>
					</td>
				</tr>

				<!-- VPC fields -->
				<tr class="ott-mode-row ott-mode-vpc"<?php echo $mode !== 'vpc' ? ' style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ltp_vpc_ip"><?php esc_html_e( 'VPC IP Address', 'open-tongue-translations' ); ?></label>
					</th>
					<td>
						<input type="text"
						       name="ltp_vpc_ip"
						       id="ltp_vpc_ip"
						       value="<?php echo esc_attr( (string) get_option( 'ltp_vpc_ip', '' ) ); ?>"
						       class="regular-text"
						       placeholder="10.0.0.5" />
						<p class="description">
							<?php esc_html_e( 'Use an RFC1918 address (10.x, 172.16–31.x, 192.168.x) to keep data within your private network.', 'open-tongue-translations' ); ?>
						</p>
					</td>
				</tr>

				<tr class="ott-mode-row ott-mode-vpc"<?php echo $mode !== 'vpc' ? ' style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ltp_vpc_port"><?php esc_html_e( 'VPC Port', 'open-tongue-translations' ); ?></label>
					</th>
					<td>
						<input type="number"
						       name="ltp_vpc_port"
						       id="ltp_vpc_port"
						       value="<?php echo esc_attr( (string) absint( get_option( 'ltp_vpc_port', 5000 ) ) ); ?>"
						       min="1" max="65535" class="small-text" />
					</td>
				</tr>

				<tr class="ott-mode-row ott-mode-vpc"<?php echo $mode !== 'vpc' ? ' style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ltp_vpc_api_key"><?php esc_html_e( 'API Key', 'open-tongue-translations' ); ?></label>
					</th>
					<td>
						<input type="password"
						       name="ltp_vpc_api_key"
						       id="ltp_vpc_api_key"
						       value="<?php echo esc_attr( (string) get_option( 'ltp_vpc_api_key', '' ) ); ?>"
						       class="regular-text"
						       autocomplete="new-password" />
						<p class="description">
							<?php esc_html_e( 'Leave blank if your LibreTranslate instance requires no API key.', 'open-tongue-translations' ); ?>
						</p>
					</td>
				</tr>

			</table>
			<?php submit_button(); ?>
		</form>

		<script>
		(function () {
			var radios = document.querySelectorAll( '.ott-mode-radio' );

			function showMode( selected ) {
				[ 'localhost', 'socket', 'vpc' ].forEach( function ( m ) {
					document.querySelectorAll( '.ott-mode-' + m ).forEach( function ( row ) {
						row.style.display = m === selected ? '' : 'none';
					} );
				} );
			}

			radios.forEach( function ( r ) {
				r.addEventListener( 'change', function () {
					showMode( r.value );
				} );
			} );
		}() );
		</script>
		<?php
	}

	// =========================================================================
	// Exclusions & Glossary tab
	// =========================================================================

	private function render_exclusions_tab(): void {
		global $wpdb;

		// Surface inline notices from CRUD redirects.
		if ( ! empty( $_GET['ott_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Invalid rule data — please check your input and try again.', 'open-tongue-translations' )
				. '</p></div>';
		}

		$table = $wpdb->prefix . 'ott_exclusion_rules';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ) ?? [];

		/** @var ExclusionRule[] $rules */
		$rules      = array_map( [ ExclusionRule::class, 'fromRow' ], $rows );
		$post_url   = admin_url( 'admin-post.php' );
		$return_url = $this->tab_url( 'exclusions' );
		?>

		<h2 style="margin-top:0;"><?php esc_html_e( 'Exclusion Rules', 'open-tongue-translations' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Rules tell the translation engine which page regions to skip. CSS selectors, XPath expressions, and regular expressions are all supported.', 'open-tongue-translations' ); ?>
		</p>

		<?php if ( ! empty( $rules ) ) : ?>
		<table class="widefat striped" style="max-width:900px;margin-bottom:28px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'open-tongue-translations' ); ?></th>
					<th><?php esc_html_e( 'Value', 'open-tongue-translations' ); ?></th>
					<th><?php esc_html_e( 'Scope', 'open-tongue-translations' ); ?></th>
					<th><?php esc_html_e( 'Status', 'open-tongue-translations' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'open-tongue-translations' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rules as $rule ) : ?>
				<tr>
					<td><code><?php echo esc_html( $rule->ruleType ); ?></code></td>
					<td style="max-width:300px;word-break:break-all;"><code><?php echo esc_html( $rule->ruleValue ); ?></code></td>
					<td>
						<?php
						echo esc_html( $rule->scope );
						if ( $rule->scopeValue !== null ) {
							echo ': <code>' . esc_html( $rule->scopeValue ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html already called
						}
						?>
					</td>
					<td>
						<?php if ( $rule->isActive ) : ?>
							<span style="color:#00a32a;"><?php esc_html_e( 'Active', 'open-tongue-translations' ); ?></span>
						<?php else : ?>
							<span style="color:#999;"><?php esc_html_e( 'Inactive', 'open-tongue-translations' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$toggle_url = wp_nonce_url(
							add_query_arg(
								[
									'action'           => 'ott_toggle_exclusion_rule',
									'rule_id'          => $rule->id,
									'_wp_http_referer' => rawurlencode( $return_url ),
								],
								$post_url
							),
							'ott_toggle_rule_' . $rule->id
						);
						$delete_url = wp_nonce_url(
							add_query_arg(
								[
									'action'           => 'ott_delete_exclusion_rule',
									'rule_id'          => $rule->id,
									'_wp_http_referer' => rawurlencode( $return_url ),
								],
								$post_url
							),
							'ott_delete_rule_' . $rule->id
						);
						?>
						<a href="<?php echo esc_url( $toggle_url ); ?>">
							<?php echo $rule->isActive
								? esc_html__( 'Disable', 'open-tongue-translations' )
								: esc_html__( 'Enable', 'open-tongue-translations' );
							?>
						</a>
						&nbsp;|&nbsp;
						<a href="<?php echo esc_url( $delete_url ); ?>"
						   onclick="return confirm( <?php echo esc_js( __( 'Delete this rule? This cannot be undone.', 'open-tongue-translations' ) ); ?> );"
						   style="color:#d63638;">
							<?php esc_html_e( 'Delete', 'open-tongue-translations' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
			<p style="margin-bottom:24px;color:#666;">
				<?php esc_html_e( 'No exclusion rules defined yet.', 'open-tongue-translations' ); ?>
			</p>
		<?php endif; ?>

		<!-- Add new rule form -->
		<div class="card" style="max-width:600px;padding:20px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Add New Rule', 'open-tongue-translations' ); ?></h3>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<input type="hidden" name="action" value="ott_save_exclusion_rule" />
				<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $return_url ); ?>" />
				<?php wp_nonce_field( 'ott_save_exclusion_rule' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ott_rule_type"><?php esc_html_e( 'Rule Type', 'open-tongue-translations' ); ?></label>
						</th>
						<td>
							<select name="rule_type" id="ott_rule_type">
								<option value="css_selector"><?php esc_html_e( 'CSS Selector', 'open-tongue-translations' ); ?></option>
								<option value="xpath"><?php esc_html_e( 'XPath', 'open-tongue-translations' ); ?></option>
								<option value="regex"><?php esc_html_e( 'Regex', 'open-tongue-translations' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ott_rule_value"><?php esc_html_e( 'Value', 'open-tongue-translations' ); ?></label>
						</th>
						<td>
							<input type="text"
							       name="rule_value"
							       id="ott_rule_value"
							       class="regular-text"
							       placeholder=".no-translate"
							       required />
							<p class="description">
								<?php esc_html_e( 'e.g. .no-translate  |  //div[@id="header"]  |  /^SKU-\d+/', 'open-tongue-translations' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ott_rule_scope"><?php esc_html_e( 'Scope', 'open-tongue-translations' ); ?></label>
						</th>
						<td>
							<select name="rule_scope" id="ott_rule_scope">
								<option value="global"><?php esc_html_e( 'Global (all pages)', 'open-tongue-translations' ); ?></option>
								<option value="post_type"><?php esc_html_e( 'Post Type', 'open-tongue-translations' ); ?></option>
								<option value="post_id"><?php esc_html_e( 'Post ID', 'open-tongue-translations' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ott_scope_value"><?php esc_html_e( 'Scope Value', 'open-tongue-translations' ); ?></label>
						</th>
						<td>
							<input type="text"
							       name="scope_value"
							       id="ott_scope_value"
							       class="regular-text"
							       placeholder="<?php esc_attr_e( 'e.g. page  |  post  |  42', 'open-tongue-translations' ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Leave blank for the Global scope.', 'open-tongue-translations' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add Rule', 'open-tongue-translations' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	// =========================================================================
	// Performance tab
	// =========================================================================

	private function render_performance_tab(): void {
		$l1_active = wp_using_ext_object_cache();
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Cache Status', 'open-tongue-translations' ); ?></h2>
		<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:28px;">
			<div class="card" style="min-width:220px;padding:16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'L1 — Object Cache', 'open-tongue-translations' ); ?></h3>
				<?php if ( $l1_active ) : ?>
					<p><span style="color:#00a32a;font-weight:bold;">&#10003; <?php esc_html_e( 'Active', 'open-tongue-translations' ); ?></span></p>
					<p class="description"><?php esc_html_e( 'Persistent backend detected (Redis / Memcached).', 'open-tongue-translations' ); ?></p>
				<?php else : ?>
					<p><span style="color:#dba617;font-weight:bold;">! <?php esc_html_e( 'Not persistent', 'open-tongue-translations' ); ?></span></p>
					<p class="description"><?php esc_html_e( 'Install a Redis or Memcached object cache drop-in for full in-memory caching.', 'open-tongue-translations' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="card" style="min-width:220px;padding:16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'L2 — Database Cache', 'open-tongue-translations' ); ?></h3>
				<p><span style="color:#00a32a;font-weight:bold;">&#10003; <?php esc_html_e( 'Active', 'open-tongue-translations' ); ?></span></p>
				<p class="description"><?php esc_html_e( 'Translations are persisted in the libre_translations table.', 'open-tongue-translations' ); ?></p>
			</div>
		</div>

		<h2><?php esc_html_e( 'Performance Settings', 'open-tongue-translations' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'ott_performance' ); ?>
			<table class="form-table" role="presentation">
				<?php do_settings_fields( 'ott_performance', 'ott_performance_main' ); ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Settings field: ltp_prune_days.
	 */
	public function field_prune_days(): void {
		$days = absint( get_option( 'ltp_prune_days', 90 ) );
		echo '<input type="number"'
			. ' name="ltp_prune_days"'
			. ' id="ltp_prune_days"'
			. ' value="' . esc_attr( (string) $days ) . '"'
			. ' min="7" max="3650"'
			. ' class="small-text" />';
		echo ' ' . esc_html__( 'days', 'open-tongue-translations' );
		echo '<p class="description">'
			. esc_html__( 'Translation rows not accessed within this window will be pruned by the weekly cron job. Rows with is_manual = 1 are never pruned.', 'open-tongue-translations' )
			. '</p>';
	}

	/**
	 * Settings field: ltp_compat_wprocket.
	 */
	public function field_compat_wprocket(): void {
		$enabled = (bool) get_option( 'ltp_compat_wprocket', 1 );
		?>
		<label>
			<input type="checkbox" name="ltp_compat_wprocket" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Register WP Rocket cache-purge hooks on translation updates.', 'open-tongue-translations' ); ?>
		</label>
		<?php if ( ! ( new WpRocketCompat() )->isActive() ) : ?>
			<p class="description"><?php esc_html_e( 'WP Rocket is not currently active.', 'open-tongue-translations' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Settings field: ltp_compat_cloudflare.
	 */
	public function field_compat_cloudflare(): void {
		$enabled = (bool) get_option( 'ltp_compat_cloudflare', 1 );
		?>
		<label>
			<input type="checkbox" name="ltp_compat_cloudflare" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Register Cloudflare cache-purge hooks on translation updates.', 'open-tongue-translations' ); ?>
		</label>
		<?php if ( ! ( new CloudflareCompat() )->isActive() ) : ?>
			<p class="description"><?php esc_html_e( 'Cloudflare plugin is not currently active.', 'open-tongue-translations' ); ?></p>
		<?php endif; ?>
		<?php
	}

	// =========================================================================
	// Manual Edits tab
	// =========================================================================

	private function render_manual_edits_tab(): void {
		$table = new OTT_Manual_Edits_Table();
		$table->prepare_items();
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Manually Edited Translations', 'open-tongue-translations' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Rows where an administrator has manually overridden the machine translation. Use "Revert to Machine" to allow the engine to re-translate a string automatically.', 'open-tongue-translations' ); ?>
		</p>
		<form method="post">
			<?php
			$table->search_box( __( 'Search', 'open-tongue-translations' ), 'manual_edit_search' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Handle inline-edit POST from the Manual Edits table.
	 */
	public function handle_update_manual_edit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'open-tongue-translations' ), '', [ 'response' => 403 ] );
		}

		$row_id = absint( $_POST['row_id'] ?? 0 );
		check_admin_referer( 'ott_edit_manual_' . $row_id );

		if ( $row_id > 0 ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'libre_translations';
			$new_text = sanitize_textarea_field( wp_unslash( (string) ( $_POST['translated_text'] ?? '' ) ) );

			$wpdb->update(
				$table,
				[ 'translated_text' => $new_text ],
				[ 'id' => $row_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		wp_safe_redirect( $this->tab_url( 'manual_edits' ) );
		exit;
	}

	// =========================================================================
	// Integrity Monitor tab
	// =========================================================================

	private function render_integrity_tab(): void {
		$table = new OTT_Integrity_Monitor_Table();
		$table->prepare_items();
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Token Integrity Monitor', 'open-tongue-translations' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Every row here is a string where LibreTranslate dropped or modified an HTML placeholder ([[OTT_TAG_n]]), causing the plugin to serve the original unmodified HTML as a safe fallback. Add an Exclusion Rule for repeating offenders.', 'open-tongue-translations' ); ?>
		</p>
		<?php if ( $table->get_pagination_arg( 'total_items' ) === 0 && ! isset( $_REQUEST['s'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success inline"><p>
				<strong><?php esc_html_e( 'All clear!', 'open-tongue-translations' ); ?></strong>
				<?php esc_html_e( 'No token-mismatch events have been recorded. Your HTML is surviving translation intact.', 'open-tongue-translations' ); ?>
			</p></div>
		<?php else : ?>
			<form method="post">
				<?php
				$table->search_box( __( 'Search', 'open-tongue-translations' ), 'integrity_search' );
				$table->display();
				?>
			</form>
		<?php endif; ?>
		<?php
	}

	// =========================================================================
	// Exclusion rule CRUD handlers
	// =========================================================================

	/**
	 * Handle POST from the "Add Rule" form on the Exclusions tab.
	 */
	public function handle_save_exclusion_rule(): void {
		check_admin_referer( 'ott_save_exclusion_rule' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'open-tongue-translations' ), '', [ 'response' => 403 ] );
		}

		$valid_types  = [ 'css_selector', 'regex', 'xpath' ];
		$valid_scopes = [ 'global', 'post_type', 'post_id' ];

		$rule_type  = sanitize_key( wp_unslash( $_POST['rule_type']   ?? '' ) );
		$rule_value = sanitize_textarea_field( wp_unslash( $_POST['rule_value']  ?? '' ) );
		$scope      = sanitize_key( wp_unslash( $_POST['rule_scope']  ?? 'global' ) );
		$scope_val  = sanitize_text_field( wp_unslash( $_POST['scope_value'] ?? '' ) );

		$referer = wp_get_referer() ?: $this->tab_url( 'exclusions' );

		if ( ! in_array( $rule_type, $valid_types, true ) || $rule_value === '' ) {
			wp_safe_redirect( add_query_arg( 'ott_error', '1', $referer ) );
			exit;
		}

		if ( ! in_array( $scope, $valid_scopes, true ) ) {
			$scope = 'global';
		}

		( new ExclusionRuleRepository() )->insert(
			[
				'rule_type'   => $rule_type,
				'rule_value'  => $rule_value,
				'scope'       => $scope,
				'scope_value' => ( $scope !== 'global' && $scope_val !== '' ) ? $scope_val : null,
				'is_active'   => 1,
				'created_by'  => get_current_user_id(),
			]
		);

		wp_safe_redirect( $referer );
		exit;
	}

	/**
	 * Handle the "Delete" link on the Exclusions tab.
	 */
	public function handle_delete_exclusion_rule(): void {
		$rule_id = absint( $_GET['rule_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'ott_delete_rule_' . $rule_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'open-tongue-translations' ), '', [ 'response' => 403 ] );
		}

		if ( $rule_id > 0 ) {
			( new ExclusionRuleRepository() )->delete( $rule_id );
		}

		$referer = $this->referer_from_get() ?? $this->tab_url( 'exclusions' );
		wp_safe_redirect( $referer );
		exit;
	}

	/**
	 * Handle the "Enable / Disable" toggle link on the Exclusions tab.
	 */
	public function handle_toggle_exclusion_rule(): void {
		$rule_id = absint( $_GET['rule_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'ott_toggle_rule_' . $rule_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'open-tongue-translations' ), '', [ 'response' => 403 ] );
		}

		if ( $rule_id > 0 ) {
			$repo = new ExclusionRuleRepository();
			$rule = $repo->findById( $rule_id );
			if ( $rule !== null ) {
				$repo->update( $rule_id, [ 'is_active' => $rule->isActive ? 0 : 1 ] );
			}
		}

		$referer = $this->referer_from_get() ?? $this->tab_url( 'exclusions' );
		wp_safe_redirect( $referer );
		exit;
	}

	// =========================================================================
	// Sanitize callbacks
	// =========================================================================

	/**
	 * Validate a BCP-47 language tag.
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_lang_tag( mixed $value ): string {
		$code = sanitize_text_field( (string) $value );

		if ( array_key_exists( $code, self::LANGUAGES ) ) {
			return $code;
		}

		// Accept well-formed BCP-47 codes not in the predefined list (e.g. pt-BR).
		if ( preg_match( '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $code ) === 1 ) {
			return $code;
		}

		add_settings_error(
			'ltp_target_lang',
			'invalid_lang',
			__( 'Invalid language code — please use a valid BCP-47 tag (e.g. fr, pt-BR).', 'open-tongue-translations' )
		);

		return (string) get_option( 'ltp_target_lang', 'en' );
	}

	/**
	 * Restrict ltp_connection_mode to known driver identifiers.
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_connection_mode( mixed $value ): string {
		$mode = sanitize_key( (string) $value );

		if ( in_array( $mode, [ 'localhost', 'socket', 'vpc' ], true ) ) {
			return $mode;
		}

		add_settings_error(
			'ltp_connection_mode',
			'invalid_mode',
			__( 'Invalid connection mode. Choose localhost, socket, or vpc.', 'open-tongue-translations' )
		);

		return (string) get_option( 'ltp_connection_mode', 'localhost' );
	}

	/**
	 * Validate a TCP port number (1–65535).
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_port( mixed $value ): int {
		$port = absint( $value );

		return ( $port >= 1 && $port <= 65535 ) ? $port : 5000;
	}

	/**
	 * Validate an IPv4 or IPv6 address (empty string permitted).
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_ip( mixed $value ): string {
		$ip = sanitize_text_field( (string) $value );

		if ( $ip === '' || filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
			return $ip;
		}

		add_settings_error(
			'ltp_vpc_ip',
			'invalid_ip',
			__( 'Invalid IP address — please enter a valid IPv4 or IPv6 address.', 'open-tongue-translations' )
		);

		return (string) get_option( 'ltp_vpc_ip', '' );
	}

	/**
	 * Clamp ltp_prune_days to a safe range (7–3650).
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_prune_days( mixed $value ): int {
		$days = absint( $value );

		return ( $days >= 7 && $days <= 3650 ) ? $days : 90;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Determine whether the active connection configuration routes translation
	 * data exclusively to a loopback or RFC1918 private address.
	 *
	 * localhost / socket modes are always local.
	 * VPC mode is local only when the configured IP is private or reserved.
	 */
	private function is_data_local(): bool {
		$mode = (string) get_option( 'ltp_connection_mode', 'localhost' );

		if ( $mode === 'localhost' || $mode === 'socket' ) {
			return true;
		}

		$ip = (string) get_option( 'ltp_vpc_ip', '' );

		if ( $ip === '' ) {
			return false;
		}

		// FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE rejects private /
		// reserved IPs. A false return value therefore means the IP IS private.
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
	}

	/**
	 * Return the active tab slug, validated against known tab keys.
	 */
	private function get_active_tab(): string {
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'dashboard' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		return array_key_exists( $tab, self::TABS ) ? $tab : 'dashboard';
	}

	/**
	 * Build an admin URL for a given tab slug.
	 */
	private function tab_url( string $tab ): string {
		return add_query_arg(
			[
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Read and validate the _wp_http_referer from the current GET request.
	 * Returns null when absent or not a valid local admin URL.
	 */
	private function referer_from_get(): ?string {
		if ( empty( $_GET['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return null;
		}

		$url = esc_url_raw( rawurldecode( wp_unslash( (string) $_GET['_wp_http_referer'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// Restrict to same site to prevent open-redirect.
		$admin_url = admin_url();
		if ( strncmp( $url, $admin_url, strlen( $admin_url ) ) !== 0 ) {
			return null;
		}

		return $url;
	}
}
