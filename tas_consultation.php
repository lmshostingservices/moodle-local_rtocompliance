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
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_tas');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$context = context_system::instance();

$tasid = required_param('tasid', PARAM_INT);
$editid = optional_param('editid', 0, PARAM_INT);
$addnew = optional_param('addnew', 0, PARAM_INT);
$deleteid = optional_param('deleteid', 0, PARAM_INT);
$generatetemplate = optional_param('generatetemplate', 0, PARAM_INT);

$tas = $DB->get_record('local_rtocompliance_tas', ['id' => $tasid], '*', MUST_EXIST);

// FIX-MAY5-ADDNEW-URL (v4.4.45): include addnew and editid in PAGE URL so
// qualified_me() returns the correct action URL for the Moodle form — prevents
// the URL query string from losing addnew=1 on installs with aggressive URL
// rewriting or reverse-proxy configurations.
$PAGE->set_url(new moodle_url('/local/rtocompliance/tas_consultation.php', [
    'tasid'  => $tasid,
    'addnew' => $addnew,
    'editid' => $editid,
]));
$PAGE->set_title('Industry Consultation - ' . $tas->qualificationcode);
$PAGE->navbar->add(get_string('tas', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/tas.php'));
$PAGE->navbar->add('Edit TAS', new moodle_url('/local/rtocompliance/tas_edit.php', ['id' => $tasid]));
$PAGE->navbar->add('Industry Consultation');
$PAGE->requires->css('/local/rtocompliance/styles.css');

$tablename = 'local_rtocompliance_tas_consult';

if ($deleteid && confirm_sesskey()) {
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'local_rtocompliance', 'consultation_evidence', $deleteid);
    $DB->delete_records($tablename, ['id' => $deleteid, 'tasid' => $tasid]);
    $remaining = $DB->get_records($tablename, ['tasid' => $tasid], 'consultationdate DESC');
    $narrative = local_rtocompliance_generate_consultation_narrative($tas, $remaining);
    $DB->set_field('local_rtocompliance_tas', 'industryconsultation', $narrative, ['id' => $tasid]);
    redirect(
        new moodle_url('/local/rtocompliance/tas_consultation.php', ['tasid' => $tasid]),
        'Consultation record deleted.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($generatetemplate) {
    $safeCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $tas->qualificationcode);
    $filename = 'Industry_Consultation_Log_' . $safeCode . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $qualTitle = $tas->qualificationname ?: $tas->qualificationcode;
    $qualCode = $tas->qualificationcode;

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rels = $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $wordrels = $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $contenttypes = $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>';

    $styles = $xml . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:pPr><w:spacing w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>'
        . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
        . '</w:tblBorders></w:tblPr></w:style>'
        . '</w:styles>';

    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    $body = '';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Industry Consultation Log</w:t></w:r></w:p>';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>' . htmlspecialchars($qualCode . ' - ' . $qualTitle) . '</w:t></w:r></w:p>';
    $body .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';

    $headers = ['Date', 'Organisation', 'Industry Representative', 'Role', 'Contact Details',
                'Consultation Method', 'Key Feedback', 'Impact on Training Delivery', 'Impact on Assessment Design'];

    $methods = [
        'meetings with workplace supervisors and managers',
        'email consultation with business representatives',
        'informal discussions with industry contacts',
        'trainer discussions with industry stakeholders',
        'review of workplace documentation and administrative procedures',
    ];

    $body .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/></w:tblPr>';
    $body .= '<w:tr>';
    foreach ($headers as $h) {
        $body .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/></w:tcPr>'
            . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="18"/></w:rPr>'
            . '<w:t>' . htmlspecialchars($h) . '</w:t></w:r></w:p></w:tc>';
    }
    $body .= '</w:tr>';

    $methodCellXml = '<w:tc>';
    $methodCellXml .= '<w:p><w:r><w:rPr><w:b/><w:sz w:val="16"/></w:rPr><w:t>Select most appropriate:</w:t></w:r></w:p>';
    foreach ($methods as $m) {
        $methodCellXml .= '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="16"/></w:rPr>'
            . '<w:t xml:space="preserve">- ' . htmlspecialchars($m) . '</w:t></w:r></w:p>';
    }
    $methodCellXml .= '</w:tc>';

    for ($i = 0; $i < 5; $i++) {
        $body .= '<w:tr>';
        foreach ($headers as $idx => $h) {
            if ($idx === 5) {
                $body .= $methodCellXml;
            } else {
                $body .= '<w:tc><w:p><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t> </w:t></w:r></w:p></w:tc>';
            }
        }
        $body .= '</w:tr>';
    }
    $body .= '</w:tbl>';

    $body .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Summary of Industry Feedback</w:t></w:r></w:p>';
    $body .= '<w:p><w:r><w:rPr><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">'
        . 'Industry representatives consistently indicated that graduates of the '
        . htmlspecialchars($qualCode . ' ' . $qualTitle)
        . ' require strong capabilities in:'
        . '</w:t></w:r></w:p>';
    $body .= '<w:p><w:r><w:rPr><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">'
        . '(Enter summary of key feedback themes here)'
        . '</w:t></w:r></w:p>';

    $body .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Ongoing Industry Engagement</w:t></w:r></w:p>';
    $body .= '<w:p><w:r><w:rPr><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">'
        . 'Industry consultation will continue throughout the delivery of this qualification through periodic meetings, '
        . 'employer feedback, trainer industry engagement, and review of industry trends.'
        . '</w:t></w:r></w:p>';

    $body .= '<w:p><w:r><w:t> </w:t></w:r></w:p>';
    $nextHeaders = ['Date', 'With Whom', 'Purpose of Meeting', 'Outcome of Meeting'];
    $body .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/></w:tblPr>';
    $body .= '<w:tr>';
    foreach ($nextHeaders as $h) {
        $body .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/></w:tcPr>'
            . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="20"/></w:rPr>'
            . '<w:t>' . htmlspecialchars($h) . '</w:t></w:r></w:p></w:tc>';
    }
    $body .= '</w:tr>';
    for ($i = 0; $i < 4; $i++) {
        $body .= '<w:tr>';
        foreach ($nextHeaders as $h) {
            $body .= '<w:tc><w:p><w:r><w:t> </w:t></w:r></w:p></w:tc>';
        }
        $body .= '</w:tr>';
    }
    $body .= '</w:tbl>';

    $document = $xml . '<w:document ' . $ns . '><w:body>' . $body . '</w:body></w:document>';

    $tmpfile = tempnam(sys_get_temp_dir(), 'docx_');
    $zip = new ZipArchive();
    $zip->open($tmpfile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contenttypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/_rels/document.xml.rels', $wordrels);
    $zip->addFromString('word/document.xml', $document);
    $zip->addFromString('word/styles.xml', $styles);
    $zip->close();

    @ob_end_clean();
    readfile($tmpfile);
    unlink($tmpfile);
    exit;
}

$consultationmethods = [
    '' => '-- Select method --',
    'meeting' => 'Meeting with workplace supervisors/managers',
    'email' => 'Email consultation with industry representatives',
    'informal' => 'Informal discussions with industry contacts',
    'trainer' => 'Trainer discussions with industry stakeholders',
    'review' => 'Review of workplace documentation and procedures',
    'advisory' => 'Industry advisory committee',
    'site_visit' => 'Site visit / workplace observation',
    'survey' => 'Industry survey / questionnaire',
    'other' => 'Other',
];

class consultation_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $entry = $this->_customdata['entry'] ?? null;
        $tasid = $this->_customdata['tasid'];
        $methods = $this->_customdata['methods'];

        $mform->addElement('hidden', 'editid');
        $mform->setType('editid', PARAM_INT);

        $mform->addElement('hidden', 'tasid', $tasid);
        $mform->setType('tasid', PARAM_INT);

        // FIX-MAY5-ADDNEW-HIDDEN (v4.4.45): embed addnew in the POST body so
        // the value reaches optional_param('addnew') even when reverse proxies
        // strip query strings from POST requests, ensuring $form guard evaluates
        // correctly and get_data() is not silently discarded.
        $mform->addElement('hidden', 'addnew', $this->_customdata['addnew'] ?? 0);
        $mform->setType('addnew', PARAM_INT);

        $mform->addElement('text', 'participantname', 'Industry Representative Name', ['size' => 50]);
        $mform->setType('participantname', PARAM_TEXT);
        $mform->addRule('participantname', 'Required', 'required', null, 'client');

        $mform->addElement('text', 'participantorg', 'Organisation', ['size' => 50]);
        $mform->setType('participantorg', PARAM_TEXT);

        $mform->addElement('text', 'participantrole', 'Role / Position', ['size' => 50]);
        $mform->setType('participantrole', PARAM_TEXT);

        $mform->addElement('text', 'contactdetails', 'Contact Details', ['size' => 50, 'placeholder' => 'Email or phone']);
        $mform->setType('contactdetails', PARAM_TEXT);

        $mform->addElement('select', 'consultationmethod', 'Consultation Method', $methods);
        $mform->addRule('consultationmethod', 'Required', 'required', null, 'client');

        $mform->addElement('date_selector', 'consultationdate', 'Consultation Date');

        $feedbackOptions = [
            'critical_thinking'   => 'Critical Thinking & Problem Solving',
            'digital_tools'       => 'Digital Tools & Technology Skills',
            'communication'       => 'Communication & Interpersonal Skills',
            'time_management'     => 'Time Management & Prioritisation',
            'workplace_realism'   => 'Workplace Realism & Practical Application',
            'compliance'          => 'Regulatory Compliance & Workplace Safety',
            'customer_service'    => 'Customer Service & Client Interaction',
            'teamwork'            => 'Teamwork & Collaboration',
            'professionalism'     => 'Professional & Ethical Conduct',
            'leadership'          => 'Leadership & Decision Making',
        ];
        // BUG-TAS-OVERLAP-2 (v4.2.21): The original helper used a fixed-height
        // <select multiple size="5"> listbox.  On the EDIT view the surrounding
        // Moodle form-control wrapper applies a narrower width than on ADD, and the
        // listbox+button row escaped the column boundary, visually overlapping the
        // action buttons below.  Replaced with a flex-wrap checkbox grid that has
        // NO fixed height and can never overflow its parent — same UX (tick boxes,
        // click "Add Selected" to copy into the textarea), more robust layout.
        $feedbackSelect = local_rtocompliance_render_quickadd_helper(
            'rtoc-feedback-grid',
            'id_feedback',
            'Quick-add Feedback Categories:',
            $feedbackOptions
        );
        // BUG-TAS-OVERLAP-3 (v4.2.23): use 'html' raw insertion instead of 'static'
        // so Moodle does NOT wrap the helper in a .fitem/.felement form-row that
        // visually clipped/constrained its height on the EDIT view, causing the
        // checkbox rows to overlap the next field's label.
        $mform->addElement('html', $feedbackSelect);

        // FIX-RTO-TESTER-FEEDBACK-MAY1 #6: add AI Generate button beneath each
        // of the three free-text consultation boxes (feedback, impacttraining,
        // impactassessment) — wired to ajax.php new contexttypes.
        $mform->addElement('textarea', 'feedback', 'Key Feedback', ['rows' => 3, 'cols' => 60, 'placeholder' => 'What key feedback was provided by the industry representative?']);
        $mform->setType('feedback', PARAM_RAW); // pipeline-ignore: PARAM_RAW — rich-text long-form field; sanitised before display
        $mform->addElement('static', 'feedbackaihelp', '',
            '<div class="rtoc-ai-box">' .
            '<button type="button" id="rtoc-ai-consult-feedback" class="btn btn-primary" data-target="id_feedback" data-context="consult_feedback">' .
            '<i class="fa fa-magic" aria-hidden="true"></i> AI: Generate Key Feedback' .
            '</button>' .
            '<span id="rtoc-ai-consult-feedback-status" class="rtoc-ai-status"></span>' .
            '<small class="rtoc-ai-hint d-block mt-1 text-muted">Uses the participant role/organisation, consultation method and ticked feedback categories to draft a 3-5 sentence summary of the key feedback.</small>' .
            '</div>');

        $trainingOptions = [
            'scenario_based'   => 'Scenario-Based Learning & Case Studies',
            'practical_tasks'  => 'Increased Practical Tasks & Workplace Simulations',
            'digital_integration' => 'Digital Tool Integration in Delivery',
            'problem_solving'  => 'Problem-Solving Activities & Critical Thinking Exercises',
            'time_simulation'  => 'Time-Pressured & Prioritisation Simulations',
            'guest_speakers'   => 'Industry Guest Speakers & Site Visits',
            'contextualised'   => 'Contextualised Delivery to Industry Sector',
            'workplace_proj'   => 'Workplace Projects & On-the-Job Activities',
            'coaching'         => 'Coaching & Mentoring from Industry Practitioners',
            'flipped'          => 'Flipped Classroom & Self-Directed Pre-Reading',
        ];
        // BUG-TAS-OVERLAP-2 (v4.2.21): see feedback helper above for rationale.
        $trainingSelect = local_rtocompliance_render_quickadd_helper(
            'rtoc-training-grid',
            'id_impacttraining',
            'Quick-add Training Delivery Impact:',
            $trainingOptions
        );
        // BUG-TAS-OVERLAP-3 (v4.2.23): see feedback helper above for rationale.
        $mform->addElement('html', $trainingSelect);

        $mform->addElement('textarea', 'impacttraining', 'Impact on Training Delivery', ['rows' => 3, 'cols' => 60, 'placeholder' => 'How has this feedback been incorporated into training delivery?']);
        $mform->setType('impacttraining', PARAM_RAW); // pipeline-ignore: PARAM_RAW — rich-text long-form field; sanitised before display
        $mform->addElement('static', 'impacttrainingaihelp', '',
            '<div class="rtoc-ai-box">' .
            '<button type="button" id="rtoc-ai-consult-training" class="btn btn-primary" data-target="id_impacttraining" data-context="consult_impact_training">' .
            '<i class="fa fa-magic" aria-hidden="true"></i> AI: Generate Impact on Training Delivery' .
            '</button>' .
            '<span id="rtoc-ai-consult-training-status" class="rtoc-ai-status"></span>' .
            '<small class="rtoc-ai-hint d-block mt-1 text-muted">Drafts a 3-5 sentence statement explaining how the feedback above and the ticked training-delivery categories will be incorporated into the qualification\'s training plan.</small>' .
            '</div>');

        $assessmentOptions = [
            'authentic'      => 'More Authentic Workplace-Based Assessments',
            'portfolio'      => 'Portfolio Evidence & Workplace Documentation',
            'observation'    => 'Observation & Demonstration in Workplace Settings',
            'third_party'    => 'Third-Party Supervisor Evidence Reports',
            'rpl'            => 'Recognition of Prior Learning Pathways Strengthened',
            'timed'          => 'Timed/Realistic Workplace Assessment Conditions',
            'digital_tools2' => 'Digital Tool Proficiency Assessed',
            'feedback'       => 'Structured Assessor Feedback & Moderation',
        ];
        // BUG-TAS-OVERLAP-2 (v4.2.21): see feedback helper above for rationale.
        $assessmentSelect = local_rtocompliance_render_quickadd_helper(
            'rtoc-assessment-grid',
            'id_impactassessment',
            'Quick-add Assessment Design Impact:',
            $assessmentOptions
        );
        // BUG-TAS-OVERLAP-3 (v4.2.23): see feedback helper above for rationale.
        $mform->addElement('html', $assessmentSelect);

        $mform->addElement('textarea', 'impactassessment', 'Impact on Assessment Design', ['rows' => 3, 'cols' => 60, 'placeholder' => 'How has this feedback been incorporated into assessment design?']);
        $mform->setType('impactassessment', PARAM_RAW); // pipeline-ignore: PARAM_RAW — rich-text long-form field; sanitised before display
        $mform->addElement('static', 'impactassessmentaihelp', '',
            '<div class="rtoc-ai-box">' .
            '<button type="button" id="rtoc-ai-consult-assessment" class="btn btn-primary" data-target="id_impactassessment" data-context="consult_impact_assessment">' .
            '<i class="fa fa-magic" aria-hidden="true"></i> AI: Generate Impact on Assessment Design' .
            '</button>' .
            '<span id="rtoc-ai-consult-assessment-status" class="rtoc-ai-status"></span>' .
            '<small class="rtoc-ai-hint d-block mt-1 text-muted">Drafts a 3-5 sentence statement explaining how the feedback above and the ticked assessment-design categories will be incorporated into the qualification\'s assessment instruments.</small>' .
            '</div>');

        $mform->addElement('date_selector', 'nextmeetingdate', 'Next Meeting Date', ['optional' => true]);

        $mform->addElement('filepicker', 'evidencefile', 'Upload Evidence Document', null, [
            'maxbytes' => 10485760,
            'accepted_types' => ['*'],
        ]);
        // FIX-MAY4-FILETYPE-HINT (v4.4.44): addElement('static',...) wraps the
        // hint in Moodle's fitem/felement divs which renders as a bordered box in
        // the Boost theme, making each file-type name appear boxed.  Replaced with
        // addElement('html',...) which injects the text as raw HTML with no wrapper.
        $mform->addElement('html', '<p class="text-muted" style="margin:2px 0 8px;font-size:0.82rem">Accepted file types: PDF, Word (.doc, .docx), images (.jpg, .png) and Excel (.xls, .xlsx) — max 10 MB</p>');

        $mform->addElement('textarea', 'topicsdiscussed', 'Additional Notes', ['rows' => 2, 'cols' => 60]);
        $mform->setType('topicsdiscussed', PARAM_RAW); // pipeline-ignore: PARAM_RAW — rich-text long-form field; sanitised before display
        $this->add_action_buttons(true, $entry ? 'Update Consultation Record' : 'Add Consultation Record');
    }
}

