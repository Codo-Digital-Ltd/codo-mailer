<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Transport\PostmarkTransport;

class PostmarkTransportTest extends HttpTransportTestCase {

	private function transport( $http, array $config = array( 'server_token' => 'pm-token', 'message_stream' => 'outbound' ) ) {
		return new PostmarkTransport( $config, $http );
	}

	public function test_full_message_payload() {
		$result = $this->transport( $this->http( 200, '{"ErrorCode":0,"MessageID":"pm-1"}' ) )->send( $this->full_message() );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertSame( 'pm-1', $result->message_id() );
		$this->assertSame( 'POST', $this->request['method'] );
		$this->assertSame( PostmarkTransport::ENDPOINT, $this->request['url'] );
		$this->assertSame( 'pm-token', $this->request['headers']['X-Postmark-Server-Token'] );

		$body = $this->json_body();
		$this->assertSame( '"Example Site" <site@example.com>', $body['From'] );
		$this->assertSame( '"Jane Doe" <jane@example.org>', $body['To'] );
		$this->assertSame( 'cc@example.org', $body['Cc'] );
		$this->assertSame( '"Hidden" <bcc@example.org>', $body['Bcc'] );
		$this->assertSame( '"Help" <help@example.org>,second@example.org', $body['ReplyTo'] );
		$this->assertSame( '<p>Hello</p>', $body['HtmlBody'] );
		$this->assertArrayNotHasKey( 'TextBody', $body );
		$this->assertSame( 'outbound', $body['MessageStream'] );
		$this->assertSame( array( array( 'Name' => 'X-Order', 'Value' => '42' ) ), $body['Headers'], 'reserved headers dropped' );
		$this->assertSame( 'invoice.pdf', $body['Attachments'][0]['Name'] );
		$this->assertSame( base64_encode( 'PDFDATA' ), $body['Attachments'][0]['Content'] );
		$this->assertSame( 'application/pdf', $body['Attachments'][0]['ContentType'] );
		$this->assertSame( 'application/octet-stream', $body['Attachments'][1]['ContentType'] );
	}

	public function test_minimal_text_message_omits_optional_fields() {
		$result = $this->transport( $this->http( 200, '{"ErrorCode":0}' ), array( 'server_token' => 't' ) )->send( $this->message() );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $result->message_id() );
		$body = $this->json_body();
		$this->assertSame( 'Plain body', $body['TextBody'] );
		foreach ( array( 'Cc', 'Bcc', 'ReplyTo', 'Headers', 'Attachments', 'MessageStream', 'HtmlBody' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $body );
		}
	}

	public function test_api_error_message_is_reported() {
		$result = $this->transport( $this->http( 422, '{"ErrorCode":300,"Message":"Invalid \'From\' address"}' ) )->send( $this->message() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( "Postmark API returned HTTP 422: Invalid 'From' address", $result->error() );
	}

	public function test_error_code_in_a_200_response_is_a_failure() {
		$result = $this->transport( $this->http( 200, '{"ErrorCode":406,"Message":"Inactive recipient"}' ) )->send( $this->message() );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Inactive recipient', $result->error() );
	}

	public function test_missing_token_fails_without_calling_the_api() {
		$result = $this->transport( $this->unused_http(), array( 'server_token' => '' ) )->send( $this->message() );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'postmark connection is missing "server_token".', $result->error() );
	}

	public function test_network_error_is_reported() {
		$result = $this->transport( $this->broken_http() )->send( $this->message() );
		$this->assertSame( 'HTTP request failed: timed out', $result->error() );
	}

	public function test_unreadable_attachment_fails_before_calling_the_api() {
		$result = $this->transport( $this->unused_http() )->send( $this->message_with_missing_attachment() );
		$this->assertSame( 'Attachment "missing.pdf" could not be read.', $result->error() );
	}

	public function test_invalid_utf8_fails_cleanly() {
		$result = $this->transport( $this->unused_http() )->send( $this->message( array( 'subject' => "\xB1\x31" ) ) );
		$this->assertSame( 'Could not encode the request as JSON.', $result->error() );
	}

	public function test_long_html_error_bodies_are_stripped_and_truncated() {
		$result = $this->transport( $this->http( 500, '<html><body>' . str_repeat( 'x', 400 ) . '</body></html>' ) )->send( $this->message() );

		$this->assertStringStartsWith( 'Postmark API returned HTTP 500: xxx', $result->error() );
		$this->assertStringNotContainsString( '<html>', $result->error() );
		$this->assertStringEndsWith( '…', $result->error() );
	}

	public function test_empty_error_body_says_so() {
		$result = $this->transport( $this->http( 503, '' ) )->send( $this->message() );
		$this->assertSame( 'Postmark API returned HTTP 503: no details', $result->error() );
	}
}
