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

namespace local_rtocompliance\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Survey Analyzer — routes all AI calls through the lms-labs.com platform.
 * Cost: 5 platform credits per analysis (deducted server-side by the platform).
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class survey_analyzer {
    const CREDIT_COST = 5;

    private $apikey;
    private $apibase;

    public function __construct() {
        // Use the same platform API key the rest of the plugin uses (not a direct OpenAI key).
        if (file_exists(__DIR__ . '/../../../../../../local/aiconfig/lib.php')
                && !function_exists('local_aiconfig_get_apikey')) {
            require_once(__DIR__ . '/../../../../../../local/aiconfig/lib.php');
        }
        $this->apikey  = function_exists('local_aiconfig_get_apikey')
            ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
            : (get_config('local_rtocompliance', 'apikey') ?: '');
        $this->apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
    }

    public function is_configured(): bool {
        return !empty($this->apikey);
    }

    /**
     * Returns the fixed credit cost for display in the UI.
     */
    public function get_credit_cost(): int {
        return self::CREDIT_COST;
    }

    public function analyze_survey_responses(string $surveytype, int $periodstart, int $periodend): array {
        global $DB;

        if (!$this->is_configured()) {
            return [
                'success' => false,
                'error'   => 'AI analysis is not configured. Please add your platform API key in plugin settings.',
            ];
        }

        // FIX-SURVEY-AI-ERROR: Wrap the ENTIRE analysis flow (including get_survey_responses)
        // in a \Throwable catch. Previously, get_survey_responses() was outside the try block,
        // so any dml_exception (e.g. missing table) propagated as an uncaught Moodle error page.
        // Also, the original catch block itself called insert_record() on the ai_survey table
        // without a guard — if that table was missing on a fresh install, the error handler
        // would also throw an uncaught exception, compounding the problem.
        try {
            $responses = $this->get_survey_responses($surveytype, $periodstart, $periodend);

            if (empty($responses)) {
                return [
                    'success' => false,
                    'error'   => 'No survey responses found for the selected period. '
                               . 'Check that survey responses exist for ' . date('Y', $periodstart) . '.',
                ];
            }

            $responsestext = $this->build_responses_text($responses);

            $platformresult = $this->call_platform_api($surveytype, $responsestext);
            $analysis       = $this->parse_analysis_response($platformresult['analysisJson']);

            $record = new \stdClass();
            $record->surveytype       = $surveytype;
            $record->analysisperiod   = $this->determine_period_type($periodstart, $periodend);
            $record->periodstart      = $periodstart;
            $record->periodend        = $periodend;
            $record->responsecount    = count($responses);
            $record->overallsentiment = $analysis['sentiment'] ?? null;
            $record->sentimentscore   = $analysis['sentiment_score'] ?? null;
            $record->satisfactionindex = $analysis['satisfaction_index'] ?? null;
            $record->keythemes        = json_encode($analysis['themes'] ?? []);
            $record->strengths        = json_encode($analysis['strengths'] ?? []);
            $record->improvements     = json_encode($analysis['improvements'] ?? []);
            $record->recommendations  = json_encode($analysis['recommendations'] ?? []);
            $record->wordcloud        = json_encode($analysis['word_frequencies'] ?? []);
            $record->trendsummary     = $analysis['trend_summary'] ?? '';
            $record->fullanalysis     = $analysis['full_report'] ?? '';
            $record->aimodel          = 'gpt-4o-mini (platform)';
            $record->creditscost      = $platformresult['creditsUsed'] ?? self::CREDIT_COST;
            $record->status           = 'completed';
            $record->requestedby      = (int) (\core\session\manager::get_userid() ?? 2);
            $record->timecreated      = time();
            $record->timecompleted    = time();

            // Insert result record — non-fatal if the table doesn't exist yet.
            $analysisid = 0;
            try {
                $analysisid = $DB->insert_record('local_rtocompliance_ai_survey', $record);
            } catch (\Throwable $dberr) {
                // Table may not exist on this installation — analysis still succeeded, just
                // the result won't be persisted. The caller gets the analysis data regardless.
            }

            return [
                'success'            => true,
                'analysis_id'        => $analysisid,
                'analysis'           => $analysis,
                'responses_analysed' => count($responses),
                'credits_used'       => $record->creditscost,
                'credits_remaining'  => $platformresult['creditsRemaining'] ?? null,
            ];

        } catch (\Throwable $e) {
            // Non-fatal attempt to log the error record.
            try {
                $errrecord = new \stdClass();
                $errrecord->surveytype     = $surveytype;
                $errrecord->analysisperiod = $this->determine_period_type($periodstart, $periodend);
                $errrecord->periodstart    = $periodstart;
                $errrecord->periodend      = $periodend;
                $errrecord->responsecount  = 0;
                $errrecord->status         = 'error';
                $errrecord->errormessage   = $e->getMessage();
                $errrecord->requestedby    = (int) (\core\session\manager::get_userid() ?? 2);
                $errrecord->timecreated    = time();
                $DB->insert_record('local_rtocompliance_ai_survey', $errrecord);
            } catch (\Throwable $ignored) {
                // Ignore secondary error — primary error message is returned below.
            }

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    private function get_survey_responses(string $surveytype, int $periodstart, int $periodend): array {
        global $DB;

        // FIX-SURVEY-TABLE: local_rtocompliance_survey_responses / survey_questions tables
        // were referenced here but never existed in install.xml, causing a fatal DB error
        // ("Table '...mdl_local_rtocompliance_survey_responses' doesn't exist") whenever
        // Run AI Analysis was clicked.
        //
        // The survey data that DOES exist lives in local_rtocompliance_surveys — each
        // completed survey has overallsatisfaction (1-5), comments (text), and a responses
        // JSON object holding individual question answers.  We flatten that here into a
        // pseudo-response array compatible with build_responses_text() so the AI analysis
        // works with real data rather than an empty or missing table.
        $surveys = $DB->get_records_sql(
            "SELECT id, respondentname, qualificationcode, overallsatisfaction, comments, responses
               FROM {local_rtocompliance_surveys}
              WHERE surveytype = ?
                AND status = 'completed'
                AND timecompleted >= ?
                AND timecompleted <= ?",
            [$surveytype, $periodstart, $periodend]
        );

        $result = [];
        $idx    = 0;

        foreach ($surveys as $survey) {
            // 1. Overall satisfaction rating.
            if (!empty($survey->overallsatisfaction)) {
                $obj = new \stdClass();
                $obj->id            = $idx++;
                $obj->questiontext  = 'Overall Satisfaction Rating (1 = Very Dissatisfied, 5 = Very Satisfied)';
                $obj->responsetext  = (string)$survey->overallsatisfaction . '/5';
                $obj->responserating = (int)$survey->overallsatisfaction;
                $result[] = $obj;
            }

            // 2. Individual question responses from the JSON responses field.
            if (!empty($survey->responses)) {
                $decoded = json_decode($survey->responses, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $qtext => $ans) {
                        if (!is_string($qtext) || $qtext === '') {
                            continue;
                        }
                        $obj = new \stdClass();
                        $obj->id            = $idx++;
                        $obj->questiontext  = $qtext;
                        $obj->responsetext  = is_array($ans) ? implode(', ', $ans) : (string)$ans;
                        $obj->responserating = null;
                        $result[] = $obj;
                    }
                }
            }

            // 3. Free-text comments.
            if (!empty(trim((string)$survey->comments))) {
                $obj = new \stdClass();
                $obj->id            = $idx++;
                $obj->questiontext  = 'General Comments & Feedback';
                $obj->responsetext  = trim((string)$survey->comments);
                $obj->responserating = null;
                $result[] = $obj;
            }
        }

        return $result;
    }

    private function build_responses_text(array $responses): string {
        $text = '';
        foreach ($responses as $response) {
            $text .= "Q: {$response->questiontext}\n";
            $text .= "A: {$response->responsetext}\n";
            if (!empty($response->responserating)) {
                $text .= "Rating: {$response->responserating}/5\n";
            }
            $text .= "\n";
        }
        return $text;
    }

    /**
     * Send survey data to the platform API, which deducts credits and calls OpenAI.
     *
     * @throws \moodle_exception on API or credential error.
     */
    private function call_platform_api(string $surveytype, string $responsestext): array {
        $payload = json_encode([
            'apiKey'        => $this->apikey,
            'surveyType'    => $surveytype,
            'responsesText' => $responsestext,
        ]);

        // Release session lock before long-running API call.
        \core\session\manager::write_close();

        $c = new \curl(['ignoresecurity' => true]);
        $c->setopt([
            'CURLOPT_TIMEOUT'        => 120,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);
        $c->setHeader([
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apikey,
        ]);

        $response = $c->post($this->apibase . '/api/rto/ai-survey-analyze', $payload);
        $httpcode = $c->get_info(CURLINFO_HTTP_CODE);
        $curlerr  = $c->error;

        // BUG-SURVEY-HTTP-ARRAY FIX: Some Moodle versions return the full curl_getinfo() array
        // from get_info() regardless of the $opt argument (older implementations ignore $opt).
        // Normalise to an int so the comparisons below work and the fallback string "HTTP N"
        // never becomes "HTTP Array" when $httpcode is unexpectedly an array.
        if (is_array($httpcode)) {
            $httpcode = (int)($httpcode['http_code'] ?? 0);
        } else {
            $httpcode = (int)$httpcode;
        }

        // BUG-SURVEY-AI-MSG: throw plain \Exception (not \moodle_exception) so the
        // message reaches the user verbatim.  moodle_exception wraps the message in the
        // 'ai_api_error' lang string ("Error communicating with AI service: {$a}") which,
        // combined with the "Analysis failed: " redirect prefix and any "AI analysis
        // failed:" prefix the server adds, produced triple-wrapped error toasts like
        // "Analysis failed: Error communicating with AI service: AI analysis failed:
        // <real reason>".  Plain Exception keeps the message single-layer.
        if ($curlerr) {
            throw new \Exception('Network error talking to platform: ' . $curlerr);
        }

        $data = json_decode($response, true);

        if ($httpcode === 401) {
            throw new \Exception('Invalid platform API key — check Site Administration → Plugins → RTO Compliance → Platform API key.');
        }

        if ($httpcode === 402) {
            $credreq = $data['creditsRequired'] ?? self::CREDIT_COST;
            $credrem = $data['creditsRemaining'] ?? 0;
            throw new \Exception(
                "Insufficient platform credits — this analysis needs {$credreq} credits but you only have {$credrem}. Top up at https://lms-labs.com/pricing."
            );
        }

        if ($httpcode !== 200 || empty($data['success'])) {
            // BUG-SURVEY-HTTP-ARRAY FIX: $data['error'] may itself be an array (nested
            // error object from the server); cast to string to avoid "Array to string
            // conversion".
            // BUG-SURVEY-AI-MSG: surface the real server-side error message (and the
            // HTTP code) instead of a generic "Error communicating with AI service".
            // Common root causes that previously hid behind the generic toast:
            //   - OpenAI rate-limit / 429              → "AI analysis failed: Rate limit reached..."
            //   - OpenAI key invalid on the platform   → "AI analysis failed: Incorrect API key provided..."
            //   - Model unavailable / timeout          → "AI analysis failed: Connection error..."
            //   - JSON parse failure of OpenAI reply   → "AI analysis failed: ..."
            // If the response body wasn't valid JSON at all (e.g. an Express HTML error
            // page on a deploy hiccup), include a 200-char snippet so admins can still
            // diagnose the issue without server-log access.
            $rawmsg = $data['error'] ?? ($data['message'] ?? null);
            if (is_array($rawmsg)) {
                $rawmsg = json_encode($rawmsg);
            }
            $rawmsg = (string) $rawmsg;
            if ($rawmsg === '' && !is_array($data)) {
                // Response wasn't JSON — show a snippet of the raw body.
                $snippet = substr(strip_tags((string) $response), 0, 200);
                $rawmsg  = $snippet !== '' ? "non-JSON response: {$snippet}" : 'empty response body';
            }
            if ($rawmsg === '') {
                $rawmsg = 'no error message returned by platform';
            }
            throw new \Exception("AI analysis failed (HTTP {$httpcode}): {$rawmsg}");
        }

        return $data;
    }

    private function parse_analysis_response(string $response): array {
        $response = trim($response);
        if (strpos($response, '```json') !== false) {
            preg_match('/```json\s*(.*?)\s*```/s', $response, $matches);
            $response = $matches[1] ?? $response;
        } elseif (strpos($response, '```') !== false) {
            preg_match('/```\s*(.*?)\s*```/s', $response, $matches);
            $response = $matches[1] ?? $response;
        }

        $analysis = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('ai_parse_error', 'local_rtocompliance', '', json_last_error_msg());
        }

        return $analysis;
    }

    private function determine_period_type(int $start, int $end): string {
        $days = ($end - $start) / 86400;

        if ($days <= 31) {
            return 'monthly';
        } elseif ($days <= 92) {
            return 'quarterly';
        } else {
            return 'yearly';
        }
    }

    public function get_recent_analyses(string $surveytype = null, int $limit = 10): array {
        global $DB;

        // BUG-SURVEY-AI FIX: The local_rtocompliance_ai_survey table may not exist on
        // installations that pre-date the DB upgrade adding it.  Wrapping in try/catch
        // ensures ai_analysis.php loads successfully on those sites rather than crashing
        // with a dml_exception before the user can interact with the page.
        try {
            $sql    = "SELECT * FROM {local_rtocompliance_ai_survey} WHERE status = 'completed'";
            $params = [];

            if ($surveytype) {
                $sql      .= " AND surveytype = ?";
                $params[]  = $surveytype;
            }

            $sql .= " ORDER BY timecreated DESC";

            return array_values($DB->get_records_sql($sql, $params, 0, $limit));
        } catch (\Throwable $ignored) {
            return [];
        }
    }

    public function get_analysis(int $id): ?object {
        global $DB;
        // BUG-SURVEY-AI FIX (follow-up): Same table-missing guard as get_recent_analyses().
        // If local_rtocompliance_ai_survey doesn't exist on a pre-upgrade install, return
        // null gracefully instead of crashing the ai_analysis.php ?id=X page.
        try {
            return $DB->get_record('local_rtocompliance_ai_survey', ['id' => $id]) ?: null;
        } catch (\Throwable $ignored) {
            return null;
        }
    }
}
