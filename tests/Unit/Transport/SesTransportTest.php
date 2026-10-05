<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Transport\AwsSigV4;
use CodoDigital\Mailer\Transport\PhpMailerBuilder;
use CodoDigital\Mailer\Transport\SesTransport;

class SesTransportTest extends HttpTransportTestCase {

	private function transport( $http, array $config = array(), $clock = null ) {
		return new SesTransport(
			array_merge(
				array(
					'region'     => 'eu-west-2',
					'access_key' => 'AKIATEST',
					'secret_key' => 'secret',
				),
				$config
			),
			$http,
			new PhpMailerBuilder(),
			new AwsSigV4(),
			null !== $clock ? $clock : static function () {
				return 1790000000;
			}
		);
	}

	public function test_name() {
		$this->assertSame( 'ses', $this->transport( $this->unused_http() )->name() );
	}

	public function test_signed_raw_request_with_envelope_recipients() {
		$message = $this->message(
			array(
				'cc'  => array( array( 'email' => 'cc@example.org', 'name' => '' ) ),
				'bcc' => array( array( 'email' => 'hidden@example.org', 'name' => '' ) ),
			)
		);

		$result = $this->transport( $this->http( 200, '{"MessageId":"0102-ses-id"}' ) )->send( $message );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertSame( '0102-ses-id', $result->message_id() );
		$this->assertSame( 'https://email.eu-west-2.amazonaws.com/v2/email/outbound-emails', $this->request['url'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIATEST/', $this->request['headers']['Authorization'] );
		$this->assertStringContainsString( '/eu-west-2/ses/aws4_request', $this->request['headers']['Authorization'] );
		$this->assertSame( gmdate( 'Ymd\THis\Z', 1790000000 ), $this->request['headers']['x-amz-date'] );

		$body = $this->json_body();
		$this->assertSame( array( 'jane@example.org', 'cc@example.org', 'hidden@example.org' ), $body['Destination']['ToAddresses'], 'bare envelope addresses; headers are in the MIME' );

		$mime = base64_decode( $body['Content']['Raw']['Data'] );
		$this->assertStringContainsString( 'Subject: Hello', $mime );
		$this->assertStringNotContainsString( 'hidden@example.org', $mime );
	}

	public function test_non_ascii_display_names_are_encoded_in_mime_not_destination() {
		$message = $this->message( array( 'to' => array( array( 'email' => 'jose@example.org', 'name' => 'José Núñez' ) ) ) );
		$this->transport( $this->http( 200, '{}' ) )->send( $message );

		$body = $this->json_body();
		$this->assertSame( array( 'jose@example.org' ), $body['Destination']['ToAddresses'] );
		$mime = base64_decode( $body['Content']['Raw']['Data'] );
		$this->assertStringContainsString( 'To: =?', $mime, 'RFC 2047 encoded-word' );
		$this->assertStringNotContainsString( 'José', explode( "\r\n\r\n", $mime )[0] );
	}

	public function test_default_clock_is_the_current_time() {
		$transport = new SesTransport(
			array( 'region' => 'eu-west-2', 'access_key' => 'A', 'secret_key' => 'S' ),
			$this->http( 200, '{}' ),
			new PhpMailerBuilder(),
			new AwsSigV4()
		);
		$before    = time();
		$transport->send( $this->message() );

		$sent_at = \DateTime::createFromFormat( 'Ymd\THis\Z', $this->request['headers']['x-amz-date'], new \DateTimeZone( 'UTC' ) )->getTimestamp();
		$this->assertGreaterThanOrEqual( $before, $sent_at );
	}

	public function test_invalid_region_is_rejected() {
		$result = $this->transport( $this->unused_http(), array( 'region' => 'evil.com/x' ) )->send( $this->message() );
		$this->assertSame( 'Invalid AWS region "evil.com/x".', $result->error() );
	}

	public function test_api_error_lowercase_message() {
		$result = $this->transport( $this->http( 400, '{"message":"Email address is not verified."}' ) )->send( $this->message() );
		$this->assertSame( 'Amazon SES API returned HTTP 400: Email address is not verified.', $result->error() );
	}

	public function test_api_error_capitalised_message() {
		$result = $this->transport( $this->http( 403, '{"Message":"The security token included in the request is invalid."}' ) )->send( $this->message() );
		$this->assertStringContainsString( 'security token', $result->error() );
	}

	public function test_api_error_without_message_and_missing_message_id() {
		$this->assertSame( 'Amazon SES API returned HTTP 500: {}', $this->transport( $this->http( 500, '{}' ) )->send( $this->message() )->error() );
		$this->assertSame( '', $this->transport( $this->http( 200, '' ) )->send( $this->message() )->message_id() );
	}

	public function test_missing_credentials() {
		$this->assertSame( 'ses connection is missing "secret_key".', $this->transport( $this->unused_http(), array( 'secret_key' => '' ) )->send( $this->message() )->error() );
	}

	public function test_network_failure() {
		$this->assertSame( 'HTTP request failed: timed out', $this->transport( $this->broken_http() )->send( $this->message() )->error() );
	}
}
