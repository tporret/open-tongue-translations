<?php
/**
 * Exception thrown when the connection factory cannot resolve a driver.
 *
 * @package OpenToungeTranslations\Connection\Exception
 */

declare(strict_types=1);

namespace OpenToungeTranslations\Connection\Exception;

/**
 * Class ConnectionException
 *
 * Raised by ConnectionFactory when the configured connection mode does not
 * map to a known driver. Callers (e.g. Plugin::boot()) should catch this
 * and disable the plugin gracefully rather than letting it bubble to core.
 */
final class ConnectionException extends \RuntimeException {}
