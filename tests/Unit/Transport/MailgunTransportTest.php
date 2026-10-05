<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Transport\MailgunTransport;
use CodoDigital\Mailer\Transport\PhpMailerBuilder;

class MailgunTransportTest extends HttpTransportTestCase {

	private function transport( $http, array $config = array() ) {
		return new MailgunTransport(
			array_merge(
				array(
					'domain'  => 'mg.example.com',
					'api_key' => 'key-123',
					'region'  => 'eu',
				),
				$config
			),
			$http,
			new PhpMailerBuilder()
		);
	}

	public function test_name() {
		$this->assertSame( 'mailgun', $this->transport( $this->unused_http() )->name() );
	}

	public function test_sends_mime_to_the_eu_endpoint_with_all_recipients() {
		$message = $this->message( array( 'bcc' => array( array( 'email' => 'hidden@example.org', 'name' => '' ) ) ) );
		$result  = $this->transport( $this->http( 200, '{"id":"<20261005.1@mg.example.com>","message":"Queued. Thank you."}' ) )->send( $message );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertSame( '20261005.1@mg.example.com', $result->message_id() );
		$this->assertSame( 'https://api.eu.mailgun.net/v3/mg.example.com/messages.mime', $this->request['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'api:key-123' ), $this->request['headers']['Authorization'] );
		$this->assertMatchesRegularExpression( '/^multipart\/form-data; boundary=codo-mailer-[0-9a-f]{24}$/', $this->request['headers']['Content-Type'] );

		$body = $this->request['body'];
		$this->assertStringContainsString( "name=\"to\"\r\n\r\njane@example.org,hidden@example.org\r\n", $body );
		$this->assertStringContainsString( 'filename="message.mime"', $body );
		$this->assertStringContainsString( 'Subject: Hello', $body );
		$this->assertStringNotContainsString( 'Bcc:', $body, 'Bcc is an envelope recipient only' );
	}

	public function test_us_region_uses_the_us_endpoint() {
		$this->transport( $this->http( 200, '{}' ), array( 'region' => 'us' ) )->send( $this->message() );
		$this->assertStringStartsWith( 'https://api.mailgun.net/v3/', $this->request['url'] );
	}

	public function test_missing_id_in_response() {
		$this->assertSame( '', $this->transport( $this->http( 200, '{}' ) )->send( $this->message() )->message_id() );
	}

	public function test_invalid_domain_is_rejected_before_any_request() {
		$result = $this->transport( $this->unused_http(), array( 'domain' => 'mg.example.com/../evil' ) )->send( $this->message() );
		$this->assertSame( 'Invalid Mailgun domain "mg.example.com/../evil".', $result->error() );
	}

	public function test_api_error() {
		$result = $this->transport( $this->http( 401, '{"message":"Invalid private key"}' ) )->send( $this->message() );
		$this->assertSame( 'Mailgun API returned HTTP 401: Invalid private key', $result->error() );
	}

	public function test_missing_fields() {
		$this->assertSame( 'mailgun connection is missing "domain".', $this->transport( $this->unused_http(), array( 'domain' => '' ) )->send( $this->message() )->error() );
		$this->assertSame( 'mailgun connection is missing "api_key".', $this->transport( $this->unused_http(), array( 'api_key' => '' ) )->send( $this->message() )->error() );
	}

	public function test_unbuildable_mime_fails_cleanly() {
		$result = $this->transport( $this->unused_http() )->send( $this->message_with_missing_attachment() );
		$this->assertStringStartsWith( 'Could not build MIME message', $result->error() );
	}
}
