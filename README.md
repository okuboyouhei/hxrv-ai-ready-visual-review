# HXRV — AI-Ready Visual Review

**ページ上の修正依頼を、AIエージェントが読める構造化Markdownに変換するWordPressビジュアルレビューツール。**

[![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-blue?logo=wordpress)](https://wordpress.org/plugins/hxrv-ai-ready-visual-review/)
[![Version](https://img.shields.io/badge/version-1.2.0-green)](https://github.com/okuboyouhei/hxrv-ai-ready-visual-review/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0-orange)](https://www.gnu.org/licenses/gpl-2.0.html)
[![HX Series](https://img.shields.io/badge/HX%20Series-3rd-0F6E56)](https://zenn.dev/youheiokubo)

---

## 概要

レビュアー（クライアント・編集者）は公開ページ上で要素をクリックしてコメントをピン留め。エンジニアは未対応コメントを**1クリックでMarkdownの修正指示書としてエクスポート**し、Claude CodeなどのAIエージェントに渡せます。

```markdown
## Page: https://example.com/

### Fix #12
- **Selector**: `#hero .hero__title`
- **Element text (excerpt)**: "私たちについて"
- **Comment**: 英語表記に変更してください: About Us
```

エクスポートMDの冒頭にはAIエージェント向けの前置き（「セレクタを手がかりにテーマのテンプレートをgrepせよ」等）が自動で付きます。**依頼 → 修正の間にあった読み解き作業が消えます。**

---

## 特徴

- **ピンは座標ではなく要素アンカー** — CSSセレクタ + 相対%オフセットで保存。クライアントがスマホで打ったピンが、エンジニアのPCでも同じ要素の上に出る（フルレスポンシブ対応）
- **3段フォールバック** — セレクタ失効時はテキスト抜粋で再アンカー、それも失敗なら「Orphaned」トレイに退避。コメントが黙って消えない
- **スレッド・Resolve** — 返信、ステータス管理、ピンナビゲーション（チップクリックでスクロール + 波紋）
- **AI可読MDエクスポート** — 未対応コメントを構造化された修正指示書として一括出力
- **Before / After 入力（v1.2.0）** — コメントに「現状」と「あるべき姿」を任意で添えられる。デフォルトは折りたたみで通常のワンクリック入力はそのまま。入力するとエクスポートに `Before (current) → After (expected)` として出力され、エージェントに修正の差分が明確に伝わる
- **Template Infoパネル（v1.1.0）** — 表示中ページのメインテンプレート・get_template_part・エンキュー済みCSS/JSを一覧して「Copy as MD」。**どの要素を直すか（ピン）+ どのファイルを触るか（Template Info）** の両方をAIに渡せる
- **データは自サイト完結** — カスタムテーブル1つ、外部送信ゼロ、アンインストールで痕跡ゼロ。案件の期間だけ入れて納品時に消す運用が可能
- **htmx + Alpine.js同梱** — テーマが既に読み込んでいる場合は検出してスキップ（二重読み込みなし）。ビルド不要

---

## 使い方

1. プラグインを有効化し、対象ページに `?hxrv` を付けてアクセス（要ログイン + 権限）
2. 「Comment」モードで要素をクリック → コメントを入力してピン留め（必要なら「＋ Before / After」で現状とあるべき姿も添える）
3. 管理画面 → HXRV でコメント一覧・ステータス管理
4. 「Export」で未対応コメントをMDダウンロード → AIエージェントに貼り付け
5. レビューモードのツールバー「Template」で、表示中ページのテンプレート情報もMDコピー可能（v1.1.0）

---

## WAHXスタックでの位置づけ

| プラグイン | 役割 | WordPress.org |
|---|---|---|
| [HXFE](https://github.com/okuboyouhei/hxfe-code-first-forms) | フォーム収集 | [hxfe-code-first-forms](https://wordpress.org/plugins/hxfe-code-first-forms/) |
| [HXSE](https://github.com/okuboyouhei/hxse-code-first-search) | 情報検索 | [hxse-code-first-search](https://wordpress.org/plugins/hxse-code-first-search/) |
| **HXRV** | フィードバック収集 | [hxrv-ai-ready-visual-review](https://wordpress.org/plugins/hxrv-ai-ready-visual-review/) |
| [HXMD](https://github.com/okuboyouhei/hxmd-markdown-log-manager) | ログ構造化保存 | [hxmd-markdown-log-manager](https://wordpress.org/plugins/hxmd-markdown-log-manager/) |

**HXMD連携（v1.0.1+）**: `hxrv_after_comment_created` フックにより、ピンコメントをHXMDのログとして自動取り込みできます（HXMD側の設定でON）。

**a-blog cms版**もあります: [hxrv-ai-ready-visual-review-acms](https://github.com/okuboyouhei/hxrv-ai-ready-visual-review-acms)

---

## テスト

jsdomによるDOMレベルの回帰テスト26項目を `tests/` に同梱（返信フロー・ピン配置・Orphaned退避・ナビゲーション）。ブラウザなしで数秒で回ります。AIエージェントに修正を任せる際のセーフティネットとしても機能します。

```bash
cd tests && npm install && npm test
```

---

## 技術スタック

| 項目 | 内容 |
|---|---|
| データ保存 | カスタムテーブル `{prefix}hxrv_comments` |
| フロント | Alpine.js + htmx（同梱・テーマ検出あり） |
| ビルドステップ | なし |
| 外部送信 | なし |
| AI API | 使用しない（MD出力に徹する） |

---

## 更新履歴

- **v1.2.0** — コメントに任意の Before / After 欄を追加（AI-ready MDに `Before → After` として出力）。DBスキーマ拡張（`before_text` / `after_text`、`HXRV_DB_VERSION` 1→2、更新時に自動マイグレーション）
- **v1.1.0** — Template Infoパネル（テンプレート・テンプレートパーツ・アセットのMDコピー）
- **v1.0.1** — `hxrv_after_comment_created` アクションフック（HXMD連携用）
- **v1.0.0** — 初回リリース

---

## ライセンス

GPL-2.0-or-later — [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 作者

**youheiokubo** — Nagoya, Japan  
Engineer & Director at CAMP inc.  
[Zenn](https://zenn.dev/youheiokubo) · [WordPress.org](https://profiles.wordpress.org/youheiokubo/)
