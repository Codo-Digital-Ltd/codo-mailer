<?php
namespace CodoDigital\Mailer\Tests\Unit\Mail;

use Brain\Monkey\Filters;
use CodoDigital\Mailer\Mail\Redactor;
use CodoDigital\Mailer\Tests\TestCase;

class RedactorTest extends TestCase {

	public function test_core_password_reset_link_is_redacted() {
		$body = "Someone requested a password reset.\n\nhttps://example.com/wp-login.php?action=rp&key=AbC123&login=jane&wp_lang=en_GB\n\nThanks";

		$result = ( new Redactor() )->redact( $body );

		$this->assertTrue( $result['redacted'] );
		$this->assertStringNotContainsString( 'AbC123', $result['body'] );
		$this->assertStringContainsString( Redactor::PLACEHOLDER, $result['body'] );
		$this->assertStringContainsString( 'Thanks', $result['body'] );
	}

	public function test_html_links_keep_their_markup() {
		$body = '<a href="https://example.com/wp-login.php?action=rp&amp;key=SECRET&amp;login=jane">Reset</a>';

		$result = ( new Redactor() )->redact( $body );

		$this->assertTrue( $result['redacted'] );
		$this->assertStringNotContainsString( 'SECRET', $result['body'] );
		$this->assertSame( '<a href="' . Redactor::PLACEHOLDER . '">Reset</a>', $result['body'] );
	}

	public function test_generic_token_parameters_are_redacted() {
		$result = ( new Redactor() )->redact( 'Log in: https://example.com/magic?token=xyz789 now' );
		$this->assertTrue( $result['redacted'] );
		$this->assertStringNotContainsString( 'xyz789', $result['body'] );
	}

	/**
	 * Secrets the first version missed (found in independent review).
	 *
	 * @dataProvider leaks
	 */
	public function test_secret_is_not_logged( $body, $secret ) {
		$result = ( new Redactor() )->redact( $body );

		$this->assertTrue( $result['redacted'] );
		$this->assertStringNotContainsString( $secret, $result['body'] );
	}

	public function leaks() {
		return array(
			'esc_url-escaped separator'  => array( '<a href="https://shop.example/my-account/?action=newaccount&#038;key=WCKEY123&#038;login=jo">Set password</a>', 'WCKEY123' ),
			'hex entity separator'       => array( 'https://e.com/wp-login.php?login=a&#x26;key=HEXKEY&#x26;action=rp', 'HEXKEY' ),
			'core 7.x parameter order'   => array( 'https://e.com/wp-login.php?login=admin&key=ORDERKEY&action=rp&wp_lang=en_GB', 'ORDERKEY' ),
			'url-encoded in redirect_to' => array( 'https://e.com/track?u=https%3A%2F%2Fe.com%2Fwp-login.php%3Faction%3Drp%26key%3DENCKEY', 'ENCKEY' ),
			'multisite welcome password' => array( "Dear User,\n\nUsername: jo\nPassword: Tr0ub4dor&3\nLog in here: https://e.com/wp-login.php", 'Tr0ub4dor&3' ),
			'html password line'         => array( '<p><strong>Password:</strong> s3cr3t-pw</p>', 's3cr3t-pw' ),
			'email change confirmation'  => array( 'https://e.com/wp-admin/profile.php?newuseremail=abc123hash', 'abc123hash' ),
			'admin email change'         => array( 'https://e.com/wp-admin/options.php?adminhash=ADMINHASH9', 'ADMINHASH9' ),
			'user request confirmation'  => array( 'https://e.com/wp-login.php?action=confirmaction&request_id=5&confirm_key=CONFKEY', 'CONFKEY' ),
			'membership plugin key'      => array( 'https://e.com/account/?mkey=MEMBERKEY', 'MEMBERKEY' ),
			'recovery mode link'         => array( 'https://e.com/wp-login.php?action=enter_recovery_mode&rm_token=a&rm_key=RMKEY', 'RMKEY' ),
			'password phrase'            => array( 'Your password is: Phr4seP4ss', 'Phr4seP4ss' ),
			'woocommerce generated'      => array( '<p>Your password has been automatically generated: <strong>WooGen123</strong></p>', 'WooGen123' ),
			'minified html'              => array( '<p>Hi Jo,</p><p>Username: jo</p><p>Password: Min1fied</p>', 'Min1fied' ),
			'double url-encoded link'    => array( 'https://t.example/c?u=https%253A%252F%252Fe.com%252Fwp-login.php%253Faction%253Drp%2526key%253DDBLKEY', 'DBLKEY' ),
		);
	}

