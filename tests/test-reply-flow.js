/**
 * HXRV DOM-level regression test (jsdom).
 *
 * Reproduces the user's flow without a browser:
 *   1. Page with overlay root + a target paragraph.
 *   2. Initial list injected (simulating hx-get load) -> mutation ->
 *      thread must become pinned.
 *   3. Reply form submitted -> delegated handler -> stubbed fetch ->
 *      refreshList -> innerHTML -> IMMEDIATE repaint expected.
 *   4. Assert: no thread is ever left unpinned after the promise chain
 *      settles, and open state survives the refresh.
 */
const { JSDOM } = require('jsdom');
const fs = require('fs');

const overlayJs = fs.readFileSync(require('path').join(__dirname, '..', 'assets', 'js', 'hxrv-overlay.js'), 'utf8');

const threadFragment = (id, content, replies) => `
  <div class="hxrv-thread hxrv-status-open" id="hxrv-thread-${id}"
    data-hxrv-id="${id}"
    data-hxrv-selector="#target-p"
    data-hxrv-selector-text="ターゲット段落"
    data-hxrv-offset-x="50" data-hxrv-offset-y="10"
    data-hxrv-dynamic="0" data-hxrv-status="open">
    <div class="hxrv-comment hxrv-comment--root">
      <p class="hxrv-comment__content">${content}</p>
    </div>
    ${replies.map(r => `<div class="hxrv-comment hxrv-comment--reply"><p class="hxrv-comment__content">${r}</p></div>`).join('')}
    <footer class="hxrv-thread__actions">
      <button type="button" class="hxrv-btn hxrv-btn--resolve" data-hxrv-action="hxrv_set_status" data-hxrv-id="${id}" data-hxrv-status="resolved" data-hxrv-nonce="testnonce">Resolve</button>
      <form class="hxrv-reply-form" action="" method="post">
        <input type="hidden" name="action" value="hxrv_reply" />
        <input type="hidden" name="parent_id" value="${id}" />
        <input type="hidden" name="nonce" value="testnonce" />
        <textarea name="content"></textarea>
        <button type="submit" class="hxrv-btn">Reply</button>
      </form>
    </footer>
  </div>`;

const dom = new JSDOM(`<!DOCTYPE html><html><body>
  <p id="target-p">ターゲット段落</p>
  <div id="hxrv-root">\n    <div class="hxrv-toolbar"><nav id="hxrv-nav" class="hxrv-toolbar__nav"></nav></div>
    <div class="hxrv-toolbar"></div>
    <div id="hxrv-comments"></div>
    <div id="hxrv-orphan-tray"><p class="hxrv-orphan-tray__label" hidden></p></div>
  </div>
</body></html>`, { url: 'http://localhost/', pretendToBeVisual: true, runScripts: 'outside-only' });

const { window } = dom;
global.window = window;

// --- environment stubs -------------------------------------------------
window.HXRV = { ajaxUrl: 'http://localhost/ajax', listUrl: 'http://localhost/list', nonce: 'testnonce', pageUrl: 'http://localhost/' };

let listResponse = threadFragment(1, 'ルート', []);
let fetchLog = [];
window.fetch = function (url, opts) {
  fetchLog.push({ url: String(url), method: (opts && opts.method) || 'GET' });
  // POST (reply/resolve) -> after it, the "server" now has the reply.
  if (opts && opts.method === 'POST') {
    listResponse = threadFragment(1, 'ルート', ['返信テスト']);
    return Promise.resolve({ ok: true, text: () => Promise.resolve('') });
  }
  return Promise.resolve({ ok: true, text: () => Promise.resolve(listResponse) });
};

// jsdom: getBoundingClientRect returns zeros — give the target a fake box.
const target = window.document.getElementById('target-p');
target.getBoundingClientRect = () => ({ left: 100, top: 400, width: 600, height: 80, right: 700, bottom: 480 });

// --- load overlay JS in the jsdom window --------------------------------
window.eval(overlayJs);

// boot the Alpine component manually (no Alpine needed: call boot with a stub `this`)
const component = window.hxrvOverlay();
component.cancelDraft = () => {};
component.boot();

// --- test helpers --------------------------------------------------------
const $ = (sel) => window.document.querySelector(sel);
const $$ = (sel) => Array.from(window.document.querySelectorAll(sel));
const tick = () => new Promise((r) => setTimeout(r, 50));
let failures = 0;
function assert(cond, label) {
  console.log((cond ? 'PASS' : 'FAIL') + '  ' + label);
  if (!cond) failures++;
}

