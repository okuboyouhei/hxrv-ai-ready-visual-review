# HXRV tests

DOM-level regression tests for `assets/js/hxrv-overlay.js`, run in jsdom
(no browser required). Covers pin positioning, the deterministic
refresh path (create/reply/resolve), open-state preservation, orphan
tray behavior, and anchor-status reporting — including regressions for
every overlay bug found during v0.1.x development.

## Run

    cd tests
    npm install
    npm test

All assertions print PASS/FAIL; the process exits non-zero on failure.
Run this after ANY change to hxrv-overlay.js.
