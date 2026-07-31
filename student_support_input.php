<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_student_support_input');
$PAGE->set_url('/local/rtocompliance/student_support_input.php');
$PAGE->set_title('Trainer Support Input');
$PAGE->set_heading('Trainer Support Input');

// API credentials — same resolution chain as tas_edit.php.
$_rtoc_apikey  = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
    : (get_config('local_rtocompliance', 'apikey') ?: '');
$_rtoc_apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class("path-local-rtocompliance");

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Trainer Support Input', null, null, 'student_support');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Trainer Support Input (Standards 2.3 & 2.4)');
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Per-student support record');
echo html_writer::tag('p', '
    Use this form to capture an individual student\'s support, adjustments, referrals, interventions,
    diversity considerations and wellbeing notes. Records are saved against the student name and form
    the per-student evidence trail for Standards 2.3 (Training Support) and 2.4 (Reasonable Adjustment).
    Use the <strong>Auto Fill (AI)</strong> button to draft compliance-aligned text from the LLN level
    and risk dropdowns — review and edit before saving.
');
echo html_writer::end_div();

// =====================================================================
// FORM
// =====================================================================
echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'New / Update Student Support Record');

echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:0.75rem;">';
echo '<div><label for="studentName" style="display:block;font-weight:600;margin-bottom:0.25rem;">Student name</label>';
echo '<input type="text" id="studentName" placeholder="e.g. Jane Smith" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;"></div>';

echo '<div><label for="llnLevel" style="display:block;font-weight:600;margin-bottom:0.25rem;">LLN level (ACSF)</label>';
echo '<select id="llnLevel" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;">';
echo '<option value="">Not assessed</option>';
echo '<option value="Below ACSF 3">Below ACSF 3</option>';
echo '<option value="ACSF 3">ACSF 3 (course level)</option>';
echo '<option value="Above ACSF 3">Above ACSF 3</option>';
echo '</select></div>';

echo '<div><label for="risk" style="display:block;font-weight:600;margin-bottom:0.25rem;">Risk level</label>';
echo '<select id="risk" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;">';
echo '<option value="Low">Low</option>';
echo '<option value="Medium">Medium</option>';
echo '<option value="High">High</option>';
echo '</select></div>';
echo '</div>';

echo '<div style="margin-top:1rem;" id="rtocSupportInputConfig"'
    . ' data-api-key="' . s($_rtoc_apikey) . '"'
    . ' data-api-base="' . s($_rtoc_apibase) . '">';
echo '<button type="button" id="autoFillBtn" class="btn btn-primary btn-sm" style="margin-right:0.5rem;">&#9889; Auto Fill (AI)</button>';
echo '<button type="button" id="clearBtn" class="btn btn-outline-secondary btn-sm">Clear all fields</button>';
echo '<span style="margin-left:0.75rem;font-size:0.82rem;color:#9ca3af;">50 credits (&frac12;&cent;)</span>';
echo '<span id="autofillStatus" style="margin-left:0.75rem;font-size:0.88rem;color:#6b7280;"></span>';
echo '</div>';

// Textareas — populated by autoFillSupport().
$fields = [
    'lln'          => ['LLN observations & support (Standard 2.3)',           'Reading / writing / numeracy / oral concerns observed; support provided.'],
    'adjustments'  => ['Reasonable adjustments made (Standard 2.4)',           'Specific adjustments such as extended time, alternative format, assistive tech.'],
    'referrals'    => ['Support service referrals',                            'Internal academic support, wellbeing services, external counselling, LLN specialists.'],
    'interventions'=> ['Intervention strategies (at-risk students)',           'Increased trainer contact, progress monitoring, tailored sessions.'],
    'diversity'    => ['Diversity & inclusion considerations (Standard 2.5)',  'Cultural safety adjustments, CALD considerations, accessibility.'],
    'wellbeing'    => ['Wellbeing notes (Standard 2.6)',                       'Wellbeing plans, flexible study arrangements, counselling referrals.'],
];

echo '<div style="margin-top:1.25rem;display:grid;gap:1rem;">';
foreach ($fields as $key => $info) {
    echo '<div>';
    echo '<label for="ta_' . s($key) . '" style="display:block;font-weight:600;margin-bottom:0.25rem;">' . s($info[0]) . '</label>';
    echo '<textarea id="ta_' . s($key) . '" rows="3" placeholder="' . s($info[1]) . '" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-family:inherit;line-height:1.5;"></textarea>';
    echo '</div>';
}
echo '</div>';

