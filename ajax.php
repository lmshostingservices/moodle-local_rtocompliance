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

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
$PAGE->set_context(context_system::instance());
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

// Bug J fix: require_sesskey() prevents CSRF. Any page an admin visits could
// trigger arbitrary TGA API calls using their credentials without this check.
require_sesskey();

// FIX-MAY5-CURL-NOTFOUND (v4.4.45): Replace Moodle's \curl class with native
// PHP curl_init() to eliminate "Class 'curl' not found" errors on installations
// where lib/filelib.php has not been fully bootstrapped before this script runs.
// Falls back to file_get_contents() + stream_context on servers where ext-curl
// is unavailable (rare but possible on some shared hosting environments).
function rtocompliance_curl_post(string $url, string $body, array $headers, int $timeout = 60): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $errmsg   = $errno ? curl_error($ch) : '';
        curl_close($ch);
        return ['response' => ($response !== false ? $response : ''), 'httpcode' => $httpcode, 'error' => $errmsg];
    }
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    $httpcode = 200;
    if (!empty($http_response_header) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
        $httpcode = (int) $m[1];
    }
    return ['response' => ($response !== false ? $response : ''), 'httpcode' => ($response !== false ? $httpcode : 0), 'error' => ($response === false ? 'HTTP request failed' : '')];
}

function rtocompliance_curl_get(string $url, array $headers, int $timeout = 30): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $errmsg   = $errno ? curl_error($ch) : '';
        curl_close($ch);
        return ['response' => ($response !== false ? $response : ''), 'httpcode' => $httpcode, 'error' => $errmsg];
    }
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    $httpcode = 200;
    if (!empty($http_response_header) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
        $httpcode = (int) $m[1];
    }
    return ['response' => ($response !== false ? $response : ''), 'httpcode' => ($response !== false ? $httpcode : 0), 'error' => ($response === false ? 'HTTP request failed' : '')];
}

$action = required_param('action', PARAM_ALPHANUMEXT);

header('Content-Type: application/json');

if ($action === 'tga_qualification' || $action === 'tgaqualification') {
    $code = required_param('code', PARAM_TEXT);
    $code = strtoupper(trim($code));

    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
    }

    $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
    // If apiurl is misconfigured to point at the Moodle site itself, fall back to the correct API.
    if (empty($apiurl) || rtrim($apiurl, '/') === rtrim($CFG->wwwroot, '/')) {
        $apiurl = 'https://lms-labs.com';
    }
    $apiurl = rtrim($apiurl, '/');

    if (function_exists('local_aiconfig_get_apikey')) {
        $apikey = local_aiconfig_get_apikey();
    } else {
        $apikey = get_config('local_rtocompliance', 'apikey');
    }

    $url = $apiurl . '/api/tga/qualification/' . urlencode($code);

    // FIX-MAY5-CURL-NOTFOUND (v4.4.45): Use native curl helper (no Moodle \curl class dependency).
    $result   = rtocompliance_curl_get($url, ['X-API-Key: ' . ($apikey ?? ''), 'Content-Type: application/json'], 30);
    $response = $result['response'];
    $httpcode = $result['httpcode'];
    $error    = $result['error'];

    if ($error) {
        echo json_encode(['success' => false, 'error' => 'Network error: ' . $error]);
        exit;
    }

    if ($httpcode !== 200) {
        $data = json_decode($response, true);
        echo json_encode(['success' => false, 'error' => $data['message'] ?? 'TGA lookup failed (HTTP ' . $httpcode . ')']);
        exit;
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['qualification'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid response from TGA API']);
        exit;
    }

    $units = [];
    if (!empty($data['units'])) {
        foreach ($data['units'] as $unit) {
            $units[] = [
                'UnitCode'     => $unit['UnitCode'] ?? $unit['unitCode'] ?? '',
                'UnitTitle'    => $unit['UnitTitle'] ?? $unit['unitTitle'] ?? '',
                'CoreUnit'     => !empty($unit['CoreUnit']) || !empty($unit['coreUnit']),
                'NominalHours' => (int)($unit['NominalHours'] ?? $unit['nominalHours'] ?? 0),
            ];
        }
    }

    // Derive total nominal hours and AQF level to pass to the frontend.
    // NominalHours is the sum of per-unit training hours from TGA.
    // AQFLevel is inferred from the qualification title (e.g. "Certificate IV" → 4).
    $totalNominalHours = array_sum(array_column($units, 'NominalHours'));
    $qualTitle = $data['qualification']['Title'] ?? $data['qualification']['title'] ?? '';
    $aqfLevel = 0;
    $combined = strtolower($qualTitle);
    if      (preg_match('/cert(?:ificate)?\s+iv\b/i',      $combined)) $aqfLevel = 4;
    elseif  (preg_match('/cert(?:ificate)?\s+iii\b/i',     $combined)) $aqfLevel = 3;
    elseif  (preg_match('/cert(?:ificate)?\s+ii\b/i',      $combined)) $aqfLevel = 2;
    elseif  (preg_match('/cert(?:ificate)?\s+i\b/i',       $combined)) $aqfLevel = 1;
    elseif  (preg_match('/advanced\s+diploma/i',           $combined)) $aqfLevel = 6;
    elseif  (preg_match('/diploma/i',                      $combined)) $aqfLevel = 5;
    elseif  (preg_match('/graduate\s+cert(?:ificate)?/i',  $combined)) $aqfLevel = 8;
    elseif  (preg_match('/graduate\s+dipl(?:oma)?/i',      $combined)) $aqfLevel = 8;

    echo json_encode([
        'success' => true,
        'qualification' => [
            'Code'         => $data['qualification']['Code'] ?? $data['qualification']['code'] ?? $code,
            'Title'        => $qualTitle,
            'NominalHours' => $totalNominalHours,
            'AQFLevel'     => $aqfLevel,
        ],
        'units' => $units,
    ]);
    exit;
}

