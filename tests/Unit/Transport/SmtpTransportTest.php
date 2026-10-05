<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use Brain\Monkey\Actions;
use CodoDigital\Mailer\Tests\Doubles\FakePhpMailer;
use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\PhpMailerBuilder;
use CodoDigital\Mailer\Transport\SmtpTransport;

class SmtpTransportTest extends TestCase {

	/** @var FakePhpMailer */
	private $mailer;

	private function transport( array $config ) {
		$this->mailer = new FakePhpMailer( true );
		return new SmtpTransport(
			$config,
			new PhpMailerBuilder(
				function () {
					return $this->mailer;
				}
			)
		);
	}

	private function config( array $overrides = array() ) {
		return array_merge(
			array(
				'host'       => 'smtp.example.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => 'user',
				'password'   => 'pass',
			),
			$overrides
		);
	}

	public function test_name() {
		$this->assertSame( 'smtp', $this->transport( $this->config() )->name() );
	}

	public function test_sends_with_tls_and_auth() {
		Actions\expectDone( 'phpmailer_init' )->once();

		$result = $this->transport( $this->config() )->send( $this->message() );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertNotSame( '', $result->message_id() );
		$this->assertSame( 1, $this->mailer->sends );
		$this->assertSame( 'smtp', $this->mailer->Mailer );
		$this->assertSame( 'smtp.example.com', $this->mailer->Host );
		$this->assertSame( 587, $this->mailer->Port );
		$this->assertSame( 'tls', $this->mailer->SMTPSecure );
		$this->assertTrue( $this->mailer->SMTPAutoTLS );
		$this->assertTrue( $this->mailer->SMTPAuth );
		$this->assertSame( 'user', $this->mailer->Username );
		$this->assertSame( 'pass', $this->mailer->Password );
	}

	public function test_no_encryption_disables_auto_tls_and_auth_off_skips_credentials() {
		$result = $this->transport( $this->config( array( 'encryption' => 'none', 'auth' => false ) ) )->send( $this->message() );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $this->mailer->SMTPSecure );
		$this->assertFalse( $this->mailer->SMTPAutoTLS );
		$this->assertFalse( $this->mailer->SMTPAuth );
		$this->assertSame( '', $this->mailer->Username );
	}

	public function test_missing_encryption_defaults_to_tls() {
		$config = $this->config();
		unset( $config['encryption'] );
		$this->transport( $config )->send( $this->message() );
		$this->assertSame( 'tls', $this->mailer->SMTPSecure );
	}

	public function test_missing_host_fails_without_connecting() {
		$result = $this->transport( $this->config( array( 'host' => '' ) ) )->send( $this->message() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'SMTP host is not configured.', $result->error() );
		$this->assertSame( 0, $this->mailer->sends );
	}

	public function test_server_error_becomes_a_failed_result() {
		$transport                = $this->transport( $this->config() );
		$this->mailer->fail_with  = 'SMTP Error: Could not authenticate.';

		$result = $transport->send( $this->message() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'SMTP error: SMTP Error: Could not authenticate.', $result->error() );
	}

	public function test_invalid_address_during_build_becomes_a_failed_result() {
		$result = $this->transport( $this->config() )->send( $this->message( array( 'from_email' => 'not-an-address' ) ) );

		$this->assertFalse( $result->is_success() );
		$this->assertStringStartsWith( 'SMTP error:', $result->error() );
	}
}
