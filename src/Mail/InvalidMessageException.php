<?php
/**
 * Thrown when wp_mail() arguments cannot form a valid message.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Invalid message exception.
 */
class InvalidMessageException extends \InvalidArgumentException {
}
