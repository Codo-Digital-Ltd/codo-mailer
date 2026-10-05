<?php
/**
 * Failure alerts by email and webhook.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Alerts;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Transport\HttpClient;
use CodoDigital\Mailer\Transport\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the site owner when email stops working.
 *
 * Alerts are throttled (one per hour by default) and guarded against
 * recursion, because the alert email itself goes through wp_mail().
 */
class AlertNotifier {

	const LOCK_TRANSIENT = 'codo_mailer_alert_lock';

	/**
	 * Whether an alert email is being sent right now.
	 *
	 * @var bool
	 */
	private static $sending = false;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * HTTP client for webhooks.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings.
	 * @param HttpClient $http     HTTP client.
	 */
	public function __construct( Settings $settings, HttpClient $http ) {
		$this->settings = $settings;
		$this->http     = $http;
	}

	/**
	 * Whether an alert is currently being sent (used to break recursion).
	 *
	 * @return bool
	 */
	public static function is_sending() {
		return self::$sending;
	}

	/**
	 * First failure waiting to be alerted at the end of the request.
	 *
	 * @var array{0: Message|null, 1: string}|null
	 */
	private $pending = null;

	/**
	 * Queue an alert for the end of the request (shutdown), so a visitor at
	 * checkout isn't kept waiting while the alert itself is sent.
	 *
	 * Only the first failure in a request is alerted.
	 *
	 * @param Message|null $message Failed message.
	 * @param string       $error   Error description.
	 * @return void
	 */
	public function defer( $message, $error ) {
		if ( null !== $this->pending ) {
			return;
		}
		$this->pending = array( $message, (string) $error );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Send the queued alert (shutdown callback).
	 *
	 * @return string[] Channels notified.
	 */
	public function flush() {
		if ( null === $this->pending ) {
			return array();
		}
		list( $message, $error ) = $this->pending;
		$this->pending           = null;

		// Let the visitor's response finish first (PHP-FPM only).
		// @codeCoverageIgnoreStart
		if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() ) {
			fastcgi_finish_request();
		}
		// @codeCoverageIgnoreEnd
		return $this->notify( $message, $error );
	}

	/**
	 * Notify about a failed message.
	 *
	 * @param Message|null $message Failed message (null if it could not be built).
	 * @param string       $error   Error description.
	 * @return string[] Channels notified: 'email', 'webhook'.
	 */
	public function notify( $message, $error ) {
		$email   = (string) $this->settings->get( 'alert_email' );
		$webhook = (string) $this->settings->get( 'alert_webhook' );

		if ( self::$sending || ( '' === $email && '' === $webhook ) ) {
			return array();
		}

		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return array();
		}

		/**
		 * Filters the minimum seconds between failure alerts.
		 *
		 * @param int $seconds Default one hour.
		 */
		$interval = (int) apply_filters( 'codo_mailer_alert_interval', HOUR_IN_SECONDS );
		set_transient( self::LOCK_TRANSIENT, time(), max( 60, $interval ) );

		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = $message instanceof Message ? $message->subject() : '';
		$text    = sprintf(
			/* translators: 1: site name, 2: email subject, 3: error message. */
			__( 'Codo Mailer on %1$s could not send "%2$s": %3$s', 'codo-mailer' ),
			$site,
			$subject,
			$error
		);

		$sent = array();

		if ( '' !== $email && $this->send_email( $email, $site, $text ) ) {
			$sent[] = 'email';
		}

		if ( '' !== $webhook && $this->send_webhook( $webhook, $site, $subject, $error, $text ) ) {
			$sent[] = 'webhook';
		}

		return $sent;
	}

	/**
	 * Send the alert email.
	 *
	 * @param string $to   Recipient.
	 * @param string $site Site name.
	 * @param string $text Alert text.
	 * @return bool
	 */
	private function send_email( $to, $site, $text ) {
		self::$sending = true;
		try {
			$body = $text . "\n\n" . sprintf(
				/* translators: %s: admin URL of the email log. */
				__( 'See the email log: %s', 'codo-mailer' ),
				admin_url( 'options-general.php?page=codo-mailer&tab=log&status=failed' )
			);
			/* translators: %s: site name. */
			return (bool) wp_mail( $to, sprintf( __( '[%s] Email delivery is failing', 'codo-mailer' ), $site ), $body );
		} finally {
			self::$sending = false;
		}
	}

	/**
	 * POST the alert to a webhook (Slack-compatible "text" field).
	 *
	 * @param string $url     Webhook URL.
	 * @param string $site    Site name.
	 * @param string $subject Failed email subject.
	 * @param string $error   Error.
	 * @param string $text    Alert text.
	 * @return bool
	 */
	private function send_webhook( $url, $site, $subject, $error, $text ) {
		$payload = wp_json_encode(
			array(
				'text'    => $text,
				'site'    => $site,
				'url'     => home_url( '/' ),
				'subject' => $subject,
				'error'   => $error,
				'time'    => gmdate( 'c' ),
			)
		);

		try {
			$response = $this->http->request( 'POST', $url, array( 'Content-Type' => 'application/json' ), (string) $payload, 10, true );
		} catch ( TransportException $e ) {
			return false;
		}

		return $response['status'] >= 200 && $response['status'] < 300;
	}
}
