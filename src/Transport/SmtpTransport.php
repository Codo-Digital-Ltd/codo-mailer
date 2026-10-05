<?php
/**
 * SMTP transport.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through any SMTP server using WordPress's bundled PHPMailer.
 */
final class SmtpTransport implements TransportInterface {

	/**
	 * Connection settings.
	 *
	 * @var array<string, mixed>
	 */
	private $config;

	/**
	 * PHPMailer builder.
	 *
	 * @var PhpMailerBuilder
	 */
	private $builder;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config  Keys: host, port, encryption, auth, username, password.
	 * @param PhpMailerBuilder     $builder PHPMailer builder.
	 */
	public function __construct( array $config, PhpMailerBuilder $builder ) {
		$this->config  = $config;
		$this->builder = $builder;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'smtp';
	}

	/**
	 * Send the message.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 */
	public function send( Message $message ) {
		if ( empty( $this->config['host'] ) ) {
			return SendResult::failure( 'SMTP host is not configured.' );
		}

		try {
			$mailer = $this->builder->build( $message );

			$mailer->isSMTP();
			$mailer->Host        = (string) $this->config['host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			$mailer->Port        = (int) $this->config['port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Timeout     = 15; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$encryption          = isset( $this->config['encryption'] ) ? (string) $this->config['encryption'] : 'tls';
			$mailer->SMTPSecure  = 'none' === $encryption ? '' : $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->SMTPAutoTLS = 'none' !== $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->SMTPAuth    = ! empty( $this->config['auth'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( $mailer->SMTPAuth ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mailer->Username = (string) $this->config['username']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mailer->Password = (string) $this->config['password']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}

			/**
			 * Fires the core hook so plugins that customise PHPMailer (DKIM
			 * signing, embedded images) keep working over SMTP.
			 *
			 * This action is documented in wp-includes/pluggable.php.
			 */
			do_action_ref_array( 'phpmailer_init', array( &$mailer ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

			$mailer->send();

			return SendResult::success( (string) $mailer->getLastMessageID() );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			return SendResult::failure( 'SMTP error: ' . $e->getMessage() );
		}
	}
}
