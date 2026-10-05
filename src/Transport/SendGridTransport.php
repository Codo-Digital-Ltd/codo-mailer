<?php
/**
 * SendGrid transport.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through the SendGrid v3 mail/send API.
 */
final class SendGridTransport extends AbstractHttpTransport {

	const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'sendgrid';
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
		$this->require_to( $message );

		// SendGrid rejects an address that appears in more than one list.
		$seen            = array();
		$personalization = array();
		foreach ( array(
			'to'  => $message->to(),
			'cc'  => $message->cc(),
			'bcc' => $message->bcc(),
		) as $key => $addresses ) {
			$unique = array();
			foreach ( $addresses as $address ) {
				$lower = strtolower( $address['email'] );
				if ( ! isset( $seen[ $lower ] ) ) {
					$seen[ $lower ] = true;
					$unique[]       = $address;
				}
			}
			if ( $unique ) {
				$personalization[ $key ] = $this->address_objects( $unique );
			}
		}

		$from = array( 'email' => $message->from_email() );
		if ( '' !== $message->from_name() ) {
			$from['name'] = $message->from_name();
		}

		$data = array(
			'personalizations' => array( $personalization ),
			'from'             => $from,
			'subject'          => $message->subject(),
			'content'          => array(
				array(
					'type'  => $message->is_html() ? 'text/html' : 'text/plain',
					'value' => '' !== $message->body() ? $message->body() : ' ',
				),
			),
		);

		if ( $message->reply_to() ) {
			$data['reply_to_list'] = $this->address_objects( $message->reply_to() );
		}

		$headers = $this->api_headers( $message );
		if ( $headers ) {
			$data['headers'] = $headers;
		}

		$attachments = array();
		foreach ( $this->encoded_attachments( $message ) as $attachment ) {
			$attachments[] = array(
				'content'  => $attachment['content'],
				'filename' => $attachment['name'],
				'type'     => $attachment['type'],
			);
		}
		if ( $attachments ) {
			$data['attachments'] = $attachments;
		}

		$response = $this->http->request(
			'POST',
			self::ENDPOINT,
			array(
				'Authorization' => 'Bearer ' . $this->config['api_key'],
				'Content-Type'  => 'application/json',
			),
			$this->json( $data )
		);

		if ( 202 !== $response['status'] && 200 !== $response['status'] ) {
			$result = $this->decode( $response['body'] );
			$detail = isset( $result['errors'][0]['message'] ) ? (string) $result['errors'][0]['message'] : '';
			$this->fail_http( $response['status'], $response['body'], $detail );
		}

		return SendResult::success();
	}
}
