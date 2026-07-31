<?php
// v4.2.36 CERTIFICATES-REDESIGN — Substantial UX overhaul.
//
// Replaces the previous filter-tabs + 50-card grid with a proper management UI:
//   - Filter bar: search, cert type, qualification, issue year, date range,
//     USI status, email status. Apply / Clear buttons. Result counter.
//   - View toggle: Cards (compact) or Table (sortable columns).
//   - Server-side pagination (25/page) via Moodle's paging_bar.
//   - Per-row actions: Download (legacy link), Email (AJAX one-click),
//     Reissue (confirm + AJAX), View (verify page).
//   - Reissued ORIGINAL rows show "Replaced by CERT-..." badge and have
//     their action buttons disabled (audit trail preserved untouched).
//   - Reissue (REPLACEMENT) rows show "Replaces CERT-..." note.
//
// Top section (clause banner + stat cards + USI alert) is unchanged from v4.2.35.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_certificates');
require_capability('local/rtocompliance:issuecerts', context_system::instance());
$PAGE->set_title(get_string('certificates', 'local_rtocompliance'));
$PAGE->set_heading(get_string('certificates', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

// ── Filter / pagination / sort params (all GET-driven, bookmarkable) ────────
$search       = trim(optional_param('search',     '',     PARAM_TEXT));
$certtype     = optional_param('certtype',        '',     PARAM_ALPHA);
$qualcode     = trim(optional_param('qualcode',   '',     PARAM_TEXT));
$year         = optional_param('year',            0,      PARAM_INT);
$datefrom     = optional_param('datefrom',        '',     PARAM_TEXT); // YYYY-MM-DD
$dateto       = optional_param('dateto',          '',     PARAM_TEXT);
$usistatus    = optional_param('usistatus',       '',     PARAM_ALPHA); // verified|unverified|''
$emailstatus  = optional_param('emailstatus',     '',     PARAM_ALPHA); // sent|notsent|''
$view         = optional_param('view',            'table', PARAM_ALPHA); // cards|table
$page         = optional_param('page',            0,      PARAM_INT);
$perpage      = 25;
$sort         = optional_param('sort',            'issuedate', PARAM_ALPHA);
$dir          = optional_param('dir',             'DESC', PARAM_ALPHA);

$validsorts = ['issuedate', 'certnumber', 'lastname', 'certtype', 'qualificationcode', 'emailsent'];
if (!in_array($sort, $validsorts, true)) {
    $sort = 'issuedate';
}
$dir = (strtoupper($dir) === 'ASC') ? 'ASC' : 'DESC';

$PAGE->add_body_class('path-local-rtocompliance');
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('certificates', 'local_rtocompliance'), null, null, 'certificates');

echo html_writer::start_div('certificates-container');

// ── Header + Issue button ───────────────────────────────────────────────────
echo html_writer::start_div('certificates-header');
echo html_writer::tag('h2', get_string('certificates', 'local_rtocompliance'));
echo html_writer::start_div('d-flex gap-2 flex-wrap');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/issue_certificate.php'),
    get_string('issue_certificate', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/soa_issue.php'),
    get_string('soa_issue', 'local_rtocompliance'),
    ['class' => 'btn btn-primary', 'title' => 'Issue a Statement of Attainment listing multiple units of competency with automatic compliance validation']
);
// v4.7.104 BULK-COURSE-CERTS — Generate all certificates for a course
echo html_writer::tag('button',
    'Generate by Course',
    [
        'type'            => 'button',
        'class'           => 'btn btn-success',
        'data-toggle'     => 'modal',
        'data-target'     => '#generateCourseModal',
        'title'           => 'Bulk-generate certificates for all students who completed a specific unit-of-competency course',
    ]
);
// GEN-BY-QUAL (v5.2.0) — Generate Testamur + RoR directly from a Qualification Builder qualification
echo html_writer::tag('button',
    'Generate by Qualification',
    [
        'type'            => 'button',
        'class'           => 'btn btn-primary',
        'data-toggle'     => 'modal',
        'data-target'     => '#generateQualModal',
        'title'           => 'Bulk-generate Testamur + Record of Results for all students who completed all units of a full qualification',
    ]
);
echo html_writer::end_div();
echo html_writer::end_div();

// ── Generate by Course modal ──────────────────────────────────────────────────
// FIX-MODAL-URL (v5.0.5): pre-compute URL via moodle_url so the modal works
// on Moodle installations in subdirectories (hardcoded '/local/...' paths break).
// FIX-ONCLICK-QUOTES (v5.2.0): replaced json_encode() (which wraps URL in double quotes,
// prematurely terminating the onclick="..." HTML attribute) with single-quote JS literals.
$generateCourseBaseUrl = (new moodle_url('/local/rtocompliance/generate_course_certs.php'))->out(false);
$allcourses = $DB->get_records_sql(
    "SELECT c.id, c.fullname, c.shortname
     FROM {course} c
     WHERE c.id > 1
     ORDER BY c.fullname ASC",
    [],
    0,
    500
);
$courseopts = '<option value="">— Select a course —</option>';
foreach ($allcourses as $c) {
    $courseopts .= '<option value="' . $c->id . '">' . htmlspecialchars($c->fullname) . ' (' . htmlspecialchars($c->shortname) . ')</option>';
}
// Single-quote JS string avoids double-quote collision with the onclick="" attribute delimiters.
$courseNavOnclick = 'var v=document.getElementById(\'generateCourseSelect\').value; if(v){window.location=\'' . htmlspecialchars($generateCourseBaseUrl, ENT_QUOTES) . '?courseid=\'+v;}else{alert(\'Please select a course\');}';
echo '
<div class="modal fade" id="generateCourseModal" tabindex="-1" role="dialog" aria-labelledby="generateCourseModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="generateCourseModalLabel">Generate Certificates for a Course</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Select a unit-of-competency course to see all students who have completed it. The system automatically determines the correct certificate type (Testamur + RoR, Statement of Attainment, or Completion Certificate) based on the course\'s qualification settings.</p>
        <div class="form-group">
          <label for="generateCourseSelect"><strong>Course</strong></label>
          <select id="generateCourseSelect" class="form-control">' . $courseopts . '</select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" onclick="' . htmlspecialchars($courseNavOnclick, ENT_QUOTES) . '">Go to Course</button>
      </div>
    </div>
  </div>
</div>';

// ── Generate by Qualification modal ──────────────────────────────────────────
// GEN-BY-QUAL (v5.2.0): Lists active qualifications from the Qualification Builder
// so RTO staff can bulk-issue Testamur + Record of Results for the whole qualification.
$generateQualBaseUrl = (new moodle_url('/local/rtocompliance/generate_qual_certs.php'))->out(false);
$activeQuals = $DB->get_records_sql(
    "SELECT id, qualificationcode, qualificationname
     FROM {local_rtocompliance_qualbuilder}
     WHERE producttype = 'qualification'
       AND status = 'active'
     ORDER BY qualificationname ASC"
);
$qualopts = '<option value="">— Select a qualification —</option>';
foreach ($activeQuals as $aq) {
    $qualopts .= '<option value="' . $aq->id . '">'
        . htmlspecialchars($aq->qualificationcode) . ' — '
        . htmlspecialchars(format_string($aq->qualificationname))
        . '</option>';
}
$qualNavOnclick = 'var v=document.getElementById(\'generateQualSelect\').value; if(v){window.location=\'' . htmlspecialchars($generateQualBaseUrl, ENT_QUOTES) . '?qualid=\'+v;}else{alert(\'Please select a qualification\');}';
echo '
<div class="modal fade" id="generateQualModal" tabindex="-1" role="dialog" aria-labelledby="generateQualModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="generateQualModalLabel">Generate Certificates for a Qualification</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="text-muted">Select a qualification to see all students who have completed every linked unit of competency. The system will generate a <strong>Testamur + Record of Results</strong> for each eligible student.</p>
        <div class="form-group">
          <label for="generateQualSelect"><strong>Qualification</strong></label>
          <select id="generateQualSelect" class="form-control">' . $qualopts . '</select>
        </div>'
        . (empty($activeQuals) ? '<p class="text-warning mt-2">No active qualifications found. Build and activate qualifications in the Qualification Builder first.</p>' : '')
        . '
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="' . htmlspecialchars($qualNavOnclick, ENT_QUOTES) . '">Go to Qualification</button>
      </div>
    </div>
  </div>
</div>';

// ── Clause banner ────────────────────────────────────────────────────────────
$templateUrl    = (new moodle_url('/local/rtocompliance/cert_templates.php'))->out(false);
// FIX-ASQA-URL (v4.9.139): ASQA migrated their website — the old Drupal /sites/default/files/
// PDF path returned 404. Replaced with the current canonical fact-sheet page URL.
$asqaFactSheet  = 'https://www.asqa.gov.au/resources/fact-sheets/fact-sheet-aqf-certification-documentation';
$nrtLogoUrl     = 'https://www.asqa.gov.au/resources/fact-sheets/nrt-logo';
// FIX-TRANSITIONS-URL (v4.9.140): training.gov.au removed the old /National/NoticeBoard path.
// Replaced with the current canonical Training Product Transitions Register URL.
$transitionsUrl = 'https://www.training.gov.au/Organisation/Registers/TrainingProductTransitions';

echo html_writer::start_div('rtoc-clause-banner');
echo html_writer::start_div('rtoc-clause-banner-title');
echo html_writer::tag('span', 'ASQA Standards', ['class' => 'rtoc-clause-banner-label']);
echo html_writer::tag('h4', 'Certificate Compliance — Clauses 9–14');
echo html_writer::link(
    $asqaFactSheet,
    'ASQA Fact Sheet on AQF Certification Documentation ↗',
    ['class' => 'rtoc-clause-factsheet-link', 'target' => '_blank',
     'style' => 'font-size:0.78rem;color:rgba(255,255,255,0.75);text-decoration:underline;margin-top:4px;display:inline-block;']
);
echo html_writer::end_div();
echo html_writer::start_div('rtoc-clause-grid');
$clauses = [
    ['num' => '9',  'title' => 'Issuance Timeline',   'desc' => 'Issue within 30 calendar days of the student meeting all requirements.',
     'link' => null, 'linktext' => null],
    ['num' => '11', 'title' => 'Template Compliance', 'desc' => 'Testamurs and SoAs must use approved templates with all mandatory elements.',
     'link' => $templateUrl, 'linktext' => 'Manage Templates →'],
    ['num' => '12', 'title' => 'USI Requirement',     'desc' => 'USI must be recorded and verified on the registry before any certificate is issued.',
     'link' => null, 'linktext' => null],
    ['num' => '13', 'title' => 'NRT Logo',            'desc' => 'The NRT logo must appear on all Testamurs and Statements of Attainment.',
     'link' => $nrtLogoUrl, 'linktext' => 'NRT Logo conditions ↗'],
    ['num' => '14', 'title' => 'Transitions',         'desc' => 'Superseded qualifications can only be certified within the approved teach-out period.',
     'link' => $transitionsUrl, 'linktext' => 'Transitions Register ↗'],
];
foreach ($clauses as $c) {
    echo html_writer::start_div('rtoc-clause-item');
    echo html_writer::tag('div', $c['num'], ['class' => 'rtoc-clause-num']);
    echo html_writer::start_div('rtoc-clause-body');
    echo html_writer::tag('div', $c['title'], ['class' => 'rtoc-clause-title']);
    echo html_writer::tag('div', $c['desc'],  ['class' => 'rtoc-clause-desc']);
    if (!empty($c['link'])) {
        $isExternal = str_starts_with($c['link'], 'http');
        echo html_writer::link(
            $c['link'],
            $c['linktext'],
            array_merge(
                ['class' => 'rtoc-clause-link', 'style' => 'font-size:0.75rem;color:rgba(255,255,255,0.8);text-decoration:underline;margin-top:5px;display:inline-block;'],
                $isExternal ? ['target' => '_blank'] : []
            )
        );
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

// ── Stats cards (unchanged from v4.2.35) ────────────────────────────────────
$dbman = $DB->get_manager();
$studentsTableExists = $dbman->table_exists('local_rtocompliance_students');

$totalcerts    = $DB->count_records('local_rtocompliance_certs', ['status' => 'issued']);
$emailedcerts  = $DB->count_records('local_rtocompliance_certs', ['status' => 'issued', 'emailsent' => 1]);
$usimissing    = 0;
if ($studentsTableExists) {
    $usimissing = (int)$DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_certs} c
         LEFT JOIN {local_rtocompliance_students} s ON s.userid = c.userid
         WHERE c.status = 'issued' AND c.certtype IN ('testamur','statement') AND (s.usi IS NULL OR s.usiverified = 0)"
    );
}

echo html_writer::start_div('stats-cards');
$summaryStats = [
    ['label' => 'Certificates Issued',          'value' => $totalcerts,   'color' => 'blue',  'icon' => local_rtocompliance_stat_icon('award')],
    ['label' => 'Emailed to Students',          'value' => $emailedcerts, 'color' => 'green', 'icon' => local_rtocompliance_stat_icon('mail')],
    ['label' => 'USI Not Verified (Clause 12)', 'value' => $usimissing,   'color' => $usimissing > 0 ? 'rose' : 'green', 'icon' => local_rtocompliance_stat_icon('alert')],
];
foreach ($summaryStats as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'], ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($usimissing > 0) {
    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('h4', 'Clause 12 Alert — USI Not Verified');
    echo html_writer::tag('p', 'There are ' . $usimissing . ' certificate(s) issued to students whose USI has not been verified with the USI Registry. Under Clause 12, a verified USI is required before a Testamur or Statement of Attainment can be issued. Review these students\' USI status in the Students register.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/students.php'),
        'Review Students USI Status',
        ['class' => 'btn btn-warning btn-sm']
    );
    echo html_writer::end_div();
}

