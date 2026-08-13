/* RTOC Sidebar JS v9 — loaded as an external file to comply with Moodle 4.3+ CSP.
   Previously this was an inline <script> block injected by local_rtocompliance_render_sidebar()
   in lib.php. Moving it here allows it to be served as a same-origin script, which is
   permitted by Moodle's Content-Security-Policy 'self' directive. */
(function() {
    var STORAGE_KEY = 'rtoc_sb_collapsed';
    var SB_W  = 258;
    var SB_CW = 62;

    var sidebar   = document.getElementById('rtoc-sidebar');
    var toggleBtn = document.getElementById('rtoc-sb-toggle-btn');
    var mobileBtn = document.getElementById('rtoc-mobile-btn');
    var overlay   = document.getElementById('rtoc-sidebar-overlay');

    if (!sidebar) return;

    function isMobile() { return window.innerWidth <= 880; }

    // -- MOBILE: slide-in overlay ------------------------------------------
    function setupMobile() {
        if (mobileBtn) {
            mobileBtn.addEventListener('click', function() {
                sidebar.classList.toggle('rtoc-sb-mobile-open');
                if (overlay) overlay.classList.toggle('rtoc-overlay-visible');
            });
        }
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('rtoc-sb-mobile-open');
                overlay.classList.remove('rtoc-overlay-visible');
            });
        }
        sidebar.querySelectorAll('.rtoc-sb-item').forEach(function(a) {
            a.addEventListener('click', function() {
                sidebar.classList.remove('rtoc-sb-mobile-open');
                if (overlay) overlay.classList.remove('rtoc-overlay-visible');
            });
        });
    }

    // -- Toggle collapse (desktop only) ------------------------------------
    var chevronL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>';
    var chevronR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

    function applyCollapsed(collapsed) {
        if (isMobile()) return;
        if (collapsed) {
            sidebar.classList.add('rtoc-sb-is-collapsed');
            sidebar.style.setProperty('width',      SB_CW + 'px', 'important');
            sidebar.style.setProperty('min-width',  SB_CW + 'px', 'important');
            sidebar.style.setProperty('flex-basis', SB_CW + 'px', 'important');
            if (toggleBtn) toggleBtn.innerHTML = chevronR;
        } else {
            sidebar.classList.remove('rtoc-sb-is-collapsed');
            sidebar.style.setProperty('width',      SB_W + 'px', 'important');
            sidebar.style.setProperty('min-width',  SB_W + 'px', 'important');
            sidebar.style.setProperty('flex-basis', SB_W + 'px', 'important');
            if (toggleBtn) toggleBtn.innerHTML = chevronL;
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            var willCollapse = !sidebar.classList.contains('rtoc-sb-is-collapsed');
            applyCollapsed(willCollapse);
            localStorage.setItem(STORAGE_KEY, willCollapse ? '1' : '0');
        });
    }

    // -- Credit balance (async, non-blocking) ------------------------------
    function fetchCredits() {
        // Prefer rtoc-ai-config (edit pages), fall back to rtoc-sb-api-config (all pages).
        var cfg = document.getElementById('rtoc-ai-config') || document.getElementById('rtoc-sb-api-config');
        var valEl = document.getElementById('rtoc-sb-credits-val');
        if (!cfg || !valEl) return;
        var apikey = cfg.dataset.apikey || '';
        var apiurl = (cfg.dataset.apiurl || 'https://lms-labs.com').replace(/\/$/, '');
        var siteid = cfg.dataset.siteid || '';
        if (!apikey) return;
        // POST /api/credits — returns { credits, creditsRaw, isUnlimited }
        fetch(apiurl + '/api/credits', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ siteId: siteid, apiKey: apikey })
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(d) {
            if (!d) return;
            if (d.isUnlimited || d.creditsRaw === -1) {
                valEl.textContent = 'Unlimited';
                valEl.style.color = '#34d399';
            } else {
                var bal = d.credits !== undefined ? Number(d.credits) : 0;
                valEl.textContent = bal.toLocaleString() + ' credits';
                valEl.style.color = bal < 10 ? '#f87171' : (bal < 50 ? '#fbbf24' : '#93c5fd');
            }
        })
        .catch(function() { /* silent — credits widget is non-critical UI */ });
    }

    // -- Collapsed icon tooltips (JS-based, avoids overflow:hidden clipping) --
    // The sidebar has overflow:hidden so CSS ::after tooltips get clipped when
    // collapsed. This JS approach appends the tooltip div to document.body,
    // escaping the overflow context entirely.
    var _tip = null;
    function showTip(item) {
        if (!sidebar.classList.contains('rtoc-sb-is-collapsed')) return;
        var label = item.getAttribute('data-label');
        if (!label) return;
        hideTip();
        _tip = document.createElement('div');
        _tip.className = 'rtoc-sb-js-tooltip';
        _tip.textContent = label;
        _tip.style.cssText = [
            'position:fixed',
            'background:#1e2d4a',
            'color:#e8f4fd',
            'font-size:12px',
            'font-weight:500',
            'padding:5px 12px',
            'border-radius:6px',
            'white-space:nowrap',
            'pointer-events:none',
            'z-index:200000',
            'box-shadow:0 4px 16px rgba(0,0,0,0.45)',
            'border:1px solid rgba(56,189,248,0.18)',
            'opacity:0',
            'transition:opacity 0.12s',
        ].join(';');
        document.body.appendChild(_tip);
        var r = item.getBoundingClientRect();
        _tip.style.top  = Math.round(r.top + (r.height - _tip.offsetHeight) / 2) + 'px';
        _tip.style.left = Math.round(r.right + 6) + 'px';
        // Force reflow then fade in
        _tip.getBoundingClientRect();
        _tip.style.opacity = '1';
    }
    function hideTip() {
        if (_tip) { _tip.parentNode && _tip.parentNode.removeChild(_tip); _tip = null; }
    }
    function wireTips() {
        sidebar.querySelectorAll('.rtoc-sb-item').forEach(function(item) {
            item.addEventListener('mouseenter', function() { showTip(item); });
            item.addEventListener('mouseleave', hideTip);
            item.addEventListener('click', hideTip);
        });
    }

    // -- Init --------------------------------------------------------------
    function doInit() {
        // Always wire up mobile handlers — user may resize desktop→mobile at any time.
        setupMobile();
        // Wire up JS tooltips for collapsed icon mode.
        wireTips();
        // Apply desktop collapse state only if we started on desktop.
        if (!isMobile()) {
            applyCollapsed(localStorage.getItem(STORAGE_KEY) === '1');
        }
        // Fetch credits after a short delay to not compete with page load.
        setTimeout(fetchCredits, 600);
        // Scroll active sidebar item into view so it is always visible on page load.
        var activeItem = sidebar.querySelector('.rtoc-sb-active');
        if (activeItem) {
            activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        // Prevent the browser restoring a saved scroll position on nav, which
        // would fire before our scroll and trip the guard below.
        if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
        // Auto-scroll: push the page so Moodle's fixed primary header stays visible
        // but ALL secondary chrome (secondary-nav, breadcrumbs, page-header) scrolls away,
        // leaving plugin content flush against the bottom edge of the fixed header.
        setTimeout(function() {
            if (window.scrollY > 80) return; // user already scrolled — respect their position
            // Measure the actual height of Moodle's fixed primary navbar at runtime.
            var navEl = document.querySelector('.navbar.fixed-top')
                     || document.querySelector('#page.fixed-top')
                     || document.querySelector('.fixed-top');
            var navH = navEl ? Math.round(navEl.getBoundingClientRect().height) : 56;
            // Find the plugin content region.
            var main = document.querySelector('#region-main')
                    || document.querySelector('.main-inner')
                    || document.querySelector('[role="main"]');
            if (main) {
                var mainTop = Math.round(main.getBoundingClientRect().top + window.scrollY);
                // Only scroll if there is actually secondary chrome to hide.
                if (mainTop > navH + 4) {
                    window.scrollTo({ top: mainTop - navH, behavior: 'smooth' });
                }
            }
        }, 300);
    }

    document.addEventListener('DOMContentLoaded', doInit);

    window.addEventListener('resize', function() {
        if (!isMobile()) {
            applyCollapsed(localStorage.getItem(STORAGE_KEY) === '1');
            sidebar.classList.remove('rtoc-sb-mobile-open');
            if (overlay) overlay.classList.remove('rtoc-overlay-visible');
        }
    });
})();

