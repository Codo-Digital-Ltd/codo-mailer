<?php
/**
 * Plugin settings, with wp-config.php constant overrides.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Settings;

use CodoDigital\Mailer\Security\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitises and stores settings.
 *
 * Any value can be fixed in wp-config.php with a constant, e.g.
 * CODO_MAILER_FROM_EMAIL or CODO_MAILER_PRIMARY_API_KEY. Constants win over
 * the database and are shown read-only in the admin screen, which lets a
 * host keep credentials out of the database entirely.
 */
class Settings {

	const OPTION = 'codo_mailer_settings';

	const SLOTS = array( 'primary', 'backup' );

	/**
	 * Secret encryption.
	 *
	 * @var Crypto
	 */
	private $crypto;

	/**
	 * Reads a constant by name, returning null when undefined.
	 *
	 * @var callable
	 */
	private $constant_reader;

	/**
	 * Constructor.
	 *
	 * @param Crypto        $crypto          Secret encryption.
	 * @param callable|null $constant_reader fn( string $name ): mixed|null.
	 */
	public function __construct( Crypto $crypto, $constant_reader = null ) {
		$this->crypto          = $crypto;
		$this->constant_reader = null !== $constant_reader ? $constant_reader : array( __CLASS__, 'read_constant' );
	}

	/**
	 * Default constant reader.
	 *
	 * @param string $name Constant name.
	 * @return mixed|null
	 */
	public static function read_constant( $name ) {
		return defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * Supported connection types and their fields.
	 *
	 * @return array<string, array{label: string, fields: array<string, array{label: string, type: string, default?: mixed, options?: array<string, string>}>}>
	 */
	public static function connection_types() {
		return array(
			'smtp'     => array(
				'label'  => 'SMTP',
				'fields' => array(
					'host'       => array(
						'label' => 'SMTP host',
						'type'  => 'text',
					),
					'port'       => array(
						'label'   => 'Port',
						'type'    => 'int',
						'default' => 587,
					),
					'encryption' => array(
						'label'   => 'Encryption',
						'type'    => 'select',
						'default' => 'tls',
						'options' => array(
							'tls'  => 'STARTTLS (port 587)',
							'ssl'  => 'SSL/TLS (port 465)',
							'none' => 'None',
						),
					),
					'auth'       => array(
						'label'   => 'Authenticate',
						'type'    => 'bool',
						'default' => true,
					),
					'username'   => array(
						'label' => 'Username',
						'type'  => 'text',
					),
					'password'   => array(
						'label' => 'Password',
						'type'  => 'secret',
					),
				),
			),
			'ses'      => array(
				'label'  => 'Amazon SES',
				'fields' => array(
					'region'     => array(
						'label'   => 'Region',
						'type'    => 'text',
						'default' => 'eu-west-2',
					),
					'access_key' => array(
						'label' => 'Access key ID',
						'type'  => 'text',
					),
					'secret_key' => array(
						'label' => 'Secret access key',
						'type'  => 'secret',
					),
				),
			),
			'postmark' => array(
				'label'  => 'Postmark',
				'fields' => array(
					'server_token'   => array(
						'label' => 'Server API token',
						'type'  => 'secret',
					),
					'message_stream' => array(
						'label'   => 'Message stream',
						'type'    => 'text',
						'default' => 'outbound',
					),
				),
			),
			'mailgun'  => array(
				'label'  => 'Mailgun',
				'fields' => array(
					'domain'  => array(
						'label' => 'Sending domain',
						'type'  => 'text',
					),
					'api_key' => array(
						'label' => 'API key',
						'type'  => 'secret',
					),
					'region'  => array(
						'label'   => 'Region',
						'type'    => 'select',
						'default' => 'eu',
						'options' => array(
							'eu' => 'EU',
							'us' => 'US',
						),
					),
				),
			),
			'brevo'    => array(
				'label'  => 'Brevo',
				'fields' => array(
					'api_key' => array(
						'label' => 'API key',
						'type'  => 'secret',
					),
				),
			),
			'sendgrid' => array(
				'label'  => 'SendGrid',
				'fields' => array(
					'api_key' => array(
						'label' => 'API key',
						'type'  => 'secret',
					),
				),
			),
		);
	}

	/**
	 * Default general settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'from_email'         => '',
			'from_name'          => '',
			'force_from'         => false,
			'primary'            => array( 'type' => 'none' ),
			'backup'             => array( 'type' => 'none' ),
			'log_enabled'        => true,
			'log_retention_days' => 30,
			'redact_sensitive'   => true,
			'alert_email'        => '',
			'alert_webhook'      => '',
		);
	}

	/**
	 * Stored settings merged with defaults (secrets still encrypted).
	 *
	 * @return array<string, mixed>
	 */
	public function stored() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Effective value of a general setting (constant wins).
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$defaults = self::defaults();
		$constant = $this->constant( $key );
		$value    = null !== $constant ? $constant : ( array_key_exists( $key, $this->stored() ) ? $this->stored()[ $key ] : null );

		if ( ! array_key_exists( $key, $defaults ) ) {
			return $value;
		}
		if ( is_bool( $defaults[ $key ] ) ) {
			return (bool) $value;
		}
		if ( is_int( $defaults[ $key ] ) ) {
			return (int) $value;
		}
		return is_scalar( $value ) ? (string) $value : $defaults[ $key ];
	}

