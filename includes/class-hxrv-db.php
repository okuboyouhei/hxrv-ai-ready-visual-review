<?php
/**
 * Database layer for HXRV.
 *
 * One custom table. Comments are anchored to a CSS selector plus a
 * relative offset, with a text snippet kept as a re-anchoring fallback.
 *
 * @package HXRV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HXRV_DB
 */
class HXRV_DB {

	const STATUS_OPEN     = 'open';
	const STATUS_RESOLVED = 'resolved';
	const STATUS_ORPHANED = 'orphaned';

	/**
	 * Full table name with WP prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'hxrv_comments';
	}

	/**
	 * Create / upgrade the schema. Runs on activation and version bump.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, KEY (not INDEX),
		// one field per line.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			page_url VARCHAR(2048) NOT NULL,
			page_url_hash CHAR(32) NOT NULL,
			selector TEXT NOT NULL,
			selector_text VARCHAR(255) DEFAULT NULL,
			offset_x DECIMAL(5,2) DEFAULT NULL,
			offset_y DECIMAL(5,2) DEFAULT NULL,
			is_dynamic TINYINT(1) NOT NULL DEFAULT 0,
			author_name VARCHAR(100) NOT NULL DEFAULT '',
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			content TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY page_hash_status (page_url_hash, status),
			KEY parent_id (parent_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'hxrv_db_version', HXRV_DB_VERSION );
	}

	/**
	 * Normalize a URL for stable hashing: host + path only. Query strings
	 * and fragments are dropped — a review pin belongs to a path.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized host + path.
	 */
	public static function normalize_url( $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		if ( '' === $path ) {
			$path = '/';
		}

		return $host . $path;
	}

	/**
	 * MD5 hash of the normalized URL, used as the indexed lookup key.
	 *
	 * @param string $url Raw URL.
	 * @return string 32-char hash.
	 */
	public static function url_hash( $url ) {
		return md5( self::normalize_url( $url ) );
	}

	/**
	 * Insert a comment (or a threaded reply when parent_id > 0).
	 *
	 * @param array $data Comment fields.
	 * @return int|false New comment ID or false on failure.
	 */
	public static function insert_comment( $data ) {
		global $wpdb;

		$defaults = array(
			'parent_id'     => 0,
			'page_url'      => '',
			'selector'      => '',
			'selector_text' => null,
			'offset_x'      => null,
			'offset_y'      => null,
			'is_dynamic'    => 0,
			'author_name'   => '',
			'author_id'     => 0,
			'content'       => '',
			'status'        => self::STATUS_OPEN,
		);
		$data     = wp_parse_args( $data, $defaults );

		if ( '' === trim( $data['content'] ) ) {
			return false;
		}

		// Replies inherit anchoring from their root comment.
		if ( $data['parent_id'] > 0 ) {
			$parent = self::get_comment( (int) $data['parent_id'] );
			if ( ! $parent ) {
				return false;
			}
			$data['page_url']      = $parent->page_url;
			$data['selector']      = $parent->selector;
			$data['selector_text'] = $parent->selector_text;
			$data['offset_x']      = null;
			$data['offset_y']      = null;
			$data['is_dynamic']    = (int) $parent->is_dynamic;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'parent_id'     => (int) $data['parent_id'],
				'page_url'      => esc_url_raw( $data['page_url'] ),
				'page_url_hash' => self::url_hash( $data['page_url'] ),
				'selector'      => sanitize_textarea_field( $data['selector'] ),
				'selector_text' => is_null( $data['selector_text'] ) ? null : sanitize_text_field( mb_substr( $data['selector_text'], 0, 255 ) ),
				'offset_x'      => is_null( $data['offset_x'] ) ? null : (float) $data['offset_x'],
				'offset_y'      => is_null( $data['offset_y'] ) ? null : (float) $data['offset_y'],
				'is_dynamic'    => (int) (bool) $data['is_dynamic'],
				'author_name'   => sanitize_text_field( $data['author_name'] ),
				'author_id'     => (int) $data['author_id'],
				'content'       => sanitize_textarea_field( $data['content'] ),
				'status'        => self::sanitize_status( $data['status'] ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch a single comment.
	 *
	 * @param int $id Comment ID.
	 * @return object|null
	 */
	public static function get_comment( $id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'hxrv_comments WHERE id = %d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
		);
	}

	/**
	 * Root comments for a page, replies attached as ->replies.
	 *
	 * @param string      $page_url Page URL.
	 * @param string|null $status   Optional status filter.
	 * @return object[]
	 */
	public static function get_page_comments( $page_url, $status = null ) {
		global $wpdb;
		$hash  = self::url_hash( $page_url );

		if ( $status ) {
			$roots = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM ' . $wpdb->prefix . 'hxrv_comments WHERE page_url_hash = %s AND parent_id = 0 AND status = %s ORDER BY created_at ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
					$hash,
					self::sanitize_status( $status )
				)
			);
		} else {
			$roots = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM ' . $wpdb->prefix . 'hxrv_comments WHERE page_url_hash = %s AND parent_id = 0 ORDER BY created_at ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
					$hash
				)
			);
		}

		return self::attach_replies( $roots );
	}

	/**
	 * All root comments across the site, for the admin list and export.
	 *
	 * @param string|null $status Optional status filter.
	 * @return object[]
	 */
	public static function get_all_comments( $status = null ) {
		global $wpdb;

		if ( $status ) {
			$roots = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT * FROM ' . $wpdb->prefix . 'hxrv_comments WHERE parent_id = 0 AND status = %s ORDER BY page_url_hash, created_at ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
					self::sanitize_status( $status )
				)
			);
		} else {
			$roots = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'hxrv_comments WHERE parent_id = 0 ORDER BY page_url_hash, created_at ASC' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
		}

		return self::attach_replies( $roots );
	}

	/**
	 * Attach replies to a set of root comments in one query.
	 *
	 * @param object[] $roots Root comments.
	 * @return object[]
	 */
	private static function attach_replies( $roots ) {
		global $wpdb;

		if ( empty( $roots ) ) {
			return array();
		}

		$ids = wp_list_pluck( $roots, 'id' );
		$ids = array_map( 'intval', $ids );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are %d repeated via array_fill, table name from $wpdb->prefix.
		$replies = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'hxrv_comments WHERE parent_id IN (' . $placeholders . ') ORDER BY created_at ASC', $ids ) );

		$by_parent = array();
		foreach ( $replies as $reply ) {
			$by_parent[ $reply->parent_id ][] = $reply;
		}

		foreach ( $roots as $root ) {
			$root->replies = isset( $by_parent[ $root->id ] ) ? $by_parent[ $root->id ] : array();
		}

		return $roots;
	}

	/**
	 * Update a comment's status (open / resolved / orphaned).
	 *
	 * @param int    $id     Comment ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'status' => self::sanitize_status( $status ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a root comment and its replies.
	 *
	 * @param int $id Comment ID.
	 * @return bool
	 */
	public static function delete_comment( $id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'hxrv_comments', array( 'parent_id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $wpdb->prefix . 'hxrv_comments', array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whitelist status values.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$valid = array( self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_ORPHANED );
		return in_array( $status, $valid, true ) ? $status : self::STATUS_OPEN;
	}
}
