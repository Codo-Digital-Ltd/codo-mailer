<?php
/**
 * Base class for HTTP API transports.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Transport;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Shared validation, attachment handling and error reporting.
 */
abstract class AbstractHttpTransport implements TransportInterface {

	/**
	 * Headers that providers set themselves and reject if supplied.
	 */
	const RESERVED_HEADERS = array( 'mime-version', 'content-transfer-encoding', 'content-type', 'x-mailer' );

	/**
	 * Default cap on the total size of a message's attachments, in bytes.
	 */
	const ATTACHMENT_LIMIT = 25 * MB_IN_BYTES;

	/**
	 * Roughly how many bytes of memory sending needs per attachment byte:
	 * the file, its base64 copy, and the request body built from that.
	 */
	const MEMORY_PER_ATTACHMENT_BYTE = 4;

	/**
	 * Connection settings.
	 *
	 * @var array<string, mixed>
	 */
	protected $config;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	protected $http;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Connection settings.
	 * @param HttpClient           $http   HTTP client.
	 */
	public function __construct( array $config, HttpClient $http ) {
		$this->config = $config;
		$this->http   = $http;
	}

	/**
	 * Config keys that must be non-empty.
	 *
	 * @return string[]
	 */
	abstract protected function required_fields();

	/**
	 * Perform the provider call.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 * @throws TransportException On failure.
	 */
	abstract protected function deliver( Message $message );

	/**
	 * Send the message.
	 *
	 * @param Message $message Message.
	 * @return SendResult
	 */
	public function send( Message $message ) {
		foreach ( $this->required_fields() as $field ) {
			if ( empty( $this->config[ $field ] ) ) {
				return SendResult::failure( sprintf( '%s connection is missing "%s".', $this->name(), $field ) );
			}
		}

		try {
			$this->check_attachment_size( $message );
			return $this->deliver( $message );
		} catch ( TransportException $e ) {
			// Messages are HTML-escaped when thrown; results hold plain text and
			// are escaped again wherever they are displayed.
			return SendResult::failure( wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
		}
	}

	/**
	 * Provider limit on the total size of attachments, in bytes.
	 *
	 * @return int
	 */
	protected function attachment_limit() {
		return self::ATTACHMENT_LIMIT;
	}

	/**
	 * Refuse attachments too large for the provider or for PHP's memory.
	 *
	 * API transports read every attachment into memory and encode it, so
	 * this runs before any file is read. Failing here lets the dispatcher
	 * log the error and try the backup connection instead of PHP running
	 * out of memory.
	 *
	 * @param Message $message Message.
	 * @return void
	 * @throws TransportException When the attachments are too large.
	 */
	protected function check_attachment_size( Message $message ) {
		$total = 0;
		foreach ( $message->attachments() as $attachment ) {
			$size   = is_file( $attachment['path'] ) ? filesize( $attachment['path'] ) : false;
			$total += false === $size ? 0 : $size;
		}
		if ( 0 === $total ) {
			return;
		}

		/**
		 * Filters the largest total attachment size an API connection will send.
		 *
		 * @param int    $bytes Limit in bytes: 10 MB for Postmark, 25 MB otherwise.
		 * @param string $type  Connection type, e.g. 'postmark'.
		 */
		$limit = (int) apply_filters( 'codo_mailer_attachment_size_limit', $this->attachment_limit(), $this->name() );
		if ( $limit > 0 && $total > $limit ) {
			throw new TransportException( esc_html( sprintf( 'Attachments total %1$s, over the %2$s limit for %3$s connections.', self::megabytes( $total ), self::megabytes( $limit ), $this->label() ) ) );
		}

		$free = self::free_memory();
		if ( null !== $free && $total * self::MEMORY_PER_ATTACHMENT_BYTE > $free ) {
			throw new TransportException( esc_html( sprintf( 'Attachments total %1$s, too large to send through %2$s with the memory PHP has left (%3$s). Raise memory_limit or send smaller files.', self::megabytes( $total ), $this->label(), self::megabytes( $free ) ) ) );
		}
	}

	/**
	 * Bytes left under memory_limit, or null if there is no limit.
	 *
	 * @return int|null
	 */
	private static function free_memory() {
		$limit = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $limit || '-1' === $limit ) {
			return null;
		}
		$bytes = (int) $limit;
		switch ( strtolower( substr( $limit, -1 ) ) ) {
			case 'g':
				$bytes *= GB_IN_BYTES;
				break;
			case 'm':
				$bytes *= MB_IN_BYTES;
				break;
			case 'k':
				$bytes *= KB_IN_BYTES;
				break;
		}
		return $bytes > 0 ? max( 0, $bytes - memory_get_usage() ) : null;
	}