if ($action === 'generate_resolution') {
    $contexttype      = optional_param('context_type', 'complaint', PARAM_ALPHANUMEXT);
    $category         = optional_param('category', '', PARAM_TEXT);
    $subcategory      = optional_param('subcategory', '', PARAM_TEXT);
    $subject          = optional_param('subject', '', PARAM_TEXT);
    $description      = optional_param('description', '', PARAM_TEXT);
    $priority         = optional_param('priority', '', PARAM_TEXT);
    $status           = optional_param('status', '', PARAM_TEXT);
    $appealtype       = optional_param('appealtype', '', PARAM_TEXT);
    $groundsforappeal = optional_param('groundsforappeal', '', PARAM_TEXT);
    $originaldecision = optional_param('originaldecision', '', PARAM_TEXT);

    // BUG-14 FIX: PARAM_TEXT doesn't cap field length — an attacker (or runaway frontend)
    // can submit megabyte-scale strings. These are interpolated directly into the AI prompt,
    // causing enormous API payloads that may exceed provider limits, cause timeouts, or
    // consume expensive tokens disproportionate to the request. Cap all free-text inputs.
    $description      = substr($description, 0, 2000);
    $subject          = substr($subject, 0, 500);
    $groundsforappeal = substr($groundsforappeal, 0, 2000);
    $originaldecision = substr($originaldecision, 0, 1000);
    $category         = substr($category, 0, 200);
    $subcategory      = substr($subcategory, 0, 200);

    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
    }

    $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
    if (empty($apiurl) || rtrim($apiurl, '/') === rtrim($CFG->wwwroot, '/')) {
        $apiurl = 'https://lms-labs.com';
    }
    $apiurl = rtrim($apiurl, '/');

    $siteid = '';
    $apikey = '';

    if (function_exists('local_aiconfig_get_siteid')) {
        $siteid = trim(local_aiconfig_get_siteid('local_rtocompliance') ?? '');
    }
    if (empty($siteid)) {
        $siteid = trim(get_config('local_rtocompliance', 'siteid') ?? '');
    }
    if (function_exists('local_aiconfig_get_apikey')) {
        $apikey = trim(local_aiconfig_get_apikey('local_rtocompliance') ?? '');
    }
    if (empty($apikey)) {
        $apikey = trim(get_config('local_rtocompliance', 'apikey') ?? '');
    }

    if (empty($siteid) || empty($apikey)) {
        echo json_encode(['success' => false, 'error' => 'AI assist is not configured. Ask your administrator to set up local_aiconfig.']);
        exit;
    }

    $reference = optional_param('reference', '', PARAM_TEXT);

    if ($contexttype === 'grounds_for_appeal') {
        $prompt  = "You are helping an appellant draft their grounds for appeal to an Australian Registered Training Organisation (RTO).\n\n";
        $prompt .= "APPEAL DETAILS:\n";
        if (!empty($appealtype)) {
            $prompt .= "Appeal Type: $appealtype\n";
        }
        if (!empty($reference)) {
            $prompt .= "Reference: $reference\n";
        }
        if (!empty($originaldecision)) {
            $prompt .= "Original Decision Being Appealed: $originaldecision\n";
        }
        $prompt .= "\nPlease write professional grounds for appeal for this RTO appeal. ";
        $prompt .= "Include: a clear statement of what decision is being appealed, the specific reasons the appellant believes the decision was incorrect or unfair, ";
        $prompt .= "reference to relevant ASQA Standards for RTOs 2025 or RTO policies that support the appeal, and the outcome being sought. ";
        $prompt .= "Keep it clear and structured (150-250 words), suitable for a formal appeal process under ASQA Standards Clause 6.2 and natural justice principles. ";
        $prompt .= "Write specific, realistic grounds — no placeholders.";
    } else if ($contexttype === 'appeal') {
        $prompt  = "You are an RTO compliance officer drafting an appeal outcome reason for an Australian Registered Training Organisation.\n\n";
        $prompt .= "APPEAL DETAILS:\n";
        if (!empty($appealtype)) {
            $prompt .= "Appeal Type: $appealtype\n";
        }
        if (!empty($groundsforappeal)) {
            $prompt .= "Grounds for Appeal: $groundsforappeal\n";
        }
        if (!empty($originaldecision)) {
            $prompt .= "Original Decision: $originaldecision\n";
        }
        if (!empty($status)) {
            $prompt .= "Outcome: $status\n";
        }
        $prompt .= "\nPlease write a professional outcome reason for this RTO appeal. ";
        $prompt .= "Include: key findings from the appeal panel, analysis of the grounds for appeal, how the decision was reached, and the rationale for the outcome. ";
        $prompt .= "Keep it concise (150-250 words), professional, and consistent with ASQA Standards for RTOs 2025 and natural justice principles. ";
        $prompt .= "Write a realistic, specific outcome reason — no placeholders.";
    } else {
        $prompt  = "You are an RTO compliance officer drafting a complaint resolution for an Australian Registered Training Organisation.\n\n";
        $prompt .= "COMPLAINT DETAILS:\n";
        if (!empty($category)) {
            $prompt .= "Category: $category" . (!empty($subcategory) ? " / $subcategory" : "") . "\n";
        }
        if (!empty($subject)) {
            $prompt .= "Subject: $subject\n";
        }
        if (!empty($priority)) {
            $prompt .= "Priority: $priority\n";
        }
        if (!empty($status)) {
            $prompt .= "Current Status: $status\n";
        }
        if (!empty($description)) {
            $prompt .= "Description: $description\n";
        }
        $prompt .= "\nPlease write a professional complaint resolution statement. ";
        $prompt .= "Include: what was investigated, key findings, actions taken to resolve the complaint, the outcome, and any systemic improvements identified. ";
        $prompt .= "Keep it concise (150-250 words), professional, and compliant with ASQA Standards for RTOs 2025 Clause 6 requirements. ";
        $prompt .= "Write a realistic, specific resolution — no placeholders.";
    }

    // FIX-CREDIT-REPORT-COMPLAINT-AI (v4.5.96): generate_resolution now routes through
    // /api/rto/ai-suggest instead of /api/moodle/course-assistant/chat.
    // course-assistant/chat logs usage as 'course_assistant' which is filtered out by the
    // rto_% WHERE clause in /api/rto/credit-usage-history, making complaint/appeal AI
    // calls invisible in the AI Credit Report.  ai-suggest logs as 'rto_ai_draft' which
    // IS captured by the report.  The server-side TAS_AI_FIELD_CONFIGS now includes
    // 'complaint_resolution', 'appeal_resolution', and 'appeal_grounds' entries with
    // ASQA-compliant systemHints, so the contexttype selects the right config.
    // FIX-SESSION-WRITE-CLOSE: release session lock before long-running curl call.
    \core\session\manager::write_close();

    if ($contexttype === 'grounds_for_appeal') {
        $fieldKey = 'appeal_grounds';
        $kwParts  = [];
        if (!empty($appealtype))       { $kwParts[] = 'Appeal Type: '      . $appealtype; }
        if (!empty($reference))        { $kwParts[] = 'Reference: '        . $reference; }
        if (!empty($originaldecision)) { $kwParts[] = 'Original Decision: '. $originaldecision; }
    } else if ($contexttype === 'appeal') {
        $fieldKey = 'appeal_resolution';
        $kwParts  = [];
        if (!empty($appealtype))       { $kwParts[] = 'Appeal Type: '    . $appealtype; }
        if (!empty($groundsforappeal)) { $kwParts[] = 'Grounds: '        . $groundsforappeal; }
        if (!empty($originaldecision)) { $kwParts[] = 'Original Decision: ' . $originaldecision; }
        if (!empty($status))           { $kwParts[] = 'Outcome: '        . $status; }
    } else {
        $fieldKey = 'complaint_resolution';
        $kwParts  = [];
        if (!empty($category))    { $kwParts[] = 'Category: '  . $category . (!empty($subcategory) ? ' / ' . $subcategory : ''); }
        if (!empty($subject))     { $kwParts[] = 'Subject: '   . $subject; }
        if (!empty($priority))    { $kwParts[] = 'Priority: '  . $priority; }
        if (!empty($status))      { $kwParts[] = 'Status: '    . $status; }
        if (!empty($description)) { $kwParts[] = 'Description: '. $description; }
    }
    $keyword = implode("\n", $kwParts);

    $suggestPayload = json_encode([
        'apiKey'   => $apikey,
        'field'    => $fieldKey,
        'qualName' => '',
        'keyword'  => $keyword,
        'count'    => 1,
    ]);
    $sr = rtocompliance_curl_post($apiurl . '/api/rto/ai-suggest', $suggestPayload, ['Content-Type: application/json', 'Accept: application/json'], 60);
    if ($sr['error']) {
        echo json_encode(['success' => false, 'error' => 'Network error: ' . $sr['error']]);
        exit;
    }
    if ($sr['httpcode'] !== 200) {
        $rd = json_decode($sr['response'], true);
        echo json_encode(['success' => false, 'error' => ($rd['error'] ?: '') ?: 'AI request failed (HTTP ' . $sr['httpcode'] . ')']);
        exit;
    }
    $rd = json_decode($sr['response'], true);
    if (!empty($rd['suggestions']) && !empty($rd['suggestions'][0])) {
        echo json_encode(['success' => true, 'text' => trim($rd['suggestions'][0])]);
    } else {
        echo json_encode(['success' => false, 'error' => $rd['error'] ?? 'AI generation returned no content']);
    }
    exit;
}

