<?php
/**
 * Brevo transport.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through the Brevo (formerly Sendinblue) transactional email API.
 */
final class BrevoTransport extends AbstractHttpTransport {

	const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'brevo';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required_fields() {
		return array( 'api_key' );
	}

	/**
	 * Perform the provider call.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 * @throws TransportException On failure.
	 */
	protected function deliver( Message $message ) {
		$sender = array( 'email' => $message->from_email() );
		if ( '' !== $message->from_name() ) {
			$sender['name'] = $message->from_name();
		}

		$data = array(
			'sender'  => $sender,
			'to'      => $this->address_objects( $message->to() ),
			'subject' => $message->subject(),
		);

		if ( $message->cc() ) {
			$data['cc'] = $this->address_objects( $message->cc() );
		}
		if ( $message->bcc() ) {
			$data['bcc'] = $this->address_objects( $message->bcc() );
		}
		if ( $message->reply_to() ) {
			// Brevo accepts a single Reply-To.
			$reply           = $this->address_objects( array_slice( $message->reply_to(), 0, 1 ) );
			$data['replyTo'] = $reply[0];
		}

		$data[ $message->is_html() ? 'htmlContent' : 'textContent' ] = $message->body();

		$headers = $this->api_headers( $message );
		if ( $headers ) {
			$data['headers'] = $headers;
		}

		$attachments = array();
		foreach ( $this->encoded_attachments( $message ) as $attachment ) {
			$attachments[] = array(
				'name'    => $attachment['name'],
				'content' => $attachment['content'],
			);
		}
		if ( $attachments ) {
			$data['attachment'] = $attachments;
		}

		$response = $this->http->request(
			'POST',
			self::ENDPOINT,
			array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'api-key'      => (string) $this->config['api_key'],
			),
			$this->json( $data )
		);
		$result   = $this->decode( $response['body'] );

		if ( 201 !== $response['status'] && 200 !== $response['status'] ) {
			$this->fail_http( $response['status'], $response['body'], isset( $result['message'] ) ? (string) $result['message'] : '' );
		}

		return SendResult::success( isset( $result['messageId'] ) ? trim( (string) $result['messageId'], '<>' ) : '' );
	}
}
