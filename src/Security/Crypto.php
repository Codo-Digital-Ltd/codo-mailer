<?php
/**
 * Encryption of stored secrets (API keys, SMTP passwords).
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption with libsodium (secretbox), keyed from the
 * site's salts or a dedicated CODO_MAILER_ENCRYPTION_KEY constant.
 *
 * A database dump on its own is therefore not enough to recover secrets.
 */
final class Crypto {

	const PREFIX = 'cm1:';

	/**
	 * Raw key material.
	 *
	 * @var string
	 */
	private $key_material;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_material Key material; defaults to constant or salts.
	 */
	public function __construct( $key_material = null ) {
		if ( null === $key_material ) {
			$key_material = defined( 'CODO_MAILER_ENCRYPTION_KEY' ) && '' !== (string) CODO_MAILER_ENCRYPTION_KEY
				? (string) CODO_MAILER_ENCRYPTION_KEY
				: wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		$this->key_material = (string) $key_material;
	}

	/**
	 * Encrypt a secret. Empty strings stay empty.
	 *
	 * @param string $plaintext Secret.
	 * @return string Prefixed ciphertext.
	 */
	public function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe storage of ciphertext.
	}

	/**
	 * Decrypt a secret.
	 *
	 * Values without the prefix are returned unchanged (legacy plaintext);
	 * values that fail authentication return an empty string.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public function decrypt( $value ) {
		$value = (string) $value;
		if ( ! $this->is_encrypted( $value ) ) {
			return $value;
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a stored value is encrypted by this class.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public function is_encrypted( $value ) {
		return 0 === strpos( (string) $value, self::PREFIX );
	}

	/**
	 * Derive the 32-byte secretbox key.
	 *
	 * @return string
	 */
	private function key() {
		return sodium_crypto_generichash( 'codo-mailer|' . $this->key_material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
