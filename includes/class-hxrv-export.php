<?php
/**
 * HXRV_Export
 *
 * The differentiator: export open comments as an AI-ready Markdown
 * brief that can be handed directly to Claude Code / coding agents.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXRV_Export {

	public static function init() {
		add_action( 'admin_post_hxrv_export_md', array( __CLASS__, 'download' ) );
	}

	public static function download() {
		if ( ! current_user_can( hxrv_capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'hxrv-ai-ready-visual-review' ) );
		}
		check_admin_referer( 'hxrv_export' );

		$md       = self::build_markdown();
		$filename = 'hxrv-review-' . gmdate( 'Ymd-His' ) . '.md';

		// Discard anything WordPress buffered so far — stray output before
		// our headers leaves the download hanging as "unconfirmed". Some
		// buffers (zlib output compression) cannot be discarded; bail out
		// instead of looping forever.
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- non-removable zlib buffer is expected.
				break;
			}
		}

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// Only promise a byte count when PHP is not gzipping the stream —
		// an uncompressed Content-Length over a compressed body makes the
		// browser wait forever for the missing bytes.
		if ( ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Length: ' . strlen( $md ) );
		}

		echo $md; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text file download, not HTML context.
		exit;
	}

	public static function build_markdown() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix, no user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . $wpdb->prefix . "hxrv_comments WHERE status IN ('open','orphaned') ORDER BY page_url_hash, parent_id, created_at" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix, literal status list.
		);

		$pages = array();
		foreach ( $rows as $row ) {
			if ( 0 === (int) $row->parent_id ) {
				$pages[ $row->page_url ]['threads'][ $row->id ] = array( 'root' => $row, 'replies' => array() );
			} else {
				foreach ( $pages as &$page ) {
					if ( isset( $page['threads'][ $row->parent_id ] ) ) {
						$page['threads'][ $row->parent_id ]['replies'][] = $row;
						break;
					}
				}
				unset( $page );
			}
		}

		$md  = "# Review Fix Brief (HXRV)\n\n";
		$md .= self::preamble();
		$md .= "\n---\n\n";

		if ( empty( $pages ) ) {
			$md .= "_No open comments._\n";
			return $md;
		}

		foreach ( $pages as $url => $page ) {
			$md .= '## Page: ' . $url . "\n\n";

			foreach ( $page['threads'] as $id => $thread ) {
				$root = $thread['root'];
				$md  .= "### Fix #{$id}" . ( $root->is_dynamic ? ' ⚠ (possibly dynamic content)' : '' ) . ( 'orphaned' === $root->status ? ' ⚠ (anchor lost)' : '' ) . "\n";
				$md  .= "- **Selector**: `{$root->selector}`\n";
				if ( 'orphaned' === $root->status ) {
					$md .= "- **Anchor lost**: the selector no longer matches any element on the page. Locate the target via the element text excerpt below, or confirm with a human before changing anything.\n";
				}
				if ( $root->selector_text ) {
					$md .= "- **Element text (excerpt)**: \"{$root->selector_text}\"\n";
				}
				$md .= "- **Comment**: {$root->content}\n";
				$md .= "- **Author**: {$root->author_name} / {$root->created_at}\n";

				foreach ( $thread['replies'] as $reply ) {
					$md .= "  - **Reply** ({$reply->author_name}): {$reply->content}\n";
				}
				$md .= "\n";
			}
		}

		return apply_filters( 'hxrv_export_markdown', $md, $pages );
	}

	/**
	 * AI-facing preamble. Filterable so agencies can adapt it to
	 * their own repo layout / conventions.
	 */
	private static function preamble() {
		$lines = array(
			'Generated: ' . gmdate( 'Y-m-d H:i \U\T\C' ),
			'Site: ' . home_url(),
			'Active theme: `' . get_stylesheet() . '`',
			'Scope: open (unresolved) review comments, including anchor-lost (orphaned) ones.',
			'',
			'**Instructions for the AI coding agent:**',
			'This document lists visual review comments pinned to specific DOM elements',
			'of a WordPress site. For each fix:',
			"1. Use the CSS selector and the element text excerpt to locate the source —",
			"   grep the active theme's template files (and template parts / block patterns) first.",
			'2. If the selector uses positional parts (e.g. `nth-of-type`), prefer the',
			'   element text excerpt as the primary anchor.',
			'3. Items marked ⚠ target possibly dynamic content (loops, latest-posts blocks);',
			'   the fix likely belongs in the template that renders the loop, not in content.',
			'4. Do not change unrelated markup. Keep the diff minimal.',
		);

		return implode( "\n", $lines ) . "\n";
	}
}
