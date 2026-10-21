<?php
namespace CodoDigital\Mailer\Tests\Unit\Privacy;

use Brain\Monkey\Functions;
use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Mail\Redactor;
use CodoDigital\Mailer\Privacy\PrivacyTools;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Tests\TestCase;

class PrivacyToolsTest extends TestCase {

	/** @var array<int, array<string, mixed>> Hydrated log entries by ID: an in-memory log table. */
	private $rows = array();

	/** @var int[] IDs whose writes fail. */
	private $broken = array();

	/** @var int[] IDs deleted between the ID lookup and reading the row. */
	private $vanished = array();

	/** @var array<string, mixed> */
	private $config = array(
		'log_enabled'        => true,
		'log_retention_days' => 30,
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'_n'           => static function ( $single, $plural, $n ) {
					return 1 === $n ? $single : $plural;
				},
				'wp_kses_post' => static function ( $html ) {
					return $html;
				},
			)
		);
	}

	private function tools() {
		$log = \Mockery::mock( LogRepository::class );
		$log->shouldReceive( 'ids_for_address' )->andReturnUsing(
			function ( $email ) {
				$ids = array();
				foreach ( $this->rows as $id => $row ) {
					if ( LogRepository::roles_of( $row, $email ) ) {
						$ids[] = $id;
					}
				}
				return $ids;
			}
		);
		$log->shouldReceive( 'find' )->andReturnUsing(
			function ( $id ) {
				return isset( $this->rows[ $id ] ) && ! in_array( $id, $this->vanished, true ) ? $this->rows[ $id ] : null;
			}
		);
		$log->shouldReceive( 'delete' )->andReturnUsing(
			function ( $id ) {
				if ( in_array( $id, $this->broken, true ) || ! isset( $this->rows[ $id ] ) ) {
					return false;
				}
				unset( $this->rows[ $id ] );
				return true;
			}
		);
		$log->shouldReceive( 'update_recipients' )->andReturnUsing(
			function ( $id, $to, $headers, $error ) {
				if ( in_array( $id, $this->broken, true ) ) {
					return false;
				}
				$this->rows[ $id ]['to_addresses'] = $to;
				$this->rows[ $id ]['headers']      = $headers;
				$this->rows[ $id ]['error']        = $error;
				return true;
			}
		);

		$settings = \Mockery::mock( Settings::class );
		$settings->shouldReceive( 'get' )->andReturnUsing(
			function ( $key ) {
				return $this->config[ $key ];
			}
		);

		return new PrivacyTools( $log, new Redactor(), $settings );
	}

	private function address( $email, $name = '' ) {
		return array(
			'email' => $email,
			'name'  => $name,
		);
	}

	/**
	 * Add a log entry.
	 *
	 * @param int                  $id        ID.
	 * @param array<int, string>   $to        To addresses.
	 * @param array<string, mixed> $overrides Other columns; cc, bcc and reply_to go in headers.
	 */
	private function row( $id, array $to, array $overrides = array() ) {
		$headers = array(
			'from_email' => 'shop@example.com',
			'from_name'  => 'Shop',
			'cc'         => array(),
			'bcc'        => array(),
			'reply_to'   => array(),
			'headers'    => array(),
		);
		foreach ( array( 'cc', 'bcc', 'reply_to' ) as $key ) {
			if ( isset( $overrides[ $key ] ) ) {
				$headers[ $key ] = array_map( array( $this, 'address' ), $overrides[ $key ] );
				unset( $overrides[ $key ] );
			}
		}
		$this->rows[ $id ] = array_merge(
			array(
				'id'           => $id,
				'created_at'   => '2026-10-01 09:00:00',
				'status'       => 'sent',
				'connection'   => 'primary:ses',
				'source'       => 'wp_mail',
				'to_addresses' => array_map( array( $this, 'address' ), $to ),
				'subject'      => 'Order #' . $id,
				'body'         => 'Thanks for order ' . $id,
				'headers'      => $headers,
				'attachments'  => array(),
				'error'        => '',
				'redacted'     => false,
			),
			$overrides
		);
	}

	private function field( array $item, $name ) {
		foreach ( $item['data'] as $field ) {
			if ( $name === $field['name'] ) {
				return $field['value'];
			}
		}
		return null;
	}

	public function test_register_adds_the_core_privacy_hooks() {
		$tools = $this->tools();
		$tools->register();

		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_exporters', array( $tools, 'register_exporter' ) ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', array( $tools, 'register_eraser' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $tools, 'add_policy_content' ) ) );
	}

	public function test_exporter_and_eraser_are_registered_alongside_others() {
		$tools = $this->tools();

		$exporters = $tools->register_exporter( array( 'wordpress-user' => array() ) );
		$this->assertSame( array( 'wordpress-user', 'codo-mailer' ), array_keys( $exporters ) );
		$this->assertSame( 'Codo Mailer email log', $exporters['codo-mailer']['exporter_friendly_name'] );
		$this->assertSame( array( $tools, 'export' ), $exporters['codo-mailer']['callback'] );

		$erasers = $tools->register_eraser( null );
		$this->assertSame( 'Codo Mailer email log', $erasers['codo-mailer']['eraser_friendly_name'] );
		$this->assertSame( array( $tools, 'erase' ), $erasers['codo-mailer']['callback'] );

		$this->assertArrayHasKey( 'codo-mailer', $tools->register_exporter( 'corrupt' ) );
	}

	public function test_export_lists_the_persons_emails_without_other_recipients() {
		$this->row(
			1,
			array( 'jane@example.org' ),
			array(
				'cc'          => array( 'boss@example.org' ),
				'bcc'         => array( 'secret-watcher@example.net' ),
				'attachments' => array(
					array(
						'path' => '/srv/uploads/invoice-1.pdf',
						'name' => 'invoice-1.pdf',
					),
					'junk',
				),
			)
		);
		$this->row( 2, array( 'someone-else@example.org' ) );
		$this->row( 3, array( 'admin@example.com' ), array( 'reply_to' => array( 'Jane@Example.org' ), 'status' => 'failed' ) );

		$result = $this->tools()->export( 'jane@example.org', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 2, $result['data'] );

		$first = $result['data'][0];
		$this->assertSame( 'codo-mailer-log', $first['group_id'] );
		$this->assertSame( 'Email log', $first['group_label'] );
		$this->assertSame( 'codo-mailer-log-1', $first['item_id'] );
		$this->assertSame( '2026-10-01 09:00:00', $this->field( $first, 'Date (UTC)' ) );
		$this->assertSame( 'Sent', $this->field( $first, 'Status' ) );
		$this->assertSame( 'To', $this->field( $first, 'Your address appeared as' ) );
		$this->assertSame( 'shop@example.com', $this->field( $first, 'From' ) );
		$this->assertSame( 'Order #1', $this->field( $first, 'Subject' ) );
		$this->assertSame( 'Thanks for order 1', $this->field( $first, 'Message' ) );
		$this->assertSame( 'invoice-1.pdf', $this->field( $first, 'Attachments' ) );

		$exported = json_encode( $result );
		$this->assertStringNotContainsString( 'boss@example.org', $exported, 'other recipients are not exported' );
		$this->assertStringNotContainsString( 'secret-watcher', $exported, 'nor is who was Bcc\'d' );
		$this->assertStringNotContainsString( '/srv/uploads', $exported, 'nor server paths' );
		$this->assertStringNotContainsString( 'someone-else', $exported );

		$this->assertSame( 'Reply-To', $this->field( $result['data'][1], 'Your address appeared as' ) );
		$this->assertSame( 'Failed', $this->field( $result['data'][1], 'Status' ) );
		$this->assertNull( $this->field( $result['data'][1], 'Attachments' ), 'no empty Attachments field' );
	}

	public function test_export_redacts_secrets_even_if_the_log_kept_them() {
		$this->row(
			1,
			array( 'jane@example.org' ),
			array(
				'subject' => 'Your code is 482913',
				'body'    => 'Reset: https://shop.example.com/wp-login.php?action=rp&key=abc123&login=jane',
			)
		);

		$item = $this->tools()->export( 'jane@example.org' )['data'][0];

		$this->assertStringNotContainsString( '482913', $this->field( $item, 'Subject' ) );
		$this->assertStringNotContainsString( 'abc123', $this->field( $item, 'Message' ) );
		$this->assertStringContainsString( Redactor::PLACEHOLDER, $this->field( $item, 'Message' ) );
	}

	public function test_export_keeps_an_already_redacted_entry_redacted() {
		$this->row( 1, array( 'jane@example.org' ), array( 'body' => Redactor::WITHHELD, 'redacted' => true ) );

		$item = $this->tools()->export( 'jane@example.org' )['data'][0];

		$this->assertSame( Redactor::WITHHELD, $this->field( $item, 'Message' ) );
	}

	public function test_export_is_paginated() {
		for ( $id = 1; $id <= 120; $id++ ) {
			$this->row( $id, array( 'jane@example.org' ) );
		}
		$tools = $this->tools();

		$pages = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$pages[ $page ] = $tools->export( 'jane@example.org', $page );
		}

		$this->assertCount( 50, $pages[1]['data'] );
		$this->assertFalse( $pages[1]['done'] );
		$this->assertSame( 'codo-mailer-log-51', $pages[2]['data'][0]['item_id'] );
		$this->assertFalse( $pages[2]['done'] );
		$this->assertCount( 20, $pages[3]['data'] );
		$this->assertTrue( $pages[3]['done'] );

		$this->assertSame( array( 'data' => array(), 'done' => true ), $tools->export( 'jane@example.org', 0 + 4 ) );
		$this->assertCount( 50, $tools->export( 'jane@example.org', 0 )['data'], 'page 0 is page 1' );
	}

	public function test_export_for_an_address_with_no_entries() {
		$this->row( 1, array( 'someone@example.org' ) );
		$this->assertSame( array( 'data' => array(), 'done' => true ), $this->tools()->export( 'jane@example.org' ) );
	}

	public function test_export_skips_entries_removed_while_it_runs() {
		$this->row( 1, array( 'jane@example.org' ) );
		$this->row( 2, array( 'jane@example.org' ) );
		$this->vanished = array( 1 );

		$result = $this->tools()->export( 'jane@example.org' );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'codo-mailer-log-2', $result['data'][0]['item_id'] );
	}

	public function test_erase_deletes_emails_sent_only_to_the_person() {
		$this->row( 1, array( 'jane@example.org' ) );
		$this->row( 2, array( 'Jane@Example.org' ), array( 'cc' => array( 'jane@example.org' ), 'reply_to' => array( 'help@example.com' ) ) );
		$this->row( 3, array(), array( 'bcc' => array( 'jane@example.org' ) ) );
		$this->row( 4, array( 'other@example.org' ) );

		$result = $this->tools()->erase( 'jane@example.org', 1 );

		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$result
		);
		$this->assertSame( array( 4 ), array_keys( $this->rows ), 'a Reply-To that is not the person does not keep the entry' );
	}

	public function test_erase_keeps_emails_also_sent_to_others_and_only_removes_the_address() {
		$this->row(
			1,
			array( 'jane@example.org', 'john@example.org' ),
			array(
				'cc'    => array( 'jane@example.org' ),
				'bcc'   => array( 'audit@example.com' ),
				'error' => 'Primary ses: Address JANE@example.org bounced',
			)
		);
		$this->row( 2, array( 'admin@example.com' ), array( 'reply_to' => array( 'jane@example.org' ), 'body' => 'Message from Jane' ) );
		$before = $this->rows;

		$result = $this->tools()->erase( 'jane@example.org', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array( '2 emails in the Codo Mailer log were also sent to other people. They were kept for them with this address removed; their subjects and bodies were not changed.' ), $result['messages'] );

		$this->assertSame( array( $this->address( 'john@example.org' ) ), $this->rows[1]['to_addresses'] );
		$this->assertSame( array(), $this->rows[1]['headers']['cc'] );
		$this->assertSame( array( $this->address( 'audit@example.com' ) ), $this->rows[1]['headers']['bcc'], 'other recipients are untouched' );
		$this->assertSame( 'Primary ses: Address [deleted] bounced', $this->rows[1]['error'] );
		$this->assertSame( $before[1]['subject'], $this->rows[1]['subject'] );
		$this->assertSame( $before[1]['body'], $this->rows[1]['body'] );
		$this->assertSame( 'shop@example.com', $this->rows[1]['headers']['from_email'] );

		$this->assertSame( array(), $this->rows[2]['headers']['reply_to'] );
		$this->assertSame( array( $this->address( 'admin@example.com' ) ), $this->rows[2]['to_addresses'] );
		$this->assertSame( 'Message from Jane', $this->rows[2]['body'] );

		$this->assertSame( array(), $this->tools()->export( 'jane@example.org' )['data'], 'nothing left to export' );
		$this->assertCount( 1, $this->tools()->export( 'john@example.org' )['data'], 'John still has his email' );
	}

	public function test_erase_message_for_a_single_shared_email() {
		$this->row( 1, array( 'jane@example.org', 'john@example.org' ) );

		$result = $this->tools()->erase( 'jane@example.org' );

		$this->assertSame( array( '1 email in the Codo Mailer log was also sent to other people. It was kept for them with this address removed; its subject and body were not changed.' ), $result['messages'] );
	}

	public function test_erase_never_touches_other_peoples_entries() {
		$this->row( 1, array( 'mary.jane@example.org' ) );
		$this->row( 2, array( 'jane@example.org.uk' ) );
		$this->row( 3, array( 'admin@example.com' ), array( 'headers' => array( 'from_email' => 'jane@example.org', 'headers' => array( 'X-For' => 'jane@example.org' ) ) ) );
		$before = $this->rows;

		$result = $this->tools()->erase( 'jane@example.org' );

		$this->assertSame( $before, $this->rows );
		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_erase_works_in_batches_until_done() {
		for ( $id = 1; $id <= 120; $id++ ) {
			$this->row( $id, 0 === $id % 2 ? array( 'jane@example.org' ) : array( 'jane@example.org', 'john@example.org' ) );
		}
		$tools = $this->tools();

		$first = $tools->erase( 'jane@example.org', 1 );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 95, $this->rows, '25 deleted, 25 anonymised' );

		$second = $tools->erase( 'jane@example.org', 2 );
		$this->assertFalse( $second['done'] );

		$third = $tools->erase( 'jane@example.org', 3 );
		$this->assertTrue( $third['done'] );
		$this->assertCount( 60, $this->rows, 'the 60 shared emails stay' );
		$this->assertSame( array(), $tools->export( 'jane@example.org' )['data'] );
	}

	public function test_erase_reports_failures_and_stops_when_nothing_can_change() {
		for ( $id = 1; $id <= 60; $id++ ) {
			$this->row( $id, 1 === $id ? array( 'jane@example.org', 'john@example.org' ) : array( 'jane@example.org' ) );
		}
		$this->broken = range( 1, 60 );

		$result = $this->tools()->erase( 'jane@example.org' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertSame( array( '50 Codo Mailer log entries could not be erased.' ), $result['messages'] );
		$this->assertTrue( $result['done'], 'no endless loop when a batch changes nothing' );

		$this->broken = array( 2 );
		$this->rows   = array_slice( $this->rows, 1, 1, true );
		$this->assertSame( array( '1 Codo Mailer log entry could not be erased.' ), $this->tools()->erase( 'jane@example.org' )['messages'] );
	}

	public function test_erase_skips_entries_removed_while_it_runs() {
		$this->row( 1, array( 'jane@example.org' ) );
		$this->vanished = array( 1 );

		$result = $this->tools()->erase( 'jane@example.org' );
		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_policy_text_describes_the_log_and_its_retention() {
		$this->config['log_retention_days'] = 14;
		Functions\expect( 'wp_add_privacy_policy_content' )->once()->with(
			'Codo Mailer',
			\Mockery::on(
				static function ( $html ) {
					return 0 === strpos( $html, '<p>This site sends email' )
						&& false !== strpos( $html, 'Entries are deleted after 14 days.' )
						&& false !== strpos( $html, 'recipients&#039; addresses' )
						&& false !== strpos( $html, 'If you request erasure' );
				}
			)
		);

		$this->tools()->add_policy_content();
	}

	public function test_policy_text_with_a_one_day_log() {
		$this->config['log_retention_days'] = 1;
		Functions\expect( 'wp_add_privacy_policy_content' )->once()->with( 'Codo Mailer', \Mockery::on( static function ( $html ) {
			return false !== strpos( $html, 'Entries are deleted after 1 day.' );
		} ) );

		$this->tools()->add_policy_content();
	}

	public function test_policy_text_without_the_log() {
		$this->config['log_enabled'] = false;
		Functions\expect( 'wp_add_privacy_policy_content' )->once()->with(
			'Codo Mailer',
			\Mockery::on(
				static function ( $html ) {
					return false !== strpos( $html, 'This site sends email' ) && false === strpos( $html, 'log' );
				}
			)
		);

		$this->tools()->add_policy_content();
	}
}
