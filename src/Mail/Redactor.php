<?php
/**
 * Removes account-takeover secrets from logged email.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Detects and redacts password-reset and similar one-time links before a
 * message body is written to the log.
 */
final class Redactor {

	const PLACEHOLDER = '[redacted by Codo Mailer]';

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

		return array(
			'body'     => $clean,
			'redacted' => $count > 0,
		);
	}

	/**
	 * Regular expressions that match secret-bearing URLs or tokens.
	 *
	 * Filterable so other plugins (e.g. magic-link logins) can add theirs.
	 *
	 * @return string[]
	 */
	private function patterns() {
		$defaults = array(
			// Core password reset / set-password links: wp-login.php?action=rp&key=...
			'#[^\s"\'<>]*[?&](?:amp;)?action=(?:rp|resetpass)[^\s"\'<>]*#i',
			// Any URL carrying a reset key or one-time token parameter.
			'#[^\s"\'<>]*[?&](?:amp;)?(?:key|reset_key|token|login_token|magic_token)=[^\s"\'<>&]+[^\s"\'<>]*#i',
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
