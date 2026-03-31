<?php
/**
 * WP-CLI command: wp ott glossary
 *
 * Provides three sub-commands for managing the OTT translation glossary:
 *
 *   wp ott glossary import  — import terms from a CSV file (streamed row-by-row)
 *   wp ott glossary export  — export terms to CSV (streamed via fputcsv)
 *   wp ott glossary list    — list terms in table / csv / json format
 *
 * ## CSV format (import / export)
 * source_term, target_term, is_protected, case_sensitive
 *
 * @package OpenToungeTranslations\Cli
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cli;

/**
 * Class GlossaryCommand
 *
 * The glossary table ({prefix}ott_glossary) is managed directly via $wpdb
 * in this command. A dedicated GlossaryRepository will be added when the
 * admin UI is built in a later task.
 */
final class GlossaryCommand extends BaseCommand {

	/**
	 * Expected CSV column order for import and export operations.
	 */
	private const CSV_COLUMNS = [ 'source_term', 'target_term', 'is_protected', 'case_sensitive' ];

	// =========================================================================
	// Subcommand: import
	// =========================================================================

	/**
	 * Import glossary terms from a CSV file.
	 *
	 * Rows are processed one at a time (fgetcsv) — the file is never loaded
	 * into memory in its entirety, making this safe for large glossary files.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file to import.
	 *
	 * --locale=<locale>
	 * : BCP-47 target language code the glossary applies to (required).
	 *
	 * [--overwrite]
	 * : Upsert existing terms. Without this flag, duplicate source terms
	 *   are skipped and a warning is emitted.
	 *
	 * [--dry-run]
	 * : Parse and validate the file but do not write to the database.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott glossary import glossary-fr.csv --locale=fr
	 *   wp ott glossary import glossary-fr.csv --locale=fr --overwrite --dry-run
	 *
	 * @subcommand import
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function import( array $args, array $assocArgs ): void {
		$filePath  = $args[0] ?? '';
		$locale    = (string) ( $assocArgs['locale'] ?? '' );
		$overwrite = ! empty( $assocArgs['overwrite'] );
		$dryRun    = $this->isDryRun( $assocArgs );

		if ( $filePath === '' ) {
			$this->log( 'A CSV <file> path is required.', 'error' );
		}

		if ( $locale === '' ) {
			$this->log( '--locale is required.', 'error' );
		}

		if ( ! file_exists( $filePath ) || ! is_readable( $filePath ) ) {
			$this->log( sprintf( 'File not found or not readable: %s', $filePath ), 'error' );
		}

		if ( $dryRun ) {
			$this->log( 'DRY-RUN mode — no data will be written.', 'warning' );
		}

		$handle = fopen( $filePath, 'r' );
		if ( $handle === false ) {
			$this->log( 'Failed to open the CSV file.', 'error' );
		}

		// Skip the header row if present.
		$firstRow = fgetcsv( $handle );
		$hasHeader = (
			$firstRow !== false &&
			array_map( 'trim', $firstRow ) === self::CSV_COLUMNS
		);

		if ( ! $hasHeader && $firstRow !== false ) {
			// Not a header row — rewind and process it as data.
			rewind( $handle );
		}

		global $wpdb;
		$table     = $wpdb->prefix . 'ott_glossary';
		$lineNum   = $hasHeader ? 1 : 0;
		$imported  = 0;
		$skipped   = 0;
		$errors    = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			++$lineNum;

			if ( count( $row ) < 4 ) {
				$this->log( sprintf( 'Line %d: expected 4 columns, got %d — skipping.', $lineNum, count( $row ) ), 'warning' );
				++$errors;
				continue;
			}

			[ $sourceTerm, $targetTerm, $isProtected, $caseSensitive ] = $row;

			$sourceTerm    = trim( $sourceTerm );
			$targetTerm    = trim( $targetTerm );
			$isProtected   = (int) (bool) $isProtected;
			$caseSensitive = (int) (bool) $caseSensitive;

			if ( $sourceTerm === '' || $targetTerm === '' ) {
				$this->log( sprintf( 'Line %d: source_term or target_term is empty — skipping.', $lineNum ), 'warning' );
				++$errors;
				continue;
			}

			if ( $dryRun ) {
				$this->log( sprintf( '[DRY-RUN] Would import "%s" → "%s" (%s).', $sourceTerm, $targetTerm, $locale ) );
				++$imported;
				continue;
			}

			// Check for existing term.
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_term = %s AND locale = %s LIMIT 1",
				$sourceTerm,
				$locale
			) );

			if ( $existing !== null && ! $overwrite ) {
				$this->log( sprintf( 'Line %d: "%s" already exists — skipping (use --overwrite to replace).', $lineNum, $sourceTerm ), 'warning' );
				++$skipped;
				continue;
			}

			if ( $existing !== null && $overwrite ) {
				$wpdb->update(
					$table,
					[
						'target_term'    => $targetTerm,
						'is_protected'   => $isProtected,
						'case_sensitive' => $caseSensitive,
					],
					[ 'id' => (int) $existing ],
					[ '%s', '%d', '%d' ],
					[ '%d' ]
				);
			} else {
				$wpdb->insert(
					$table,
					[
						'locale'         => $locale,
						'source_term'    => $sourceTerm,
						'target_term'    => $targetTerm,
						'is_protected'   => $isProtected,
						'case_sensitive' => $caseSensitive,
					],
					[ '%s', '%s', '%s', '%d', '%d' ]
				);
			}

			++$imported;
		}

		fclose( $handle );

		$this->log( sprintf( 'Import complete — imported: %d, skipped: %d, errors: %d.', $imported, $skipped, $errors ), 'success' );
	}

	// =========================================================================
	// Subcommand: export
	// =========================================================================

	/**
	 * Export glossary terms to a CSV file or stdout.
	 *
	 * Rows are written with fputcsv() — the result set is never built as a
	 * string variable, making this safe for very large glossaries.
	 *
	 * ## OPTIONS
	 *
	 * --locale=<locale>
	 * : BCP-47 target language code to export (required).
	 *
	 * [--file=<file>]
	 * : Destination file path. Omit to write to stdout.
	 *
	 * [--protected-only]
	 * : Export only terms where is_protected = 1.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott glossary export --locale=fr --file=glossary-fr.csv
	 *   wp ott glossary export --locale=fr
	 *   wp ott glossary export --locale=fr --protected-only --file=protected-fr.csv
	 *
	 * @subcommand export
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function export( array $args, array $assocArgs ): void {
		$locale        = (string) ( $assocArgs['locale'] ?? '' );
		$file          = $assocArgs['file'] ?? null;
		$protectedOnly = ! empty( $assocArgs['protected-only'] );

		if ( $locale === '' ) {
			$this->log( '--locale is required.', 'error' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ott_glossary';

		$sql = $protectedOnly
			? $wpdb->prepare( "SELECT source_term, target_term, is_protected, case_sensitive FROM {$table} WHERE locale = %s AND is_protected = 1 ORDER BY source_term ASC", $locale )
			: $wpdb->prepare( "SELECT source_term, target_term, is_protected, case_sensitive FROM {$table} WHERE locale = %s ORDER BY source_term ASC", $locale );

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			$this->log( sprintf( 'No glossary terms found for locale "%s".', $locale ), 'warning' );
			return;
		}

		$handle = ( $file !== null ) ? fopen( (string) $file, 'w' ) : STDOUT;

		if ( $handle === false ) {
			$this->log( sprintf( 'Failed to open output file: %s', $file ), 'error' );
		}

		// Write header row.
		fputcsv( $handle, self::CSV_COLUMNS );

		foreach ( $rows as $row ) {
			fputcsv( $handle, [
				$row['source_term'],
				$row['target_term'],
				$row['is_protected'],
				$row['case_sensitive'],
			] );
		}

		if ( $file !== null ) {
			fclose( $handle );
			$this->log( sprintf( 'Exported %d term(s) to %s.', count( $rows ), $file ), 'success' );
		}
	}

	// =========================================================================
	// Subcommand: list
	// =========================================================================

	/**
	 * List glossary terms for a locale.
	 *
	 * ## OPTIONS
	 *
	 * --locale=<locale>
	 * : BCP-47 target language code (required).
	 *
	 * [--protected-only]
	 * : Show only terms where is_protected = 1.
	 *
	 * [--format=<format>]
	 * : Output format: table, csv, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott glossary list --locale=fr
	 *   wp ott glossary list --locale=fr --format=json
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function list( array $args, array $assocArgs ): void {
		$locale        = (string) ( $assocArgs['locale'] ?? '' );
		$protectedOnly = ! empty( $assocArgs['protected-only'] );
		$format        = (string) ( $assocArgs['format'] ?? 'table' );

		if ( $locale === '' ) {
			$this->log( '--locale is required.', 'error' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ott_glossary';

		$sql = $protectedOnly
			? $wpdb->prepare( "SELECT source_term, target_term, is_protected, case_sensitive FROM {$table} WHERE locale = %s AND is_protected = 1 ORDER BY source_term ASC", $locale )
			: $wpdb->prepare( "SELECT source_term, target_term, is_protected, case_sensitive FROM {$table} WHERE locale = %s ORDER BY source_term ASC", $locale );

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			$this->log( sprintf( 'No glossary terms found for locale "%s".', $locale ), 'warning' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, self::CSV_COLUMNS );
	}
}