	/**
	 * Whether a general setting is fixed by a constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_constant( $key ) {
		return null !== $this->constant( $key );
	}

	/**
	 * Whether a connection field is fixed by a constant.
	 *
	 * @param string $slot  primary|backup.
	 * @param string $field Field key, or 'type'.
	 * @return bool
	 */
	public function is_connection_constant( $slot, $field ) {
		return null !== $this->constant( $slot . '_' . $field );
	}

	/**
	 * Effective, decrypted connection configuration for a slot.
	 *
	 * @param string $slot primary|backup.
	 * @return array<string, mixed> Always includes 'type' ('none' when unset).
	 */
	public function connection( $slot ) {
		$stored = $this->stored();
		$config = isset( $stored[ $slot ] ) && is_array( $stored[ $slot ] ) ? $stored[ $slot ] : array();

		$type_constant = $this->constant( $slot . '_type' );
		$type          = null !== $type_constant ? (string) $type_constant : ( isset( $config['type'] ) ? (string) $config['type'] : 'none' );
		$types         = self::connection_types();

		if ( ! isset( $types[ $type ] ) ) {
			return array( 'type' => 'none' );
		}

		$result = array( 'type' => $type );
		foreach ( $types[ $type ]['fields'] as $field => $meta ) {
			$constant = $this->constant( $slot . '_' . $field );
			if ( null !== $constant ) {
				$value = $constant;
			} elseif ( array_key_exists( $field, $config ) ) {
				$value = 'secret' === $meta['type'] ? $this->crypto->decrypt( (string) $config[ $field ] ) : $config[ $field ];
			} else {
				$value = isset( $meta['default'] ) ? $meta['default'] : '';
			}
			$result[ $field ] = $this->cast( $meta, $value );
		}

		return $result;
	}

