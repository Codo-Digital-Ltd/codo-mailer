<?php
/**
 * Email log storage.
 *
 * @package CodoDigital\Mailer
 */

namespace CodoDigital\Mailer\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the {prefix}codo_mailer_log table.
 *
 * Every query goes through $wpdb->prepare(); there are deliberately no
 * REST or AJAX endpoints that expose this data.
 */
class LogRepository {

	const TABLE = 'codo_mailer_log';

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'codo_mailer_db_version';

	const STATUS_SENT   = 'sent';
	const STATUS_FAILED = 'failed';

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Database handle.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public function table() {
		return $this->db->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the table if the schema version changed.
	 *
	 * @return bool Whether an install ran.
	 */
	public function maybe_install() {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return false;
		}
		$this->install();
		return true;
	}

	/**
	 * Create the table with dbDelta.
	 *
	 * @return void
	 */
	public function install() {
		$table   = $this->table();
		$charset = $this->db->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(20) NOT NULL,
			connection varchar(40) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'wp_mail',
			to_addresses text NOT NULL,
			subject text NOT NULL,
			body longtext NOT NULL,
			headers longtext NOT NULL,
			attachments text NOT NULL,
			error text NOT NULL,
			redacted tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // @codeCoverageIgnore
		}
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true ); // Read on every request by maybe_install().
	}

	/**
	 * Insert an entry.
	 *
	 * @param array<string, mixed> $entry Keys: status, connection, source, to, subject, body, headers, attachments, error, redacted.
	 * @return int Inserted ID (0 on failure).
	 */
	public function insert( array $entry ) {
		$row = array(
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'status'       => self::STATUS_SENT === ( isset( $entry['status'] ) ? $entry['status'] : '' ) ? self::STATUS_SENT : self::STATUS_FAILED,
			'connection'   => isset( $entry['connection'] ) ? substr( (string) $entry['connection'], 0, 40 ) : '',
			'source'       => isset( $entry['source'] ) ? substr( (string) $entry['source'], 0, 20 ) : 'wp_mail',
			'to_addresses' => (string) wp_json_encode( isset( $entry['to'] ) ? array_values( (array) $entry['to'] ) : array() ),
			'subject'      => isset( $entry['subject'] ) ? (string) $entry['subject'] : '',
			'body'         => isset( $entry['body'] ) ? (string) $entry['body'] : '',
			'headers'      => (string) wp_json_encode( isset( $entry['headers'] ) ? (array) $entry['headers'] : array() ),
			'attachments'  => (string) wp_json_encode( isset( $entry['attachments'] ) ? array_values( (array) $entry['attachments'] ) : array() ),
			'error'        => isset( $entry['error'] ) ? (string) $entry['error'] : '',
			'redacted'     => empty( $entry['redacted'] ) ? 0 : 1,
		);

		$ok = $this->db->insert(
			$this->table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return $ok ? (int) $this->db->insert_id : 0;
	}

	/**
	 * Find an entry by ID.
	 *
	 * @param int $id Entry ID.
	 * @return array<string, mixed>|null Decoded entry.
	 */
	public function find( $id ) {
		$row = $this->db->get_row(
			$this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), (int) $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Page through entries, newest first.
	 *
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @param string $status   Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function paginate( $page, $per_page, $status = '' ) {
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;

		if ( in_array( $status, array( self::STATUS_SENT, self::STATUS_FAILED ), true ) ) {
			$sql = $this->db->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d', $this->table(), $status, $per_page, $offset );
		} else {
			$sql = $this->db->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $this->table(), $per_page, $offset );
		}

		$rows = $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count entries.
	 *
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public function count( $status = '' ) {
		if ( in_array( $status, array( self::STATUS_SENT, self::STATUS_FAILED ), true ) ) {
			$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $this->table(), $status );
		} else {
			$sql = $this->db->prepare( 'SELECT COUNT(*) FROM %i', $this->table() );
		}
		return (int) $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	}

	/**
	 * IDs of the entries an email address appears on, oldest first.
	 *
	 * Matches To, Cc, Bcc and Reply-To exactly (ignoring case). The SQL LIKE
	 * only narrows the rows down; each candidate is then checked in PHP, so
	 * a longer address that contains this one, or the address appearing in
	 * a custom header, never matches.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	public function ids_for_address( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return array();
		}

		$like = '%' . $this->db->esc_like( (string) wp_json_encode( $email ) ) . '%';
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT id, to_addresses, headers FROM %i WHERE to_addresses LIKE %s OR headers LIKE %s ORDER BY id ASC', $this->table(), $like, $like ),
			ARRAY_A
		);

		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['attachments'] = '[]';
			$row['redacted']    = 0;
			$entry              = $this->hydrate( $row );
			if ( ! empty( self::roles_of( $entry, $email ) ) ) {
				$ids[] = $entry['id'];
			}
		}
		return $ids;
	}

	/**
	 * How an address appears on an entry: To, Cc, Bcc and/or Reply-To.
	 *
	 * @param array<string, mixed> $entry Hydrated entry.
	 * @param string               $email Email address (case-insensitive).
	 * @return string[] Header names, e.g. array( 'To', 'Reply-To' ).
	 */
	public static function roles_of( array $entry, $email ) {
		$email = strtolower( trim( (string) $email ) );
		$roles = array();
		foreach ( self::address_lists( $entry ) as $role => $addresses ) {
			foreach ( $addresses as $address ) {
				if ( is_array( $address ) && isset( $address['email'] ) && strtolower( trim( (string) $address['email'] ) ) === $email ) {
					$roles[] = $role;
					break;
				}
			}
		}
		return $roles;
	}

	/**
	 * An entry's address lists, by header name.
	 *
	 * @param array<string, mixed> $entry Hydrated entry.
	 * @return array<string, array<int, mixed>>
	 */
	public static function address_lists( array $entry ) {
		$headers = isset( $entry['headers'] ) && is_array( $entry['headers'] ) ? $entry['headers'] : array();
		$lists   = array( 'To' => isset( $entry['to_addresses'] ) ? $entry['to_addresses'] : array() );
		foreach ( array(
			'Cc'       => 'cc',
			'Bcc'      => 'bcc',
			'Reply-To' => 'reply_to',
		) as $role => $key ) {
			$lists[ $role ] = isset( $headers[ $key ] ) ? $headers[ $key ] : array();
		}
		return array_map(
			static function ( $addresses ) {
				return is_array( $addresses ) ? array_values( $addresses ) : array();
			},
			$lists
		);
	}

	/**
	 * Replace an entry's recipients and error text (used to anonymise it).
	 *
	 * @param int                  $id      Entry ID.
	 * @param array<int, mixed>    $to      To addresses.
	 * @param array<string, mixed> $headers Header data, including cc, bcc and reply_to.
	 * @param string               $error   Error text.
	 * @return bool Whether the row was updated.
	 */
	public function update_recipients( $id, array $to, array $headers, $error ) {
		$updated = $this->db->update(
			$this->table(),
			array(
				'to_addresses' => (string) wp_json_encode( array_values( $to ) ),
				'headers'      => (string) wp_json_encode( $headers ),
				'error'        => (string) $error,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}

	/**
	 * Delete one entry.
	 *
	 * @param int $id Entry ID.
	 * @return bool Whether a row was deleted.
	 */
	public function delete( $id ) {
		return (bool) $this->db->delete( $this->table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete entries older than a number of days.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public function purge_older_than( $days ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		return (int) $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), $cutoff ) );
	}

	/**
	 * Delete every entry.
	 *
	 * @return int Rows deleted.
	 */
	public function clear() {
		return (int) $this->db->query( $this->db->prepare( 'DELETE FROM %i', $this->table() ) );
	}

	/**
	 * Drop the table (uninstall only).
	 *
	 * @return void
	 */
	public function drop() {
		$this->db->query( $this->db->prepare( 'DROP TABLE IF EXISTS %i', $this->table() ) );
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ) {
		foreach ( array( 'to_addresses', 'headers', 'attachments' ) as $column ) {
			$decoded        = isset( $row[ $column ] ) ? json_decode( (string) $row[ $column ], true ) : null;
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}
		$row['id']       = (int) $row['id'];
		$row['redacted'] = ! empty( $row['redacted'] );
		return $row;
	}
}
