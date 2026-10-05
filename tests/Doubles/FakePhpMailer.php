<?php
namespace CodoDigital\Mailer\Tests\Doubles;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * PHPMailer that records instead of talking to a server.
 */
class FakePhpMailer extends PHPMailer {

	/** @var string|null Error to throw from send(). */
	public $fail_with = null;

	/** @var int */
	public $sends = 0;

	public function send() {
		if ( null !== $this->fail_with ) {
			throw new Exception( $this->fail_with );
		}
		$this->sends++;
		$this->preSend();
		return true;
	}
}
