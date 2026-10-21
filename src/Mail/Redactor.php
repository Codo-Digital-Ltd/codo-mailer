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
		return $this->run( (string) $body, self::WITHHELD );
	}

	/**
	 * Redact secrets from a subject line.
	 *
	 * Same patterns as the body; a subject that can't be safely redacted is
	 * replaced by the short placeholder rather than the body notice.
	 *
	 * @param string $subject Subject.
	 * @return array{body: string, redacted: bool} The redacted subject is in 'body'.
	 */
	public function redact_subject( $subject ) {
		return $this->run( (string) $subject, self::PLACEHOLDER );
	}

	/**
	 * Apply the patterns, with the decode-and-recheck fail-safe.
	 *
	 * @param string $text     Text to redact.
	 * @param string $fallback What to return when the text can't be safely redacted.
	 * @return array{body: string, redacted: bool}
	 */
	private function run( $text, $fallback ) {
		$patterns = $this->patterns();
		$count    = 0;
		$clean    = $text;

		$withheld = array(
			'body'     => $fallback,
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
		// What may separate a code from its label: punctuation, tags and prompt words.
		$gap = '(?:[\s:=.,()\-]|<[^>]+>|(?:is|was|are|your|the|this|a|one-time|verification|security|login|log|sign|in|to|finish|complete|logging|signing|enter|use|below|here|for|account|confirm|confirmation|access|authentication|two-factor|2fa|new|reset)\b){0,40}?';

		$defaults = array(
			// Password reset, set-password and new-account links (core, WooCommerce).
			'~' . $url . $sep . 'action=(?:rp|resetpass|newaccount)' . $url . '~i',
			// Any URL carrying a reset key, confirmation hash or one-time token.
			'~' . $url . $sep . '(?:key|reset_key|rm_key|activation_key|confirm_key|adminhash|newuseremail|mkey|token|login_token|magic_token|otp|code|auth_code|verification_code|access_token|auth_token|login_key|loginkey|magic_key|pwd|password)=[^\s"\'<>&]+' . $url . '~i',
			// Plaintext passwords, keeping the label: "Password: x", "Your password
			// is: x", "...automatically generated: <strong>x</strong>".
			'~\b(?:password|passwort|mot de passe|contraseña)\b[^:\n<>]{0,40}:[ \t]*(?:<[^>]+>[ \t]*)*\K(?!\[redacted)[^\s<]+~iu',
			// Plaintext passwords without a colon: "Your password is x". Skips
			// prose such as "your password is incorrect".
			'~\b(?:password|passwort|mot de passe|contraseña)[ \t]+(?:is|ist|est|es)[ \t]+(?:<[^>]+>[ \t]*)*\K(?!\[redacted)(?!(?:now|not|still|required|incorrect|invalid|wrong|too|being|about|going|set|reset|changed|updated|expired|weak|strong|the|a|an|your|safe|secure)\b)[^\s<:]+~iu',
			// One-time codes and PINs after their label, digits only, so order
			// and invoice numbers stay readable: "Your verification code is
			// 482913", "PIN: 1234", "Enter this code to finish logging in:
			// <b>123 456</b>". Only prompt words may sit between label and code,
			// so "code of conduct, updated 2026" is left alone, as are postcodes
			// and zip and area codes.
			'~(?<!post |zip |area |postal |country )\b(?:code|pin|otp|passcode)\b' . $gap . '\K(?<![\w#-])(?:\d{3}[ -]\d{3}|\d{4,10})(?![\w-])~iu',
			// Code before its label: "482913 is your verification code".
			'~(?<![\w#-])(?:\d{3}[ -]\d{3}|\d{4,10})(?![\w-])(?=[ \t]+is[ \t]+(?:your|the)\b[^\n<>]{0,30}?\b(?:code|pin|otp|passcode)\b)~iu',
		);

		/**
		 * Filters the patterns used to redact secrets from logged email bodies and subjects.
		 *
		 * @param string[] $defaults PCRE patterns.
		 */
		$patterns = apply_filters( 'codo_mailer_redaction_patterns', $defaults );

		return is_array( $patterns ) ? array_filter( $patterns, 'is_string' ) : $defaults;
	}
}
