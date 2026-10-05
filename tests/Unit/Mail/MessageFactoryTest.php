<?php
namespace CodoDigital\Mailer\Tests\Unit\Mail;

use Brain\Monkey\Filters;
use CodoDigital\Mailer\Mail\InvalidMessageException;
use CodoDigital\Mailer\Mail\MessageFactory;
use CodoDigital\Mailer\Tests\TestCase;

class MessageFactoryTest extends TestCase {

	public function test_defaults_match_core_when_nothing_is_configured() {
		$message = ( new MessageFactory() )->from_wp_mail(
			array(
				'to'      => 'jane@example.org',
				'subject' => 'Hi',
				'message' => 'Body',
			)
		);

		$this->assertSame( 'wordpress@example.com', $message->from_email(), 'www. is stripped like core does' );
		$this->assertSame( 'WordPress', $message->from_name() );
		$this->assertSame( 'text/plain', $message->content_type() );
		$this->assertSame( 'UTF-8', $message->charset() );
		$this->assertSame( 'Hi', $message->subject() );
		$this->assertSame( 'Body', $message->body() );
	}

	public function test_configured_from_replaces_the_default_but_not_a_plugin_from_header() {
		$factory = new MessageFactory( 'noreply@example.com', 'Example' );

		$default = $factory->from_wp_mail( array( 'to' => 'jane@example.org' ) );
		$this->assertSame( 'noreply@example.com', $default->from_email() );
		$this->assertSame( 'Example', $default->from_name() );

		$plugin = $factory->from_wp_mail(
			array(
				'to'      => 'jane@example.org',
				'headers' => array( 'From: Shop <shop@example.com>' ),
			)
		);
		$this->assertSame( 'shop@example.com', $plugin->from_email() );
		$this->assertSame( 'Shop', $plugin->from_name() );
	}

	public function test_configured_name_fills_a_from_header_without_a_name() {
		$message = ( new MessageFactory( '', 'Example' ) )->from_wp_mail(
			array(
				'to'      => 'jane@example.org',
				'headers' => 'From: shop@example.com',
			)
		);

		$this->assertSame( 'shop@example.com', $message->from_email() );
		$this->assertSame( 'Example', $message->from_name() );
	}

	public function test_force_from_overrides_plugin_headers() {
		$message = ( new MessageFactory( 'noreply@example.com', 'Example', true ) )->from_wp_mail(
			array(
				'to'      => 'jane@example.org',
				'headers' => array( 'From: Shop <shop@example.com>' ),
			)
		);

		$this->assertSame( 'noreply@example.com', $message->from_email() );
		$this->assertSame( 'Example', $message->from_name() );
	}

	public function test_core_filters_still_apply() {
		Filters\expectApplied( 'wp_mail_from' )->once()->andReturn( 'filtered@example.com' );
		Filters\expectApplied( 'wp_mail_from_name' )->once()->andReturn( 'Filtered' );
		Filters\expectApplied( 'wp_mail_content_type' )->once()->andReturn( 'text/html' );
		Filters\expectApplied( 'wp_mail_charset' )->once()->andReturn( 'ISO-8859-1' );

		$message = ( new MessageFactory() )->from_wp_mail( array( 'to' => 'jane@example.org' ) );

		$this->assertSame( 'filtered@example.com', $message->from_email() );
		$this->assertSame( 'Filtered', $message->from_name() );
		$this->assertTrue( $message->is_html() );
		$this->assertSame( 'ISO-8859-1', $message->charset() );
	}

	public function test_empty_filtered_content_type_and_charset_fall_back() {
		Filters\expectApplied( 'wp_mail_content_type' )->andReturn( '' );
		Filters\expectApplied( 'wp_mail_charset' )->andReturn( '' );

		$message = ( new MessageFactory() )->from_wp_mail( array( 'to' => 'jane@example.org' ) );

		$this->assertSame( 'text/plain', $message->content_type() );
		$this->assertSame( 'UTF-8', $message->charset() );
	}

