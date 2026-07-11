/**
 * HXRV overlay — hxrv-overlay.js
 *
 * Alpine: client state (comment mode, hover highlight, draft popover)
 * htmx:   server sync (list / reply / resolve / delete via fragments)
 * This file: selector generation, re-anchoring, thread pinning, orphan tray.
 *
 * Expects (localized): HXRV = { ajaxUrl, nonce, pageUrl, i18n }
 */
(function () {
	'use strict';

	// Belt-and-braces: if this file somehow loads twice (theme bundling
	// an unrecognized copy, aggressive optimizers), a second execution
	// would double every delegated listener. Bail out instead.
	if (window.__hxrvOverlayLoaded) {
		return;
	}
	window.__hxrvOverlayLoaded = true;

	/* ---------------------------------------------------------------
	 * Selector generation
	 * Priority: #id → stable classes → nth-of-type. Verified unique.
	 * ------------------------------------------------------------- */

	// State classes, animation-library classes, and Gutenberg's
	// dynamically generated layout/support classes (they change on theme
	// or core updates, or per-save for wp-container-*) are all unstable
	// anchors — never let them into a stored selector.
	var UNSTABLE_CLASS = /^(is-|has-|active$|open$|hover|focus|aos-|swiper-slide-|hxrv-|wp-container-|wp-elements-|wp-duotone-)|-is-(layout|content-justification)-/;

	function stableClasses(el) {
		return Array.prototype.filter.call(el.classList, function (c) {
			return !UNSTABLE_CLASS.test(c);
		});
	}

	function segment(el) {
		if (el.id) {
			return '#' + CSS.escape(el.id);
		}
		var sel = el.tagName.toLowerCase();
		var classes = stableClasses(el);
		if (classes.length) {
			sel += '.' + classes.slice(0, 2).map(function (c) { return CSS.escape(c); }).join('.');
		}
		var parent = el.parentElement;
		if (parent) {
			var same = Array.prototype.filter.call(parent.children, function (sib) {
				return sib.tagName === el.tagName;
			});
			if (same.length > 1) {
				sel += ':nth-of-type(' + (same.indexOf(el) + 1) + ')';
			}
		}
		return sel;
	}

	function fullPath(el) {
		var parts = [];
		var node = el;
		while (node && node !== document.body) {
			var idx = 1;
			var sib = node;
			while ((sib = sib.previousElementSibling)) {
				if (sib.tagName === node.tagName) idx++;
			}
			parts.unshift(node.tagName.toLowerCase() + ':nth-of-type(' + idx + ')');
			node = node.parentElement;
		}
		return 'body > ' + parts.join(' > ');
	}

	function buildSelector(el) {
		var parts = [];
		var node = el;
		while (node && node !== document.body) {
			var seg = segment(node);
			parts.unshift(seg);
			if (seg.charAt(0) === '#') break;
			node = node.parentElement;
		}
		var selector = parts.join(' > ');
		try {
			if (document.querySelectorAll(selector).length === 1) {
				return selector;
			}
		} catch (e) { /* fall through */ }
		return fullPath(el);
	}

	function looksDynamic(el) {
		return !!el.closest(
			'.wp-block-latest-posts, .wp-block-query, [class*="archive"], [class*="post-list"], [class*="news-list"]'
		);
	}

	function textExcerpt(el) {
		return (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 120);
	}

	/* ---------------------------------------------------------------
	 * Re-anchoring: selector → saved text excerpt → orphan tray
	 * ------------------------------------------------------------- */

	function resolveTarget(selector, savedText) {
		var el = null;
		try {
			el = document.querySelector(selector);
		} catch (e) { /* invalid selector after template edits */ }

		if (el && !el.closest('#hxrv-root')) return el;
		if (!savedText) return null;

		var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
		var node;
		while ((node = walker.nextNode())) {
			if (node.closest('#hxrv-root')) continue;
			if (node.children.length < 10 && textExcerpt(node) === savedText) {
				return node;
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------
	 * Thread pinning
	 * Each .hxrv-thread fragment carries data-hxrv-* anchor attributes.
	 * We inject a numbered marker, collapse the card, and absolutely
	 * position the thread at its element (document coordinates —
	 * #hxrv-root sits at the document origin, see CSS).
	 * ------------------------------------------------------------- */

	function ensureMarker(threadEl, number) {
		var marker = threadEl.querySelector('.hxrv-thread__marker');
		if (!marker) {
			marker = document.createElement('button');
			marker.type = 'button';
			marker.className = 'hxrv-thread__marker';
			marker.addEventListener('click', function () {
				threadEl.classList.toggle('is-open');
			});
			threadEl.insertBefore(marker, threadEl.firstChild);
			threadEl.classList.add('hxrv-thread--pinned');
		}
		marker.textContent = String(number);
	}

	/* ---------------------------------------------------------------
	 * Anchor status sync: tell the server when a thread loses (or
	 * regains) its element, so admin counts and the export stay true.
	 * The server enforces the allowed transitions; we just report.
	 * ------------------------------------------------------------- */

	var anchorReported = {};

	function reportAnchor(threadEl, found) {
		var id = threadEl.getAttribute('data-hxrv-id');
		var status = threadEl.getAttribute('data-hxrv-status');
		if (!id) return;

		// Only the transitions the server would act on are worth a request.
		if (found && 'orphaned' !== status) return;
		if (!found && 'open' !== status) return;

		var want = found ? 'open' : 'orphaned';
		if (anchorReported[id] === want) return;
		anchorReported[id] = want;
		threadEl.setAttribute('data-hxrv-status', want);

		var body = new FormData();
		body.append('action', 'hxrv_anchor');
		body.append('id', id);
		body.append('found', found ? '1' : '0');
		body.append('nonce', HXRV.nonce);
		fetch(HXRV.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.catch(function (err) {
				window.console && console.error(err);
			});
	}

	function positionThread(threadEl, number) {
		ensureMarker(threadEl, number);

		var target = resolveTarget(
			threadEl.getAttribute('data-hxrv-selector'),
			threadEl.getAttribute('data-hxrv-selector-text')
		);

		if (!target) {
			reportAnchor(threadEl, false);
			orphan(threadEl);
			return;
		}

		reportAnchor(threadEl, true);
		threadEl.classList.remove('hxrv-thread--orphaned');
		var rect = target.getBoundingClientRect();
		var ox = parseFloat(threadEl.getAttribute('data-hxrv-offset-x') || '50') / 100;
		var oy = parseFloat(threadEl.getAttribute('data-hxrv-offset-y') || '0') / 100;
		threadEl.style.left = (window.scrollX + rect.left + rect.width * ox) + 'px';
		threadEl.style.top = (window.scrollY + rect.top + rect.height * oy) + 'px';
	}

	function orphan(threadEl) {
		var tray = document.getElementById('hxrv-orphan-tray');
		if (!tray) return;
		threadEl.classList.add('hxrv-thread--orphaned');
		threadEl.style.left = '';
		threadEl.style.top = '';
		tray.appendChild(threadEl);
		var label = tray.querySelector('.hxrv-orphan-tray__label');
		if (label) label.hidden = false;
	}

	function positionAll() {
		var container = document.getElementById('hxrv-comments');
		if (!container) return;
		var n = 0;
		// Threads may live in #hxrv-comments or already in the orphan tray.
		document.querySelectorAll('#hxrv-root .hxrv-thread').forEach(function (threadEl) {
			n++;
			positionThread(threadEl, n);
		});
		renderNav();
	}

	/* ---------------------------------------------------------------
	 * Pin navigation: numbered chips in the toolbar, one per thread,
	 * mirroring the marker status grammar (green = open, hollow =
	 * resolved, amber = orphaned). Click scrolls to the pin, opens
	 * the thread, and pulses its marker. Rebuilt on every repaint so
	 * it can never drift from the pins.
	 * ------------------------------------------------------------- */

	function renderNav() {
		var nav = document.getElementById('hxrv-nav');
		if (!nav) return;

		nav.textContent = '';
		var n = 0;
		document.querySelectorAll('#hxrv-root .hxrv-thread').forEach(function (threadEl) {
			n++;
			var id = threadEl.getAttribute('data-hxrv-id');
			var status = threadEl.getAttribute('data-hxrv-status') || 'open';
			var chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'hxrv-nav__chip hxrv-nav__chip--' + status;
			chip.textContent = String(n);
			chip.setAttribute('data-hxrv-target', id || '');
			chip.setAttribute('aria-label', 'Comment ' + n + ' (' + status + ')');
			nav.appendChild(chip);
		});
	}

	function jumpToThread(id) {
		var threadEl = document.querySelector('.hxrv-thread[data-hxrv-id="' + id + '"]');
		if (!threadEl) return;

		if (threadEl.classList.contains('hxrv-thread--orphaned')) {
			// Orphaned pins have no page position — reveal the tray instead.
			var tray = document.getElementById('hxrv-orphan-tray');
			if (tray && typeof tray.scrollIntoView === 'function') {
				tray.scrollIntoView({ behavior: 'smooth', block: 'end' });
			}
			pulse(threadEl);
			return;
		}

		var top = parseFloat(threadEl.style.top || '0');
		if (typeof window.scrollTo === 'function') {
			window.scrollTo({
				top: Math.max(0, top - window.innerHeight / 3),
				behavior: 'smooth'
			});
		}
		threadEl.classList.add('is-open');
		if (id) openIds[id] = true;
		pulse(threadEl);
	}

	function pulse(threadEl) {
		var marker = threadEl.querySelector('.hxrv-thread__marker');
		var target = marker || threadEl;
		target.classList.remove('hxrv-pulse');
		// Force reflow so re-adding the class restarts the animation.
		void target.offsetWidth;
		target.classList.add('hxrv-pulse');
	}

	document.addEventListener('click', function (e) {
		var chip = e.target && e.target.closest ? e.target.closest('.hxrv-nav__chip') : null;
		if (!chip) return;
		jumpToThread(chip.getAttribute('data-hxrv-target'));
	});

	/* ---------------------------------------------------------------
	 * Open-state bookkeeping + deterministic list refresh
	 * ------------------------------------------------------------- */

	var openIds = {};

	function snapshotOpen() {
		openIds = {};
		document.querySelectorAll('.hxrv-thread.is-open').forEach(function (t) {
			var id = t.getAttribute('data-hxrv-id');
			if (id) openIds[id] = true;
		});
	}

	function applyOpen() {
		Object.keys(openIds).forEach(function (id) {
			var t = document.querySelector('.hxrv-thread[data-hxrv-id="' + id + '"]');
			if (t) t.classList.add('is-open');
		});
	}

	/* Reassigned by boot() to the observer-safe repaint. Fallback keeps
	 * things working even if boot has not run yet. */
	var requestRepaint = function () {
		positionAll();
		applyOpen();
	};

	/**
	 * The single, deterministic mutation path: every change (create,
	 * reply, resolve, delete) re-fetches the full list, injects it into
	 * the stable container, and repaints IMMEDIATELY in the same tick.
	 * No htmx swap, no event bubbling, no observer timing involved.
	 */
	function refreshList() {
		snapshotOpen();
		fetch(HXRV.listUrl, { credentials: 'same-origin' })
			.then(function (res) {
				if (!res.ok) throw new Error('HXRV: list failed (' + res.status + ')');
				return res.text();
			})
			.then(function (html) {
				var container = document.getElementById('hxrv-comments');
				if (!container) return;
				container.innerHTML = html;
				requestRepaint();
			})
			.catch(function (err) {
				window.console && console.error(err);
			});
	}

	/* ---------------------------------------------------------------
	 * Alpine overlay component (markup lives in class-hxrv-frontend.php)
	 * ------------------------------------------------------------- */

	window.hxrvOverlay = function () {
		return {
			commentMode: false,
			templateOpen: false,
			draft: null,
			draftText: '',
			_hoverEl: null,

			copyTemplateInfo: function (e) {
				var pre = document.getElementById('hxrv-template-md');
				var btn = e.target;
				if (!pre) {
					return;
				}
				var write = function (text) {
					if (navigator.clipboard && window.isSecureContext) {
						return navigator.clipboard.writeText(text);
					}
					// HTTP環境フォールバック
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.style.position = 'fixed';
					ta.style.opacity = '0';
					document.body.appendChild(ta);
					ta.focus();
					ta.select();
					document.execCommand('copy');
					document.body.removeChild(ta);
					return Promise.resolve();
				};
				write(pre.textContent).then(function () {
					var orig = btn.textContent;
					btn.textContent = 'Copied!';
					setTimeout(function () { btn.textContent = orig; }, 2000);
				});
			},

			boot: function () {
				var self = this;
				var root = document.getElementById('hxrv-root');
				var raf = 0;
				var observer = null;

				// Snapshot open threads before htmx swaps too (initial
				// hx-get load); the deterministic refreshList() path takes
				// its own snapshot.
				document.body.addEventListener('htmx:beforeSwap', snapshotOpen);

				// Repaint = position pins + restore open state. The observer
				// is disconnected while running: positionAll itself mutates
				// the DOM (marker injection, orphan moves) and would
				// self-trigger in a loop otherwise.
				function repaint() {
					if (observer) observer.disconnect();
					positionAll();
					applyOpen();
					if (observer && root) {
						observer.observe(root, { childList: true, subtree: true });
					}
				}

				// Module-level code (refreshList) repaints through this hook
				// so the observer bookkeeping stays consistent.
				requestRepaint = repaint;

				function scheduleRepaint() {
					cancelAnimationFrame(raf);
					raf = requestAnimationFrame(repaint);
				}

				// Watch the overlay for swapped-in content directly. Unlike
				// htmx events, a MutationObserver fires regardless of swap
				// style (innerHTML / outerHTML / oob), event bubbling from
				// detached nodes, and htmx's post-swap attribute restoration
				// — the rAF debounce runs after htmx has fully finished.
				if (root && window.MutationObserver) {
					observer = new MutationObserver(scheduleRepaint);
					observer.observe(root, { childList: true, subtree: true });
				}

				// All thread mutations (reply / resolve / delete) go through
				// fetch → refreshList, the same path as comment creation:
				// the stable #hxrv-comments container gets an innerHTML swap,
				// which the observer reliably sees. We never let htmx
				// outerHTML-swap a positioned thread (its placement and
				// marker would be lost — the v0.1.3 "thread at page top" bug).

				// Reply forms (capture: also blocks any native GET fallback
				// that would drop ?hxrv and discard the input).
				document.addEventListener('submit', function (e) {
					var form = e.target;
					if (!form || !form.closest || !form.closest('#hxrv-root')) return;
					e.preventDefault();

					if (!form.classList.contains('hxrv-reply-form')) return; // draft form is handled by Alpine.

					var content = form.querySelector('textarea[name="content"]');
					if (!content || '' === content.value.trim()) return;

					var btn = form.querySelector('button[type="submit"]');
					if (btn) btn.disabled = true;

					fetch(HXRV.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: new FormData(form) })
						.then(function (res) {
							if (!res.ok) throw new Error('HXRV: reply failed (' + res.status + ')');
							refreshList();
						})
						.catch(function (err) {
							if (btn) btn.disabled = false;
							window.console && console.error(err);
						});
				}, true);

				// Resolve / Reopen / Delete buttons.
				document.addEventListener('click', function (e) {
					var btn = e.target && e.target.closest ? e.target.closest('[data-hxrv-action]') : null;
					if (!btn || !btn.closest('#hxrv-root')) return;

					var confirmMsg = btn.getAttribute('data-hxrv-confirm');
					if (confirmMsg && !window.confirm(confirmMsg)) return;

					var body = new FormData();
					body.append('action', btn.getAttribute('data-hxrv-action'));
					body.append('id', btn.getAttribute('data-hxrv-id'));
					body.append('nonce', btn.getAttribute('data-hxrv-nonce'));
					if (btn.getAttribute('data-hxrv-status')) {
						body.append('status', btn.getAttribute('data-hxrv-status'));
					}

					btn.disabled = true;
					fetch(HXRV.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (res) {
							if (!res.ok) throw new Error('HXRV: action failed (' + res.status + ')');
							refreshList();
						})
						.catch(function (err) {
							btn.disabled = false;
							window.console && console.error(err);
						});
				});

				// Surface failed requests instead of failing silently.
				document.body.addEventListener('htmx:responseError', function (evt) {
					if (window.console) {
						console.error('HXRV: request failed', evt.detail && evt.detail.xhr ? evt.detail.xhr.status : '', evt.detail && evt.detail.xhr ? evt.detail.xhr.responseText : '');
					}
				});

				var resizeTimer;
				window.addEventListener('resize', function () {
					clearTimeout(resizeTimer);
					resizeTimer = setTimeout(scheduleRepaint, 150);
				});

				document.addEventListener('keydown', function (e) {
					if ('Escape' !== e.key) return;
					if (self.draft) {
						self.cancelDraft();
					} else if (self.commentMode) {
						self.toggleCommentMode();
					}
				});
			},

			toggleCommentMode: function () {
				this.commentMode = !this.commentMode;
				document.documentElement.classList.toggle('hxrv-commenting', this.commentMode);
				if (this.commentMode) {
					this.bindCapture();
				} else {
					this.unbindCapture();
					this.clearHover();
				}
			},

			bindCapture: function () {
				this._onMove = this.onMove.bind(this);
				this._onClick = this.onClick.bind(this);
				// Capture phase: intercept before the reviewed page's own
				// links / Alpine components can react.
				document.addEventListener('mousemove', this._onMove, true);
				document.addEventListener('click', this._onClick, true);
			},

			unbindCapture: function () {
				document.removeEventListener('mousemove', this._onMove, true);
				document.removeEventListener('click', this._onClick, true);
			},

			onMove: function (e) {
				var el = e.target;
				if (el.closest('#hxrv-root') || el.closest('#wpadminbar') || el === this._hoverEl) return;
				this.clearHover();
				this._hoverEl = el;
				el.classList.add('hxrv-hover');
			},

			clearHover: function () {
				if (this._hoverEl) {
					this._hoverEl.classList.remove('hxrv-hover');
					this._hoverEl = null;
				}
			},

			onClick: function (e) {
				var el = e.target;
				// Let the overlay's own UI and the admin bar work normally.
				if (el.closest('#hxrv-root') || el.closest('#wpadminbar')) return;
				e.preventDefault();
				e.stopPropagation();

				var rect = el.getBoundingClientRect();
				this.draft = {
					selector: buildSelector(el),
					selectorText: textExcerpt(el),
					offsetX: rect.width ? (((e.clientX - rect.left) / rect.width) * 100).toFixed(2) : '50',
					offsetY: rect.height ? (((e.clientY - rect.top) / rect.height) * 100).toFixed(2) : '0',
					isDynamic: looksDynamic(el),
					pageX: e.pageX,
					pageY: e.pageY
				};
				this.draftText = '';
				this.clearHover();

				var self = this;
				this.$nextTick(function () {
					if (self.$refs.draftContent) self.$refs.draftContent.focus();
				});
			},

			draftPopoverStyle: function () {
				if (!this.draft) return '';
				var w = 300;
				var h = 170;
				var left = Math.min(this.draft.pageX, window.scrollX + window.innerWidth - w - 16);
				var top = Math.min(this.draft.pageY, window.scrollY + window.innerHeight - h - 16);
				return 'left:' + Math.max(window.scrollX + 8, left) + 'px;top:' + Math.max(window.scrollY + 8, top) + 'px;';
			},

			submitDraft: function () {
				if (!this.draft || '' === this.draftText.trim()) return;

				var self = this;
				var body = new FormData();
				body.append('action', 'hxrv_create');
				body.append('nonce', HXRV.nonce);
				body.append('page_url', HXRV.pageUrl);
				body.append('selector', this.draft.selector);
				body.append('selector_text', this.draft.selectorText);
				body.append('offset_x', this.draft.offsetX);
				body.append('offset_y', this.draft.offsetY);
				body.append('is_dynamic', this.draft.isDynamic ? '1' : '0');
				body.append('content', this.draftText);

				fetch(HXRV.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (res) {
						if (!res.ok) throw new Error('HXRV: create failed (' + res.status + ')');
						self.draft = null;
						self.draftText = '';
						if (self.commentMode) self.toggleCommentMode();
						refreshList();
					})
					.catch(function (err) {
						window.console && console.error(err);
					});
			},

			cancelDraft: function () {
				this.draft = null;
				this.draftText = '';
			}
		};
	};
})();
