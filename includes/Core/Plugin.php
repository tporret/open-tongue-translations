<?php
/**
 * Core plugin orchestrator.
 *
 * Owns the plugin lifecycle: resolves the translation client via the
 * ConnectionFactory, brings up the persistence + caching layer, registers
 * both interception layers, wires static-cache compat, and schedules
 * maintenance jobs. Any failure during boot is caught and logged so
 * WordPress core is never disrupted.
 *
 * @package OpenToungeTranslations\Core
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Core;

use OpenToungeTranslations\Cache\CacheManager;
use OpenToungeTranslations\Cache\DatabaseCacheDriver;
use OpenToungeTranslations\Cache\ObjectCacheDriver;
use OpenToungeTranslations\Cli\CacheCommand;
use OpenToungeTranslations\Cli\GlossaryCommand;
use OpenToungeTranslations\Cli\StatusCommand;
use OpenToungeTranslations\Cli\TranslateCommand;
use OpenToungeTranslations\Compat\StaticCacheCompatManager;
use OpenToungeTranslations\Connection\ConnectionFactory;
use OpenToungeTranslations\Connection\Exception\ConnectionException;
use OpenToungeTranslations\Database\Migrations\Migration_1_2_0;
use OpenToungeTranslations\Database\Schema;
use OpenToungeTranslations\Database\TranslationRepository;
use OpenToungeTranslations\Exclusion\ExclusionEngine;
use OpenToungeTranslations\Exclusion\ExclusionRuleRepository;
use OpenToungeTranslations\Html\AttributePreserver;
use OpenToungeTranslations\Html\HtmlAwareTranslator;
use OpenToungeTranslations\Html\TagProtector;
use OpenToungeTranslations\Interception\GettextInterceptor;
use OpenToungeTranslations\Interception\OutputBufferInterceptor;
use OpenToungeTranslations\Maintenance\PruningJob;

/**
 * Class Plugin
 *
 * Entry point into the plugin's object graph. Instantiated once on
 * `plugins_loaded` from the main bootstrap file.
 */
final class Plugin {

	/**
	 * Holds the connection factory for the request lifecycle.
	 */
	private ConnectionFactory $factory;

	/**
	 * Plugin constructor.
	 *
	 * Instantiates the ConnectionFactory. Driver resolution is deferred
	 * until boot() so that options are available.
	 */
	public function __construct() {
		$this->factory = new ConnectionFactory();
	}

	/**
	 * Bootstrap all plugin subsystems.
	 *
	 * Order of operations:
	 *  1. Schema — create/upgrade DB table if version changed.
	 *  2. Translation client — resolve the configured driver.
	 *  3. Cache stack — wire ObjectCacheDriver → DatabaseCacheDriver → CacheManager.
	 *  4. Interception — register gettext filter and output-buffer hook.
	 *  5. Static-cache compat — register WP Rocket / Cloudflare invalidation hooks.
	 *  6. Maintenance — register PruningJob cron hooks.
	 *
	 * If the connection mode is misconfigured, a notice is logged and the
	 * interception layers are not registered — the site continues to function
	 * without translation but is never broken.
	 *
	 * @return void
	 */
	public function boot(): void {
		// --- 1. Schema -------------------------------------------------------
		( new Schema() )->createOrUpgrade();

		// Run Migration_1_2_0 if the exclusion_rules table hasn't been created yet.
		if ( version_compare( (string) get_option( 'ott_db_version', '0.0.0' ), Migration_1_2_0::VERSION, '<' ) ) {
			( new Migration_1_2_0() )->up();
		}

		// --- 2. Translation client -------------------------------------------
		try {
			$client = $this->factory->make();
		} catch ( ConnectionException $e ) {
			error_log(
				sprintf(
					'[OpenTongue] Plugin failed to boot — connection misconfigured: %s',
					$e->getMessage()
				)
			);
			return;
		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] Plugin encountered an unexpected error during boot: %s',
					$e->getMessage()
				)
			);
			return;
		}

		/** @var string $targetLang The BCP-47 language tag the site should be translated into. */
		$targetLang = (string) get_option( 'ltp_target_lang', 'en' );

		// --- 3. Cache stack --------------------------------------------------
		$objectCache   = new ObjectCacheDriver();
		$objectCache->register();

		$repo          = new TranslationRepository();
		$dbCache       = new DatabaseCacheDriver( $repo );
		$cacheManager  = new CacheManager( $objectCache, $dbCache, $repo );

		// --- 4. HTML-aware translation decorator -----------------------------
		// Wrap the raw client with the HTML pipeline so that every string routed
		// through HtmlAwareTranslator benefits from tag/attribute protection and
		// user-defined exclusion rules.
		$tagProtector    = new TagProtector();
		$attrPreserver   = new AttributePreserver();
		$exclusionRepo   = new ExclusionRuleRepository();
		$exclusionEngine = new ExclusionEngine( $exclusionRepo );
		$htmlClient      = new HtmlAwareTranslator( $client, $tagProtector, $attrPreserver, $exclusionEngine );

		// --- 5. Interception -------------------------------------------------
		$gettextInterceptor = new GettextInterceptor( $htmlClient, $targetLang, $cacheManager );
		$gettextInterceptor->register();

		$outputBufferInterceptor = new OutputBufferInterceptor( $htmlClient, $targetLang );
		$outputBufferInterceptor->setExclusionEngine( $exclusionEngine );
		$outputBufferInterceptor->register();

		// --- 5. Static-cache compat ------------------------------------------
		// Registered at priority 99 on plugins_loaded so all third-party plugins
		// (WP Rocket, Cloudflare) have registered their functions and classes first.
		$compatManager = new StaticCacheCompatManager();
		add_action(
			'plugins_loaded',
			static function () use ( $compatManager ): void {
				$compatManager->register();
			},
			99
		);

		// --- 6. Maintenance --------------------------------------------------
		$pruningJob = new PruningJob( $repo );
		$pruningJob->register();

		// --- 7. WP-CLI command registration ----------------------------------
		// Commands are registered inside boot() so they receive fully-wired
		// dependencies rather than recreating the DI graph in the bootstrap.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ott translate', new TranslateCommand( $htmlClient, $repo, $cacheManager, $compatManager ) );
			\WP_CLI::add_command( 'ott cache',     new CacheCommand( $cacheManager, $repo ) );
			\WP_CLI::add_command( 'ott glossary',  new GlossaryCommand() );
			\WP_CLI::add_command( 'ott status',    new StatusCommand( $client, $repo ) );
		}
	}
}
