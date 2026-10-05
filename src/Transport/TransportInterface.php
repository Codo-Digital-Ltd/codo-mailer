<?php
/**
 * Delivery transport contract.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a message through one provider. Implementations never throw;
 * every failure is reported as a failed SendResult.
 */
interface TransportInterface {

	/**
	 * Send the message.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 */
	public function send( Message $message );

	/**
	 * Connection type slug, e.g. smtp or ses.
	 *
	 * @return string
	 */
	public function name();
}
