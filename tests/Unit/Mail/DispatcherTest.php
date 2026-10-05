<?php
namespace CodoDigital\Mailer\Tests\Unit\Mail;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use CodoDigital\Mailer\Alerts\AlertNotifier;
use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Mail\Dispatcher;
use CodoDigital\Mailer\Mail\MessageFactory;
use CodoDigital\Mailer\Mail\Redactor;
use CodoDigital\Mailer\Mail\SendResult;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\TransportFactory;
use CodoDigital\Mailer\Transport\TransportInterface;

class DispatcherTest extends TestCase {

	/** @var array<string, array<string, mixed>> */
	private $connections = array();

	/** @var array<string, mixed> */
	private $config = array();

	/** @var array<string, TransportInterface|null> Keyed by type. */
	private $transports = array();

	/** @var array<int, array<string, mixed>> */
	private $logged = array();

	/** @var \Mockery\MockInterface */
	private $alerts;

	protected function setUp(): void {
		parent::setUp();
		$this->connections = array(
			'primary' => array( 'type' => 'smtp' ),
			'backup'  => array( 'type' => 'none' ),
		);
		$this->config      = array(
			'log_enabled'      => true,
			'redact_sensitive' => true,
			'from_email'       => '',
			'from_name'        => '',
			'force_from'       => false,
		);
		$this->alerts      = \Mockery::mock( AlertNotifier::class );
	}

	private function dispatcher() {
		$settings = \Mockery::mock( Settings::class );
		$settings->shouldReceive( 'connection' )->andReturnUsing(
			function ( $slot ) {
				return $this->connections[ $slot ];
			}
		);
		$settings->shouldReceive( 'get' )->andReturnUsing(
			function ( $key ) {
				return $this->config[ $key ];
			}
		);

		$factory = \Mockery::mock( TransportFactory::class );
		$factory->shouldReceive( 'create' )->andReturnUsing(
			function ( $config ) {
				return isset( $this->transports[ $config['type'] ] ) ? $this->transports[ $config['type'] ] : null;
			}
		);

		$log = \Mockery::mock( LogRepository::class );
		$log->shouldReceive( 'insert' )->andReturnUsing(
			function ( $entry ) {
				$this->logged[] = $entry;
				return count( $this->logged );
			}
		);

		return new Dispatcher( $settings, $factory, $log, $this->alerts, new Redactor() );
	}

	private function transport( $type, SendResult $result, $times = 1 ) {
		$transport = \Mockery::mock( TransportInterface::class );
		$transport->shouldReceive( 'name' )->andReturn( $type );
		$transport->shouldReceive( 'send' )->times( $times )->andReturn( $result );
		$this->transports[ $type ] = $transport;
		return $transport;
	}

	private function atts( array $overrides = array() ) {
		return array_merge(
			array(
				'to'          => 'jane@example.org',
				'subject'     => 'Order #1',
				'message'     => 'Thanks',
				'headers'     => array( 'X-Order: 1' ),
				'attachments' => array(),
			),
			$overrides
		);
	}

	public function test_register_hooks_pre_wp_mail() {
		$dispatcher = $this->dispatcher();
		$dispatcher->register();
		$this->assertNotFalse( has_filter( 'pre_wp_mail', array( $dispatcher, 'filter_pre_wp_mail' ) ) );
	}

	public function test_earlier_short_circuit_is_respected() {
		$this->assertTrue( $this->dispatcher()->filter_pre_wp_mail( true, $this->atts() ) );
		$this->assertFalse( $this->dispatcher()->filter_pre_wp_mail( false, $this->atts() ) );
	}

	public function test_unconfigured_plugin_lets_core_send() {
		$this->connections['primary'] = array( 'type' => 'none' );
		$this->assertNull( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts() ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_primary_success_logs_and_fires_core_hook() {
		$this->transport( 'smtp', SendResult::success( 'id-1' ) );

		Actions\expectDone( 'wp_mail_succeeded' )->once()->with(
			array(
				'to'          => array( 'jane@example.org' ),
				'subject'     => 'Order #1',
				'message'     => 'Thanks',
				'headers'     => array( 'X-Order: 1' ),
				'attachments' => array(),
			)
		);
		Actions\expectDone( 'codo_mailer_sent' )->once();
		Actions\expectDone( 'wp_mail_failed' )->never();
		$this->alerts->shouldNotReceive( 'notify' );

		$this->assertTrue( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts() ) );

		$this->assertCount( 1, $this->logged );
		$this->assertSame( 'sent', $this->logged[0]['status'] );
		$this->assertSame( 'primary:smtp', $this->logged[0]['connection'] );
		$this->assertSame( 'wp_mail', $this->logged[0]['source'] );
		$this->assertSame( 'Thanks', $this->logged[0]['body'] );
		$this->assertSame( array( 'X-Order' => '1' ), $this->logged[0]['headers']['headers'] );
		$this->assertFalse( $this->logged[0]['redacted'] );
		$this->assertSame( '', $this->logged[0]['error'] );
	}

	public function test_primary_failure_falls_back_to_backup() {
		$this->connections['backup'] = array( 'type' => 'ses' );
		$this->transport( 'smtp', SendResult::failure( 'Connection refused' ) );
		$this->transport( 'ses', SendResult::success() );
		$this->alerts->shouldNotReceive( 'notify' );

		$this->assertTrue( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts() ) );

		$this->assertSame( 'sent', $this->logged[0]['status'] );
		$this->assertSame( 'backup:ses', $this->logged[0]['connection'] );
		$this->assertSame( 'Primary smtp: Connection refused', $this->logged[0]['error'], 'the primary failure is kept for diagnosis' );
	}