echo '<div style="margin-top:1.25rem;">';
echo '<button type="button" id="saveRecordBtn" class="btn btn-primary">Save Support Record</button>';
echo '<span id="saveStatus" style="margin-left:0.75rem;font-size:0.9rem;"></span>';
echo '</div>';

echo html_writer::end_div(); // info-card

// =====================================================================
// SAVED RECORDS TABLE
// =====================================================================
echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'Saved Support Records (this browser)');
echo html_writer::tag('p', 'Records below are saved locally in this browser and form the per-student evidence trail. For permanent storage, copy text into the student profile in the Student Records register.', ['style' => 'font-size:0.88rem;color:#6b7280;']);
echo html_writer::div('', '', ['id' => 'savedRecordsTable']);
echo html_writer::end_div();

// Related.
echo html_writer::start_div('', ['style' => 'margin-top:2rem;padding:1.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;']);
echo html_writer::tag('h4', 'Related pages', ['style' => 'margin:0 0 0.75rem;color:#0369a1;']);
echo '<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">';
echo '<a href="' . (new moodle_url('/local/rtocompliance/student_support.php'))->out() . '" class="btn btn-outline-primary btn-sm">Student Support</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/students.php'))->out() . '" class="btn btn-outline-primary btn-sm">Student Records</a>';
echo '</div>';
echo html_writer::end_div();

