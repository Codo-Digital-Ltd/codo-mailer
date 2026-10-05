<?php
/**
 * Amazon SES (API v2) transport.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends raw MIME through SES v2 SendEmail, signed with SigV4.
 */
final class SesTransport extends AbstractHttpTransport {

	/**
	 * MIME builder.
	 *
	 * @var PhpMailerBuilder
	 */
	private $mime;

	/**
	 * Request signer.
	 *
	 * @var AwsSigV4
	 */
	private $signer;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Keys: region, access_key, secret_key.
	 * @param HttpClient           $http   HTTP client.
	 * @param PhpMailerBuilder     $mime   MIME builder.
	 * @param AwsSigV4             $signer Signer.
	 * @param callable|null        $clock  fn(): int.
	 */
	public function __construct( array $config, HttpClient $http, PhpMailerBuilder $mime, AwsSigV4 $signer, $clock = null ) {
		parent::__construct( $config, $http );
		$this->mime   = $mime;
		$this->signer = $signer;
		$this->clock  = null !== $clock ? $clock : 'time';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'ses';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function required_fields() {
		return array( 'region', 'access_key', 'secret_key' );
	}

	/**
	 * Perform the provider call.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 * @throws TransportException On failure.
	 */
	protected function deliver( Message $message ) {
		$region = strtolower( (string) $this->config['region'] );
		if ( ! preg_match( '/^[a-z]{2}(-[a-z]+)+-\d+$/', $region ) ) {
			throw new TransportException( esc_html( sprintf( 'Invalid AWS region "%s".', $region ) ) );
		}

		$body = $this->json(
			array(
				'Destination' => array(
					'ToAddresses'  => array_map( array( Message::class, 'format_address' ), $message->to() ),
					'CcAddresses'  => array_map( array( Message::class, 'format_address' ), $message->cc() ),
					'BccAddresses' => array_map( array( Message::class, 'format_address' ), $message->bcc() ),
				),
				'Content'     => array(
					'Raw' => array(
						'Data' => base64_encode( $this->mime->build_mime( $message ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by SES.
					),
				),
			)
		);

		$url     = 'https://email.' . $region . '.amazonaws.com/v2/email/outbound-emails'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the SES sending API, not offloaded assets.
		$headers = $this->signer->sign(
			'POST',
			$url,
			array( 'content-type' => 'application/json' ),
			$body,
			$region,
			'ses',
			(string) $this->config['access_key'],
			(string) $this->config['secret_key'],
			(int) call_user_func( $this->clock )
		);

		$response = $this->http->request( 'POST', $url, $headers, $body );
		$data     = $this->decode( $response['body'] );

		if ( 200 !== $response['status'] ) {
			$detail = isset( $data['message'] ) ? (string) $data['message'] : ( isset( $data['Message'] ) ? (string) $data['Message'] : '' );
			$this->fail_http( $response['status'], $response['body'], $detail );
		}

		return SendResult::success( isset( $data['MessageId'] ) ? (string) $data['MessageId'] : '' );
	}
}
