<?php
namespace CodoDigital\Mailer\Tests\Unit\Log;

use Brain\Monkey\Functions;
use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Tests\TestCase;

class LogRepositoryTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $db;

	/** @var string[] SQL passed to prepare(). */
	private $prepared = array();

	protected function setUp(): void {
		parent::setUp();
		$this->db         = \Mockery::mock( 'wpdb' );
		$this->db->prefix = 'wp_';
		$this->db->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared[] = $sql;
				return 'PREPARED:' . $sql . '|' . implode( ',', $args );
			}
		);
	}

	private function repo() {
		return new LogRepository( $this->db );
	}

	public function test_table_uses_site_prefix() {
		$this->assertSame( 'wp_codo_mailer_log', $this->repo()->table() );
	}

	public function test_install_runs_db_delta_and_records_version() {
		$this->db->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET=utf8mb4' );
		Functions\expect( 'dbDelta' )->once()->with(
			\Mockery::on(
				static function ( $sql ) {
					return false !== strpos( $sql, 'CREATE TABLE wp_codo_mailer_log' )
						&& false !== strpos( $sql, 'PRIMARY KEY  (id)' )
						&& false !== strpos( $sql, 'DEFAULT CHARSET=utf8mb4' );
				}
			)
		);
		Functions\expect( 'update_option' )->once()->with( LogRepository::DB_VERSION_OPTION, LogRepository::DB_VERSION, true );

		$this->repo()->install();
	}

	public function test_maybe_install_skips_when_current() {
		Functions\when( 'get_option' )->justReturn( LogRepository::DB_VERSION );
		Functions\expect( 'dbDelta' )->never();
		$this->assertFalse( $this->repo()->maybe_install() );
	}

	public function test_maybe_install_runs_when_outdated() {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'dbDelta' )->once();
		$this->db->shouldReceive( 'get_charset_collate' )->andReturn( '' );
		$this->assertTrue( $this->repo()->maybe_install() );
	}

	public function test_insert_normalises_and_encodes_columns() {
		$this->db->insert_id = 7;
		$this->db->shouldReceive( 'insert' )->once()->with(
			'wp_codo_mailer_log',
			\Mockery::on(
				function ( $row ) {
					$this->assertSame( 'sent', $row['status'] );
					$this->assertSame( str_repeat( 'c', 40 ), $row['connection'] );
					$this->assertSame( 'resend', $row['source'] );
					$this->assertSame( '[{"email":"a@example.org","name":""}]', $row['to_addresses'] );
					$this->assertSame( '{"cc":[]}', $row['headers'] );
					$this->assertSame( '[]', $row['attachments'] );
					$this->assertSame( 1, $row['redacted'] );
					$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['created_at'] );
					return true;
				}
			),
			\Mockery::type( 'array' )
		)->andReturn( 1 );

		$id = $this->repo()->insert(
			array(
				'status'     => 'sent',
				'connection' => str_repeat( 'c', 50 ),
				'source'     => 'resend',
				'to'         => array( array( 'email' => 'a@example.org', 'name' => '' ) ),
				'headers'    => array( 'cc' => array() ),
				'redacted'   => true,
			)
		);

		$this->assertSame( 7, $id );
	}

	public function test_insert_defaults_unknown_status_to_failed_and_reports_db_errors() {
		$this->db->shouldReceive( 'insert' )->once()->with(
			\Mockery::any(),
			\Mockery::on(
				static function ( $row ) {
					return 'failed' === $row['status'] && 'wp_mail' === $row['source'] && 0 === $row['redacted'] && '' === $row['subject'];
				}
			),
			\Mockery::any()
		)->andReturn( false );

		$this->assertSame( 0, $this->repo()->insert( array( 'status' => 'weird' ) ) );
	}

	public function test_find_hydrates_json_columns() {
		$this->db->shouldReceive( 'get_row' )->once()->andReturn(
			array(
				'id'           => '3',
				'to_addresses' => '[{"email":"a@example.org","name":""}]',
				'headers'      => 'not json',
				'attachments'  => '[]',
				'redacted'     => '1',
			)
		);

		$entry = $this->repo()->find( 3 );

		$this->assertSame( 3, $entry['id'] );
		$this->assertSame( 'a@example.org', $entry['to_addresses'][0]['email'] );
		$this->assertSame( array(), $entry['headers'] );
		$this->assertTrue( $entry['redacted'] );
		$this->assertStringContainsString( 'WHERE id = %d', end( $this->prepared ) );
	}

	public function test_find_returns_null_when_missing() {
		$this->db->shouldReceive( 'get_row' )->andReturn( null );
		$this->assertNull( $this->repo()->find( 99 ) );
	}

	public function test_paginate_with_and_without_status() {
		$this->db->shouldReceive( 'get_results' )->twice()->andReturn(
			array( array( 'id' => '2', 'to_addresses' => '[]', 'headers' => '{}', 'attachments' => '[]', 'redacted' => '0' ) ),
			null
		);

		$rows = $this->repo()->paginate( 2, 1000, 'failed' );
		$this->assertSame( 2, $rows[0]['id'] );
		$this->assertFalse( $rows[0]['redacted'] );
		$this->assertStringContainsString( 'WHERE status = %s', end( $this->prepared ) );

		$this->assertSame( array(), $this->repo()->paginate( 0, 0, 'bogus' ) );
		$this->assertStringNotContainsString( 'WHERE', end( $this->prepared ) );
	}

	public function test_count_with_and_without_status() {
		$this->db->shouldReceive( 'get_var' )->twice()->andReturn( '5', null );

		$this->assertSame( 5, $this->repo()->count( 'sent' ) );
		$this->assertStringContainsString( 'WHERE status', end( $this->prepared ) );
		$this->assertSame( 0, $this->repo()->count() );
	}

	public function test_purge_clear_and_drop() {
		$this->db->shouldReceive( 'query' )->times( 3 )->andReturn( 4, 9, true );
		Functions\expect( 'delete_option' )->once()->with( LogRepository::DB_VERSION_OPTION );

		$this->assertSame( 4, $this->repo()->purge_older_than( 0 ) );
		$this->assertStringContainsString( 'created_at < %s', $this->prepared[0] );
		$this->assertSame( 9, $this->repo()->clear() );
		$this->repo()->drop();
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS %i', end( $this->prepared ) );
	}

	private function candidate( $id, array $to, array $headers = array() ) {
		return array(
			'id'           => (string) $id,
			'to_addresses' => json_encode( $to ),
			'headers'      => json_encode( $headers ),
		);
	}

	public function test_ids_for_address_matches_exactly_on_every_address_list() {
		$this->db->shouldReceive( 'esc_like' )->once()->andReturnUsing( 'addslashes' );
		$this->db->shouldReceive( 'get_results' )->once()->with( \Mockery::type( 'string' ), ARRAY_A )->andReturn(
			array(
				$this->candidate( 1, array( array( 'email' => 'Jane@Example.org', 'name' => 'Jane' ) ) ),
				$this->candidate( 2, array( array( 'email' => 'shop@example.com', 'name' => '' ) ), array( 'cc' => array( array( 'email' => 'jane@example.org', 'name' => '' ) ) ) ),
				$this->candidate( 3, array(), array( 'bcc' => array( array( 'email' => 'jane@example.org', 'name' => '' ) ) ) ),
				$this->candidate( 4, array( array( 'email' => 'admin@example.com', 'name' => '' ) ), array( 'reply_to' => array( array( 'email' => 'jane@example.org', 'name' => '' ) ) ) ),
				// LIKE false positives: a custom header, a from address, a longer address.
				$this->candidate( 5, array( array( 'email' => 'admin@example.com', 'name' => '' ) ), array( 'headers' => array( 'X-For' => 'jane@example.org' ), 'from_email' => 'jane@example.org' ) ),
				$this->candidate( 6, array( array( 'email' => 'mary.jane@example.org', 'name' => '' ) ) ),
				$this->candidate( 7, array( 'jane@example.org' ), array( 'cc' => 'jane@example.org' ) ),
			)
		);

		$this->assertSame( array( 1, 2, 3, 4 ), $this->repo()->ids_for_address( ' JANE@example.org ' ) );

		$sql = end( $this->prepared );
		$this->assertStringContainsString( 'SELECT id, to_addresses, headers FROM %i WHERE to_addresses LIKE %s OR headers LIKE %s ORDER BY id ASC', $sql );
	}

	public function test_ids_for_address_searches_for_the_json_encoded_address() {
		$this->db->shouldReceive( 'esc_like' )->once()->with( '"o\\/connor@example.org"' )->andReturn( 'escaped' );
		$this->db->shouldReceive( 'get_results' )->once()->with(
			\Mockery::on(
				static function ( $sql ) {
					return '|wp_codo_mailer_log,%escaped%,%escaped%' === substr( $sql, -strlen( '|wp_codo_mailer_log,%escaped%,%escaped%' ) );
				}
			),
			ARRAY_A
		)->andReturn( null );

		$this->assertSame( array(), $this->repo()->ids_for_address( 'O/Connor@example.org' ) );
	}

	public function test_ids_for_an_empty_address_queries_nothing() {
		$this->db->shouldNotReceive( 'get_results' );
		$this->assertSame( array(), $this->repo()->ids_for_address( '  ' ) );
	}

	public function test_roles_of_and_address_lists() {
		$entry = array(
			'to_addresses' => array( array( 'email' => 'jane@example.org', 'name' => '' ) ),
			'headers'      => array(
				'cc'       => array( array( 'email' => 'other@example.org', 'name' => '' ) ),
				'bcc'      => 'corrupt',
				'reply_to' => array( 'not-an-array', array( 'email' => 'JANE@example.org' ) ),
			),
		);

		$this->assertSame( array( 'To', 'Reply-To' ), LogRepository::roles_of( $entry, 'jane@example.org' ) );
		$this->assertSame( array( 'Cc' ), LogRepository::roles_of( $entry, 'other@example.org' ) );
		$this->assertSame( array(), LogRepository::roles_of( $entry, 'nobody@example.org' ) );
		$this->assertSame( array(), LogRepository::address_lists( $entry )['Bcc'] );
		$this->assertSame( array( 'To' => array(), 'Cc' => array(), 'Bcc' => array(), 'Reply-To' => array() ), LogRepository::address_lists( array( 'headers' => 'corrupt' ) ) );
	}

	public function test_update_recipients() {
		$this->db->shouldReceive( 'update' )->once()->with(
			'wp_codo_mailer_log',
			array(
				'to_addresses' => '[{"email":"a@example.org","name":""}]',
				'headers'      => '{"cc":[]}',
				'error'        => 'err',
			),
			array( 'id' => 5 ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		)->times( 3 )->andReturn( 1, 0, false );

		$repo = $this->repo();
		$to   = array( 3 => array( 'email' => 'a@example.org', 'name' => '' ) );
		$this->assertTrue( $repo->update_recipients( '5', $to, array( 'cc' => array() ), 'err' ) );
		$this->assertTrue( $repo->update_recipients( 5, $to, array( 'cc' => array() ), 'err' ), 'no change is not a failure' );
		$this->assertFalse( $repo->update_recipients( 5, $to, array( 'cc' => array() ), 'err' ) );
	}

	public function test_delete() {
		$this->db->shouldReceive( 'delete' )->twice()->with( 'wp_codo_mailer_log', array( 'id' => 8 ), array( '%d' ) )->andReturn( 1, 0 );

		$this->assertTrue( $this->repo()->delete( '8' ) );
		$this->assertFalse( $this->repo()->delete( 8 ) );
	}
}
