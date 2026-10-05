<?php
namespace CodoDigital\Mailer\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use CodoDigital\Mailer\Security\Crypto;
use CodoDigital\Mailer\Settings\Settings;
use CodoDigital\Mailer\Tests\TestCase;

class SettingsTest extends TestCase {

	/** @var array<string, mixed> */
	private $option = array();

	/** @var array<string, mixed> */
	private $constants = array();

	/** @var Crypto */
	private $crypto;

	protected function setUp(): void {
		parent::setUp();
		$this->crypto = new Crypto( 'test-key' );
		Functions\when( 'get_option' )->alias(
			function () {
				return $this->option;
			}
		);
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
			}
		);
	}

	private function settings() {
		return new Settings(
			$this->crypto,
			function ( $name ) {
				return array_key_exists( $name, $this->constants ) ? $this->constants[ $name ] : null;
			}
		);
	}

	public function test_defaults_when_nothing_stored() {
		$this->option = 'corrupt';
		$settings     = $this->settings();

		$this->assertSame( '', $settings->get( 'from_email' ) );
		$this->assertTrue( $settings->get( 'log_enabled' ) );
		$this->assertSame( 30, $settings->get( 'log_retention_days' ) );
		$this->assertSame( array( 'type' => 'none' ), $settings->connection( 'primary' ) );
		$this->assertNull( $settings->get( 'unknown_key' ) );
	}

	public function test_get_casts_by_default_type() {
		$this->option = array(
			'force_from'         => '1',
			'log_retention_days' => '14',
			'from_name'          => array( 'not', 'scalar' ),
		);
		$settings     = $this->settings();

		$this->assertTrue( $settings->get( 'force_from' ) );
		$this->assertSame( 14, $settings->get( 'log_retention_days' ) );
		$this->assertSame( '', $settings->get( 'from_name' ) );
	}

	public function test_constants_override_stored_values() {
		$this->option    = array( 'from_email' => 'db@example.com' );
		$this->constants = array( 'CODO_MAILER_FROM_EMAIL' => 'const@example.com' );
		$settings        = $this->settings();

		$this->assertSame( 'const@example.com', $settings->get( 'from_email' ) );
		$this->assertTrue( $settings->is_constant( 'from_email' ) );
		$this->assertFalse( $settings->is_constant( 'from_name' ) );
	}

	public function test_default_constant_reader() {
		$this->assertNull( Settings::read_constant( 'CODO_MAILER_SURELY_UNDEFINED' ) );
		$this->assertSame( 'ARRAY_A', Settings::read_constant( 'ARRAY_A' ) );
		$this->assertNull( ( new Settings( $this->crypto ) )->get( 'unknown' ) );
	}

	public function test_connection_decrypts_secrets_and_applies_defaults() {
		$this->option = array(
			'primary' => array(
				'type'     => 'smtp',
				'host'     => 'smtp.example.com',
				'password' => $this->crypto->encrypt( 'hunter2' ),
			),
		);

		$connection = $this->settings()->connection( 'primary' );

		$this->assertSame( 'smtp', $connection['type'] );
		$this->assertSame( 'smtp.example.com', $connection['host'] );
		$this->assertSame( 'hunter2', $connection['password'] );
		$this->assertSame( 587, $connection['port'] );
		$this->assertSame( 'tls', $connection['encryption'] );
		$this->assertTrue( $connection['auth'] );
		$this->assertSame( '', $connection['username'] );
	}

	public function test_connection_constants_override_and_report() {
		$this->option    = array( 'backup' => array( 'type' => 'ses', 'region' => 'us-east-1' ) );
		$this->constants = array(
			'CODO_MAILER_BACKUP_REGION'     => 'eu-west-2',
			'CODO_MAILER_BACKUP_SECRET_KEY' => 'from-config',
		);
		$settings        = $this->settings();
		$connection      = $settings->connection( 'backup' );

		$this->assertSame( 'eu-west-2', $connection['region'] );
		$this->assertSame( 'from-config', $connection['secret_key'] );
		$this->assertTrue( $settings->is_connection_constant( 'backup', 'secret_key' ) );
		$this->assertFalse( $settings->is_connection_constant( 'backup', 'access_key' ) );
	}

	public function test_type_constant_configures_a_connection_without_the_database() {
		$this->constants = array(
			'CODO_MAILER_PRIMARY_TYPE'    => 'postmark',
			'CODO_MAILER_PRIMARY_SERVER_TOKEN' => 'pm-token',
		);
		$connection      = $this->settings()->connection( 'primary' );

		$this->assertSame( 'postmark', $connection['type'] );
		$this->assertSame( 'pm-token', $connection['server_token'] );
		$this->assertSame( 'outbound', $connection['message_stream'] );
	}

	public function test_unknown_type_is_treated_as_none() {
		$this->option = array( 'primary' => array( 'type' => 'carrier-pigeon' ) );
		$this->assertSame( array( 'type' => 'none' ), $this->settings()->connection( 'primary' ) );
	}

	public function test_invalid_select_and_port_fall_back_to_defaults() {
		$this->option = array(
			'primary' => array(
				'type'       => 'smtp',
				'port'       => 99999,
				'encryption' => 'rot13',
				'host'       => array( 'x' ),
			),
		);
		$connection   = $this->settings()->connection( 'primary' );

		$this->assertSame( 587, $connection['port'] );
		$this->assertSame( 'tls', $connection['encryption'] );
		$this->assertSame( '', $connection['host'] );
	}

	public function test_sanitize_cleans_general_fields() {
		$clean = $this->settings()->sanitize(
			array(
				'from_email'         => 'not an email',
				'from_name'          => '<b>Shop</b>',
				'force_from'         => '1',
				'log_enabled'        => '',
				'redact_sensitive'   => '1',
				'log_retention_days' => '9999',
				'alert_email'        => 'ops@example.com',
				'alert_webhook'      => 'http://insecure.example.com/hook',
			)
		);

		$this->assertSame( '', $clean['from_email'] );
		$this->assertSame( 'Shop', $clean['from_name'] );
		$this->assertTrue( $clean['force_from'] );
		$this->assertFalse( $clean['log_enabled'] );
		$this->assertTrue( $clean['redact_sensitive'] );
		$this->assertSame( 365, $clean['log_retention_days'] );
		$this->assertSame( 'ops@example.com', $clean['alert_email'] );
		$this->assertSame( '', $clean['alert_webhook'], 'only https webhooks are accepted' );
	}

	public function test_sanitize_handles_missing_and_minimum_values() {
		$clean = $this->settings()->sanitize( array( 'log_retention_days' => '0', 'alert_email' => 'nope', 'primary' => 'not-an-array' ) );

		$this->assertSame( 1, $clean['log_retention_days'] );
		$this->assertSame( '', $clean['alert_email'] );
		$this->assertSame( '', $clean['alert_webhook'] );
		$this->assertSame( array( 'type' => 'none' ), $clean['primary'] );
		$this->assertSame( '', $clean['from_name'] );

		$this->assertSame( 30, $this->settings()->sanitize( array() )['log_retention_days'] );
	}

	public function test_sanitize_accepts_https_webhook() {
		$clean = $this->settings()->sanitize( array( 'alert_webhook' => ' https://hooks.slack.com/services/T/B/X ' ) );
		$this->assertSame( 'https://hooks.slack.com/services/T/B/X', $clean['alert_webhook'] );
	}

	public function test_sanitize_encrypts_new_secrets_and_keeps_existing_ones_when_blank() {
		$existing     = $this->crypto->encrypt( 'old-secret' );
		$this->option = array(
			'primary' => array( 'type' => 'sendgrid', 'api_key' => $existing ),
			'backup'  => array( 'type' => 'brevo', 'api_key' => $existing ),
		);

		$clean = $this->settings()->sanitize(
			array(
				'primary' => array( 'type' => 'sendgrid', 'api_key' => '   ' ),
				'backup'  => array( 'type' => 'brevo', 'api_key' => 'new-secret' ),
			)
		);

		$this->assertSame( $existing, $clean['primary']['api_key'] );
		$this->assertTrue( $this->crypto->is_encrypted( $clean['backup']['api_key'] ) );
		$this->assertSame( 'new-secret', $this->crypto->decrypt( $clean['backup']['api_key'] ) );
	}

	public function test_changing_type_does_not_carry_a_secret_across_providers() {
		$this->option = array( 'primary' => array( 'type' => 'brevo', 'api_key' => $this->crypto->encrypt( 'brevo-key' ) ) );

		$clean = $this->settings()->sanitize( array( 'primary' => array( 'type' => 'sendgrid', 'api_key' => '' ) ) );

		$this->assertSame( '', $clean['primary']['api_key'] );
	}

	public function test_sanitize_connection_fields_by_type() {
		$clean = $this->settings()->sanitize(
			array(
				'primary' => array(
					'type'       => 'SMTP',
					'host'       => ' smtp.example.com ',
					'port'       => '465',
					'encryption' => 'ssl',
					'username'   => array( 'bad' ),
				),
			)
		);

		$this->assertSame( 'smtp', $clean['primary']['type'] );
		$this->assertSame( 'smtp.example.com', $clean['primary']['host'] );
		$this->assertSame( 465, $clean['primary']['port'] );
		$this->assertSame( 'ssl', $clean['primary']['encryption'] );
		$this->assertFalse( $clean['primary']['auth'], 'unchecked box is false' );
		$this->assertSame( '', $clean['primary']['username'] );
		$this->assertSame( '', $clean['primary']['password'] );

		$this->assertSame( array( 'type' => 'none' ), $this->settings()->sanitize( array( 'backup' => array( 'type' => 'bogus' ) ) )['backup'] );
	}

	public function test_omitted_fields_get_their_defaults() {
		$clean = $this->settings()->sanitize( array( 'primary' => array( 'type' => 'smtp' ), 'backup' => array( 'type' => 'mailgun' ) ) );

		$this->assertSame( 587, $clean['primary']['port'] );
		$this->assertSame( 'tls', $clean['primary']['encryption'] );
		$this->assertSame( '', $clean['primary']['host'] );
		$this->assertSame( 'eu', $clean['backup']['region'] );
	}

	public function test_save_persists_without_autoload() {
		Functions\expect( 'update_option' )
			->once()
			->with( Settings::OPTION, \Mockery::type( 'array' ), false )
			->andReturn( true );

		$stored = $this->settings()->save( array( 'from_name' => 'Shop' ) );
		$this->assertSame( 'Shop', $stored['from_name'] );
	}

	public function test_every_connection_type_has_labelled_fields() {
		foreach ( Settings::connection_types() as $type => $meta ) {
			$this->assertNotEmpty( $meta['label'], $type );
			foreach ( $meta['fields'] as $field => $field_meta ) {
				$this->assertContains( $field_meta['type'], array( 'text', 'int', 'select', 'bool', 'secret' ), "$type.$field" );
			}
		}
	}
}
