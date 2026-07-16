<?php
/**
 * htmx endpoints for HXRV.
 *
 * All endpoints go through admin-ajax.php and return HTML fragments —
 * htmx swaps them straight into the overlay. No JSON layer.
 *
 * @package HXRV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HXRV_Ajax
 */
class HXRV_Ajax {

	/**
	 * Register handlers. Logged-in users only (no nopriv) in v1.
	 */
	public static function init() {
		add_action( 'wp_ajax_hxrv_list', array( __CLASS__, 'list_comments' ) );
		add_action( 'wp_ajax_hxrv_create', array( __CLASS__, 'create_comment' ) );
		add_action( 'wp_ajax_hxrv_reply', array( __CLASS__, 'reply' ) );
		add_action( 'wp_ajax_hxrv_set_status', array( __CLASS__, 'set_status' ) );
		add_action( 'wp_ajax_hxrv_anchor', array( __CLASS__, 'anchor' ) );
		add_action( 'wp_ajax_hxrv_delete', array( __CLASS__, 'delete' ) );
	}

	/**
	 * Shared guard: capability + nonce. Dies with 403 fragment on failure.
	 */
	private static function guard_capability() {
		if ( ! current_user_can( hxrv_capability() ) ) {
			status_header( 403 );
			echo '<div class="hxrv-error">' . esc_html__( 'Permission denied.', 'hxrv-ai-ready-visual-review' ) . '</div>';
			wp_die();
		}
	}

	/**
	 * GET: all comment threads for a page. Each thread wrapper carries its
	 * anchor data as data-attributes; the overlay JS pins them to elements.
	 */
	public static function list_comments() {
		check_ajax_referer( 'hxrv', 'nonce' );
		self::guard_capability();

		$page_url = isset( $_GET['page_url'] ) ? esc_url_raw( wp_unslash( $_GET['page_url'] ) ) : '';
		if ( '' === $page_url ) {
			wp_die();
		}

		$comments = HXRV_DB::get_page_comments( $page_url );

		foreach ( $comments as $comment ) {
			self::render_thread( $comment );
		}
		wp_die();
	}

