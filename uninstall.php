<?php
/**
 * Uninstall HXRV.
 *
 * Removes the custom table and all options. Review data is project-scoped
 * and disposable by design — a clean uninstall leaves zero traces.
 *
 * @package HXRV
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'hxrv_comments' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix, no user input.

delete_option( 'hxrv_db_version' );
