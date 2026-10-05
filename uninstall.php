<?php
/**
 * Removes all Codo Mailer data when the plugin is deleted.
 *
 * @package CodoDigital\Mailer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'CODO_MAILER_FILE' ) ) {
	define( 'CODO_MAILER_FILE', __DIR__ . '/codo-mailer.php' );
}

require_once __DIR__ . '/src/Autoloader.php';
\CodoDigital\Mailer\Autoloader::register( __DIR__ . '/src' );

/*
 * Multisite: clean every site, since each has its own table and option.
 */
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $codo_mailer_site_id ) {
		switch_to_blog( $codo_mailer_site_id );
		\CodoDigital\Mailer\Plugin::uninstall();
		restore_current_blog();
	}
} else {
	\CodoDigital\Mailer\Plugin::uninstall();
}
