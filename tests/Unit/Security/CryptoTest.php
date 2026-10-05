<?php
namespace CodoDigital\Mailer\Tests\Unit\Security;

use CodoDigital\Mailer\Security\Crypto;
use CodoDigital\Mailer\Tests\TestCase;

class CryptoTest extends TestCase {

	public function test_round_trip() {
		$crypto = new Crypto( 'key-material' );
		$cipher = $crypto->encrypt( 'p@ssw0rd' );

		$this->assertStringStartsWith( Crypto::PREFIX, $cipher );
		$this->assertStringNotContainsString( 'p@ssw0rd', $cipher );
		$this->assertTrue( $crypto->is_encrypted( $cipher ) );
		$this->assertSame( 'p@ssw0rd', $crypto->decrypt( $cipher ) );
	}

	public function test_each_encryption_uses_a_fresh_nonce() {
		$crypto = new Crypto( 'key-material' );
		$this->assertNotSame( $crypto->encrypt( 'same' ), $crypto->encrypt( 'same' ) );
	}

	public function test_empty_values_stay_empty() {
		$crypto = new Crypto( 'k' );
		$this->assertSame( '', $crypto->encrypt( '' ) );
		$this->assertSame( '', $crypto->decrypt( '' ) );
	}

	public function test_legacy_plaintext_passes_through() {
		$crypto = new Crypto( 'k' );
		$this->assertFalse( $crypto->is_encrypted( 'plain' ) );
		$this->assertSame( 'plain', $crypto->decrypt( 'plain' ) );
	}

	public function test_wrong_key_fails_closed() {
		$cipher = ( new Crypto( 'one' ) )->encrypt( 'secret' );
		$this->assertSame( '', ( new Crypto( 'two' ) )->decrypt( $cipher ) );
	}

	public function test_tampered_or_malformed_ciphertext_fails_closed() {
		$crypto = new Crypto( 'k' );
		$cipher = $crypto->encrypt( 'secret' );

		$raw      = base64_decode( substr( $cipher, strlen( Crypto::PREFIX ) ) );
		$raw[30]  = chr( ord( $raw[30] ) ^ 1 );
		$tampered = Crypto::PREFIX . base64_encode( $raw );

		$this->assertSame( '', $crypto->decrypt( $tampered ) );
		$this->assertSame( '', $crypto->decrypt( Crypto::PREFIX . '!!!not base64!!!' ) );
		$this->assertSame( '', $crypto->decrypt( Crypto::PREFIX . base64_encode( 'short' ) ) );
	}

	public function test_default_key_comes_from_wp_salts() {
		$a = new Crypto();
		$b = new Crypto( 'salt-authsalt-secure_auth' );
		$this->assertSame( 'x', $b->decrypt( $a->encrypt( 'x' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dedicated_constant_key_wins_over_salts() {
		define( 'CODO_MAILER_ENCRYPTION_KEY', 'dedicated' );
		$a = new Crypto();
		$b = new Crypto( 'dedicated' );
		$this->assertSame( 'x', $b->decrypt( $a->encrypt( 'x' ) ) );
	}
}