$form = null;
$entry = null;

if ($editid || $addnew) {
    if ($editid) {
        $entry = $DB->get_record($tablename, ['id' => $editid, 'tasid' => $tasid], '*', MUST_EXIST);
    }

    $form = new consultation_form(null, [
        'entry'   => $entry,
        'tasid'   => $tasid,
        'methods' => $consultationmethods,
        'addnew'  => $addnew,
    ]);

    if ($entry) {
        $formdata = clone $entry;
        $formdata->editid = $entry->id;
        if (!empty($entry->consultationtype)) {
            $formdata->consultationmethod = $entry->consultationtype;
        }
        // FIX-RTO-TESTER-FEEDBACK-MAY1 #7: file_get_submitted_draft_itemid()
        // reads the request — on a GET (Edit click) it returns 0, which causes
        // file_prepare_draft_area to silently skip copying the existing file
        // into a draft area, so the previously-uploaded evidence appears empty
        // in the filepicker (and is wiped on save because the draft area is
        // empty).  Switch to file_get_unused_draft_itemid() so we always get a
        // fresh draft area itemid and the existing file is copied in.
        $draftitemid = file_get_unused_draft_itemid();
        file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'local_rtocompliance',
            'consultation_evidence',
            $entry->id,
            ['maxbytes' => 10485760, 'maxfiles' => 1]
        );
        $formdata->evidencefile = $draftitemid;
        $form->set_data($formdata);
    }

    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/rtocompliance/tas_consultation.php', ['tasid' => $tasid]));
    }
}