	/**
	 * Format bytes as megabytes, or kilobytes below 1 MB.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private static function megabytes( $bytes ) {
		return $bytes < MB_IN_BYTES ? ceil( $bytes / KB_IN_BYTES ) . ' KB' : round( $bytes / MB_IN_BYTES, 1 ) . ' MB';
	}

	/**
	 * JSON APIs (Postmark, Brevo, SendGrid) require at least one To address.
	 *
	 * @param Message $message Message.
	 * @return void
	 * @throws TransportException When there is no To recipient.
	 */
	protected function require_to( Message $message ) {
		if ( empty( $message->to() ) ) {
			throw new TransportException( esc_html( sprintf( '%s requires at least one To recipient (Cc/Bcc-only messages are not supported).', $this->label() ) ) );
		}
	}

	/**
	 * Read attachments for JSON APIs.
	 *
	 * @param Message $message Message.
	 * @return array<int, array{name: string, content: string, type: string}> Base64 content.
	 * @throws TransportException When a file cannot be read.
	 */
	protected function encoded_attachments( Message $message ) {
		$result = array();
		foreach ( $message->attachments() as $attachment ) {
			$path = $attachment['path'];
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				throw new TransportException( esc_html( sprintf( 'Attachment "%s" could not be read.', $attachment['name'] ) ) );
			}
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			$filetype = wp_check_filetype( $attachment['name'] );
			$result[] = array(
				'name'    => $attachment['name'],
				'content' => base64_encode( (string) $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by provider APIs.
				'type'    => ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream',
			);
		}
		return $result;
	}

	/**
	 * Custom headers safe to hand to a JSON API.
	 *
	 * @param Message $message Message.
	 * @return array<string, string>
	 */
	protected function api_headers( Message $message ) {
		$headers = array();
		foreach ( $message->headers() as $name => $value ) {
			if ( ! in_array( strtolower( $name ), self::RESERVED_HEADERS, true ) ) {
				$headers[ $name ] = $value;
			}
		}
		return $headers;
	}

	/**
	 * JSON-encode a request body.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return string
	 * @throws TransportException When encoding fails (e.g. invalid UTF-8).
	 */
	protected function json( array $data ) {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			throw new TransportException( 'Could not encode the request as JSON.' );
		}
		return $json;
	}

	/**
	 * Decode a JSON response body to an array (empty on failure).
	 *
	 * @param string $body Body.
	 * @return array<string, mixed>
	 */
	protected function decode( $body ) {
		$data = json_decode( (string) $body, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Throw for an unexpected HTTP status.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 * @param string $detail Provider-specific error text, if extracted.
	 * @return void
	 * @throws TransportException Always.
	 */
	protected function fail_http( $status, $body, $detail = '' ) {
		if ( '' === $detail ) {
			$detail = trim( wp_strip_all_tags( (string) $body ) );
		}
		if ( strlen( $detail ) > 300 ) {
			$detail = mb_substr( $detail, 0, 300, 'UTF-8' ) . '…';
		}
		throw new TransportException( esc_html( sprintf( '%s API returned HTTP %d: %s', $this->label(), $status, '' !== $detail ? $detail : 'no details' ) ) );
	}

	/**
	 * Human-readable provider name.
	 *
	 * @return string
	 */
	protected function label() {
		$types = \CodoDigital\Mailer\Settings\Settings::connection_types();
		return isset( $types[ $this->name() ] ) ? $types[ $this->name() ]['label'] : $this->name();
	}

	/**
	 * Map addresses to an API shape.
	 *
	 * @param array<int, array{email: string, name: string}> $addresses Addresses.
	 * @param string                                         $email_key Key for the email.
	 * @param string                                         $name_key  Key for the name.
	 * @return array<int, array<string, string>>
	 */
	protected function address_objects( array $addresses, $email_key = 'email', $name_key = 'name' ) {
		$result = array();
		foreach ( $addresses as $address ) {
			$item = array( $email_key => $address['email'] );
			if ( '' !== $address['name'] ) {
				$item[ $name_key ] = $address['name'];
			}
			$result[] = $item;
		}
		return $result;
	}
}
