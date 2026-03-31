<?php
/**
 * Factory that resolves and memoizes the configured translation driver.
 *
 * Reads the `ltp_connection_mode` option to select the correct driver, reads
 * any driver-specific options, and returns the instantiated client. The
 * resolved instance is cached for the lifetime of the PHP request so that
 * repeated calls from GettextInterceptor and OutputBufferInterceptor share
 * a single object without introducing a global variable.
 *
 * @package OpenToungeTranslations\Connection
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Connection;

use OpenToungeTranslations\Connection\Contracts\TranslationClientInterface;
use OpenToungeTranslations\Connection\Drivers\LocalhostRestClient;
use OpenToungeTranslations\Connection\Drivers\PrivateVpcClient;
use OpenToungeTranslations\Connection\Drivers\UnixSocketClient;
use OpenToungeTranslations\Connection\Exception\ConnectionException;

/**
 * Class ConnectionFactory
 *
 * Single point of truth for driver instantiation. All WordPress option reads
 * are isolated here so that driver classes remain pure value objects whose
 * behaviour depends only on their constructor arguments.
 *
 * Singleton pattern is scoped to the request: the $client property is a
 * regular instance property (not static), so each ConnectionFactory instance
 * maintains its own cache. Plugin::boot() creates exactly one factory per
 * request, which is the intended usage.
 */
final class ConnectionFactory {

	/**
	 * Memoized driver instance for the current request.
	 *
	 * Null until make() resolves it for the first time.
	 */
	private ?TranslationClientInterface $client = null;

	/**
	 * Resolve and return the translation client configured in WordPress options.
	 *
	 * Subsequent calls within the same request return the cached instance
	 * without re-reading options or re-constructing the driver.
	 *
	 * @return TranslationClientInterface The resolved translation driver.
	 *
	 * @throws ConnectionException If the configured mode is not recognised.
	 */
	public function make(): TranslationClientInterface {
		if ( $this->client !== null ) {
			return $this->client;
		}

		$mode = (string) get_option( 'ltp_connection_mode', 'localhost' );

		$this->client = match ( $mode ) {
			'localhost' => new LocalhostRestClient(
				host: (string) get_option( 'ltp_localhost_host', '127.0.0.1' ),
				port: (int) get_option( 'ltp_localhost_port', 5000 ),
				timeout: 3,
			),
			'socket'    => new UnixSocketClient(
				socketPath: (string) get_option( 'ltp_socket_path', '/tmp/libretranslate.sock' ),
				timeout: 3,
			),
			'vpc'       => new PrivateVpcClient(
				ip: (string) get_option( 'ltp_vpc_ip', '' ),
				port: (int) get_option( 'ltp_vpc_port', 5000 ),
				timeout: 5,
				apiKey: (string) get_option( 'ltp_vpc_api_key', '' ),
			),
			default     => throw new ConnectionException(
				sprintf(
					'[OpenTongue] Unknown connection mode: "%s". Expected one of: localhost, socket, vpc.',
					$mode
				)
			),
		};

		return $this->client;
	}
}
