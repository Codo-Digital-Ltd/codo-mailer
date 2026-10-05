<?php
/**
 * Postmark transport.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through the Postmark /email API.
 */
final class PostmarkTransport extends AbstractHttpTransport {

	const ENDPOINT = 'https://api.postmarkapp.com/email';

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'postmark';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required_fields() {
		return array( 'server_token' );
	}

	/**
	 * Perform the provider call.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 * @throws TransportException On failure.
	 */
	protected function deliver( Message $message ) {
		$format = array( Message::class, 'format_address' );
		$data   = array(
			'From'    => Message::format_address(
				array(
					'email' => $message->from_email(),
					'name'  => $message->from_name(),
				)
			),
			'To'      => implode( ',', array_map( $format, $message->to() ) ),
			'Subject' => $message->subject(),
		);

		if ( $message->cc() ) {
			$data['Cc'] = implode( ',', array_map( $format, $message->cc() ) );
		}
		if ( $message->bcc() ) {
			$data['Bcc'] = implode( ',', array_map( $format, $message->bcc() ) );
		}
		if ( $message->reply_to() ) {
			$data['ReplyTo'] = implode( ',', array_map( $format, $message->reply_to() ) );
		}

		$data[ $message->is_html() ? 'HtmlBody' : 'TextBody' ] = $message->body();

		if ( ! empty( $this->config['message_stream'] ) ) {
			$data['MessageStream'] = (string) $this->config['message_stream'];
		}

		$headers = array();
		foreach ( $this->api_headers( $message ) as $name => $value ) {
			$headers[] = array(
				'Name'  => $name,
				'Value' => $value,
			);
		}
		if ( $headers ) {
			$data['Headers'] = $headers;
		}

		$attachments = array();
		foreach ( $this->encoded_attachments( $message ) as $attachment ) {
			$attachments[] = array(
				'Name'        => $attachment['name'],
				'Content'     => $attachment['content'],
				'ContentType' => $attachment['type'],
			);
		}
		if ( $attachments ) {
			$data['Attachments'] = $attachments;
		}

		$response = $this->http->request(
			'POST',
			self::ENDPOINT,
			array(
				'Accept'                  => 'application/json',
				'Content-Type'            => 'application/json',
				'X-Postmark-Server-Token' => (string) $this->config['server_token'],
			),
			$this->json( $data )
		);
		$result   = $this->decode( $response['body'] );

		if ( 200 !== $response['status'] || ( isset( $result['ErrorCode'] ) && 0 !== (int) $result['ErrorCode'] ) ) {
			$this->fail_http( $response['status'], $response['body'], isset( $result['Message'] ) ? (string) $result['Message'] : '' );
		}

		return SendResult::success( isset( $result['MessageID'] ) ? (string) $result['MessageID'] : '' );
	}
}
