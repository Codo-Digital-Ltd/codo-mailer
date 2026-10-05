<?php
namespace CodoDigital\Mailer\Tests\Unit\Mail;

use CodoDigital\Mailer\Mail\Message;
use CodoDigital\Mailer\Mail\SendResult;
use CodoDigital\Mailer\Tests\TestCase;

class MessageTest extends TestCase {

	public function test_defaults_fill_missing_parts_and_unknown_keys_are_dropped() {
		$message = new Message( array( 'subject' => 'Hi', 'bogus' => 'x' ) );

		$this->assertSame( 'Hi', $message->subject() );
		$this->assertSame( 'text/plain', $message->content_type() );
		$this->assertSame( 'UTF-8', $message->charset() );
		$this->assertSame( array(), $message->to() );
		$this->assertArrayNotHasKey( 'bogus', $message->to_array() );
	}

	public function test_accessors_return_parts() {
		$message = $this->message(
			array(
				'cc'          => array( array( 'email' => 'c@example.org', 'name' => '' ) ),
				'bcc'         => array( array( 'email' => 'b@example.org', 'name' => '' ) ),
				'reply_to'    => array( array( 'email' => 'r@example.org', 'name' => 'R' ) ),
				'headers'     => array( 'X-Test' => '1' ),
				'attachments' => array( array( 'path' => '/tmp/a', 'name' => 'a' ) ),
			)
		);

		$this->assertSame( 'site@example.com', $message->from_email() );
		$this->assertSame( 'Example Site', $message->from_name() );
		$this->assertSame( 'c@example.org', $message->cc()[0]['email'] );
		$this->assertSame( 'b@example.org', $message->bcc()[0]['email'] );
		$this->assertSame( 'R', $message->reply_to()[0]['name'] );
		$this->assertSame( array( 'X-Test' => '1' ), $message->headers() );
		$this->assertSame( 'a', $message->attachments()[0]['name'] );
		$this->assertSame( 'Plain body', $message->body() );
	}

	public function test_is_html_is_case_insensitive() {
		$this->assertTrue( $this->message( array( 'content_type' => 'TEXT/HTML' ) )->is_html() );
		$this->assertFalse( $this->message()->is_html() );
	}

	public function test_all_recipient_emails_deduplicates_case_insensitively() {
		$message = $this->message(
			array(
				'cc'  => array( array( 'email' => 'JANE@example.org', 'name' => '' ) ),
				'bcc' => array( array( 'email' => 'other@example.org', 'name' => '' ) ),
			)
		);

		$this->assertSame( array( 'JANE@example.org', 'other@example.org' ), $message->all_recipient_emails() );
	}

	public function test_with_returns_a_modified_copy() {
		$original = $this->message();
		$copy     = $original->with( array( 'subject' => 'Changed' ) );

		$this->assertSame( 'Hello', $original->subject() );
		$this->assertSame( 'Changed', $copy->subject() );
	}

	public function test_format_address_quotes_names_and_strips_injection() {
		$this->assertSame( 'a@example.org', Message::format_address( array( 'email' => 'a@example.org', 'name' => '' ) ) );
		$this->assertSame( '"Jane Doe" <a@example.org>', Message::format_address( array( 'email' => 'a@example.org', 'name' => 'Jane "Doe' ) ) );
		$this->assertSame( '"EvilBcc: x" <a@example.org>', Message::format_address( array( 'email' => 'a@example.org', 'name' => "Evil\r\nBcc: x" ) ) );
	}

	public function test_send_result_success_and_failure() {
		$ok = SendResult::success( 'abc' );
		$this->assertTrue( $ok->is_success() );
		$this->assertSame( 'abc', $ok->message_id() );
		$this->assertSame( '', $ok->error() );

		$fail = SendResult::failure( 'boom' );
		$this->assertFalse( $fail->is_success() );
		$this->assertSame( 'boom', $fail->error() );
		$this->assertSame( '', $fail->message_id() );

		$this->assertSame( 'Unknown error', SendResult::failure( '' )->error() );
	}
}
