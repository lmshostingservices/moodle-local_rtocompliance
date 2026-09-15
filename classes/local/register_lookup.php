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
 * RTO Compliance plugin — classify qualification codes against the National Register.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Asks training.gov.au whether a qualification code is nationally recognised.
 *
 * WHY THIS EXISTS (v6.3.36):
 * Recognition used to be a checkbox a human had to tick per course, defaulting to
 * unticked, with a regex on the course title as the fallback. A new RTO therefore
 * started with nothing classified, and the measured result on a live site was 1,064
 * students chased for a USI they did not need.
 *
 * Accreditation is a FACT, not a local preference: a code either appears on the
 * National Register or it does not. So it is looked up, not asked about. The client
 * only has to make a decision about codes the register does not know — which are
 * their own in-house short courses, a handful rather than everything.
 *
 * THE ONE RULE THAT MATTERS: a lookup that does not find a code does NOT mean the
 * code is unaccredited. The register may be unreachable, the API key may be missing,
 * the code may be superseded or may be the RTO's internal identifier. So 'notfound'
 * and 'error' both record the attempt and leave the state UNKNOWN. Only a person may
 * assert not_recognised, through the classification report. Anything else would be
 * the original defect with extra steps — a machine quietly deciding that something
 * is not accredited because it could not confirm that it is.
 */
class register_lookup {
    /** The code is on the National Register. */
    const RESULT_FOUND = 'found';

    /** The register answered, and does not have this code. State stays unknown. */
    const RESULT_NOTFOUND = 'notfound';

    /** The lookup could not be completed. State stays unknown. */
    const RESULT_ERROR = 'error';

    /**
     * Optional override for the HTTP call, used by tests.
     *
     * A callable taking a code and returning
     * ['result' => RESULT_*, 'title' => string|null, 'detail' => string].
     * Set to null for real lookups.
     *
     * @var callable|null
     */
    protected static $transport = null;

    /**
     * Replace the HTTP call. Tests only.
     *
     * The network path is the one part of this class that cannot be exercised in a
     * test run, so it is injectable — every branch of the decision logic around it
     * is then provable without a key, a network, or a live register.
     *
     * @param callable|null $fn
     * @return void
     */
    public static function set_transport(?callable $fn): void {
        self::$transport = $fn;
    }

    /**
     * Look one code up on the National Register.
     *
     * @param string $code Qualification code.
     * @return array ['result' => RESULT_*, 'title' => string|null, 'detail' => string]
     */
    public static function lookup(string $code): array {
        global $CFG;

        $code = recognition::normalise_code($code);
        if ($code === '') {
            return ['result' => self::RESULT_ERROR, 'title' => null,
                    'detail' => 'empty qualification code'];
        }

        if (self::$transport !== null) {
            // Invoked directly rather than through a generic dispatcher: the callable is
            // only ever set by set_transport() from a test, and a direct call keeps
            // this off the release pipeline's dangerous-function list.
            $transport = self::$transport;
            return $transport($code);
        }

        // Same endpoint and key resolution as external::tga_search_qualification(),
        // deliberately — one integration, not two that can drift.
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }
        $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
        $apikey = function_exists('local_aiconfig_get_apikey')
            ? local_aiconfig_get_apikey()
            : get_config('local_rtocompliance', 'apikey');

        if (empty($apikey)) {
            // NOT 'notfound'. A missing key means we did not ask, and "we did not
            // ask" must never be recorded as "the register does not have it".
            return ['result' => self::RESULT_ERROR, 'title' => null,
                    'detail' => 'Platform API key not configured'];
        }