	public function test_both_failing_logs_fires_wp_mail_failed_and_alerts() {
		$this->connections['backup'] = array( 'type' => 'ses' );
		$this->transport( 'smtp', SendResult::failure( 'Auth failed' ) );
		$this->transport( 'ses', SendResult::failure( 'Throttled' ) );

		Actions\expectDone( 'wp_mail_failed' )->once()->with(
			\Mockery::on(
				static function ( $error ) {
					return $error instanceof \WP_Error
						&& 'wp_mail_failed' === $error->get_error_code()
						&& 'Primary smtp: Auth failed Backup ses: Throttled' === $error->get_error_message()
						&& array( 'jane@example.org' ) === $error->get_error_data()['to'];
				}
			)
		);
		$this->alerts->shouldReceive( 'notify' )->once()->with( \Mockery::type( \CodoDigital\Mailer\Mail\Message::class ), 'Primary smtp: Auth failed Backup ses: Throttled' );

		$this->assertFalse( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts() ) );
		$this->assertSame( 'failed', $this->logged[0]['status'] );
		$this->assertSame( 'none', $this->logged[0]['connection'] );
	}

	public function test_primary_type_without_a_transport_is_reported() {
		$this->connections['primary'] = array( 'type' => 'smtp' );
		$this->alerts->shouldReceive( 'notify' )->once();

		$this->assertFalse( $this->dispatcher()->send( $this->message() ) );
		$this->assertSame( 'No primary connection is configured.', $this->logged[0]['error'] );
	}

	public function test_invalid_wp_mail_arguments_are_logged_without_content_and_alerted() {
		Actions\expectDone( 'wp_mail_failed' )->once();
		$this->alerts->shouldReceive( 'notify' )->once()->with( null, 'No valid recipient address.' );

		$this->assertFalse( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts( array( 'to' => 'nope' ) ) ) );

		$this->assertSame( 'failed', $this->logged[0]['status'] );
		$this->assertSame( 'Order #1', $this->logged[0]['subject'] );
		$this->assertSame( '', $this->logged[0]['body'] );
		$this->assertTrue( $this->logged[0]['redacted'], 'nothing to resend' );
	}

	public function test_escaped_exception_text_is_logged_as_plain_text() {
		Filters\expectApplied( 'wp_mail_from' )->andReturn( 'bad"from' );
		$this->alerts->shouldReceive( 'notify' )->once()->with( null, 'Invalid From address "bad"from".' );

		$this->assertFalse( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts() ) );
		$this->assertSame( 'Invalid From address "bad"from".', $this->logged[0]['error'] );
	}

	public function test_non_array_atts_are_handled() {
		Actions\expectDone( 'wp_mail_failed' )->once();
		$this->alerts->shouldReceive( 'notify' )->once();
		$this->assertFalse( $this->dispatcher()->filter_pre_wp_mail( null, 'garbage' ) );
		$this->assertSame( '', $this->logged[0]['subject'] );
	}

	public function test_invalid_arguments_with_logging_off_still_fail_and_alert() {
		$this->config['log_enabled'] = false;
		$this->alerts->shouldReceive( 'notify' )->once();
		$this->assertFalse( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts( array( 'to' => '' ) ) ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_logging_disabled_writes_nothing() {
		$this->config['log_enabled'] = false;
		$this->transport( 'smtp', SendResult::success() );
		$this->assertTrue( $this->dispatcher()->filter_pre_wp_mail( null, $this->atts() ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_password_reset_bodies_are_redacted_in_the_log() {
		$this->transport( 'smtp', SendResult::success() );
		$this->dispatcher()->filter_pre_wp_mail( null, $this->atts( array( 'message' => 'Reset: https://e.com/wp-login.php?action=rp&key=SECRET&login=a' ) ) );

		$this->assertTrue( $this->logged[0]['redacted'] );
		$this->assertStringNotContainsString( 'SECRET', $this->logged[0]['body'] );
	}

	public function test_redaction_can_be_turned_off() {
		$this->config['redact_sensitive'] = false;
		$this->transport( 'smtp', SendResult::success() );
		$this->dispatcher()->filter_pre_wp_mail( null, $this->atts( array( 'message' => 'https://e.com/wp-login.php?action=rp&key=SECRET' ) ) );

		$this->assertFalse( $this->logged[0]['redacted'] );
		$this->assertStringContainsString( 'SECRET', $this->logged[0]['body'] );
	}

	public function test_no_alert_when_the_failing_message_is_itself_an_alert() {
		$this->transport( 'smtp', SendResult::failure( 'down' ), 2 );
		$this->config['alert_email'] = 'ops@example.com';
		$dispatcher                  = $this->dispatcher();

		// Real notifier: its alert goes back through the dispatcher via wp_mail().
		$settings = \Mockery::mock( Settings::class );
		$settings->shouldReceive( 'get' )->andReturnUsing(
			function ( $key ) {
				return 'alert_email' === $key ? 'ops@example.com' : '';
			}
		);
		\Brain\Monkey\Functions\when( 'get_transient' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'set_transient' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body ) use ( $dispatcher ) {
				return $dispatcher->filter_pre_wp_mail( null, compact( 'to', 'subject' ) + array( 'message' => $body ) );
			}
		);
		$real = new AlertNotifier( $settings, \Mockery::mock( \CodoDigital\Mailer\Transport\HttpClient::class ) );
		$this->alerts->shouldReceive( 'notify' )->once()->andReturnUsing( array( $real, 'notify' ) );

		$this->assertFalse( $dispatcher->filter_pre_wp_mail( null, $this->atts() ) );
		$this->assertCount( 2, $this->logged, 'original + failed alert, no loop' );
	}

	public function test_message_factory_uses_settings() {
		$this->config['from_email'] = 'noreply@example.com';
		$this->config['from_name']  = 'Shop';
		$this->config['force_from'] = true;

		$factory = $this->dispatcher()->message_factory();
		$this->assertInstanceOf( MessageFactory::class, $factory );
		$this->assertSame( 'noreply@example.com', $factory->from_wp_mail( array( 'to' => 'a@example.org', 'headers' => 'From: x@example.org' ) )->from_email() );
	}

	private function entry( array $overrides = array() ) {
		return array_merge(
			array(
				'id'           => 5,
				'to_addresses' => array( array( 'email' => 'jane@example.org', 'name' => 'Jane' ) ),
				'subject'      => 'Order #1',
				'body'         => 'Thanks',
				'headers'      => array(
					'from_email'   => 'shop@example.com',
					'from_name'    => 'Shop',
					'cc'           => array(),
					'bcc'          => array( array( 'email' => 'audit@example.com', 'name' => '' ) ),
					'reply_to'     => array(),
					'content_type' => 'text/html',
					'charset'      => 'UTF-8',
					'headers'      => array( 'X-Order' => '1' ),
					'ignored'      => 'x',
				),
				'attachments'  => array(),
				'redacted'     => false,
			),
			$overrides
		);
	}

	public function test_resend_rebuilds_the_message() {
		$file      = $this->temp_file( 'data', '.pdf' );
		$transport = $this->transport( 'smtp', SendResult::success(), 0 );
		$transport->shouldReceive( 'send' )->once()->with(
			\Mockery::on(
				static function ( $message ) use ( $file ) {
					return 'shop@example.com' === $message->from_email()
						&& $message->is_html()
						&& array( 'X-Order' => '1' ) === $message->headers()
						&& 'audit@example.com' === $message->bcc()[0]['email']
						&& array( array( 'path' => $file, 'name' => 'a.pdf' ) ) === $message->attachments();
				}
			)
		)->andReturn( SendResult::success() );

		$result = $this->dispatcher()->resend(
			$this->entry(
				array(
					'attachments' => array(
						array( 'path' => $file, 'name' => 'a.pdf' ),
						array( 'path' => '/gone/b.pdf', 'name' => 'b.pdf' ),
						'junk',
					),
				)
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 'resend', $this->logged[0]['source'] );
	}

	public function test_redacted_entries_cannot_be_resent() {
		$this->assertStringContainsString( 'cannot be resent', $this->dispatcher()->resend( $this->entry( array( 'redacted' => true ) ) ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_incomplete_entries_cannot_be_resent() {
		$this->assertStringContainsString( 'incomplete', $this->dispatcher()->resend( $this->entry( array( 'to_addresses' => array(), 'headers' => array() ) ) ) );
		$this->assertStringContainsString( 'incomplete', $this->dispatcher()->resend( $this->entry( array( 'headers' => 'corrupt' ) ) ) );
	}

	public function test_failed_resend_reports_an_error() {
		$this->transport( 'smtp', SendResult::failure( 'still down' ) );
		$this->alerts->shouldReceive( 'notify' )->once();
		$this->assertSame( 'Resending failed. The new attempt has been logged.', $this->dispatcher()->resend( $this->entry() ) );
	}
}
