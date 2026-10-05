<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\PhpMailerBuilder;
use CodoDigital\Mailer\Transport\TransportException;
use PHPMailer\PHPMailer\PHPMailer;

class PhpMailerBuilderTest extends TestCase {

	public function test_default_factory_creates_an_exception_throwing_phpmailer() {
		$mailer = PhpMailerBuilder::create_default();
		$this->assertInstanceOf( PHPMailer::class, $mailer );
	}

	public function test_build_copies_every_part() {
		$file    = $this->temp_file( 'PDFDATA', '.pdf' );
		$message = $this->message(
			array(
				'cc'          => array( array( 'email' => 'cc@example.org', 'name' => 'Cee' ) ),
				'bcc'         => array( array( 'email' => 'bcc@example.org', 'name' => '' ) ),
				'reply_to'    => array( array( 'email' => 'help@example.org', 'name' => 'Help' ) ),
				'headers'     => array( 'X-Order' => '42' ),
				'attachments' => array( array( 'path' => $file, 'name' => 'invoice.pdf' ) ),
				'charset'     => 'ISO-8859-1',
			)
		);

		$mailer = ( new PhpMailerBuilder() )->build( $message );

		$this->assertSame( 'site@example.com', $mailer->From );
		$this->assertSame( 'Example Site', $mailer->FromName );
		$this->assertSame( array( array( 'jane@example.org', 'Jane Doe' ) ), $mailer->getToAddresses() );
		$this->assertSame( array( array( 'cc@example.org', 'Cee' ) ), $mailer->getCcAddresses() );
		$this->assertSame( array( array( 'bcc@example.org', '' ) ), $mailer->getBccAddresses() );
		$this->assertArrayHasKey( 'help@example.org', $mailer->getReplyToAddresses() );
		$this->assertSame( array( array( 'X-Order', '42' ) ), $mailer->getCustomHeaders() );
		$this->assertSame( 'invoice.pdf', $mailer->getAttachments()[0][2] );
		$this->assertSame( 'ISO-8859-1', $mailer->CharSet );
		$this->assertSame( 'text/plain', $mailer->ContentType );
	}

	public function test_html_message_sets_html_content_type() {
		$mailer = ( new PhpMailerBuilder() )->build( $this->message( array( 'content_type' => 'text/html', 'body' => '<p>Hi</p>' ) ) );
		$this->assertSame( 'text/html', $mailer->ContentType );
	}

	public function test_build_mime_produces_rfc_message_without_bcc_header() {
		$message = $this->message(
			array(
				'bcc'     => array( array( 'email' => 'secret@example.org', 'name' => '' ) ),
				'subject' => 'Order shipped',
			)
		);

		$mime = ( new PhpMailerBuilder() )->build_mime( $message );

		$this->assertStringContainsString( 'To: Jane Doe <jane@example.org>', $mime );
		$this->assertStringContainsString( 'From: Example Site <site@example.com>', $mime );
		$this->assertStringContainsString( 'Subject: Order shipped', $mime );
		$this->assertStringContainsString( 'Plain body', $mime );
		$this->assertStringNotContainsString( 'secret@example.org', $mime );
		$this->assertStringNotContainsString( 'PHPMailer', $mime, 'X-Mailer banner suppressed' );
	}

	public function test_build_mime_wraps_phpmailer_errors() {
		$message = $this->message( array( 'attachments' => array( array( 'path' => '/nonexistent/file.pdf', 'name' => 'x.pdf' ) ) ) );

		$this->expectException( TransportException::class );
		$this->expectExceptionMessage( 'Could not build MIME message' );

		( new PhpMailerBuilder() )->build_mime( $message );
	}

	public function test_custom_factory_is_used() {
		$created = 0;
		$builder = new PhpMailerBuilder(
			static function () use ( &$created ) {
				$created++;
				return new PHPMailer( true );
			}
		);
		$builder->build( $this->message() );
		$this->assertSame( 1, $created );
	}
}
