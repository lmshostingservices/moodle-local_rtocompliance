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
 * @copyright 2025 Essay Grader AI
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
        global $PAGE;

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
