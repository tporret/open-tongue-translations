<?php
/**
 * Translation driver: HTTP to a LibreTranslate instance on a private VPC IP.
 *
 * Structurally identical to LocalhostRestClient but targets a configurable
 * private-range IP address rather than the loopback interface. A strict
 * RFC 1918 guard prevents the driver from ever routing translation requests
 * to a public IP address — enforcing the plugin's data-locality guarantee at
 * the network layer.
 *
 * Optional Bearer-token authentication is supported for LibreTranslate
 * deployments that require an API key.
 *
 * @package OpenToungeTranslations\Connection\Drivers
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Connection\Drivers;

use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;

/**
 * Class PrivateVpcClient
 *
 * Sends translation requests via HTTP POST to a LibreTranslate instance
 * reachable only over a private VPC network (RFC 1918 addresses). The
 * driver refuses and logs any request whose configured IP falls outside
 * the private ranges, acting as a last-resort safety net against
 * misconfiguration.
 */
final class PrivateVpcClient implements TranslationClientInterface {

	/**
	 * @param string $ip      IPv4 address of the LibreTranslate instance (must be RFC 1918).
	 * @param int    $port    TCP port the instance is listening on.
	 * @param int    $timeout Maximum seconds to wait for a response.
	 * @param string $apiKey  Optional Bearer token; pass an empty string to omit the header.
	 */
	public function __construct(
		private readonly string $ip,
		private readonly int $port,
		private readonly int $timeout,
		private readonly string $apiKey,
	) {}

	/**
	 * Translate a UTF-8 string via the VPC-resident LibreTranslate REST API.
	 *
	 * Refuses to issue the request if the configured IP is not in a private
	 * RFC 1918 range. On any failure the original $text is returned.
	 *
	 * @param string $text       Source text (may contain HTML).
	 * @param string $sourceLang BCP-47 source language or "auto".
	 * @param string $targetLang BCP-47 target language.
	 *
	 * @return string Translated string, or $text on any failure.
	 */
	public function translate( string $text, string $sourceLang, string $targetLang ): string {
		if ( ! $this->isPrivateIp( $this->ip ) ) {
			error_log(
				sprintf(
					'[OpenTongue] PrivateVpcClient: IP "%s" is not in an RFC 1918 private range. Refusing request to protect data locality.',
					$this->ip
				)
			);
			return $text;
		}

		$endpoint = sprintf( 'http://%s:%d/translate', $this->ip, $this->port );

		try {
			$response = wp_remote_post( $endpoint, $this->buildArgs( $text, $sourceLang, $targetLang ) );

			if ( is_wp_error( $response ) ) {
				error_log(
					sprintf(
						'[OpenTongue] PrivateVpcClient error: %s',
						$response->get_error_message()
					)
				);
				return $text;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code !== 200 ) {
				error_log(
					sprintf(
						'[OpenTongue] PrivateVpcClient received non-200 status: %d',
						$code
					)
				);
				return $text;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, associative: true );

			if ( ! is_array( $data ) || ! isset( $data['translatedText'] ) ) {
				error_log( '[OpenTongue] PrivateVpcClient: malformed response — missing translatedText key.' );
				return $text;
			}

			return (string) $data['translatedText'];

		} catch ( \Throwable $e ) {
			error_log(
				sprintf(
					'[OpenTongue] PrivateVpcClient unexpected exception: %s',
					$e->getMessage()
				)
			);
			return $text;
		}
	}

	/**
	 * Check connectivity by probing the /languages endpoint on the VPC host.
	 *
	 * @return bool True if the backend responds with HTTP 200.
	 */
	public function healthCheck(): bool {
		if ( ! $this->isPrivateIp( $this->ip ) ) {
			return false;
		}

		$endpoint = sprintf( 'http://%s:%d/languages', $this->ip, $this->port );
		$args     = [ 'timeout' => $this->timeout ];

		if ( $this->apiKey !== '' ) {
			$args['headers'] = [ 'Authorization' => 'Bearer ' . $this->apiKey ];
		}

		$response = wp_remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_response_code( $response ) === 200;
	}

	/**
	 * Fetch available languages from the VPC-resident LibreTranslate /languages endpoint.
	 *
	 * Refuses to issue the request if the configured IP is not in a private range.
	 *
	 * @return array<int, array{code: string, name: string}> Empty array on failure.
	 */
	public function listLanguages(): array {
		if ( ! $this->isPrivateIp( $this->ip ) ) {
			return [];
		}

		$endpoint = sprintf( 'http://%s:%d/languages', $this->ip, $this->port );
		$args     = [ 'timeout' => $this->timeout ];

		if ( $this->apiKey !== '' ) {
			$args['headers'] = [ 'Authorization' => 'Bearer ' . $this->apiKey ];
		}

		$response = wp_remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), associative: true );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Build the WP_Http arguments array for a translation request.
	 *
	 * Injects the optional Bearer token when an API key has been configured.
	 *
	 * @param string $text       Source text.
	 * @param string $sourceLang BCP-47 source language or "auto".
	 * @param string $targetLang BCP-47 target language.
	 *
	 * @return array<string, mixed> WP_Http-compatible arguments array.
	 */
	private function buildArgs( string $text, string $sourceLang, string $targetLang ): array {
		$headers = [
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		];

		if ( $this->apiKey !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $this->apiKey;
		}

		return [
			'timeout' => $this->timeout,
			'headers' => $headers,
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

	/**
	 * Validate that an IPv4 address falls within an RFC 1918 private range.
	 *
	 * Checked ranges:
	 *   10.0.0.0/8       — Class A private
	 *   172.16.0.0/12    — Class B private
	 *   192.168.0.0/16   — Class C private
	 *
	 * IPv6 addresses always return false; this plugin targets IPv4 VPCs only.
	 *
	 * @param string $ip Dot-decimal IPv4 address to validate.
	 *
	 * @return bool True only when $ip is a valid IPv4 address in a private range.
	 */
	private function isPrivateIp( string $ip ): bool {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) === false ) {
			return false;
		}

		$long = ip2long( $ip );

		if ( $long === false ) {
			return false;
		}

		// Each entry: [network_long, subnet_mask]
		$privateRanges = [
			[ ip2long( '10.0.0.0' ),    0xFF000000 ],  // 10.0.0.0/8
			[ ip2long( '172.16.0.0' ),  0xFFF00000 ],  // 172.16.0.0/12
			[ ip2long( '192.168.0.0' ), 0xFFFF0000 ],  // 192.168.0.0/16
		];

		foreach ( $privateRanges as [ $network, $mask ] ) {
			if ( ( $long & $mask ) === ( $network & $mask ) ) {
				return true;
			}
		}

		return false;
	}
}
