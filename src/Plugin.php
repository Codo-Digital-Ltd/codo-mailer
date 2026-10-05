<?php
/**
 * Plugin bootstrap.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer;

use CodoDigital\Mailer\Admin\AdminPage;
use CodoDigital\Mailer\Alerts\AlertNotifier;
use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Mail\Dispatcher;
use CodoDigital\Mailer\Mail\Redactor;
use CodoDigital\Mailer\Security\Crypto;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Transport\HttpClient;
use CodoDigital\Mailer\Transport\TransportFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services together and registers hooks.
 */
final class Plugin {

	const CRON_HOOK = 'codo_mailer_purge_log';

	/**
	 * Booted instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Log storage.
	 *
	 * @var LogRepository
	 */
	private $log;

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Admin screen.
	 *
	 * @var AdminPage
	 */
	private $admin;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings   Settings.
	 * @param LogRepository $log        Log storage.
	 * @param Dispatcher    $dispatcher Dispatcher.
	 * @param AdminPage     $admin      Admin screen.
	 */
	public function __construct( Settings $settings, LogRepository $log, Dispatcher $dispatcher, AdminPage $admin ) {
		$this->settings   = $settings;
		$this->log        = $log;
		$this->dispatcher = $dispatcher;
		$this->admin      = $admin;
	}

	/**
	 * Build the default object graph.
	 *
	 * @return self
	 */
	public static function create() {
		global $wpdb;

		$settings   = new Settings( new Crypto() );
		$http       = new HttpClient();
		$log        = new LogRepository( $wpdb );
		$dispatcher = new Dispatcher( $settings, new TransportFactory( $http ), $log, new AlertNotifier( $settings, $http ), new Redactor() );
		$admin      = new AdminPage( $settings, $dispatcher, $log );

		return new self( $settings, $log, $dispatcher, $admin );
	}

	/**
	 * Boot once, on plugins_loaded.
	 *
	 * @return self
	 */
	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = self::create();
			self::$instance->register();
		}
		return self::$instance;
	}

	/**
	 * Forget the booted instance (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		$this->log->maybe_install();
		$this->dispatcher->register();
		add_action( self::CRON_HOOK, array( $this, 'purge_log' ) );
		self::schedule_purge();

		if ( is_admin() ) {
			$this->admin->register();
		}
	}

	/**
	 * Daily retention purge.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_log() {
		return $this->log->purge_older_than( (int) $this->settings->get( 'log_retention_days' ) );
	}

	/**
	 * Activation: create the table and schedule the purge.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create()->log->install();
		self::schedule_purge();
	}

	/**
	 * Schedule the daily purge if missing. Also called on every load, so each
	 * site of a network gets its own schedule, not just the one activated on.
	 *
	 * @return void
	 */
	public static function schedule_purge() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation: stop the purge. Data stays until uninstall.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Uninstall: remove all data.
	 *
	 * @return void
	 */
	public static function uninstall() {
		self::create()->log->drop();
		delete_option( Settings::OPTION );
		delete_transient( AlertNotifier::LOCK_TRANSIENT );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
