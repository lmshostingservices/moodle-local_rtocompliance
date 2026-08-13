/* RTOC Trainers page JS — action menu + role-badge tooltips.
   Loaded as an external file to comply with Moodle 4.3+ CSP 'self' directive.
   Previously these were inline <script> blocks in trainers.php. */

// ── Custom body-appended action menu (v4.0.83) ───────────────────────────────
// Bootstrap dropdowns fail inside overflow-x:auto wrappers when any ancestor
// element has a CSS transform (common in Moodle themes) because that cancels
// position:fixed. Instead, we build a plain <div> menu and append it directly
// to document.body so it escapes ALL overflow and stacking-context boundaries.
(function () {
    var _openMenu = null;   // currently visible menu div
    var _openBtn  = null;   // button that opened it

    function closeMenu() {
        if (_openMenu) {
            _openMenu.parentNode && _openMenu.parentNode.removeChild(_openMenu);
            _openMenu = null;
            _openBtn  = null;
        }
    }

    function positionMenu(btn, menu) {
        var r    = btn.getBoundingClientRect();
        var sx   = window.pageXOffset || 0;
        var sy   = window.pageYOffset || 0;
        var mw   = menu.offsetWidth  || 170;
        var mh   = menu.offsetHeight || 64;
        var top  = r.bottom + sy + 2;
        var left = r.left   + sx;
        // Flip upward if the menu would fall off the viewport bottom.
        if (r.bottom + mh > window.innerHeight && r.top >= mh) {
            top = r.top + sy - mh - 2;
        }
        // Align right edge of menu to right edge of button if it overflows right.
        if (r.left + mw > window.innerWidth) {
            left = r.right + sx - mw;
        }
        if (left < 4) left = 4;
        menu.style.top  = top  + "px";
        menu.style.left = left + "px";
    }

    function openMenu(btn) {
        closeMenu();
        var editUrl = btn.getAttribute("data-edit-url");
        var delForm = btn.getAttribute("data-del-form");

        var menu = document.createElement("div");
        menu.className = "rtoc-body-menu";
        menu.style.cssText = "position:absolute;z-index:99999;min-width:11rem;"
            + "background:#fff;border:1px solid rgba(0,0,0,0.15);border-radius:4px;"
            + "padding:4px 0;box-shadow:0 2px 8px rgba(0,0,0,0.12);overflow:visible;";

        // "Edit Trainer" item
        var editLink = document.createElement("a");
        editLink.href = editUrl;
        editLink.textContent = "Edit Trainer";
        editLink.style.cssText = "display:block;padding:6px 16px;white-space:nowrap;"
            + "color:#212529;text-decoration:none;font-size:0.9rem;";
        editLink.onmouseover = function() { this.style.background = "#f8f9fa"; };
        editLink.onmouseout  = function() { this.style.background = ""; };
        menu.appendChild(editLink);

        // "Delete" item
        if (delForm) {
            var delBtn = document.createElement("button");
            delBtn.type = "button";
            delBtn.style.cssText = "display:block;width:100%;padding:6px 16px;white-space:nowrap;"
                + "color:#dc3545;background:none;border:none;text-align:left;cursor:pointer;font-size:0.9rem;";
            delBtn.onmouseover = function() { this.style.background = "#f8f9fa"; };
            delBtn.onmouseout  = function() { this.style.background = ""; };
            delBtn.textContent = "Delete";
            delBtn.setAttribute("data-del-form", delForm);
            menu.appendChild(delBtn);
        }

        document.body.appendChild(menu);
        _openMenu = menu;
        _openBtn  = btn;
        positionMenu(btn, menu);  // position after append so offsetWidth is real
    }

    // Toggle on button click
    document.addEventListener("click", function (e) {
        var btn = e.target && e.target.closest && e.target.closest(".rtoc-action-btn");
        if (btn) {
            e.stopPropagation();
            if (_openBtn === btn) { closeMenu(); return; }
            openMenu(btn);
            return;
        }
        // Delete item inside body menu
        var delBtn = e.target && e.target.closest && e.target.closest("[data-del-form]");
        if (delBtn && _openMenu && _openMenu.contains(delBtn)) {
            if (!confirm("Delete this trainer? This cannot be undone.")) return;
            var form = document.getElementById(delBtn.getAttribute("data-del-form"));
            if (form) { closeMenu(); form.submit(); }
            return;
        }
        // Click outside — close
        if (_openMenu && !_openMenu.contains(e.target)) {
            closeMenu();
        }
    }, true);

    // Close on scroll or resize to prevent stale positioning
    window.addEventListener("scroll", closeMenu, true);
    window.addEventListener("resize", closeMenu, true);
})();

