<?php
/**
 * Routes wp_mail() through the configured connections.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Mail;

use CodoDigital\Mailer\Alerts\AlertNotifier;
use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Transport\TransportFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Takes over delivery via the core `pre_wp_mail` filter, tries the primary
 * connection then the backup, logs the outcome and raises alerts.
 */
class Dispatcher {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Transport factory.
	 *
	 * @var TransportFactory
	 */
	private $transports;

	/**
	 * Log storage.
	 *
	 * @var LogRepository
	 */
	private $log;

	/**
	 * Alerts.
	 *
	 * @var AlertNotifier
	 */
	private $alerts;

	/**
	 * Secret redaction for logs.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings   Settings.
	 * @param TransportFactory $transports Transport factory.
	 * @param LogRepository    $log        Log storage.
	 * @param AlertNotifier    $alerts     Alerts.
	 * @param Redactor         $redactor   Redactor.
	 */
	public function __construct( Settings $settings, TransportFactory $transports, LogRepository $log, AlertNotifier $alerts, Redactor $redactor ) {
		$this->settings   = $settings;
		$this->transports = $transports;
		$this->log        = $log;
		$this->alerts     = $alerts;
		$this->redactor   = $redactor;
	}

	/**
	 * Hook into wp_mail().
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'pre_wp_mail', array( $this, 'filter_pre_wp_mail' ), 10, 2 );
	}

	/**
	 * Short-circuit wp_mail() when a connection is configured.
	 *
	 * @param null|bool            $short_circuit Value from earlier filters.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 * @return null|bool Null lets core send (no connection configured).
	 */
	public function filter_pre_wp_mail( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		$primary = $this->settings->connection( 'primary' );
		if ( 'none' === $primary['type'] ) {
			return null;
		}

		$atts = is_array( $atts ) ? $atts : array();

		try {
			$message = $this->message_factory()->from_wp_mail( $atts );
		} catch ( InvalidMessageException $e ) {
			$this->fail( null, $atts, wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), '', 'wp_mail' );
			return false;
		}

