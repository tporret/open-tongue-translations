<?php
/**
 * Translation driver: cURL over a Unix domain socket.
 *
 * WP_Http has no mechanism to bind to a Unix socket, so this driver uses
 * PHP's cURL extension directly. The socket path is validated before every
 * request; a missing or unreadable socket triggers a logged notice and an
 * immediate fallback to the original text — never an exception bubble.
 *
 * @package OpenToungeTranslations\Connection\Drivers
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Connection\Drivers;

use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;

/**
 * Class UnixSocketClient
 *
 * Communicates with a LibreTranslate instance over a Unix domain socket.
 * When CURLOPT_UNIX_SOCKET_PATH is set, cURL bypasses DNS and TCP entirely —
 * the URL (http://localhost/translate) is present only to satisfy the HTTP
 * protocol framing; actual I/O travels through the socket file.
 *
 * This is the lowest-latency driver for on-host LibreTranslate deployments
 * managed by systemd or a process supervisor that binds the socket.
 */
final class UnixSocketClient implements TranslationClientInterface {

	/**
	 * @param string $socketPath Absolute path to the LibreTranslate Unix socket file.
	 * @param int    $timeout    Maximum seconds to wait for a cURL response.
	 */
	public function __construct(
		private readonly string $socketPath,
		private readonly int $timeout,
	) {}

	/**
	 * Translate a UTF-8 string by POST-ing to LibreTranslate over the Unix socket.
	 *
	 * If the socket is missing, unreadable, or the request fails, $text is
	 * returned unchanged and the reason is written to the PHP error log.
	 *
	 * @param string $text       Source text (may contain HTML).
	 * @param string $sourceLang BCP-47 source language or "auto".
	 * @param string $targetLang BCP-47 target language.
	 *
	 * @return string Translated string, or $text on any failure.
	 */
	public function translate( string $text, string $sourceLang, string $targetLang ): string {
		if ( ! $this->isSocketAvailable() ) {
			error_log(
				sprintf(
					'[OpenTongue] UnixSocketClient: socket not available at "%s". Returning original text.',
					$this->socketPath
				)
			);
			return $text;
		}

		try {
			$payload = wp_json_encode(
				[
					'q'      => $text,
					'source' => $sourceLang,
					'target' => $targetLang,
					'format' => 'html',
				]
			);

			$ch = curl_init();

			curl_setopt_array(
				$ch,
				[
					// The URL is protocol framing only; cURL ignores name resolution
					// when CURLOPT_UNIX_SOCKET_PATH is provided.
					CURLOPT_URL             => 'http://localhost/translate',
					CURLOPT_UNIX_SOCKET_PATH => $this->socketPath,
					CURLOPT_POST            => true,
					CURLOPT_POSTFIELDS      => $payload,
					CURLOPT_RETURNTRANSFER  => true,
					CURLOPT_TIMEOUT         => $this->timeout,
					CURLOPT_HTTPHEADER      => [
						'Content-Type: application/json',
						'Accept: application/json',
						// Explicit Host header so LibreTranslate's virtual-host
						// routing accepts the request.
						'Host: localhost',
					],
				]
			);

			$result   = curl_exec( $ch );
			$httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curlError = curl_error( $ch );
			curl_close( $ch );

			if ( $result === false || $curlError !== '' ) {
				error_log(
					sprintf( '[OpenTongue] UnixSocketClient cURL error: %s', $curlError )
				);
				return $text;
			}

			if ( $httpCode !== 200 ) {
				error_log(
					sprintf( '[OpenTongue] UnixSocketClient received non-200 status: %d', $httpCode )
				);
				return $text;
			}

			$data = json_decode( (string) $result, associative: true );

			if ( ! is_array( $data ) || ! isset( $data['translatedText'] ) ) {
				error_log( '[OpenTongue] UnixSocketClient: malformed response — missing translatedText key.' );
				return $text;
			}

			return (string) $data['translatedText'];

		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] UnixSocketClient unexpected exception: %s',
					$e->getMessage()
				)
			);
			return $text;
		}
	}

	/**
	 * Check connectivity by probing the /languages endpoint over the socket.
	 *
	 * @return bool True if a 200 response is received.
	 */
	public function healthCheck(): bool {
		if ( ! $this->isSocketAvailable() ) {
			return false;
		}

		try {
			$ch = curl_init();

			curl_setopt_array(
				$ch,
				[
					CURLOPT_URL             => 'http://localhost/languages',
					CURLOPT_UNIX_SOCKET_PATH => $this->socketPath,
					CURLOPT_RETURNTRANSFER  => true,
					CURLOPT_TIMEOUT         => $this->timeout,
					CURLOPT_HTTPHEADER      => [ 'Host: localhost' ],
				]
			);

			$result   = curl_exec( $ch );
			$httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			return $result !== false && $httpCode === 200;

		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Confirm the socket file exists and is readable before attempting I/O.
	 *
	 * @return bool True when the socket is present and readable.
	 */
	private function isSocketAvailable(): bool {
		return file_exists( $this->socketPath ) && is_readable( $this->socketPath );
	}
}
