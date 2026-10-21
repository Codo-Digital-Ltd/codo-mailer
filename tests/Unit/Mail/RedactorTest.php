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
			// Issue #4.
			'bare verification code'     => array( 'Your verification code is 482913. It expires in 10 minutes.', '482913' ),
			'two-factor prompt'          => array( "Enter this code to finish logging in:\n\n<strong>731 204</strong>", '731 204' ),
			'pin with colon'             => array( 'Your PIN: 5821', '5821' ),
			'otp in html'                => array( '<p>OTP <b>90817265</b></p>', '90817265' ),
			'passcode'                   => array( 'Use passcode 4401 to join', '4401' ),
			'code before label'          => array( '662019 is your login code', '662019' ),
			'password without colon'     => array( 'Your new password is Fl4mingo-77 so keep it safe', 'Fl4mingo-77' ),
			'html password without colon' => array( '<p>Your password is <strong>Gr33nT3a</strong></p>', 'Gr33nT3a' ),
			'oauth style code parameter' => array( 'https://e.com/callback?state=x&code=OAUTHCODE1', 'OAUTHCODE1' ),
			'magic link login_key'       => array( 'https://e.com/?login_key=LKEY42&user=3', 'LKEY42' ),
			'access token parameter'     => array( 'https://e.com/approve?access_token=ATOK99', 'ATOK99' ),
		);
	}

	/**
	 * Issue #4: ordinary numbers and prose must survive.
	 *
	 * @dataProvider ordinary
	 */
	public function test_ordinary_numbers_and_prose_are_not_redacted( $body ) {
		$result = ( new Redactor() )->redact( $body );
		$this->assertFalse( $result['redacted'], $result['body'] );
		$this->assertSame( $body, $result['body'] );
	}

	public function ordinary() {
		return array(
			'order number'         => array( 'Your order #12345 has shipped. Invoice 20261021 attached.' ),
			'discount code'        => array( 'Use discount code SAVE10 for 10% off.' ),
			'postcode'             => array( 'Delivery postcode BD23 2HQ, post code 1234, zip code 90210, area code 0113' ),
			'code with no number'  => array( 'Your code expires soon. Request a new one.' ),
			'password prose'       => array( 'Your password is incorrect. Your password is now set. The password is required.' ),
			'year after label'     => array( 'Code of conduct, updated 2026' ),
			'phone number'         => array( 'Call 01756 700000 for help with your PIN reset.' ),
		);
	}

	public function test_several_codes_are_redacted_without_withholding_the_body() {
		$result = ( new Redactor() )->redact( 'Use code 1234 or 5678 to log in.' );
		$this->assertTrue( $result['redacted'] );
		$this->assertStringNotContainsString( '1234', $result['body'] );
		$this->assertNotSame( Redactor::WITHHELD, $result['body'] );
	}

	public function test_subject_is_redacted_with_the_short_placeholder() {
		$redactor = new Redactor();

		$this->assertSame(
			array(
				'body'     => 'Order 482913 shipped',
				'redacted' => false,
			),
			$redactor->redact_subject( 'Order 482913 shipped' ),
			'ordinary subject untouched'
		);
		$this->assertSame(
			array(
				'body'     => Redactor::PLACEHOLDER . ' is your login code',
				'redacted' => true,
			),
			$redactor->redact_subject( '482913 is your login code' )
		);
		$this->assertSame( Redactor::PLACEHOLDER, $redactor->redact_subject( 'Login %3Faction%3Drp%26key%3DX' )['body'], 'encoded secret withholds the subject, not with the body notice' );
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
				$patterns[] = '/Voucher: \w{6}/';
				$patterns[] = 42;
				return $patterns;
			}
		);

		$result = ( new Redactor() )->redact( 'Your Voucher: AB12CD' );
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
