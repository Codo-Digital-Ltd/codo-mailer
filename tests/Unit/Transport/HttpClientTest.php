<?php
namespace CodoDigital\Mailer\Tests\Unit\Transport;

use Brain\Monkey\Functions;
use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\HttpClient;
use CodoDigital\Mailer\Transport\TransportException;

class HttpClientTest extends TestCase {

	public function test_successful_request_returns_status_and_body() {
		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://api.example.com/send',
				\Mockery::on(
					static function ( $args ) {
						return 'POST' === $args['method']
							&& array( 'A' => 'b' ) === $args['headers']
							&& '{}' === $args['body']
							&& 20 === $args['timeout']
							&& 0 === $args['redirection']
							&& true === $args['sslverify']
							&& 0 === strpos( $args['user-agent'], 'CodoMailer/1.0.0-test; ' );
					}
				)
			)
			->andReturn( array( 'response' => array( 'code' => 202 ), 'body' => 'ok' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 202 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'ok' );

		$response = ( new HttpClient() )->request( 'POST', 'https://api.example.com/send', array( 'A' => 'b' ), '{}', 20 );

		$this->assertSame( array( 'status' => 202, 'body' => 'ok' ), $response );
	}

	public function test_safe_requests_use_wp_safe_remote_request() {
		Functions\expect( 'wp_safe_remote_request' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_request' )->never();
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->assertSame( 200, ( new HttpClient() )->request( 'POST', 'https://hooks.example.com', array(), '', 10, true )['status'] );
	}

	public function test_network_error_throws() {
		Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$this->expectException( TransportException::class );
		$this->expectExceptionMessage( 'HTTP request failed: cURL error 28: timed out' );

		( new HttpClient() )->request( 'GET', 'https://api.example.com', array(), '' );
	}
}
