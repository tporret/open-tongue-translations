<?php
/**
 * WP-CLI command: wp ott translate
 *
 * Provides three sub-commands for translating WordPress content via the
 * HTML-aware pipeline:
 *
 *   wp ott translate batch   — bulk-translate all posts of a given type/locale
 *   wp ott translate post    — translate a single post by ID
 *   wp ott translate string  — spot-test the pipeline with an inline string
 *
 * @package OpenToungeTranslations\Cli
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Cli;

use OpenToungeTranslations\Cache\CacheManager;
use OpenToungeTranslations\Compat\StaticCacheCompatManager;
use OpenToungeTranslations\Database\TranslationRecord;
use OpenToungeTranslations\Database\TranslationRepository;
use OpenToungeTranslations\Html\HtmlAwareTranslator;

/**
 * Class TranslateCommand
 *
 * ## Memory management strategy
 *
 * Batch translation of large sites (10k+ posts) can exhaust PHP memory if
 * all post objects are held in memory simultaneously. This command uses two
 * strategies to prevent OOM:
 *
 *  1. First WP_Query pass retrieves IDs only ('fields' => 'ids') with
 *     post-meta and term caches disabled. Post content is fetched
 *     post-by-post via get_post() inside the processing loop.
 *
 *  2. wp_cache_flush() is called every 50 posts to purge the WordPress
 *     object cache (post objects, meta, terms) that accumulates during
 *     a large batch. Without this, each get_post() call adds to a growing
 *     in-memory array that is never garbage-collected within the request.
 *
 * The --batch-size flag controls how many posts are passed to each
 * WP_Query call. Reduce it on hosts with < 256 MB memory_limit.
 */
final class TranslateCommand extends BaseCommand {

	/**
	 * How many posts to process before flushing the WP object cache.
	 */
	private const CACHE_FLUSH_INTERVAL = 50;

	public function __construct(
		private readonly HtmlAwareTranslator $translator,
		private readonly TranslationRepository $repo,
		private readonly CacheManager $cacheManager,
		private readonly StaticCacheCompatManager $compatManager,
	) {}

	// =========================================================================
	// Subcommand: batch
	// =========================================================================

