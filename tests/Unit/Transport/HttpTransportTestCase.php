<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\HttpClient;
use CodoDigital\Mailer\Transport\TransportException;

/**
 * Shared helpers for HTTP API transport tests.
 */
abstract class HttpTransportTestCase extends TestCase {

	/** @var array{method: string, url: string, headers: array, body: string}|null */
	protected $request = null;

	/**
	 * HTTP client that records the request and returns a canned response.
	 *
	 * @param int    $status Status.
	 * @param string $body   Body.
	 * @return HttpClient
	 */
	protected function http( $status, $body = '' ) {
		$http = \Mockery::mock( HttpClient::class );
		$http->shouldReceive( 'request' )->andReturnUsing(
			function ( $method, $url, $headers, $request_body ) use ( $status, $body ) {
				$this->request = array(
					'method'  => $method,
					'url'     => $url,
					'headers' => $headers,
					'body'    => $request_body,
				);
				return array(
					'status' => $status,
					'body'   => $body,
				);
			}
		);
		return $http;
	}

	/**
	 * HTTP client that fails at the network level.
	 *
	 * @return HttpClient
	 */
	protected function broken_http() {
		$http = \Mockery::mock( HttpClient::class );
		$http->shouldReceive( 'request' )->andThrow( new TransportException( 'HTTP request failed: timed out' ) );
		return $http;
	}

	/**
	 * Mock that must never be called.
	 *
	 * @return HttpClient
	 */
	protected function unused_http() {
		$http = \Mockery::mock( HttpClient::class );
		$http->shouldNotReceive( 'request' );
		return $http;
	}

	/**
	 * Decoded JSON request body.
	 *
	 * @return array<string, mixed>
	 */
	protected function json_body() {
		return json_decode( $this->request['body'], true );
	}

	/**
	 * A message with every optional part.
	 *
	 * @param string $content_type Content type.
	 * @return \CodoDigital\Mailer\Mail\Message
	 */
	protected function full_message( $content_type = 'text/html' ) {
		return $this->message(
			array(
				'content_type' => $content_type,
				'body'         => '<p>Hello</p>',
				'cc'           => array( array( 'email' => 'cc@example.org', 'name' => '' ) ),
				'bcc'          => array( array( 'email' => 'bcc@example.org', 'name' => 'Hidden' ) ),
				'reply_to'     => array(
					array( 'email' => 'help@example.org', 'name' => 'Help' ),
					array( 'email' => 'second@example.org', 'name' => '' ),
				),
				'headers'      => array(
					'X-Order'      => '42',
					'MIME-Version' => '1.0',
				),
				'attachments'  => array(
					array( 'path' => $this->temp_file( 'PDFDATA', '.pdf' ), 'name' => 'invoice.pdf' ),
					array( 'path' => $this->temp_file( 'BIN', '.bin' ), 'name' => 'data.bin' ),
				),
			)
		);
	}

	/**
	 * A message whose attachment does not exist.
	 *
	 * @return \CodoDigital\Mailer\Mail\Message
	 */
	protected function message_with_missing_attachment() {
		return $this->message( array( 'attachments' => array( array( 'path' => '/nope/missing.pdf', 'name' => 'missing.pdf' ) ) ) );
	}
}
