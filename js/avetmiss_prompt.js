// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Missing-AVETMISS-data student prompt (v5.9.440).
 *
 * Served same-origin so it satisfies Moodle 4.3+ CSP (script-src 'self'); an inline
 * <script> block would be blocked. Reveals the server-injected modal once per browser
 * session (a student who clicks "Remind me later" is not nagged again until they open a
 * new session or complete their profile). The primary button is a plain link, so the
 * profile is always reachable.
 */
(function () {
    'use strict';

    function init() {
        var modal = document.getElementById('rtoc-avetmiss-modal');
        if (!modal) {
            return;
        }
        try {
            if (window.sessionStorage && window.sessionStorage.getItem('rtocAvmDismissed') === '1') {
                return;
            }
        } catch (e) {
            // sessionStorage unavailable (private mode etc.) — just show the modal.
        }

        modal.style.display = 'flex';

        var later = document.getElementById('rtoc-avm-later');
        if (later) {
            later.addEventListener('click', function () {
                modal.style.display = 'none';
                try {
                    window.sessionStorage.setItem('rtocAvmDismissed', '1');
                } catch (e) {
                    // Ignore — the modal is hidden for this page load regardless.
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