if ($form && ($data = $form->get_data())) {
    $record = new stdClass();
    $record->tasid = $tasid;
    $record->participantname = $data->participantname;
    $record->participantorg = $data->participantorg ?? '';
    $record->participantrole = $data->participantrole ?? '';
    $record->contactdetails = $data->contactdetails ?? '';
    $record->consultationtype = $data->consultationmethod ?? '';
    $record->consultationdate = $data->consultationdate;
    $record->feedback = $data->feedback ?? '';
    $record->impacttraining = $data->impacttraining ?? '';
    $record->impactassessment = $data->impactassessment ?? '';
    $record->topicsdiscussed = $data->topicsdiscussed ?? '';
    $record->actionsagreed = '';
    $record->nextmeetingdate = $data->nextmeetingdate ?? 0;

    if (!empty($data->editid)) {
        $record->id = $data->editid;
        $DB->update_record($tablename, $record);
        $recordid = $data->editid;
        $message = 'Consultation record updated.';
    } else {
        $record->timecreated = time();
        $record->createdby = $USER->id;
        // v4.2.57 #6: initialise evidencedocument to empty string (never NULL)
        // so subsequent $DB->set_field() updates behave consistently across
        // all Moodle DB drivers.
        $record->evidencedocument = '';
        $recordid = $DB->insert_record($tablename, $record);
        $message = 'Consultation record added.';
    }

    // v4.2.57 #6 (FIX-RTO-EVIDENCE-NOTSHOW): a customer reported that uploaded
    // evidence files were not showing in the consultation list table after
    // hitting Save.  Two defensive changes vs. the previous flow:
    //   (a) Capture the expected filename from the DRAFT area BEFORE
    //       file_save_draft_area_files() runs and set evidencedocument
    //       eagerly from that value.  Previously the code re-queried the
    //       saved area AFTER save and only set the field if that second
    //       query returned a file — any caching / timing edge that made
    //       the post-save get_area_files() return empty would silently
    //       leave evidencedocument NULL and the row would render "None".
    //   (b) Initialise $record->evidencedocument='' on insert so the
    //       column is never NULL on a fresh row (some Moodle DB drivers
    //       treat NULL char columns differently from empty strings in
    //       subsequent set_field() calls).
    $evidenceitemid = file_get_submitted_draft_itemid('evidencefile');
    if ($evidenceitemid) {
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $evidenceitemid, 'id', false);
        if (!empty($draftfiles)) {
            // v4.4.15 #6: iterate to find the first real (non-placeholder) file.
            // reset() may return the '.' placeholder Moodle inserts to mark non-empty
            // draft areas; that left evidencedocument as '.' and the set_field() guard
            // below would skip the DB update, so evidence never appeared after save.
            $expectedFilename = '.';
            foreach ($draftfiles as $df) {
                if ($df->get_filename() !== '.') {
                    $expectedFilename = $df->get_filename();
                    break;
                }
            }
            file_save_draft_area_files(
                $evidenceitemid,
                $context->id,
                'local_rtocompliance',
                'consultation_evidence',
                $recordid,
                ['maxbytes' => 10485760, 'maxfiles' => 1]
            );
            // Set the DB field eagerly using the draft filename — does not depend
            // on a second get_area_files() round-trip succeeding.
            if (!empty($expectedFilename) && $expectedFilename !== '.') {
                $DB->set_field($tablename, 'evidencedocument', $expectedFilename, ['id' => $recordid]);
            }
        }
    }

    $consultations = $DB->get_records($tablename, ['tasid' => $tasid], 'consultationdate DESC');
    $narrative = local_rtocompliance_generate_consultation_narrative($tas, $consultations);
    $DB->set_field('local_rtocompliance_tas', 'industryconsultation', $narrative, ['id' => $tasid]);

    redirect(
        new moodle_url('/local/rtocompliance/tas_consultation.php', ['tasid' => $tasid]),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$consultations = $DB->get_records($tablename, ['tasid' => $tasid], 'consultationdate DESC');

$now = time();
$twelvemonthsago = $now - (365 * 24 * 60 * 60);
$compliancestatus = 'NO_EVIDENCE';
$statusmsg = 'No consultation records uploaded yet.';
$latestdate = 0;

if ($consultations) {
    foreach ($consultations as $c) {
        if ($c->consultationdate > $latestdate) {
            $latestdate = $c->consultationdate;
        }
    }
    if ($latestdate >= $twelvemonthsago) {
        $compliancestatus = 'OK';
        $statusmsg = 'Latest consultation is within the last 12 months (' . userdate($latestdate, '%d %B %Y') . ').';
    } elseif ($latestdate >= $twelvemonthsago - (90 * 24 * 60 * 60)) {
        $compliancestatus = 'DUE';
        $statusmsg = 'Latest consultation is 12-15 months old (' . userdate($latestdate, '%d %B %Y') . '). Schedule a new consultation soon.';
    } else {
        $compliancestatus = 'OVERDUE';
        $statusmsg = 'Latest consultation is over 15 months old (' . userdate($latestdate, '%d %B %Y') . '). Urgent: schedule industry consultation.';
    }
}

// BUG-TAS-OVERLAP FIX: Register rtocAppendDropdown via Moodle's JS manager so it receives
// the correct CSP nonce in Moodle 4.x and is available at the time any button is clicked.
// Previously an inline <script> block at the end of the page was used; Moodle 4.x Boost
// applies a Content-Security-Policy nonce to Moodle-generated scripts but NOT to raw
// echo'd <script> blocks, causing the browser to refuse to execute the function and the
// "Add Selected" / "Clear field" buttons to produce a ReferenceError on click.
$PAGE->requires->js_init_code('
function rtocAppendDropdown(selectId, textareaId) {
    var sel = document.getElementById(selectId);
    var ta  = document.getElementById(textareaId);
    if (!sel || !ta) { return; }
    var chosen = [];
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].selected) { chosen.push(sel.options[i].value); }
    }
    if (!chosen.length) { return; }
    var existing = ta.value.trim();
    var addition = chosen.map(function (v){ return "- " + v; }).join("\n");
    ta.value = existing ? existing + "\n" + addition : addition;
    for (var j = 0; j < sel.options.length; j++) { sel.options[j].selected = false; }
}
// BUG-TAS-OVERLAP-2 (v4.2.21): Companion helper for the new checkbox-grid quick-add
// widgets.  Reads ticked checkboxes inside the given container and appends each value
// as a bullet to the destination textarea, then unticks them so the user can do
// another batch.  Same UX as rtocAppendDropdown above but without the fragile
// fixed-height multi-select listbox that was overflowing on the EDIT view.
function rtocAppendChecked(gridId, textareaId) {
    var grid = document.getElementById(gridId);
    var ta   = document.getElementById(textareaId);
    if (!grid || !ta) { return; }
    var boxes = grid.querySelectorAll("input[type=checkbox]:checked");
    if (!boxes.length) { return; }
    var chosen = [];
    for (var i = 0; i < boxes.length; i++) { chosen.push(boxes[i].value); }
    var existing = ta.value.trim();
    var addition = chosen.map(function (v){ return "- " + v; }).join("\n");
    ta.value = existing ? existing + "\n" + addition : addition;
    for (var j = 0; j < boxes.length; j++) { boxes[j].checked = false; }
}
', true);

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    'Industry Consultation Evidence',
    'Edit TAS',
    '/local/rtocompliance/tas_edit.php?id=' . $tasid,
    'tas'
);

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('info-card', ['style' => 'margin-bottom: 20px;']);
echo html_writer::tag('h3', $tas->qualificationcode . ' - ' . ($tas->qualificationname ?: 'Unnamed'), ['style' => 'margin: 0 0 8px 0;']);
echo html_writer::tag('p', 'Manage industry consultation evidence for this Training and Assessment Strategy. '
    . 'Download the pre-filled consultation log template, record consultation details, and upload completed evidence documents. '
    . 'The TAS "Industry Consultation Evidence" section will be auto-generated from your records.',
    ['class' => 'text-muted', 'style' => 'margin: 0;']);
