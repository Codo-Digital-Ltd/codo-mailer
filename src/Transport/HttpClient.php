<?php
/**
 * Thin wrapper over the WordPress HTTP API.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Performs HTTP requests via wp_remote_request().
 */
class HttpClient {

	/**
	 * Send a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     URL (https).
	 * @param array<string, string> $headers Request headers.
	 * @param string                $body    Request body.
	 * @param int                   $timeout Timeout in seconds.
	 * @return array{status: int, body: string}
	 * @throws TransportException On a network-level error.
	 */
	public function request( $method, $url, array $headers, $body, $timeout = 15 ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'CodoMailer/' . ( defined( 'CODO_MAILER_VERSION' ) ? CODO_MAILER_VERSION : 'dev' ) . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new TransportException( esc_html( 'HTTP request failed: ' . $response->get_error_message() ) );
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
