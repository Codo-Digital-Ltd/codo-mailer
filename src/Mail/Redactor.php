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

		$withheld = array(
			'body'     => self::WITHHELD,
			'redacted' => true,
		);

		foreach ( $patterns as $pattern ) {
			preg_match( '/^/', '' ); // Reset preg_last_error() from any earlier call.
			$replaced = @preg_replace( $pattern, self::PLACEHOLDER, $clean, -1, $hits ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failing pattern is handled below.
			if ( null === $replaced || PREG_NO_ERROR !== preg_last_error() ) {
				// Fail closed: if a pattern can't run (bad filter pattern,
				// backtrack/JIT limit on a huge body), log no body at all.
				return $withheld;
			}
			$clean  = $replaced;
			$count += $hits;
		}

		// Fail-safe: decode (repeatedly, for double encoding) and re-check.
		$decoded = $clean;
		for ( $i = 0; $i < 3; $i++ ) {
			$next = html_entity_decode( rawurldecode( $decoded ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}
		foreach ( $patterns as $pattern ) {
			if ( 0 !== @preg_match( $pattern, $decoded ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 1 (match) and false (error) both withhold.
				return $withheld;
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
			'~' . $url . $sep . '(?:key|reset_key|rm_key|activation_key|confirm_key|adminhash|newuseremail|mkey|token|login_token|magic_token|otp)=[^\s"\'<>&]+' . $url . '~i',
			// Plaintext passwords, keeping the label: "Password: x", "Your password
			// is: x", "...automatically generated: <strong>x</strong>".
			'~\b(?:password|passwort|mot de passe|contraseña)\b[^:\n<>]{0,40}:[ \t]*(?:<[^>]+>[ \t]*)*\K(?!\[redacted)[^\s<]+~iu',
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
