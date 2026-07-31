/**
 * FEAT-TRANSITION-AI (v4.4.70)
 * Wires the "AI: Generate Transition Plan" button on transition_edit.php.
 * External file loaded via $PAGE->requires->js() so Moodle's CSP nonce
 * system handles it correctly (avoids the inline-script CSP block on
 * Moodle 4.3+, documented in v4.4.24 CSP-EXTERNAL-JS).
 */
(function () {
    'use strict';

    function init() {
        var btn = document.getElementById('rtoc-ai-transitionplan');
        if (!btn) return;

        var cfgEl = document.getElementById('rtoc-ai-config');
        var ajax  = (cfgEl ? (cfgEl.getAttribute('data-api-base') || '').replace(/\/$/, '') : '')
                  + '/local/rtocompliance/ajax.php';

        // Derive ajax URL from page origin if rtoc-ai-config base is external SaaS URL.
        // ajax.php is always same-origin (Moodle server), so use window.location.origin.
        var ajaxUrl = window.location.origin + '/local/rtocompliance/ajax.php';

        function v(name) {
            var el = document.querySelector('[name=' + JSON.stringify(name) + ']');
            return el ? (el.value || '') : '';
        }
        function selText(name) {
            var el = document.querySelector('[name=' + JSON.stringify(name) + ']');
            if (!el || el.tagName !== 'SELECT') return '';
            var o = el.options[el.selectedIndex];
            return o ? o.text : '';
        }

        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target');
            var statusEl = document.getElementById('rtoc-ai-transitionplan-status');
            var target   = document.getElementById(targetId);
            if (!target) return;

            if (target.value && target.value.length > 30) {
                if (!confirm('This will replace the existing text. Continue?')) return;
            }
            btn.disabled = true;
            if (statusEl) { statusEl.textContent = 'Generating...'; statusEl.classList.remove('is-error'); }

            // Assemble teach-out deadline from Moodle date_selector (day/month/year selects).
            var tday   = v('teachoutdeadline[day]');
            var tmonth = v('teachoutdeadline[month]');
            var tyear  = v('teachoutdeadline[year]');
            var teachoutStr = (tday && tmonth && tyear) ? (tday + '/' + tmonth + '/' + tyear) : '';

            var fd = new FormData();
            fd.append('action',               'ai_draft_text');
            fd.append('contexttype',          'transitionplan');
            fd.append('sesskey',              M.cfg.sesskey);
            fd.append('seed[oldproductcode]',  v('oldproductcode'));
            fd.append('seed[oldproductname]',  v('oldproductname'));
            fd.append('seed[transitiontype]',  selText('transitiontype'));
            fd.append('seed[newproductcode]',  v('newproductcode'));
            fd.append('seed[newproductname]',  v('newproductname'));
            fd.append('seed[teachoutdeadline]', teachoutStr);
            fd.append('seed[studentsaffected]', v('studentsaffected'));

            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (raw) {
                    btn.disabled = false;
                    var j;
                    try { j = JSON.parse(raw); } catch (e) {
                        if (statusEl) { statusEl.textContent = 'Error: Bad response — ' + raw.substring(0, 100); statusEl.classList.add('is-error'); }
                        return;
                    }
                    if (j && j.success && j.text) {
                        target.value = j.text;
                        target.dispatchEvent(new Event('input', { bubbles: true }));
                        if (statusEl) statusEl.textContent = 'Draft inserted - please review and edit before saving.';
                    } else {
                        if (statusEl) { statusEl.textContent = 'Error: ' + ((j && (j.error || j.message)) || 'AI request failed'); statusEl.classList.add('is-error'); }
                    }
                })
                .catch(function (e) {
                    btn.disabled = false;
                    if (statusEl) { statusEl.textContent = 'Network error: ' + e.message; statusEl.classList.add('is-error'); }
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
