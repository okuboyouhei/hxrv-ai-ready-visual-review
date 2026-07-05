<?php
/**
 * HXRV_Admin
 *
 * Minimal admin screen: list comments grouped by page + export button.
 * Deliberately no settings page (v1 has nothing to configure — filters only).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXRV_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the copy / download helper only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_hxrv' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'hxrv-admin', HXRV_PLUGIN_URL . 'assets/js/hxrv-admin.js', array(), HXRV_VERSION, true );
	}

	public static function menu() {
		add_menu_page(
			'HXRV',
			'HXRV',
			hxrv_capability(),
			'hxrv',
			array( __CLASS__, 'render' ),
			'dashicons-format-chat',
			81
		);
	}

	public static function render() {
		// Export view: preview + copy + client-side download.
		if ( isset( $_GET['view'] ) && 'export' === $_GET['view'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in render_export().
			self::render_export();
			return;
		}

		self::render_list();
	}

	/**
	 * Export preview screen. The Markdown is rendered into a textarea so
	 * it can be copied straight into an AI coding agent; the download
	 * button builds a Blob client-side (no admin-post streaming involved).
	 */
	private static function render_export() {
		check_admin_referer( 'hxrv_export_view' );

		$md       = HXRV_Export::build_markdown();
		$filename = 'hxrv-review-' . gmdate( 'Ymd-His' ) . '.md';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Review Fix Brief (Markdown)', 'hxrv-ai-ready-visual-review' ); ?></h1>

			<p>
				<button type="button" class="button button-primary" id="hxrv-copy-md" data-copied="<?php esc_attr_e( 'Copied!', 'hxrv-ai-ready-visual-review' ); ?>">
					<?php esc_html_e( 'Copy to clipboard', 'hxrv-ai-ready-visual-review' ); ?>
				</button>
				<button type="button" class="button" id="hxrv-download-md" data-filename="<?php echo esc_attr( $filename ); ?>">
					<?php esc_html_e( 'Download .md', 'hxrv-ai-ready-visual-review' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=hxrv' ) ); ?>" class="button button-link">
					<?php esc_html_e( 'Back', 'hxrv-ai-ready-visual-review' ); ?>
				</a>
			</p>

			<textarea id="hxrv-export-md" readonly rows="24" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $md ); ?></textarea>

			<p class="description">
				<?php esc_html_e( 'Paste this brief into your AI coding agent (Claude Code etc.) — it contains selectors and element text to locate each fix.', 'hxrv-ai-ready-visual-review' ); ?>
			</p>
		</div>
		<?php
	}

	private static function render_list() {
		global $wpdb;


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- aggregate over own table, table name from $wpdb->prefix, no user input.
		$rows = $wpdb->get_results(
			'SELECT page_url,'
			. " SUM(status = 'open' AND parent_id = 0) AS open_count,"
			. " SUM(status = 'resolved' AND parent_id = 0) AS resolved_count,"
			. " SUM(status = 'orphaned' AND parent_id = 0) AS orphaned_count"
			. ' FROM ' . $wpdb->prefix . 'hxrv_comments'
			. ' GROUP BY page_url_hash, page_url'
			. ' ORDER BY MAX(created_at) DESC'
		);

		$export_url = wp_nonce_url(
			admin_url( 'admin.php?page=hxrv&view=export' ),
			'hxrv_export_view'
		);
		?>
		<div class="wrap">
			<h1>HXRV - AI-Ready Visual Review</h1>

			<p>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Export open comments (Markdown for AI agents)', 'hxrv-ai-ready-visual-review' ); ?>
				</a>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'hxrv-ai-ready-visual-review' ); ?></th>
						<th><?php esc_html_e( 'Open', 'hxrv-ai-ready-visual-review' ); ?></th>
						<th><?php esc_html_e( 'Resolved', 'hxrv-ai-ready-visual-review' ); ?></th>
						<th><?php esc_html_e( 'Orphaned', 'hxrv-ai-ready-visual-review' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No comments yet.', 'hxrv-ai-ready-visual-review' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->page_url ); ?></td>
								<td><?php echo esc_html( $row->open_count ); ?></td>
								<td><?php echo esc_html( $row->resolved_count ); ?></td>
								<td><?php echo esc_html( $row->orphaned_count ); ?></td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( 'hxrv', '1', $row->page_url ) ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'Open in review mode', 'hxrv-ai-ready-visual-review' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
