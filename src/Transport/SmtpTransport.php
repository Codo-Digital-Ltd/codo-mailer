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
	 * Whether a connection would send its SMTP password unencrypted to
	 * another machine: Authenticate on, Encryption none, host not loopback.
	 *
	 * @param array<string, mixed> $config Connection settings.
	 * @return bool
	 */
	public static function is_plaintext_auth( array $config ) {
		if ( empty( $config['auth'] ) || ! isset( $config['encryption'] ) || 'none' !== $config['encryption'] ) {
			return false;
		}
		$host = strtolower( trim( isset( $config['host'] ) ? (string) $config['host'] : '', " \t[]" ) );
		return '' !== $host && 'localhost' !== $host && '::1' !== $host && 0 !== strpos( $host, '127.' );
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

		if ( self::is_plaintext_auth( $this->config ) ) {
			/**
			 * Filters whether SMTP may authenticate over an unencrypted connection.
			 *
			 * With Encryption set to None and Authenticate on, the username and
			 * password cross the network in plain text. Return false to refuse
			 * to send instead, e.g. on a host that manages many sites.
			 *
			 * @param bool   $allow Default true, for internal relays that need it.
			 * @param string $host  SMTP host.
			 */
			if ( ! apply_filters( 'codo_mailer_smtp_allow_plaintext_auth', true, (string) $this->config['host'] ) ) {
				return SendResult::failure( 'SMTP is set to authenticate without encryption, which would send the password in plain text, and this site does not allow that. Set Encryption to TLS or SSL.' );
			}
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
		} catch ( \Throwable $e ) {
			// A phpmailer_init callback threw; fail so the backup still runs.
			return SendResult::failure( 'SMTP error: ' . $e->getMessage() );
		}
	}
}
