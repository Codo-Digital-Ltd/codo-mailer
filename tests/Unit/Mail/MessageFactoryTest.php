<?php
namespace CodoDigital\Mailer\Tests\Unit\Mail;

use Brain\Monkey\Filters;
use CodoDigital\Mailer\Mail\InvalidMessageException;
use CodoDigital\Mailer\Mail\Message;
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

	public function test_header_names_with_line_breaks_or_spaces_are_dropped() {
		$parsed = ( new MessageFactory() )->parse_headers( array( "X-A\r\nBcc: v@example.org: y", 'Bad Name: z', 'X-Ok: 1' ) );
		$this->assertSame( array( 'X-Ok' => '1' ), $parsed['custom'] );
		$this->assertSame( array(), $parsed['bcc'] );
	}

	public function test_content_type_and_charset_with_injection_are_ignored() {
		$parsed = ( new MessageFactory() )->parse_headers( array( "Content-Type: text/html; charset=utf-8\r\n\r\n<injected>" ) );
		$this->assertSame( 'text/html', $parsed['content_type'] );
		$this->assertNull( $parsed['charset'] );

		$parsed = ( new MessageFactory() )->parse_headers( array( 'Content-Type: text/html<x>; charset="utf 8"' ) );
		$this->assertNull( $parsed['content_type'] );
		$this->assertNull( $parsed['charset'] );
	}

	public function test_line_breaks_in_the_subject_become_spaces() {
		$message = ( new MessageFactory() )->from_wp_mail( array( 'to' => 'jane@example.org', 'subject' => "Hi\r\nBcc: x@example.org" ) );
		$this->assertSame( 'Hi  Bcc: x@example.org', $message->subject() );
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

	public function test_stream_wrapper_attachments_are_dropped() {
		$factory = new MessageFactory();

		$this->assertSame(
			array( array( 'path' => '/var/www/uploads/a.pdf', 'name' => 'a.pdf' ) ),
			$factory->parse_attachments( array( 'phar:///var/www/uploads/x.jpg/a', 'ftp://internal/secret', 'PHP://filter/resource=/etc/passwd', '/var/www/uploads/a.pdf' ) )
		);
		$this->assertTrue( MessageFactory::is_local_path( 'C:\uploads\a.pdf' ), 'Windows drive paths are local' );
		$this->assertTrue( MessageFactory::is_local_path( '/tmp/a.pdf' ) );
		$this->assertFalse( MessageFactory::is_local_path( 'https://example.org/a.pdf' ) );
	}

	public function test_default_from_keeps_non_www_hosts() {
		\Brain\Monkey\Functions\when( 'network_home_url' )->justReturn( 'https://shop.example.co.uk/' );
		$this->assertSame( 'wordpress@shop.example.co.uk', ( new MessageFactory() )->default_from_email() );
	}

	public function test_normalize_keeps_a_valid_message_as_it_is() {
		$message = $this->message(
			array(
				'cc'           => array( array( 'email' => 'cc@example.org', 'name' => 'Cc' ) ),
				'bcc'          => array( array( 'email' => 'archive@example.com', 'name' => '' ) ),
				'reply_to'     => array( array( 'email' => 'help@example.com', 'name' => 'Help' ) ),
				'content_type' => 'text/html',
				'charset'      => 'ISO-8859-1',
				'headers'      => array( 'X-Order' => '42' ),
				'attachments'  => array( array( 'path' => '/tmp/a.pdf', 'name' => 'Invoice.pdf' ) ),
			)
		);

		$this->assertSame( $message->to_array(), ( new MessageFactory() )->normalize( $message )->to_array() );
	}

	public function test_normalize_drops_invalid_addresses_and_line_breaks() {
		$message = $this->message(
			array(
				'from_name' => "Shop\r\nBcc: evil@example.net",
				'to'        => array(
					array( 'email' => 'jane@example.org', 'name' => "Jane\nBcc: evil@example.net" ),
					array( 'email' => 'not-an-address', 'name' => '' ),
					'jane@example.org',
					array( 'name' => 'No email' ),
				),
				'cc'        => 'cc@example.org',
				'bcc'       => array( array( 'email' => 'b@example.org', 'name' => array( 'x' ) ) ),
				'subject'   => "Hi\r\nBcc: evil@example.net",
			)
		);

		$clean = ( new MessageFactory() )->normalize( $message );

		$this->assertSame( 'ShopBcc: evil@example.net', $clean->from_name() );
		$this->assertSame( array( array( 'email' => 'jane@example.org', 'name' => 'JaneBcc: evil@example.net' ) ), $clean->to() );
		$this->assertSame( array(), $clean->cc() );
		$this->assertSame( array( array( 'email' => 'b@example.org', 'name' => '' ) ), $clean->bcc() );
		$this->assertSame( 'Hi  Bcc: evil@example.net', $clean->subject() );
	}

	public function test_normalize_checks_custom_headers_like_wp_mail() {
		$message = $this->message(
			array(
				'headers' => array(
					'X-Ok'        => 'yes',
					'X-Injected'  => "a\r\nBcc: evil@example.net",
					'Bad Name'    => 'x',
					'X-Empty'     => '',
					'X-Array'     => array( 'x' ),
					'Cc'          => 'sneaky@example.net',
					'Content-Type' => 'text/html',
				),
			)
		);

		$clean = ( new MessageFactory() )->normalize( $message );

		$this->assertSame(
			array(
				'X-Ok'       => 'yes',
				'X-Injected' => 'aBcc: evil@example.net',
			),
			$clean->headers()
		);
		$this->assertSame( array(), $clean->cc(), 'address headers are not taken from custom headers' );
		$this->assertSame( 'text/plain', $clean->content_type() );
	}

	public function test_normalize_falls_back_on_an_invalid_content_type_or_charset() {
		$clean = ( new MessageFactory() )->normalize(
			$this->message(
				array(
					'content_type' => "text/html\r\nBcc: x@example.net",
					'charset'      => 'utf 8; evil',
				)
			)
		);

		$this->assertSame( 'text/plain', $clean->content_type() );
		$this->assertSame( 'UTF-8', $clean->charset() );

		$multipart = ( new MessageFactory() )->normalize( $this->message( array( 'content_type' => 'multipart/alternative' ) ) );
		$this->assertSame( 'text/plain', $multipart->content_type() );
	}

	public function test_normalize_drops_stream_wrapper_attachments() {
		$clean = ( new MessageFactory() )->normalize(
			$this->message(
				array(
					'attachments' => array(
						array( 'path' => ' /tmp/a.pdf ', 'name' => '' ),
						array( 'path' => '/tmp/b.pdf', 'name' => "b\r\n.pdf" ),
						array( 'path' => 'phar:///tmp/x.phar/y', 'name' => 'y' ),
						array( 'path' => 'https://example.net/z.pdf', 'name' => 'z' ),
						array( 'path' => array( 'x' ) ),
						array( 'name' => 'no path' ),
						'/tmp/c.pdf',
					),
				)
			)
		);

		$this->assertSame(
			array(
				array( 'path' => '/tmp/a.pdf', 'name' => 'a.pdf' ),
				array( 'path' => '/tmp/b.pdf', 'name' => 'b.pdf' ),
			),
			$clean->attachments()
		);
	}

	public function test_normalize_accepts_bcc_only() {
		$clean = ( new MessageFactory() )->normalize(
			$this->message(
				array(
					'to'  => array(),
					'bcc' => array( array( 'email' => 'b@example.org', 'name' => '' ) ),
				)
			)
		);
		$this->assertSame( 'b@example.org', $clean->bcc()[0]['email'] );
	}

	public function test_normalize_rejects_a_message_without_recipients() {
		$this->expectException( InvalidMessageException::class );
		$this->expectExceptionMessage( 'No valid recipient address.' );

		( new MessageFactory() )->normalize( $this->message( array( 'to' => array( array( 'email' => 'nope', 'name' => '' ) ) ) ) );
	}

	public function test_normalize_rejects_an_invalid_from_address() {
		$this->expectException( InvalidMessageException::class );
		$this->expectExceptionMessage( 'Invalid From address &quot;bad&quot;.' );

		( new MessageFactory() )->normalize( $this->message( array( 'from_email' => 'bad' ) ) );
	}
}
