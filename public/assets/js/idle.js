/**
 * ============================================================
 *  Idle sign-out
 * ------------------------------------------------------------
 *  The server already expires an idle session, but that check
 *  only runs when a request arrives. A screen left open shows
 *  whatever was last loaded — customers, prices, cash balances —
 *  until somebody touches it. On a counter or a stock desk, that
 *  is the whole of the exposure.
 *
 *  So the browser keeps its own clock. A minute before the
 *  deadline it warns and offers to carry on; if nobody answers,
 *  it signs out.
 *
 *  Two decisions worth knowing about:
 *
 *  • Once the warning is up, ordinary mouse movement no longer
 *    counts as activity. A nudge from someone walking past must
 *    not silently extend a session — only the button does.
 *
 *  • The clock lives in localStorage, so it is shared across
 *    every tab on this machine. Working in one tab keeps the
 *    others alive, and one sign-out ends them all.
 *
 *  Nothing here polls the server. A timed ping would refresh the
 *  session forever and there would be no idle timeout at all.
 * ============================================================
 */

(function () {
    'use strict';

    const cfgEl = document.getElementById('idle-config');
    if (!cfgEl) return;                     // signed out — nothing to guard

    let cfg;
    try {
        cfg = JSON.parse(cfgEl.textContent);
    } catch (err) {
        return;
    }

    const IDLE_MS   = Math.max(60, cfg.timeoutSeconds) * 1000;
    const WARN_MS   = Math.min(Math.max(10, cfg.warningSeconds) * 1000, IDLE_MS / 2);
    const STORE_KEY = 'erp-last-activity';
    const ACTIVITY_EVENTS = ['mousedown', 'keydown', 'wheel', 'touchstart', 'scroll', 'mousemove'];

    let warningOpen = false;
    let signingOut  = false;
    let lastWrite   = 0;
    let dialog      = null;
    let countdownEl = null;

    /* ── The shared clock ───────────────────────────────────── */

    function readLastActivity() {
        try {
            const v = parseInt(localStorage.getItem(STORE_KEY) || '', 10);
            if (v > 0) return v;
        } catch (err) { /* private mode, or storage disabled */ }
        return Date.now();
    }

    function writeLastActivity() {
        try {
            localStorage.setItem(STORE_KEY, String(Date.now()));
        } catch (err) { /* nothing to do — the in-tab timer still works */ }
        lastWrite = Date.now();
    }

    /* ── Activity ───────────────────────────────────────────── */

    function onActivity() {
        // While the warning is showing, only the button counts.
        if (warningOpen || signingOut) return;
        // Writing on every mousemove would be thousands of writes a
        // minute for no gain; once every few seconds is plenty.
        if (Date.now() - lastWrite < 3000) return;
        writeLastActivity();
    }

    ACTIVITY_EVENTS.forEach((ev) => {
        document.addEventListener(ev, onActivity, { passive: true, capture: true });
    });

    // Submitting a form or opening another page is activity too, and
    // both may happen without any of the events above firing first.
    document.addEventListener('submit', () => { if (!warningOpen) writeLastActivity(); }, true);
    window.addEventListener('pageshow', () => { if (!warningOpen) writeLastActivity(); });

    /* ── The warning ────────────────────────────────────────── */

    function buildDialog() {
        const overlay = document.createElement('div');
        overlay.className = 'idle-overlay';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'idleTitle');
        overlay.innerHTML =
            '<div class="idle-box">' +
              '<h2 class="idle-title" id="idleTitle">Still there?</h2>' +
              '<p class="idle-text">' +
                'You have not done anything for a while, so you are about to be ' +
                'signed out to keep this screen private.' +
              '</p>' +
              '<p class="idle-count">Signing out in <strong data-idle-count>—</strong></p>' +
              '<div class="idle-actions">' +
                '<button type="button" class="btn btn-ghost" data-idle-out>Sign out now</button>' +
                '<button type="button" class="btn btn-primary" data-idle-stay>Stay signed in</button>' +
              '</div>' +
            '</div>';

        overlay.querySelector('[data-idle-stay]').addEventListener('click', staySignedIn);
        overlay.querySelector('[data-idle-out]').addEventListener('click', () => signOut('manual'));
        return overlay;
    }

    function showWarning() {
        if (warningOpen) return;
        warningOpen = true;
        if (!dialog) dialog = buildDialog();
        document.body.appendChild(dialog);
        countdownEl = dialog.querySelector('[data-idle-count]');
        // Focus the safe choice, so Enter keeps you working rather
        // than throwing away what is on screen.
        dialog.querySelector('[data-idle-stay]').focus();
    }

    function hideWarning() {
        if (!warningOpen) return;
        warningOpen = false;
        if (dialog && dialog.parentNode) dialog.parentNode.removeChild(dialog);
    }

    function renderCountdown(msLeft) {
        if (!countdownEl) return;
        const secs = Math.max(0, Math.ceil(msLeft / 1000));
        countdownEl.textContent = secs === 1 ? '1 second' : secs + ' seconds';
    }

    /* ── Answers ────────────────────────────────────────────── */

    function staySignedIn() {
        hideWarning();
        writeLastActivity();
        // Tell the server too. Without this the browser's clock would
        // be reset while the session it belongs to quietly expired.
        fetch(cfg.pingUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        }).then((res) => {
            if (res.status === 401 || res.status === 403) signOut('expired');
        }).catch(() => { /* offline; the local clock still holds */ });
    }

    function signOut(reason) {
        if (signingOut) return;
        signingOut = true;
        try { localStorage.removeItem(STORE_KEY); } catch (err) { /* ignore */ }
        window.location.href = cfg.logoutUrl + (reason === 'manual' ? '' : '?reason=idle');
    }

    /* ── The clock ──────────────────────────────────────────── */

    function tick() {
        if (signingOut) return;
        const idleFor   = Date.now() - readLastActivity();
        const remaining = IDLE_MS - idleFor;

        if (remaining <= 0) {
            signOut('idle');
        } else if (remaining <= WARN_MS) {
            showWarning();
            renderCountdown(remaining);
        } else {
            // Another tab has been busy on our behalf.
            hideWarning();
        }
    }

    writeLastActivity();
    setInterval(tick, 1000);

    // A background tab has its timers throttled, so the deadline can
    // pass without a tick. Check the moment it comes back.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) tick();
    });

    // Signing out in one tab signs out the rest.
    window.addEventListener('storage', (e) => {
        if (e.key === STORE_KEY && e.newValue === null) signOut('idle');
    });
})();
