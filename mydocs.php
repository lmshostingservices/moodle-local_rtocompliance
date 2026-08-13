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
 * RTO Compliance plugin — mydocs.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// v4.9.108 STUDENT-DOC-REPOSITORY — Central student document & certificate portal.
//
// One page per student that combines:
//   (1) All issued certificates (from local_rtocompliance_certs) with download/verify links.
//   (2) Teacher-uploaded documents (from local_rtocompliance_student_docs) — RPL decisions,
//       USI letters, suitability evidence, credit transfer records, enrolment agreements,
//       third-party workplace records, AVETMISS exports, and other evidence.
//   (3) Upload form (admins/trainers with issuecerts or viewall capability).
//   (4) Delete uploaded documents (admins/trainers only, with confirmation).
//
// Access:
//   - Students: their own page only (local/rtocompliance:viewown on user context).
//   - Admins/trainers: any student's page (?userid=X) via local/rtocompliance:viewall.
//   - Upload/delete: local/rtocompliance:issuecerts OR local/rtocompliance:viewall.
//
// File storage: Moodle file API, component='local_rtocompliance', filearea='student_doc',
//               contextid=system context, itemid=local_rtocompliance_student_docs.id.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

$userid  = optional_param('userid', $USER->id, PARAM_INT);
$action  = optional_param('action', '',         PARAM_ALPHA);
$docid   = optional_param('docid',  0,          PARAM_INT);

$syscontext  = context_system::instance();
$usercontext = context_user::instance($userid);

if ($userid != $USER->id) {
    require_capability('local/rtocompliance:viewall', $syscontext);
}
require_capability('local/rtocompliance:viewown', $usercontext);

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

$canupload = has_capability('local/rtocompliance:issuecerts', $syscontext) ||
             has_capability('local/rtocompliance:viewall',    $syscontext);

// ── POST: Upload document ─────────────────────────────────────────────────
if ($action === 'upload' && $canupload && confirm_sesskey()) {
    $doctype = required_param('doctype', PARAM_ALPHANUMEXT);
    $notes   = optional_param('notes',   '', PARAM_TEXT);

    if (empty($_FILES['docfile']['tmp_name']) || $_FILES['docfile']['error'] !== UPLOAD_ERR_OK) {
        redirect(
            new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $userid]),
            'No file uploaded or upload error.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $originalname = clean_filename(basename($_FILES['docfile']['name']));
    $filesize     = (int) $_FILES['docfile']['size'];
    $mimetype     = $_FILES['docfile']['type'] ?: 'application/octet-stream';
    $contents     = file_get_contents($_FILES['docfile']['tmp_name']);

    if ($contents === false || $filesize > 20 * 1024 * 1024) {
        redirect(
            new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $userid]),
            $filesize > 20 * 1024 * 1024 ? 'File exceeds 20 MB limit.' : 'Could not read uploaded file.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Insert DB record first — need the ID as file itemid.
    $doc               = new stdClass();
    $doc->userid       = $userid;
    $doc->uploaderid   = $USER->id;
    $doc->doctype      = $doctype;
    $doc->notes        = $notes;
    $doc->filename     = $originalname;
    $doc->filesize     = $filesize;
    $doc->mimetype     = $mimetype;
    $doc->contextid    = $syscontext->id;
    $doc->timecreated  = time();
    $doc->timemodified = time();
    $doc->id           = $DB->insert_record('local_rtocompliance_student_docs', $doc);

    $fs       = get_file_storage();
    $fileinfo = [
        'contextid' => $syscontext->id,
        'component' => 'local_rtocompliance',
        'filearea'  => 'student_doc',
        'itemid'    => $doc->id,
        'filepath'  => '/',
        'filename'  => $originalname,
    ];
    $existing = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                               $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
    if ($existing) {
        $existing->delete();
    }
    $fs->create_file_from_string($fileinfo, $contents);

    redirect(
        new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $userid]),
        'Document uploaded successfully.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── POST: Delete document ─────────────────────────────────────────────────