		return $this->send( $message, 'wp_mail', $atts );
	}

	/**
	 * Send a message: primary, then backup on failure.
	 *
	 * @param Message                   $message Message.
	 * @param string                    $source  wp_mail|test|resend.
	 * @param array<string, mixed>|null $atts    Original wp_mail() arguments, for hooks.
	 * @return bool Whether any connection accepted the message.
	 */
	public function send( Message $message, $source = 'wp_mail', $atts = null ) {
		$errors = array();

		foreach ( Settings::SLOTS as $slot ) {
			$config    = $this->settings->connection( $slot );
			$transport = $this->transports->create( $config );

			if ( null === $transport ) {
				if ( 'primary' === $slot ) {
					$errors[] = 'No primary connection is configured.';
				}
				continue;
			}

			$result = $transport->send( $message );
			$label  = $slot . ':' . $transport->name();

			if ( $result->is_success() ) {
				$this->record( $message, LogRepository::STATUS_SENT, $label, $source, implode( ' ', $errors ) );

				$mail_data = $this->mail_data( $message, $atts );
				/** This action is documented in wp-includes/pluggable.php */
				do_action( 'wp_mail_succeeded', $mail_data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

				/**
				 * Fires after Codo Mailer delivers a message.
				 *
				 * @param Message    $message    Message.
				 * @param string     $connection Connection used, e.g. primary:ses.
				 * @param SendResult $result     Provider result.
				 */
				do_action( 'codo_mailer_sent', $message, $label, $result );
				return true;
			}

			$errors[] = sprintf( '%s %s: %s', ucfirst( $slot ), $transport->name(), $result->error() );
		}

		$this->fail( $message, $atts, implode( ' ', $errors ), 'none', $source );
		return false;
	}

	/**
	 * Resend a log entry.
	 *
	 * @param array<string, mixed> $entry Hydrated log entry.
	 * @return true|string True on success, or an error message.
	 */
	public function resend( array $entry ) {
		if ( ! empty( $entry['redacted'] ) ) {
			return __( 'This email contained a password-reset or one-time link that was redacted, so it cannot be resent. Ask the user to request a new one.', 'codo-mailer' );
		}

		$headers     = is_array( $entry['headers'] ) ? $entry['headers'] : array();
		$attachments = array();
		foreach ( (array) $entry['attachments'] as $attachment ) {
			if ( is_array( $attachment ) && isset( $attachment['path'], $attachment['name'] ) && is_readable( $attachment['path'] ) ) {
				$attachments[] = array(
					'path' => (string) $attachment['path'],
					'name' => (string) $attachment['name'],
				);
			}
		}

		$parts                = array_intersect_key( $headers, Message::defaults() );
		$parts['to']          = (array) $entry['to_addresses'];
		$parts['subject']     = (string) $entry['subject'];
		$parts['body']        = (string) $entry['body'];
		$parts['attachments'] = $attachments;
		$message              = new Message( $parts );

		if ( empty( $message->all_recipient_emails() ) || '' === $message->from_email() ) {
			return __( 'This log entry is incomplete and cannot be resent.', 'codo-mailer' );
		}

		return $this->send( $message, 'resend' ) ? true : __( 'Resending failed. The new attempt has been logged.', 'codo-mailer' );
	}

	/**
	 * Build the message factory from current settings.
	 *
	 * @return MessageFactory
	 */
	public function message_factory() {
		return new MessageFactory(
			(string) $this->settings->get( 'from_email' ),
			(string) $this->settings->get( 'from_name' ),
			(bool) $this->settings->get( 'force_from' )
		);
	}

	/**
	 * Handle a failure: log, fire core's hook, alert.
	 *
	 * @param Message|null              $message Message, if one was built.
	 * @param array<string, mixed>|null $atts    Original arguments.
	 * @param string                    $error   Error.
	 * @param string                    $label   Connection label.
	 * @param string                    $source  Source.
	 * @return void
	 */
	private function fail( $message, $atts, $error, $label, $source ) {
		if ( $message instanceof Message ) {
			$this->record( $message, LogRepository::STATUS_FAILED, $label, $source, $error );
		} elseif ( $this->settings->get( 'log_enabled' ) ) {
			$this->log->insert(
				array(
					'status'     => LogRepository::STATUS_FAILED,
					'connection' => $label,
					'source'     => $source,
					'to'         => array(),
					'subject'    => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
					'body'       => '',
					'error'      => $error,
					'redacted'   => true,
				)
			);
		}

		$mail_data = $message instanceof Message ? $this->mail_data( $message, $atts ) : (array) $atts;

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', $error, $mail_data ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		if ( ! AlertNotifier::is_sending() ) {
			$this->alerts->notify( $message, $error );
		}
	}

	/**
	 * Write a log entry, redacting secrets if enabled.
	 *
	 * @param Message $message Message.
	 * @param string  $status  Status.
	 * @param string  $label   Connection label.
	 * @param string  $source  Source.
	 * @param string  $error   Error text.
	 * @return void
	 */
	private function record( Message $message, $status, $label, $source, $error ) {
		if ( ! $this->settings->get( 'log_enabled' ) ) {
			return;
		}

		$body     = $message->body();
		$redacted = false;
		if ( $this->settings->get( 'redact_sensitive' ) ) {
			$clean    = $this->redactor->redact( $body );
			$body     = $clean['body'];
			$redacted = $clean['redacted'];
		}

		$this->log->insert(
			array(
				'status'      => $status,
				'connection'  => $label,
				'source'      => $source,
				'to'          => $message->to(),
				'subject'     => $message->subject(),
				'body'        => $body,
				'headers'     => array(
					'from_email'   => $message->from_email(),
					'from_name'    => $message->from_name(),
					'cc'           => $message->cc(),
					'bcc'          => $message->bcc(),
					'reply_to'     => $message->reply_to(),
					'content_type' => $message->content_type(),
					'charset'      => $message->charset(),
					'headers'      => $message->headers(),
				),
				'attachments' => $message->attachments(),
				'error'       => $error,
				'redacted'    => $redacted,
			)
		);
	}

	/**
	 * The $mail_data array core passes to wp_mail_succeeded / wp_mail_failed.
	 *
	 * @param Message                   $message Message.
	 * @param array<string, mixed>|null $atts    Original arguments.
	 * @return array<string, mixed>
	 */
	private function mail_data( Message $message, $atts ) {
		return array(
			'to'          => array_column( $message->to(), 'email' ),
			'subject'     => $message->subject(),
			'message'     => $message->body(),
			'headers'     => is_array( $atts ) && isset( $atts['headers'] ) ? $atts['headers'] : array(),
			'attachments' => array_column( $message->attachments(), 'path' ),
		);
	}
}
