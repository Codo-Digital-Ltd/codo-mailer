<?php
/**
 * Transport-level failure.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Raised inside transports and converted to a failed SendResult.
 */
class TransportException extends \RuntimeException {
}
