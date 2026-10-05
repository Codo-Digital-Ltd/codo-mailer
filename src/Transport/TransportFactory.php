<?php
/**
 * Builds transports from connection settings.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a connection type to its transport.
 */
class TransportFactory {

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * PHPMailer builder.
	 *
	 * @var PhpMailerBuilder
	 */
	private $builder;

	/**
	 * Constructor.
	 *
	 * @param HttpClient|null       $http    HTTP client.
	 * @param PhpMailerBuilder|null $builder PHPMailer builder.
	 */
	public function __construct( $http = null, $builder = null ) {
		$this->http    = $http instanceof HttpClient ? $http : new HttpClient();
		$this->builder = $builder instanceof PhpMailerBuilder ? $builder : new PhpMailerBuilder();
	}

	/**
	 * Create the transport for a connection.
	 *
	 * @param array<string, mixed> $config Connection settings including 'type'.
	 * @return TransportInterface|null Null when the connection is unset.
	 */
	public function create( array $config ) {
		$type = isset( $config['type'] ) ? (string) $config['type'] : 'none';

		switch ( $type ) {
			case 'smtp':
				return new SmtpTransport( $config, $this->builder );
			case 'ses':
				return new SesTransport( $config, $this->http, $this->builder, new AwsSigV4() );
			case 'postmark':
				return new PostmarkTransport( $config, $this->http );
			case 'mailgun':
				return new MailgunTransport( $config, $this->http, $this->builder );
			case 'brevo':
				return new BrevoTransport( $config, $this->http );
			case 'sendgrid':
				return new SendGridTransport( $config, $this->http );
			default:
				/**
				 * Lets extensions provide transports for other connection types.
				 *
				 * @param TransportInterface|null $transport Transport, or null.
				 * @param array<string, mixed>    $config    Connection settings.
				 */
				$custom = apply_filters( 'codo_mailer_transport', null, $config );
				return $custom instanceof TransportInterface ? $custom : null;
		}
	}
}