	public function test_headers_are_parsed_from_a_crlf_string() {
		$headers = "Cc: a@example.org, \"Smith, Bob\" <b@example.org>\r\n"
			. "Bcc: c@example.org\r\n"
			. "Reply-To: Help <help@example.org>\r\n"
			. "Content-Type: text/html; charset=\"ISO-8859-1\"\r\n"
			. "X-Custom: value\r\n"
			. "Broken header line\r\n"
			. "X-Empty:   \r\n"
			. ": no-name\r\n";

		$message = ( new MessageFactory() )->from_wp_mail(
			array(
				'to'      => 'jane@example.org',
				'headers' => $headers,
			)
		);

		$this->assertSame( array( 'a@example.org', 'b@example.org' ), array_column( $message->cc(), 'email' ) );
		$this->assertSame( 'Smith, Bob', $message->cc()[1]['name'], 'commas inside quoted names do not split' );
		$this->assertSame( 'c@example.org', $message->bcc()[0]['email'] );
		$this->assertSame( 'Help', $message->reply_to()[0]['name'] );
		$this->assertTrue( $message->is_html() );
		$this->assertSame( 'ISO-8859-1', $message->charset() );
		$this->assertSame( array( 'X-Custom' => 'value' ), $message->headers() );
	}

	public function test_multipart_content_type_keeps_the_body_type() {
		$parsed = ( new MessageFactory() )->parse_headers( array( 'Content-Type: multipart/alternative; boundary="x"' ) );
		$this->assertNull( $parsed['content_type'] );
		$this->assertNull( $parsed['charset'] );
	}

	public function test_non_string_headers_and_invalid_from_header_are_ignored() {
		$parsed = ( new MessageFactory() )->parse_headers( array( 42, 'From: not-an-email', array( 'x' ) ) );
		$this->assertNull( $parsed['from'] );
	}

	public function test_header_injection_is_stripped_from_custom_values() {
		$parsed = ( new MessageFactory() )->parse_headers( array( "X-Test: a\rb" ) );
		$this->assertSame( 'ab', $parsed['custom']['X-Test'] );
	}

	public function test_recipients_accept_arrays_and_drop_invalid_entries() {
		$list = ( new MessageFactory() )->parse_address_list( array( 'Jane <jane@example.org>', 'bad', 7, ' ok@example.org ' ) );

		$this->assertSame(
			array(
				array( 'email' => 'jane@example.org', 'name' => 'Jane' ),
				array( 'email' => 'ok@example.org', 'name' => '' ),
			),
			$list
		);
	}

	public function test_no_valid_recipient_throws() {
		$this->expectException( InvalidMessageException::class );
		( new MessageFactory() )->from_wp_mail( array( 'to' => 'not-an-email' ) );
	}

	public function test_bcc_only_message_is_allowed() {
		$message = ( new MessageFactory() )->from_wp_mail(
			array(
				'to'      => '',
				'headers' => array( 'Bcc: hidden@example.org' ),
			)
		);
		$this->assertSame( array( 'hidden@example.org' ), $message->all_recipient_emails() );
	}

	public function test_invalid_from_throws() {
		Filters\expectApplied( 'wp_mail_from' )->andReturn( 'nope' );
		$this->expectException( InvalidMessageException::class );
		$this->expectExceptionMessage( 'Invalid From address &quot;nope&quot;.' ); // HTML-escaped when thrown.
		( new MessageFactory() )->from_wp_mail( array( 'to' => 'jane@example.org' ) );
	}

	public function test_missing_keys_are_tolerated() {
		$message = ( new MessageFactory() )->from_wp_mail( array( 'to' => array( 'jane@example.org' ) ) );
		$this->assertSame( '', $message->subject() );
		$this->assertSame( '', $message->body() );
		$this->assertSame( array(), $message->attachments() );
	}

	public function test_attachments_support_string_list_and_named_forms() {
		$factory = new MessageFactory();

		$this->assertSame(
			array(
				array( 'path' => '/tmp/a.pdf', 'name' => 'a.pdf' ),
				array( 'path' => '/tmp/b.txt', 'name' => 'b.txt' ),
			),
			$factory->parse_attachments( "/tmp/a.pdf\r\n\r\n/tmp/b.txt" )
		);

		$this->assertSame(
			array(
				array( 'path' => '/tmp/x.pdf', 'name' => 'Invoice.pdf' ),
				array( 'path' => '/tmp/y.pdf', 'name' => 'y.pdf' ),
			),
			$factory->parse_attachments( array( 'Invoice.pdf' => '/tmp/x.pdf', '/tmp/y.pdf', '', 5 ) )
		);
	}

	public function test_default_from_keeps_non_www_hosts() {
		\Brain\Monkey\Functions\when( 'network_home_url' )->justReturn( 'https://shop.example.co.uk/' );
		$this->assertSame( 'wordpress@shop.example.co.uk', ( new MessageFactory() )->default_from_email() );
	}
}
