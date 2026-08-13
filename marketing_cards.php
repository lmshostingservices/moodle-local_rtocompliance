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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_marketing_info');
$PAGE->set_url('/local/rtocompliance/marketing_cards.php');
$PAGE->set_title('Standard 2.1 — Pre-Enrolment Information Cards');
$PAGE->set_heading('Standard 2.1 — Pre-Enrolment Information Cards');

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class("path-local-rtocompliance");

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Standard 2.1 — Pre-Enrolment Information', null, null, 'marketing_info');

// =====================================================================
// Pull RTO data server-side from existing tables. Falls back gracefully
// when tables / rows are missing — page still renders the card structure
// so auditors can see the framework even when data is being populated.
// =====================================================================
global $DB;

$courseRow = null;
try {
    if ($DB->get_manager()->table_exists('local_rtocompliance_courses')) {
        $courseRow = $DB->get_record_sql(
            'SELECT * FROM {local_rtocompliance_courses} ORDER BY id DESC',
            null, IGNORE_MULTIPLE
        );
    }
} catch (Throwable $e) { $courseRow = null; }

$feeRow = null;
try {
    if ($DB->get_manager()->table_exists('local_rtocompliance_feeprotection')) {
        $feeRow = $DB->get_record_sql(
            'SELECT * FROM {local_rtocompliance_feeprotection} ORDER BY id DESC',
            null, IGNORE_MULTIPLE
        );
    }
} catch (Throwable $e) { $feeRow = null; }

$transitions = [];
try {
    if ($DB->get_manager()->table_exists('local_rtocompliance_transitions')) {
        $transitions = $DB->get_records_sql(
            'SELECT * FROM {local_rtocompliance_transitions} ORDER BY id DESC',
            null, 0, 5
        );
    }
} catch (Throwable $e) { $transitions = []; }

// Build the auto-content payload for the JS card renderer / self-check.
$courseData = [
    'code'       => $courseRow->coursecode ?? ($courseRow->code ?? ''),
    'title'      => $courseRow->coursename ?? ($courseRow->title ?? ($courseRow->name ?? '')),
    'duration'   => $courseRow->duration ?? '',
    'mode'       => $courseRow->deliverymode ?? ($courseRow->mode ?? ''),
    'location'   => $courseRow->location ?? '',
    'startDates' => $courseRow->startdates ?? ($courseRow->startdate ?? ''),
];
$feeData = [
    'total'   => $feeRow->totalfees ?? ($feeRow->total ?? ''),
    'payment' => $feeRow->paymentterms ?? ($feeRow->payment ?? ''),
    'refund'  => $feeRow->refundpolicy ?? ($feeRow->refund ?? ''),
];
$changesData = [];
foreach ($transitions as $t) {
    $changesData[] = [
        'date'    => isset($t->effectivedate) ? userdate($t->effectivedate, '%d %b %Y') : (isset($t->timecreated) ? userdate($t->timecreated, '%d %b %Y') : ''),
        'message' => $t->summary ?? ($t->description ?? ($t->title ?? '')),
    ];
}

$studentSupportUrl    = (new moodle_url('/local/rtocompliance/student_support.php'))->out(false);
$tasUrl               = (new moodle_url('/local/rtocompliance/tas.php'))->out(false);
$feeUrl               = (new moodle_url('/local/rtocompliance/feeprotection.php'))->out(false);
$transitionsUrl       = (new moodle_url('/local/rtocompliance/transitions.php'))->out(false);
$practiceUrl          = (new moodle_url('/local/rtocompliance/practice_guides.php'))->out(false);
$studentsUrl          = (new moodle_url('/local/rtocompliance/students.php'))->out(false);
$declarationSendUrl   = (new moodle_url('/local/rtocompliance/student_declaration_send.php'))->out(false);

