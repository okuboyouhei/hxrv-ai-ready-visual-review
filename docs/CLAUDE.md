# HXRV – AI-Ready Visual Review

Self-hosted visual review plugin for WordPress. Part of the HX Series
(HXFE, HXSE). Reviewers pin comments to page elements on the live site;
engineers export open issues as an AI-ready Markdown brief and hand it
to a coding agent (Claude Code etc.).

## Positioning

- **Not** code-first (the core interaction is inherently GUI).
  The shared HX DNA here: htmx-powered, no build step, no SaaS,
  data never leaves the site, aggressive feature subtraction.
- Tagline: **AI-Ready Visual Review**. The MD export is the differentiator —
  no existing feedback plugin (BugHerd, Atarim, Noted…) outputs
  agent-consumable fix briefs.

## Architecture decisions (fixed — do not revisit without reason)

1. **Element-anchored, not coordinate-anchored.** Comments store a CSS
   selector + relative % offsets inside the element, so pins survive
   responsive layout changes. Competitors mostly use page coordinates.
2. **Custom table** (`{prefix}hxrv_comments`), not CPT. Reasons: fast
   per-page queries via `page_url_hash` (MD5 index), clean
   `DROP TABLE` on uninstall. dbDelta quirks apply (two spaces after
   `PRIMARY KEY`, no backticks).
3. **Three-stage anchor fallback:** selector → `selector_text`
   (120-char text excerpt) re-scan → `orphaned` status (never silently
   lose a comment after template edits).
4. **Alpine = client state** (comment mode, hover highlight, draft
   popover). **htmx = server sync** (list/create/reply/resolve as HTML
   fragments over admin-ajax). Bridge: Alpine state → hidden inputs
   in the draft form. Server triggers list refresh via
   `HX-Trigger: hxrv:refresh` response header.
5. **Capture-phase event interception** in comment mode
   (`addEventListener(..., true)` + preventDefault/stopPropagation) so
   links and the reviewed page's own Alpine components don't fire.
   Overlay UI (`#hxrv-root`) is exempted via `closest()` check.
6. **Dynamic-content heuristic:** elements inside
   `.wp-block-latest-posts`, `.wp-block-query`, archive/list classes get
   `is_dynamic = 1` and a ⚠ in the export (fix belongs in the loop
   template, not content). v1 does not try to be smarter than this.
7. **No settings page.** Configuration via filters only:
   `hxrv_capability`, `hxrv_normalize_url`, `hxrv_export_markdown`.

## v1 scope

In: login-only review mode (`?hxrv` + `edit_pages`), pin/thread/resolve,
admin list grouped by page, MD export (open + orphaned, AI preamble),
bidirectional anchor-status sync.
Out (v2+ roadmap, rough priority order):
1. **External reviewer share links** — page-scoped tokens + expiry,
   no login required. The single most requested capability for the
   client-review use case.
2. **Admin screen htmx-ification** — status tabs, per-page expansion,
   inline resolve, pagination. This is where htmx earns its "series
   axis" role: stable targets, server-driven swaps, none of the
   overlay's positioning pitfalls. (Decided 2026-07-05: HXRV keeps
   htmx as a series pillar; the overlay is Alpine's domain, the admin
   is htmx's.)
3. **Image review mode** — attachment pages already work with the
   overlay as-is (img element + relative offsets = Figma-style pins on
   design comps), but two gaps need closing before promoting it as a
   feature: (a) text-excerpt re-anchoring is useless on images
   (no textContent), so swapping the image file leaves stale pins
   silently pointing at a new design — anchor by image URL + relative
   coordinates instead on attachment pages; (b) the export's
   "grep the theme" instructions don't apply — emit image filename +
   coordinates for these pins. Until then it can be mentioned as an
   experimental use, not a feature.
4. Soft-delete ("trashed" status + admin restore) — decide after
   real-project use whether accidental client deletions are an actual
   problem (see Deletion semantics below).
5. Screenshots, email notifications, Slack integrations — still cut.

## Deletion semantics (v1, deliberate)

Delete is a physical DELETE (root + replies via parent_id), guarded by
one confirm dialog. Deactivation preserves all data; uninstall drops
the table and options entirely ("zero traces" promise in readme).
Resolve is the "keep the record" path; Delete is irreversible.

## File map

- `hxrv.php` — bootstrap, constants, `hxrv_capability()`
- `includes/class-hxrv-db.php` — dbDelta schema, CRUD, URL normalization
- `includes/class-hxrv-frontend.php` — asset loading (bundled htmx/Alpine,
  reuse theme's handles if registered), overlay root markup, admin bar link
- `includes/class-hxrv-ajax.php` — admin-ajax endpoints returning HTML
  fragments (nonce + capability guarded)
- `includes/class-hxrv-export.php` — MD brief builder + download endpoint
- `includes/class-hxrv-admin.php` — minimal list screen + export button
- `assets/js/hxrv.js` — selector generation (id → stable classes →
  nth-of-type; uniqueness-verified), text re-anchoring, pin positioning,
  Alpine component
- `assets/css/hxrv.css` — overlay styles, z-index 999998+

## TODO before WordPress.org submission

- [x] Bundle htmx (2.0.4) and Alpine.js (3.14.9) minified files into `assets/js/`
- [x] readme.txt (WordPress.org format), llms.txt (root), CLAUDE.md moved to
      `docs/` (Plugin Check `unexpected_markdown_file` avoidance)
- [x] Orphan tray UI + bidirectional anchor-status sync (hxrv_anchor endpoint)
- [x] Plugin Check: 0 errors confirmed (2026-07-05)
- [x] Design pass: token-based CSS (--hxrv-*), primary #0F6E56
- [x] tests/ — jsdom regression suite (20 assertions)
- [ ] i18n: generate .pot, Japanese translation (can land during review)
- [ ] ai-reference.md (place in docs/, not plugin root)
- [ ] Screenshots for readme.txt (4 planned; needed for the directory page,
      not for submission itself)

## Testing policy

**Run `cd tests && npm install && npm test` after ANY change to
hxrv-overlay.js.** The suite replays every overlay bug found during
v0.1.x development (hx-vals inheritance, outerHTML repositioning,
open-state loss, duplicate anchor reports). An AI agent making changes
must run it and report PASS before considering work done.

## Release procedure

1. Bump version in THREE places: hxrv.php header, HXRV_VERSION constant,
   readme.txt Stable tag. All three must match.
2. Run tests (above) + `php -l` on all PHP files + Plugin Check.
3. Build the distribution ZIP EXCLUDING dev files:
   `zip -r hxrv.zip hxrv -x "hxrv/tests/*" "hxrv/.gitignore" "hxrv/node_modules/*"`
4. Update SVN **and** GitHub together (HXFE lesson: recreating the ZIP
   for one and forgetting the other causes drift).

## Testing notes

- **htmx `hx-vals` inheritance (v0.1.2 lesson):** hx-vals on a container is
  inherited by ALL descendants and overrides their form input values. The
  reply form's `action=hxrv_reply` hidden input was silently clobbered to
  `hxrv_list` by the container's hx-vals, producing an empty 200 that
  outerHTML-swapped the thread away. Rule: never put hx-vals on containers;
  pass list params in the hx-get URL query string.
- Test on: classic theme template pages, block-editor pages, custom
  blocks — anchor stability differs (template HTML > block content >
  dynamic loops).
- Reviewed page may itself run Alpine (WAHX sites): multiple x-data
  roots coexist; only capture-phase interception matters.
- Safari: check `CSS.escape` availability (OK ≥10.1) and pin
  positioning with `window.scrollX/Y`.
