<?php
namespace CodoDigital\Mailer\Tests\Unit\Alerts;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use CodoDigital\Mailer\Alerts\AlertNotifier;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Tests\TestCase;
use CodoDigital\Mailer\Transport\HttpClient;
use CodoDigital\Mailer\Transport\TransportException;

class AlertNotifierTest extends TestCase {

	/** @var array<string, mixed> */
	private $config = array();

	/** @var array<string, mixed> */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->transients[ $key ] = array( $value, $ttl );
				return true;
			}
		);
	}

	private function notifier( $http = null ) {
		$settings = \Mockery::mock( Settings::class );
		$settings->shouldReceive( 'get' )->andReturnUsing(
			function ( $key ) {
				return isset( $this->config[ $key ] ) ? $this->config[ $key ] : '';
			}
		);
		return new AlertNotifier( $settings, $http ? $http : \Mockery::mock( HttpClient::class ) );
	}

	public function test_nothing_configured_means_no_alert_and_no_lock() {
		$this->assertSame( array(), $this->notifier()->notify( $this->message(), 'boom' ) );
		$this->assertSame( array(), $this->transients );
	}

	public function test_email_alert_is_sent_and_throttled() {
		$this->config = array( 'alert_email' => 'ops@example.com' );

		Functions\expect( 'wp_mail' )->once()->with(
			'ops@example.com',
			'[Example & Co] Email delivery is failing',
			\Mockery::on(
				function ( $body ) {
					$this->assertTrue( AlertNotifier::is_sending(), 'recursion guard is up while sending' );
					return false !== strpos( $body, 'could not send "Hello": boom' )
						&& false !== strpos( $body, 'page=codo-mailer&tab=log&status=failed' );
				}
			)
		)->andReturn( true );

		$this->assertSame( array( 'email' ), $this->notifier()->notify( $this->message(), 'boom' ) );
		$this->assertFalse( AlertNotifier::is_sending() );
		$this->assertSame( HOUR_IN_SECONDS, $this->transients[ AlertNotifier::LOCK_TRANSIENT ][1] );

		// Second failure inside the window: throttled.
		$this->assertSame( array(), $this->notifier()->notify( $this->message(), 'again' ) );
	}

	public function test_guard_is_released_even_if_wp_mail_throws() {
		$this->config = array( 'alert_email' => 'ops@example.com' );
		Functions\when( 'wp_mail' )->alias(
			static function () {
				throw new \RuntimeException( 'fatal in another plugin' );
			}
		);

		try {
			$this->notifier()->notify( $this->message(), 'boom' );
			$this->fail( 'Exception expected' );
		} catch ( \RuntimeException $e ) {
			$this->assertFalse( AlertNotifier::is_sending() );
		}
	}

	public function test_failed_alert_email_is_not_reported_as_sent() {
		$this->config = array( 'alert_email' => 'ops@example.com' );
		Functions\when( 'wp_mail' )->justReturn( false );
		$this->assertSame( array(), $this->notifier()->notify( null, 'Could not build message' ) );
	}

	public function test_webhook_alert_posts_slack_compatible_json() {
		$this->config = array( 'alert_webhook' => 'https://hooks.example.com/x' );
		Filters\expectApplied( 'codo_mailer_alert_interval' )->andReturn( 5 );

		$http = \Mockery::mock( HttpClient::class );
		$http->shouldReceive( 'request' )->once()->with(
			'POST',
			'https://hooks.example.com/x',
			array( 'Content-Type' => 'application/json' ),
			\Mockery::on(
				static function ( $json ) {
					$data = json_decode( $json, true );
					return 'Codo Mailer on Example & Co could not send "": API down' === $data['text']
						&& 'API down' === $data['error']
						&& 'https://www.example.com/' === $data['url'];
				}
			),
			10
		)->andReturn( array( 'status' => 200, 'body' => 'ok' ) );

		$this->assertSame( array( 'webhook' ), $this->notifier( $http )->notify( null, 'API down' ) );
		$this->assertSame( 60, $this->transients[ AlertNotifier::LOCK_TRANSIENT ][1], 'interval has a 60s floor' );
	}

	public function test_webhook_http_error_and_network_error_are_not_reported_as_sent() {
		$this->config = array( 'alert_webhook' => 'https://hooks.example.com/x' );

		$http = \Mockery::mock( HttpClient::class );
		$http->shouldReceive( 'request' )->andReturn( array( 'status' => 500, 'body' => '' ) );
		$this->assertSame( array(), $this->notifier( $http )->notify( null, 'x' ) );

		$this->transients = array();
		$broken           = \Mockery::mock( HttpClient::class );
		$broken->shouldReceive( 'request' )->andThrow( new TransportException( 'down' ) );
		$this->assertSame( array(), $this->notifier( $broken )->notify( null, 'x' ) );
	}

	public function test_both_channels() {
		$this->config = array(
			'alert_email'   => 'ops@example.com',
			'alert_webhook' => 'https://hooks.example.com/x',
		);
		Functions\when( 'wp_mail' )->justReturn( true );
		$http = \Mockery::mock( HttpClient::class );
		$http->shouldReceive( 'request' )->andReturn( array( 'status' => 204, 'body' => '' ) );

		$this->assertSame( array( 'email', 'webhook' ), $this->notifier( $http )->notify( $this->message(), 'x' ) );
	}

	public function test_no_alert_while_an_alert_is_already_sending() {
		$this->config = array( 'alert_email' => 'ops@example.com' );
		$notifier     = $this->notifier();

		Functions\expect( 'wp_mail' )->once()->andReturnUsing(
			function () use ( $notifier ) {
				// The alert itself fails and the dispatcher asks for another alert.
				$this->assertSame( array(), $notifier->notify( null, 'nested' ) );
				return false;
			}
		);

		$notifier->notify( $this->message(), 'outer' );
	}
}
