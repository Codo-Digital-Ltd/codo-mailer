<?php
/**
 * PHPUnit bootstrap: unit tests run without WordPress, with core functions
 * mocked by Brain Monkey.
 *
 * @package CodoDigital\Mailer\Tests
 */

define( 'ABSPATH', __DIR__ . '/fixtures/wordpress/' );
define( 'WPINC', 'wp-includes' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
define( 'DAY_IN_SECONDS', 86400 );
define( 'CODO_MAILER_VERSION', '1.0.0-test' );
define( 'CODO_MAILER_FILE', dirname( __DIR__ ) . '/codo-mailer.php' );
define( 'CODO_MAILER_DIR', dirname( __DIR__ ) );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/class-wp-error.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

\CodoDigital\Mailer\Autoloader::register( dirname( __DIR__ ) . '/src' );
