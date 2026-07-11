<?php
/**
 * HXRV_Template_Info — 表示中ページのテンプレート・アセット情報の収集
 *
 * レビューモード時のみ、ページ描画中に以下を記録する:
 * - メインテンプレート（template_include）
 * - get_template_part の呼び出し（slug / name）
 * - エンキューされたCSS / JS（ハンドル・src・依存）
 *
 * 収集した情報はオーバーレイのパネルに表示し、AI可読なMDとしてコピーできる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HXRV_Template_Info {

	/** @var string メインテンプレートの絶対パス */
	private static $template = '';

	/** @var array get_template_part の呼び出し記録 */
	private static $parts = array();

	/** @var array 収集したスタイル情報 */
	private static $styles = array();

	/** @var array 収集したスクリプト情報 */
	private static $scripts = array();

	public static function init() {
		// レビューモード判定は is_active() だが、template_include は
		// wp_enqueue_scripts より前に走るため、フック登録自体は常時行い
		// 各コールバック内で is_active() を確認する（オーバーヘッドは実質ゼロ）。
		add_filter( 'template_include', array( __CLASS__, 'capture_template' ), PHP_INT_MAX );
		add_action( 'get_template_part', array( __CLASS__, 'capture_part' ), 10, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'capture_assets' ), 1 ); // overlay(=wp_footer default 10)より先に収集する。
	}

	/**
	 * メインテンプレートを記録する。フィルターなので必ず $template を返す。
	 *
	 * @param string $template テンプレートの絶対パス
	 * @return string
	 */
	public static function capture_template( $template ) {
		if ( HXRV_Frontend::is_active() ) {
			self::$template = $template;
		}
		return $template;
	}

	/**
	 * get_template_part の呼び出しを記録する。
	 *
	 * @param string      $slug テンプレートスラッグ
	 * @param string|null $name テンプレート名
	 * @param array       $templates 候補ファイル名の配列
	 */
	public static function capture_part( $slug, $name, $templates ) {
		if ( ! HXRV_Frontend::is_active() ) {
			return;
		}
		self::$parts[] = array(
			'slug'      => (string) $slug,
			'name'      => (string) ( $name ?? '' ),
			'candidate' => isset( $templates[0] ) ? (string) $templates[0] : '',
		);
	}

	/**
	 * エンキュー済みのCSS / JSを列挙する（wp_footer時点 = ほぼ確定状態）。
	 */
	public static function capture_assets() {
		if ( ! HXRV_Frontend::is_active() ) {
			return;
		}
		global $wp_styles, $wp_scripts;

		if ( $wp_styles instanceof WP_Styles ) {
			foreach ( array_unique( array_merge( $wp_styles->done, $wp_styles->queue ) ) as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$obj = $wp_styles->registered[ $handle ];
				self::$styles[] = array(
					'handle' => $handle,
					'src'    => (string) $obj->src,
					'deps'   => $obj->deps,
				);
			}
		}

		if ( $wp_scripts instanceof WP_Scripts ) {
			foreach ( array_unique( array_merge( $wp_scripts->done, $wp_scripts->queue ) ) as $handle ) {
				if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
					continue;
				}
				$obj = $wp_scripts->registered[ $handle ];
				self::$scripts[] = array(
					'handle' => $handle,
					'src'    => (string) $obj->src,
					'deps'   => $obj->deps,
				);
			}
		}
	}

	/**
	 * 収集結果をAI可読なMarkdownとして組み立てる。
	 *
	 * @return string
	 */
	public static function build_markdown() {
		$theme_dir = get_stylesheet_directory();
		$rel       = function ( $path ) use ( $theme_dir ) {
			// テーマからの相対パスにして読みやすく。テーマ外はABSPATH相対。
			if ( 0 === strpos( $path, $theme_dir ) ) {
				return ltrim( str_replace( $theme_dir, '', $path ), '/' );
			}
			return ltrim( str_replace( ABSPATH, '', $path ), '/' );
		};

		$lines   = array();
		$lines[] = '# Template Info';
		$lines[] = 'Page: ' . home_url( remove_query_arg( 'hxrv' ) );
		$lines[] = 'Theme: ' . get_stylesheet();
		$lines[] = 'Generated: ' . date_i18n( 'Y-m-d H:i' );
		$lines[] = '';
		$lines[] = '## Main Template';
		$lines[] = '';
		$lines[] = '- `' . ( self::$template ? $rel( self::$template ) : '(not captured)' ) . '`';

		if ( ! empty( self::$parts ) ) {
			$lines[] = '';
			$lines[] = '## Template Parts (get_template_part)';
			$lines[] = '';
			foreach ( self::$parts as $part ) {
				$label   = $part['slug'] . ( $part['name'] ? '-' . $part['name'] : '' );
				$lines[] = '- `' . $label . '.php`' . ( $part['candidate'] ? ' (candidate: `' . $part['candidate'] . '`)' : '' );
			}
		}

		if ( ! empty( self::$styles ) ) {
			$lines[] = '';
			$lines[] = '## Enqueued CSS';
			$lines[] = '';
			foreach ( self::$styles as $style ) {
				$src     = $style['src'] ? ' — ' . $style['src'] : ' — (inline/registered only)';
				$deps    = $style['deps'] ? ' (deps: ' . implode( ', ', $style['deps'] ) . ')' : '';
				$lines[] = '- `' . $style['handle'] . '`' . $src . $deps;
			}
		}

		if ( ! empty( self::$scripts ) ) {
			$lines[] = '';
			$lines[] = '## Enqueued JS';
			$lines[] = '';
			foreach ( self::$scripts as $script ) {
				$src     = $script['src'] ? ' — ' . $script['src'] : ' — (inline/registered only)';
				$deps    = $script['deps'] ? ' (deps: ' . implode( ', ', $script['deps'] ) . ')' : '';
				$lines[] = '- `' . $script['handle'] . '`' . $src . $deps;
			}
		}

		return implode( "\n", $lines );
	}
}
