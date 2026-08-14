<?php
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

namespace local_rtocompliance\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callback for before_standard_head_html_generation.
 *
 * @package   local_rtocompliance
 * @copyright 2025 LMS Labs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_head_html_generation {
    /**
     * Callback for before_standard_head_html_generation hook.
     *
     * Ensures the body element carries the 'path-local-rtocompliance' CSS class on all
     * RTO Compliance admin pages (Moodle 5.x entry point).
     *
     * WHY THIS IS NEEDED:
     * admin_externalpage_setup() sets the Moodle pagetype to
     * 'admin-setting-local_rtocompliance_*', which causes the body to get a class like
     * 'page-type-admin-setting-local-rtocompliance-dashboard'. This does NOT contain the
     * substring 'path-local-rtocompliance' that all styles.css rules are scoped to via
     * [class*="path-local-rtocompliance"]. Without this explicit add_body_class() call,
     * the entire stylesheet is ignored on admin pages: SVGs render unsized, layouts
     * collapse, and Bootstrap's max-width:100% makes icons fill the full page width.
     *
     * This hook fires during <head> generation, BEFORE the <body> tag is output, so
     * $PAGE->add_body_class() takes effect correctly.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook The hook instance.
     * @return void
     */
    public static function callback(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $PAGE, $CFG;

        // AVETMISS PROFILE GATE BACKSTOP (v6.3.0):
        // local_rtocompliance_extend_navigation() is where the gate normally fires,
        // but that callback only runs when a page actually builds the global
        // navigation. Layouts that never do (embedded, popup, some report pages)
        // would slip past it, so the gate is re-checked here as well. The function
        // itself is idempotent — a static guard makes the second call a no-op.
        //
        // Two conditions before it may run here:
        //  - nothing has been sent to the browser yet, and
        //  - no debugging output has been printed. Once DEBUGGING_PRINTED is defined,
        //    Moodle's redirect() refuses the fast 303 path and instead renders a
        //    "continue" page, which calls $OUTPUT->header() — and we are already
        //    inside header generation, so moodle_page::set_state() would throw. On a
        //    developer site that would turn every page into a fatal error for a
        //    gated student. Skipping here is harmless: the navigation callback
        //    catches them on the next request.
        if (!headers_sent() && !defined('DEBUGGING_PRINTED')) {
            require_once($CFG->dirroot . '/local/rtocompliance/lib.php');
            local_rtocompliance_profile_gate_check();
        }

        if (empty($PAGE->url)) {
            return;
        }

        // Add the body class on any RTOC page so [class*="path-local-rtocompliance"]
        // CSS selectors match correctly.
        if (strpos($PAGE->url->get_path(), '/local/rtocompliance/') !== false) {
            $PAGE->add_body_class('path-local-rtocompliance');
        }
    }
}
