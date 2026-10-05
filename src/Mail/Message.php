<?php
/**
 * Immutable outgoing email message.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * A normalised email, independent of how it will be delivered.
 *
 * Addresses are stored as lists of array( 'email' => string, 'name' => string ).
 */
final class Message {

	/**
	 * Message parts.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Message parts; see defaults().
	 */
	public function __construct( array $data ) {
		$this->data = array_merge( self::defaults(), array_intersect_key( $data, self::defaults() ) );
	}

	/**
	 * Default message parts.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'from_email'   => '',
			'from_name'    => '',
			'to'           => array(),
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'subject'      => '',
			'body'         => '',
			'content_type' => 'text/plain',
			'charset'      => 'UTF-8',
			'headers'      => array(),
			'attachments'  => array(),
		);
	}

	/**
	 * Sender email.
	 *
	 * @return string
	 */
	public function from_email() {
		return (string) $this->data['from_email'];
	}

	/**
	 * Sender name.
	 *
	 * @return string
	 */
	public function from_name() {
		return (string) $this->data['from_name'];
	}

	/**
	 * To recipients.
	 *
	 * @return array<int, array{email: string, name: string}>
	 */
	public function to() {
		return $this->data['to'];
	}

	/**
	 * Cc recipients.
	 *
	 * @return array<int, array{email: string, name: string}>
	 */
	public function cc() {
		return $this->data['cc'];
	}

	/**
	 * Bcc recipients.
	 *
	 * @return array<int, array{email: string, name: string}>
	 */
	public function bcc() {
		return $this->data['bcc'];
	}

	/**
	 * Reply-To addresses.
	 *
	 * @return array<int, array{email: string, name: string}>
	 */
	public function reply_to() {
		return $this->data['reply_to'];
	}

	/**
	 * Every envelope recipient (To, Cc and Bcc), de-duplicated.
	 *
	 * @return string[]
	 */
	public function all_recipient_emails() {
		$emails = array();
		foreach ( array_merge( $this->to(), $this->cc(), $this->bcc() ) as $address ) {
			$emails[ strtolower( $address['email'] ) ] = $address['email'];
		}
		return array_values( $emails );
	}

	/**
	 * Subject line.
	 *
	 * @return string
	 */
	public function subject() {
		return (string) $this->data['subject'];
	}

	/**
	 * Body.
	 *
	 * @return string
	 */
	public function body() {
		return (string) $this->data['body'];
	}

	/**
	 * Content type, e.g. text/plain or text/html.
	 *
	 * @return string
	 */
	public function content_type() {
		return (string) $this->data['content_type'];
	}

	/**
	 * Whether the body is HTML.
	 *
	 * @return bool
	 */
	public function is_html() {
		return 'text/html' === strtolower( $this->content_type() );
	}

	/**
	 * Character set.
	 *
	 * @return string
	 */
	public function charset() {
		return (string) $this->data['charset'];
	}

	/**
	 * Custom headers as name => value.
	 *
	 * @return array<string, string>
	 */
	public function headers() {
		return $this->data['headers'];
	}

	/**
	 * Attachments as array( 'path' => string, 'name' => string ).
	 *
	 * @return array<int, array{path: string, name: string}>
	 */
	public function attachments() {
		return $this->data['attachments'];
	}

	/**
	 * Return a copy with some parts replaced.
	 *
	 * @param array<string, mixed> $changes Parts to change.
	 * @return self
	 */
	public function with( array $changes ) {
		return new self( array_merge( $this->data, $changes ) );
	}

	/**
	 * Export all parts.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Format an address for a header, e.g. "Jane <jane@example.com>".
	 *
	 * @param array{email: string, name: string} $address Address.
	 * @return string
	 */
	public static function format_address( array $address ) {
		$name = trim( str_replace( array( '"', "\r", "\n" ), '', (string) $address['name'] ) );
		if ( '' === $name ) {
			return $address['email'];
		}
		return sprintf( '"%s" <%s>', $name, $address['email'] );
	}
}
