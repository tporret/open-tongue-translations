<?php
/**
 * WP-Cron job: prunes stale translation rows from the database.
 *
 * Runs on a weekly schedule ('ltp_weekly'). When a large backlog exists
 * (the first batch equals batchSize) the job reschedules itself to run
 * again in 60 seconds so the table is drained incrementally without ever
 * issuing an unbounded DELETE that could lock the table for seconds on
 * high-traffic sites.
 *
 * Human-edited rows (is_manual = 1) are never pruned — the repository
 * enforces this at the SQL level.
 *
 * @package OpenToungeTranslations\Maintenance
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Maintenance;

use OpenToungeTranslations\Database\TranslationRepository;

/**
 * Class PruningJob
 *
 * Registers a custom WP-Cron schedule and hooks the pruning action.
 * Must be activated via register() inside Plugin::boot() and scheduled
 * via scheduleOnActivation() inside the plugin activation hook.
 */
final class PruningJob {

	/**
	 * The WP-Cron action name that triggers a pruning run.
	 */
	public const CRON_HOOK = 'ltp_prune_translations';

	/**
	 * The custom cron schedule identifier.
	 */
	private const SCHEDULE_NAME = 'ltp_weekly';

	/**
	 * Interval in seconds for the 'ltp_weekly' schedule (7 days).
	 */
	private const SCHEDULE_INTERVAL = 604800;

	/**
	 * Number of rows deleted per batch. Kept at 1000 to avoid long
	 * table locks — see pruneOlderThan() docs for details.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * @param TranslationRepository $repo Repository used to perform the deletion query.
	 */
	public function __construct(
		private readonly TranslationRepository $repo,
	) {}

	/**
	 * Register the custom cron schedule and hook the pruning action.
	 *
	 * Must be called during plugin boot (plugins_loaded) so that the schedule
	 * is available to WP-Cron's scheduler before any event fires.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'addSchedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
	}

	/**
	 * Add the 'ltp_weekly' custom WP-Cron schedule.
	 *
	 * @param array<string, array<string, int|string>> $schedules Existing cron schedules.
	 *
	 * @return array<string, array<string, int|string>> Modified schedules array.
	 */
	public function addSchedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE_NAME ] ) ) {
			$schedules[ self::SCHEDULE_NAME ] = [
				'interval' => self::SCHEDULE_INTERVAL,
				'display'  => __( 'Once Weekly (Open Tongue)', 'open-tongue-translations' ),
			];
		}

		return $schedules;
	}

	/**
	 * Execute one pruning batch and re-queue if more rows remain.
	 *
	 * Algorithm:
	 *  1. Compute the cutoff date: now - ltp_prune_days (default 90).
	 *  2. Delete up to BATCH_SIZE rows older than the cutoff (excluding is_manual=1).
	 *  3. If exactly BATCH_SIZE rows were deleted, reschedule a one-off run in
	 *     60 seconds to drain the backlog without a mega-query.
	 *  4. Log the deletion count to the WP debug log.
	 *
	 * @return void
	 */
	public function run(): void {
		$pruneDays = (int) get_option( 'ltp_prune_days', 90 );

		if ( $pruneDays <= 0 ) {
			error_log( '[OpenTongue] PruningJob: ltp_prune_days is 0 or negative — pruning skipped.' );
			return;
		}

		try {
			$cutoff = new \DateTimeImmutable(
				sprintf( '-%d days', $pruneDays ),
				new \DateTimeZone( 'UTC' )
			);
		} catch ( \Throwable $e ) {
			error_log(
				sprintf( '[OpenTongue] PruningJob: could not compute cutoff date — %s', $e->getMessage() )
			);
			return;
		}

		try {
			$deleted = $this->repo->pruneOlderThan( $cutoff, self::BATCH_SIZE );
		} catch ( \Throwable $e ) {
			error_log(
				sprintf( '[OpenTongue] PruningJob::run() exception: %s', $e->getMessage() )
			);
			return;
		}

		error_log(
			sprintf(
				'[OpenTongue] PruningJob: deleted %d stale translation rows (cutoff: %s, is_manual rows preserved).',
				$deleted,
				$cutoff->format( 'Y-m-d H:i:s' )
			)
		);

		// If a full batch was deleted there are likely more stale rows.
		// Reschedule a one-off run in 60 seconds to drain incrementally.
		if ( $deleted === self::BATCH_SIZE ) {
			$nextRun = time() + 60;

			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( $nextRun, self::CRON_HOOK );

				error_log(
					sprintf(
						'[OpenTongue] PruningJob: full batch deleted — scheduled follow-up run at %s.',
						gmdate( 'Y-m-d H:i:s', $nextRun )
					)
				);
			}
		}
	}

	/**
	 * Schedule the weekly pruning event on plugin activation.
	 *
	 * Must be called from the plugin's register_activation_hook() callback.
	 * Bails when the event is already scheduled so repeated activations do
	 * not create duplicate cron entries.
	 *
	 * @return void
	 */
	public static function scheduleOnActivation(): void {
		// The plugin is not yet loaded when the activation hook fires, so the
		// cron_schedules filter registered in register() hasn't run yet.
		// Add the custom schedule inline so wp_schedule_event() accepts it.
		add_filter( 'cron_schedules', static function ( array $schedules ): array {
			if ( ! isset( $schedules[ self::SCHEDULE_NAME ] ) ) {
				$schedules[ self::SCHEDULE_NAME ] = [
					'interval' => self::SCHEDULE_INTERVAL,
					'display'  => 'Once Weekly (OTT)',
				];
			}
			return $schedules;
		} );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$result = wp_schedule_event( time(), self::SCHEDULE_NAME, self::CRON_HOOK );

			if ( $result === false || is_wp_error( $result ) ) {
				error_log( '[OpenTongue] PruningJob: failed to schedule weekly cron event.' );
			} else {
				error_log( '[OpenTongue] PruningJob: weekly cron event scheduled.' );
			}
		}
	}

	/**
	 * Remove the scheduled pruning event on plugin deactivation.
	 *
	 * Must be called from the plugin's register_deactivation_hook() callback.
	 *
	 * @return void
	 */
	public static function clearOnDeactivation(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		error_log( '[OpenTongue] PruningJob: cron event cleared on deactivation.' );
	}
}