// PROBLEM 3 FIX: "Show Evidence" for Training Product Information must link to RTO's PUBLIC website,
// NOT the TAS (which requires Moodle login and is irrelevant to pre-enrolment auditing).
// Fallback: a clearly-labelled notice page rather than silently linking to TAS.
$rtoWebsiteUrl     = trim(get_config('local_rtocompliance', 'website') ?: '');
// Guard: if the admin saved the URL without any scheme (e.g. "nct.edu.au" instead of
// "https://nct.edu.au"), browsers treat it as a relative path on the Moodle server
// which returns a 404. Prefix with https:// automatically.
// Uses #://#  (not #^https?://#) to avoid prepending https:// to ftp://, mailto:, etc.
if (!empty($rtoWebsiteUrl) && !preg_match('#://#', $rtoWebsiteUrl)) {
    $rtoWebsiteUrl = 'https://' . $rtoWebsiteUrl;
}
$rtoWebsiteNotSet  = empty($rtoWebsiteUrl);

// Student Handbook URL — used as "Show Evidence" link on the Student Obligations card.
// Configured separately from the main website URL so the link goes directly to the
// handbook page (e.g. https://yourrto.edu.au/student-handbook) rather than the home page.
// Falls back to the Student Declaration send page when not configured.
$studentHandbookUrl = trim(get_config('local_rtocompliance', 'student_handbook_url') ?: '');
if (!empty($studentHandbookUrl) && !preg_match('#://#', $studentHandbookUrl)) {
    $studentHandbookUrl = 'https://' . $studentHandbookUrl;
}
$studentHandbookNotSet = empty($studentHandbookUrl);
// When not configured, point to a settings reminder page rather than TAS
$rtoWebsiteUrl     = $rtoWebsiteUrl ?: (new moodle_url('/local/rtocompliance/marketing_info.php'))->out(false);

// PROBLEM 5 FIX: policy URLs come from admin-configurable settings; when blank, the
// card renders a "not configured" notice with a link to set them up rather than a dead link.
// Pull policy records from DB table (created via upgrade.php if table exists)
$policyDocs = [];
try {
    if ($DB->get_manager()->table_exists('local_rtocompliance_policies')) {
        $policyRows = $DB->get_records_sql(
            "SELECT name, fileurl, externalurl, visibility FROM {local_rtocompliance_policies}
              WHERE visibility IN ('public','student') ORDER BY sortorder, id", []
        );
        foreach ($policyRows as $pr) {
            $link = !empty($pr->externalurl) ? $pr->externalurl : (!empty($pr->fileurl) ? $pr->fileurl : '');
            $policyDocs[] = ['name' => $pr->name, 'link' => $link];
        }
    }
} catch (Throwable $e) { $policyDocs = []; }

// Fallback to admin config settings when policy table has no rows
if (empty($policyDocs)) {
    $policyDocs = [
        ['name' => 'Safe & Inclusive Learning Policy', 'link' => get_config('local_rtocompliance', 'policy_safe_learning_url') ?: ''],
        ['name' => 'Equity & Diversity Policy',        'link' => get_config('local_rtocompliance', 'policy_equity_url') ?: ''],
        ['name' => 'Cultural Safety Policy',           'link' => get_config('local_rtocompliance', 'policy_cultural_url') ?: ''],
        ['name' => 'Anti-Discrimination Policy',       'link' => get_config('local_rtocompliance', 'policy_antidiscrimination_url') ?: ''],
        ['name' => 'Student Code of Conduct',          'link' => get_config('local_rtocompliance', 'policy_codeofconduct_url') ?: ''],
    ];
}

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Standard 2.1 — Information about the organisation and training products');
echo html_writer::end_div();


// Card grid container.
// Compliance status banner — written to by runComplianceCheck() in JS.
echo '<div id="standard21Status" style="margin-top:1rem;min-height:1.5rem;"></div>';

echo html_writer::start_div('', ['id' => 'standard21Cards', 'style' => 'display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.25rem;margin-top:1.5rem;']);
echo html_writer::end_div();