// ── Role badge tooltips (ASQA practice guide) ────────────────────────────────
// Renders a rich floating tooltip when hovering over any .rtoc-role-badge.
// Content comes from data-rtoc-tip (newlines rendered as paragraph breaks).
(function () {
    "use strict";

    var tip = null;

    function ensureTip() {
        if (tip) return tip;
        tip = document.createElement("div");
        tip.className = "rtoc-role-tip";
        tip.setAttribute("role", "tooltip");
        tip.setAttribute("aria-live", "polite");
        document.body.appendChild(tip);
        return tip;
    }

    function showTip(badge) {
        var t = ensureTip();
        var raw = badge.getAttribute("data-rtoc-tip") || "";

        // Split on double-newline for paragraphs, single newline for line breaks
        var paragraphs = raw.split(/\n\n+/);
        t.innerHTML = paragraphs.map(function(p) {
            var lines = p.split(/\n/).map(function(l) {
                return l.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
            });
            return "<p>" + lines.join("<br>") + "</p>";
        }).join("");

        t.style.display = "block";
        positionTip(badge, t);
        requestAnimationFrame(function () { t.classList.add("rtoc-role-tip--in"); });
    }

    function positionTip(badge, t) {
        t.style.left  = "-9999px";
        t.style.top   = "-9999px";
        t.style.display = "block";

        var r   = badge.getBoundingClientRect();
        var sx  = window.pageXOffset || 0;
        var sy  = window.pageYOffset || 0;
        var tw  = t.offsetWidth  || 280;
        var th  = t.offsetHeight || 60;

        var left = r.left + sx + (r.width / 2) - (tw / 2);
        var top  = r.top  + sy - th - 10;

        // Flip below if no room above
        var arrowClass = "rtoc-role-tip--above";
        if (r.top < th + 14) {
            top = r.bottom + sy + 10;
            arrowClass = "rtoc-role-tip--below";
        }

        // Keep within viewport horizontally
        var vpw = window.innerWidth;
        if (left + tw > vpw - 8) left = vpw - tw - 8 + sx;
        if (left < 8 + sx) left = 8 + sx;

        t.className = "rtoc-role-tip " + arrowClass + " rtoc-role-tip--in";
        t.style.left = left + "px";
        t.style.top  = top  + "px";
    }

    function hideTip() {
        if (!tip) return;
        tip.classList.remove("rtoc-role-tip--in");
        tip.style.display = "none";
    }

    document.addEventListener("mouseover", function (e) {
        var badge = e.target && e.target.closest && e.target.closest(".rtoc-role-badge");
        if (badge) { showTip(badge); return; }
        if (tip && !tip.contains(e.target)) hideTip();
    });

    document.addEventListener("mouseout", function (e) {
        var badge = e.target && e.target.closest && e.target.closest(".rtoc-role-badge");
        if (badge) {
            var to = e.relatedTarget;
            if (!to || (!badge.contains(to) && to !== tip && !tip.contains(to))) {
                hideTip();
            }
        }
    });

    document.addEventListener("focusin", function (e) {
        if (e.target && e.target.classList && e.target.classList.contains("rtoc-role-badge")) {
            showTip(e.target);
        }
    });

    document.addEventListener("focusout", function (e) {
        if (e.target && e.target.classList && e.target.classList.contains("rtoc-role-badge")) {
            hideTip();
        }
    });

    window.addEventListener("scroll", hideTip, true);
    window.addEventListener("resize", hideTip, true);
}());