/* ── Collapsible fieldset fallback (v4.0.60) ─────────────────────────────
   Moodle's core/collapsible_section AMD module may not fire if RequireJS
   is delayed or Bootstrap collapse init is skipped. This plain-JS handler
   directly toggles fieldset.collapsible sections so TAS Generator accordion
   sections always open/close regardless of AMD module state.
   ──────────────────────────────────────────────────────────────────────── */
(function () {
    function initCollapsibleFallback() {
        var fieldsets = document.querySelectorAll('.mform fieldset.collapsible');
        if (!fieldsets.length) return;

        fieldsets.forEach(function (fs) {
            var header = fs.querySelector('> div:first-child');
            if (!header || header.dataset.rtocInited) return;
            header.dataset.rtocInited = '1';

            header.addEventListener('click', function (e) {
                /* Let direct child buttons / links in collapsible-actions handle
                   themselves (Expand all / Collapse all). */
                if (e.target.closest('.collapsible-actions')) return;

                var isCollapsed = fs.classList.contains('collapsed');
                var container   = fs.querySelector('.fcontainer');
                if (!container) return;

                /* If Moodle / Bootstrap already toggled the state by the time
                   our listener fires, do nothing (they handled it). We detect
                   this by checking whether the state changed since mousedown. */
                var stateBeforeClick = header.dataset.rtocState;
                var stateNow = isCollapsed ? 'collapsed' : 'open';
                if (stateBeforeClick && stateBeforeClick !== stateNow) {
                    /* State already changed by another handler — sync and exit. */
                    header.dataset.rtocState = stateNow;
                    return;
                }

                /* Manually toggle. */
                if (isCollapsed) {
                    fs.classList.remove('collapsed');
                    container.style.display = '';
                    container.classList.add('show');
                    container.classList.remove('collapse');
                    var fheader = header.querySelector('a.fheader');
                    if (fheader) { fheader.setAttribute('aria-expanded', 'true'); fheader.classList.remove('collapsed'); }
                } else {
                    fs.classList.add('collapsed');
                    container.style.display = 'none';
                    container.classList.remove('show');
                    var fheader2 = header.querySelector('a.fheader');
                    if (fheader2) { fheader2.setAttribute('aria-expanded', 'false'); fheader2.classList.add('collapsed'); }
                }
                header.dataset.rtocState = isCollapsed ? 'open' : 'collapsed';
            });

            /* Record initial state so we can detect external toggles. */
            header.dataset.rtocState = fs.classList.contains('collapsed') ? 'collapsed' : 'open';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCollapsibleFallback);
    } else {
        initCollapsibleFallback();
        /* Also run after a short delay in case Moodle's AMD fires late. */
        setTimeout(initCollapsibleFallback, 800);
    }
})();
