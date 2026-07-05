=== HXRV - AI-Ready Visual Review ===
Contributors: youheiokubo
Tags: feedback, review, comments, visual-feedback, ai
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted visual review. Pin comments to page elements, resolve them, and export open issues as an AI-ready Markdown brief.

== Description ==

HXRV adds a lightweight review overlay to your WordPress site. Logged-in reviewers click any element on the live page to pin a comment. Threads can be discussed and resolved Figma-style. When it is time to fix things, engineers export all open comments as a Markdown brief designed to be handed directly to AI coding agents such as Claude Code.

**Why HXRV?**

* **Element-anchored comments.** Pins are stored as a CSS selector plus a relative offset - they survive responsive layout changes, unlike coordinate-based tools.
* **Three-stage anchor fallback.** Selector first, then a saved text excerpt re-scan, and finally an orphan tray. Comments never silently disappear after template edits.
* **AI-ready export.** One click produces a Markdown fix brief with selectors, element text excerpts, and instructions for a coding agent. Dynamic-content comments are flagged so the agent knows the fix belongs in a loop template.
* **Self-hosted.** All data lives in a custom table in your own database. No SaaS account, no external requests, nothing leaves your site.
* **Lightweight by design.** Powered by htmx and Alpine.js, bundled locally. No build step, no jQuery. Assets load only in review mode for authorized users.
* **Zero settings screens.** Configuration via filters only: `hxrv_capability`, `hxrv_export_markdown`.

Part of the HX Series (HXFE - Code-First Forms, HXSE - Code-First Search).

== Frequently Asked Questions ==

= Where is my data stored? =

In a custom table (`wp_hxrv_comments`) in your own WordPress database. Nothing is sent to any external service. Uninstalling the plugin removes the table completely.

= Who can leave comments? =

Logged-in users with the `edit_pages` capability. This is filterable via `hxrv_capability`. External reviewer share links are planned for a future version.

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
