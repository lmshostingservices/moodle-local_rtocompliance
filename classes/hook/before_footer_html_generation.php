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

/**
 * Hook callback for before_footer_html_generation.
 *
 * This is the Moodle 5.0+ compatible hook callback that replaces the
 * legacy local_rtocompliance_before_footer() callback in lib.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for local_rtocompliance before_footer_html_generation.
 */
class before_footer_html_generation {
    /**
     * Callback for core\hook\output\before_footer_html_generation hook.
     *
     * Injects on RTOC pages only:
     *  1. Table sorting JavaScript.
     *
     * v3.8.67+: Sidebar is rendered server-side by render_nav_header() as a flex child.
     * No sidebar HTML injection is done here — only table-sort JS.
     *
     * IMPORTANT: Do NOT add any site-wide JS here. Pre-defining core/first or
     * overriding requirejs.onError in the footer breaks Moodle's primary/secondary
     * navigation by replacing the real core/first module with a noop before Moodle's
     * async AMD loader has finished fetching it. See upgrade.php v3.7.78 and v3.8.19.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The hook object.
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $CFG, $DB;

        if (empty($PAGE->url)) {
            return;
        }

        $path = $PAGE->url->get_path();

        // ── ENROLLED USERS PAGE: inject "Fix Placeholder Student Names" banner ──
        // FIX-ENROL-BANNER (v4.9.188): The standard Moodle Enrolled Users /
        // Participants page shows placeholder accounts ("Student 0000005776")
        // when NAT auto-enrol ran without a NAT00080 file.  We inject a floating
        // orange banner so admins can fix names without navigating away.
        //
        // URL patterns vary by Moodle version and URL rewriting config:
        //   /enrol/index.php?id=N   — Moodle 3.x / 4.x enrolment management
        //   /user/index.php?id=N    — Participants page (older Moodle / some themes)
        // Pagetype is the most reliable signal regardless of URL rewriting.
        $isEnrolPage = (
            strpos($path, '/enrol/index.php') !== false ||
            strpos($path, '/user/index.php')  !== false ||
            ($PAGE->pagetype ?? '') === 'enrol-index' ||
            ($PAGE->pagetype ?? '') === 'user-index'
        );
        if ($isEnrolPage) {
            $courseid = (int)($PAGE->url->get_param('id') ?? 0);
            if ($courseid > 0) {
                // Count placeholder accounts enrolled in this course.
                // Uses indexed columns — fast even on large sites.
                $placeholderCount = (int)$DB->count_records_sql(
                    "SELECT COUNT(u.id)
                       FROM {user} u
                       JOIN {user_enrolments} ue ON ue.userid = u.id
                       JOIN {enrol} e ON e.id = ue.enrolid
                      WHERE e.courseid = :courseid
                        AND u.firstname = 'Student'
                        AND " . $DB->sql_isnotempty('u', 'idnumber', false, false),
                    ['courseid' => $courseid]
                );

                if ($placeholderCount > 0) {
                    $repairUrl = new \moodle_url('/local/rtocompliance/data_import.php');
                    $sesskey   = sesskey();
                    $noun      = $placeholderCount === 1 ? 'account' : 'accounts';
                    $hook->add_html('
<style>
#rtoc-repairnames-banner{
    position:fixed;bottom:24px;right:24px;z-index:99999;
    background:#b45309;color:#fff;border-radius:6px;
    padding:14px 18px;max-width:380px;
    box-shadow:0 4px 18px rgba(0,0,0,.35);
    font-family:sans-serif;font-size:.92em;line-height:1.4;
}
#rtoc-repairnames-banner p{margin:0 0 10px;}
#rtoc-repairnames-banner button{
    background:#fff;color:#b45309;border:none;border-radius:4px;
    padding:7px 14px;font-weight:700;cursor:pointer;font-size:.92em;
}
#rtoc-repairnames-banner .rtoc-dismiss{
    position:absolute;top:8px;right:10px;
    background:none;color:#fff;border:none;
    font-size:1.1em;cursor:pointer;padding:0 4px;font-weight:700;
}
</style>
<div id="rtoc-repairnames-banner">
  <button class="rtoc-dismiss" onclick="document.getElementById(\'rtoc-repairnames-banner\').remove()" title="Dismiss">&times;</button>
  <p><strong>' . $placeholderCount . ' placeholder student ' . s($noun) . ' found</strong><br>
  Names show as &ldquo;Student 0000005776&rdquo; because the NAT00080 file was not included in the original import.</p>
  <form method="post" action="' . s($repairUrl->out(false)) . '">
    <input type="hidden" name="sesskey" value="' . s($sesskey) . '">
    <input type="hidden" name="action"  value="repairnames">
    <button type="submit">Fix Student Names Now</button>
  </form>
</div>');
                }
            }
        }

        // ── RTOC PAGES: table-sort and tables JS ─────────────────────────────
        if (strpos($path, '/local/rtocompliance/') === false) {
            return;
        }

        // v3.8.67+: Sidebar is rendered server-side by render_nav_header() as a flex child.
        // No sidebar injection needed here. Only table-sort JS is appended.
        require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

        // v4.4.26 CSP-TABLESORTER: Moodle 4.3+ blocks inline <script> blocks that
        // lack a server-issued nonce (CSP directive: script-src 'self'). The old
        // $sorting_script heredoc injected an inline <script> block via add_html(),
        // which fires on every RTOC page — including trainers.php — and was being
        // silently blocked by the browser, making the page appear blank.
        // Fix: the JS is now served from js/tablesorter.js (same-origin, always
        // allowed by 'self'). We output a plain <script src="..."> tag instead.
        $tablesorter_url = (new \moodle_url('/local/rtocompliance/js/tablesorter.js'))->out();
        $hook->add_html('<script src="' . s($tablesorter_url) . '"></script>');

        // v4.4.40 TABLES-FOOTER: tables.js must load on every plugin page, including
        // trainer_dashboard.php which uses $OUTPUT->header() directly and never calls
        // render_nav_header(). init() in tables.js is fully idempotent — the
        // closestScrollContainer + addToolbar duplicate-guard make a second run a no-op
        // on pages that already received tables.js via render_nav_header().
        $tables_url = (new \moodle_url('/local/rtocompliance/js/tables.js'))->out();
        $hook->add_html('<script src="' . s($tables_url) . '"></script>');
    }
}