	/**
	 * Batch-translate posts matching the given criteria.
	 *
	 * ## OPTIONS
	 *
	 * --locale=<locale>
	 * : BCP-47 target language code (required). Example: fr, de, es-MX.
	 *
	 * [--post-type=<post-type>]
	 * : Comma-separated list of post types to translate. Default: post,page.
	 *
	 * [--post-id=<id>]
	 * : Restrict to a single post ID (overrides --post-type).
	 *
	 * [--limit=<n>]
	 * : Maximum number of posts to process. Default: 100.
	 *
	 * [--offset=<n>]
	 * : Number of posts to skip. Default: 0.
	 *
	 * [--batch-size=<n>]
	 * : Posts per WP_Query page. Default: 50. Reduce on low-memory hosts.
	 *
	 * [--force]
	 * : Re-translate even when a cached translation exists.
	 *   CRITICAL: is_manual=1 rows are NEVER overwritten, even with --force.
	 *
	 * [--dry-run]
	 * : Log what would be translated but commit nothing.
	 *
	 * [--yes]
	 * : Skip confirmation prompts (for CI/CD use).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott translate batch --locale=fr --post-type=post --limit=500
	 *   wp ott translate batch --locale=de --force --dry-run
	 *
	 * @subcommand batch
	 *
	 * @param array<int, string>    $args      Positional arguments.
	 * @param array<string, mixed>  $assocArgs Named arguments (flags).
	 *
	 * @return void
	 */
	public function batch( array $args, array $assocArgs ): void {
		$locale    = (string) ( $assocArgs['locale'] ?? '' );
		$dryRun    = $this->isDryRun( $assocArgs );
		$force     = ! empty( $assocArgs['force'] );
		$limit     = (int) ( $assocArgs['limit'] ?? 100 );
		$offset    = (int) ( $assocArgs['offset'] ?? 0 );
		$batchSize = (int) ( $assocArgs['batch-size'] ?? 50 );

		if ( $locale === '' ) {
			$this->log( '--locale is required.', 'error' );
		}

		$postTypes = isset( $assocArgs['post-id'] )
			? []
			: array_map( 'trim', explode( ',', (string) ( $assocArgs['post-type'] ?? 'post,page' ) ) );

		if ( $dryRun ) {
			$this->log( 'DRY-RUN mode — no data will be written.', 'warning' );
		}

		// Resolve IDs without loading full post objects.
		$allIds = $this->resolvePostIds( $assocArgs, $postTypes, $limit, $offset, $batchSize, $locale );

		if ( empty( $allIds ) ) {
			$this->log( 'No posts found matching the given criteria.', 'warning' );
			return;
		}

		$this->log( sprintf( 'Found %d post(s) to process.', count( $allIds ) ) );

		$progress = $this->progressBar( 'Translating posts', count( $allIds ) );
		$summary  = [];
		$counter  = 0;

		foreach ( $allIds as $postId ) {
			$startMs = (int) round( microtime( true ) * 1000 );
			$post    = get_post( $postId );

			if ( ! $post instanceof \WP_Post ) {
				$progress->tick();
				++$counter;
				continue;
			}

			[ 'translated' => $translated, 'skipped' => $skipped, 'found' => $found ]
				= $this->translatePost( $post, $locale, $force, $dryRun );

			$elapsed = (int) round( microtime( true ) * 1000 ) - $startMs;

			$summary[] = [
				'post_id'             => $postId,
				'post_title'          => mb_strimwidth( $post->post_title, 0, 50, '…' ),
				'segments_found'      => $found,
				'segments_translated' => $translated,
				'skipped'             => $skipped,
				'elapsed_ms'          => $elapsed,
			];

			$progress->tick();
			++$counter;

			// Flush the WP object cache every N posts to prevent memory
			// exhaustion. Each get_post() call populates multiple cache
			// buckets that are never evicted within a single CLI request.
			if ( $counter % self::CACHE_FLUSH_INTERVAL === 0 ) {
				wp_cache_flush();
				$this->log( sprintf( 'Flushed WP object cache at post %d/%d.', $counter, count( $allIds ) ) );
			}
		}

		$progress->finish();

		\WP_CLI\Utils\format_items( 'table', $summary, array_keys( $summary[0] ?? [] ) );
		$this->log( sprintf( 'Done. Processed %d post(s).', count( $allIds ) ), 'success' );
	}

	// =========================================================================
	// Subcommand: post
	// =========================================================================