echo html_writer::end_div();

$badgeColors = [
    'OK' => 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;',
    'DUE' => 'background: #fff3cd; color: #856404; border: 1px solid #ffeeba;',
    'OVERDUE' => 'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;',
    'NO_EVIDENCE' => 'background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db;',
];
$badgeStyle = $badgeColors[$compliancestatus] ?? $badgeColors['NO_EVIDENCE'];

echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 20px; padding: 12px 16px; border-radius: 8px; border: 1px solid #dee2e6; background: #fafafa;']);
echo html_writer::tag('span', $compliancestatus, ['style' => 'display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; ' . $badgeStyle]);
echo html_writer::tag('span', $statusmsg, ['style' => 'color: #555; font-size: 14px;']);
echo html_writer::end_div();

$templateUrl = new moodle_url('/local/rtocompliance/tas_consultation.php', ['tasid' => $tasid, 'generatetemplate' => 1]);
echo html_writer::start_div('', ['style' => 'margin-bottom: 24px;']);
echo html_writer::tag('a', 'Download Industry Consultation Log Template (DOCX)',
    ['href' => $templateUrl->out(false), 'class' => 'btn btn-outline-primary', 'style' => 'margin-right: 12px;']);
echo html_writer::tag('span', 'Pre-filled for ' . $tas->qualificationcode, ['style' => 'color: #888; font-size: 13px;']);
echo html_writer::end_div();

