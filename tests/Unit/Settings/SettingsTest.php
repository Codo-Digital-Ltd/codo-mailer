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

	/** @var mixed */
	private $pins = array();

	/** @var array<int, array{0: string, 1: mixed}> */
	private $updates = array();

	/** @var Crypto */
	private $crypto;

	protected function setUp(): void {
		parent::setUp();
		$this->crypto = new Crypto( 'test-key' );
		Functions\when( 'get_option' )->alias(
			function ( $name ) {
				return Settings::PINS_OPTION === $name ? $this->pins : $this->option;
			}
		);

		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
			}
		);
	}

	private function track_updates() {
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->updates[] = array( $name, $value );
				if ( Settings::PINS_OPTION === $name ) {
					$this->pins = $value;
				}
				return true;
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

	public function test_unknown_type_is_kept_so_sending_fails_instead_of_falling_back() {
		$this->option = array( 'primary' => array( 'type' => 'carrier-pigeon' ) );
		$this->assertSame( array( 'type' => 'carrier-pigeon' ), $this->settings()->connection( 'primary' ) );
	}

	public function test_type_constant_is_case_insensitive() {
		$this->constants = array(
			'CODO_MAILER_PRIMARY_TYPE'       => ' SES ',
			'CODO_MAILER_PRIMARY_SECRET_KEY' => 'k',
		);
		$this->assertSame( 'ses', $this->settings()->connection( 'primary' )['type'] );
	}

	public function test_empty_type_is_none() {
		$this->option = array( 'primary' => array( 'type' => '' ) );
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

	public function test_sanitize_encrypts_an_https_webhook() {
		$clean = $this->settings()->sanitize( array( 'alert_webhook' => ' https://hooks.slack.com/services/T/B/X ' ) );

		$this->assertTrue( $this->crypto->is_encrypted( $clean['alert_webhook'] ) );
		$this->assertSame( 'https://hooks.slack.com/services/T/B/X', $this->crypto->decrypt( $clean['alert_webhook'] ) );

		$this->option = $clean;
		$this->assertSame( 'https://hooks.slack.com/services/T/B/X', $this->settings()->get( 'alert_webhook' ) );
		$this->assertTrue( $this->settings()->has_saved_webhook() );
	}

	public function test_blank_webhook_keeps_the_saved_one_and_remove_clears_it() {
		$saved        = $this->crypto->encrypt( 'https://hooks.example.com/x' );
		$this->option = array( 'alert_webhook' => $saved );

		$this->assertSame( $saved, $this->settings()->sanitize( array( 'alert_webhook' => '  ' ) )['alert_webhook'] );
		$this->assertSame( '', $this->settings()->sanitize( array( 'alert_webhook' => '', 'alert_webhook_clear' => '1' ) )['alert_webhook'] );
		$this->assertSame( '', $this->settings()->sanitize( array( 'alert_webhook' => 'http://insecure.example.com' ) )['alert_webhook'] );
	}

	public function test_plaintext_webhook_from_1_0_0_is_read_and_encrypted() {
		$this->track_updates();
		$this->option = array( 'alert_webhook' => 'https://hooks.example.com/legacy' );

		$this->assertSame( 'https://hooks.example.com/legacy', $this->settings()->get( 'alert_webhook' ) );

		$kept = $this->settings()->sanitize( array( 'alert_webhook' => '' ) )['alert_webhook'];
		$this->assertSame( 'https://hooks.example.com/legacy', $this->crypto->decrypt( $kept ), 'resaving encrypts it' );

		$this->assertTrue( $this->settings()->encrypt_legacy_secrets() );
		$this->assertSame( Settings::OPTION, $this->updates[0][0] );
		$this->assertSame( 'https://hooks.example.com/legacy', $this->crypto->decrypt( $this->updates[0][1]['alert_webhook'] ) );
	}

	public function test_encrypt_legacy_secrets_does_nothing_when_already_encrypted_or_empty() {
		$this->track_updates();
		$this->option = array( 'alert_webhook' => $this->crypto->encrypt( 'https://hooks.example.com/x' ) );
		$this->assertFalse( $this->settings()->encrypt_legacy_secrets() );

		$this->option = array( 'alert_webhook' => '' );
		$this->assertFalse( $this->settings()->encrypt_legacy_secrets() );
		$this->assertFalse( $this->settings()->has_saved_webhook() );

		$this->option = 'corrupt';
		$this->assertFalse( $this->settings()->encrypt_legacy_secrets() );
		$this->assertSame( array(), $this->updates );
	}

	public function test_webhook_constant_wins_and_is_not_decrypted() {
		$this->constants = array( 'CODO_MAILER_ALERT_WEBHOOK' => 'https://hooks.example.com/const' );
		$this->assertSame( 'https://hooks.example.com/const', $this->settings()->get( 'alert_webhook' ) );
	}

	private function smtp_with_constant_password( array $overrides = array() ) {
		$this->constants = array( 'CODO_MAILER_PRIMARY_PASSWORD' => 'wp-config-pass' );
		$this->option    = array(
			'primary' => array_merge(
				array(
					'type'       => 'smtp',
					'host'       => 'smtp.example.com',
					'port'       => 587,
					'encryption' => 'tls',
					'auth'       => true,
					'username'   => 'u',
				),
				$overrides
			),
		);
	}

	public function test_constant_password_is_used_until_a_connection_is_pinned() {
		$this->smtp_with_constant_password();
		$config = $this->settings()->connection( 'primary' );

		$this->assertSame( 'wp-config-pass', $config['password'] );
		$this->assertArrayNotHasKey( 'error', $config );
	}

	public function test_pinned_constant_password_is_refused_for_a_different_server() {
		$this->track_updates();
		$this->smtp_with_constant_password();
		$settings = $this->settings();
		$settings->pin_connection( 'primary', $settings->connection( 'primary' ) );
		$this->assertArrayHasKey( 'primary', $this->pins );

		$this->assertSame( 'wp-config-pass', $this->settings()->connection( 'primary' )['password'], 'same server still works' );

		$changes = array(
			'host'       => 'attacker.example',
			'port'       => 2525,
			'encryption' => 'none',
			'username'   => 'x',
		);
		foreach ( $changes as $field => $value ) {
			$this->smtp_with_constant_password( array( $field => $value ) );
			$config = $this->settings()->connection( 'primary' );

			$this->assertSame( '', $config['password'], $field );
			$this->assertStringContainsString( 'CODO_MAILER_PRIMARY_HOST', $config['error'], $field );
		}
	}

	public function test_pin_is_written_once_and_not_for_stored_passwords() {
		$this->track_updates();
		$this->smtp_with_constant_password();
		$settings = $this->settings();
		$settings->pin_connection( 'primary', $settings->connection( 'primary' ) );
		$settings->pin_connection( 'primary', $settings->connection( 'primary' ) );
		$this->assertCount( 1, $this->updates, 'an existing pin is not rewritten' );

		$this->constants = array();
		$this->pins      = array();
		$this->updates   = array();
		$this->option    = array(
			'primary' => array(
				'type'     => 'smtp',
				'host'     => 'h',
				'password' => $this->crypto->encrypt( 'p' ),
			),
		);
		$settings->pin_connection( 'primary', $settings->connection( 'primary' ) );
		$settings->pin_connection( 'primary', array( 'type' => 'carrier-pigeon' ) );
		$settings->pin_connection( 'primary', array() );
		$this->assertSame( array(), $this->updates );
	}

	public function test_pin_is_dropped_once_the_server_is_also_set_in_wp_config() {
		$this->track_updates();
		$this->smtp_with_constant_password();
		$this->pins      = array(
			'primary' => 'stale',
			'backup'  => 'other',
		);
		$this->constants = array(
			'CODO_MAILER_PRIMARY_PASSWORD'   => 'wp-config-pass',
			'CODO_MAILER_PRIMARY_HOST'       => 'smtp.example.com',
			'CODO_MAILER_PRIMARY_PORT'       => 587,
			'CODO_MAILER_PRIMARY_ENCRYPTION' => 'tls',
			'CODO_MAILER_PRIMARY_USERNAME'   => 'u',
		);
		$settings        = $this->settings();
		$config          = $settings->connection( 'primary' );

		$this->assertArrayNotHasKey( 'error', $config, 'no pin check when every server field is a constant' );
		$settings->pin_connection( 'primary', $config );
		$this->assertSame( array( 'backup' => 'other' ), $this->pins );
	}

	public function test_corrupt_pins_option_is_ignored() {
		$this->smtp_with_constant_password();
		$this->pins = 'corrupt';
		$this->assertSame( 'wp-config-pass', $this->settings()->connection( 'primary' )['password'] );
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

	public function test_smtp_password_is_kept_while_the_server_is_unchanged() {
		$existing     = $this->crypto->encrypt( 'smtp-pass' );
		$server       = array( 'type' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => true, 'username' => 'u' );
		$this->option = array( 'primary' => $server + array( 'password' => $existing ) );

		$clean = $this->settings()->sanitize( array( 'primary' => $server + array( 'password' => '' ) ) );

		$this->assertSame( $existing, $clean['primary']['password'] );
	}

	/**
	 * @dataProvider provide_server_changes
	 */
	public function test_smtp_password_is_dropped_when_the_server_changes( $field, $value ) {
		$server       = array( 'type' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => true, 'username' => 'u' );
		$this->option = array( 'primary' => $server + array( 'password' => $this->crypto->encrypt( 'smtp-pass' ) ) );

		$submitted           = $server;
		$submitted[ $field ] = $value;
		$clean               = $this->settings()->sanitize( array( 'primary' => $submitted + array( 'password' => '' ) ) );

		$this->assertSame( '', $clean['primary']['password'] );
	}

	public function provide_server_changes() {
		return array(
			'host'       => array( 'host', 'attacker.example' ),
			'port'       => array( 'port', 2525 ),
			'encryption' => array( 'encryption', 'none' ),
			'username'   => array( 'username', 'other' ),
		);
	}

	public function test_new_smtp_password_is_stored_when_the_server_changes() {
		$server       = array( 'type' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'auth' => true, 'username' => 'u' );
		$this->option = array( 'primary' => $server + array( 'password' => $this->crypto->encrypt( 'old' ) ) );

		$clean = $this->settings()->sanitize( array( 'primary' => array( 'host' => 'new.example.com', 'password' => 'new' ) + $server ) );

		$this->assertSame( 'new', $this->crypto->decrypt( $clean['primary']['password'] ) );
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

	public function test_ses_session_token_is_an_optional_encrypted_secret() {
		$clean = $this->settings()->sanitize(
			array(
				'primary' => array(
					'type' => 'ses',
					'ses'  => array( 'region' => 'eu-west-2', 'access_key' => 'ASIATEMP', 'secret_key' => 's', 'session_token' => 'tok' ),
				),
			)
		);
		$this->assertSame( 'tok', $this->crypto->decrypt( $clean['primary']['session_token'] ) );

		$this->option = $clean;
		$this->assertSame( 'tok', $this->settings()->connection( 'primary' )['session_token'] );

		$this->option = array( 'primary' => array( 'type' => 'ses', 'access_key' => 'AKIA1' ) );
		$this->assertSame( '', $this->settings()->connection( 'primary' )['session_token'], 'optional: empty when never set' );
	}

	public function test_ses_session_token_can_be_set_in_wp_config() {
		$this->constants = array(
			'CODO_MAILER_PRIMARY_TYPE'          => 'ses',
			'CODO_MAILER_PRIMARY_SESSION_TOKEN' => 'const-token',
		);

		$settings = $this->settings();
		$this->assertSame( 'const-token', $settings->connection( 'primary' )['session_token'] );
		$this->assertTrue( $settings->is_connection_constant( 'primary', 'session_token' ) );
	}

	public function test_blank_session_token_is_kept_while_the_access_key_is_unchanged() {
		$token        = $this->crypto->encrypt( 'tok' );
		$this->option = array( 'primary' => array( 'type' => 'ses', 'region' => 'eu-west-2', 'access_key' => 'ASIA1', 'secret_key' => $this->crypto->encrypt( 's' ), 'session_token' => $token ) );

		$clean = $this->settings()->sanitize( array( 'primary' => array( 'type' => 'ses', 'region' => 'eu-west-2', 'access_key' => 'ASIA1' ) ) );

		$this->assertSame( $token, $clean['primary']['session_token'] );
	}

	public function test_saved_session_token_is_dropped_with_a_new_access_key_but_the_secret_key_is_kept() {
		$secret       = $this->crypto->encrypt( 's' );
		$this->option = array( 'primary' => array( 'type' => 'ses', 'region' => 'eu-west-2', 'access_key' => 'ASIA1', 'secret_key' => $secret, 'session_token' => $this->crypto->encrypt( 'tok' ) ) );

		$clean = $this->settings()->sanitize( array( 'primary' => array( 'type' => 'ses', 'region' => 'eu-west-2', 'access_key' => 'AKIA2' ) ) );

		$this->assertSame( '', $clean['primary']['session_token'], 'a token only works with the access key it was issued with' );
		$this->assertSame( $secret, $clean['primary']['secret_key'], 'unchanged behaviour for the secret key' );
	}

	public function test_new_session_token_is_stored_with_a_new_access_key() {
		$this->option = array( 'primary' => array( 'type' => 'ses', 'access_key' => 'ASIA1', 'session_token' => $this->crypto->encrypt( 'old' ) ) );

		$clean = $this->settings()->sanitize( array( 'primary' => array( 'type' => 'ses', 'access_key' => 'ASIA2', 'session_token' => 'new' ) ) );

		$this->assertSame( 'new', $this->crypto->decrypt( $clean['primary']['session_token'] ) );
	}

	public function test_remove_clears_a_saved_session_token() {
		$this->option = array( 'primary' => array( 'type' => 'ses', 'access_key' => 'ASIA1', 'session_token' => $this->crypto->encrypt( 'tok' ) ) );

		$clean = $this->settings()->sanitize(
			array(
				'primary' => array(
					'type' => 'ses',
					'ses'  => array( 'access_key' => 'ASIA1', 'session_token' => '', 'session_token_clear' => '1' ),
				),
			)
		);

		$this->assertSame( '', $clean['primary']['session_token'] );
	}

	public function test_remove_only_applies_to_clearable_secrets() {
		$secret       = $this->crypto->encrypt( 's' );
		$this->option = array( 'primary' => array( 'type' => 'ses', 'access_key' => 'ASIA1', 'secret_key' => $secret ) );

		$clean = $this->settings()->sanitize( array( 'primary' => array( 'type' => 'ses', 'access_key' => 'ASIA1', 'secret_key_clear' => '1' ) ) );

		$this->assertSame( $secret, $clean['primary']['secret_key'] );
	}
}
