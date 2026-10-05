<?php
/**
 * Fills a PHPMailer instance from a Message.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use PHPMailer\PHPMailer\PHPMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the SMTP transport (to send) and the raw-MIME API transports
 * (SES, Mailgun) to build a standards-compliant MIME message.
 */
class PhpMailerBuilder {

	/**
	 * Creates PHPMailer instances.
	 *
	 * @var callable
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $factory fn(): PHPMailer. Defaults to core's bundled copy.
	 */
	public function __construct( $factory = null ) {
		$this->factory = null !== $factory ? $factory : array( __CLASS__, 'create_default' );
	}

	/**
	 * Create a PHPMailer using the copy bundled with WordPress.
	 *
	 * @return PHPMailer
	 */
	public static function create_default() {
		if ( ! class_exists( PHPMailer::class ) ) {
			// @codeCoverageIgnoreStart
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			// @codeCoverageIgnoreEnd
		}
		return new PHPMailer( true );
	}

	/**
	 * Create a PHPMailer populated with the message.
	 *
	 * @param Message $message Message.
	 * @return PHPMailer
	 * @throws \PHPMailer\PHPMailer\Exception When an address or attachment is rejected.
	 */
	public function build( Message $message ) {
		$mailer = call_user_func( $this->factory );

		$mailer->CharSet  = $message->charset(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		$mailer->Encoding = '8bit'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->XMailer  = ' '; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- suppress the PHPMailer banner.

		$mailer->setFrom( $message->from_email(), $message->from_name(), false );

		foreach ( $message->to() as $address ) {
			$mailer->addAddress( $address['email'], $address['name'] );
		}
		foreach ( $message->cc() as $address ) {
			$mailer->addCC( $address['email'], $address['name'] );
		}
		foreach ( $message->bcc() as $address ) {
			$mailer->addBCC( $address['email'], $address['name'] );
		}
		foreach ( $message->reply_to() as $address ) {
			$mailer->addReplyTo( $address['email'], $address['name'] );
		}

		$mailer->Subject = $message->subject(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Body    = $message->body(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->isHTML( $message->is_html() );
		if ( ! $message->is_html() ) {
			$mailer->ContentType = $message->content_type(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		foreach ( $message->headers() as $name => $value ) {
			$mailer->addCustomHeader( $name, $value );
		}

		foreach ( $message->attachments() as $attachment ) {
			$mailer->addAttachment( $attachment['path'], $attachment['name'] );
		}

		return $mailer;
	}

	/**
	 * Build the full RFC 5322 message (headers + body) without sending, plus
	 * the envelope recipients.
	 *
	 * Bcc recipients are not included in the headers, as per the standard;
	 * callers must pass `recipients` to the provider as the envelope. They
	 * are read back after phpmailer_init, so addresses a callback adds
	 * (e.g. "Bcc the admin" snippets) are delivered too.
	 *
	 * @param Message $message Message.
	 * @return array{mime: string, recipients: string[]}
	 * @throws TransportException When the message cannot be built.
	 */
	public function build_mime( Message $message ) {
		try {
			$mailer = $this->build( $message );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			throw new TransportException( esc_html( 'Could not build MIME message: ' . $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped; the previous exception is not output.
		}

		try {
			/** This action is documented in wp-includes/pluggable.php (lets DKIM and similar plugins sign the MIME). */
			do_action_ref_array( 'phpmailer_init', array( &$mailer ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		} catch ( \Throwable $e ) {
			throw new TransportException( esc_html( 'A phpmailer_init callback failed: ' . $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped; the previous exception is not output.
		}

		try {
			// SMTP mode keeps Bcc out of the headers (mail/sendmail modes add
			// it), even if a phpmailer_init callback changed the mode.
			// preSend() only builds the message; nothing connects.
			$mailer->isSMTP();
			$mailer->preSend();
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			throw new TransportException( esc_html( 'Could not build MIME message: ' . $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped; the previous exception is not output.
		}

		return array(
			'mime'       => $mailer->getSentMIMEMessage(),
			'recipients' => array_values( array_map( 'strval', array_keys( $mailer->getAllRecipientAddresses() ) ) ),
		);
	}
}