$certtypes = local_rtocompliance_get_certificate_types();

// ── FILTER BAR ──────────────────────────────────────────────────────────────
// Build dropdown options dynamically from existing data.
$qualcodeOptions = $DB->get_fieldset_sql(
    "SELECT DISTINCT qualificationcode FROM {local_rtocompliance_certs}
     WHERE qualificationcode IS NOT NULL AND qualificationcode <> ''
     ORDER BY qualificationcode"
);
$yearOptions = $DB->get_fieldset_sql(
    "SELECT DISTINCT FLOOR(issuedate / " . YEARSECS . ") + 1970 AS yr FROM {local_rtocompliance_certs}
     WHERE issuedate > 0 ORDER BY yr DESC"
);

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/rtocompliance/certificates.php'),
    'class'  => 'rtoc-filter-bar',
    'id'     => 'rtoc-cert-filters',
    'style'  => 'background: var(--rtoc-card-bg, #f7f7f9); border: 1px solid var(--rtoc-border, #e1e1e8); border-radius: 6px; padding: 12px 16px; margin: 16px 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px 12px; align-items: end;',
]);

// Search
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_search', 'local_rtocompliance') . '</label>';
echo '<input type="text" name="search" value="' . s($search) . '" class="form-control form-control-sm" placeholder="Name, email, or CERT-..." style="width:100%;"></div>';