// FIX-RTO-TESTER-FEEDBACK-MAY1 #5/6 (v4.2.42): generic AI text-draft endpoint
// used by:
//   - trainer_voccomp.php "AI Generate Description"      (context=voccomp_description)
//   - tas_consultation.php "AI Generate Feedback Summary" (context=consult_feedback)
//   - tas_consultation.php "AI Generate Training Impact"  (context=consult_impact_training)
//   - tas_consultation.php "AI Generate Assessment Impact"(context=consult_impact_assessment)
if ($action === 'ai_draft_text') {
    $contexttype = optional_param('contexttype', '', PARAM_ALPHANUMEXT);
    $seed        = optional_param_array('seed', [], PARAM_RAW);

    // Sanitise seed values (cap each to 1KB so prompts stay reasonable).
    $clean = [];
    foreach ($seed as $k => $v) {
        if (!is_string($v)) { continue; }
        $clean[clean_param($k, PARAM_ALPHANUMEXT)] = substr($v, 0, 1000);
    }

    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) { require_once($aiconfiglib); }

    $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
    if (empty($apiurl) || rtrim($apiurl, '/') === rtrim($CFG->wwwroot, '/')) {
        $apiurl = 'https://lms-labs.com';
    }
    $apiurl = rtrim($apiurl, '/');

    $siteid = ''; $apikey = '';
    if (function_exists('local_aiconfig_get_siteid')) {
        $siteid = trim(local_aiconfig_get_siteid('local_rtocompliance') ?? '');
    }
    if (empty($siteid)) { $siteid = trim(get_config('local_rtocompliance', 'siteid') ?? ''); }
    if (function_exists('local_aiconfig_get_apikey')) {
        $apikey = trim(local_aiconfig_get_apikey('local_rtocompliance') ?? '');
    }
    if (empty($apikey)) { $apikey = trim(get_config('local_rtocompliance', 'apikey') ?? ''); }

    if (empty($siteid) || empty($apikey)) {
        echo json_encode(['success' => false, 'error' => 'AI assist is not configured. Ask your administrator to set up local_aiconfig.']);
        exit;
    }

    // Build the prompt per contexttype.
    $prompt = '';
    if ($contexttype === 'voccomp_description') {
        $prompt  = "You are an Australian RTO compliance officer drafting a 'Description of Vocational Practice' for a trainer's vocational competency activity log (Standard 3.3(2) of the Standards for RTOs 2025).\n\n";
        $prompt .= "ACTIVITY DETAILS:\n";
        if (!empty($clean['activitytype']))  { $prompt .= "Activity Type: " . $clean['activitytype'] . "\n"; }
        if (!empty($clean['title']))         { $prompt .= "Title: " . $clean['title'] . "\n"; }
        if (!empty($clean['qualification'])) { $prompt .= "Related Qualification(s): " . $clean['qualification'] . "\n"; }
        if (!empty($clean['organisation']))  { $prompt .= "Organisation/Employer: " . $clean['organisation'] . "\n"; }
        if (!empty($clean['startdate']))     { $prompt .= "Start Date: " . $clean['startdate'] . "\n"; }
        if (!empty($clean['enddate']))       { $prompt .= "End Date: " . $clean['enddate'] . "\n"; }
        if (!empty($clean['totalhours']))    { $prompt .= "Total Hours: " . $clean['totalhours'] . "\n"; }
        $prompt .= "\nWrite a concise 3-5 sentence description of the vocational practice. ";
        $prompt .= "Explain what vocational skills were applied or maintained through this activity and how they directly relate to the units of competency in the qualification(s) being delivered. ";
        $prompt .= "Use Australian English and an objective professional tone (no first-person pronouns, no placeholders). 80-150 words.";
    } else if ($contexttype === 'consult_feedback') {
        $prompt  = "You are an Australian RTO compliance officer drafting the 'Key Feedback' summary section of an industry consultation record (ASQA Standards 2025 clauses 1.4 / 4.1).\n\n";
        $prompt .= "CONSULTATION DETAILS:\n";
        if (!empty($clean['participantname']))  { $prompt .= "Industry Representative: " . $clean['participantname'] . "\n"; }
        if (!empty($clean['participantorg']))   { $prompt .= "Organisation: " . $clean['participantorg'] . "\n"; }
        if (!empty($clean['participantrole']))  { $prompt .= "Role: " . $clean['participantrole'] . "\n"; }
        if (!empty($clean['consultationmethod'])) { $prompt .= "Consultation Method: " . $clean['consultationmethod'] . "\n"; }
        if (!empty($clean['qualification']))    { $prompt .= "Qualification: " . $clean['qualification'] . "\n"; }
        if (!empty($clean['categories']))       { $prompt .= "Feedback Themes Identified: " . $clean['categories'] . "\n"; }
        $prompt .= "\nWrite a concise 3-5 sentence summary of the key feedback the industry representative provided. ";
        $prompt .= "Reflect their perspective (third person), reference the themes selected above, and tie the feedback to job-readiness for the qualification's target roles. ";
        $prompt .= "Australian English, professional tone, no placeholders. 80-150 words.";
    } else if ($contexttype === 'consult_impact_training') {
        $prompt  = "You are an Australian RTO compliance officer drafting the 'Impact on Training Delivery' section of an industry consultation record (ASQA Standards 2025 clauses 1.4 / 1.5 / 4.1).\n\n";
        $prompt .= "CONSULTATION CONTEXT:\n";
        if (!empty($clean['qualification']))   { $prompt .= "Qualification: " . $clean['qualification'] . "\n"; }
        if (!empty($clean['feedback']))        { $prompt .= "Feedback Received: " . $clean['feedback'] . "\n"; }
        if (!empty($clean['categories']))      { $prompt .= "Training-Delivery Strategies Selected: " . $clean['categories'] . "\n"; }
        $prompt .= "\nWrite a concise 3-5 sentence statement explaining how the feedback will be incorporated into training delivery. ";
        $prompt .= "Be specific about the strategies selected (e.g. scenario-based learning, workplace simulations, guest speakers) and tie each strategy to a unit-of-competency outcome. ";
        $prompt .= "Australian English, future-tense action voice (\"the RTO will...\"), no placeholders. 80-150 words.";
    } else if ($contexttype === 'consult_impact_assessment') {
        $prompt  = "You are an Australian RTO compliance officer drafting the 'Impact on Assessment Design' section of an industry consultation record (ASQA Standards 2025 clauses 1.8 / 1.12 / 4.1).\n\n";
        $prompt .= "CONSULTATION CONTEXT:\n";
        if (!empty($clean['qualification']))   { $prompt .= "Qualification: " . $clean['qualification'] . "\n"; }
        if (!empty($clean['feedback']))        { $prompt .= "Feedback Received: " . $clean['feedback'] . "\n"; }
        if (!empty($clean['categories']))      { $prompt .= "Assessment-Design Strategies Selected: " . $clean['categories'] . "\n"; }
        $prompt .= "\nWrite a concise 3-5 sentence statement explaining how the feedback will be incorporated into assessment design. ";
        $prompt .= "Be specific about the strategies selected (e.g. workplace observation, third-party reports, portfolios) and the principles of assessment (validity, reliability, fairness, flexibility) they reinforce. ";
        $prompt .= "Australian English, future-tense action voice, no placeholders. 80-150 words.";
    } else if ($contexttype === 'transitionplan') {
        // FEAT-TRANSITION-AI (v4.4.69): AI Generate button for Transition Plan.
        $prompt  = "You are an Australian RTO compliance officer drafting a qualification teach-out and transition plan (Standard 1.12 of the Standards for RTOs 2025).\n\n";
        $prompt .= "TRANSITION DETAILS:\n";
        if (!empty($clean['oldproductcode']))    { $prompt .= "Superseded Qualification Code: " . $clean['oldproductcode'] . "\n"; }
        if (!empty($clean['oldproductname']))    { $prompt .= "Superseded Qualification Name: " . $clean['oldproductname'] . "\n"; }
        if (!empty($clean['transitiontype']))    { $prompt .= "Transition Type: " . $clean['transitiontype'] . "\n"; }
        if (!empty($clean['newproductcode']))    { $prompt .= "Replacement Qualification Code: " . $clean['newproductcode'] . "\n"; }
        if (!empty($clean['newproductname']))    { $prompt .= "Replacement Qualification Name: " . $clean['newproductname'] . "\n"; }
        if (!empty($clean['teachoutdeadline']))  { $prompt .= "Teach-Out Deadline: " . $clean['teachoutdeadline'] . "\n"; }
        if (!empty($clean['studentsaffected']))  { $prompt .= "Students Affected: " . $clean['studentsaffected'] . "\n"; }
        $prompt .= "\nWrite a professional teach-out and transition plan. ";
        $prompt .= "Include: the date the qualification was or will be superseded, the teach-out deadline by which enrolled learners must complete, options available to students (complete under current qualification, transition to the replacement via credit transfer or RPL), ";
        $prompt .= "how students are notified of the changes and their options, support provided during the teach-out period, and the process for any student who cannot complete within the teach-out period. ";
        $prompt .= "If zero students are affected, state that clearly and confirm no teach-out action is required. ";
        $prompt .= "Australian English, professional RTO compliance tone, no placeholders. 120-200 words.";
    } else {
        echo json_encode(['success' => false, 'error' => 'Unknown ai_draft_text contexttype: ' . $contexttype]);
        exit;
    }

    // FIX-CONSULT-AI-SUGGEST (v4.4.54): Industry consultation AI buttons now use
    // /api/rto/ai-suggest (same endpoint as TAS AI buttons) instead of
    // /api/moodle/course-assistant/chat.  The ai-suggest endpoint is more reliable —
    // it validates only the apiKey (no siteUrl cross-check), uses OpenAI directly,
    // and has three dedicated TAS_AI_FIELD_CONFIGS entries added server-side for
    // consult_feedback / consult_impact_training / consult_impact_assessment.
    // FIX-VOCCOMP-AI-SUGGEST (v4.4.64): voccomp_description given same fix —
    // course-assistant/chat validates siteUrl against stored client URL but PHP sends
    // the siteid text ID (not the actual Moodle URL) → HTTP 401 → "AI request failed".
    // ai-suggest validates apiKey only, which is the correct path for plugin calls.
    // FIX-SESSION-WRITE-CLOSE (v4.4.66): Release the Moodle session before the
    // long-running curl call to /api/rto/ai-suggest (up to 60 s). Without this,
    // any concurrent Moodle request in the same session blocks waiting for the
    // session file lock, which can cause the entire AJAX call to time out.
    \core\session\manager::write_close();
    if (in_array($contexttype, ['voccomp_description', 'consult_feedback', 'consult_impact_training', 'consult_impact_assessment', 'transitionplan'])) {
        $kwParts = [];
        if ($contexttype === 'voccomp_description') {
            if (!empty($clean['activitytype']))  $kwParts[] = 'Activity Type: '  . $clean['activitytype'];
            if (!empty($clean['title']))         $kwParts[] = 'Title: '           . $clean['title'];
            if (!empty($clean['organisation']))  $kwParts[] = 'Organisation: '    . $clean['organisation'];
            if (!empty($clean['startdate']))     $kwParts[] = 'Start Date: '      . $clean['startdate'];
            if (!empty($clean['enddate']))       $kwParts[] = 'End Date: '        . $clean['enddate'];
            if (!empty($clean['totalhours']))    $kwParts[] = 'Total Hours: '     . $clean['totalhours'];
        } else if ($contexttype === 'transitionplan') {
            if (!empty($clean['oldproductcode']))    $kwParts[] = 'Superseded Code: '    . $clean['oldproductcode'];
            if (!empty($clean['oldproductname']))    $kwParts[] = 'Superseded Name: '    . $clean['oldproductname'];
            if (!empty($clean['transitiontype']))    $kwParts[] = 'Transition Type: '    . $clean['transitiontype'];
            if (!empty($clean['newproductcode']))    $kwParts[] = 'Replacement Code: '   . $clean['newproductcode'];
            if (!empty($clean['newproductname']))    $kwParts[] = 'Replacement Name: '   . $clean['newproductname'];
            if (!empty($clean['teachoutdeadline']))  $kwParts[] = 'Teach-Out Deadline: ' . $clean['teachoutdeadline'];
            if (!empty($clean['studentsaffected']))  $kwParts[] = 'Students Affected: '  . $clean['studentsaffected'];
        } else {
            if (!empty($clean['participantname']))     $kwParts[] = 'Industry Representative: '  . $clean['participantname'];
            if (!empty($clean['participantorg']))      $kwParts[] = 'Organisation: '              . $clean['participantorg'];
            if (!empty($clean['participantrole']))     $kwParts[] = 'Role: '                      . $clean['participantrole'];
            if (!empty($clean['consultationmethod']))  $kwParts[] = 'Consultation Method: '       . $clean['consultationmethod'];
            if (!empty($clean['categories']))          $kwParts[] = 'Themes / Strategies: '       . $clean['categories'];
            if (!empty($clean['feedback']))            $kwParts[] = 'Feedback Received: '         . $clean['feedback'];
        }
        $keyword = implode("\n", $kwParts);

        $suggestPayload = json_encode([
            'apiKey'   => $apikey,
            'field'    => $contexttype,
            'qualName' => !empty($clean['qualification']) ? $clean['qualification'] : '',
            'keyword'  => $keyword,
            'count'    => 1,
        ]);
        $sr = rtocompliance_curl_post($apiurl . '/api/rto/ai-suggest', $suggestPayload, ['Content-Type: application/json', 'Accept: application/json'], 60);
        if ($sr['error']) {
            echo json_encode(['success' => false, 'error' => 'Network error: ' . $sr['error']]);
            exit;
        }
        if ($sr['httpcode'] !== 200) {
            $rd = json_decode($sr['response'], true);
            // FIX-EMPTY-ERROR-STRING (v4.4.66): Use ?: (not ??) so an empty-string
            // error field from the server also falls through to the HTTP-code fallback.
            // The old ?? operator kept "" as-is, making j.error falsy in JS and
            // showing the generic "AI request failed" with no diagnostic detail.
            echo json_encode(['success' => false, 'error' => ($rd['error'] ?: 'AI request failed (HTTP ' . $sr['httpcode'] . ')')]);
            exit;
        }
        $rd = json_decode($sr['response'], true);
        if ($rd && !empty($rd['success']) && isset($rd['suggestions'][0])) {
            $sugText = trim($rd['suggestions'][0]);
            if ($sugText === '') {
                echo json_encode(['success' => false, 'error' => 'AI returned an empty response. Please try again.']);
            } else {
                echo json_encode(['success' => true, 'text' => $sugText]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => ($rd['error'] ?: 'AI generation failed — please try again.')]);
        }
        exit;
    }

    // FIX-AI-COURSENAME (v4.4.50): The course-assistant endpoint requires courseName and
    // courseContext fields — omitting them returns HTTP 400 "Invalid request parameters".
    // Use the qualification name from $clean if available, otherwise a generic fallback.
    $aiCourseName    = !empty($clean['qualification']) ? $clean['qualification'] : 'RTO Compliance';
    $aiCourseContext = 'Australian vocational training and RTO compliance management system (ASQA Standards 2025)';

    $postdata = [
        'siteUrl'        => $siteid,
        'apiKey'         => $apikey,
        'action'         => 'ai_assist',
        'question'       => $prompt,
        'userId'         => $USER->id,
        'courseId'       => 0,
        'courseName'     => $aiCourseName,
        'courseContext'  => $aiCourseContext,
        'isFirstMessage' => true,
        'studentName'    => fullname($USER),
    ];

    // FIX-MAY5-CURL-NOTFOUND (v4.4.45): Use native curl helper (no Moodle \curl class dependency).
    $result   = rtocompliance_curl_post($apiurl . '/api/moodle/course-assistant/chat', json_encode($postdata), ['Content-Type: application/json', 'Accept: application/json'], 60);
    $response = $result['response'];
    $httpcode = $result['httpcode'];
    $error    = $result['error'];

    if ($error) {
        echo json_encode(['success' => false, 'error' => 'Network error: ' . $error]);
        exit;
    }
    if ($httpcode !== 200) {
        $rd = json_decode($response, true);
        echo json_encode(['success' => false, 'error' => ($rd['error'] ?: 'AI request failed (HTTP ' . $httpcode . ')')]);
        exit;
    }

    $result = json_decode($response, true);
    if ($result && !empty($result['success'])) {
        $text = $result['message'] ?? $result['response'] ?? $result['text'] ?? '';
        $text = trim($text);
        if ($text === '') {
            echo json_encode(['success' => false, 'error' => 'AI returned an empty response. Please try again.']);
        } else {
            echo json_encode(['success' => true, 'text' => $text]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => ($result['error'] ?: 'AI generation failed — please try again.')]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
