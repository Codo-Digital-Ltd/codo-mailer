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
}