if ($action === 'delete' && $canupload && $docid > 0 && confirm_sesskey()) {
    $doc = $DB->get_record('local_rtocompliance_student_docs', ['id' => $docid, 'userid' => $userid]);
    if ($doc) {
        $fs = get_file_storage();
        $fs->delete_area_files($syscontext->id, 'local_rtocompliance', 'student_doc', $docid);
        $DB->delete_records('local_rtocompliance_student_docs', ['id' => $docid]);
    }
    redirect(
        new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $userid]),
        'Document deleted.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Page setup ────────────────────────────────────────────────────────────
$PAGE->set_url('/local/rtocompliance/mydocs.php', ['userid' => $userid]);
$PAGE->set_context($usercontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mydocuments', 'local_rtocompliance') . ' — ' . fullname($user));
$PAGE->set_heading(fullname($user) . ' — ' . get_string('mydocuments', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// ── Fetch data ────────────────────────────────────────────────────────────
$certs = $DB->get_records_sql(
    "SELECT * FROM {local_rtocompliance_certs}
     WHERE userid = :userid AND status = :status
     ORDER BY issuedate DESC",
    ['userid' => $userid, 'status' => 'issued']
);

$docs = $DB->get_records_sql(
    "SELECT d.*, " . $DB->sql_fullname('u.firstname', 'u.lastname') . " AS uploadername
     FROM {local_rtocompliance_student_docs} d
     JOIN {user} u ON u.id = d.uploaderid
     WHERE d.userid = :userid
     ORDER BY d.timecreated DESC",
    ['userid' => $userid]
);

$certtypes = local_rtocompliance_get_certificate_types();

$doctypelabels = [
    'rpl'                 => 'RPL Decision / Evidence',
    'usi_letter'          => 'USI Verification Letter',
    'suitability'         => 'Suitability Assessment',
    'credit_transfer'     => 'Credit Transfer Record',
    'enrolment_agreement' => 'Enrolment Agreement',
    'third_party'         => 'Third-Party Workplace Record',
    'nat_export'          => 'AVETMISS Export',
    'other'               => 'Other Document',
];

// ── Output ────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('mydocuments', 'local_rtocompliance'), null, null, 'certificates');

echo html_writer::start_div('certificates-container');

// Page header
echo html_writer::start_div('certificates-header');
echo html_writer::start_div('d-flex align-items-center justify-content-between flex-wrap', ['style' => 'gap:8px;']);
echo html_writer::tag('h2', get_string('mydocuments', 'local_rtocompliance'));
if ($userid != $USER->id) {
    echo html_writer::tag('span', 'Viewing: ' . fullname($user), ['class' => 'badge badge-info']);
}
echo html_writer::end_div();
echo html_writer::end_div();

// Stat summary
$totalcerts = count($certs);
$totaldocs  = count($docs);
echo html_writer::start_div('', ['style' => 'display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;']);
echo html_writer::start_div('', ['style' => 'background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 20px;min-width:110px;']);
echo html_writer::tag('div', $totalcerts, ['style' => 'font-size:1.6rem;font-weight:700;color:#0284c7;line-height:1;']);
echo html_writer::tag('div', 'Certificates', ['style' => 'font-size:0.8rem;color:#64748b;margin-top:2px;']);
echo html_writer::end_div();
echo html_writer::start_div('', ['style' => 'background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 20px;min-width:110px;']);
echo html_writer::tag('div', $totaldocs, ['style' => 'font-size:1.6rem;font-weight:700;color:#16a34a;line-height:1;']);
echo html_writer::tag('div', 'Documents', ['style' => 'font-size:0.8rem;color:#64748b;margin-top:2px;']);
echo html_writer::end_div();
echo html_writer::end_div();

// ── SECTION 1: Certificates ───────────────────────────────────────────────
// White content card (matches the plugin's card system) — the previous
// rtoc-clause-banner rendered a dark navy panel with white title text, so the
// muted empty-state text was unreadable on the dark background.
echo html_writer::start_div('mb-4', ['style' => 'background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 1px 2px rgba(15,23,42,.04);']);
echo html_writer::start_div('', ['style' => 'margin-bottom:12px;']);
echo html_writer::tag('span', 'Certificates', ['style' => 'display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6366f1;margin-bottom:4px;']);
echo html_writer::tag('h4', 'Issued Certificates', ['style' => 'margin:0;font-size:1.125rem;font-weight:700;color:#0f172a;']);
echo html_writer::end_div();
echo html_writer::start_div('');

if (empty($certs)) {
    echo html_writer::tag('p', 'No certificates have been issued yet.', ['class' => 'text-muted']);
} else {
    echo '<table class="table table-sm table-bordered" style="background:#fff;">';
    echo '<thead><tr>'
        . '<th title="The kind of certificate — Testamur, Statement of Attainment, Record of Results or Course Completion">Type</th>'
        . '<th title="The qualification (or unit) this certificate was issued for">Qualification / Unit</th>'
        . '<th title="The unique certificate number for verification and your records">Cert Number</th>'
        . '<th title="The date this certificate was issued">Issued</th>'
        . '<th title="Download the certificate PDF, or open the public verification page">Actions</th>'
        . '</tr></thead>';
    echo '<tbody>';
    foreach ($certs as $cert) {
        $typelabel = $certtypes[$cert->certtype] ?? $cert->certtype;
        $typebadge = ['testamur' => 'badge-success', 'statement' => 'badge-info',
                      'record'   => 'badge-secondary', 'completion' => 'badge-warning'][$cert->certtype] ?? 'badge-light';
        $issuedate = $cert->issuedate ? date('d M Y', $cert->issuedate) : '—';
        $qualparts = array_filter([
            !empty($cert->qualificationcode) ? htmlspecialchars($cert->qualificationcode) : '',
            !empty($cert->qualificationname) ? htmlspecialchars($cert->qualificationname) : '',
        ]);
        $qualstr = $qualparts ? implode(' — ', $qualparts) : '—';
        $downloadurl = new moodle_url('/local/rtocompliance/download_cert.php', ['certid' => $cert->id]);
        $verifyurl   = new moodle_url('/local/rtocompliance/verify.php', ['token' => $cert->verifytoken]);
        echo '<tr>';
        echo '<td><span class="badge ' . $typebadge . '">' . htmlspecialchars($typelabel) . '</span></td>';
        echo '<td>' . $qualstr . '</td>';
        echo '<td><code>' . htmlspecialchars($cert->certnumber ?? '—') . '</code></td>';
        echo '<td>' . $issuedate . '</td>';
        echo '<td>';
        echo '<a href="' . $downloadurl->out(false) . '" class="btn btn-sm btn-outline-primary mr-1" title="Download this certificate as a PDF">Download PDF</a>';
        echo '<a href="' . $verifyurl->out(false) . '" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open the public verification page for this certificate (new tab)">Verify</a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo html_writer::end_div();
echo html_writer::end_div();

// ── SECTION 2: Uploaded Documents ────────────────────────────────────────
echo html_writer::start_div('mb-4', ['style' => 'background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 1px 2px rgba(15,23,42,.04);']);
echo html_writer::start_div('', ['style' => 'margin-bottom:12px;']);
echo html_writer::tag('span', 'Documents', ['style' => 'display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6366f1;margin-bottom:4px;']);
echo html_writer::tag('h4', 'Uploaded Documents', ['style' => 'margin:0;font-size:1.125rem;font-weight:700;color:#0f172a;']);
echo html_writer::end_div();
echo html_writer::start_div('');

if (empty($docs)) {
    echo html_writer::tag('p', 'No documents have been uploaded yet.', ['class' => 'text-muted']);
} else {
    echo '<table class="table table-sm table-bordered" style="background:#fff;">';
    echo '<thead><tr>'
        . '<th title="The document category (e.g. RPL Decision, USI Verification Letter, Enrolment Agreement)">Type</th>'
        . '<th title="The uploaded file name and its size">Filename</th>'
        . '<th title="Any notes recorded when the document was uploaded">Notes</th>'
        . '<th title="The staff member or student who uploaded this document">Uploaded By</th>'
        . '<th title="The date the document was uploaded">Date</th>'
        . '<th title="Download the file, or delete it (staff only)">Actions</th>'
        . '</tr></thead>';
    echo '<tbody>';
    foreach ($docs as $doc) {
        $dtlabel  = $doctypelabels[$doc->doctype] ?? htmlspecialchars($doc->doctype);
        $filesize_str = $doc->filesize > 0 ? ' <small class="text-muted">(' . round($doc->filesize / 1024, 1) . ' KB)</small>' : '';
        $dlurl = new moodle_url('/local/rtocompliance/student_docs_download.php', [
            'docid'   => $doc->id,
            'sesskey' => sesskey(),
        ]);
        $deletehtml = '';
        if ($canupload) {
            $delurl = new moodle_url('/local/rtocompliance/mydocs.php', [
                'userid'  => $userid,
                'action'  => 'delete',
                'docid'   => $doc->id,
                'sesskey' => sesskey(),
            ]);
            $deletehtml = ' <a href="' . $delurl->out(false) . '" class="btn btn-sm btn-outline-danger"'
                        . ' title="Delete this document permanently" onclick="return confirm(\'Delete this document?\')">Delete</a>';
        }
        echo '<tr>';
        echo '<td><span class="badge badge-secondary">' . htmlspecialchars($dtlabel) . '</span></td>';
        echo '<td>' . htmlspecialchars($doc->filename) . $filesize_str . '</td>';
        echo '<td>' . htmlspecialchars($doc->notes ?: '—') . '</td>';
        echo '<td>' . htmlspecialchars($doc->uploadername) . '</td>';
        echo '<td>' . date('d M Y', $doc->timecreated) . '</td>';
        echo '<td><a href="' . $dlurl->out(false) . '" class="btn btn-sm btn-outline-primary mr-1" title="Download this document">Download</a>' . $deletehtml . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

// ── Upload form (admin / trainer only) ────────────────────────────────────
if ($canupload) {
    echo html_writer::start_div('', ['style' => 'margin-top:16px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;']);
    echo html_writer::tag('h6', 'Upload Document for ' . fullname($user), ['style' => 'font-weight:700;margin-bottom:14px;']);

    $uploadurl = new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $userid]);
    echo '<form method="post" action="' . $uploadurl->out(false) . '" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action"  value="upload">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

    echo '<div class="form-group row mb-2">';
    echo '<label class="col-sm-3 col-form-label col-form-label-sm" for="sdoctype">Document Type</label>';
    echo '<div class="col-sm-9"><select name="doctype" id="sdoctype" class="form-control form-control-sm" required>';
    foreach ($doctypelabels as $k => $v) {
        echo '<option value="' . htmlspecialchars($k) . '">' . htmlspecialchars($v) . '</option>';
    }
    echo '</select></div></div>';

    echo '<div class="form-group row mb-2">';
    echo '<label class="col-sm-3 col-form-label col-form-label-sm" for="sdocnotes">Notes</label>';
    echo '<div class="col-sm-9"><input type="text" name="notes" id="sdocnotes" class="form-control form-control-sm"'
       . ' maxlength="500" placeholder="Optional description or context"></div></div>';

    echo '<div class="form-group row mb-2">';
    echo '<label class="col-sm-3 col-form-label col-form-label-sm" for="sdocfile">File</label>';
    echo '<div class="col-sm-9"><input type="file" name="docfile" id="sdocfile" class="form-control-file" required>';
    echo '<small class="form-text text-muted">PDF, Word, images or any file — max 20 MB</small></div></div>';

    echo '<div class="form-group row"><div class="col-sm-9 offset-sm-3">';
    echo '<button type="submit" class="btn btn-primary btn-sm">Upload Document</button>';
    echo '</div></div>';

    echo '</form>';
    echo html_writer::end_div();
}

echo html_writer::end_div(); // rtoc-clause-banner-body
echo html_writer::end_div(); // rtoc-clause-banner

echo html_writer::end_div(); // certificates-container
echo $OUTPUT->footer();
