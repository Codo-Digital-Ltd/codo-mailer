<?php
namespace CodoDigital\Mailer\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use CodoDigital\Mailer\Admin\AdminPage;
use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Mail\Dispatcher;
use CodoDigital\Mailer\Mail\MessageFactory;
use CodoDigital\Mailer\Security\Crypto;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Tests\TestCase;

class AdminPageTest extends TestCase {

	/** @var array<string, mixed> */
	private $option = array();

	/** @var array<string, mixed> */
	private $constants = array();

	/** @var array<string, mixed> */
	private $transients = array();

	/** @var string|null */
	private $redirected_to = null;

	/** @var int */
	private $terminated = 0;

	/** @var bool */
	private $can = true;

	/** @var string[] Nonce actions checked. */
	private $nonces = array();

	/** @var bool */
	private $nonce_valid = true;

	/** @var \Mockery\MockInterface */
	private $dispatcher;

	/** @var \Mockery\MockInterface */
	private $log;

	/** @var Settings */
	private $settings;

	protected function setUp(): void {
		parent::setUp();
		$_GET  = array();
		$_POST = array();

		Functions\stubs(
			array(
				'current_user_can'     => function () {
					return $this->can;
				},
				'wp_die'               => static function ( $message ) {
					throw new \RuntimeException( 'wp_die: ' . $message );
				},
				'check_admin_referer'  => function ( $action ) {
					$this->nonces[] = $action;
					if ( ! $this->nonce_valid ) {
						throw new \RuntimeException( 'bad nonce' );
					}
					return 1;
				},
				'wp_nonce_field'       => static function ( $action ) {
					echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">';
				},
				'submit_button'        => static function ( $text ) {
					echo '<button>' . $text . '</button>';
				},
				'selected'             => static function ( $a, $b, $echo ) {
					return (string) $a === (string) $b ? ' selected="selected"' : '';
				},
				'checked'              => static function ( $a, $b, $echo ) {
					return $a === $b ? ' checked="checked"' : '';
				},
				'disabled'             => static function ( $a, $b, $echo ) {
					return $a === $b ? ' disabled="disabled"' : '';
				},
				'get_transient'        => function ( $key ) {
					return isset( $this->transients[ $key ] ) ? $this->transients[ $key ] : false;
				},
				'set_transient'        => function ( $key, $value ) {
					$this->transients[ $key ] = $value;
					return true;
				},
				'delete_transient'     => function ( $key ) {
					unset( $this->transients[ $key ] );
					return true;
				},
				'get_current_user_id'  => 3,
				'wp_get_current_user'  => static function () {
					return (object) array( 'user_email' => 'admin@example.com' );
				},
				'wp_safe_redirect'     => function ( $url ) {
					$this->redirected_to = $url;
					return true;
				},
				'get_option'           => function () {
					return $this->option;
				},
				'esc_url_raw'          => static function ( $url ) {
					return $url;
				},
				'_n'                   => static function ( $single, $plural, $n ) {
					return 1 === $n ? $single : $plural;
				},
			)
		);

		$this->dispatcher = \Mockery::mock( Dispatcher::class );
		$this->log        = \Mockery::mock( LogRepository::class );
		$this->settings   = new Settings(
			new Crypto( 'k' ),
			function ( $name ) {
				return isset( $this->constants[ $name ] ) ? $this->constants[ $name ] : null;
			}
		);
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	private function page() {
		return new AdminPage(
			$this->settings,
			$this->dispatcher,
			$this->log,
			function () {
				$this->terminated++;
			}
		);
	}

	private function render() {
		ob_start();
		$this->page()->render();
		return ob_get_clean();
	}

	private function notice() {
		return $this->transients['codo_mailer_notice_3'];
	}

	public function test_register_adds_hooks() {
		$page = $this->page();
		$page->register();

		foreach ( array( 'admin_menu', 'admin_enqueue_scripts', 'admin_post_codo_mailer_save', 'admin_post_codo_mailer_test', 'admin_post_codo_mailer_resend', 'admin_post_codo_mailer_clear_log' ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), $hook );
		}
		$this->assertNotFalse( has_filter( 'plugin_action_links_codo-mailer/codo-mailer.php' ) );
	}

