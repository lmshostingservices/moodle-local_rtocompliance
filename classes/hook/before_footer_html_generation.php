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
 * @copyright  2025 LMS Labs
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
        global $PAGE, $CFG, $DB, $USER;

        if (empty($PAGE->url)) {
            return;
        }

        $path = $PAGE->url->get_path();

        // ── MISSING AVETMISS DATA PROMPT (v5.9.440) — site-wide student nudge ──────────
        // A logged-in student whose AVETMISS profile is incomplete gets a modal prompting
        // them to complete it (and telling them where to download their certificates). It
        // reads only the student's OWN row (one indexed lookup by userid), never shows for
        // admins/guests or on the profile page itself, and appears once per browser session
        // (the external js/avetmiss_prompt.js reveals it and remembers "Remind me later").
        // The primary button is a plain link, so a student can always reach their profile.
        if (isloggedin() && !isguestuser() && !is_siteadmin()
                && strpos($path, '/local/rtocompliance/my_profile.php') === false) {
            $student = $DB->get_record('local_rtocompliance_students', ['userid' => (int)$USER->id]);
            if ($student) {
                $labels = [
                    'usi' => 'USI (Unique Student Identifier)', 'dateofbirth' => 'Date of birth',
                    'sex' => 'Sex', 'postcode' => 'Postcode', 'statecode' => 'State', 'suburb' => 'Suburb',
                    'indigenousstatus' => 'Indigenous status', 'countryofbirth' => 'Country of birth',
                    'languageathome' => 'Language spoken at home', 'labourforcestatus' => 'Labour force status',
                    'highestschoollevel' => 'Highest school level completed',
                ];
                $missing = [];
                foreach ($labels as $f => $lbl) {
                    if ($f === 'dateofbirth') {
                        if (empty($student->dateofbirth)) {
                            $missing[] = $lbl;
                        }
                        continue;
                    }
                    $v = trim((string)($student->$f ?? ''));
                    if ($v === '' || $v === '@' || $v === '@@') {
                        $missing[] = $lbl;
                    }
                }
                if (!empty($missing)) {
                    $profileurl = (new \moodle_url('/local/rtocompliance/my_profile.php'))->out(false);
                    $jsurl      = (new \moodle_url('/local/rtocompliance/js/avetmiss_prompt.js'))->out();
                    $items = '';
                    foreach (array_slice($missing, 0, 8) as $mlbl) {
                        $items .= '<li>' . s($mlbl) . '</li>';
                    }
                    if (count($missing) > 8) {
                        $items .= '<li>&hellip;and ' . (count($missing) - 8) . ' more</li>';
                    }
                    $hook->add_html(
'<style>
.rtoc-avm-backdrop{position:fixed;inset:0;z-index:100050;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:20px;}
.rtoc-avm-card{background:#fff;max-width:520px;width:100%;border-radius:16px;box-shadow:0 24px 60px -12px rgba(15,23,42,.45);padding:26px 28px;font-family:inherit;box-sizing:border-box;}
.rtoc-avm-head{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
.rtoc-avm-icon{flex:0 0 auto;width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;font-weight:800;font-size:22px;line-height:1;display:flex;align-items:center;justify-content:center;}
.rtoc-avm-card h2{margin:0;font-size:20px;color:#0f172a;font-weight:750;}
.rtoc-avm-lead{margin:0 0 14px;color:#334155;font-size:14.5px;line-height:1.6;}
.rtoc-avm-sub{margin:0 0 6px;font-weight:700;color:#0f172a;font-size:13.5px;}
.rtoc-avm-list{margin:0 0 16px;padding-left:20px;color:#475569;font-size:13.5px;line-height:1.7;}
.rtoc-avm-tip{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:11px 14px;color:#1e40af;font-size:13px;line-height:1.55;margin-bottom:18px;}
.rtoc-avm-actions{display:flex;gap:10px;flex-wrap:wrap;}
.rtoc-avm-btn-primary{flex:1 1 auto;text-align:center;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;text-decoration:none;font-weight:700;font-size:14.5px;padding:12px 18px;border-radius:10px;}
.rtoc-avm-btn-secondary{background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;font-weight:600;font-size:13.5px;padding:12px 16px;border-radius:10px;cursor:pointer;}
</style>
<div id="rtoc-avetmiss-modal" class="rtoc-avm-backdrop" role="dialog" aria-modal="true" aria-labelledby="rtoc-avm-title">
  <div class="rtoc-avm-card">
    <div class="rtoc-avm-head"><div class="rtoc-avm-icon">!</div><h2 id="rtoc-avm-title">Complete your student details</h2></div>
    <p class="rtoc-avm-lead">Before you continue, please complete your required student information. Your training provider needs this to report your training correctly and to issue your certificates.</p>
    <p class="rtoc-avm-sub">Still needed (' . count($missing) . '):</p>
    <ul class="rtoc-avm-list">' . $items . '</ul>
    <div class="rtoc-avm-tip">&#128196; <strong>Your certificates:</strong> once your details are complete, you can download your certificates any time from your profile menu &mdash; click your initials in the top-right corner and open your certificates.</div>
    <div class="rtoc-avm-actions">
      <a class="rtoc-avm-btn-primary" href="' . s($profileurl) . '">Complete my details now</a>
      <button type="button" class="rtoc-avm-btn-secondary" id="rtoc-avm-later">Remind me later</button>
    </div>
  </div>
</div>
<script src="' . s($jsurl) . '"></script>');
                }
            }
        }

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