echo html_writer::end_div(); // compliance-container
?>
<script>
(function () {
    'use strict';

    var STORAGE_KEY = 'rto_support_records_v1';

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }
    function nl2br(s) { return escapeHtml(s).replace(/\n/g, '<br>'); }

    function loadRecords() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || []; }
        catch (e) { return []; }
    }
    function saveRecords(arr) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(arr)); } catch (e) {}
    }

    // ---------------------------------------------------------------
    // AI Auto-Fill — calls /api/rto/ai-support-autofill (50 credits).
    // Generates ASQA-compliant draft text for all 6 support fields.
    // Trainer reviews and edits before saving.
    // ---------------------------------------------------------------
    var _rtocCfg = document.getElementById('rtocSupportInputConfig');
    var API_KEY  = _rtocCfg ? (_rtocCfg.getAttribute('data-api-key')  || '') : '';
    var API_BASE = _rtocCfg ? (_rtocCfg.getAttribute('data-api-base') || 'https://lms-labs.com') : 'https://lms-labs.com';

    function stripMd(s) {
        return String(s)
            .replace(/#{1,6}\s*/g, '')
            .replace(/\*{1,3}([^*]+)\*{1,3}/g, '$1')
            .replace(/_{1,3}([^_]+)_{1,3}/g, '$1')
            .replace(/`{1,3}[^`]*`{1,3}/g, '')
            .replace(/^[-*+]\s+/gm, '')
            .replace(/^\d+\.\s+/gm, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function autoFillSupport() {
        var student  = (document.getElementById('studentName').value || '').trim();
        var llnLevel = document.getElementById('llnLevel').value;
        var risk     = document.getElementById('risk').value;

        if (!student) {
            var st = document.getElementById('autofillStatus');
            st.textContent = 'Enter a student name before using Auto Fill.';
            st.style.color = '#b91c1c';
            setTimeout(function () { st.textContent = ''; }, 4000);
            return;
        }

        if (!API_KEY) {
            var st = document.getElementById('autofillStatus');
            st.textContent = 'API key not configured — check Plugin Settings.';
            st.style.color = '#b91c1c';
            setTimeout(function () { st.textContent = ''; }, 5000);
            return;
        }

        var btn    = document.getElementById('autoFillBtn');
        var status = document.getElementById('autofillStatus');
        btn.disabled    = true;
        btn.textContent = 'Generating\u2026';
        status.textContent = 'Calling AI (50 credits)\u2026';
        status.style.color  = '#6b7280';

        fetch(API_BASE + '/api/rto/ai-support-autofill', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
            body: JSON.stringify({
                apiKey:      API_KEY,
                studentName: student,
                llnLevel:    llnLevel,
                riskLevel:   risk
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled    = false;
            btn.textContent = '\u26A1 Auto Fill (AI)';
            if (!data.success) {
                status.textContent = data.error || 'Auto-fill failed. Please try again.';
                status.style.color  = '#b91c1c';
                setTimeout(function () { status.textContent = ''; }, 8000);
                return;
            }
            var f = data.fields || {};
            if (f.lln)           document.getElementById('ta_lln').value           = stripMd(f.lln);
            if (f.adjustments)   document.getElementById('ta_adjustments').value   = stripMd(f.adjustments);
            if (f.referrals)     document.getElementById('ta_referrals').value     = stripMd(f.referrals);
            if (f.interventions) document.getElementById('ta_interventions').value = stripMd(f.interventions);
            if (f.diversity)     document.getElementById('ta_diversity').value     = stripMd(f.diversity);
            if (f.wellbeing)     document.getElementById('ta_wellbeing').value     = stripMd(f.wellbeing);

            var credMsg = '';
            if (data.creditsRemaining !== undefined && data.creditsRemaining !== -1) {
                credMsg = ' \u2022 ' + data.creditsRemaining + ' credits remaining';
            } else if (data.creditsRemaining === -1) {
                credMsg = ' \u2022 Unlimited credits';
            }
            status.textContent = '50 credits used' + credMsg + ' \u2014 review and edit before saving.';
            status.style.color  = '#166534';
            setTimeout(function () { status.textContent = ''; }, 8000);
        })
        .catch(function () {
            btn.disabled    = false;
            btn.textContent = '\u26A1 Auto Fill (AI)';
            status.textContent = 'Connection error. Check your internet connection and try again.';
            status.style.color  = '#b91c1c';
            setTimeout(function () { status.textContent = ''; }, 6000);
        });
    }

    function clearForm() {
        ['studentName','ta_lln','ta_adjustments','ta_referrals','ta_interventions','ta_diversity','ta_wellbeing']
            .forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
        document.getElementById('llnLevel').value = '';
        document.getElementById('risk').value = 'Low';
    }

    function saveRecord() {
        var name = (document.getElementById('studentName').value || '').trim();
        if (!name) {
            var st = document.getElementById('saveStatus');
            st.textContent = 'Student name is required.';
            st.style.color = '#b91c1c';
            return;
        }
        var vals = {
            student:       name,
            llnLevel:      document.getElementById('llnLevel').value,
            risk:          document.getElementById('risk').value,
            lln:           document.getElementById('ta_lln').value,
            adjustments:   document.getElementById('ta_adjustments').value,
            referrals:     document.getElementById('ta_referrals').value,
            interventions: document.getElementById('ta_interventions').value,
            diversity:     document.getElementById('ta_diversity').value,
            wellbeing:     document.getElementById('ta_wellbeing').value
        };
        var records = loadRecords();
        var msg;
        if (_editingId) {
            // Update existing record — preserve original id and date.
            records = records.map(function (r) {
                if (r.id !== _editingId) return r;
                return Object.assign({}, r, vals);
            });
            saveRecords(records);
            msg = '\u2713 Record updated for ' + name + '.';
            _editingId = null;
            resetSaveBtn();
        } else {
            var rec = Object.assign({ id: Date.now(), date: new Date().toLocaleDateString('en-AU', { day: '2-digit', month: 'short', year: 'numeric' }) }, vals);
            records.unshift(rec);
            saveRecords(records);
            msg = '\u2713 Record saved for ' + name + '.';
        }
        var st = document.getElementById('saveStatus');
        st.textContent = msg;
        st.style.color = '#166534';
        renderRecords();
        setTimeout(function () { st.textContent = ''; }, 5000);
    }

    // ---------------------------------------------------------------
    // Edit-mode state — set when loading a record back into the form.
    // ---------------------------------------------------------------
    var _editingId = null;

    function deleteRecord(id) {
        var records = loadRecords().filter(function (r) { return r.id !== id; });
        saveRecords(records);
        if (_editingId === id) { _editingId = null; resetSaveBtn(); clearForm(); }
        renderRecords();
    }
    window.rtoDeleteSupportRecord = deleteRecord;

    // ---------------------------------------------------------------
    // Edit record — load back into form; Save becomes Update.
    // ---------------------------------------------------------------
    function editRecord(id) {
        var records = loadRecords();
        var rec = null;
        for (var i = 0; i < records.length; i++) { if (records[i].id === id) { rec = records[i]; break; } }
        if (!rec) return;
        _editingId = id;
        document.getElementById('studentName').value      = rec.student    || '';
        document.getElementById('llnLevel').value         = rec.llnLevel   || '';
        document.getElementById('risk').value             = rec.risk       || 'Low';
        document.getElementById('ta_lln').value           = rec.lln        || '';
        document.getElementById('ta_adjustments').value   = rec.adjustments|| '';
        document.getElementById('ta_referrals').value     = rec.referrals  || '';
        document.getElementById('ta_interventions').value = rec.interventions || '';
        document.getElementById('ta_diversity').value     = rec.diversity  || '';
        document.getElementById('ta_wellbeing').value     = rec.wellbeing  || '';
        var btn = document.getElementById('saveRecordBtn');
        btn.textContent = 'Update Support Record';
        btn.className   = 'btn btn-warning';
        var st = document.getElementById('saveStatus');
        st.textContent = 'Editing existing record — click Update to save changes.';
        st.style.color  = '#92400e';
        document.getElementById('studentName').focus();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    window.rtoEditSupportRecord = editRecord;

    function resetSaveBtn() {
        var btn = document.getElementById('saveRecordBtn');
        btn.textContent = 'Save Support Record';
        btn.className   = 'btn btn-primary';
        var st = document.getElementById('saveStatus');
        st.textContent = '';
    }

    // ---------------------------------------------------------------
    // View record — modal with all 6 fields.
    // ---------------------------------------------------------------
    var FIELD_LABELS = {
        lln:           'LLN Observations & Support (Standard 2.3)',
        adjustments:   'Reasonable Adjustments (Standard 2.4)',
        referrals:     'Support Service Referrals',
        interventions: 'Intervention Strategies',
        diversity:     'Diversity & Inclusion Considerations (Standard 2.5)',
        wellbeing:     'Wellbeing Notes (Standard 2.6)'
    };

    function viewRecord(id) {
        var records = loadRecords();
        var rec = null;
        for (var i = 0; i < records.length; i++) { if (records[i].id === id) { rec = records[i]; break; } }
        if (!rec) return;
        var overlay = document.getElementById('rtoViewModal');
        var body = '';
        body += '<div style="display:grid;grid-template-columns:auto auto auto;gap:0.35rem 1.5rem;margin-bottom:1.25rem;font-size:0.92rem;">';
        body += '<span style="color:#6b7280;">Date</span><span style="color:#6b7280;">LLN Level</span><span style="color:#6b7280;">Risk</span>';
        body += '<strong>' + escapeHtml(rec.date) + '</strong><strong>' + escapeHtml(rec.llnLevel || '\u2014') + '</strong>';
        var riskCol = rec.risk === 'High' ? '#dc2626' : (rec.risk === 'Medium' ? '#d97706' : '#16a34a');
        body += '<strong style="color:' + riskCol + ';">' + escapeHtml(rec.risk || '\u2014') + '</strong>';
        body += '</div>';
        var fields = ['lln','adjustments','referrals','interventions','diversity','wellbeing'];
        fields.forEach(function (f) {
            var val = (rec[f] || '').trim();
            if (!val) return;
            body += '<div style="margin-bottom:1rem;">' +
                '<div style="font-weight:600;font-size:0.88rem;color:#374151;margin-bottom:0.3rem;text-transform:uppercase;letter-spacing:0.03em;">' + FIELD_LABELS[f] + '</div>' +
                '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:0.65rem 0.85rem;font-size:0.92rem;line-height:1.6;color:#1f2937;">' + nl2br(val) + '</div>' +
                '</div>';
        });
        overlay.querySelector('#rtoViewModalTitle').textContent = 'Support Record — ' + rec.student;
        overlay.querySelector('#rtoViewModalBody').innerHTML = body;
        overlay.querySelector('#rtoViewModalPdfBtn').setAttribute('data-rec-id', id);
        overlay.querySelector('#rtoViewModalEditBtn').setAttribute('data-rec-id', id);
        overlay.style.display = 'flex';
    }
    window.rtoViewSupportRecord = viewRecord;

    function closeViewModal() {
        var overlay = document.getElementById('rtoViewModal');
        if (overlay) overlay.style.display = 'none';
    }
    window.rtoCloseViewModal = closeViewModal;

    // ---------------------------------------------------------------
    // Download PDF — opens a print-ready window with formatted record.
    // ---------------------------------------------------------------
    function downloadPdf(id) {
        var records = loadRecords();
        var rec = null;
        for (var i = 0; i < records.length; i++) { if (records[i].id === id) { rec = records[i]; break; } }
        if (!rec) return;
        var fields = ['lln','adjustments','referrals','interventions','diversity','wellbeing'];
        var sections = '';
        fields.forEach(function (f) {
            var val = (rec[f] || '').trim();
            sections +=
                '<div style="margin-bottom:18px;page-break-inside:avoid;">' +
                '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#374151;margin-bottom:5px;border-bottom:1px solid #d1d5db;padding-bottom:3px;">' + FIELD_LABELS[f] + '</div>' +
                '<div style="font-size:11px;line-height:1.65;color:#111827;">' + (val ? val.replace(/\n/g, '<br>') : '<em style="color:#9ca3af;">Not recorded.</em>') + '</div>' +
                '</div>';
        });
        var riskBg = rec.risk === 'High' ? '#fef2f2' : (rec.risk === 'Medium' ? '#fffbeb' : '#f0fdf4');
        var riskFg = rec.risk === 'High' ? '#dc2626' : (rec.risk === 'Medium' ? '#d97706' : '#16a34a');
        var html =
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' +
            '<title>Student Support Record — ' + rec.student + '</title>' +
            '<style>*{box-sizing:border-box;}body{font-family:Arial,sans-serif;margin:0;padding:24px 32px;color:#111827;background:#fff;}' +
            '@media print{body{padding:16px 20px;}button{display:none!important;}@page{margin:15mm;}}' +
            '</style></head><body>' +
            '<div style="border-bottom:2px solid #1d4ed8;margin-bottom:16px;padding-bottom:10px;display:flex;justify-content:space-between;align-items:flex-end;">' +
            '<div><div style="font-size:18px;font-weight:700;color:#1d4ed8;">Student Support Record</div>' +
            '<div style="font-size:11px;color:#6b7280;margin-top:2px;">ASQA Standards 2.3, 2.4, 2.5 &amp; 2.6 — RTO Compliance</div></div>' +
            '<div style="font-size:10px;color:#6b7280;text-align:right;">Generated: ' + new Date().toLocaleDateString('en-AU', {day:'2-digit',month:'short',year:'numeric'}) + '</div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px;">' +
            '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px;"><div style="font-size:9px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Student Name</div><div style="font-size:13px;font-weight:700;">' + escapeHtml(rec.student) + '</div></div>' +
            '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px;"><div style="font-size:9px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:3px;">LLN Level (ACSF)</div><div style="font-size:13px;font-weight:600;">' + escapeHtml(rec.llnLevel || '\u2014') + '</div></div>' +
            '<div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px;background:' + riskBg + ';"><div style="font-size:9px;color:#6b7280;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Risk Level</div><div style="font-size:13px;font-weight:700;color:' + riskFg + ';">' + escapeHtml(rec.risk || '\u2014') + '</div></div>' +
            '</div>' +
            '<div style="font-size:9px;color:#6b7280;margin-bottom:14px;">Record Date: ' + escapeHtml(rec.date) + ' &nbsp;|&nbsp; Record ID: ' + rec.id + '</div>' +
            sections +
            '<div style="margin-top:28px;border-top:1px solid #e5e7eb;padding-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:20px;">' +
            '<div><div style="font-size:9px;color:#9ca3af;margin-bottom:18px;">Trainer Signature</div><div style="border-top:1px solid #374151;width:180px;margin-top:2px;"></div></div>' +
            '<div><div style="font-size:9px;color:#9ca3af;margin-bottom:18px;">Date</div><div style="border-top:1px solid #374151;width:120px;margin-top:2px;"></div></div>' +
            '</div>' +
            '<div style="margin-top:16px;font-size:8px;color:#9ca3af;text-align:center;">This record forms part of the per-student evidence trail for ASQA Standards 2.3–2.6. Retain securely in compliance with the Privacy Act 1988.</div>' +
            '<div style="margin-top:16px;text-align:center;">' +
            '<button onclick="window.print()" style="background:#1d4ed8;color:#fff;border:none;padding:8px 22px;border-radius:5px;font-size:12px;cursor:pointer;margin-right:8px;">Print / Save as PDF</button>' +
            '<button onclick="window.close()" style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;padding:8px 18px;border-radius:5px;font-size:12px;cursor:pointer;">Close</button>' +
            '</div></body></html>';
        var win = window.open('', '_blank', 'width=800,height=900,scrollbars=yes');
        if (win) { win.document.write(html); win.document.close(); }
    }
    window.rtoDownloadSupportPdf = downloadPdf;

    // ---------------------------------------------------------------
    // Render records table with View / Edit / PDF / Delete actions.
    // ---------------------------------------------------------------
    function renderRecords() {
        var el = document.getElementById('savedRecordsTable');
        if (!el) return;
        var records = loadRecords();
        if (records.length === 0) {
            el.innerHTML = '<p style="color:#6b7280;font-style:italic;margin:0;">No records saved yet.</p>';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;font-size:0.92rem;">' +
            '<thead><tr style="background:#f3f4f6;text-align:left;">' +
            '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Date</th>' +
            '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Student</th>' +
            '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">LLN</th>' +
            '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Risk</th>' +
            '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Summary</th>' +
            '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;min-width:190px;">Actions</th>' +
            '</tr></thead><tbody>';
        records.forEach(function (r) {
            var summary = (r.lln || '').substring(0, 100) + ((r.lln || '').length > 100 ? '\u2026' : '');
            var riskStyle = r.risk === 'High' ? 'color:#dc2626;font-weight:600;' : (r.risk === 'Medium' ? 'color:#d97706;font-weight:600;' : 'color:#16a34a;');
            html += '<tr style="border-bottom:1px solid #e5e7eb;vertical-align:top;">' +
                '<td style="padding:8px 10px;white-space:nowrap;">' + escapeHtml(r.date) + '</td>' +
                '<td style="padding:8px 10px;white-space:nowrap;font-weight:600;">' + escapeHtml(r.student) + '</td>' +
                '<td style="padding:8px 10px;white-space:nowrap;font-size:0.85rem;">' + escapeHtml(r.llnLevel || '\u2014') + '</td>' +
                '<td style="padding:8px 10px;white-space:nowrap;' + riskStyle + '">' + escapeHtml(r.risk || '\u2014') + '</td>' +
                '<td style="padding:8px 10px;color:#6b7280;font-size:0.88rem;">' + nl2br(summary) + '</td>' +
                '<td style="padding:8px 6px;white-space:nowrap;">' +
                '<button type="button" class="btn btn-outline-primary btn-sm" style="margin-right:3px;" onclick="rtoViewSupportRecord(' + r.id + ')" title="View full record">View</button>' +
                '<button type="button" class="btn btn-outline-secondary btn-sm" style="margin-right:3px;" onclick="rtoEditSupportRecord(' + r.id + ')" title="Load into form to edit">Edit</button>' +
                '<button type="button" class="btn btn-outline-success btn-sm" style="margin-right:3px;" onclick="rtoDownloadSupportPdf(' + r.id + ')" title="Download / print as PDF">PDF</button>' +
                '<button type="button" class="btn btn-outline-danger btn-sm" onclick="rtoDeleteSupportRecord(' + r.id + ')" title="Delete record">Delete</button>' +
                '</td></tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }

    // ---------------------------------------------------------------
    // View Modal — injected once into DOM.
    // ---------------------------------------------------------------
    function buildModal() {
        if (document.getElementById('rtoViewModal')) return;
        var m = document.createElement('div');
        m.id = 'rtoViewModal';
        m.style.cssText = 'display:none;position:fixed;inset:0;z-index:99999;align-items:center;justify-content:center;background:rgba(0,0,0,0.45);padding:16px;';
        m.innerHTML =
            '<div style="background:#fff;border-radius:10px;max-width:720px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.25);">' +
            '<div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">' +
            '<h3 id="rtoViewModalTitle" style="margin:0;font-size:1.05rem;font-weight:700;color:#111827;"></h3>' +
            '<div style="display:flex;gap:8px;align-items:center;">' +
            '<button type="button" id="rtoViewModalPdfBtn" class="btn btn-outline-success btn-sm" onclick="rtoDownloadSupportPdf(Number(this.getAttribute(\'data-rec-id\')))">Download PDF</button>' +
            '<button type="button" id="rtoViewModalEditBtn" class="btn btn-outline-secondary btn-sm" onclick="rtoCloseViewModal();rtoEditSupportRecord(Number(this.getAttribute(\'data-rec-id\')))">Edit</button>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="rtoCloseViewModal()">&times; Close</button>' +
            '</div></div>' +
            '<div id="rtoViewModalBody" style="overflow-y:auto;padding:20px;flex:1;"></div>' +
            '</div>';
        m.addEventListener('click', function (e) { if (e.target === m) closeViewModal(); });
        document.body.appendChild(m);
    }

    function init() {
        buildModal();
        document.getElementById('autoFillBtn').addEventListener('click', autoFillSupport);
        document.getElementById('clearBtn').addEventListener('click', function () { _editingId = null; resetSaveBtn(); clearForm(); });
        document.getElementById('saveRecordBtn').addEventListener('click', saveRecord);
        renderRecords();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else { init(); }
})();
</script>
<?php
echo $OUTPUT->footer();
