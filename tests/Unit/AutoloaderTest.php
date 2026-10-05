<?php
namespace CodoDigital\Mailer\Tests\Unit;

use CodoDigital\Mailer\Autoloader;
use CodoDigital\Mailer\Tests\TestCase;

class AutoloaderTest extends TestCase {

	public function test_loads_plugin_classes() {
		$this->assertTrue( Autoloader::load( 'CodoDigital\\Mailer\\Transport\\AwsSigV4' ) );
	}

	public function test_ignores_other_namespaces() {
		$this->assertFalse( Autoloader::load( 'Vendor\\Thing' ) );
	}

	public function test_rejects_path_traversal_and_odd_characters() {
		$this->assertFalse( Autoloader::load( 'CodoDigital\\Mailer\\..\\..\\etc\\passwd' ) );
		$this->assertFalse( Autoloader::load( 'CodoDigital\\Mailer\\Foo/Bar' ) );
	}

	public function test_register_is_idempotent() {
		$before = count( spl_autoload_functions() );
		Autoloader::register( dirname( __DIR__, 2 ) . '/src/' );
		$this->assertSame( $before, count( spl_autoload_functions() ) );
		$this->assertTrue( class_exists( 'CodoDigital\\Mailer\\Mail\\Redactor' ) );
	}

	public function test_missing_class_returns_false() {
		$this->assertFalse( Autoloader::load( 'CodoDigital\\Mailer\\DoesNotExist' ) );
	}
}