	/**
	 * Sanitise submitted settings into storable form.
	 *
	 * Empty secret fields keep the previously stored secret, so the admin
	 * screen never needs to print a secret back into the page.
	 *
	 * @param array<string, mixed> $input Raw input (already unslashed).
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ) {
		$current = $this->stored();
		$clean   = self::defaults();

		$from_email          = isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '';
		$clean['from_email'] = is_email( $from_email ) ? $from_email : '';
		$clean['from_name']  = isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '';
		$clean['force_from'] = ! empty( $input['force_from'] );

		$clean['log_enabled']        = ! empty( $input['log_enabled'] );
		$clean['redact_sensitive']   = ! empty( $input['redact_sensitive'] );
		$days                        = isset( $input['log_retention_days'] ) ? (int) $input['log_retention_days'] : 30;
		$clean['log_retention_days'] = max( 1, min( 365, $days ) );

		$alert_email          = isset( $input['alert_email'] ) ? sanitize_email( (string) $input['alert_email'] ) : '';
		$clean['alert_email'] = is_email( $alert_email ) ? $alert_email : '';

		$webhook                = isset( $input['alert_webhook'] ) ? esc_url_raw( trim( (string) $input['alert_webhook'] ), array( 'https' ) ) : '';
		$clean['alert_webhook'] = 0 === strpos( $webhook, 'https://' ) ? $webhook : '';

		foreach ( self::SLOTS as $slot ) {
			$submitted      = isset( $input[ $slot ] ) && is_array( $input[ $slot ] ) ? $input[ $slot ] : array();
			$previous       = isset( $current[ $slot ] ) && is_array( $current[ $slot ] ) ? $current[ $slot ] : array();
			$clean[ $slot ] = $this->sanitize_connection( $submitted, $previous );
		}

		return $clean;
	}

	/**
	 * Sanitise and persist settings.
	 *
	 * @param array<string, mixed> $input Raw input (already unslashed).
	 * @return array<string, mixed> What was stored.
	 */
	public function save( array $input ) {
		$clean = $this->sanitize( $input );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Sanitise one connection block.
	 *
	 * @param array<string, mixed> $submitted Submitted values.
	 * @param array<string, mixed> $previous  Previously stored values.
	 * @return array<string, mixed>
	 */
	private function sanitize_connection( array $submitted, array $previous ) {
		$types = self::connection_types();
		$type  = isset( $submitted['type'] ) ? sanitize_key( (string) $submitted['type'] ) : 'none';

		if ( ! isset( $types[ $type ] ) ) {
			return array( 'type' => 'none' );
		}

		$same_type = isset( $previous['type'] ) && $previous['type'] === $type;
		$clean     = array( 'type' => $type );

		foreach ( $types[ $type ]['fields'] as $field => $meta ) {
			$raw = isset( $submitted[ $field ] ) ? $submitted[ $field ] : null;

			if ( 'secret' === $meta['type'] ) {
				$raw = is_string( $raw ) ? trim( $raw ) : '';
				if ( '' === $raw ) {
					$clean[ $field ] = $same_type && isset( $previous[ $field ] ) ? (string) $previous[ $field ] : '';
				} else {
					$clean[ $field ] = $this->crypto->encrypt( $raw );
				}
				continue;
			}

			if ( 'bool' === $meta['type'] ) {
				$clean[ $field ] = ! empty( $raw );
				continue;
			}

			if ( null === $raw ) {
				$raw = isset( $meta['default'] ) ? $meta['default'] : '';
			}
			$clean[ $field ] = $this->cast( $meta, is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '' );
		}

		return $clean;
	}

	/**
	 * Cast a value to its field type.
	 *
	 * @param array<string, mixed> $meta  Field metadata.
	 * @param mixed                $value Value.
	 * @return mixed
	 */
	private function cast( array $meta, $value ) {
		switch ( $meta['type'] ) {
			case 'int':
				$int = (int) $value;
				return $int > 0 && $int <= 65535 ? $int : (int) $meta['default'];
			case 'bool':
				return (bool) $value;
			case 'select':
				$value = (string) $value;
				return isset( $meta['options'][ $value ] ) ? $value : (string) $meta['default'];
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Read a CODO_MAILER_* constant for a setting key.
	 *
	 * @param string $key Setting key, e.g. from_email or primary_api_key.
	 * @return mixed|null
	 */
	private function constant( $key ) {
		return call_user_func( $this->constant_reader, 'CODO_MAILER_' . strtoupper( $key ) );
	}
}
