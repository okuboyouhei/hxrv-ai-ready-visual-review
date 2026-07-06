=== HXRV - AI-Ready Visual Review ===
Contributors: youheiokubo
Tags: feedback, review, comments, visual-feedback, ai
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted visual review. Pin comments to page elements, resolve them, and export open issues as an AI-ready Markdown brief.

== Description ==

Most feedback tools collect comments. HXRV turns them into a fix pipeline.

Reviewers click any element on the live page to pin a comment. Threads are discussed and resolved Figma-style. Then, instead of leaving a pile of tasks for a human to decipher, HXRV exports every open comment as a structured Markdown brief - selectors, element text, warnings included - ready to be handed directly to an AI coding agent such as Claude Code. The agent locates the template, the engineer reviews the diff, the reviewer checks the box.

**What makes HXRV different**

* **AI-ready export.** The fix brief records each comment's CSS selector, an excerpt of the element's text, dynamic-content warnings ("this pin sits inside a posts loop - fix the template, not the content"), and anchor-lost notices. An AI coding agent can grep your theme and go straight to the right file. No other feedback tool produces agent-consumable output.
* **Element-anchored pins.** Most tools store pins as page coordinates, so they drift on responsive layouts. HXRV anchors comments to the element itself: CSS selector plus a relative offset inside it. Pins follow their element at any viewport width.
* **Three-stage anchor fallback.** When a template edit breaks a selector, HXRV re-anchors by the element's saved text excerpt; if that fails too, the comment moves to an orphan tray instead of silently disappearing - and the export flags it so nobody (human or AI) acts on a stale pointer. Restore the element and the comment re-anchors itself.
* **Truly self-hosted.** Everything lives in one custom table in your own database. No SaaS account, no external requests, no tracking. Install it for a review cycle, uninstall it, and every trace is gone - including the table.
* **Lightweight by design.** Powered by htmx and Alpine.js, bundled locally (~100 KB total). No build step, no jQuery, no React widget injected into your client's site. Assets load only in review mode for authorized users.
* **Zero settings screens.** Configuration is code: filters (`hxrv_capability`, `hxrv_export_markdown`) and CSS custom properties (`--hxrv-primary` and friends) for restyling the overlay from your theme.

**The workflow**

1. A reviewer (client, editor, designer) opens the page, clicks an element, leaves a comment.
2. Threads collect replies; finished items get resolved.
3. The engineer exports the open comments as a Markdown brief and pastes it into an AI coding agent - or reads it themselves.
4. Fixes land, boxes get checked, the plugin can be removed without a trace.

Part of the HX Series (HXFE - forms, HXSE - search): htmx-powered WordPress tools that send HTML over the wire, skip the build step, and cut every feature that is not essential.

== Frequently Asked Questions ==

= Where is my data stored? =

In a custom table (`wp_hxrv_comments`) in your own WordPress database. Nothing is sent to any external service. Uninstalling the plugin removes the table completely.

= Who can leave comments? =

Logged-in users with the `edit_pages` capability (administrators and editors) by default. Change it with one line in your theme's functions.php:

`add_filter( 'hxrv_capability', fn() => 'publish_posts' ); // authors and above`
`add_filter( 'hxrv_capability', fn() => 'edit_posts' );    // contributors and above`
`add_filter( 'hxrv_capability', fn() => 'read' );          // any logged-in user`

For a review-only client account, return a custom capability (e.g. `hxrv_review`) and add it to the client's role with `add_cap()` - they get commenting rights without any editing rights. External reviewer share links (no login required) are planned for a future version.

= My theme already loads htmx / Alpine.js. Will they load twice? =

No. HXRV detects common script handles (`htmx`, `alpinejs`, and friends) and reuses your theme's copy instead of loading its own, while guaranteeing the load order the overlay needs. If your theme registers them under custom handles, declare them via the `hxrv_htmx_handles` / `hxrv_alpine_handles` filters.

= How do I start a review? =

Click "HXRV Review" in the admin bar on any front-end page, or append `?hxrv` to the URL while logged in.

= What is the AI export for? =

It turns open review comments into a structured Markdown brief. Paste it into an AI coding agent (Claude Code, etc.) and the agent can locate the relevant template files using the recorded CSS selectors and element text.

= Does it work with the block editor and custom blocks? =

Yes. Comments anchor to the rendered DOM, so classic templates, block content, and custom blocks all work. Elements inside dynamic loops (latest posts, query blocks) are flagged as possibly dynamic.

== Screenshots ==

1. Review overlay - click any element to pin a comment.
2. Threaded discussion and resolve button on a pin.
3. Admin list grouped by page, with the AI export button.
4. Exported Markdown brief ready for a coding agent.

== Changelog ==

= 0.1.0 =
* Initial release.
* Element-anchored pin comments with threaded replies and resolve flow.
* Text-excerpt re-anchoring and orphan handling.
* Admin overview grouped by page.
* AI-ready Markdown export of open comments.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
