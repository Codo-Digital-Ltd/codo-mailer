<?php
/**
 * Mailgun transport.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends raw MIME through Mailgun's messages.mime endpoint.
 */
final class MailgunTransport extends AbstractHttpTransport {

	/**
	 * MIME builder.
	 *
	 * @var PhpMailerBuilder
	 */
	private $mime;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Keys: domain, api_key, region.
	 * @param HttpClient           $http   HTTP client.
	 * @param PhpMailerBuilder     $mime   MIME builder.
	 */
	public function __construct( array $config, HttpClient $http, PhpMailerBuilder $mime ) {
		parent::__construct( $config, $http );
		$this->mime = $mime;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'mailgun';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required_fields() {
		return array( 'domain', 'api_key' );
	}

	/**
	 * Perform the provider call.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 * @throws TransportException On failure.
	 */
	protected function deliver( Message $message ) {
		$domain = strtolower( (string) $this->config['domain'] );
		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain ) ) {
			throw new TransportException( esc_html( sprintf( 'Invalid Mailgun domain "%s".', $domain ) ) );
		}

		$host     = isset( $this->config['region'] ) && 'us' === $this->config['region'] ? 'api.mailgun.net' : 'api.eu.mailgun.net';
		$url      = 'https://' . $host . '/v3/' . rawurlencode( $domain ) . '/messages.mime';
		$boundary = 'codo-mailer-' . bin2hex( random_bytes( 12 ) );

		$body  = '--' . $boundary . "\r\n";
		$body .= "Content-Disposition: form-data; name=\"to\"\r\n\r\n";
		$body .= implode( ',', $message->all_recipient_emails() ) . "\r\n";
		$body .= '--' . $boundary . "\r\n";
		$body .= "Content-Disposition: form-data; name=\"message\"; filename=\"message.mime\"\r\n";
		$body .= "Content-Type: message/rfc822\r\n\r\n";
		$body .= $this->mime->build_mime( $message ) . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		$response = $this->http->request(
			'POST',
			$url,
			array(
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $this->config['api_key'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			$body
		);
		$result   = $this->decode( $response['body'] );

		if ( 200 !== $response['status'] ) {
			$this->fail_http( $response['status'], $response['body'], isset( $result['message'] ) ? (string) $result['message'] : '' );
		}

		return SendResult::success( isset( $result['id'] ) ? trim( (string) $result['id'], '<>' ) : '' );
	}
}
