<?php
/**
 * Translation driver: HTTP to a localhost LibreTranslate instance.
 *
 * Uses WP_Http (wp_remote_post) so the request inherits WordPress's proxy
 * settings and SSL handling. Hard timeout of 3 seconds ensures the driver
 * never stalls page rendering.
 *
 * @package OpenToungeTranslations\Connection\Drivers
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Connection\Drivers;

use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;

/**
 * Class LocalhostRestClient
 *
 * Sends translation requests via HTTP POST to a LibreTranslate instance
 * bound to the loopback interface (127.0.0.1). Intended for setups where
 * LibreTranslate runs as a local service on the same host as WordPress.
 */
final class LocalhostRestClient implements TranslationClientInterface {

	/**
	 * @param string $host    IPv4 or IPv6 address of the local LibreTranslate instance.
	 * @param int    $port    TCP port the instance is listening on.
	 * @param int    $timeout Maximum seconds to wait for a response.
	 */
	public function __construct(
		private readonly string $host,
		private readonly int $port,
		private readonly int $timeout,
	) {}

	/**
	 * Translate a UTF-8 string via the LibreTranslate REST API.
	 *
	 * On WP_Error, non-200 status, or malformed response the original
	 * $text is returned untouched and the error is written to the PHP
	 * error log.
	 *
	 * @param string $text       Source text (may contain HTML).
	 * @param string $sourceLang BCP-47 source language or "auto".
	 * @param string $targetLang BCP-47 target language.
	 *
	 * @return string Translated string, or $text on any failure.
	 */
	public function translate( string $text, string $sourceLang, string $targetLang ): string {
		$endpoint = sprintf( 'http://%s:%d/translate', $this->host, $this->port );

		try {
			$response = wp_remote_post( $endpoint, $this->buildArgs( $text, $sourceLang, $targetLang ) );

			if ( is_wp_error( $response ) ) {
				error_log(
					sprintf(
						'[OpenTongue] LocalhostRestClient error: %s',
						$response->get_error_message()
					)
				);
				return $text;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code !== 200 ) {
				error_log(
					sprintf(
						'[OpenTongue] LocalhostRestClient received non-200 status: %d',
						$code
					)
				);
				return $text;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, associative: true );

			if ( ! is_array( $data ) || ! isset( $data['translatedText'] ) ) {
				error_log( '[OpenTongue] LocalhostRestClient: malformed response — missing translatedText key.' );
				return $text;
			}

			return (string) $data['translatedText'];

		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] LocalhostRestClient unexpected exception: %s',
					$e->getMessage()
				)
			);
			return $text;
		}
	}

	/**
	 * Check whether the LibreTranslate instance is reachable.
	 *
	 * Uses the /languages endpoint (GET, no auth) as a lightweight probe.
	 *
	 * @return bool True if the backend responds with HTTP 200.
	 */
	public function healthCheck(): bool {
		$endpoint = sprintf( 'http://%s:%d/languages', $this->host, $this->port );

		$response = wp_remote_get( $endpoint, [ 'timeout' => $this->timeout ] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_response_code( $response ) === 200;
	}

	/**
	 * Build the WP_Http arguments array for a translation request.
	 *
	 * Kept private so the HTTP contract (headers, body shape, timeout) is
	 * defined in one place and not leaked to callers.
	 *
	 * @param string $text       Source text.
	 * @param string $sourceLang BCP-47 source language or "auto".
	 * @param string $targetLang BCP-47 target language.
	 *
	 * @return array<string, mixed> WP_Http-compatible arguments array.
	 */
	private function buildArgs( string $text, string $sourceLang, string $targetLang ): array {
		return [
			'timeout' => $this->timeout,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode(
				[
					'q'      => $text,
					'source' => $sourceLang,
					'target' => $targetLang,
					'format' => 'html',
				]
			),
		];
	}
}
