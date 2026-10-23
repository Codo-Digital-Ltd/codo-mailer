<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Transport\AwsSigV4;
use CodoDigital\Mailer\Transport\PhpMailerBuilder;
use CodoDigital\Mailer\Transport\SesTransport;

class SesTransportTest extends HttpTransportTestCase {

	private function transport( $http, array $config = array(), $clock = null, array $env = array() ) {
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
			},
			static function ( $name ) use ( $env ) {
				return array_key_exists( $name, $env ) ? $env[ $name ] : false;
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
		$this->assertSame( 'https://email.eu-west-2.api.aws/v2/email/outbound-emails', $this->request['url'] );
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

	public function test_raw_mime_transports_check_attachment_size_too() {
		$result = $this->transport( $this->unused_http() )->send( $this->message_with_attachments_of( array( 30 * MB_IN_BYTES ) ) );
		$this->assertSame( 'Attachments total 30 MB, over the 25 MB limit for Amazon SES connections.', $result->error() );
	}

	public function test_session_token_is_sent_and_signed() {
		$result = $this->transport( $this->http( 200, '{}' ), array( 'session_token' => 'FwoGZXIvYXdzEXAMPLE' ) )->send( $this->message() );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertSame( 'FwoGZXIvYXdzEXAMPLE', $this->request['headers']['x-amz-security-token'] );
		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-date;x-amz-security-token,', $this->request['headers']['Authorization'] );
	}

	public function test_no_token_header_for_long_term_keys() {
		$this->transport( $this->http( 200, '{}' ) )->send( $this->message() );

		$this->assertArrayNotHasKey( 'x-amz-security-token', $this->request['headers'] );
	}

	public function test_credentials_come_from_the_aws_environment_when_no_access_key_is_configured() {
		$env = array(
			'AWS_ACCESS_KEY_ID'     => 'ASIAENV',
			'AWS_SECRET_ACCESS_KEY' => 'env-secret',
			'AWS_SESSION_TOKEN'     => ' env-token ',
		);

		$result = $this->transport( $this->http( 200, '{}' ), array( 'access_key' => '', 'secret_key' => 'saved-without-a-key' ), null, $env )->send( $this->message() );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=ASIAENV/', $this->request['headers']['Authorization'] );
		$this->assertSame( 'env-token', $this->request['headers']['x-amz-security-token'] );
	}

	public function test_environment_without_a_session_token() {
		$env = array(
			'AWS_ACCESS_KEY_ID'     => 'AKIAENV',
			'AWS_SECRET_ACCESS_KEY' => 'env-secret',
		);

		$this->transport( $this->http( 200, '{}' ), array( 'access_key' => '', 'secret_key' => '' ), null, $env )->send( $this->message() );

		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIAENV/', $this->request['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'x-amz-security-token', $this->request['headers'] );
	}

	public function test_a_configured_access_key_is_never_mixed_with_environment_credentials() {
		$env = array(
			'AWS_ACCESS_KEY_ID'     => 'ASIAENV',
			'AWS_SECRET_ACCESS_KEY' => 'env-secret',
			'AWS_SESSION_TOKEN'     => 'env-token',
		);

		$this->transport( $this->http( 200, '{}' ), array(), null, $env )->send( $this->message() );

		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIATEST/', $this->request['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'x-amz-security-token', $this->request['headers'] );

		$result = $this->transport( $this->unused_http(), array( 'secret_key' => '' ), null, $env )->send( $this->message() );
		$this->assertSame( 'ses connection is missing "secret_key".', $result->error() );
	}

	public function test_no_access_key_anywhere() {
		$result = $this->transport( $this->unused_http(), array( 'access_key' => '' ) )->send( $this->message() );
		$this->assertSame( 'ses connection is missing "access_key".', $result->error() );
	}

	public function test_default_environment_reader_is_getenv() {
		$saved = array();
		foreach ( array( 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN' ) as $name ) {
			$saved[ $name ] = getenv( $name );
		}
		putenv( 'AWS_ACCESS_KEY_ID=ASIAGETENV' );
		putenv( 'AWS_SECRET_ACCESS_KEY=getenv-secret' );
		putenv( 'AWS_SESSION_TOKEN=getenv-token' );

		try {
			$transport = new SesTransport( array( 'region' => 'eu-west-2' ), $this->http( 200, '{}' ), new PhpMailerBuilder(), new AwsSigV4() );
			$transport->send( $this->message() );
		} finally {
			foreach ( $saved as $name => $value ) {
				putenv( false === $value ? $name : $name . '=' . $value );
			}
		}

		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=ASIAGETENV/', $this->request['headers']['Authorization'] );
		$this->assertSame( 'getenv-token', $this->request['headers']['x-amz-security-token'] );
	}
}
