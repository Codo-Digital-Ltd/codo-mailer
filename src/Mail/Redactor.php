<?php
/**
 * Removes account-takeover secrets from logged email.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Detects and redacts password-reset links, one-time tokens and plaintext
 * passwords before a message body is written to the log.
 *
 * Redaction is best-effort: as a fail-safe, if a secret is still visible
 * once the redacted body is URL- and entity-decoded, the whole body is
 * withheld from the log.
 */
final class Redactor {

	const PLACEHOLDER = '[redacted by Codo Mailer]';

	const WITHHELD = '[Body not logged by Codo Mailer: it contained a one-time secret in an encoded form.]';

	/**
	 * Redact secrets from a body.
	 *
	 * @param string $body Message body.
	 * @return array{body: string, redacted: bool}
	 */
	public function redact( $body ) {
		$patterns = $this->patterns();
		$count    = 0;
		$clean    = (string) $body;

		foreach ( $patterns as $pattern ) {
			$replaced = preg_replace( $pattern, self::PLACEHOLDER, $clean, -1, $hits );
			if ( null !== $replaced ) {
				$clean  = $replaced;
				$count += $hits;
			}
		}

		$decoded = html_entity_decode( rawurldecode( $clean ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $decoded ) ) {
				return array(
					'body'     => self::WITHHELD,
					'redacted' => true,
				);
			}
		}

		return array(
			'body'     => $clean,
			'redacted' => $count > 0,
		);
	}

	/**
	 * Regular expressions that match secrets.
	 *
	 * Filterable so other plugins (e.g. magic-link logins) can add theirs.
	 *
	 * @return string[]
	 */
	private function patterns() {
		// Query separator, raw or HTML-escaped (& &amp; &#38; &#038; &#x26;).
		$sep = '(?:[?&]|&amp;|&#0*38;|&#x0*26;)';
		$url = '[^\s"\'<>]*';

		$defaults = array(
			// Password reset, set-password and new-account links (core, WooCommerce).
			'~' . $url . $sep . 'action=(?:rp|resetpass|newaccount)' . $url . '~i',
			// Any URL carrying a reset key, confirmation hash or one-time token.
			'~' . $url . $sep . '(?:key|reset_key|activation_key|confirm_key|adminhash|newuseremail|hash|mkey|token|login_token|magic_token|otp)=[^\s"\'<>&]+' . $url . '~i',
			// Plaintext passwords ("Password: hunter2"), keeping the label.
			'~^[ \t]*(?:<[^>]+>[ \t]*)*(?:your[ \t]+)?(?:password|passwort|mot de passe|contraseña)[ \t]*:[ \t]*(?:<[^>]+>[ \t]*)*\K(?!\[redacted)[^\s<]+~imu',
		);

		/**
		 * Filters the patterns used to redact secrets from logged email bodies.
		 *
		 * @param string[] $defaults PCRE patterns.
		 */
		$patterns = apply_filters( 'codo_mailer_redaction_patterns', $defaults );

		return is_array( $patterns ) ? array_filter( $patterns, 'is_string' ) : $defaults;
	}
}