	/**
	 * Translate a single post by ID.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : The WordPress post ID to translate.
	 *
	 * --locale=<locale>
	 * : BCP-47 target language code (required).
	 *
	 * [--force]
	 * : Re-translate even if a cached translation exists.
	 *   is_manual=1 rows are never overwritten.
	 *
	 * [--dry-run]
	 * : Log what would be translated but commit nothing.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott translate post 42 --locale=fr
	 *   wp ott translate post 42 --locale=de --force --dry-run
	 *
	 * @subcommand post
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function post( array $args, array $assocArgs ): void {
		$postId = (int) ( $args[0] ?? 0 );
		$locale = (string) ( $assocArgs['locale'] ?? '' );
		$dryRun = $this->isDryRun( $assocArgs );
		$force  = ! empty( $assocArgs['force'] );

		if ( $postId <= 0 ) {
			$this->log( 'A valid <post-id> is required.', 'error' );
		}

		if ( $locale === '' ) {
			$this->log( '--locale is required.', 'error' );
		}

		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			$this->log( sprintf( 'Post %d not found.', $postId ), 'error' );
		}

		$startMs = (int) round( microtime( true ) * 1000 );
		$result  = $this->translatePost( $post, $locale, $force, $dryRun );
		$elapsed = (int) round( microtime( true ) * 1000 ) - $startMs;

		if ( ! $dryRun ) {
			$this->compatManager->purgePost( $postId, $locale );
		}

		\WP_CLI\Utils\format_items( 'table', [ array_merge( $result, [
			'post_id'    => $postId,
			'locale'     => $locale,
			'elapsed_ms' => $elapsed,
		] ) ], [ 'post_id', 'locale', 'found', 'translated', 'skipped', 'elapsed_ms' ] );

		$this->log( sprintf( 'Post %d translated into %s.', $postId, $locale ), 'success' );
	}

	// =========================================================================
	// Subcommand: string
	// =========================================================================

	/**
	 * Translate an arbitrary string inline — for spot-testing only.
	 *
	 * ## IMPORTANT
	 * This subcommand does NOT persist translations to the database.
	 * It is intended purely for testing the connection, glossary pipeline,
	 * and HTML-aware translator from the terminal. Do not use in scripts.
	 *
	 * ## OPTIONS
	 *
	 * <text>
	 * : The string to translate.
	 *
	 * --from=<lang>
	 * : BCP-47 source language code (e.g. en).
	 *
	 * --to=<lang>
	 * : BCP-47 target language code (e.g. fr).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ott translate string "Hello World" --from=en --to=fr
	 *   wp ott translate string "<p>Hello <strong>World</strong></p>" --from=en --to=de
	 *
	 * @subcommand string
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assocArgs
	 *
	 * @return void
	 */
	public function string( array $args, array $assocArgs ): void {
		$text = $args[0] ?? '';
		$from = (string) ( $assocArgs['from'] ?? 'auto' );
		$to   = (string) ( $assocArgs['to'] ?? '' );

		if ( $text === '' ) {
			$this->log( 'A non-empty <text> argument is required.', 'error' );
		}

		if ( $to === '' ) {
			$this->log( '--to is required.', 'error' );
		}

		$cacheHit = false;
		$hash     = \OpenToungeTranslations\Database\TranslationRecord::computeHash( $text );
		$cached   = $this->cacheManager->resolve( [ $hash ], $to );

		if ( isset( $cached[ $hash ] ) ) {
			$cacheHit   = true;
			$translated = $cached[ $hash ];
			$elapsed    = 0;
		} else {
			$startMs    = (int) round( microtime( true ) * 1000 );
			$translated = $this->translator->translate( $text, $from, $to );
			$elapsed    = (int) round( microtime( true ) * 1000 ) - $startMs;
		}

		\WP_CLI\Utils\format_items( 'table', [ [
			'source'     => mb_strimwidth( $text, 0, 80, '…' ),
			'translated' => mb_strimwidth( $translated, 0, 80, '…' ),
			'cache'      => $cacheHit ? 'HIT' : 'MISS',
			'elapsed_ms' => $elapsed,
		] ], [ 'source', 'translated', 'cache', 'elapsed_ms' ] );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Resolve the list of post IDs to process based on the provided arguments.
	 *
	 * Uses 'fields' => 'ids' to avoid loading post objects into memory on the
	 * discovery pass. 'no_found_rows' prevents an expensive COUNT(*) query.
	 * Disabling post-meta and term caches avoids pre-loading data we won't use.
	 *
	 * @param array<string, mixed> $assocArgs
	 * @param string[]             $postTypes
	 * @param int                  $limit
	 * @param int                  $offset
	 * @param int                  $batchSize
	 * @param string               $locale
	 *
	 * @return int[]
	 */
	private function resolvePostIds(
		array $assocArgs,
		array $postTypes,
		int $limit,
		int $offset,
		int $batchSize,
		string $locale
	): array {
		if ( isset( $assocArgs['post-id'] ) ) {
			return [ (int) $assocArgs['post-id'] ];
		}

		$allIds = [];
		$page   = 1;

		do {
			$queryArgs = [
				'post_type'              => $postTypes,
				'post_status'            => 'publish',
				'posts_per_page'         => $batchSize,
				'paged'                  => $page,
				'offset'                 => $offset + ( ( $page - 1 ) * $batchSize ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,   // Skip COUNT(*) — not needed for IDs-only pass.
				'update_post_meta_cache' => false,   // We don't need post meta during ID discovery.
				'update_post_term_cache' => false,   // Likewise for taxonomy terms.
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			];

			/**
			 * Allow developers to modify the WP_Query args used during batch translation.
			 *
			 * @param array  $queryArgs The resolved WP_Query arguments.
			 * @param string $locale    BCP-47 target locale.
			 */
			$queryArgs = (array) apply_filters( 'ott_cli_batch_query_args', $queryArgs, $locale );

			$query = new \WP_Query( $queryArgs );
			$ids   = (array) $query->posts;

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$allIds[] = (int) $id;
				if ( count( $allIds ) >= $limit ) {
					break 2;
				}
			}

			++$page;
		} while ( count( $ids ) === $batchSize );

		return $allIds;
	}

	/**
	 * Run the full translation pipeline for a single WP_Post.
	 *
	 * Extracts content segments, checks the cache, translates misses via the
	 * HTML-aware pipeline, and persists the results.
	 *
	 * @param \WP_Post $post    The post to translate.
	 * @param string   $locale  BCP-47 target locale.
	 * @param bool     $force   When true, re-translate cached hits (but never is_manual=1 rows).
	 * @param bool     $dryRun  When true, log only — do not write to DB.
	 *
	 * @return array{found: int, translated: int, skipped: int}
	 */
	private function translatePost( \WP_Post $post, string $locale, bool $force, bool $dryRun ): array {
		// Segments: title, excerpt, content (stripped of shortcodes for safety).
		$segments = array_filter( [
			$post->post_title,
			$post->post_excerpt,
			strip_shortcodes( $post->post_content ),
		] );

		$found      = count( $segments );
		$translated = 0;
		$skipped    = 0;
		$records    = [];

		foreach ( $segments as $segment ) {
			if ( trim( $segment ) === '' ) {
				continue;
			}

			$hash = TranslationRecord::computeHash( $segment );

			// Skip the API call if a cached translation exists AND --force not set.
			if ( ! $force ) {
				$cached = $this->cacheManager->resolve( [ $hash ], $locale );
				if ( isset( $cached[ $hash ] ) ) {
					++$skipped;
					continue;
				}
			}

			// When --force is active, still skip is_manual=1 rows — human edits
			// are never overwritten by automated processes.
			if ( $force ) {
				$existing = $this->repo->find( $hash, $locale );
				if ( $existing !== null && $existing->isManual ) {
					$this->log(
						sprintf( 'Skipping is_manual row for post %d (hash %s).', $post->ID, $hash ),
						'warning'
					);
					++$skipped;
					continue;
				}
			}

			if ( $dryRun ) {
				$this->log( sprintf( '[DRY-RUN] Would translate segment (hash %s) for post %d.', $hash, $post->ID ) );
				++$translated;
				continue;
			}

			$translatedText = $this->translator->translate( $segment, 'auto', $locale );

			$records[] = TranslationRecord::fromRow( [
				'string_hash'    => $hash,
				'source_lang'    => 'auto',
				'target_lang'    => $locale,
				'source_text'    => $segment,
				'translated_text' => $translatedText,
				'context'        => '',
				'is_manual'      => 0,
			] );

			++$translated;
		}

		if ( ! $dryRun && ! empty( $records ) ) {
			$this->repo->upsertBatch( $records );
		}

		return [
			'found'      => $found,
			'translated' => $translated,
			'skipped'    => $skipped,
		];
	}
}
