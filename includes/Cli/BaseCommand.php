<?php
/**
 * Abstract base for all OTT WP-CLI commands.
 *
 * Provides shared helpers — progress bar, structured logging, dry-run
 * detection, and interactive confirmation — so each concrete command
 * focuses only on its own domain logic.
 *
 * @package OpenToungeTranslations\Cli
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cli;

/**
 * Class BaseCommand
 *
 * All WP-CLI commands in the `ott` namespace extend this class.
 * Must only be loaded when WP_CLI is defined and true.
 */
abstract class BaseCommand extends \WP_CLI_Command {

	/**
	 * Output prefix applied to every log message.
	 */
	private const LOG_PREFIX = '[OTT]';

	/**
	 * Create and return a WP-CLI progress bar.
	 *
	 * The bar is returned to the caller so it can tick and finish it at
	 * the appropriate points in the command's iteration loop.
	 *
	 * Returns \cli\progress\Bar in TTY mode; \WP_CLI\NoOp when stdout is a
	 * pipe or is redirected (both share the same tick()/finish() API).
	 *
	 * @param string $label Human-readable label displayed before the bar.
	 * @param int    $total Number of items that will be processed.
	 *
	 * @return \cli\progress\Bar|\WP_CLI\NoOp
	 */
	protected function progressBar( string $label, int $total ): \cli\progress\Bar|\WP_CLI\NoOp {
		return \WP_CLI\Utils\make_progress_bar( $label, $total );
	}

	/**
	 * Determine whether the command is running in dry-run mode.
	 *
	 * Every write command MUST check this flag and skip all destructive
	 * operations when it is true, logging what would have happened instead.
	 *
	 * @param array<string, mixed> $assocArgs The associative arguments array
	 *                                        passed to the command's __invoke().
	 *
	 * @return bool True when --dry-run is present, false otherwise.
	 */
	protected function isDryRun( array $assocArgs ): bool {
		return (bool) ( $assocArgs['dry-run'] ?? false );
	}

	/**
	 * Write a prefixed message to WP-CLI output.
	 *
	 * Dispatches to the appropriate WP_CLI method based on $level:
	 *   'log'     → WP_CLI::log     (neutral, white)
	 *   'success' → WP_CLI::success (green)
	 *   'warning' → WP_CLI::warning (yellow)
	 *   'error'   → WP_CLI::error   (red, exits with code 1)
	 *
	 * @param string $msg   The message to display.
	 * @param string $level One of: log, success, warning, error.
	 *
	 * @return void
	 */
	protected function log( string $msg, string $level = 'log' ): void {
		$prefixed = self::LOG_PREFIX . ' ' . $msg;

		match ( $level ) {
			'success' => \WP_CLI::success( $prefixed ),
			'warning' => \WP_CLI::warning( $prefixed ),
			'error'   => \WP_CLI::error( $prefixed ),
			default   => \WP_CLI::log( $prefixed ),
		};
	}

	/**
	 * Prompt the user for confirmation before proceeding.
	 *
	 * If --yes is present in $assocArgs the prompt is skipped entirely,
	 * making the command suitable for non-interactive CI/CD use. When the
	 * user answers 'n' at the prompt, the command is aborted via WP_CLI::error()
	 * which exits with code 1.
	 *
	 * @param string               $question  The yes/no question to display.
	 * @param array<string, mixed> $assocArgs The associative arguments from __invoke().
	 *
	 * @return void
	 */
	protected function confirm( string $question, array $assocArgs ): void {
		if ( ! empty( $assocArgs['yes'] ) ) {
			return;
		}

		\WP_CLI::confirm( $question );
	}
}
