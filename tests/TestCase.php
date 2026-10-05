<?php
/**
 * Base test case.
 *
 * @package CodoDigital\Mailer\Tests
 */

namespace CodoDigital\Mailer\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CodoDigital\Mailer\Mail\Message;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

/**
 * Sets up Brain Monkey and common WordPress function stubs.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Files created during a test.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'wp_json_encode'        => static function ( $data ) {
					return json_encode( $data );
				},
				'wp_parse_url'          => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component );
				},
				'is_email'              => static function ( $email ) {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
				},
				'sanitize_email'        => static function ( $email ) {
					return trim( (string) $email );
				},
				'sanitize_text_field'   => static function ( $text ) {
					return trim( strip_tags( (string) $text ) );
				},
				'sanitize_key'          => static function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'wp_unslash'            => static function ( $value ) {
					return $value;
				},
				'absint'                => static function ( $value ) {
					return abs( (int) $value );
				},
				'wp_strip_all_tags'     => static function ( $text ) {
					return strip_tags( (string) $text );
				},
				'wp_specialchars_decode' => static function ( $text ) {
					return htmlspecialchars_decode( (string) $text, ENT_QUOTES );
				},
				'network_home_url'      => 'https://www.example.com',
				'home_url'              => 'https://www.example.com/',
				'admin_url'             => static function ( $path = '' ) {
					return 'https://www.example.com/wp-admin/' . ltrim( $path, '/' );
				},
				'get_bloginfo'          => static function ( $show = '' ) {
					return 'charset' === $show ? 'UTF-8' : 'Example & Co';
				},
				'wp_salt'               => static function ( $scheme = 'auth' ) {
					return 'salt-' . $scheme;
				},
				'is_wp_error'           => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
				'wp_check_filetype'     => static function ( $name ) {
					$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
					$map = array(
						'pdf' => 'application/pdf',
						'txt' => 'text/plain',
					);
					return array(
						'ext'  => $ext,
						'type' => isset( $map[ $ext ] ) ? $map[ $ext ] : false,
					);
				},
				'add_query_arg'         => static function ( $args, $url ) {
					return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
				},
				'plugin_basename'       => 'codo-mailer/codo-mailer.php',
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Create a temporary file that is removed after the test.
	 *
	 * @param string $contents Contents.
	 * @param string $suffix   File suffix.
	 * @return string Path.
	 */
	protected function temp_file( $contents, $suffix = '.txt' ) {
		$path = tempnam( sys_get_temp_dir(), 'codo' ) . $suffix;
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;
		return $path;
	}

	/**
	 * A representative message.
	 *
	 * @param array<string, mixed> $overrides Parts to change.
	 * @return Message
	 */
	protected function message( array $overrides = array() ) {
		return new Message(
			array_merge(
				array(
					'from_email'   => 'site@example.com',
					'from_name'    => 'Example Site',
					'to'           => array(
						array(
							'email' => 'jane@example.org',
							'name'  => 'Jane Doe',
						),
					),
					'subject'      => 'Hello',
					'body'         => 'Plain body',
					'content_type' => 'text/plain',
					'charset'      => 'UTF-8',
				),
				$overrides
			)
		);
	}
}