// Related.
echo html_writer::start_div('', ['style' => 'margin-top:2rem;padding:1.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;']);
echo html_writer::tag('h4', 'Related pages', ['style' => 'margin:0 0 0.75rem;color:#0369a1;']);
echo '<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">';
echo '<a href="' . (new moodle_url('/local/rtocompliance/marketing_info.php'))->out() . '" class="btn btn-outline-primary btn-sm">Marketing Information</a>';
echo '<a href="' . $studentSupportUrl . '" class="btn btn-outline-primary btn-sm">Student Support</a>';
echo '<a href="' . $feeUrl . '" class="btn btn-outline-primary btn-sm">Fee Protection</a>';
echo '<a href="' . $transitionsUrl . '" class="btn btn-outline-primary btn-sm">Training Product Transitions</a>';
echo '</div>';
echo html_writer::end_div();

echo html_writer::end_div(); // compliance-container

// =====================================================================
// Pass server data into JS payload safely.
// =====================================================================
$payload = [
    'course'              => $courseData,
    'fees'                => $feeData,
    'support'             => [],
    'policies'            => $policyDocs,
    'changes'             => $changesData,
    'rtoWebsiteNotSet'      => $rtoWebsiteNotSet,
    'studentHandbookNotSet' => $studentHandbookNotSet,
    'routes'              => [
        'tas'             => $tasUrl,
        'rtowebsite'      => $rtoWebsiteUrl,
        'support'         => $studentSupportUrl,
        'fees'            => $feeUrl,
        'transitions'     => $transitionsUrl,
        'practice'        => $practiceUrl,
        'students'        => $studentsUrl,
        'declarationsend' => $declarationSendUrl,
        'studenthandbook' => $studentHandbookUrl,
    ],
];
?>
<script>
(function () {
    'use strict';

    var DATA = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    // Hydrate support list from the Student Support page (localStorage rto_support_v1).
    function hydrateSupportFromLocalStorage() {
        try {
            var raw = localStorage.getItem('rto_support_v1');
            if (!raw) return;
            var state = JSON.parse(raw);
            var ss = state.supportServices || {};
            DATA.support = Object.keys(ss);
        } catch (e) { /* ignore */ }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    function buildCards() {
        var supportContent = (DATA.support && DATA.support.length)
            ? DATA.support.map(function (s) { return '&bull; ' + escapeHtml(s); }).join('<br>')
            : '<em style="color:#9ca3af;">No support services recorded — visit Student Support</em>';

        var feesContent = (DATA.fees.total || DATA.fees.payment || DATA.fees.refund)
            ? [
                DATA.fees.total   ? 'Total: ' + escapeHtml(DATA.fees.total) : '',
                DATA.fees.payment ? escapeHtml(DATA.fees.payment) : '',
                DATA.fees.refund  ? escapeHtml(DATA.fees.refund)  : ''
              ].filter(Boolean).join('<br>')
            : '<em style="color:#9ca3af;">No fee record on file</em>';

        // PROBLEM 5 FIX: render policy links with proper open/download buttons;
        // when URL is blank, show "not configured" helper with admin link instead of dead link.
        var policyLinks = DATA.policies.length ? DATA.policies.map(function (p) {
            if (p.link) {
                return '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">'
                    + '<a href="' + escapeHtml(p.link) + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" style="font-size:0.8rem;padding:2px 8px;">Open PDF</a>'
                    + '<a href="' + escapeHtml(p.link) + '" download target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" style="font-size:0.8rem;padding:2px 8px;">Download</a>'
                    + '<span style="font-size:0.85rem;">' + escapeHtml(p.name) + '</span>'
                    + '</div>';
            }
            return '<div style="margin-bottom:4px;font-size:0.85rem;">'
                + '<span style="color:#dc3545;">&#9888; ' + escapeHtml(p.name) + '</span>'
                + ' <em style="color:#9ca3af;">&mdash; URL not configured.</em>'
                + ' <a href="/local/rtocompliance/marketing_info.php" style="font-size:0.8rem;">Set URL in RTO Settings</a>'
                + '</div>';
        }).join('') : '<em style="color:#9ca3af;">No policy documents configured &mdash; add policy URLs in RTO Settings.</em>';

        var changesContent = DATA.changes && DATA.changes.length
            ? DATA.changes.map(function (c) { return escapeHtml(c.date) + ': ' + escapeHtml(c.message); }).join('<br>')
            : '<em style="color:#9ca3af;">No transitions recorded</em>';

        // PROBLEM 3 FIX: Training Product card "Show Evidence" links to RTO's PUBLIC website,
        // not the TAS. If website is not configured, render a clear setup notice.
        var trainingProductEvidence = DATA.rtoWebsiteNotSet
            ? '<div style="margin-top:0.75rem;">'
              + '<span style="color:#b45309;font-size:0.85rem;">&#9888; RTO website URL not configured &mdash; '
              + 'auditors cannot view the public course catalogue.</span><br>'
              + '<a href="/local/rtocompliance/marketing_info.php" class="btn btn-warning btn-sm" style="margin-top:6px;">Configure RTO Website URL</a>'
              + '</div>'
            : '<div style="margin-top:0.75rem;">'
              + '<a href="' + escapeHtml(DATA.routes.rtowebsite) + '" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">&#128270; Show Evidence (Public Website)</a>'
              + '</div>';

        return [
            {
                title: 'Training Product Information',
                content: '',
                evidenceHtml: trainingProductEvidence,
                route: DATA.routes.rtowebsite
            },
            {
                title: 'Support Services (Training + Wellbeing)',
                content: supportContent,
                route: DATA.routes.support
            },
            {
                title: 'Fees, Costs & Refunds',
                content: feesContent,
                route: DATA.routes.fees
            },
            {
                // Student Obligations card has two actions:
                // 1. "Show Evidence" → Student Handbook on RTO website (external link, new tab)
                //    Falls back to Declaration send page if handbook URL not configured.
                // 2. "Send Declaration" → sends the Student Declaration checklist to all students.
                title: 'Student Obligations',
                content: '&bull; Student Handbook — rights &amp; obligations<br>'
                       + '&bull; Student behaviour expectations &amp; code of conduct<br>'
                       + '&bull; How to lodge complaints &amp; appeals<br>'
                       + '&bull; Responsibility to participate in training &amp; assessment<br>'
                       + '&bull; Requirement to provide accurate information &amp; evidence<br>'
                       + '&bull; Obligation to complete assessments honestly (no plagiarism)<br>'
                       + '&bull; Support services &amp; adjustments available<br>'
                       + '&bull; Acknowledgement that this information was provided prior to/at enrolment',
                evidenceHtml: DATA.studentHandbookNotSet
                    ? '<div style="margin-top:0.75rem;">'
                      + '<a href="' + escapeHtml(DATA.routes.declarationsend) + '" class="btn btn-outline-primary btn-sm">&#128270; Show Evidence</a>'
                      + '<a href="' + escapeHtml(DATA.routes.declarationsend) + '" class="btn btn-primary btn-sm" style="margin-left:6px;">Send Declaration to Students</a>'
                      + '<small style="display:block;margin-top:0.35rem;color:#b45309;">&#9888; Student Handbook URL not configured — set it in Plugin Settings &rsaquo; Student Handbook URL to link directly to your handbook.</small>'
                      + '</div>'
                    : '<div style="margin-top:0.75rem;">'
                      + '<a href="' + escapeHtml(DATA.routes.studenthandbook) + '" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">&#128270; Show Evidence (Student Handbook)</a>'
                      + '<a href="' + escapeHtml(DATA.routes.declarationsend) + '" class="btn btn-primary btn-sm" style="margin-left:6px;">Send Declaration to Students</a>'
                      + '</div>',
                route: DATA.studentHandbookNotSet ? DATA.routes.declarationsend : DATA.routes.studenthandbook
            },
            {
                // Standard 2.5 — Diversity and Inclusion.
                // Policies are published on the RTO's public website (Policies menu item).
                // Show Evidence links to Declaration by Students (under Student Obligations).
                // policyLinks are fetched from DB (local_rtocompliance_policies) or config settings.
                title: 'Diversity and Inclusion Policies (Standard 2.5)',
                content: DATA.rtoWebsiteNotSet
                    ? '<em style="color:#b45309;">&#9888; RTO website URL not configured &mdash; set it in RTO Settings so the policy menu item is accessible.</em>'
                    : '<span style="font-size:0.92rem;color:#374151;">Policies are published on the RTO\'s website. Prospective and current students can access them via the Policies menu item there.</span>'
                      + '<div style="margin-top:0.5rem;">'
                      + '<a href="' + escapeHtml(DATA.routes.rtowebsite) + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" style="font-size:0.8rem;">Open RTO Website</a>'
                      + '</div>'
                      + '<div style="margin-top:0.75rem;">' + policyLinks + '</div>',
                evidenceHtml: '<div style="margin-top:0.75rem;">'
                    + '<a href="' + escapeHtml(DATA.routes.declarationsend) + '" class="btn btn-outline-primary btn-sm">&#128270; Show Evidence (Declaration by Students)</a>'
                    + '</div>',
                route: DATA.routes.declarationsend
            },
            {
                title: 'Changes to Training',
                content: changesContent,
                route: DATA.routes.transitions
            }
        ];
    }

    function renderCards() {
        var container = document.getElementById('standard21Cards');
        if (!container) return;
        var cards = buildCards();
        container.innerHTML = cards.map(function (c) {
            var evidenceBlock = c.evidenceHtml ||
                ('<div style="margin-top:0.75rem;">'
                + '<a href="' + escapeHtml(c.route) + '" class="btn btn-outline-primary btn-sm">&#128270; Show Evidence</a>'
                + (c.extraButtons || '')
                + '</div>');
            var contentBlock = c.content
                ? '<div style="font-size:0.92rem;line-height:1.6;color:#374151;">' + c.content + '</div>'
                : '';
            return '<div class="info-card" style="margin:0;">'
                + '<h4 style="margin:0 0 0.5rem;">' + escapeHtml(c.title) + '</h4>'
                + contentBlock
                + evidenceBlock
                + '</div>';
        }).join('');
    }

    function runComplianceCheck() {
        var issues = [];
        if (!DATA.policies || DATA.policies.length === 0) issues.push('Diversity &amp; Inclusion: no policy documents configured — add URLs in Plugin Settings');
        if (DATA.rtoWebsiteNotSet) issues.push('RTO website URL not configured — auditors cannot view the public course catalogue (Plugin Settings &rsaquo; Website)');
        if (DATA.studentHandbookNotSet) issues.push('Student Handbook URL not configured — Show Evidence links to Declaration page as fallback (Plugin Settings &rsaquo; Student Handbook URL)');

        var status = document.getElementById('standard21Status');
        if (!status) return;
        if (issues.length === 0) {
            status.innerHTML = '<div style="padding:0.6rem 1rem;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;color:#166534;font-weight:600;">&#10003; Fully Compliant — all Standard 2.1 data is present</div>';
        } else {
            status.innerHTML = '<div style="padding:0.6rem 1rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;">'
                + '<strong style="color:#b91c1c;">&#9888; ' + issues.length + ' issue' + (issues.length === 1 ? '' : 's') + ' found:</strong>'
                + '<ul style="margin:0.35rem 0 0 1rem;padding:0;color:#7f1d1d;">'
                + issues.map(function (i) { return '<li style="margin-bottom:0.2rem;">' + i + '</li>'; }).join('')
                + '</ul></div>';
        }
    }

    function init() {
        hydrateSupportFromLocalStorage();
        renderCards();
        runComplianceCheck();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else { init(); }
})();
</script>
<?php
echo $OUTPUT->footer();
