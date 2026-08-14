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
 * RTO Compliance plugin — ajax.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
    $seed        = optional_param_array('seed', [], PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-text AI prompt seeds; keys cleaned with PARAM_ALPHANUMEXT and values capped to 1KB immediately below.

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
        // TAS-PROMPT-2025 (v6.2.38): correct clause (Industry Engagement is Standard 1.2 under
        // the 2025 Standards, not 1.4/4.1), grounded-only (no fabricated consensus), with a
        // mandatory human-review disclaimer per ASQA's responsible-AI expectations.
        $prompt  = "You are assisting an Australian RTO to record the KEY FEEDBACK actually given by an industry/employer representative during genuine industry engagement (2025 Standards for RTOs, Quality Area 1, Standard 1.2 Industry Engagement).\n\n";
        $prompt .= "CONSULTATION DETAILS (use ONLY what is provided — do NOT invent findings, statistics, names or quotes):\n";
        if (!empty($clean['participantname']))  { $prompt .= "Industry Representative: " . $clean['participantname'] . "\n"; }
        if (!empty($clean['participantorg']))   { $prompt .= "Organisation: " . $clean['participantorg'] . "\n"; }
        if (!empty($clean['participantrole']))  { $prompt .= "Role: " . $clean['participantrole'] . "\n"; }
        if (!empty($clean['consultationmethod'])) { $prompt .= "Consultation Method: " . $clean['consultationmethod'] . "\n"; }
        if (!empty($clean['qualification']))    { $prompt .= "Qualification: " . $clean['qualification'] . "\n"; }
        if (!empty($clean['categories']))       { $prompt .= "Feedback themes the RTO ticked: " . $clean['categories'] . "\n"; }
        $prompt .= "\nWrite a 3-5 sentence, third-person summary of THIS representative's feedback, grounded strictly in the details above. ";
        $prompt .= "If no substantive feedback themes are supplied, do NOT fabricate consensus — instead write a neutral line such as 'The representative was consulted on [topic]; specific feedback to be recorded by the RTO.' ";
        $prompt .= "Do not claim industry-wide agreement from a single contact. Australian English, professional tone, no placeholders, 80-150 words. ";
        $prompt .= "End with: 'This is an AI-assisted draft and must be reviewed and verified against the actual consultation record by RTO staff before use.'";
    } else if ($contexttype === 'consult_impact_training') {
        // TAS-PROMPT-2025 (v6.2.38): Training strategy is Standard 1.1 + Industry Engagement 1.2
        // (not 1.4/1.5/4.1); require online/blended human touchpoints; never assert changes are
        // already done unless the input says so; human-review disclaimer.
        $prompt  = "You are assisting an Australian RTO to document how verified industry feedback will change TRAINING DELIVERY (2025 Standards for RTOs, Quality Area 1, Standard 1.1 Training and assessment strategies and practices; Standard 1.2 Industry Engagement).\n\n";
        $prompt .= "CONTEXT (use ONLY what is provided):\n";
        if (!empty($clean['qualification']))   { $prompt .= "Qualification: " . $clean['qualification'] . "\n"; }
        if (!empty($clean['feedback']))        { $prompt .= "Verified feedback: " . $clean['feedback'] . "\n"; }
        if (!empty($clean['categories']))      { $prompt .= "Delivery strategies the RTO selected: " . $clean['categories'] . "\n"; }
        $prompt .= "\nWrite a 3-5 sentence statement in future action voice (\"the RTO will...\") linking EACH selected strategy to a specific unit/skill outcome of the training product. ";
        $prompt .= "For online or blended delivery, state the human touchpoints and supervision that keep delivery authentic (scheduled live sessions, trainer check-ins). ";
        $prompt .= "Do not assert changes have already been made unless the input says so. Australian English, no placeholders, 80-150 words. ";
        $prompt .= "End with: 'AI-assisted draft — review and confirm against the RTO's actual training plan before saving.'";
    } else if ($contexttype === 'consult_impact_assessment') {
        // TAS-PROMPT-2025 (v6.2.38): Assessment is Standards 1.3 (consistent with the product),
        // 1.4 (principles + rules of evidence) and 1.5 (validation) — not 1.8/1.12/4.1. Add the
        // rules of evidence + authenticity/AI-integrity; never assert tools already changed;
        // human-review disclaimer.
        $prompt  = "You are assisting an Australian RTO to document how verified industry feedback will change ASSESSMENT (2025 Standards for RTOs, Quality Area 1: Standard 1.3 assessment consistent with the training product; Standard 1.4 principles of assessment and rules of evidence; Standard 1.5 validation).\n\n";
        $prompt .= "CONTEXT (use ONLY what is provided):\n";
        if (!empty($clean['qualification']))   { $prompt .= "Qualification: " . $clean['qualification'] . "\n"; }
        if (!empty($clean['feedback']))        { $prompt .= "Verified feedback: " . $clean['feedback'] . "\n"; }
        if (!empty($clean['categories']))      { $prompt .= "Assessment strategies the RTO selected: " . $clean['categories'] . "\n"; }
        $prompt .= "\nWrite a 3-5 sentence statement tying each selected strategy to the principles of assessment (fairness, flexibility, validity, reliability) and the rules of evidence (valid, sufficient, authentic, current). ";
        $prompt .= "Where relevant, note how authenticity is assured (that evidence is the student's own work, not plagiarised or AI-generated) and how any change will be validated. Do not state that tools have already been changed unless the input says so. ";
        $prompt .= "Australian English, no placeholders, 80-150 words. End with: 'AI-assisted draft — an assessment-competent person must review and validate before use.'";
    } else if ($contexttype === 'transitionplan') {
        // FEAT-TRANSITION-AI (v4.4.69): AI Generate button for Transition Plan.
        // TAS-PROMPT-2025 (v6.2.38): there is no "Standard 1.12" in the 2025 Standards — cite
        // the training-product transition requirements under the VET Quality Framework instead.
        $prompt  = "You are an Australian RTO compliance officer drafting a qualification teach-out and transition plan (training-product transition requirements under the VET Quality Framework and the 2025 Standards for RTOs).\n\n";
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

// ── Issue certificate from Student Results page ───────────────────────────────
// TASK-45 (v5.9.345): AJAX endpoint called by "Issue Certificate" button on
// qualbuilder_results.php. Accepts userid + qualbuilderid, resolves cert types
// based on product type (testamur+record for qualifications, statement for skill
// sets/single units), checks for existing certs, and calls
// local_rtocompliance_programmatic_issue_cert() for each required cert type.
if ($action === 'issue_cert_from_results') {
    $userid       = required_param('userid',       PARAM_INT);
    $qualbuilderid = required_param('qualbuilderid', PARAM_INT);

    require_once(__DIR__ . '/lib.php');

    // Load the qualification record.
    $qual = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualbuilderid]);
    if (!$qual) {
        echo json_encode(['success' => false, 'error' => 'Qualification not found']);
        exit;
    }

    $user = core_user::get_user($userid);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    // Resolve the student record (needed for unit outcome lookup).
    $studentrec = $DB->get_record('local_rtocompliance_students', ['userid' => $userid], 'id', IGNORE_MISSING);

    // Determine which cert types to issue.
    $isqualification = ($qual->producttype === 'qualification');
    $certtypes = $isqualification ? ['testamur', 'record'] : ['statement'];

    // Verify the student is actually complete (all selected units have a
    // competent outcome) before issuing — guards against button being shown
    // in edge cases or direct AJAX calls.
    $allunits = $DB->get_records('local_rtocompliance_qualunits', [
        'qualbuilderid' => $qualbuilderid, 'selected' => 1], 'unittype ASC, unitcode ASC');
    if (!empty($allunits)) {
        $isComplete = $DB->record_exists_sql(
            "SELECT 1 FROM {local_rtocompliance_qualunits} qu
              WHERE qu.qualbuilderid = :qbid
                AND qu.selected = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {local_rtocompliance_enrolments} e2
                     WHERE e2.studentid = :studentid
                       AND e2.unitcode = qu.unitcode
                       AND e2.outcomeidentifier IN ('20','51','60','81')
                )",
            ['qbid' => $qualbuilderid, 'studentid' => $studentrec ? $studentrec->id : -1]
        );
        // EXISTS returns true = there IS a unit without a final outcome → not complete.
        if ($isComplete) {
            echo json_encode(['success' => false, 'error' => 'Student has not yet completed all units']);
            exit;
        }
    }

    // Build unit list with per-student outcomes for the cert.
    $unitsForCert = [];
    if ($studentrec) {
        $unitsForCert = local_rtocompliance_get_qualbuilder_unit_list_with_outcomes($qualbuilderid, $studentrec->id);
    }
    if (empty($unitsForCert)) {
        // Fallback: plain unit list with outcome '20'.
        foreach ($allunits as $u) {
            $unitsForCert[] = ['code' => $u->unitcode, 'name' => $u->unitname, 'outcome' => '20'];
        }
    }

    \core\session\manager::write_close();

    $issued  = [];
    $skipped = [];
    $errors  = [];

    foreach ($certtypes as $certtype) {
        // Skip if a non-superseded cert already exists for this qual + type.
        $existing = $DB->get_record_sql(
            "SELECT id, certnumber, issuedate
               FROM {local_rtocompliance_certs}
              WHERE userid            = :userid
                AND certtype          = :certtype
                AND qualificationcode = :qualcode
                AND status            = 'issued'
                AND (reissued_at IS NULL OR reissued_at = 0)
              LIMIT 1",
            ['userid' => $userid, 'certtype' => $certtype, 'qualcode' => $qual->qualificationcode]
        );
        if ($existing) {
            $skipped[] = [
                'certtype'   => $certtype,
                'certnumber' => $existing->certnumber,
                'issuedate'  => userdate($existing->issuedate, get_string('strftimedate', 'core_langconfig')),
            ];
            continue;
        }

        $result = local_rtocompliance_programmatic_issue_cert(
            $userid,
            $certtype,
            $qual->qualificationcode,
            $qual->qualificationname,
            $unitsForCert,
            time(),
            'default',
            1,  // send email
            0   // timecompleted unknown here
        );

        if ($result['ok']) {
            $issued[] = [
                'certtype'   => $certtype,
                'certnumber' => $result['certnumber'],
                'certid'     => $result['certid'],
                'issuedate'  => userdate(time(), get_string('strftimedate', 'core_langconfig')),
            ];
            // Mark autocert queue row complete if one exists.
            if ($studentrec) {
                $autocertrow = $DB->get_record('local_rtocompliance_autocerts', [
                    'studentid'     => $studentrec->id,
                    'qualbuilderid' => $qualbuilderid,
                    'status'        => 'pending',
                ]);
                if ($autocertrow) {
                    $DB->update_record('local_rtocompliance_autocerts', (object)[
                        'id'            => $autocertrow->id,
                        'status'        => 'complete',
                        'timeprocessed' => time(),
                        'certsissued'   => ($autocertrow->certsissued ?? 0) + 1,
                    ]);
                }
            }
        } elseif ($result['error'] === 'INSUFFICIENT_CREDITS') {
            echo json_encode(['success' => false, 'error' => 'Insufficient credits to issue certificate']);
            exit;
        } else {
            $errors[] = $certtype . ': ' . ($result['error'] ?? 'unknown error');
        }
    }

    if (!empty($errors) && empty($issued)) {
        echo json_encode(['success' => false, 'error' => implode('; ', $errors)]);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'issued'     => $issued,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'studentname' => fullname($user),
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