	public function test_encoded_secret_withholds_the_whole_body() {
		$result = ( new Redactor() )->redact( 'Click https://e.com/c?u=https%3A%2F%2Fe.com%2Fwp-login.php%3Faction%3Drp%26key%3DENCKEY' );
		$this->assertSame( Redactor::WITHHELD, $result['body'] );
	}

	public function test_password_label_is_kept_and_prose_mentions_are_not_redacted() {
		$result = ( new Redactor() )->redact( "Your password was changed.\nPassword: hunter2" );
		$this->assertSame( "Your password was changed.\nPassword: " . Redactor::PLACEHOLDER, $result['body'] );
	}

	public function test_pcre_limit_errors_fail_closed() {
		// A pattern that exhausts the backtrack limit (as a huge body can).
		Filters\expectApplied( 'codo_mailer_redaction_patterns' )->andReturnUsing(
			static function ( $patterns ) {
				$patterns[] = '/(a+)+$/';
				return $patterns;
			}
		);
		$limit = ini_get( 'pcre.backtrack_limit' );
		$jit   = ini_get( 'pcre.jit' );
		ini_set( 'pcre.backtrack_limit', '1000' );
		ini_set( 'pcre.jit', '0' );
		try {
			$result = ( new Redactor() )->redact( 'Password: hunter2 ' . str_repeat( 'a', 40 ) . 'b' );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
			ini_set( 'pcre.jit', $jit );
		}

		$this->assertSame( Redactor::WITHHELD, $result['body'] );
		$this->assertTrue( $result['redacted'] );
	}

	public function test_ordinary_tracking_hashes_are_not_redacted() {
		$body   = 'Unsubscribe: https://e.com/unsubscribe?hash=abc123&list=7';
		$result = ( new Redactor() )->redact( $body );
		$this->assertFalse( $result['redacted'] );
		$this->assertSame( $body, $result['body'] );
	}

	public function test_ordinary_email_is_untouched() {
		$body   = 'Your order #123 has shipped. Track it at https://example.com/track?order=123';
		$result = ( new Redactor() )->redact( $body );

		$this->assertFalse( $result['redacted'] );
		$this->assertSame( $body, $result['body'] );
	}

	public function test_patterns_are_filterable() {
		Filters\expectApplied( 'codo_mailer_redaction_patterns' )->once()->andReturnUsing(
			static function ( $patterns ) {
				$patterns[] = '/OTP: \d{6}/';
				$patterns[] = 42;
				return $patterns;
			}
		);

		$result = ( new Redactor() )->redact( 'Your OTP: 123456' );
		$this->assertTrue( $result['redacted'] );
		$this->assertSame( 'Your ' . Redactor::PLACEHOLDER, $result['body'] );
	}

	public function test_non_array_filter_result_falls_back_to_defaults() {
		Filters\expectApplied( 'codo_mailer_redaction_patterns' )->andReturn( 'nonsense' );
		$result = ( new Redactor() )->redact( 'https://e.com/wp-login.php?action=rp&key=K' );
		$this->assertTrue( $result['redacted'] );
	}

	public function test_invalid_pattern_from_a_filter_does_not_break_logging() {
		Filters\expectApplied( 'codo_mailer_redaction_patterns' )->andReturn( array( '/(unclosed' ) );
		$result = ( new Redactor() )->redact( 'plain text' );
		$this->assertSame( Redactor::WITHHELD, $result['body'], 'fails closed' );
		$this->assertTrue( $result['redacted'] );
	}
}
