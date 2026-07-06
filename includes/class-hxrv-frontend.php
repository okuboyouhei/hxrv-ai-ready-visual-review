<?php
/**
 * Frontend bootstrap for HXRV.
 *
 * Review mode activates only when: the visitor is logged in with the
 * required capability AND the URL carries ?hxrv. Zero assets are loaded
 * for everyone else — the plugin is invisible to normal visitors.
 *
 * @package HXRV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HXRV_Frontend
 */
class HXRV_Frontend {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_overlay_root' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_link' ), 90 );
	}

	/**
	 * Is review mode active for this request?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return isset( $_GET['hxrv'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only mode switch.
			&& is_user_logged_in()
			&& current_user_can( hxrv_capability() )
			&& ! is_admin();
	}

	/**
	 * Enqueue htmx, Alpine and the overlay bundle — review mode only.
	 */
	/**
	 * The list endpoint URL, shared by the container's hx-get (initial
	 * load) and HXRV.listUrl (deterministic refresh path in JS).
	 */
	private static function list_url() {
		return add_query_arg(
			array(
				'action'   => 'hxrv_list',
				'page_url' => rawurlencode( home_url( remove_query_arg( 'hxrv' ) ) ),
				'nonce'    => wp_create_nonce( 'hxrv' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function enqueue() {
		if ( ! self::is_active() ) {
			return;
		}

		wp_enqueue_script( 'hxrv-htmx', HXRV_PLUGIN_URL . 'assets/js/htmx.min.js', array(), '2.0.10', true );
		wp_enqueue_script( 'hxrv-overlay', HXRV_PLUGIN_URL . 'assets/js/hxrv-overlay.js', array( 'hxrv-htmx' ), HXRV_VERSION, true );
		// Alpine boots on DOMContentLoaded; the overlay component must be
		// registered first, so Alpine loads after it.
		wp_enqueue_script( 'hxrv-alpine', HXRV_PLUGIN_URL . 'assets/js/alpine.min.js', array( 'hxrv-overlay' ), '3.15.12', true );
		wp_script_add_data( 'hxrv-alpine', 'defer', true );

		wp_enqueue_style( 'hxrv-overlay', HXRV_PLUGIN_URL . 'assets/css/hxrv-overlay.css', array(), HXRV_VERSION );

		wp_localize_script(
			'hxrv-overlay',
			'HXRV',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'listUrl' => self::list_url(),
				'nonce'   => wp_create_nonce( 'hxrv' ),
				'pageUrl' => home_url( remove_query_arg( 'hxrv' ) ),
				'i18n'    => array(
					'placeholder' => __( 'Leave a comment…', 'hxrv-ai-ready-visual-review' ),
					'submit'      => __( 'Comment', 'hxrv-ai-ready-visual-review' ),
					'cancel'      => __( 'Cancel', 'hxrv-ai-ready-visual-review' ),
					'exitReview'  => __( 'Exit review', 'hxrv-ai-ready-visual-review' ),
					'dynamicWarn' => __( 'This element may be dynamic content — the pin could drift.', 'hxrv-ai-ready-visual-review' ),
				),
			)
		);
	}

	/**
	 * Overlay markup, printed just before </body>.
	 *
	 * htmx loads existing threads into #hxrv-comments on page load; the
	 * overlay JS then pins each thread to its anchored element.
	 */
	public static function render_overlay_root() {
		if ( ! self::is_active() ) {
			return;
		}

		$page_url = home_url( remove_query_arg( 'hxrv' ) );
		$exit_url = remove_query_arg( 'hxrv' );
		?>
		<div id="hxrv-root" x-data="hxrvOverlay()" x-init="boot()">

			<div class="hxrv-toolbar">
				<button
					type="button"
					class="hxrv-btn hxrv-btn--mode"
					:class="{ 'is-active': commentMode }"
					@click="toggleCommentMode()"
				><?php esc_html_e( 'Comment', 'hxrv-ai-ready-visual-review' ); ?></button>
				<a class="hxrv-toolbar__exit" href="<?php echo esc_url( $exit_url ); ?>"><?php esc_html_e( 'Exit review', 'hxrv-ai-ready-visual-review' ); ?></a>
			</div>

			<?php
			// The list endpoint params live in the URL, NOT in hx-vals:
			// hx-vals on a container is inherited by every descendant and
			// overrides form inputs — the reply form's action=hxrv_reply
			// would be clobbered to hxrv_list. Hard-won lesson.
			?>
			<div
				id="hxrv-comments"
				hx-get="<?php echo esc_url( self::list_url() ); ?>"
				hx-trigger="load"
				hx-swap="innerHTML"
			></div>

			<div id="hxrv-orphan-tray">
				<p class="hxrv-orphan-tray__label" hidden><?php esc_html_e( 'Comments that lost their element', 'hxrv-ai-ready-visual-review' ); ?></p>
			</div>

			<form
				class="hxrv-draft"
				x-show="draft !== null"
				x-cloak
				:style="draftPopoverStyle()"
				@submit.prevent="submitDraft()"
			>
				<p class="hxrv-draft__warn" x-show="draft && draft.isDynamic"><?php esc_html_e( 'This element may be dynamic content — the pin could drift.', 'hxrv-ai-ready-visual-review' ); ?></p>
				<textarea
					x-ref="draftContent"
					x-model="draftText"
					rows="3"
					placeholder="<?php esc_attr_e( 'Leave a comment…', 'hxrv-ai-ready-visual-review' ); ?>"
					required
				></textarea>
				<div class="hxrv-draft__actions">
					<button type="submit" class="hxrv-btn hxrv-btn--primary"><?php esc_html_e( 'Comment', 'hxrv-ai-ready-visual-review' ); ?></button>
					<button type="button" class="hxrv-btn" @click="cancelDraft()"><?php esc_html_e( 'Cancel', 'hxrv-ai-ready-visual-review' ); ?></button>
				</div>
			</form>

		</div>
		<?php
	}

	/**
	 * "Review this page" toggle in the admin bar.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 */
	public static function admin_bar_link( $bar ) {
		if ( is_admin() || ! current_user_can( hxrv_capability() ) ) {
			return;
		}

		$active = self::is_active();
		$url    = $active
			? remove_query_arg( 'hxrv' )
			: add_query_arg( 'hxrv', '1' );

		$bar->add_node(
			array(
				'id'    => 'hxrv-toggle',
				'title' => $active ? __( 'Exit Review', 'hxrv-ai-ready-visual-review' ) : __( 'Review this page', 'hxrv-ai-ready-visual-review' ),
				'href'  => esc_url( $url ),
			)
		);
	}
}
