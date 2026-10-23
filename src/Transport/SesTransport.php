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
 *
 * With no access key ID configured, the standard AWS environment variables
 * are used instead (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and
 * AWS_SESSION_TOKEN), as set for temporary credentials on many AWS hosts.
 */
final class SesTransport extends AbstractHttpTransport {

	/**
	 * Environment variables for each credential field.
	 */
	const ENV_CREDENTIALS = array(
		'access_key'    => 'AWS_ACCESS_KEY_ID',
		'secret_key'    => 'AWS_SECRET_ACCESS_KEY',
		'session_token' => 'AWS_SESSION_TOKEN',
	);

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
	 * @param array<string, mixed> $config Keys: region, access_key, secret_key, session_token.
	 * @param HttpClient           $http   HTTP client.
	 * @param PhpMailerBuilder     $mime   MIME builder.
	 * @param AwsSigV4             $signer Signer.
	 * @param callable|null        $clock  fn(): int.
	 * @param callable|null        $env    fn( string $name ): string|false, reads an environment variable.
	 */
	public function __construct( array $config, HttpClient $http, PhpMailerBuilder $mime, AwsSigV4 $signer, $clock = null, $env = null ) {
		parent::__construct( self::with_env_credentials( $config, null !== $env ? $env : 'getenv' ), $http );
		$this->mime   = $mime;
		$this->signer = $signer;
		$this->clock  = null !== $clock ? $clock : 'time';
	}

	/**
	 * Take every credential from the AWS environment variables when no access key ID is configured.
	 *
	 * All or nothing: a configured access key is never combined with a secret
	 * or session token from the environment, which would not match it, and a
	 * secret saved without an access key is not used.
	 *
	 * @param array<string, mixed> $config Connection settings.
	 * @param callable             $env    fn( string $name ): string|false.
	 * @return array<string, mixed>
	 */
	private static function with_env_credentials( array $config, $env ) {
		if ( ! empty( $config['access_key'] ) ) {
			return $config;
		}
		foreach ( self::ENV_CREDENTIALS as $field => $variable ) {
			$value            = call_user_func( $env, $variable );
			$config[ $field ] = is_string( $value ) ? trim( $value ) : '';
		}
		return $config;
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
		if ( ! preg_match( '/^[a-z]{2}(-[a-z]+)+-\d+\z/', $region ) ) {
			throw new TransportException( esc_html( sprintf( 'Invalid AWS region "%s".', $region ) ) );
		}

		$raw  = $this->mime->build_mime( $message );
		$body = $this->json(
			array(
				// The envelope: every recipient as a bare address (display names
				// live in the MIME headers, RFC 2047-encoded; SES rejects raw
				// non-ASCII here). Which header each one appears in comes from
				// the MIME, so they can all be listed as ToAddresses.
				'Destination' => array(
					'ToAddresses' => $raw['recipients'],
				),
				'Content'     => array(
					'Raw' => array(
						'Data' => base64_encode( $raw['mime'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by SES.
					),
				),
			)
		);

		$url     = 'https://email.' . $region . '.api.aws/v2/email/outbound-emails'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the dual-stack (IPv4 and IPv6) SES sending API, not offloaded assets.
		$headers = $this->signer->sign(
			'POST',
			$url,
			array( 'content-type' => 'application/json' ),
			$body,
			$region,
			'ses',
			(string) $this->config['access_key'],
			(string) $this->config['secret_key'],
			(int) call_user_func( $this->clock ),
			isset( $this->config['session_token'] ) ? (string) $this->config['session_token'] : ''
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