// Cert type
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_certtype', 'local_rtocompliance') . '</label>';
echo '<select name="certtype" class="form-control form-control-sm" style="width:100%;">';
echo '<option value="">All types</option>';
foreach ($certtypes as $key => $label) {
    $sel = ($certtype === $key) ? ' selected' : '';
    echo '<option value="' . s($key) . '"' . $sel . '>' . s($label) . '</option>';
}
echo '</select></div>';

// Qualification
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_qualification', 'local_rtocompliance') . '</label>';
echo '<select name="qualcode" class="form-control form-control-sm" style="width:100%;">';
echo '<option value="">All qualifications</option>';
foreach ($qualcodeOptions as $qc) {
    $sel = ($qualcode === $qc) ? ' selected' : '';
    echo '<option value="' . s($qc) . '"' . $sel . '>' . s($qc) . '</option>';
}
echo '</select></div>';

// Year
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_year', 'local_rtocompliance') . '</label>';
echo '<select name="year" class="form-control form-control-sm" style="width:100%;">';
echo '<option value="0">All years</option>';
foreach ($yearOptions as $yr) {
    $yri = (int)$yr;
    $sel = ($year === $yri) ? ' selected' : '';
    echo '<option value="' . $yri . '"' . $sel . '>' . $yri . '</option>';
}
echo '</select></div>';

// Date from
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_datefrom', 'local_rtocompliance') . '</label>';
echo '<input type="date" name="datefrom" value="' . s($datefrom) . '" class="form-control form-control-sm" style="width:100%;"></div>';

// Date to
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_dateto', 'local_rtocompliance') . '</label>';
echo '<input type="date" name="dateto" value="' . s($dateto) . '" class="form-control form-control-sm" style="width:100%;"></div>';

// USI status
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_usi', 'local_rtocompliance') . '</label>';
echo '<select name="usistatus" class="form-control form-control-sm" style="width:100%;">';
echo '<option value="">All</option>';
echo '<option value="verified"'   . ($usistatus === 'verified'   ? ' selected' : '') . '>USI verified</option>';
echo '<option value="unverified"' . ($usistatus === 'unverified' ? ' selected' : '') . '>USI not verified</option>';
echo '</select></div>';

// Email status
echo '<div><label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;">' . get_string('certificates_filter_email', 'local_rtocompliance') . '</label>';
echo '<select name="emailstatus" class="form-control form-control-sm" style="width:100%;">';
echo '<option value="">All</option>';
echo '<option value="sent"'    . ($emailstatus === 'sent'    ? ' selected' : '') . '>Emailed</option>';
echo '<option value="notsent"' . ($emailstatus === 'notsent' ? ' selected' : '') . '>Not emailed</option>';
echo '</select></div>';

// Preserve view + sort across filter submission.
echo '<input type="hidden" name="view" value="' . s($view) . '">';
echo '<input type="hidden" name="sort" value="' . s($sort) . '">';
echo '<input type="hidden" name="dir"  value="' . s($dir)  . '">';