	public function test_add_menu_requires_manage_options() {
		Functions\expect( 'add_options_page' )->once()->with( 'Codo Mailer', 'Codo Mailer', 'manage_options', 'codo-mailer', \Mockery::type( 'array' ) );
		$this->page()->add_menu();
	}

	public function test_action_links_prepend_settings() {
		$links = $this->page()->action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertStringContainsString( 'page=codo-mailer', $links[0] );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertCount( 2, $links );
	}

	public function test_enqueue_only_on_our_screen() {
		Functions\expect( 'wp_register_script' )->once();
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'codo-mailer-admin' );
		Functions\expect( 'wp_add_inline_script' )->once();

		$this->page()->enqueue( 'index.php' );
		$this->page()->enqueue( 'settings_page_codo-mailer' );
	}

	public function test_render_requires_capability() {
		$this->can = false;
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->page()->render();
	}

	public function test_settings_tab_renders_fields_without_leaking_secrets() {
		$crypto       = new Crypto( 'k' );
		$this->option = array(
			'from_email' => 'noreply@example.com',
			'primary'    => array(
				'type'     => 'smtp',
				'host'     => 'smtp.example.com',
				'password' => $crypto->encrypt( 'TOP-SECRET' ),
			),
		);

		$html = $this->render();

		$this->assertStringContainsString( 'nav-tab nav-tab-active">Settings', $html );
		$this->assertStringContainsString( 'value="noreply@example.com"', $html );
		$this->assertStringContainsString( 'value="smtp.example.com"', $html );
		$this->assertStringContainsString( 'Saved. Leave blank to keep it.', $html );
		$this->assertStringNotContainsString( 'TOP-SECRET', $html );
		$this->assertStringContainsString( 'nonce-codo_mailer_save', $html );
		$this->assertStringContainsString( '<option value="smtp" selected="selected">SMTP</option>', $html );
		$this->assertStringContainsString( 'data-codo-type="ses"', $html, 'every provider is rendered for the type switcher' );
		$this->assertStringContainsString( 'Used only when the primary connection fails.', $html );
	}

	public function test_constant_values_are_read_only() {
		$this->constants = array(
			'CODO_MAILER_FROM_EMAIL'       => 'host@example.com',
			'CODO_MAILER_LOG_ENABLED'      => true,
			'CODO_MAILER_PRIMARY_TYPE'     => 'mailgun',
			'CODO_MAILER_PRIMARY_API_KEY'  => 'key',
			'CODO_MAILER_PRIMARY_REGION'   => 'us',
		);

		$html = $this->render();

		$this->assertStringContainsString( 'value="host@example.com" class="regular-text" disabled="disabled"', $html );
		$this->assertStringContainsString( 'name="codo_mailer[primary][type]" value="mailgun"', $html, 'hidden field keeps the locked type' );
		$this->assertStringContainsString( 'Set in wp-config.php', $html );
		$this->assertStringNotContainsString( 'value="key"', $html );
	}

	public function test_unknown_tab_falls_back_to_settings() {
		$_GET['tab'] = 'evil<script>';
		$this->assertStringContainsString( 'nav-tab nav-tab-active">Settings', $this->render() );
	}

	public function test_notice_is_shown_once_and_escaped() {
		$this->transients['codo_mailer_notice_3'] = array( 'type' => 'error', 'message' => '<b>Failed</b>' );

		$html = $this->render();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '&lt;b&gt;Failed&lt;/b&gt;', $html );
		$this->assertArrayNotHasKey( 'codo_mailer_notice_3', $this->transients );

		$this->transients['codo_mailer_notice_3'] = array( 'message' => 'ok' );
		$this->assertStringContainsString( 'notice-success', $this->render() );

		$this->transients['codo_mailer_notice_3'] = 'corrupt';
		$this->assertStringNotContainsString( 'notice-', $this->render() );
	}

	public function test_test_tab_prefills_current_user() {
		$_GET['tab'] = 'test';
		$html        = $this->render();
		$this->assertStringContainsString( 'value="admin@example.com"', $html );
		$this->assertStringContainsString( 'nonce-codo_mailer_test', $html );
	}

	private function log_row( array $overrides = array() ) {
		return array_merge(
			array(
				'id'           => 12,
				'created_at'   => '2026-10-05 09:00:00',
				'status'       => 'failed',
				'connection'   => 'primary:smtp',
				'to_addresses' => array( array( 'email' => 'jane@example.org', 'name' => '' ), 'junk' ),
				'subject'      => '<script>alert(1)</script>',
				'body'         => '<p>Body</p>',
				'headers'      => array(
					'from_email' => 'shop@example.com',
					'cc'         => array( array( 'email' => 'cc@example.org', 'name' => '' ) ),
				),
				'attachments'  => array(),
				'error'        => 'Auth failed',
				'redacted'     => false,
			),
			$overrides
		);
	}

	public function test_log_list_escapes_and_paginates() {
		$_GET = array( 'tab' => 'log', 'status' => 'failed', 'paged' => '2' );
		$this->log->shouldReceive( 'count' )->andReturn( 60 );
		$this->log->shouldReceive( 'paginate' )->once()->with( 2, AdminPage::PER_PAGE, 'failed' )->andReturn( array( $this->log_row(), $this->log_row( array( 'id' => 13, 'status' => 'sent' ) ) ) );

		$html = $this->render();

		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringContainsString( 'jane@example.org', $html );
		$this->assertStringContainsString( '<td>Sent</td>', $html );
		$this->assertStringContainsString( 'Page 2 of 3', $html );
		$this->assertStringContainsString( 'Newer', $html );
		$this->assertStringContainsString( 'Older', $html );
		$this->assertStringContainsString( 'class="current">Failed', $html );
		$this->assertStringContainsString( 'nonce-codo_mailer_clear_log', $html );
	}

	public function test_empty_log_with_logging_off() {
		$_GET                    = array( 'tab' => 'log' );
		$this->option            = array( 'log_enabled' => false );
		$this->log->shouldReceive( 'count' )->andReturn( 0 );
		$this->log->shouldReceive( 'paginate' )->andReturn( array() );

		$html = $this->render();
		$this->assertStringContainsString( 'Logging is turned off', $html );
		$this->assertStringContainsString( 'No emails logged yet.', $html );
		$this->assertStringNotContainsString( 'Page 1 of', $html );
	}

	public function test_log_entry_view_with_resend_button() {
		$_GET = array( 'tab' => 'log', 'entry' => '12' );
		$this->log->shouldReceive( 'find' )->with( 12 )->andReturn( $this->log_row() );

		$html = $this->render();

		$this->assertStringContainsString( '&lt;p&gt;Body&lt;/p&gt;', $html, 'HTML bodies are shown as source, never rendered' );
		$this->assertStringContainsString( 'shop@example.com', $html );
		$this->assertStringContainsString( 'cc@example.org', $html );
		$this->assertStringContainsString( 'Auth failed', $html );
		$this->assertStringContainsString( 'nonce-codo_mailer_resend_12', $html );
		$this->assertStringNotContainsString( '<th scope="row">Bcc</th>', $html, 'empty rows are skipped' );
	}

	public function test_redacted_entry_has_no_resend_button() {
		$_GET = array( 'tab' => 'log', 'entry' => '12' );
		$this->log->shouldReceive( 'find' )->andReturn( $this->log_row( array( 'redacted' => true, 'headers' => array() ) ) );

		$html = $this->render();
		$this->assertStringContainsString( 'were redacted before logging', $html );
		$this->assertStringNotContainsString( 'codo_mailer_resend', $html );
	}

	public function test_missing_entry() {
		$_GET = array( 'tab' => 'log', 'entry' => '404' );
		$this->log->shouldReceive( 'find' )->andReturn( null );
		$this->assertStringContainsString( 'Log entry not found.', $this->render() );
	}

	public function test_handlers_require_capability() {
		$this->can = false;
		$this->expectException( \RuntimeException::class );
		$this->page()->handle_save();
	}

	public function test_handlers_verify_the_nonce() {
		$this->nonce_valid = false;
		$this->log->shouldNotReceive( 'clear' );
		try {
			$this->page()->handle_clear_log();
			$this->fail( 'Expected the nonce check to stop the request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
			$this->assertSame( array( 'codo_mailer_clear_log' ), $this->nonces );
		}
	}

	public function test_handle_save_stores_and_redirects() {
		$_POST['codo_mailer'] = array( 'from_name' => 'Shop' );
		Functions\expect( 'update_option' )->once()->with( Settings::OPTION, \Mockery::on( static function ( $v ) {
			return 'Shop' === $v['from_name'];
		} ), false );

		$this->page()->handle_save();

		$this->assertStringContainsString( 'tab=settings', $this->redirected_to );
		$this->assertSame( 'Settings saved.', $this->notice()['message'] );
		$this->assertSame( 1, $this->terminated );
	}

	public function test_handle_save_ignores_non_array_input() {
		$_POST['codo_mailer'] = 'string';
		Functions\expect( 'update_option' )->once();
		$this->page()->handle_save();
	}

	public function test_handle_test_validates_address() {
		$_POST['to'] = 'not-an-email';
		$this->page()->handle_test();
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertSame( 'Enter a valid email address.', $this->notice()['message'] );

		unset( $_POST['to'] );
		$this->page()->handle_test();
		$this->assertSame( 'Enter a valid email address.', $this->notice()['message'] );
	}

	public function test_handle_test_requires_a_connection() {
		$_POST['to'] = 'me@example.com';
		$this->page()->handle_test();
		$this->assertSame( 'Choose and save a primary connection first.', $this->notice()['message'] );
	}

	public function test_handle_test_success_and_failure() {
		$_POST['to']  = 'me@example.com';
		$this->option = array( 'primary' => array( 'type' => 'sendgrid' ) );
		$this->dispatcher->shouldReceive( 'message_factory' )->andReturn( new MessageFactory() );
		$this->dispatcher->shouldReceive( 'send' )->twice()->with( \Mockery::on( static function ( $m ) {
			return 'me@example.com' === $m->to()[0]['email'] && false !== strpos( $m->subject(), 'Example & Co' );
		} ), 'test' )->andReturn( true, false );

		$this->page()->handle_test();
		$this->assertSame( 'Test email sent to me@example.com.', $this->notice()['message'] );
		$this->assertStringContainsString( 'tab=test', $this->redirected_to );

		$this->page()->handle_test();
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertStringContainsString( 'tab=log', $this->redirected_to );
		$this->assertStringContainsString( 'status=failed', $this->redirected_to );
	}

	public function test_handle_test_reports_an_invalid_sender() {
		$_POST['to']  = 'me@example.com';
		$this->option = array( 'primary' => array( 'type' => 'sendgrid' ) );
		\Brain\Monkey\Filters\expectApplied( 'wp_mail_from' )->andReturn( 'broken' );
		$this->dispatcher->shouldReceive( 'message_factory' )->andReturn( new MessageFactory() );
		$this->dispatcher->shouldNotReceive( 'send' );

		$this->page()->handle_test();
		$this->assertSame( 'Invalid From address "broken".', $this->notice()['message'] );
	}

	public function test_handle_resend_paths() {
		$_POST['entry'] = '12';
		$this->log->shouldReceive( 'find' )->with( 12 )->andReturn( null, $this->log_row(), $this->log_row() );
		$this->dispatcher->shouldReceive( 'resend' )->twice()->andReturn( true, 'Resending failed.' );

		$this->page()->handle_resend();
		$this->assertSame( 'Log entry not found.', $this->notice()['message'] );

		$this->page()->handle_resend();
		$this->assertSame( 'Email resent.', $this->notice()['message'] );

		$this->page()->handle_resend();
		$this->assertSame( 'Resending failed.', $this->notice()['message'] );
		$this->assertStringContainsString( 'entry=12', $this->redirected_to );
		$this->assertSame( array_fill( 0, 3, 'codo_mailer_resend_12' ), $this->nonces, 'nonce is bound to the entry ID' );
	}

	public function test_handle_resend_without_an_id() {
		$this->log->shouldReceive( 'find' )->with( 0 )->andReturn( null );
		$this->page()->handle_resend();
		$this->assertSame( array( 'codo_mailer_resend_0' ), $this->nonces );
		$this->assertSame( 'Log entry not found.', $this->notice()['message'] );
	}

	public function test_handle_clear_log() {
		$this->log->shouldReceive( 'clear' )->twice()->andReturn( 1, 4 );

		$this->page()->handle_clear_log();
		$this->assertSame( 'Deleted 1 log entry.', $this->notice()['message'] );

		$this->page()->handle_clear_log();
		$this->assertSame( 'Deleted 4 log entries.', $this->notice()['message'] );
	}
}