	/**
	 * POST: create a root comment anchored to an element.
	 */
	public static function create_comment() {
		check_ajax_referer( 'hxrv', 'nonce' );
		self::guard_capability();

		$user = wp_get_current_user();

		$id = HXRV_DB::insert_comment(
			array(
				'page_url'      => isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '',
				'selector'      => isset( $_POST['selector'] ) ? wp_unslash( $_POST['selector'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in HXRV_DB::insert_comment().
				'selector_text' => isset( $_POST['selector_text'] ) ? wp_unslash( $_POST['selector_text'] ) : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in HXRV_DB::insert_comment().
				'offset_x'      => isset( $_POST['offset_x'] ) ? (float) $_POST['offset_x'] : null,
				'offset_y'      => isset( $_POST['offset_y'] ) ? (float) $_POST['offset_y'] : null,
				'is_dynamic'    => isset( $_POST['is_dynamic'] ) ? (int) $_POST['is_dynamic'] : 0,
				'author_name'   => $user->display_name,
				'author_id'     => $user->ID,
				'content'       => isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in HXRV_DB::insert_comment().
				'before_text'   => isset( $_POST['before_text'] ) ? wp_unslash( $_POST['before_text'] ) : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in HXRV_DB::insert_comment().
				'after_text'    => isset( $_POST['after_text'] ) ? wp_unslash( $_POST['after_text'] ) : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in HXRV_DB::insert_comment().
			)
		);

		if ( ! $id ) {
			status_header( 422 );
			echo '<div class="hxrv-error">' . esc_html__( 'Comment could not be saved.', 'hxrv-ai-ready-visual-review' ) . '</div>';
			wp_die();
		}

		$comment          = HXRV_DB::get_comment( $id );
		$comment->replies = array();

		/**
		 * コメント（ピン）作成完了後のフック。
		 *
		 * @since 1.0.1
		 * @param int    $id      コメントID
		 * @param object $comment コメントオブジェクト（page_url, selector, content, author_name 等）
		 */
		do_action( 'hxrv_after_comment_created', $id, $comment );

		self::render_thread( $comment );
		wp_die();
	}

	/**
	 * POST: reply to a thread, then return the refreshed thread.
	 */
	public static function reply() {
		check_ajax_referer( 'hxrv', 'nonce' );
		self::guard_capability();

		$user      = wp_get_current_user();
		$parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;

		$id = HXRV_DB::insert_comment(
			array(
				'parent_id'   => $parent_id,
				'author_name' => $user->display_name,
				'author_id'   => $user->ID,
				'content'     => isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in HXRV_DB::insert_comment().
			)
		);

		if ( ! $id ) {
			status_header( 422 );
			echo '<div class="hxrv-error">' . esc_html__( 'Reply could not be saved.', 'hxrv-ai-ready-visual-review' ) . '</div>';
			wp_die();
		}

		self::render_thread_by_id( $parent_id );
		wp_die();
	}

	/**
	 * POST: toggle open / resolved (the Figma-style check), or mark orphaned.
	 */
	public static function set_status() {
		check_ajax_referer( 'hxrv', 'nonce' );
		self::guard_capability();

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		HXRV_DB::set_status( $id, $status );
		self::render_thread_by_id( $id );
		wp_die();
	}

	/**
	 * POST: anchor status sync from the client.
	 *
	 * The overlay JS reports whether a thread's element could be
	 * re-anchored (selector or text-excerpt fallback). Transitions are
	 * enforced HERE, server-side, so a stray report can never resurrect
	 * or bury a resolved thread:
	 *   found=0 : open     -> orphaned
	 *   found=1 : orphaned -> open
	 * Everything else is a no-op.
	 */
	public static function anchor() {
		check_ajax_referer( 'hxrv', 'nonce' );
		self::guard_capability();

		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$found = isset( $_POST['found'] ) && '1' === $_POST['found'];

		$comment = $id ? HXRV_DB::get_comment( $id ) : null;
		if ( ! $comment || 0 !== (int) $comment->parent_id ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		if ( ! $found && HXRV_DB::STATUS_OPEN === $comment->status ) {
			HXRV_DB::set_status( $id, HXRV_DB::STATUS_ORPHANED );
		} elseif ( $found && HXRV_DB::STATUS_ORPHANED === $comment->status ) {
			HXRV_DB::set_status( $id, HXRV_DB::STATUS_OPEN );
		}

		wp_die();
	}

	/**
	 * POST: delete a thread. Empty 200 — htmx swaps it away.
	 */
	public static function delete() {
		check_ajax_referer( 'hxrv', 'nonce' );
		self::guard_capability();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		HXRV_DB::delete_comment( $id );
		wp_die();
	}

	/**
	 * Load a root comment with replies and render it.
	 *
	 * @param int $id Root comment ID.
	 */
	private static function render_thread_by_id( $id ) {
		$root = HXRV_DB::get_comment( $id );
		if ( ! $root ) {
			return;
		}

		$threads = HXRV_DB::get_page_comments( $root->page_url );
		foreach ( $threads as $thread ) {
			if ( (int) $thread->id === (int) $id ) {
				self::render_thread( $thread );
				return;
			}
		}
	}

	/**
	 * Render one comment thread fragment.
	 *
	 * @param object $comment Root comment with ->replies.
	 */
	private static function render_thread( $comment ) {
		$is_resolved = HXRV_DB::STATUS_RESOLVED === $comment->status;
		$nonce       = wp_create_nonce( 'hxrv' );
		?>
		<div
			class="hxrv-thread hxrv-status-<?php echo esc_attr( $comment->status ); ?>"
			id="hxrv-thread-<?php echo (int) $comment->id; ?>"
			data-hxrv-id="<?php echo (int) $comment->id; ?>"
			data-hxrv-selector="<?php echo esc_attr( $comment->selector ); ?>"
			data-hxrv-selector-text="<?php echo esc_attr( (string) $comment->selector_text ); ?>"
			data-hxrv-offset-x="<?php echo esc_attr( (string) $comment->offset_x ); ?>"
			data-hxrv-offset-y="<?php echo esc_attr( (string) $comment->offset_y ); ?>"
			data-hxrv-dynamic="<?php echo (int) $comment->is_dynamic; ?>"
			data-hxrv-status="<?php echo esc_attr( $comment->status ); ?>"
		>
			<div class="hxrv-comment hxrv-comment--root">
				<header class="hxrv-comment__meta">
					<span class="hxrv-comment__author"><?php echo esc_html( $comment->author_name ); ?></span>
					<time class="hxrv-comment__date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $comment->created_at ) ); ?></time>
					<?php if ( $comment->is_dynamic ) : ?>
						<span class="hxrv-badge hxrv-badge--dynamic" title="<?php esc_attr_e( 'Pinned to possibly dynamic content', 'hxrv-ai-ready-visual-review' ); ?>">⚠</span>
					<?php endif; ?>
				</header>
				<p class="hxrv-comment__content"><?php echo esc_html( $comment->content ); ?></p>
				<?php if ( ! empty( $comment->before_text ) || ! empty( $comment->after_text ) ) : ?>
					<dl class="hxrv-ba">
						<?php if ( ! empty( $comment->before_text ) ) : ?>
							<dt class="hxrv-ba__label hxrv-ba__label--before"><?php esc_html_e( 'Before', 'hxrv-ai-ready-visual-review' ); ?></dt>
							<dd class="hxrv-ba__text"><?php echo esc_html( $comment->before_text ); ?></dd>
						<?php endif; ?>
						<?php if ( ! empty( $comment->after_text ) ) : ?>
							<dt class="hxrv-ba__label hxrv-ba__label--after"><?php esc_html_e( 'After', 'hxrv-ai-ready-visual-review' ); ?></dt>
							<dd class="hxrv-ba__text"><?php echo esc_html( $comment->after_text ); ?></dd>
						<?php endif; ?>
					</dl>
				<?php endif; ?>
			</div>

			<?php foreach ( $comment->replies as $reply ) : ?>
				<div class="hxrv-comment hxrv-comment--reply">
					<header class="hxrv-comment__meta">
						<span class="hxrv-comment__author"><?php echo esc_html( $reply->author_name ); ?></span>
						<time class="hxrv-comment__date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $reply->created_at ) ); ?></time>
					</header>
					<p class="hxrv-comment__content"><?php echo esc_html( $reply->content ); ?></p>
				</div>
			<?php endforeach; ?>

			<footer class="hxrv-thread__actions">
				<?php
				// No hx-* attributes here on purpose: outerHTML swaps of a
				// JS-positioned thread lose its placement (htmx fires the
				// after-swap events on the detached old node, so client-side
				// repositioning cannot reliably hook them). All thread
				// mutations instead go through delegated handlers in
				// hxrv-overlay.js: fetch → refresh the whole list into the
				// stable #hxrv-comments container (same path as create).
				?>
				<button
					type="button"
					class="hxrv-btn hxrv-btn--resolve"
					data-hxrv-action="hxrv_set_status"
					data-hxrv-id="<?php echo (int) $comment->id; ?>"
					data-hxrv-status="<?php echo $is_resolved ? 'open' : 'resolved'; ?>"
					data-hxrv-nonce="<?php echo esc_attr( $nonce ); ?>"
				>
					<?php $is_resolved ? esc_html_e( 'Reopen', 'hxrv-ai-ready-visual-review' ) : esc_html_e( 'Resolve', 'hxrv-ai-ready-visual-review' ); ?>
				</button>

				<button
					type="button"
					class="hxrv-btn hxrv-btn--delete"
					data-hxrv-action="hxrv_delete"
					data-hxrv-id="<?php echo (int) $comment->id; ?>"
					data-hxrv-nonce="<?php echo esc_attr( $nonce ); ?>"
					data-hxrv-confirm="<?php esc_attr_e( 'Delete this thread?', 'hxrv-ai-ready-visual-review' ); ?>"
				>
					<?php esc_html_e( 'Delete', 'hxrv-ai-ready-visual-review' ); ?>
				</button>

				<form class="hxrv-reply-form" action="" method="post">
					<input type="hidden" name="action" value="hxrv_reply" />
					<input type="hidden" name="parent_id" value="<?php echo (int) $comment->id; ?>" />
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<textarea name="content" rows="2" placeholder="<?php esc_attr_e( 'Reply…', 'hxrv-ai-ready-visual-review' ); ?>" required></textarea>
					<button type="submit" class="hxrv-btn"><?php esc_html_e( 'Reply', 'hxrv-ai-ready-visual-review' ); ?></button>
				</form>
			</footer>
		</div>
		<?php
	}
}
