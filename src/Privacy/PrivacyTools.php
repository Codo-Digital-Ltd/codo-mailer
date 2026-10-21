<?php
/**
 * Personal data export and erasure for the email log.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Privacy;

use CodoDigital\Mailer\Log\LogRepository;
use CodoDigital\Mailer\Mail\Redactor;
use CodoDigital\Mailer\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the email log to WordPress's privacy tools (Tools > Export
 * Personal Data and Tools > Erase Personal Data) and suggests privacy
 * policy text.
 *
 * Entries are found by exact address on To, Cc, Bcc or Reply-To. The
 * exporter never includes other people's addresses, and runs the redactor
 * over every subject and body, whatever the log's redaction setting, since
 * an export leaves the admin screen. The eraser deletes an entry only when
 * the person was its sole recipient; an email also sent to other people is
 * kept for them, with this person's address removed.
 */
class PrivacyTools {

	/**
	 * Exporter and eraser ID.
	 */
	const ID = 'codo-mailer';

	/**
	 * Export group ID.
	 */
	const GROUP = 'codo-mailer-log';

	/**
	 * Entries handled per request.
	 */
	const PER_PAGE = 50;

	/**
	 * Log storage.
	 *
	 * @var LogRepository
	 */
	private $log;

	/**
	 * Secret redaction.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $log      Log storage.
	 * @param Redactor      $redactor Secret redaction.
	 * @param Settings      $settings Settings.
	 */
	public function __construct( LogRepository $log, Redactor $redactor, Settings $settings ) {
		$this->log      = $log;
		$this->redactor = $redactor;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( $exporters ) {
		$exporters             = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Codo Mailer email log', 'codo-mailer' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( $erasers ) {
		$erasers             = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Codo Mailer email log', 'codo-mailer' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export one page of the log entries an address appears on.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ) {
		$ids  = $this->log->ids_for_address( $email );
		$page = max( 1, (int) $page );
		$data = array();

		foreach ( array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $id ) {
			$entry = $this->log->find( $id );
			$roles = is_array( $entry ) ? LogRepository::roles_of( $entry, $email ) : array();
			if ( empty( $roles ) ) {
				continue;
			}
			$data[] = array(
				'group_id'          => self::GROUP,
				'group_label'       => __( 'Email log', 'codo-mailer' ),
				'group_description' => __( 'Emails this site sent to you, or with your address as Reply-To, as recorded in its email log.', 'codo-mailer' ),
				'item_id'           => self::GROUP . '-' . $entry['id'],
				'data'              => $this->export_fields( $entry, $roles ),
			);
		}

		return array(
			'data' => $data,
			'done' => $page * self::PER_PAGE >= count( $ids ),
		);
	}

	/**
	 * The exported name/value pairs for one entry.
	 *
	 * Other recipients are left out: they are other people's data, and a
	 * Bcc list would reveal who else was copied in.
	 *
	 * @param array<string, mixed> $entry Hydrated entry.
	 * @param string[]             $roles How the address appears on it.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function export_fields( array $entry, array $roles ) {
		$headers = is_array( $entry['headers'] ) ? $entry['headers'] : array();
		$subject = $this->redactor->redact_subject( isset( $entry['subject'] ) ? (string) $entry['subject'] : '' );
		$body    = $this->redactor->redact( isset( $entry['body'] ) ? (string) $entry['body'] : '' );

		$fields = array(
			array(
				'name'  => __( 'Date (UTC)', 'codo-mailer' ),
				'value' => isset( $entry['created_at'] ) ? (string) $entry['created_at'] : '',
			),
			array(
				'name'  => __( 'Status', 'codo-mailer' ),
				'value' => LogRepository::STATUS_SENT === ( isset( $entry['status'] ) ? $entry['status'] : '' ) ? __( 'Sent', 'codo-mailer' ) : __( 'Failed', 'codo-mailer' ),
			),
			array(
				'name'  => __( 'Your address appeared as', 'codo-mailer' ),
				'value' => implode( ', ', $roles ),
			),
			array(
				'name'  => __( 'From', 'codo-mailer' ),
				'value' => isset( $headers['from_email'] ) ? (string) $headers['from_email'] : '',
			),
			array(
				'name'  => __( 'Subject', 'codo-mailer' ),
				'value' => $subject['body'],
			),
			array(
				'name'  => __( 'Message', 'codo-mailer' ),
				'value' => $body['body'],
			),
		);

		$names = array();
		foreach ( is_array( $entry['attachments'] ) ? $entry['attachments'] : array() as $attachment ) {
			if ( is_array( $attachment ) && isset( $attachment['name'] ) && '' !== (string) $attachment['name'] ) {
				$names[] = (string) $attachment['name'];
			}
		}
		if ( ! empty( $names ) ) {
			$fields[] = array(
				'name'  => __( 'Attachments', 'codo-mailer' ),
				'value' => implode( ', ', $names ),
			);
		}

		return $fields;
	}

	/**
	 * Erase one batch of the log entries an address appears on.
	 *
	 * Handled entries no longer match, so every call takes the first batch
	 * of what is left, and the page number core passes is not needed. The
	 * run ends when the last batch is done, or when a batch changes nothing
	 * (so a row that can't be updated cannot make core call this forever).
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ) {
		$ids     = $this->log->ids_for_address( $email );
		$deleted = 0;
		$shared  = 0;
		$failed  = 0;

		foreach ( array_slice( $ids, 0, self::PER_PAGE ) as $id ) {
			$entry = $this->log->find( $id );
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$outcome = $this->erase_entry( $entry, $email );
			if ( 'deleted' === $outcome ) {
				++$deleted;
			} elseif ( 'anonymised' === $outcome ) {
				++$shared;
			} else {
				++$failed;
			}
		}

		$messages = array();
		if ( $shared > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of emails. */
				_n(
					'%d email in the Codo Mailer log was also sent to other people. It was kept for them with this address removed; its subject and body were not changed.',
					'%d emails in the Codo Mailer log were also sent to other people. They were kept for them with this address removed; their subjects and bodies were not changed.',
					$shared,
					'codo-mailer'
				),
				$shared
			);
		}
		if ( $failed > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of emails. */
				_n( '%d Codo Mailer log entry could not be erased.', '%d Codo Mailer log entries could not be erased.', $failed, 'codo-mailer' ),
				$failed
			);
		}

		return array(
			'items_removed'  => $deleted + $shared > 0,
			'items_retained' => $shared + $failed > 0,
			'messages'       => $messages,
			'done'           => count( $ids ) <= self::PER_PAGE || 0 === $deleted + $shared,
		);
	}

	/**
	 * Delete an entry, or remove the address from it if others received it too.
	 *
	 * @param array<string, mixed> $entry Hydrated entry.
	 * @param string               $email Email address.
	 * @return string deleted|anonymised|failed
	 */
	private function erase_entry( array $entry, $email ) {
		$email  = strtolower( trim( (string) $email ) );
		$lists  = LogRepository::address_lists( $entry );
		$others = false;

		foreach ( array( 'To', 'Cc', 'Bcc' ) as $role ) {
			foreach ( $lists[ $role ] as $address ) {
				if ( ! $this->is_address( $address, $email ) ) {
					$others = true;
				}
			}
		}

		if ( ! $others ) {
			return $this->log->delete( $entry['id'] ) ? 'deleted' : 'failed';
		}

		$keep = function ( $address ) use ( $email ) {
			return ! $this->is_address( $address, $email );
		};

		$headers = is_array( $entry['headers'] ) ? $entry['headers'] : array();
		foreach ( array(
			'Cc'       => 'cc',
			'Bcc'      => 'bcc',
			'Reply-To' => 'reply_to',
		) as $role => $key ) {
			if ( isset( $headers[ $key ] ) ) {
				$headers[ $key ] = array_values( array_filter( $lists[ $role ], $keep ) );
			}
		}
		$to    = array_values( array_filter( $lists['To'], $keep ) );
		$error = str_ireplace( $email, '[deleted]', isset( $entry['error'] ) ? (string) $entry['error'] : '' );

		return $this->log->update_recipients( $entry['id'], $to, $headers, $error ) ? 'anonymised' : 'failed';
	}

	/**
	 * Whether a logged address is the given one.
	 *
	 * @param mixed  $address Logged address array.
	 * @param string $email   Lower-case email address.
	 * @return bool
	 */
	private function is_address( $address, $email ) {
		return is_array( $address ) && isset( $address['email'] ) && strtolower( trim( (string) $address['email'] ) ) === $email;
	}

	/**
	 * Suggest privacy policy text (Settings > Privacy > Policy Guide).
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return; // @codeCoverageIgnore
		}

		$paragraphs = array(
			__( 'This site sends email, such as password resets, account and order notifications and replies to forms, through an email delivery service. The service receives each email, including the recipients\' addresses, the subject and the message, in order to deliver it.', 'codo-mailer' ),
		);

		if ( $this->settings->get( 'log_enabled' ) ) {
			$days         = (int) $this->settings->get( 'log_retention_days' );
			$paragraphs[] = sprintf(
				/* translators: %d: number of days. */
				_n(
					'The site keeps a log of the emails it sends so that delivery problems can be found and fixed. Each entry records the date, whether the email was delivered, the sender and recipient addresses, the subject and the message, with password-reset links, one-time codes and passwords removed. Only site administrators can read the messages. Entries are deleted after %d day.',
					'The site keeps a log of the emails it sends so that delivery problems can be found and fixed. Each entry records the date, whether the email was delivered, the sender and recipient addresses, the subject and the message, with password-reset links, one-time codes and passwords removed. Only site administrators can read the messages. Entries are deleted after %d days.',
					$days,
					'codo-mailer'
				),
				$days
			);
			$paragraphs[] = __( 'If you request an export of your personal data, it includes the logged emails sent to your address. If you request erasure, emails sent only to you are deleted from the log; an email that was also sent to other people is kept for them, with your address removed.', 'codo-mailer' );
		}

		wp_add_privacy_policy_content( 'Codo Mailer', wp_kses_post( '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>' ) );
	}
}
