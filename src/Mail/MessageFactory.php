<?php
/**
 * Builds Message objects from wp_mail() arguments.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Parses wp_mail() arguments the same way core does, so other plugins'
 * filters (wp_mail_from, wp_mail_content_type, ...) keep working.
 */
final class MessageFactory {

	/**
	 * Configured From email ('' to keep WordPress's default).
	 *
	 * @var string
	 */
	private $from_email;

	/**
	 * Configured From name.
	 *
	 * @var string
	 */
	private $from_name;

	/**
	 * Whether to override a From set by the calling plugin.
	 *
	 * @var bool
	 */
	private $force_from;

	/**
	 * Constructor.
	 *
	 * @param string $from_email Configured From email.
	 * @param string $from_name  Configured From name.
	 * @param bool   $force_from Override From headers set by other plugins.
	 */
	public function __construct( $from_email = '', $from_name = '', $force_from = false ) {
		$this->from_email = (string) $from_email;
		$this->from_name  = (string) $from_name;
		$this->force_from = (bool) $force_from;
	}

	/**
	 * Build a message from the (already wp_mail-filtered) arguments.
	 *
	 * @param array<string, mixed> $atts Keys: to, subject, message, headers, attachments.
	 * @return Message
	 * @throws InvalidMessageException When there is no valid recipient.
	 */
	public function from_wp_mail( array $atts ) {
		$headers      = isset( $atts['headers'] ) ? $atts['headers'] : array();
		$parsed       = $this->parse_headers( $headers );
		$header_from  = $parsed['from'];
		$default_from = $this->default_from_email();

		$from_email = $default_from;
		$from_name  = 'WordPress';

		if ( null !== $header_from ) {
			$from_email = $header_from['email'];
			$from_name  = '' !== $header_from['name'] ? $header_from['name'] : $from_name;
		}

		if ( '' !== $this->from_email && ( $this->force_from || null === $header_from ) ) {
			$from_email = $this->from_email;
		}
		if ( '' !== $this->from_name && ( $this->force_from || null === $header_from || '' === $header_from['name'] ) ) {
			$from_name = $this->from_name;
		}

		/** This filter is documented in wp-includes/pluggable.php */
		$from_email = (string) apply_filters( 'wp_mail_from', $from_email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		/** This filter is documented in wp-includes/pluggable.php */
		$from_name = (string) apply_filters( 'wp_mail_from_name', $from_name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$content_type = null !== $parsed['content_type'] ? $parsed['content_type'] : 'text/plain';
		/** This filter is documented in wp-includes/pluggable.php */
		$content_type = (string) apply_filters( 'wp_mail_content_type', $content_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$charset = null !== $parsed['charset'] ? $parsed['charset'] : (string) get_bloginfo( 'charset' );
		/** This filter is documented in wp-includes/pluggable.php */
		$charset = (string) apply_filters( 'wp_mail_charset', $charset ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		$to = $this->parse_address_list( isset( $atts['to'] ) ? $atts['to'] : array() );
		if ( empty( $to ) && empty( $parsed['cc'] ) && empty( $parsed['bcc'] ) ) {
			throw new InvalidMessageException( 'No valid recipient address.' );
		}

		if ( ! is_email( $from_email ) ) {
			throw new InvalidMessageException( esc_html( sprintf( 'Invalid From address "%s".', $from_email ) ) );
		}

		return new Message(
			array(
				'from_email'   => $from_email,
				'from_name'    => $from_name,
				'to'           => $to,
				'cc'           => $parsed['cc'],
				'bcc'          => $parsed['bcc'],
				'reply_to'     => $parsed['reply_to'],
				'subject'      => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'body'         => isset( $atts['message'] ) ? (string) $atts['message'] : '',
				'content_type' => '' !== $content_type ? $content_type : 'text/plain',
				'charset'      => '' !== $charset ? $charset : 'UTF-8',
				'headers'      => $parsed['custom'],
				'attachments'  => $this->parse_attachments( isset( $atts['attachments'] ) ? $atts['attachments'] : array() ),
			)
		);
	}

	/**
	 * WordPress's default From address: wordpress@<site domain>.
	 *
	 * @return string
	 */
	public function default_from_email() {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return 'wordpress@' . $host;
	}

	/**
	 * Parse wp_mail headers (string or array) into their parts.
	 *
	 * @param string|string[] $headers Raw headers.
	 * @return array{from: array{email: string, name: string}|null, cc: array, bcc: array, reply_to: array, content_type: string|null, charset: string|null, custom: array<string, string>}
	 */
	public function parse_headers( $headers ) {
		$result = array(
			'from'         => null,
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'content_type' => null,
			'charset'      => null,
			'custom'       => array(),
		);

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}

		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) || false === strpos( $header, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $header, 2 );
			$name                 = trim( $name );
			$value                = trim( $value );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			switch ( strtolower( $name ) ) {
				case 'from':
					$from = $this->parse_address_list( $value );
					if ( ! empty( $from ) ) {
						$result['from'] = $from[0];
					}
					break;
				case 'cc':
					$result['cc'] = array_merge( $result['cc'], $this->parse_address_list( $value ) );
					break;
				case 'bcc':
					$result['bcc'] = array_merge( $result['bcc'], $this->parse_address_list( $value ) );
					break;
				case 'reply-to':
					$result['reply_to'] = array_merge( $result['reply_to'], $this->parse_address_list( $value ) );
					break;
				case 'content-type':
					$this->parse_content_type( $value, $result );
					break;
				default:
					$result['custom'][ $name ] = str_replace( array( "\r", "\n" ), '', $value );
			}
		}

		return $result;
	}

	/**
	 * Parse a Content-Type header value into type and charset.
	 *
	 * @param string               $value  Header value.
	 * @param array<string, mixed> $result Parsed headers, updated in place.
	 * @return void
	 */
	private function parse_content_type( $value, array &$result ) {
		$parts = array_map( 'trim', explode( ';', $value ) );
		$type  = strtolower( array_shift( $parts ) );

		// Multipart types are rebuilt by the transport; keep only the body type.
		if ( 0 !== strpos( $type, 'multipart/' ) ) {
			$result['content_type'] = $type;
		}

		foreach ( $parts as $part ) {
			if ( 0 === stripos( $part, 'charset=' ) ) {
				$result['charset'] = trim( substr( $part, 8 ), "\"' " );
			}
		}
	}

	/**
	 * Parse a recipient list into normalised, validated addresses.
	 *
	 * Accepts "a@b.com, Name <c@d.com>" strings or arrays of such strings.
	 * Invalid addresses are dropped.
	 *
	 * @param string|string[] $addresses Addresses.
	 * @return array<int, array{email: string, name: string}>
	 */
	public function parse_address_list( $addresses ) {
		if ( ! is_array( $addresses ) ) {
			$addresses = $this->split_addresses( (string) $addresses );
		}

		$result = array();
		foreach ( $addresses as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$address = $this->parse_address( $item );
			if ( null !== $address ) {
				$result[] = $address;
			}
		}
		return $result;
	}

	/**
	 * Split a comma-separated list, ignoring commas inside quoted names.
	 *
	 * @param string $raw Comma-separated addresses.
	 * @return string[]
	 */
	private function split_addresses( $raw ) {
		$items     = array();
		$current   = '';
		$in_quotes = false;
		$length    = strlen( $raw );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $raw[ $i ];
			if ( '"' === $char ) {
				$in_quotes = ! $in_quotes;
			}
			if ( ',' === $char && ! $in_quotes ) {
				$items[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$items[] = $current;

		return array_values( array_filter( array_map( 'trim', $items ), 'strlen' ) );
	}

	/**
	 * Parse one address, e.g. "Jane Doe <jane@example.com>".
	 *
	 * @param string $item Address string.
	 * @return array{email: string, name: string}|null Null if invalid.
	 */
	private function parse_address( $item ) {
		$item = trim( str_replace( array( "\r", "\n" ), '', $item ) );
		$name = '';

		if ( preg_match( '/^(.*)<([^>]+)>\s*$/', $item, $matches ) ) {
			$name = trim( $matches[1], " \"'" );
			$item = trim( $matches[2] );
		}

		if ( ! is_email( $item ) ) {
			return null;
		}

		return array(
			'email' => $item,
			'name'  => $name,
		);
	}

	/**
	 * Normalise attachments into path/name pairs.
	 *
	 * Core accepts a newline-separated string, a list of paths, or
	 * name => path pairs (since WordPress 6.2).
	 *
	 * @param string|array<int|string, string> $attachments Attachments.
	 * @return array<int, array{path: string, name: string}>
	 */
	public function parse_attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		$result = array();
		foreach ( $attachments as $key => $path ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				continue;
			}
			$path     = trim( $path );
			$result[] = array(
				'path' => $path,
				'name' => is_string( $key ) && '' !== $key ? $key : basename( $path ),
			);
		}
		return $result;
	}
}
