<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Transport\BrevoTransport;

class BrevoTransportTest extends HttpTransportTestCase {

	private function transport( $http, array $config = array( 'api_key' => 'xkeysib-1' ) ) {
		return new BrevoTransport( $config, $http );
	}

	public function test_name() {
		$this->assertSame( 'brevo', $this->transport( $this->unused_http() )->name() );
	}

	public function test_full_message_payload() {
		$result = $this->transport( $this->http( 201, '{"messageId":"<abc@smtp-relay.brevo.com>"}' ) )->send( $this->full_message() );

		$this->assertTrue( $result->is_success(), $result->error() );
		$this->assertSame( 'abc@smtp-relay.brevo.com', $result->message_id() );
		$this->assertSame( BrevoTransport::ENDPOINT, $this->request['url'] );
		$this->assertSame( 'xkeysib-1', $this->request['headers']['api-key'] );

		$body = $this->json_body();
		$this->assertSame( array( 'email' => 'site@example.com', 'name' => 'Example Site' ), $body['sender'] );
		$this->assertSame( array( array( 'email' => 'jane@example.org', 'name' => 'Jane Doe' ) ), $body['to'] );
		$this->assertSame( array( array( 'email' => 'cc@example.org' ) ), $body['cc'] );
		$this->assertSame( array( array( 'email' => 'bcc@example.org', 'name' => 'Hidden' ) ), $body['bcc'] );
		$this->assertSame( array( 'email' => 'help@example.org', 'name' => 'Help' ), $body['replyTo'], 'only the first Reply-To' );
		$this->assertSame( '<p>Hello</p>', $body['htmlContent'] );
		$this->assertSame( array( 'X-Order' => '42' ), $body['headers'] );
		$this->assertSame( array( 'name' => 'invoice.pdf', 'content' => base64_encode( 'PDFDATA' ) ), $body['attachment'][0] );
	}

	public function test_minimal_text_message() {
		$result = $this->transport( $this->http( 200, '{}' ) )->send( $this->message( array( 'from_name' => '' ) ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '', $result->message_id() );
		$body = $this->json_body();
		$this->assertSame( array( 'email' => 'site@example.com' ), $body['sender'] );
		$this->assertSame( 'Plain body', $body['textContent'] );
		foreach ( array( 'cc', 'bcc', 'replyTo', 'headers', 'attachment', 'htmlContent' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $body );
		}
	}

	public function test_api_error() {
		$result = $this->transport( $this->http( 401, '{"code":"unauthorized","message":"Key not found"}' ) )->send( $this->message() );
		$this->assertSame( 'Brevo API returned HTTP 401: Key not found', $result->error() );
	}

	public function test_error_without_json_uses_the_raw_body() {
		$result = $this->transport( $this->http( 502, 'Bad Gateway' ) )->send( $this->message() );
		$this->assertSame( 'Brevo API returned HTTP 502: Bad Gateway', $result->error() );
	}

	public function test_missing_key() {
		$result = $this->transport( $this->unused_http(), array() )->send( $this->message() );
		$this->assertSame( 'brevo connection is missing "api_key".', $result->error() );
	}

	public function test_unreadable_attachment() {
		$result = $this->transport( $this->unused_http() )->send( $this->message_with_missing_attachment() );
		$this->assertFalse( $result->is_success() );
	}
}
