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
		$result = @( new Redactor() )->redact( 'plain text' ); // phpcs:ignore -- preg warning is expected.
		$this->assertSame( 'plain text', $result['body'] );
		$this->assertFalse( $result['redacted'] );
	}
}