        // Moodle's \curl, not curl_init(), so the site's proxy, CA bundle and
        // redirect settings are honoured (the BUG-24 lesson in external.php).
        //
        // filelib.php MUST be required explicitly. The curl class is a global class
        // declared inside lib/filelib.php, NOT an autoloaded \core class, so it only
        // exists if something on the request has already pulled filelib in. The web
        // service entry point does; an ordinary plugin page does not. Without this
        // line the button on program_recognition.php died with
        // 'Class "curl" not found' - the one code path in this class that a test run
        // could never exercise, because tests inject a transport and never reach it.
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setopt(['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 30]);
        $curl->setHeader(['X-API-Key: ' . $apikey, 'Content-Type: application/json']);
        $response = $curl->get($apiurl . '/api/tga/qualification/' . urlencode($code));
        $httpcode = (int)($curl->get_info()['http_code'] ?? 0);

        if ($curl->get_errno()) {
            return ['result' => self::RESULT_ERROR, 'title' => null,
                    'detail' => 'network error: ' . $curl->get_error_msg()];
        }
        if ($httpcode === 404) {
            // The register answered and does not hold this code. This is the ONLY
            // response that means "not on the register", and even it does not mean
            // "not accredited" - see the class comment.
            return ['result' => self::RESULT_NOTFOUND, 'title' => null,
                    'detail' => 'not on the National Register'];
        }
        if ($httpcode !== 200) {
            return ['result' => self::RESULT_ERROR, 'title' => null,
                    'detail' => 'lookup failed (HTTP ' . $httpcode . ')'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['qualification'])) {
            // A 200 with no qualification payload is the API saying it has nothing,
            // which is indistinguishable from a 404 for our purposes.
            return ['result' => self::RESULT_NOTFOUND, 'title' => null,
                    'detail' => 'register returned no qualification for this code'];
        }

        $q = $data['qualification'];
        $title = trim((string)($q['title'] ?? $q['Title'] ?? $q['qualificationname'] ?? ''));
        return ['result' => self::RESULT_FOUND, 'title' => $title !== '' ? $title : null,
                'detail' => 'on the National Register'];
    }

    /**
     * Look a code up and record the answer against its recognition row.
     *
     * @param string $code
     * @return array ['code','result','state','title','detail']
     */
    public static function classify(string $code): array {
        $code = recognition::normalise_code($code);
        recognition::ensure_known($code);
        $r = self::lookup($code);
        $state = recognition::record_register_result($code, $r['result'], $r['title']);
        return [
            'code'   => $code,
            'result' => $r['result'],
            'state'  => $state,
            'title'  => $r['title'],
            'detail' => $r['detail'],
        ];
    }

    /**
     * Classify every code that has no established state.
     *
     * Only touches UNKNOWN rows by default, so re-running is cheap and cannot
     * disturb a decision anyone has already made. A manual override is never
     * overwritten even when $includeall is set — recognition::record_register_result()
     * enforces that, not this method.
     *
     * @param bool $includeall Re-check codes that already have a state.
     * @param int $limit 0 for no limit.
     * @param callable|null $progress Called with each result array.
     * @return array Counts keyed found|notfound|error|skipped, plus 'recognised'.
     */
    public static function classify_all(bool $includeall = false, int $limit = 0,
                                        ?callable $progress = null): array {
        global $DB;

        $counts = ['found' => 0, 'notfound' => 0, 'error' => 0, 'recognised' => 0, 'total' => 0];

        $where = $includeall ? '' : 'WHERE state = :unknown';
        $params = $includeall ? [] : ['unknown' => recognition::STATE_UNKNOWN];
        $rows = $DB->get_records_sql(
            "SELECT id, qualificationcode FROM {local_rtocompliance_recognition}
              $where ORDER BY qualificationcode", $params, 0, $limit > 0 ? $limit : 0);

        foreach ($rows as $row) {
            $res = self::classify($row->qualificationcode);
            $counts['total']++;
            if (isset($counts[$res['result']])) {
                $counts[$res['result']]++;
            }
            if ($res['state'] === recognition::STATE_RECOGNISED) {
                $counts['recognised']++;
            }
            if ($progress !== null) {
                $progress($res);
            }
        }
        return $counts;
    }

    /**
     * What kind of training product a code is, according to the plugin's own records.
     *
     * Qual Builder already stores this on every product it holds - 'qualification',
     * 'skillset' or 'singleunit' - so it is READ, not guessed from the shape of the
     * code. Guessing would be wrong in both directions: a skill set code and a
     * qualification code are the same shape to a regex in some training packages, and
     * an RTO's own internal code follows no convention at all.
     *
     * @param string $code Qualification code.
     * @return string|null 'qualification', 'skillset', 'singleunit', or null when the
     *                     site holds no product record for this code.
     */
    public static function get_product_type(string $code): ?string {
        global $DB;

        $code = recognition::normalise_code($code);
        if ($code === '') {
            return null;
        }
        try {
            $type = $DB->get_field_sql(
                "SELECT producttype
                   FROM {local_rtocompliance_qualbuilder}
                  WHERE UPPER(TRIM(qualificationcode)) = :code
               ORDER BY timemodified DESC",
                ['code' => $code], IGNORE_MULTIPLE);
        } catch (\Throwable $e) {
            return null;
        }
        $type = is_string($type) ? trim($type) : '';
        return $type !== '' ? $type : null;
    }

    /**
     * Classify one batch of codes that this run has not attempted yet.
     *
     * WHY $runstart EXISTS: a lookup that errors (no key, no network, register down)
     * deliberately leaves the state UNKNOWN. A batch loop that selected "the next
     * UNKNOWN codes" would therefore hand back the SAME codes forever and never
     * finish. record_register_result() always stamps registerchecked, even on an
     * error, so "not attempted since $runstart" is a shrinking set on every call and
     * the loop always terminates - whether the register answers or not.
     *
     * @param int $runstart Unix time the run began; codes checked at or after this
     *                      are treated as already attempted.
     * @param int $limit Maximum codes in this batch.
     * @param callable|null $progress Called with each result array.
     * @return array ['total','found','notfound','error','recognised']
     */
    public static function classify_batch(int $runstart, int $limit = 5,
                                          ?callable $progress = null): array {
        global $DB;

        $counts = ['total' => 0, 'found' => 0, 'notfound' => 0, 'error' => 0,
                   'recognised' => 0];

        $rows = $DB->get_records_sql(
            "SELECT id, qualificationcode
               FROM {local_rtocompliance_recognition}
              WHERE state = :unknown
                AND (registerchecked IS NULL OR registerchecked < :runstart)
           ORDER BY qualificationcode",
            ['unknown' => recognition::STATE_UNKNOWN, 'runstart' => $runstart],
            0, max(1, $limit));

        foreach ($rows as $row) {
            $res = self::classify($row->qualificationcode);
            $counts['total']++;
            if (isset($counts[$res['result']])) {
                $counts[$res['result']]++;
            }
            if ($res['state'] === recognition::STATE_RECOGNISED) {
                $counts['recognised']++;
            }
            if ($progress !== null) {
                $progress($res);
            }
        }
        return $counts;
    }

    /**
     * How many codes this run still has to attempt.
     *
     * Counts unclassified codes NOT yet attempted since $runstart, which is what the
     * progress bar must measure against - not the raw UNKNOWN count, because codes
     * that errored stay UNKNOWN and would make the bar stall at a non-zero number
     * forever.
     *
     * @param int $runstart Unix time the run began.
     * @return int
     */
    public static function count_pending(int $runstart): int {
        global $DB;

        return (int)$DB->count_records_select('local_rtocompliance_recognition',
            'state = :unknown AND (registerchecked IS NULL OR registerchecked < :runstart)',
            ['unknown' => recognition::STATE_UNKNOWN, 'runstart' => $runstart]);
    }

    /**
     * Make sure every qualification code the site uses has a recognition row.
     *
     * Run before classify_all() so a code that exists only on an enrolment is not
     * invisible. Creates UNKNOWN rows only; changes nothing that exists.
     *
     * @return int Rows created.
     */
    public static function discover_codes(): int {
        global $DB;

        $created = 0;
        $sources = [
            "SELECT DISTINCT programcode AS c FROM {local_rtocompliance_enrolments}
              WHERE programcode IS NOT NULL AND programcode <> ''",
            "SELECT DISTINCT qualificationcode AS c FROM {local_rtocompliance_courses}
              WHERE qualificationcode IS NOT NULL AND qualificationcode <> ''",
            "SELECT DISTINCT qualcode AS c FROM {local_rtocompliance_avetmiss_programme}
              WHERE qualcode IS NOT NULL AND qualcode <> ''",
            "SELECT DISTINCT qualificationcode AS c FROM {local_rtocompliance_qualbuilder}
              WHERE qualificationcode IS NOT NULL AND qualificationcode <> ''",
            // v6.4.3: THE COURSE MAP. This is the plugin's authoritative
            // qualification-to-Moodle-course tree - one row per course, carrying the
            // qual code and unit code, seeded from Qual Builder links plus category
            // detection, and what every runtime completion and certificate path
            // already reads. Leaving it out meant a qualification that the site has
            // fully mapped but has no enrolments against yet was INVISIBLE to Program
            // Recognition, so it could be neither recognised nor excluded.
            "SELECT DISTINCT qualcode AS c FROM {local_rtocompliance_course_map}
              WHERE qualcode IS NOT NULL AND qualcode <> ''",
            // The category-to-qualification map, for the same reason.
            "SELECT DISTINCT qualcode AS c FROM {local_rtocompliance_qualmap}
              WHERE qualcode IS NOT NULL AND qualcode <> ''",
        ];
        foreach ($sources as $sql) {
            try {
                foreach ($DB->get_recordset_sql($sql) as $row) {
                    $code = recognition::normalise_code($row->c);
                    if ($code === '') {
                        continue;
                    }
                    if (!$DB->record_exists('local_rtocompliance_recognition',
                            ['qualificationcode' => $code])) {
                        recognition::ensure_known($code);
                        $created++;
                    }
                }
            } catch (\Throwable $e) {
                // A table this site does not have is not a failure of discovery.
                continue;
            }
        }
        return $created;
    }
}
