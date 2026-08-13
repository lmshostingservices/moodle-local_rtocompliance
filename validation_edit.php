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

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\validation_form;

admin_externalpage_setup('local_rtocompliance_validation');
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/validation_edit.php', ['id' => $id]));

$validation = null;
if ($id) {
    $validation = $DB->get_record('local_rtocompliance_validations', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit Validation Event');
    $PAGE->navbar->add(get_string('validation', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/validation.php'));
    $PAGE->navbar->add('Edit Validation');
} else {
    $PAGE->set_title('New Validation Event');
    $PAGE->navbar->add(get_string('validation', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/validation.php'));
    $PAGE->navbar->add('New Validation');
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_validations', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/validation.php'),
        get_string('validation_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new validation_form(null, ['validation' => $validation]);

// BUG-VALIDATION-METHODS-PERSIST: Pre-populate the methodology and risk-factor
// checkboxes from the saved record so previously selected items stay ticked when
// the user re-opens an existing validation event.  The DB column stores the
// selections as JSON ({"keys":[...],"notes":"..."}); legacy rows that pre-date
// this format are stored as a comma-separated keys line followed by an optional
// notes paragraph and are decoded with a fallback parser below.
$methodologykeys = ['tool_review', 'evidence_review', 'judgement_review', 'mapping_check',
                    'industry_feedback', 'moderator_review', 'compliance_check', 'rpl_review',
                    'observation_review', 'benchmarking'];
$riskfactorkeys  = ['new_product', 'new_trainer', 'high_enrolments', 'high_complaints',
                    'low_completion', 'regulatory_focus', 'industry_change', 'external_issues',
                    'long_gap', 'assessment_issues', 'student_feedback', 'employer_feedback'];

/**
 * Decode a stored selection field into ['keys' => [...], 'notes' => '...'].
 * Supports the new JSON format and the legacy "key1, key2\nFreeform notes" format.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
$rtoc_decode_selection = function ($raw, $validkeys) {
    $result = ['keys' => [], 'notes' => ''];
    if (empty($raw)) {
        return $result;
    }
    $raw = (string) $raw;
    $trimmed = ltrim($raw);
    if ($trimmed !== '' && $trimmed[0] === '{') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (!empty($decoded['keys']) && is_array($decoded['keys'])) {
                foreach ($decoded['keys'] as $k) {
                    if (in_array($k, $validkeys, true)) {
                        $result['keys'][] = $k;
                    }
                }
            }
            $result['notes'] = isset($decoded['notes']) ? (string) $decoded['notes'] : '';
            return $result;
        }
    }
    // Legacy parser: first line may contain comma-separated keys; remaining lines = notes.
    $lines = preg_split('/\r?\n/', $raw, 2);
    $firstline = trim($lines[0] ?? '');
    $notes     = isset($lines[1]) ? trim($lines[1]) : '';
    $candidates = array_map('trim', explode(',', $firstline));
    $foundkeys = [];
    $leftover  = [];
    foreach ($candidates as $cand) {
        if ($cand === '') { continue; }
        if (in_array($cand, $validkeys, true)) {
            $foundkeys[] = $cand;
        } else {
            $leftover[] = $cand;
        }
    }
    $result['keys'] = $foundkeys;
    if (!empty($leftover)) {
        $notes = implode(', ', $leftover) . ($notes !== '' ? "\n" . $notes : '');
    }
    $result['notes'] = $notes;
    return $result;
};

if ($validation) {
    $methodsel = $rtoc_decode_selection($validation->methodologies ?? '', $methodologykeys);
    $risksel   = $rtoc_decode_selection($validation->riskfactors  ?? '', $riskfactorkeys);
    foreach ($methodologykeys as $k) {
        $fname = "method_{$k}";
        $validation->$fname = in_array($k, $methodsel['keys'], true) ? $k : '0';
    }
    foreach ($riskfactorkeys as $k) {
        $fname = "riskfactor_{$k}";
        $validation->$fname = in_array($k, $risksel['keys'], true) ? $k : '0';
    }
    $validation->methodologies = $methodsel['notes'];
    $validation->riskfactors   = $risksel['notes'];
    $form->set_data($validation);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/validation.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->reference = $data->reference;
    $record->productcode = $data->productcode;
    $record->productname = $data->productname;
    $record->unitcodes = $data->unitcodes ?? '';
    $record->validationtype = $data->validationtype;
    $record->risklevel = $data->risklevel;
    
    // BUG-VALIDATION-METHODS-PERSIST: Store risk factors as JSON
    // ({"keys":[...],"notes":"..."}) so the LOAD path can deterministically
    // separate ticked checkboxes from the additional notes textarea.  This
    // prevents the previous-format pollution where each save was concatenating
    // checkbox keys onto the textarea content, eventually producing lines like
    // "new_trainer, high_enrolments\nnew_trainer, high_enrolments\n..." after
    // multiple edits.  $riskfactorkeys is defined above (LOAD section).
    $selectedfactors = [];
    foreach ($riskfactorkeys as $key) {
        $fieldname = "riskfactor_{$key}";
        if (!empty($data->$fieldname) && $data->$fieldname !== '0') {
            $selectedfactors[] = $key;
        }
    }
    $record->riskfactors = json_encode([
        'keys'  => $selectedfactors,
        'notes' => trim((string) ($data->riskfactors ?? '')),
    ]);
    $record->scheduleddate = $data->scheduleddate;
    $record->actualdate = $data->actualdate ?? null;
    $record->status = $data->status;
    
    // FIX-LEADVALIDATOR-PERSIST: save the raw dropdown value so the select
    // pre-populates correctly when the record is re-opened for editing.
    // The column was changed to VARCHAR(50) in savepoint 2026050700148 to
    // support both numeric validator IDs and 'trainer_N' composite keys.
    $record->leadvalidatorid = $data->leadvalidatorid ?? '';
    if (!empty($data->leadvalidatorid)) {
        global $DB;
        if (strpos($data->leadvalidatorid, 'trainer_') === 0) {
            $trainerId = str_replace('trainer_', '', $data->leadvalidatorid);
            $trainer = $DB->get_record('local_rtocompliance_trainers', ['id' => $trainerId], 'fullname');
            $record->leadvalidator = $trainer ? $trainer->fullname : '';
        } else {
            $validator = $DB->get_record('local_rtocompliance_validators', ['id' => $data->leadvalidatorid], 'fullname');
            $record->leadvalidator = $validator ? $validator->fullname : '';
        }
    } else {
        $record->leadvalidator = $data->leadvalidator ?? '';
    }
    
    $panelNames = [];
    if (!empty($data->panelmemberids)) {
        foreach ($data->panelmemberids as $memberId) {
            if (strpos($memberId, 'trainer_') === 0) {
                $trainerId = str_replace('trainer_', '', $memberId);
                $trainer = $DB->get_record('local_rtocompliance_trainers', ['id' => $trainerId], 'fullname');
                if ($trainer) {
                    $panelNames[] = $trainer->fullname;
                }
            } else {
                $validator = $DB->get_record('local_rtocompliance_validators', ['id' => $memberId], 'fullname');
                if ($validator) {
                    $panelNames[] = $validator->fullname;
                }
            }
        }
    }
    if (!empty($data->panelmembers)) {
        $panelNames[] = $data->panelmembers;
    }
    $record->panelmembers = implode("\n", $panelNames);
    
    // BUG-VALIDATION-METHODS-PERSIST: Store methodologies as JSON — same
    // rationale as risk factors above.  $methodologykeys defined in LOAD section.
    $selectedmethods = [];
    foreach ($methodologykeys as $key) {
        $fieldname = "method_{$key}";
        if (!empty($data->$fieldname) && $data->$fieldname !== '0') {
            $selectedmethods[] = $key;
        }
    }
    $record->methodologies = json_encode([
        'keys'  => $selectedmethods,
        'notes' => trim((string) ($data->methodologies ?? '')),
    ]);
    $record->samplesize = $data->samplesize ?? 0;
    $record->samplingmethod = $data->samplingmethod ?? '';
    $record->findingscount = $data->findingscount ?? 0;
    $record->findings = $data->findings ?? '';
    $record->reportdocument = $data->reportdocument ?? '';
    $record->adclinked = $data->adclinked;
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if ($id > 0) {
        $record->id = $id;
        $DB->update_record('local_rtocompliance_validations', $record);
        $message = get_string('validation_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $DB->insert_record('local_rtocompliance_validations', $record);
        $message = get_string('validation_created', 'local_rtocompliance');
    }

    redirect(
        new moodle_url('/local/rtocompliance/validation.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// API credentials — same resolution chain as other AI-enabled pages.
$_rtoc_apikey  = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
    : (get_config('local_rtocompliance', 'apikey') ?: '');
$_rtoc_apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? 'Edit Validation' : 'New Validation', get_string('validation', 'local_rtocompliance'), '/local/rtocompliance/validation.php', 'validation');
echo $OUTPUT->heading($id ? 'Edit Validation Event' : 'New Validation Event');

$form->display();

// AI Suggest button — injected after the form renders so DOM is ready.
echo '<div id="rtoc-val-ai-config"'
    . ' data-api-key="' . s($_rtoc_apikey) . '"'
    . ' data-api-base="' . s($_rtoc_apibase) . '"'
    . ' style="display:none;"></div>';
?>
<script>
(function () {
    var cfg = document.getElementById('rtoc-val-ai-config');
    if (!cfg) return;
    var API_KEY  = cfg.getAttribute('data-api-key')  || '';
    var API_BASE = cfg.getAttribute('data-api-base') || 'https://lms-labs.com';

    // Wait for Moodle's own JS to finish initialising the form.
    document.addEventListener('DOMContentLoaded', function () { injectBtn(); });
    if (document.readyState === 'loading') {
        // DOMContentLoaded not yet fired — listener above covers us.
    } else {
        injectBtn();
    }

    function injectBtn() {
        var ta = document.getElementById('id_methodologies');
        if (!ta) return; // form not on page

        // Wrapper so button + status sit below the textarea.
        var wrap = document.createElement('div');
        wrap.id = 'rtoc-method-ai-wrap';
        wrap.style.cssText = 'display:flex;align-items:center;gap:0.6rem;margin-top:0.4rem;flex-wrap:wrap;';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '\u26A1 AI Suggest';
        btn.className = 'btn btn-sm btn-outline-secondary';
        btn.style.cssText = 'font-size:0.8rem;';

        var cost = document.createElement('span');
        cost.style.cssText = 'font-size:0.78rem;color:#9ca3af;';
        cost.textContent = '5 credits (5\u00a2)';

        var status = document.createElement('span');
        status.id = 'rtoc-method-ai-status';
        status.style.cssText = 'font-size:0.82rem;color:#6b7280;';

        wrap.appendChild(btn);
        wrap.appendChild(cost);
        wrap.appendChild(status);
        ta.parentNode.insertBefore(wrap, ta.nextSibling);

        btn.addEventListener('click', function () {
            // Gather checked methodology checkboxes.
            // FIX-METHODOLOGY-CHECKBOX: Use hardcoded element IDs matching the exact Moodle
            // rendering pattern (id_methodologiesgroup_method_<code>) for reliable detection
            // across all Moodle versions, instead of name-attribute selectors which can vary.
            var methodKeys = ['tool_review', 'evidence_review', 'judgement_review', 'mapping_check',
                              'industry_feedback', 'moderator_review', 'compliance_check', 'rpl_review',
                              'observation_review', 'benchmarking'];
            var checked = [];
            methodKeys.forEach(function (key) {
                var cb = document.getElementById('id_methodologiesgroup_method_' + key);
                if (cb && cb.checked) {
                    checked.push(key);
                }
            });
            // Fallback: also scan by name attribute in case theme uses different ID pattern.
            if (checked.length === 0) {
                var fallbackCbs = document.querySelectorAll(
                    'input[type="checkbox"][name^="methodologiesgroup"], input[type="checkbox"][name*="method_"]'
                );
                fallbackCbs.forEach(function (cb) {
                    if (cb.checked && cb.value && cb.value !== '0' && cb.value !== '1') {
                        checked.push(cb.value);
                    }
                });
            }
            if (checked.length === 0) {
                status.textContent = 'Tick at least one methodology above first.';
                status.style.color = '#dc2626';
                return;
            }

            var productname = (document.getElementById('id_productname') || {}).value || '';
            var productcode = (document.getElementById('id_productcode') || {}).value || '';

            btn.disabled = true;
            status.style.color = '#6b7280';
            status.textContent = 'Generating\u2026';

            fetch(API_BASE + '/api/rto/ai-methodology-suggest', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-API-Key': API_KEY
                },
                body: JSON.stringify({
                    methods: checked,
                    productname: productname,
                    productcode: productcode
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false;
                if (d.success && d.text) {
                    ta.value = d.text;
                    status.style.color = '#16a34a';
                    status.textContent = 'Done — review and edit before saving. ('
                        + d.creditsRemaining + ' credits remaining)';
                } else {
                    status.style.color = '#dc2626';
                    status.textContent = 'Error: ' + (d.error || 'Unknown error');
                }
            })
            .catch(function (err) {
                btn.disabled = false;
                status.style.color = '#dc2626';
                status.textContent = 'Network error: ' + err.message;
            });
        });
    }
})();
</script>
<?php
echo $OUTPUT->footer();
