<?php
/**
 * Outcome of a delivery attempt.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Result of sending a message through one connection.
 */
final class SendResult {

	/**
	 * Whether the provider accepted the message.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Provider message ID, if returned.
	 *
	 * @var string
	 */
	private $message_id;

	/**
	 * Error description on failure.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Constructor.
	 *
	 * @param bool   $success    Whether it succeeded.
	 * @param string $message_id Provider message ID.
	 * @param string $error      Error description.
	 */
	private function __construct( $success, $message_id, $error ) {
		$this->success    = (bool) $success;
		$this->message_id = (string) $message_id;
		$this->error      = (string) $error;
	}

	/**
	 * Successful result.
	 *
	 * @param string $message_id Provider message ID.
	 * @return self
	 */
	public static function success( $message_id = '' ) {
		return new self( true, $message_id, '' );
	}

	/**
	 * Failed result.
	 *
	 * @param string $error Error description.
	 * @return self
	 */
	public static function failure( $error ) {
		return new self( false, '', '' === (string) $error ? 'Unknown error' : $error );
	}

	/**
	 * Whether delivery succeeded.
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * Provider message ID.
	 *
	 * @return string
	 */
	public function message_id() {
		return $this->message_id;
	}

	/**
	 * Error description.
	 *
	 * @return string
	 */
	public function error() {
		return $this->error;
	}
}
