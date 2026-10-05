<?php
/**
 * Plugin Name:       Codo Mailer
 * Plugin URI:        https://github.com/Codo-Digital-Ltd/codo-mailer
 * Description:       Secure-by-default email delivery for WordPress: SMTP, Amazon SES, Postmark, Mailgun, Brevo and SendGrid, with an email log, resend, backup connection and failure alerts. Free, no upsells.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Codo Digital
 * Author URI:        https://cododigital.co.uk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codo-mailer
 *
 * @package CodoDigital\Mailer
 */

defined( 'ABSPATH' ) || exit;

define( 'CODO_MAILER_VERSION', '1.0.0' );
define( 'CODO_MAILER_FILE', __FILE__ );
define( 'CODO_MAILER_DIR', __DIR__ );

require_once __DIR__ . '/src/Autoloader.php';

\CodoDigital\Mailer\Autoloader::register( __DIR__ . '/src' );

register_activation_hook( __FILE__, array( \CodoDigital\Mailer\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CodoDigital\Mailer\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \CodoDigital\Mailer\Plugin::class, 'boot' ) );
