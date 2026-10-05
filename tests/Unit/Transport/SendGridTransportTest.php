<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Transport\SendGridTransport;

class SendGridTransportTest extends HttpTransportTestCase {

	private function transport( $http, array $config = array( 'api_key' => 'SG.key' ) ) {
		return new SendGridTransport( $config, $http );
	}

	public function test_name() {
		$this->assertSame( 'sendgrid', $this->transport( $this->unused_http() )->name() );
	}

	public function test_full_message_payload() {
		$result = $this->transport( $this->http( 202 ) )->send( $this->full_message() );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertSame( SendGridTransport::ENDPOINT, $this->request['url'] );
		$this->assertSame( 'Bearer SG.key', $this->request['headers']['Authorization'] );

		$body = $this->json_body();
		$this->assertSame( array( array( 'email' => 'jane@example.org', 'name' => 'Jane Doe' ) ), $body['personalizations'][0]['to'] );
		$this->assertSame( array( array( 'email' => 'cc@example.org' ) ), $body['personalizations'][0]['cc'] );
		$this->assertSame( array( array( 'email' => 'bcc@example.org', 'name' => 'Hidden' ) ), $body['personalizations'][0]['bcc'] );
		$this->assertSame( array( 'email' => 'site@example.com', 'name' => 'Example Site' ), $body['from'] );
		$this->assertSame( array( array( 'type' => 'text/html', 'value' => '<p>Hello</p>' ) ), $body['content'] );
		$this->assertCount( 2, $body['reply_to_list'] );
		$this->assertSame( array( 'X-Order' => '42' ), $body['headers'] );
		$this->assertSame( 'invoice.pdf', $body['attachments'][0]['filename'] );
		$this->assertSame( 'application/pdf', $body['attachments'][0]['type'] );
	}

	public function test_duplicate_recipients_across_lists_are_removed() {
		$message = $this->message(
			array(
				'cc'  => array( array( 'email' => 'JANE@example.org', 'name' => '' ) ),
				'bcc' => array(
					array( 'email' => 'jane@example.org', 'name' => '' ),
					array( 'email' => 'x@example.org', 'name' => '' ),
				),
			)
		);

		$this->transport( $this->http( 202 ) )->send( $message );
		$personalization = $this->json_body()['personalizations'][0];

		$this->assertArrayNotHasKey( 'cc', $personalization );
		$this->assertSame( array( array( 'email' => 'x@example.org' ) ), $personalization['bcc'] );
	}

	public function test_empty_body_is_sent_as_a_space_and_text_type() {
		$this->transport( $this->http( 200 ) )->send( $this->message( array( 'body' => '', 'from_name' => '' ) ) );
		$body = $this->json_body();

		$this->assertSame( array( array( 'type' => 'text/plain', 'value' => ' ' ) ), $body['content'] );
		$this->assertSame( array( 'email' => 'site@example.com' ), $body['from'] );
		foreach ( array( 'reply_to_list', 'headers', 'attachments' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $body );
		}
	}

	public function test_api_error_uses_first_error_message() {
		$result = $this->transport( $this->http( 400, '{"errors":[{"message":"The from address does not match a verified Sender Identity."}]}' ) )->send( $this->message() );
		$this->assertSame( 'SendGrid API returned HTTP 400: The from address does not match a verified Sender Identity.', $result->error() );
	}

	public function test_api_error_without_json() {
		$result = $this->transport( $this->http( 500, 'oops' ) )->send( $this->message() );
		$this->assertSame( 'SendGrid API returned HTTP 500: oops', $result->error() );
	}

	public function test_missing_key_and_network_error() {
		$this->assertFalse( $this->transport( $this->unused_http(), array( 'api_key' => '' ) )->send( $this->message() )->is_success() );
		$this->assertSame( 'HTTP request failed: timed out', $this->transport( $this->broken_http() )->send( $this->message() )->error() );
	}

	public function test_unreadable_attachment() {
		$this->assertFalse( $this->transport( $this->unused_http() )->send( $this->message_with_missing_attachment() )->is_success() );
	}
}