(async () => {
  // 1) simulate initial hx-get load: htmx would innerHTML-swap the container
  $('#hxrv-comments').innerHTML = listResponse;
  await tick(); // let MutationObserver + rAF run

  assert($$('.hxrv-thread').length === 1, 'initial: 1 thread rendered');
  assert($('.hxrv-thread').classList.contains('hxrv-thread--pinned'), 'initial: thread pinned via observer');
  assert(!!$('.hxrv-thread__marker'), 'initial: marker injected');
  assert($('.hxrv-thread').style.left !== '', 'initial: positioned (left set)');

  // open the thread (marker click)
  $('.hxrv-thread__marker').click();
  assert($('.hxrv-thread').classList.contains('is-open'), 'marker click opens thread');

  // 2) submit a reply through the real delegated handler
  const form = $('.hxrv-reply-form');
  form.querySelector('textarea[name="content"]').value = '返信テスト';
  form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

  await tick(); // fetch POST -> refreshList (fetch GET) -> innerHTML -> repaint

  const posts = fetchLog.filter((f) => f.method === 'POST').length;
  const gets = fetchLog.filter((f) => f.method === 'GET').length;
  assert(posts === 1, 'reply: exactly one POST sent (got ' + posts + ')');
  assert(gets >= 1, 'reply: list re-fetched');

  const threads = $$('.hxrv-thread');
  assert(threads.length === 1, 'after reply: 1 thread rendered');
  assert(threads[0].querySelectorAll('.hxrv-comment--reply').length === 1, 'after reply: reply visible');
  assert(threads[0].classList.contains('hxrv-thread--pinned'), 'after reply: thread pinned IMMEDIATELY (the v0.1.4 bug)');
  assert(threads[0].style.left !== '', 'after reply: positioned (left set)');
  assert(threads[0].classList.contains('is-open'), 'after reply: open state preserved');
  assert(!!threads[0].querySelector('.hxrv-thread__marker'), 'after reply: marker present');

  // 3) resolve via the delegated button handler
  fetchLog = [];
  threads[0].querySelector('[data-hxrv-action]').click();
  await tick();
  assert(fetchLog.filter((f) => f.method === 'POST').length === 1, 'resolve: POST sent');
  assert($('.hxrv-thread').classList.contains('hxrv-thread--pinned'), 'after resolve: still pinned');

  // 4) orphan: inject a thread whose selector matches nothing
  fetchLog = [];
  const orphanFragment = threadFragment(2, '迷子コメント', []).replace('#target-p', '#no-such-element').replace('ターゲット段落"', '存在しないテキスト"');
  listResponse = threadFragment(1, 'ルート', ['返信テスト']) + orphanFragment;
  $('#hxrv-comments').innerHTML = listResponse;
  await tick();

  const tray = $('#hxrv-orphan-tray');
  assert(tray.querySelectorAll('.hxrv-thread').length === 1, 'orphan: thread moved to tray');
  assert(tray.querySelector('.hxrv-thread').classList.contains('hxrv-thread--orphaned'), 'orphan: orphaned class applied');
  assert(!tray.querySelector('.hxrv-orphan-tray__label').hidden, 'orphan: tray label revealed');
  const anchorPosts = fetchLog.filter((f) => f.method === 'POST').length;
  assert(anchorPosts === 1, 'orphan: exactly one hxrv_anchor POST (got ' + anchorPosts + ')');
  assert($('#hxrv-thread-2').getAttribute('data-hxrv-status') === 'orphaned', 'orphan: local status updated');

  // 5) repaint again: no duplicate anchor report
  fetchLog = [];
  window.dispatchEvent(new window.Event('resize'));
  await new Promise((r) => setTimeout(r, 250));
  assert(fetchLog.filter((f) => f.method === 'POST').length === 0, 'orphan: no duplicate report on repaint');

  // 6) pin navigation chips
  const nav = $('#hxrv-nav');
  assert(!!nav, 'nav: container exists');
  const chips = nav.querySelectorAll('.hxrv-nav__chip');
  assert(chips.length === 2, 'nav: one chip per thread (got ' + chips.length + ')');
  assert(chips[1].classList.contains('hxrv-nav__chip--orphaned'), 'nav: orphaned chip styled');

  // clicking chip 1 opens its thread and pulses the marker
  $('#hxrv-thread-1').classList.remove('is-open');
  let scrolled = false;
  window.scrollTo = function () { scrolled = true; };
  chips[0].dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  assert($('#hxrv-thread-1').classList.contains('is-open'), 'nav: chip click opens thread');
  assert(scrolled, 'nav: chip click scrolls the page');
  assert(!!$('#hxrv-thread-1 .hxrv-thread__marker.hxrv-pulse'), 'nav: marker pulses after jump');

  // 7) draft submit carries the optional Before / After fields (v1.2.0)
  let createBody = null;
  const origFetch = window.fetch;
  window.fetch = function (url, opts) {
    if (opts && opts.method === 'POST' && opts.body && typeof opts.body.get === 'function' && opts.body.get('action') === 'hxrv_create') {
      createBody = opts.body;
      return Promise.resolve({ ok: true, text: () => Promise.resolve('') });
    }
    return origFetch(url, opts);
  };

  component.draft = { selector: '#target-p', selectorText: 'ターゲット段落', offsetX: '50', offsetY: '10', isDynamic: false, pageX: 0, pageY: 0 };
  component.draftText = '見出しを直す';
  component.draftBefore = 'font-size 20px / 左寄せ';
  component.draftAfter = 'font-size 32px / 中央寄せ';
  component.draftShowBA = true;
  component.submitDraft();
  await tick();

  assert(createBody !== null, 'draft: create POST sent');
  assert(createBody && createBody.get('content') === '見出しを直す', 'draft: content sent');
  assert(createBody && createBody.get('before_text') === 'font-size 20px / 左寄せ', 'draft: before_text sent');
  assert(createBody && createBody.get('after_text') === 'font-size 32px / 中央寄せ', 'draft: after_text sent');
  assert(component.draft === null && component.draftBefore === '' && component.draftAfter === '' && !component.draftShowBA, 'draft: before/after state reset after submit');

  window.fetch = origFetch;

  console.log(failures === 0 ? '\nALL TESTS PASSED' : '\n' + failures + ' FAILURES');
  process.exit(failures === 0 ? 0 : 1);
})();
