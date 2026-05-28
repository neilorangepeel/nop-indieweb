<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\IndieAuth;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database abstraction for IndieAuth-issued Bearer tokens.
 *
 * Tokens are stored as SHA-256 hashes — the raw value is only held in memory
 * long enough to return it to the client. After that it cannot be recovered
 * from the database. Revocation deletes the row entirely.
 */
class Token_Store {

	private const DB_VERSION = 1;
	private const TABLE_SLUG = 'nop_indieweb_tokens';

	// ── Schema ────────────────────────────────────────────────────────────────

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SLUG;
	}

	/**
	 * Creates or updates the tokens table. Idempotent — safe to call on every
	 * plugins_loaded. Skips the query if the DB version option is current.
	 */
	public static function maybe_create_table(): void {
		if ( (int) get_option( 'nop_indieweb_db_version', 0 ) >= self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// Two spaces before PRIMARY KEY is load-bearing for dbDelta's diff logic.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash varchar(64) NOT NULL,
  client_id varchar(2000) NOT NULL,
  client_name varchar(255) NOT NULL DEFAULT '',
  scope varchar(500) NOT NULL DEFAULT '',
  issued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY user_id (user_id)
) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Remove the legacy static secret_token from settings.
		$opts = get_option( 'nop_indieweb_settings', [] );
		if ( isset( $opts['secret_token'] ) ) {
			unset( $opts['secret_token'] );
			update_option( 'nop_indieweb_settings', $opts, false );
		}

		update_option( 'nop_indieweb_db_version', self::DB_VERSION );
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Stores a new token. Only the SHA-256 hash of $raw_token is persisted.
	 */
	public static function insert(
		string $raw_token,
		string $client_id,
		string $client_name,
		string $scope,
		int    $user_id
	): bool {
		global $wpdb;

		return (bool) $wpdb->insert(
			self::table_name(),
			[
				'token_hash'  => hash( 'sha256', $raw_token ),
				'client_id'   => $client_id,
				'client_name' => $client_name,
				'scope'       => $scope,
				'issued_at'   => current_time( 'mysql', true ),
				'user_id'     => $user_id,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d' ]
		);
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Looks up a token row by hashing the provided raw value.
	 * Returns the row as an associative array, or null if not found.
	 */
	public static function find_by_token( string $raw_token ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE token_hash = %s',
				hash( 'sha256', $raw_token )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/** Returns all tokens for a user, newest first. */
	public static function get_by_user( int $user_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, client_id, client_name, scope, issued_at, last_used_at
				   FROM ' . self::table_name() . '
				  WHERE user_id = %d
				  ORDER BY issued_at DESC',
				$user_id
			),
			ARRAY_A
		) ?: [];
	}

	// ── Update ────────────────────────────────────────────────────────────────

	/** Updates last_used_at to now for the given token hash. */
	public static function touch( string $token_hash ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[ 'last_used_at' => current_time( 'mysql', true ) ],
			[ 'token_hash'   => $token_hash ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	/** Revokes by database row ID. Used from the admin sessions UI. */
	public static function delete_by_id( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);
	}

	/** Revokes by raw token value. Used by the token endpoint's revoke action. */
	public static function revoke_by_raw( string $raw_token ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table_name(),
			[ 'token_hash' => hash( 'sha256', $raw_token ) ],
			[ '%s' ]
		);
	}
}
