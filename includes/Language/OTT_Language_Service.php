<?php
/**
 * Language catalogue service.
 *
 * Fetches the list of languages supported by the configured LibreTranslate
 * backend and caches the result as a WordPress transient for 24 hours.
 * All API calls are issued through the plugin's existing connection drivers
 * (Localhost, Socket, or VPC) so no external URLs are ever hardcoded.
 *
 * @package OpenToungeTranslations\Language
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Language;

use OpenToungeTranslations\Connection\ConnectionFactory;
use OpenToungeTranslations\Connection\Exception\ConnectionException;

/**
 * Class OTT_Language_Service
 *
 * Single point of truth for the language catalogue. Consumers must never
 * call the connection driver directly for language data; they must go through
 * this service so they benefit from the transient cache automatically.
 */
final class OTT_Language_Service {

	/**
	 * Transient key used to store the cached language list.
	 */
	private const TRANSIENT_KEY = 'ott_available_languages';

	/**
	 * How long (seconds) the language list is cached in the transient store.
	 * Defaults to 24 hours — language catalogues rarely change between deploys.
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * In-memory copy of the language list for the current request.
	 * Avoids hitting the transient store more than once per runtime.
	 *
	 * @var array<int, array{code: string, name: string}>|null
	 */
	private ?array $languages = null;

	/**
	 * @param ConnectionFactory $factory The configured connection factory.
	 */
	public function __construct(
		private readonly ConnectionFactory $factory,
	) {}

	/**
	 * Return the full list of supported languages.
	 *
	 * Resolution order:
	 *   1. In-memory cache (populated earlier in the same request).
	 *   2. WordPress transient (populated within the last 24 hours).
	 *   3. Live API call through the configured driver.
	 *
	 * On any driver failure an empty array is returned so callers can degrade
	 * gracefully instead of throwing.
	 *
	 * @return array<int, array{code: string, name: string}>
	 */
	public function getLanguages(): array {
		if ( $this->languages !== null ) {
			return $this->languages;
		}

		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) && $cached !== [] ) {
			$this->languages = $cached;
			return $this->languages;
		}

		try {
			$client = $this->factory->make();
			$raw    = $client->listLanguages();
		} catch ( ConnectionException | \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] OTT_Language_Service: failed to fetch languages — %s',
					$e->getMessage()
				)
			);
			return [];
		}

		// Normalise: keep only entries that have both 'code' and 'name'.
		$languages = [];
		foreach ( $raw as $item ) {
			if ( isset( $item['code'], $item['name'] ) ) {
				$languages[] = [
					'code' => (string) $item['code'],
					'name' => (string) $item['name'],
				];
			}
		}

		if ( $languages !== [] ) {
			set_transient( self::TRANSIENT_KEY, $languages, self::CACHE_TTL );
		}

		$this->languages = $languages;

		return $this->languages;
	}

	/**
	 * Return only the BCP-47 language codes supported by the backend.
	 *
	 * @return string[]
	 */
	public function getLanguageCodes(): array {
		return array_column( $this->getLanguages(), 'code' );
	}

	/**
	 * Check whether a given BCP-47 code is in the supported language list.
	 *
	 * @param string $code BCP-47 language code to validate (e.g. "fr").
	 *
	 * @return bool True when the code is recognised by the backend.
	 */
	public function isCodeSupported( string $code ): bool {
		return in_array( $code, $this->getLanguageCodes(), strict: true );
	}

	/**
	 * Delete the transient cache and reset the in-memory copy.
	 *
	 * Useful after changing the connection mode or upgrading LibreTranslate
	 * to a version with a different language set. Called by the admin
	 * settings page whenever the connection driver options are saved.
	 *
	 * @return void
	 */
	public function bustCache(): void {
		delete_transient( self::TRANSIENT_KEY );
		$this->languages = null;
	}
}
