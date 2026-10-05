<?php
namespace CodoDigital\Mailer\Tests\Unit;

use Brain\Monkey\Functions;
use CodoDigital\Mailer\Admin\AdminPage;
use CodoDigital\Mailer\Alerts\AlertNotifier;
use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Mail\Dispatcher;
use CodoDigital\Mailer\Plugin;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Tests\TestCase;

class PluginTest extends TestCase {

	/** @var \Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Plugin::reset();
		$this->wpdb         = \Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' )->byDefault();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function plugin( $settings = null, $log = null, $dispatcher = null, $admin = null ) {
		return new Plugin(
			$settings ? $settings : \Mockery::mock( Settings::class ),
			$log ? $log : \Mockery::mock( LogRepository::class ),
			$dispatcher ? $dispatcher : \Mockery::mock( Dispatcher::class ),
			$admin ? $admin : \Mockery::mock( AdminPage::class )
		);
	}

	public function test_create_builds_the_object_graph() {
		$this->assertInstanceOf( Plugin::class, Plugin::create() );
	}

	public function test_boot_registers_once() {
		Functions\when( 'get_option' )->justReturn( LogRepository::DB_VERSION );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );

		$first  = Plugin::boot();
		$second = Plugin::boot();

		$this->assertSame( $first, $second );
		$this->assertNotFalse( has_filter( 'pre_wp_mail' ) );
		$this->assertNotFalse( has_action( Plugin::CRON_HOOK ) );
		$this->assertFalse( has_action( 'admin_menu' ), 'admin hooks only in wp-admin' );
	}

	public function test_register_in_admin_adds_admin_hooks() {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\expect( 'wp_next_scheduled' )->once()->andReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Plugin::CRON_HOOK );
		$log = \Mockery::mock( LogRepository::class );
		$log->shouldReceive( 'maybe_install' )->once();
		$dispatcher = \Mockery::mock( Dispatcher::class );
		$dispatcher->shouldReceive( 'register' )->once();
		$admin = \Mockery::mock( AdminPage::class );
		$admin->shouldReceive( 'register' )->once();

		$this->plugin( null, $log, $dispatcher, $admin )->register();
	}

	public function test_purge_uses_retention_setting() {
		$settings = \Mockery::mock( Settings::class );
		$settings->shouldReceive( 'get' )->with( 'log_retention_days' )->andReturn( 14 );
		$log = \Mockery::mock( LogRepository::class );
		$log->shouldReceive( 'purge_older_than' )->once()->with( 14 )->andReturn( 3 );

		$this->assertSame( 3, $this->plugin( $settings, $log )->purge_log() );
	}

	public function test_activate_installs_and_schedules_once() {
		Functions\expect( 'dbDelta' )->twice();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\expect( 'wp_next_scheduled' )->twice()->with( Plugin::CRON_HOOK )->andReturn( false, 123 );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', Plugin::CRON_HOOK );

		Plugin::activate();
		Plugin::activate();
	}

	public function test_deactivate_unschedules() {
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Plugin::CRON_HOOK );
		Plugin::deactivate();
	}

	public function test_uninstall_removes_everything() {
		$this->wpdb->shouldReceive( 'query' )->once();
		Functions\expect( 'delete_option' )->once()->with( LogRepository::DB_VERSION_OPTION );
		Functions\expect( 'delete_option' )->once()->with( Settings::OPTION );
		Functions\expect( 'delete_transient' )->once()->with( AlertNotifier::LOCK_TRANSIENT );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Plugin::CRON_HOOK );

		Plugin::uninstall();
	}
}