// Buttons row (full-width)
echo '<div style="grid-column: 1 / -1; display:flex; gap: 8px; justify-content: flex-end; margin-top: 4px;">';
echo '<button type="submit" class="btn btn-primary btn-sm">' . get_string('certificates_filter_apply', 'local_rtocompliance') . '</button>';
echo html_writer::link(
    new moodle_url('/local/rtocompliance/certificates.php'),
    get_string('certificates_filter_clear', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary btn-sm']
);
echo '</div>';

echo html_writer::end_tag('form');

// ── Build query (filter WHERE + count + paged fetch) ────────────────────────
$where  = ["c.status = 'issued'"];
$params = [];

if ($search !== '') {
    $like = '%' . $DB->sql_like_escape($search) . '%';
    $where[] = '(' . $DB->sql_like('u.firstname', ':s1', false) . ' OR ' .
               $DB->sql_like('u.lastname',  ':s2', false) . ' OR ' .
               $DB->sql_like('u.email',     ':s3', false) . ' OR ' .
               $DB->sql_like('c.certnumber', ':s4', false) . ')';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
}
if ($certtype !== '' && isset($certtypes[$certtype])) {
    $where[] = 'c.certtype = :ctype';
    $params['ctype'] = $certtype;
}
if ($qualcode !== '') {
    $where[] = 'c.qualificationcode = :qcode';
    $params['qcode'] = $qualcode;
}
if ($year > 0) {
    $startyear = strtotime($year . '-01-01 00:00:00');
    $endyear   = strtotime(($year + 1) . '-01-01 00:00:00');
    $where[] = 'c.issuedate >= :yrstart AND c.issuedate < :yrend';
    $params['yrstart'] = $startyear;
    $params['yrend']   = $endyear;
}
if ($datefrom !== '' && ($df = strtotime($datefrom)) !== false) {
    $where[] = 'c.issuedate >= :dfrom';
    $params['dfrom'] = $df;
}
if ($dateto !== '' && ($dt = strtotime($dateto . ' 23:59:59')) !== false) {
    $where[] = 'c.issuedate <= :dto';
    $params['dto'] = $dt;
}
if ($usistatus !== '' && $studentsTableExists) {
    if ($usistatus === 'verified') {
        $where[] = 's.usiverified = 1';
    } else if ($usistatus === 'unverified') {
        $where[] = '(s.usiverified IS NULL OR s.usiverified = 0)';
    }
}
if ($emailstatus !== '') {
    $where[] = 'c.emailsent = :estatus';
    $params['estatus'] = ($emailstatus === 'sent') ? 1 : 0;
}

$whereSql = implode(' AND ', $where);

if ($studentsTableExists) {
    $baseFrom = "FROM {local_rtocompliance_certs} c
                 JOIN {user} u ON u.id = c.userid
                 LEFT JOIN {local_rtocompliance_students} s ON s.userid = c.userid";
    $selectCols = "c.*, u.firstname, u.lastname, u.email,
                   u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                   s.usi, s.usiverified, s.usiverifieddate";
} else {
    $baseFrom = "FROM {local_rtocompliance_certs} c
                 JOIN {user} u ON u.id = c.userid";
    $selectCols = "c.*, u.firstname, u.lastname, u.email,
                   u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename";
}

$totalmatching = (int)$DB->count_records_sql("SELECT COUNT(*) $baseFrom WHERE $whereSql", $params);

// Sort column mapping (some need table aliases).
$sortcol = $sort;
if ($sortcol === 'lastname') {
    $sortcol = 'u.lastname';
} else {
    $sortcol = 'c.' . $sortcol;
}

$sql = "SELECT $selectCols $baseFrom WHERE $whereSql ORDER BY $sortcol $dir, c.id DESC";
$certs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// Look up replacement chain info for displayed certs.
$displayedSourceIds = [];
$displayedCertIds   = [];
foreach ($certs as $c) {
    $displayedCertIds[] = (int)$c->id;
    if (!empty($c->replacement_of)) {
        $displayedSourceIds[] = (int)$c->replacement_of;
    }
}

// For each displayed cert: if it has been reissued, what is the new cert number?
$replacedByMap = [];
if (!empty($displayedCertIds)) {
    [$insql, $inparams] = $DB->get_in_or_equal($displayedCertIds, SQL_PARAMS_NAMED, 'rep');
    $repRows = $DB->get_records_sql(
        "SELECT id, certnumber, replacement_of FROM {local_rtocompliance_certs}
         WHERE replacement_of $insql ORDER BY id ASC",
        $inparams
    );
    foreach ($repRows as $r) {
        // Latest replacement wins (overwrite as we iterate ASC by id).
        $replacedByMap[(int)$r->replacement_of] = $r->certnumber;
    }
}

// For each displayed cert that IS a reissue: what's the original cert number?
$replacesMap = [];
if (!empty($displayedSourceIds)) {
    [$insql, $inparams] = $DB->get_in_or_equal($displayedSourceIds, SQL_PARAMS_NAMED, 'src');
    $srcRows = $DB->get_records_sql(
        "SELECT id, certnumber FROM {local_rtocompliance_certs} WHERE id $insql",
        $inparams
    );
    foreach ($srcRows as $r) {
        $replacesMap[(int)$r->id] = $r->certnumber;
    }
}

// ── View toggle + result counter ─────────────────────────────────────────────
$baseparams = compact('search', 'certtype', 'qualcode', 'year', 'datefrom', 'dateto', 'usistatus', 'emailstatus', 'sort', 'dir');
$cardsUrl = new moodle_url('/local/rtocompliance/certificates.php', $baseparams + ['view' => 'cards']);
$tableUrl = new moodle_url('/local/rtocompliance/certificates.php', $baseparams + ['view' => 'table']);

echo html_writer::start_div('', ['style' => 'display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin: 12px 0;']);

if ($totalmatching > 0) {
    $from = ($page * $perpage) + 1;
    $to   = min($from + $perpage - 1, $totalmatching);
    echo html_writer::tag('div',
        get_string('certificates_showing', 'local_rtocompliance', (object)['from' => $from, 'to' => $to, 'total' => $totalmatching]),
        ['class' => 'text-muted', 'style' => 'font-size:0.9rem;']
    );
} else {
    echo html_writer::tag('div', '', ['class' => 'text-muted']);
}

echo html_writer::start_div('', ['style' => 'display:flex; gap:6px;']);
echo html_writer::link(
    $tableUrl,
    get_string('certificates_view_table', 'local_rtocompliance'),
    ['class' => 'btn btn-sm ' . ($view === 'table' ? 'btn-primary' : 'btn-secondary')]
);
echo html_writer::link(
    $cardsUrl,
    get_string('certificates_view_cards', 'local_rtocompliance'),
    ['class' => 'btn btn-sm ' . ($view === 'cards' ? 'btn-primary' : 'btn-secondary')]
);
echo html_writer::end_div();
echo html_writer::end_div();

// ── Helper closure: build action button HTML for one cert ───────────────────
$buildActions = function($cert, $usiMissing, $isReplacedOriginal) use ($USER) {
    $sesskey = sesskey();
    $out = '';
    $replacedTitle = 'Original cert preserved for audit trail — see the replacement row for actions';

    // BUG-MAY2-USI-WARN-NOT-BLOCK (v4.2.55): the tester reported that
    // the v4.2.46 popup was a hard block — clicking OK simply dismissed
    // the alert without ever performing the download/view/email, even in
    // legitimate cases (e.g. she'd already verified the student's USI
    // offline with USI Registry but the local flag had not yet been
    // refreshed).  Downgrade to a NON-BLOCKING advisory: the popup still
    // surfaces the Clause 12 reminder per click so the admin sees which student
    // is unverified, but after she clicks OK the original action proceeds.
    // The Email button now uses the SAME AJAX wiring as the normal path
    // (the inline alert pops first, then the click bubbles to the
    // delegated rtoc-cert-email-btn handler).  The matching server-side
    // throws in download_cert.php and email_cert.php were also downgraded
    // to soft notifications in this release so the action completes end
    // to end.
    $usiWarnMsg = "Note: this student's USI has not yet been verified with the USI Registry.\n\nUnder Clause 12 of the Standards for RTOs 2025, a verified USI should be on file before issuing a Testamur or Statement of Attainment. You can still download or email this certificate, but please verify the student's USI on the Students register as soon as possible.";
    $usiWarnJs  = 'alert(' . json_encode($usiWarnMsg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';

    // Download
    if ($isReplacedOriginal) {
        $out .= html_writer::tag('button', get_string('certificates_action_download', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-secondary', 'disabled' => 'disabled', 'title' => $replacedTitle, 'style' => 'cursor:not-allowed; opacity:0.55;']);
    } else {
        $downloadAttrs = ['class' => 'btn btn-sm btn-primary', 'data-testid' => ($usiMissing ? 'link-download-warn-' : 'link-download-') . $cert->id];
        if ($usiMissing) { $downloadAttrs['onclick'] = $usiWarnJs; }
        $out .= html_writer::link(
            new moodle_url('/local/rtocompliance/download_cert.php', ['id' => $cert->id]),
            get_string('certificates_action_download', 'local_rtocompliance'),
            $downloadAttrs
        );
    }

    // Email (AJAX) — usiMissing path now uses the same AJAX wiring as the
    // normal path; the inline alert() pops first, then the click bubbles
    // to the delegated rtoc-cert-email-btn handler which performs the
    // AJAX send.
    if ($isReplacedOriginal) {
        $out .= ' ' . html_writer::tag('button', get_string('certificates_action_email', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-secondary', 'disabled' => 'disabled', 'title' => $replacedTitle, 'style' => 'cursor:not-allowed; opacity:0.55;']);
    } else if ($cert->emailsent) {
        // FIX-EMAIL-BADGE (v5.2.52): Show a green pill badge with the sent date.
        // Guard against emailsentdate = 0 (epoch) which happened when certs were
        // issued before lib.php was fixed to save emailsentdate at issuance time.
        $sentDateStr = (!empty($cert->emailsentdate) && $cert->emailsentdate > 86400)
            ? userdate($cert->emailsentdate, '%d %b %Y')
            : date('d M Y'); // fallback to today for legacy rows with missing date
        $out .= ' ' . html_writer::tag('span',
            '&#10003; Emailed ' . $sentDateStr,
            [
                'class' => 'badge',
                'style' => 'background-color:#16a34a; color:#fff; padding:5px 10px; border-radius:20px; font-size:0.78rem; font-weight:600; white-space:nowrap; letter-spacing:0.01em;',
                'title' => 'Certificate emailed on ' . $sentDateStr,
            ]
        );
    } else {
        $emailAttrs = [
            'type'           => 'button',
            'class'          => 'btn btn-sm btn-secondary rtoc-cert-email-btn',
            'data-cert-id'   => $cert->id,
            'data-sesskey'   => $sesskey,
            'data-testid'    => ($usiMissing ? 'button-email-warn-' : 'button-email-') . $cert->id,
        ];
        if ($usiMissing) { $emailAttrs['onclick'] = $usiWarnJs; }
        $out .= ' ' . html_writer::tag('button', get_string('certificates_action_email', 'local_rtocompliance'), $emailAttrs);
    }

    // Reissue (AJAX with confirm) — gated only by replaced-original status,
    // not by USI.  USI verification is a download / email gate, not an
    // issue / reissue gate.
    if ($isReplacedOriginal) {
        $out .= ' ' . html_writer::tag('button', get_string('certificates_action_reissue', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-secondary', 'disabled' => 'disabled', 'title' => 'Already reissued', 'style' => 'cursor:not-allowed; opacity:0.55;']);
    } else {
        $out .= ' ' . html_writer::tag('button', get_string('certificates_action_reissue', 'local_rtocompliance'), [
            'type'              => 'button',
            'class'             => 'btn btn-sm btn-warning rtoc-cert-reissue-btn',
            'data-cert-id'      => $cert->id,
            'data-cert-number'  => $cert->certnumber,
            'data-fullname'     => fullname($cert),
            'data-sesskey'      => $sesskey,
            'data-testid'       => 'button-reissue-' . $cert->id,
        ]);
    }

    // PACK-DOWNLOAD (v5.9.246): For testamur and record certs, add a combined
    // "Download Pack" button that fetches both PDFs and streams them as a ZIP.
    // This solves the issue where admins saw only one cert per row download link
    // and had no affordance to get the paired testamur + RoR together.
    if (!$isReplacedOriginal && in_array($cert->certtype, ['testamur', 'record'], true) && !empty($cert->qualificationcode)) {
        $packAttrs = [
            'class'       => 'btn btn-sm btn-outline-primary',
            'title'       => 'Download Testamur + Record of Results as a ZIP',
            'data-testid' => 'link-pack-' . $cert->id,
        ];
        if ($usiMissing) {
            $packAttrs['onclick'] = $usiWarnJs;
        }
        $out .= ' ' . html_writer::link(
            new moodle_url('/local/rtocompliance/download_cert_pack.php', [
                'userid'   => $cert->userid,
                'qualcode' => $cert->qualificationcode,
            ]),
            '&#128230; Pack',
            $packAttrs
        );
    }

    // View — opens the rendered certificate PDF in a new tab.  When USI
    // is unverified the warning popup is shown but does NOT block the
    // open (v4.2.55 — see comment block above).
    if (!$isReplacedOriginal) {
        $viewAttrs = ['class' => 'btn btn-sm btn-link', 'target' => '_blank', 'data-testid' => ($usiMissing ? 'link-view-warn-' : 'link-view-') . $cert->id];
        if ($usiMissing) { $viewAttrs['onclick'] = $usiWarnJs; }
        $out .= ' ' . html_writer::link(
            new moodle_url('/local/rtocompliance/download_cert.php', ['id' => $cert->id]),
            get_string('certificates_action_view', 'local_rtocompliance'),
            $viewAttrs
        );
    }
    $out .= ' ' . html_writer::link(
        new moodle_url('/local/rtocompliance/verify.php', ['token' => $cert->verifytoken]),
        'Verify',
        ['class' => 'btn btn-sm btn-link', 'target' => '_blank', 'data-testid' => 'link-verify-' . $cert->id, 'title' => 'Open the public QR-verification page']
    );

    // Delete — revokes the certificate (soft-delete, audit trail preserved).
    $out .= ' ' . html_writer::tag('button', get_string('certificates_action_delete', 'local_rtocompliance'), [
        'type'              => 'button',
        'class'             => 'btn btn-sm btn-outline-danger rtoc-cert-delete-btn',
        'data-cert-id'      => $cert->id,
        'data-cert-number'  => $cert->certnumber,
        'data-fullname'     => fullname($cert),
        'data-sesskey'      => $sesskey,
        'data-testid'       => 'button-delete-' . $cert->id,
        'title'             => 'Revoke and remove this certificate',
    ]);

    return $out;
};

// ── Render: TABLE view ──────────────────────────────────────────────────────
if (!$certs) {
    echo html_writer::div(
        html_writer::tag('p', get_string('certificates_no_results', 'local_rtocompliance')),
        'no-deadlines'
    );
} else if ($view === 'table') {
    // Sortable column header link helper.
    $sortLink = function($column, $label) use ($baseparams, $sort, $dir, $view) {
        $newdir = ($sort === $column && $dir === 'ASC') ? 'DESC' : 'ASC';
        $url = new moodle_url('/local/rtocompliance/certificates.php',
            $baseparams + ['view' => $view, 'sort' => $column, 'dir' => $newdir]);
        $arrow = '';
        if ($sort === $column) {
            $arrow = ' ' . ($dir === 'ASC' ? '▲' : '▼');
        }
        return html_writer::link($url, $label . $arrow, ['style' => 'text-decoration:none; color:inherit; font-weight:600;']);
    };

    echo '<div class="rtoc-table-wrapper" style="overflow-x:auto;"><table class="generaltable" style="width:100%;" data-testid="table-certificates">';
    echo '<thead><tr>';
    echo '<th style="width:32px;"><input type="checkbox" id="rtoc-select-all" data-testid="checkbox-select-all" title="Select/deselect all on this page"></th>';
    echo '<th>' . $sortLink('certnumber',        'Cert #') . '</th>';
    echo '<th>' . $sortLink('lastname',          'Student') . '</th>';
    echo '<th>' . $sortLink('certtype',          'Type') . '</th>';
    echo '<th>' . $sortLink('qualificationcode', 'Qualification') . '</th>';
    echo '<th>' . $sortLink('issuedate',         'Issued') . '</th>';
    if ($studentsTableExists) {
        echo '<th>USI</th>';
    }
    echo '<th>' . $sortLink('emailsent', 'Email') . '</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ($certs as $cert) {
        $requiresUsi = in_array($cert->certtype, ['testamur', 'statement']);
        $usiVerified = !empty($cert->usiverified);
        $usiMissing  = $studentsTableExists && $requiresUsi && !$usiVerified;
        $isReplacedOriginal = !empty($cert->reissued_at);
        $isReissue          = !empty($cert->replacement_of);
        $rowStyle = $isReplacedOriginal ? 'opacity:0.6;' : '';

        echo '<tr style="' . $rowStyle . '" data-testid="row-cert-' . $cert->id . '">';

        // Bulk-select checkbox
        echo '<td><input type="checkbox" class="rtoc-cert-checkbox" value="' . (int)$cert->id . '" data-testid="checkbox-cert-' . $cert->id . '"></td>';

        // Cert #
        echo '<td><span class="certificate-number" data-testid="text-certnumber-' . $cert->id . '">' . s($cert->certnumber) . '</span>';
        if ($isReplacedOriginal && isset($replacedByMap[(int)$cert->id])) {
            echo '<br><span class="badge badge-secondary" style="font-size:0.7rem;" data-testid="badge-replaced-' . $cert->id . '">'
                . s(get_string('certificates_replaced_by', 'local_rtocompliance', $replacedByMap[(int)$cert->id])) . '</span>';
        }
        if ($isReissue && isset($replacesMap[(int)$cert->replacement_of])) {
            echo '<br><span class="badge badge-info" style="font-size:0.7rem;" data-testid="badge-replaces-' . $cert->id . '">'
                . s(get_string('certificates_replaces', 'local_rtocompliance', $replacesMap[(int)$cert->replacement_of])) . '</span>';
        }
        echo '</td>';

        // Student
        echo '<td>' . s(fullname($cert)) . '<br><span class="text-muted" style="font-size:0.8rem;">' . s($cert->email) . '</span></td>';

        // Type
        echo '<td>' . s($certtypes[$cert->certtype] ?? $cert->certtype) . '</td>';

        // Qualification
        $qualtxt = $cert->qualificationcode;
        if (!empty($cert->qualificationname)) {
            $qualtxt .= ' — ' . $cert->qualificationname;
        }
        echo '<td>' . s($qualtxt) . '</td>';

        // Issued
        echo '<td>' . userdate($cert->issuedate, '%d %b %Y') . '</td>';

        // USI
        if ($studentsTableExists) {
            if (!$requiresUsi) {
                echo '<td><span class="text-muted">n/a</span></td>';
            } else if ($usiVerified) {
                echo '<td><span class="badge badge-success" data-testid="badge-usi-' . $cert->id . '">Verified</span></td>';
            } else {
                echo '<td><span class="badge badge-danger" data-testid="badge-usi-' . $cert->id . '">Not verified</span></td>';
            }
        }

        // Email
        if ($cert->emailsent) {
            echo '<td><span class="badge badge-success" data-testid="badge-email-' . $cert->id . '">Sent</span></td>';
        } else {
            echo '<td><span class="badge badge-secondary" data-testid="badge-email-' . $cert->id . '">Not sent</span></td>';
        }

        // Actions
        echo '<td style="white-space:nowrap;">' . $buildActions($cert, $usiMissing, $isReplacedOriginal) . '</td>';

        echo '</tr>';
    }
    echo '</tbody></table></div>';
} else {
    // ── Render: CARDS view (preserves v4.2.35 look, adds reissue + AJAX email) ──
    echo html_writer::start_div('certificates-grid');
    foreach ($certs as $cert) {
        $requiresUsi = in_array($cert->certtype, ['testamur', 'statement']);
        $usiVerified = !empty($cert->usiverified);
        $usiMissing  = $studentsTableExists && $requiresUsi && !$usiVerified;
        $isReplacedOriginal = !empty($cert->reissued_at);
        $isReissue          = !empty($cert->replacement_of);

        $cardClass = 'certificate-card';
        if ($usiMissing) {
            $cardClass .= ' compliance-warning';
        }
        if ($isReplacedOriginal) {
            $cardClass .= ' rtoc-cert-replaced';
        }

        echo html_writer::start_div($cardClass, ['style' => $isReplacedOriginal ? 'opacity:0.6;' : '']);
        echo html_writer::tag('h4', fullname($cert));
        echo html_writer::tag('p', $certtypes[$cert->certtype] ?? $cert->certtype);
        echo html_writer::tag('p', html_writer::tag('span', $cert->certnumber, ['class' => 'certificate-number']));

        if ($isReplacedOriginal && isset($replacedByMap[(int)$cert->id])) {
            echo html_writer::tag('p',
                html_writer::tag('span', get_string('certificates_replaced_by', 'local_rtocompliance', $replacedByMap[(int)$cert->id]),
                ['class' => 'badge badge-secondary'])
            );
        }
        if ($isReissue && isset($replacesMap[(int)$cert->replacement_of])) {
            echo html_writer::tag('p',
                html_writer::tag('span', get_string('certificates_replaces', 'local_rtocompliance', $replacesMap[(int)$cert->replacement_of]),
                ['class' => 'badge badge-info'])
            );
        }

        echo html_writer::tag('p', 'Issued: ' . userdate($cert->issuedate, '%d %b %Y'));
        if ($cert->qualificationname) {
            echo html_writer::tag('p', $cert->qualificationcode . ' - ' . $cert->qualificationname, ['class' => 'text-muted']);
        }

        if ($studentsTableExists && $requiresUsi) {
            if ($usiVerified) {
                $usiVerifiedDate = !empty($cert->usiverifieddate) ? ' (' . userdate($cert->usiverifieddate, '%d %b %Y') . ')' : '';
                echo html_writer::tag('p',
                    html_writer::tag('span', 'USI Verified' . $usiVerifiedDate, ['class' => 'badge badge-success'])
                );
            } else {
                echo html_writer::tag('p',
                    html_writer::tag('span', 'USI Not Verified — Clause 12 Issue', ['class' => 'badge badge-danger'])
                );
            }
        }

        echo html_writer::start_div('certificate-actions', ['style' => 'display:flex; flex-wrap:wrap; gap:6px;']);
        echo $buildActions($cert, $usiMissing, $isReplacedOriginal);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

// ── Pagination ──────────────────────────────────────────────────────────────
if ($totalmatching > $perpage) {
    $pageurl = new moodle_url('/local/rtocompliance/certificates.php', $baseparams + ['view' => $view]);
    echo $OUTPUT->paging_bar($totalmatching, $page, $perpage, $pageurl);
}

// ── Transitions footer card ──────────────────────────────────────────────────
echo html_writer::start_div('info-card info-accent-blue');
echo html_writer::tag('h4', 'Training Products Transitions Register (Clause 14)');
echo html_writer::tag('p', 'Certificates for superseded, deleted, or transitioned qualifications must only be issued within the teach-out period. Verify certificate qualification codes against the Training Product Transitions register.');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/transitions.php'),
    'View Training Product Transitions',
    ['class' => 'btn btn-secondary btn-sm']
);
echo ' ';
echo html_writer::link(
    $transitionsUrl,
    'Training.gov.au Transitions Register ↗',
    ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']
);
echo ' ';
echo html_writer::link(
    $asqaFactSheet,
    'ASQA Fact Sheet ↗',
    ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']
);
echo html_writer::end_div();

echo html_writer::end_div(); // .certificates-container

// ── Inline JS for one-click email + reissue (vanilla, no AMD) ───────────────
// Kept inline & dependency-free so we don't have to write & build an AMD module
// for a one-page UX touch-up. Uses native fetch() — Moodle's supported browsers
// (modern Chrome/Firefox/Safari/Edge) all have fetch.
$emailEndpoint   = (new moodle_url('/local/rtocompliance/email_cert.php'))->out(false);
$reissueEndpoint = (new moodle_url('/local/rtocompliance/reissue_cert.php'))->out(false);
$deleteEndpoint  = (new moodle_url('/local/rtocompliance/delete_cert.php'))->out(false);
$bulkEndpoint    = (new moodle_url('/local/rtocompliance/bulk_action_cert.php'))->out(false);
?>
<?php if ($view === 'table'): ?>
<div id="rtoc-bulk-bar" style="visibility:hidden; position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#1f2937; color:#fff; padding:10px 18px; border-radius:8px; box-shadow:0 4px 18px rgba(0,0,0,0.3); display:flex; align-items:center; gap:10px; z-index:1050;" data-testid="bar-bulk-actions">
  <span data-testid="text-bulk-count" style="font-size:0.9rem;font-weight:600;white-space:nowrap;margin-right:4px;"><strong id="rtoc-bulk-count">0</strong> selected</span>
  <button type="button" class="btn btn-sm btn-light" id="rtoc-bulk-email" data-testid="button-bulk-email" style="min-width:80px;font-size:0.82rem;padding:5px 14px;font-weight:500;">Email</button>
  <button type="button" class="btn btn-sm btn-light" id="rtoc-bulk-zip" data-testid="button-bulk-zip" style="min-width:120px;font-size:0.82rem;padding:5px 14px;font-weight:500;">Download ZIP</button>
  <button type="button" class="btn btn-sm btn-light" id="rtoc-bulk-csv" data-testid="button-bulk-csv" style="min-width:100px;font-size:0.82rem;padding:5px 14px;font-weight:500;">Export CSV</button>
  <button type="button" class="btn btn-sm btn-light" id="rtoc-bulk-clear" data-testid="button-bulk-clear" style="min-width:60px;font-size:0.82rem;padding:5px 14px;font-weight:500;opacity:0.75;">Clear</button>
</div>
<?php endif; ?>
<script>
(function() {
    'use strict';
    var EMAIL_URL   = <?php echo json_encode($emailEndpoint); ?>;
    var REISSUE_URL = <?php echo json_encode($reissueEndpoint); ?>;
    var DELETE_URL  = <?php echo json_encode($deleteEndpoint); ?>;
    var BULK_URL    = <?php echo json_encode($bulkEndpoint); ?>;
    var SESSKEY     = <?php echo json_encode(sesskey()); ?>;

    function setBtnBusy(btn, busy, label) {
        btn.disabled = busy;
        if (busy) {
            btn.dataset.origLabel = btn.textContent;
            btn.textContent = label || 'Working...';
        } else if (btn.dataset.origLabel) {
            btn.textContent = btn.dataset.origLabel;
        }
    }

    // Email button — one-click AJAX, no confirm dialog.
    document.querySelectorAll('.rtoc-cert-email-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = btn.dataset.certId;
            var sesskey = btn.dataset.sesskey;
            setBtnBusy(btn, true, 'Sending...');
            var fd = new FormData();
            fd.append('id', id);
            fd.append('ajax', '1');
            fd.append('sesskey', sesskey);
            fetch(EMAIL_URL, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function(r) {
                return r.json().then(function(j) { return { ok: r.ok, body: j }; });
            }).then(function(res) {
                if (res.body && res.body.ok) {
                    btn.textContent = 'Emailed';
                    btn.classList.remove('btn-secondary');
                    btn.classList.add('btn-success');
                    setTimeout(function() { window.location.reload(); }, 800);
                } else {
                    setBtnBusy(btn, false);
                    alert('Email failed: ' + ((res.body && res.body.error) || 'Unknown error'));
                }
            }).catch(function(e) {
                setBtnBusy(btn, false);
                alert('Email request failed: ' + e.message);
            });
        });
    });

    // Reissue button — confirm prompt (cost disclosure), then AJAX.
    document.querySelectorAll('.rtoc-cert-reissue-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id        = btn.dataset.certId;
            var num       = btn.dataset.certNumber;
            var name      = btn.dataset.fullname;
            var sesskey   = btn.dataset.sesskey;
            var msg = 'Reissue ' + num + ' for ' + name + '?\n\n' +
                      'A new certificate will be issued with a fresh certificate number ' +
                      'and today\'s issue date. The original is preserved for the audit trail.\n\n' +
                      'This will charge 5 credits.';
            if (!confirm(msg)) {
                return;
            }
            setBtnBusy(btn, true, 'Reissuing...');
            var fd = new FormData();
            fd.append('id', id);
            fd.append('sesskey', sesskey);
            fetch(REISSUE_URL, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function(r) {
                return r.json().then(function(j) { return { ok: r.ok, body: j }; });
            }).then(function(res) {
                if (res.body && res.body.ok) {
                    btn.textContent = 'Reissued: ' + res.body.new_certnumber;
                    btn.classList.remove('btn-warning');
                    btn.classList.add('btn-success');
                    setTimeout(function() { window.location.reload(); }, 1000);
                } else {
                    setBtnBusy(btn, false);
                    var err = (res.body && res.body.error) || 'Unknown error';
                    if (res.body && res.body.buyUrl) {
                        if (confirm(err + '\n\nOpen credit purchase page?')) {
                            window.open(res.body.buyUrl, '_blank');
                        }
                    } else {
                        alert('Reissue failed: ' + err);
                    }
                }
            }).catch(function(e) {
                setBtnBusy(btn, false);
                alert('Reissue request failed: ' + e.message);
            });
        });
    });

    // Delete button — confirm, then AJAX revoke.
    document.querySelectorAll('.rtoc-cert-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id      = btn.dataset.certId;
            var num     = btn.dataset.certNumber;
            var name    = btn.dataset.fullname;
            var sesskey = btn.dataset.sesskey;
            var msg = 'Delete certificate ' + num + ' for ' + name + '?\n\n' +
                      'The certificate will be revoked and removed from the issued list. ' +
                      'This action cannot be undone.';
            if (!confirm(msg)) {
                return;
            }
            setBtnBusy(btn, true, 'Deleting...');
            var fd = new FormData();
            fd.append('id', id);
            fd.append('sesskey', sesskey);
            fetch(DELETE_URL, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function(r) {
                return r.json().then(function(j) { return { ok: r.ok, body: j }; });
            }).then(function(res) {
                if (res.body && res.body.ok) {
                    btn.textContent = 'Deleted';
                    btn.classList.remove('btn-outline-danger');
                    btn.classList.add('btn-secondary');
                    setTimeout(function() { window.location.reload(); }, 800);
                } else {
                    setBtnBusy(btn, false);
                    alert('Delete failed: ' + ((res.body && res.body.error) || 'Unknown error'));
                }
            }).catch(function(e) {
                setBtnBusy(btn, false);
                alert('Delete request failed: ' + e.message);
            });
        });
    });

    // ── Bulk actions (table view only) ─────────────────────────────────────
    var bar         = document.getElementById('rtoc-bulk-bar');
    var countEl     = document.getElementById('rtoc-bulk-count');
    var selectAll   = document.getElementById('rtoc-select-all');
    var rowChecks   = document.querySelectorAll('.rtoc-cert-checkbox');
    var btnEmail    = document.getElementById('rtoc-bulk-email');
    var btnZip      = document.getElementById('rtoc-bulk-zip');
    var btnCsv      = document.getElementById('rtoc-bulk-csv');
    var btnClear    = document.getElementById('rtoc-bulk-clear');

    if (!bar || rowChecks.length === 0) {
        return; // Card view, or no rows.
    }

    function selectedIds() {
        var out = [];
        rowChecks.forEach(function(cb) { if (cb.checked) { out.push(cb.value); } });
        return out;
    }

    function refreshBar() {
        var n = selectedIds().length;
        countEl.textContent = n;
        bar.style.visibility = n > 0 ? 'visible' : 'hidden';
        if (selectAll) {
            selectAll.checked       = (n > 0 && n === rowChecks.length);
            selectAll.indeterminate = (n > 0 && n < rowChecks.length);
        }
    }

    rowChecks.forEach(function(cb) { cb.addEventListener('change', refreshBar); });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            rowChecks.forEach(function(cb) { cb.checked = selectAll.checked; });
            refreshBar();
        });
    }

    btnClear.addEventListener('click', function() {
        rowChecks.forEach(function(cb) { cb.checked = false; });
        refreshBar();
    });

    function setBarBusy(busy) {
        [btnEmail, btnZip, btnCsv, btnClear].forEach(function(b) { b.disabled = busy; });
    }

    function makeInput(name, val) {
        var i = document.createElement('input');
        i.type = 'hidden';
        i.name = name;
        i.value = val;
        return i;
    }

    // Email — AJAX, returns JSON summary.
    btnEmail.addEventListener('click', function() {
        var ids = selectedIds();
        if (ids.length === 0) { return; }
        if (!confirm('Email ' + ids.length + ' certificate(s) to their students?\n\nAlready-emailed, USI-blocked, and replaced certificates will be skipped automatically.\n\nNo credits are charged for emailing.')) {
            return;
        }
        setBarBusy(true);
        var origLabel = btnEmail.textContent;
        btnEmail.textContent = 'Sending...';

        var fd = new FormData();
        fd.append('action', 'email');
        fd.append('sesskey', SESSKEY);
        fd.append('ids', ids.join(','));

        fetch(BULK_URL, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function(r) {
            return r.json().then(function(j) { return { ok: r.ok, body: j }; });
        }).then(function(res) {
            setBarBusy(false);
            btnEmail.textContent = origLabel;
            if (!res.body || !res.body.ok) {
                alert('Bulk email failed: ' + ((res.body && res.body.error) || 'Unknown error'));
                return;
            }
            var c = res.body.counts;
            var msg = 'Bulk email complete:\n' +
                      '  Sent:    ' + c.sent + '\n' +
                      '  Skipped: ' + c.skipped + '\n' +
                      '  Failed:  ' + c.failed;
            alert(msg);
            window.location.reload();
        }).catch(function(e) {
            setBarBusy(false);
            btnEmail.textContent = origLabel;
            alert('Bulk email request failed: ' + e.message);
        });
    });

    // Download ZIP — submit a form so the browser handles the binary download.
    btnZip.addEventListener('click', function() {
        var ids = selectedIds();
        if (ids.length === 0) { return; }
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = BULK_URL;
        f.target = '_self';
        f.style.display = 'none';
        f.appendChild(makeInput('action', 'download_zip'));
        f.appendChild(makeInput('sesskey', SESSKEY));
        f.appendChild(makeInput('ids', ids.join(',')));
        document.body.appendChild(f);
        f.submit();
    });

    // Export CSV — also a form submit (binary stream).
    btnCsv.addEventListener('click', function() {
        var ids = selectedIds();
        if (ids.length === 0) { return; }
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = BULK_URL;
        f.target = '_self';
        f.style.display = 'none';
        f.appendChild(makeInput('action', 'export_csv'));
        f.appendChild(makeInput('sesskey', SESSKEY));
        f.appendChild(makeInput('ids', ids.join(',')));
        document.body.appendChild(f);
        f.submit();
    });

    refreshBar();
})();
</script>
<?php

echo $OUTPUT->footer();
