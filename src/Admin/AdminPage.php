<?php
/**
 * Settings > Codo Mailer admin screen.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Admin;

use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Mail\Dispatcher;
use CodoDigital\Mailer\Mail\InvalidMessageException;
use CodoDigital\Mailer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the admin screen and handles its form posts.
 *
 * Every handler checks the manage_options capability and a nonce; the log
 * is only ever rendered server-side here, never exposed via REST or AJAX.
 */
class AdminPage {

	const SLUG     = 'codo-mailer';
	const PER_PAGE = 25;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Log storage.
	 *
	 * @var LogRepository
	 */
	private $log;

	/**
	 * Ends the request after a redirect.
	 *
	 * @var callable
	 */
	private $terminate;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings   Settings.
	 * @param Dispatcher    $dispatcher Dispatcher.
	 * @param LogRepository $log        Log storage.
	 * @param callable|null $terminate  Called after redirecting; defaults to exit.
	 */
	public function __construct( Settings $settings, Dispatcher $dispatcher, LogRepository $log, $terminate = null ) {
		$this->settings   = $settings;
		$this->dispatcher = $dispatcher;
		$this->log        = $log;
		$this->terminate  = null !== $terminate ? $terminate : array( __CLASS__, 'exit_request' );
	}

	/**
	 * Default terminator.
	 *
	 * @codeCoverageIgnore
	 * @return void
	 */
	public static function exit_request() {
		exit;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_codo_mailer_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_codo_mailer_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_codo_mailer_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_post_codo_mailer_clear_log', array( $this, 'handle_clear_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CODO_MAILER_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Capability needed to manage mail.
	 *
	 * On multisite this is a network capability: whoever controls the mail
	 * route (or turns off redaction) can read every password-reset email,
	 * including a super admin's, so subsite admins must not have it.
	 *
	 * @return string
	 */
	public function capability() {
		$default = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filters the capability required to manage Codo Mailer.
		 *
		 * @param string $capability Capability.
		 */
		$capability = apply_filters( 'codo_mailer_capability', $default );
		return is_string( $capability ) && '' !== $capability ? $capability : $default;
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Codo Mailer', 'codo-mailer' ),
			__( 'Codo Mailer', 'codo-mailer' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $this->url() ), esc_html__( 'Settings', 'codo-mailer' ) )
		);
		return $links;
	}

	/**
	 * Show/hide connection fields as the type changes.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_register_script( 'codo-mailer-admin', false, array(), CODO_MAILER_VERSION, true );
		wp_enqueue_script( 'codo-mailer-admin' );
		wp_add_inline_script(
			'codo-mailer-admin',
			"document.querySelectorAll('.codo-mailer-type').forEach(function(s){var f=function(){document.querySelectorAll('[data-codo-slot=\"'+s.dataset.slot+'\"]').forEach(function(el){el.hidden=el.dataset.codoType!==s.value;});};s.addEventListener('change',f);f();});"
		);
	}

	/**
	 * Admin URL for a tab.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string, mixed> $args Extra query args.
	 * @return string
	 */
	public function url( $tab = 'settings', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage email settings.', 'codo-mailer' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs = array(
			'settings' => __( 'Settings', 'codo-mailer' ),
			'test'     => __( 'Send a test', 'codo-mailer' ),
			'log'      => __( 'Email log', 'codo-mailer' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Codo Mailer', 'codo-mailer' ) . '</h1>';
		$this->render_notice();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $this->url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'test' === $tab ) {
			$this->render_test_tab();
		} elseif ( 'log' === $tab ) {
			$this->render_log_tab();
		} else {
			$this->render_settings_tab();
		}

		echo '</div>';
	}

	/**
	 * Show and clear the current user's one-off notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		$key    = $this->notice_key();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'success';
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="codo_mailer_save">';
		wp_nonce_field( 'codo_mailer_save' );

		echo '<h2>' . esc_html__( 'Sender', 'codo-mailer' ) . '</h2><table class="form-table" role="presentation">';
		$this->text_row( 'from_email', __( 'From email', 'codo-mailer' ), 'email', __( 'Use an address on a domain your provider has verified (SPF/DKIM).', 'codo-mailer' ) );
		$this->text_row( 'from_name', __( 'From name', 'codo-mailer' ) );
		$this->checkbox_row( 'force_from', __( 'Force sender', 'codo-mailer' ), __( 'Use these values even when another plugin sets its own From header.', 'codo-mailer' ) );
		echo '</table>';

		foreach ( Settings::SLOTS as $slot ) {
			$this->render_connection( $slot );
		}

		echo '<h2>' . esc_html__( 'Email log', 'codo-mailer' ) . '</h2><table class="form-table" role="presentation">';
		$this->checkbox_row( 'log_enabled', __( 'Log emails', 'codo-mailer' ), __( 'Keep a record of sent and failed emails so you can inspect and resend them.', 'codo-mailer' ) );
		$this->checkbox_row( 'redact_sensitive', __( 'Redact secrets', 'codo-mailer' ), __( 'Strip password-reset and one-time login links from logged emails (recommended).', 'codo-mailer' ) );
		$this->text_row( 'log_retention_days', __( 'Keep logs for (days)', 'codo-mailer' ), 'number' );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Failure alerts', 'codo-mailer' ) . '</h2><table class="form-table" role="presentation">';
		$this->text_row( 'alert_email', __( 'Alert email', 'codo-mailer' ), 'email', __( 'Sent through the backup connection if the primary is down. At most one alert per hour.', 'codo-mailer' ) );
		$this->text_row( 'alert_webhook', __( 'Alert webhook (https)', 'codo-mailer' ), 'url', __( 'Receives a JSON POST with a "text" field, so a Slack incoming webhook works as-is.', 'codo-mailer' ) );
		echo '</table>';

		submit_button( __( 'Save settings', 'codo-mailer' ) );
		echo '</form>';
	}

	/**
	 * One connection (primary or backup).
	 *
	 * @param string $slot primary|backup.
	 * @return void
	 */
	private function render_connection( $slot ) {
		$config = $this->settings->connection( $slot );
		$types  = Settings::connection_types();
		$locked = $this->settings->is_connection_constant( $slot, 'type' );
		$title  = 'primary' === $slot ? __( 'Primary connection', 'codo-mailer' ) : __( 'Backup connection', 'codo-mailer' );

		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( 'backup' === $slot ) {
			echo '<p>' . esc_html__( 'Used only when the primary connection fails.', 'codo-mailer' ) . '</p>';
		}
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="codo-mailer-' . esc_attr( $slot ) . '-type">' . esc_html__( 'Provider', 'codo-mailer' ) . '</label></th><td>';
		printf(
			'<select id="codo-mailer-%1$s-type" class="codo-mailer-type" data-slot="%1$s" name="codo_mailer[%1$s][type]"%2$s>',
			esc_attr( $slot ),
			disabled( $locked, true, false )
		);
		printf( '<option value="none"%s>%s</option>', selected( $config['type'], 'none', false ), esc_html__( 'None (use WordPress default)', 'codo-mailer' ) );
		foreach ( $types as $type => $meta ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $config['type'], $type, false ), esc_html( $meta['label'] ) );
		}
		echo '</select>';
		if ( $locked ) {
			printf( '<input type="hidden" name="codo_mailer[%s][type]" value="%s">', esc_attr( $slot ), esc_attr( $config['type'] ) );
			$this->constant_hint();
		}
		echo '</td></tr></table>';

		foreach ( $types as $type => $meta ) {
			printf( '<table class="form-table" role="presentation" data-codo-slot="%s" data-codo-type="%s">', esc_attr( $slot ), esc_attr( $type ) );
			$values = $config['type'] === $type ? $config : array();
			foreach ( $meta['fields'] as $field => $field_meta ) {
				$this->connection_field( $slot, $type, $field, $field_meta, $values );
			}
			echo '</table>';
		}
	}

	/**
	 * One connection field.
	 *
	 * @param string               $slot   Slot.
	 * @param string               $type   Connection type the field belongs to.
	 * @param string               $field  Field key.
	 * @param array<string, mixed> $meta   Field metadata.
	 * @param array<string, mixed> $values Current values (empty if another type is active).
	 * @return void
	 */
	private function connection_field( $slot, $type, $field, array $meta, array $values ) {
		// Every provider's fields are in the page (the type switcher hides the
		// others), so names carry the type: hidden inputs are still submitted.
		$id     = 'codo-mailer-' . $slot . '-' . $type . '-' . $field;
		$name   = 'codo_mailer[' . $slot . '][' . $type . '][' . $field . ']';
		$locked = $this->settings->is_connection_constant( $slot, $field );
		$value  = array_key_exists( $field, $values ) ? $values[ $field ] : ( isset( $meta['default'] ) ? $meta['default'] : '' );

		printf( '<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr( $id ), esc_html( $meta['label'] ) );

		switch ( $meta['type'] ) {
			case 'secret':
				$saved = '' !== (string) $value;
				printf(
					'<input type="password" id="%s" name="%s" class="regular-text" autocomplete="new-password" placeholder="%s"%s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $saved ? __( 'Saved. Leave blank to keep it.', 'codo-mailer' ) : '' ),
					disabled( $locked, true, false )
				);
				break;
			case 'bool':
				printf( '<input type="checkbox" id="%s" name="%s" value="1"%s%s>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ), disabled( $locked, true, false ) );
				break;
			case 'select':
				printf( '<select id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), disabled( $locked, true, false ) );
				foreach ( $meta['options'] as $option => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( (string) $value, $option, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;
			default:
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s>',
					'int' === $meta['type'] ? 'number' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					disabled( $locked, true, false )
				);
		}

		if ( $locked ) {
			$this->constant_hint();
		}
		echo '</td></tr>';
	}

	/**
	 * A text-like row for a general setting.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $type        Input type.
	 * @param string $description Help text.
	 * @return void
	 */
	private function text_row( $key, $label, $type = 'text', $description = '' ) {
		$locked = $this->settings->is_constant( $key );
		printf(
			'<tr><th scope="row"><label for="codo-mailer-%1$s">%2$s</label></th><td><input type="%3$s" id="codo-mailer-%1$s" name="codo_mailer[%1$s]" value="%4$s" class="regular-text"%5$s>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) $this->settings->get( $key ) ),
			disabled( $locked, true, false )
		);
		if ( $locked ) {
			$this->constant_hint();
		}
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * A checkbox row for a general setting.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $description Checkbox text.
	 * @return void
	 */
	private function checkbox_row( $key, $label, $description ) {
		$locked = $this->settings->is_constant( $key );
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="codo_mailer[%2$s]" value="1"%3$s%4$s> %5$s</label>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( (bool) $this->settings->get( $key ), true, false ),
			disabled( $locked, true, false ),
			esc_html( $description )
		);
		if ( $locked ) {
			$this->constant_hint();
		}
		echo '</td></tr>';
	}

	/**
	 * Note that a value comes from wp-config.php.
	 *
	 * @return void
	 */
	private function constant_hint() {
		echo ' <span class="description">' . esc_html__( 'Set in wp-config.php', 'codo-mailer' ) . '</span>';
	}

	/**
	 * Test email tab.
	 *
	 * @return void
	 */
	private function render_test_tab() {
		$current = wp_get_current_user();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="codo_mailer_test">';
		wp_nonce_field( 'codo_mailer_test' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="codo-mailer-test-to">' . esc_html__( 'Send to', 'codo-mailer' ) . '</label></th><td>';
		printf( '<input type="email" id="codo-mailer-test-to" name="to" value="%s" class="regular-text" required>', esc_attr( $current->user_email ) );
		echo '</td></tr></table>';
		submit_button( __( 'Send test email', 'codo-mailer' ) );
		echo '</form>';
	}

	/**
	 * Log tab: a single entry or the list.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$id     = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		if ( $id > 0 ) {
			$this->render_log_entry( $id );
			return;
		}

		if ( ! $this->settings->get( 'log_enabled' ) ) {
			echo '<p>' . esc_html__( 'Logging is turned off in Settings.', 'codo-mailer' ) . '</p>';
		}

		echo '<ul class="subsubsub">';
		foreach ( array(
			''       => __( 'All', 'codo-mailer' ),
			'sent'   => __( 'Sent', 'codo-mailer' ),
			'failed' => __( 'Failed', 'codo-mailer' ),
		) as $key => $label ) {
			printf(
				'<li><a href="%s"%s>%s <span class="count">(%d)</span></a>%s</li>',
				esc_url( $this->url( 'log', '' === $key ? array() : array( 'status' => $key ) ) ),
				$key === $status ? ' class="current"' : '',
				esc_html( $label ),
				(int) $this->log->count( $key ),
				'failed' === $key ? '' : ' | '
			);
		}
		echo '</ul>';

		$entries = $this->log->paginate( $page, self::PER_PAGE, $status );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date (UTC)', 'codo-mailer' ), __( 'Status', 'codo-mailer' ), __( 'To', 'codo-mailer' ), __( 'Subject', 'codo-mailer' ), __( 'Connection', 'codo-mailer' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $entries ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No emails logged yet.', 'codo-mailer' ) . '</td></tr>';
		}

		foreach ( $entries as $entry ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( $this->url( 'log', array( 'entry' => $entry['id'] ) ) ),
				esc_html( (string) $entry['created_at'] ),
				esc_html( $this->status_label( (string) $entry['status'] ) ),
				esc_html( $this->format_recipients( $entry['to_addresses'] ) ),
				esc_html( (string) $entry['subject'] ),
				esc_html( (string) $entry['connection'] )
			);
		}
		echo '</tbody></table>';

		$total = $this->log->count( $status );
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="tablenav-pages">';
			if ( $page > 1 ) {
				printf(
					'<a class="button" href="%s">&laquo; %s</a> ',
					esc_url(
						$this->url(
							'log',
							array(
								'status' => $status,
								'paged'  => $page - 1,
							)
						)
					),
					esc_html__( 'Newer', 'codo-mailer' )
				);
			}
			/* translators: 1: current page, 2: total pages. */
			echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'codo-mailer' ), $page, $pages ) );
			if ( $page < $pages ) {
				printf(
					' <a class="button" href="%s">%s &raquo;</a>',
					esc_url(
						$this->url(
							'log',
							array(
								'status' => $status,
								'paged'  => $page + 1,
							)
						)
					),
					esc_html__( 'Older', 'codo-mailer' )
				);
			}
			echo '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="codo_mailer_clear_log">';
		wp_nonce_field( 'codo_mailer_clear_log' );
		submit_button( __( 'Delete all log entries', 'codo-mailer' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * One log entry.
	 *
	 * @param int $id Entry ID.
	 * @return void
	 */
	private function render_log_entry( $id ) {
		$entry = $this->log->find( $id );
		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( $this->url( 'log' ) ), esc_html__( 'Back to the log', 'codo-mailer' ) );

		if ( null === $entry ) {
			echo '<p>' . esc_html__( 'Log entry not found.', 'codo-mailer' ) . '</p>';
			return;
		}

		$headers = $entry['headers'];
		$rows    = array(
			__( 'Date (UTC)', 'codo-mailer' ) => (string) $entry['created_at'],
			__( 'Status', 'codo-mailer' )     => $this->status_label( (string) $entry['status'] ),
			__( 'Connection', 'codo-mailer' ) => (string) $entry['connection'],
			__( 'From', 'codo-mailer' )       => isset( $headers['from_email'] ) ? (string) $headers['from_email'] : '',
			__( 'To', 'codo-mailer' )         => $this->format_recipients( $entry['to_addresses'] ),
			__( 'Cc', 'codo-mailer' )         => $this->format_recipients( isset( $headers['cc'] ) ? $headers['cc'] : array() ),
			__( 'Bcc', 'codo-mailer' )        => $this->format_recipients( isset( $headers['bcc'] ) ? $headers['bcc'] : array() ),
			__( 'Subject', 'codo-mailer' )    => (string) $entry['subject'],
			__( 'Error', 'codo-mailer' )      => (string) $entry['error'],
		);

		echo '<table class="form-table" role="presentation">';
		foreach ( $rows as $label => $value ) {
			if ( '' !== $value ) {
				printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
			}
		}
		echo '</table>';

		if ( $entry['redacted'] ) {
			echo '<p class="description">' . esc_html__( 'Secret links in this email were redacted before logging, so it cannot be resent.', 'codo-mailer' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Body', 'codo-mailer' ) . '</h2>';
		echo '<pre style="white-space:pre-wrap;max-height:30em;overflow:auto;background:#fff;padding:1em;border:1px solid #c3c4c7">' . esc_html( (string) $entry['body'] ) . '</pre>';

		if ( ! $entry['redacted'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="codo_mailer_resend">';
			printf( '<input type="hidden" name="entry" value="%d">', (int) $entry['id'] );
			wp_nonce_field( 'codo_mailer_resend_' . (int) $entry['id'] );
			submit_button( __( 'Resend this email', 'codo-mailer' ), 'secondary' );
			echo '</form>';
		}
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public function handle_save() {
		$this->guard( 'codo_mailer_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); sanitised field by field in Settings::sanitize().
		$input = isset( $_POST['codo_mailer'] ) && is_array( $_POST['codo_mailer'] ) ? wp_unslash( $_POST['codo_mailer'] ) : array();
		$this->settings->save( $input );
		$this->redirect( 'settings', 'success', __( 'Settings saved.', 'codo-mailer' ) );
	}

	/**
	 * Send a test email.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->guard( 'codo_mailer_test' );
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().

		if ( ! is_email( $to ) ) {
			$this->redirect( 'test', 'error', __( 'Enter a valid email address.', 'codo-mailer' ) );
			return;
		}

		if ( 'none' === $this->settings->connection( 'primary' )['type'] ) {
			$this->redirect( 'test', 'error', __( 'Choose and save a primary connection first.', 'codo-mailer' ) );
			return;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		try {
			$message = $this->dispatcher->message_factory()->from_wp_mail(
				array(
					'to'      => $to,
					/* translators: %s: site name. */
					'subject' => sprintf( __( 'Codo Mailer test from %s', 'codo-mailer' ), $site ),
					'message' => __( 'This test email was sent by Codo Mailer. If you can read it, email delivery from your site is working.', 'codo-mailer' ),
				)
			);
		} catch ( InvalidMessageException $e ) {
			$this->redirect( 'test', 'error', wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
			return;
		}

		if ( $this->dispatcher->send( $message, 'test' ) ) {
			/* translators: %s: recipient email. */
			$this->redirect( 'test', 'success', sprintf( __( 'Test email sent to %s.', 'codo-mailer' ), $to ) );
			return;
		}

		$this->redirect( 'log', 'error', __( 'The test email failed. The error is shown in the newest log entry.', 'codo-mailer' ), array( 'status' => 'failed' ) );
	}

	/**
	 * Resend a logged email.
	 *
	 * @return void
	 */
	public function handle_resend() {
		$id = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard() with an ID-specific action.
		$this->guard( 'codo_mailer_resend_' . $id );

		$entry = $this->log->find( $id );
		if ( null === $entry ) {
			$this->redirect( 'log', 'error', __( 'Log entry not found.', 'codo-mailer' ) );
			return;
		}

		$result = $this->dispatcher->resend( $entry );
		if ( true === $result ) {
			$this->redirect( 'log', 'success', __( 'Email resent.', 'codo-mailer' ) );
			return;
		}
		$this->redirect( 'log', 'error', $result, array( 'entry' => $id ) );
	}

	/**
	 * Delete all log entries.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		$this->guard( 'codo_mailer_clear_log' );
		$deleted = $this->log->clear();
		/* translators: %d: number of entries. */
		$this->redirect( 'log', 'success', sprintf( _n( 'Deleted %d log entry.', 'Deleted %d log entries.', $deleted, 'codo-mailer' ), $deleted ) );
	}

	/**
	 * Enforce capability and nonce, or stop.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function guard( $action ) {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage email settings.', 'codo-mailer' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Store a notice and redirect back to the screen.
	 *
	 * @param string               $tab     Tab.
	 * @param string               $type    success|error.
	 * @param string               $message Notice text (escaped on output).
	 * @param array<string, mixed> $args    Extra query args.
	 * @return void
	 */
	private function redirect( $tab, $type, $message, array $args = array() ) {
		set_transient(
			$this->notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( $this->url( $tab, $args ) );
		call_user_func( $this->terminate );
	}

	/**
	 * Per-user notice transient key.
	 *
	 * @return string
	 */
	private function notice_key() {
		return 'codo_mailer_notice_' . get_current_user_id();
	}

	/**
	 * Translated status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( $status ) {
		return LogRepository::STATUS_SENT === $status ? __( 'Sent', 'codo-mailer' ) : __( 'Failed', 'codo-mailer' );
	}

	/**
	 * Join addresses for display.
	 *
	 * @param mixed $addresses Address list.
	 * @return string
	 */
	private function format_recipients( $addresses ) {
		$emails = array();
		foreach ( (array) $addresses as $address ) {
			if ( is_array( $address ) && isset( $address['email'] ) ) {
				$emails[] = (string) $address['email'];
			}
		}
		return implode( ', ', $emails );
	}
}
