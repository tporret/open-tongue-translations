<?php
/**
 * REST API controller for the admin batch-translation progress feature.
 *
 * Provides two endpoints under /wp-json/ott/v1/:
 *
 *   POST /batch/start   — enqueues a batch translation job and stores its state
 *                         in a transient. Returns { job_id, total, status }.
 *   GET  /batch/status  — returns the current progress of the active job.
 *                         Returns { job_id, total, done, failed, status, pct, eta_seconds }.
 *
 * Batch execution
 * ───────────────
 * On WordPress sites without Action Scheduler, translation happens inline
 * within the REST request via chunked processing. A WP-Cron event is
 * registered to advance the remaining chunks so the admin UI can poll for
 * real-time progress.
 *
 * State is stored in a transient keyed by job_id (format: ott_batch_{job_id}).
 * State expires automatically after 24 hours.
 *
 * @package OpenToungeTranslations\Admin
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Admin;

use OpenToungeTranslations\Cache\CacheManager;
use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;
use OpenToungeTranslations\Database\TranslationRepository;

/**
 * Class OTT_Batch_REST_Controller
 */
final class OTT_Batch_REST_Controller {

	/** REST namespace. */
	private const NAMESPACE = 'ott/v1';

	/** Transient TTL for job state (24 hours). */
	private const JOB_TTL = DAY_IN_SECONDS;

	/** Rows to translate per cron tick. */
	private const CHUNK_SIZE = 50;

	public function __construct(
		private readonly TranslationClientInterface $client,
		private readonly TranslationRepository      $repo,
		private readonly CacheManager               $cache
	) {}

	/**
	 * Register REST routes and the cron hook.
	 * Call once on rest_api_init.
	 */
	public function register(): void {
		register_rest_route( self::NAMESPACE, '/batch/start', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_start' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'target_lang' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn ( $v ) => (bool) preg_match( '/^[a-z]{2,8}(-[A-Za-z0-9]{2,8})*$/', (string) $v ),
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/batch/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'job_id' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Cron hook: advances one chunk per tick.
		add_action( 'ott_batch_process_chunk', [ $this, 'process_chunk' ] );
	}

	// =========================================================================
	// Permission callback
	// =========================================================================

	/** @internal */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// =========================================================================
	// REST handlers
	// =========================================================================

	/**
	 * POST /batch/start
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_start( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$target_lang = (string) $request->get_param( 'target_lang' );

		// Count untranslated strings for this locale.
		$total = $this->count_pending( $target_lang );

		if ( $total === 0 ) {
			return new \WP_REST_Response( [
				'status'  => 'idle',
				'message' => __( 'No untranslated strings found for this locale.', 'open-tongue-translations' ),
				'total'   => 0,
			], 200 );
		}

		// Build a new job and persist it.
		$job_id = wp_generate_uuid4();
		$state  = [
			'job_id'      => $job_id,
			'target_lang' => $target_lang,
			'total'       => $total,
			'done'        => 0,
			'failed'      => 0,
			'status'      => 'running',
			'started_at'  => time(),
		];

		set_transient( $this->transient_key( $job_id ), $state, self::JOB_TTL );
		// Also store the latest job_id under a known key for polling without a job_id.
		set_transient( 'ott_batch_latest_job', $job_id, self::JOB_TTL );

		// Schedule the first chunk immediately.
		wp_schedule_single_event( time(), 'ott_batch_process_chunk', [ $job_id ] );

		return new \WP_REST_Response( [
			'job_id' => $job_id,
			'total'  => $total,
			'status' => 'running',
		], 202 );
	}

	/**
	 * GET /batch/status
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$job_id = (string) ( $request->get_param( 'job_id' ) ?: get_transient( 'ott_batch_latest_job' ) );

		if ( $job_id === '' ) {
			return new \WP_REST_Response( [ 'status' => 'idle', 'message' => 'No active job.' ], 200 );
		}

		$state = get_transient( $this->transient_key( $job_id ) );

		if ( $state === false ) {
			return new \WP_REST_Response( [ 'status' => 'expired', 'job_id' => $job_id ], 200 );
		}

		$pct     = $state['total'] > 0 ? (int) round( $state['done'] / $state['total'] * 100 ) : 100;
		$elapsed = max( 1, time() - (int) $state['started_at'] );
		$rate    = $state['done'] > 0 ? $elapsed / $state['done'] : 0;
		$eta     = $rate > 0 ? (int) round( $rate * max( 0, $state['total'] - $state['done'] ) ) : null;

		return new \WP_REST_Response( [
			'job_id'      => $state['job_id'],
			'target_lang' => $state['target_lang'],
			'total'       => $state['total'],
			'done'        => $state['done'],
			'failed'      => $state['failed'],
			'status'      => $state['status'],
			'pct'         => $pct,
			'eta_seconds' => $eta,
		], 200 );
	}

	// =========================================================================
	// Cron chunk processor
	// =========================================================================

	/**
	 * Translates one chunk of untranslated strings and reschedules itself.
	 *
	 * @param string $job_id
	 *
	 * @internal Called via WP-Cron.
	 */
	public function process_chunk( string $job_id ): void {
		$state = get_transient( $this->transient_key( $job_id ) );

		if ( $state === false || $state['status'] !== 'running' ) {
			return;
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'libre_translations';
		$target_lang = $state['target_lang'];

		// Fetch a chunk of source strings that are not yet translated into this locale.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT source_text FROM {$table} WHERE (target_lang != %s OR target_lang IS NULL) LIMIT %d",
				$target_lang,
				self::CHUNK_SIZE
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			// No more work — mark complete.
			$state['status'] = 'complete';
			set_transient( $this->transient_key( $job_id ), $state, self::JOB_TTL );
			return;
		}

		foreach ( $rows as $row ) {
			$source = (string) $row['source_text'];

			try {
				$translated = $this->client->translate( $source, 'auto', $target_lang );
				$this->cache->set( md5( $source ), $target_lang, $translated );
				++$state['done'];
			} catch ( \Throwable ) {
				++$state['failed'];
				++$state['done']; // Count failed items as processed to avoid infinite loops.
			}
		}

		set_transient( $this->transient_key( $job_id ), $state, self::JOB_TTL );

		// Reschedule next chunk if there's more to do.
		if ( $state['done'] < $state['total'] ) {
			wp_schedule_single_event( time() + 5, 'ott_batch_process_chunk', [ $job_id ] );
		} else {
			$state['status'] = 'complete';
			set_transient( $this->transient_key( $job_id ), $state, self::JOB_TTL );
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Count source strings not yet translated into $target_lang.
	 */
	private function count_pending( string $target_lang ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'libre_translations';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT source_text) FROM {$table} WHERE target_lang != %s",
				$target_lang
			)
		);
	}

	/**
	 * Build the transient key for a given job ID.
	 */
	private function transient_key( string $job_id ): string {
		return 'ott_batch_' . $job_id;
	}
}
