// tables.js — scroll wrapper + full-screen expand for every plugin table.
// Loaded as an external file via lib.php to comply with Moodle 4.3+ CSP.
// v4.4.40: auto-detects tables on any plugin page, wraps them for in-place
// horizontal scroll (scroll bar appears immediately below the table, not at
// the bottom of the page), and injects a "Full screen" button so wide tables
// are easy to read without any horizontal scrolling.
//
// Wrapper class priority (highest → lowest):
//   rtoc-table-wrapper   — PHP-rendered on compliance/trainers list pages
//   rtoc-table-scroll    — legacy JS wrapper (tablesorter.js no longer adds this;
//                          kept as a recognised class so old cached pages don't
//                          double-wrap if the old tablesorter.js runs first)
//   rtoc-table-wrap      — our wrapper, added when neither of the above exists
//
// Tables already inside any of the above get a toolbar only (no extra wrapper).
(function () {
    'use strict';

    var WRAP_CLS = 'rtoc-table-wrap';
    var BAR_CLS  = 'rtoc-table-toolbar';
    var BTN_CLS  = 'rtoc-expand-btn';
    var OL_CLS   = 'rtoc-fullscreen-overlay';

    // All scroll-container classes this plugin has ever used
    var SCROLL_CLASSES = [WRAP_CLS, 'rtoc-table-scroll', 'rtoc-table-wrapper'];

    var EXPAND_SVG =
        '<svg viewBox="0 0 16 16" width="13" height="13" fill="currentColor" aria-hidden="true">' +
        '<path d="M1.5 1h4v1.5h-2.5v2.5h-1.5v-4z"/>' +
        '<path d="M10.5 1h4v4h-1.5v-2.5h-2.5v-1.5z"/>' +
        '<path d="M1.5 10.5h1.5v2.5h2.5v1.5h-4v-4z"/>' +
        '<path d="M14.5 10.5v4h-4v-1.5h2.5v-2.5z"/>' +
        '</svg>';

    var CLOSE_SVG =
        '<svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 ' +
        '.708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 ' +
        '0 0 1-.708-.708L7.293 8z"/>' +
        '</svg>';

    // ── helpers ───────────────────────────────────────────────────────────────

    function nearestHeading(el) {
        var container = el.closest('.generalbox, .rtoc-admin-card, .rtoc-page-card, .card');
        if (!container) { return ''; }
        var h = container.querySelector('h2, h3, h4, h5, h6');
        return h ? h.textContent.trim() : '';
    }

    function closestScrollContainer(el) {
        var selector = '.' + SCROLL_CLASSES.join(', .');
        return el.closest(selector);
    }

    // ── toolbar ───────────────────────────────────────────────────────────────

    function addToolbar(wrapEl, table) {
        // Guard: don't insert a second toolbar if one is already present
        var prev = wrapEl.previousElementSibling;
        if (prev && prev.classList.contains(BAR_CLS)) { return; }

        var bar = document.createElement('div');
        bar.className = BAR_CLS;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = BTN_CLS;
        btn.title = 'Open table in full screen';
        btn.innerHTML = EXPAND_SVG + '<span>Full screen</span>';
        btn.addEventListener('click', function () { openOverlay(table); });

        bar.appendChild(btn);
        wrapEl.parentNode.insertBefore(bar, wrapEl);
    }

    // ── full-screen overlay ───────────────────────────────────────────────────

    function openOverlay(table) {
        var label = nearestHeading(table) || 'Table';

        var overlay = document.createElement('div');
        overlay.className = OL_CLS;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', label + ' — full screen');

        // header bar
        var hdr = document.createElement('div');
        hdr.className = 'rtoc-fs-header';

        var titleEl = document.createElement('span');
        titleEl.className = 'rtoc-fs-title';
        titleEl.textContent = label;
        hdr.appendChild(titleEl);

        var hint = document.createElement('span');
        hint.className = 'rtoc-fs-hint';
        hint.textContent = 'Esc or Close to return';
        hdr.appendChild(hint);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'rtoc-fs-close';
        closeBtn.title = 'Close full screen (Esc)';
        closeBtn.innerHTML = CLOSE_SVG + '<span>Close</span>';
        hdr.appendChild(closeBtn);

        // body — cloned table
        var body = document.createElement('div');
        body.className = 'rtoc-fs-body';
        body.appendChild(table.cloneNode(true));

        overlay.appendChild(hdr);
        overlay.appendChild(body);
        document.body.appendChild(overlay);

        // lock body scroll (v6.2.37: also lock the root element so the page behind the
        // overlay does not keep its own vertical scrollbar → no more double scrollbar).
        document.body.classList.add('rtoc-fs-open');
        document.documentElement.classList.add('rtoc-fs-open');

        // focus close button for a11y
        closeBtn.focus();

        function close() {
            document.removeEventListener('keydown', onKey);
            document.body.classList.remove('rtoc-fs-open');
            document.documentElement.classList.remove('rtoc-fs-open');
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        }

        function onKey(e) { if (e.key === 'Escape') { close(); } }

        closeBtn.addEventListener('click', close);
        document.addEventListener('keydown', onKey);

        // click on the raw backdrop (not on header/body) also closes
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { close(); }
        });
    }

    // ── wrap table ────────────────────────────────────────────────────────────

    function wrapTable(table) {
        // Skip: nested inside another table cell
        if (table.parentNode.tagName === 'TD' || table.parentNode.tagName === 'TH') { return; }
        // Skip: inside the full-screen overlay (it's a clone)
        if (table.closest('.' + OL_CLS)) { return; }

        // Case 1 — table is already inside a scroll container (any class variant).
        // Just add a toolbar above that existing wrapper; don't double-wrap.
        var existingWrap = closestScrollContainer(table);
        if (existingWrap) {
            // Add toolbar if the table is a direct child of the wrapper, or one
            // level deeper (handles Moodle themes that inject a .table-responsive
            // or similar div between the wrapper and the <table>).
            if (table.parentNode === existingWrap ||
                table.parentNode.parentNode === existingWrap) {
                addToolbar(existingWrap, table);
            }
            return;
        }

        // Case 2 — table is not yet wrapped. Create our scroll wrapper.
        var wrap = document.createElement('div');
        wrap.className = WRAP_CLS;
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);
        addToolbar(wrap, table);
    }

    // ── init ──────────────────────────────────────────────────────────────────

    function init() {
        // Only run on plugin pages
        if (!document.body.classList.contains('path-local-rtocompliance') &&
            !document.querySelector('[class*="path-local-rtocompliance"]')) {
            return;
        }

        var tables = document.querySelectorAll(
            'table.table, table.generaltable, ' +
            'table.data-table, table.trainers-table, table.deadline-table, ' +
            'table.wfm-mapping-table'
        );
        tables.forEach(wrapTable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