if ($consultations) {
    echo html_writer::tag('h4', 'Consultation Register (' . count($consultations) . ' records)', ['style' => 'margin-bottom: 12px;']);

    echo '<div style="overflow-x:auto;">';
    echo '<table class="generaltable" style="width: 100%; margin-bottom: 24px; table-layout: fixed; word-break: break-word; overflow-wrap: break-word;">
        <colgroup>
            <col style="width:90px">
            <col style="width:15%">
            <col style="width:15%">
            <col style="width:15%">
            <col>
            <col style="width:12%">
            <col style="width:110px">
        </colgroup>
        <thead><tr>
        <th>Date</th><th>Organisation</th><th>Representative</th><th>Method</th><th>Key Feedback</th><th>Evidence</th><th style="white-space:nowrap">Actions</th>
        </tr></thead><tbody>';

    $methodLabels = $consultationmethods;
    foreach ($consultations as $c) {
        $deleteUrl = new moodle_url('/local/rtocompliance/tas_consultation.php', [
            'tasid' => $tasid, 'deleteid' => $c->id, 'sesskey' => sesskey(),
        ]);
        $editUrl = new moodle_url('/local/rtocompliance/tas_consultation.php', [
            'tasid' => $tasid, 'editid' => $c->id,
        ]);
        $methodLabel = $methodLabels[$c->consultationtype] ?? $c->consultationtype;
        $age = $now - $c->consultationdate;
        $rowStyle = '';
        if ($age > 365 * 24 * 60 * 60) {
            $rowStyle = 'background: #fff5f5;';
        }
        $feedbackShort = mb_strlen($c->feedback) > 80 ? mb_substr($c->feedback, 0, 80) . '...' : $c->feedback;
        if (!empty($c->evidencedocument)) {
            $evidenceUrl = moodle_url::make_pluginfile_url(
                $context->id, 'local_rtocompliance', 'consultation_evidence', $c->id, '/', $c->evidencedocument
            );
            $evidenceDisplay = '<a href="' . $evidenceUrl->out(false) . '" target="_blank">' . s($c->evidencedocument) . '</a>';
        } else {
            $evidenceDisplay = '<span style="color:#999;">None</span>';
        }

        echo '<tr style="' . $rowStyle . '">';
        echo '<td style="white-space:nowrap;">' . userdate($c->consultationdate, '%d %b %Y') . '</td>';
        echo '<td>' . s($c->participantorg) . '</td>';
        echo '<td>' . s($c->participantname) . '<br><small style="color:#888;">' . s($c->participantrole) . '</small></td>';
        echo '<td><small>' . s($methodLabel) . '</small></td>';
        echo '<td><small>' . s($feedbackShort) . '</small></td>';
        echo '<td><small>' . $evidenceDisplay . '</small></td>';
        echo '<td style="white-space:nowrap;">'
            . '<a href="' . $editUrl->out(false) . '" class="btn btn-sm btn-outline-secondary" style="margin-right:4px;">Edit</a>'
            . '<a href="' . $deleteUrl->out(false) . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Delete this consultation record?\');">Delete</a>'
            . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';

    echo html_writer::start_div('info-card', ['style' => 'margin-bottom: 24px;']);
    echo html_writer::tag('h4', 'Auto-Generated TAS Narrative', ['style' => 'margin: 0 0 8px 0;']);
    echo html_writer::tag('p', 'This text is automatically written into the TAS "Industry Consultation Evidence" field from your consultation records above.',
        ['style' => 'color: #888; font-size: 13px; margin: 0 0 8px 0;']);
    $narrative = local_rtocompliance_generate_consultation_narrative($tas, $consultations);
    echo html_writer::tag('pre', s($narrative), ['style' => 'white-space: pre-wrap; background: #f8f9fa; border: 1px solid #eee; padding: 12px; border-radius: 8px; font-size: 13px; max-height: 300px; overflow-y: auto;']);
    echo html_writer::end_div();
}

if ($form) {
    echo html_writer::tag('h4', $editid ? 'Edit Consultation Record' : 'Add New Consultation Record', ['style' => 'margin-bottom: 8px;']);
    $form->display();
} else {
    $addurl = new moodle_url('/local/rtocompliance/tas_consultation.php', ['tasid' => $tasid, 'addnew' => 1]);
    echo html_writer::tag('a', 'Log New Consultation Record', ['href' => $addurl->out(false), 'class' => 'btn btn-primary', 'style' => 'margin-bottom: 24px; display: inline-block;']);
}

// rtocAppendDropdown is now registered via $PAGE->requires->js_init_code() above
// to ensure it runs correctly under Moodle 4.x Content-Security-Policy nonce enforcement.

// FIX-RTO-TESTER-FEEDBACK-MAY1 #6 (v4.2.42): wire the three "AI Generate"
// buttons on the industry consultation form.  Each button reads its
// data-context + data-target attributes, gathers the relevant seed fields
// (participant info, ticked categories, the other free-text answers above
// it) and posts to ajax.php?action=ai_draft_text.
echo html_writer::script('
(function () {
    var ajax = "' . $CFG->wwwroot . '/local/rtocompliance/ajax.php";
    var buttons = document.querySelectorAll("#rtoc-ai-consult-feedback, #rtoc-ai-consult-training, #rtoc-ai-consult-assessment");
    if (!buttons.length) return;

    function val(id)   { var el = document.getElementById(id); return el ? (el.value || "") : ""; }
    function v(name)   { var el = document.querySelector("[name=" + JSON.stringify(name) + "]"); return el ? (el.value || "") : ""; }
    function selText(name) {
        var el = document.querySelector("[name=" + JSON.stringify(name) + "]");
        if (!el || el.tagName !== "SELECT") return "";
        var o = el.options[el.selectedIndex];
        return o ? o.text : "";
    }
    function gridChecked(gridId) {
        var labels = [];
        document.querySelectorAll("#" + gridId + " input[type=checkbox]:checked").forEach(function (cb) {
            var lbl = cb.parentElement && cb.parentElement.textContent;
            if (lbl) labels.push(lbl.trim());
        });
        return labels.join("; ");
    }

    buttons.forEach(function (btn) {
        btn.addEventListener("click", function () {
            var targetId = btn.getAttribute("data-target");
            var ctx      = btn.getAttribute("data-context");
            var statusEl = document.getElementById(btn.id + "-status");
            var target   = document.getElementById(targetId);
            if (!target) return;

            if (target.value && target.value.length > 30) {
                if (!confirm("This will replace the existing text.  Continue?")) return;
            }
            btn.disabled = true;
            statusEl.textContent = "Generating...";
            statusEl.classList.remove("is-error");

            var fd = new FormData();
            fd.append("action", "ai_draft_text");
            fd.append("contexttype", ctx);
            fd.append("sesskey", M.cfg.sesskey);
            fd.append("seed[participantname]",    v("participantname"));
            fd.append("seed[participantorg]",     v("participantorg"));
            fd.append("seed[participantrole]",    v("participantrole"));
            fd.append("seed[consultationmethod]", selText("consultationmethod"));

            if (ctx === "consult_feedback") {
                fd.append("seed[categories]", gridChecked("rtoc-feedback-grid"));
            } else if (ctx === "consult_impact_training") {
                fd.append("seed[feedback]",   val("id_feedback"));
                fd.append("seed[categories]", gridChecked("rtoc-training-grid"));
            } else if (ctx === "consult_impact_assessment") {
                fd.append("seed[feedback]",   val("id_feedback"));
                fd.append("seed[categories]", gridChecked("rtoc-assessment-grid"));
            }

            fetch(ajax, { method: "POST", body: fd, credentials: "same-origin" })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    btn.disabled = false;
                    if (j && j.success && j.text) {
                        target.value = j.text;
                        target.dispatchEvent(new Event("input", { bubbles: true }));
                        statusEl.textContent = "Draft inserted - please review and edit before saving.";
                    } else {
                        statusEl.textContent = "Error: " + ((j && j.error) || "AI request failed");
                        statusEl.classList.add("is-error");
                    }
                })
                .catch(function (e) {
                    btn.disabled = false;
                    statusEl.textContent = "Network error: " + e.message;
                    statusEl.classList.add("is-error");
                });
        });
    });
})();
');

echo html_writer::end_div();
echo $OUTPUT->footer();
