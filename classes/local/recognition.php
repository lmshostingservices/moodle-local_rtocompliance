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
 * RTO Compliance plugin — is a qualification code nationally recognised training?
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\local;

defined('MOODLE_INTERNAL') || die();

/**
 * THE single answer to "is this program nationally recognised training?".
 *
 * WHY THIS CLASS EXISTS (v6.3.36):
 * Before it there were THREE disagreeing routes and no reconciliation between
 * any of them:
 *
 *  1. NAT import read the VET flag from the last character of the NAT00030
 *     record (data_import.php:1720) into {avetmiss_programme}.isvetprog.
 *     AVETMISS Release 8.0 DELETED the VET flag from NAT00030, so on any
 *     current file that character is a space and the flag lands NULL. A new
 *     RTO importing a conformant file therefore learns nothing.
 *  2. A Moodle course carried {courses}.nationallyrecognised, an int
 *     defaulting to 0, set only by a human ticking a box.
 *  3. When that flag was empty, lib.php fell back to a REGEX on the course
 *     fullname/shortname, treating a leading Australian unit code as proof of
 *     accreditation.
 *
 * Nothing read more than one of them. The measured consequence on a live site:
 * zero courses had recognition recorded, and 1,064 students enrolled only in
 * non-accredited short courses were being chased for a USI they do not need —
 * 57% of that site's entire no-USI backlog.
 *
 * THE DESIGN, and why each part is the way it is:
 *
 * - **Accreditation is a fact, not a preference.** A code either appears on the
 *   National Register or it does not. So the primary source is a
 *   training.gov.au lookup, not a checkbox and never a regex. A guess must
 *   never feed a compliance decision.
 *
 * - **Three states, not a boolean.** A boolean cannot distinguish "we know this
 *   is not accredited" from "nobody has said yet". Collapsing the second into
 *   the first is precisely the defect above: every unclassified program
 *   silently asserted a definite answer. STATE_UNKNOWN is a real, reportable
 *   state that blocks nothing and claims nothing.
 *
 * - **A lookup that finds nothing does NOT mean not-recognised.** The register
 *   may be unreachable, the code may be superseded, the RTO may use an internal
 *   code. `registerresult` records what the lookup said; `state` stays unknown.
 *   Only a human may assert STATE_NOT_RECOGNISED, and it is recorded with who
 *   and when.
 *
 * - **One row per code, for every code.** Not on {qualbuilder}: that table is
 *   about unit packaging and on the live site covers only 4 of 15 programs. A
 *   code with no Qual Builder setup still needs an answer.
 */
class recognition {
    /** Nobody has established this code's status. Blocks nothing, reported everywhere. */
    const STATE_UNKNOWN = 'unknown';

    /** Nationally recognised training. AVETMISS-reportable, USI required. */
    const STATE_RECOGNISED = 'recognised';

    /** Deliberately asserted as NOT nationally recognised. No NAT, no USI. */
    const STATE_NOT_RECOGNISED = 'not_recognised';

    /** Set by a training.gov.au lookup. */
    const SOURCE_REGISTER = 'register';

    /** Set by a person, with setby/settime recorded. Outranks the register. */
    const SOURCE_MANUAL = 'manual';

    /** Migrated from the old courses.nationallyrecognised / isvetprog flags. */
    const SOURCE_LEGACY = 'legacy';

    /** No state has been established. */
    const SOURCE_NONE = 'none';

    /**
     * Every valid state.
     *
     * @return string[]
     */
    public static function get_states(): array {
        return [self::STATE_UNKNOWN, self::STATE_RECOGNISED, self::STATE_NOT_RECOGNISED];
    }

    /**
     * Does the recognition table exist yet?
     *
     * THE UPGRADE WINDOW. On a live site the plugin's code is in place BEFORE the
     * upgrade step that creates this table: that is the gap between unzipping the
     * plugin and clicking Upgrade, and on a busy site somebody is using the plugin
     * during it. Every method here that names the table would otherwise throw a DML
     * exception, which reached the operator as 'Error reading from database' on
     * Student Records - measured by dropping the table and loading the page.
     *
     * Cached per request: the answer cannot change mid-request, and an uncached
     * check would add a schema query to every row of every student query.
     *
     * @return bool
     */
    public static function table_ready(): bool {
        global $DB;
        static $ready = null;

        if ($ready === null) {
            try {
                $ready = $DB->get_manager()->table_exists('local_rtocompliance_recognition');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * Normalise a qualification code to the stored form.
     *
     * One place, so a lookup can never miss because of case or padding.
     *
     * @param string|null $code
     * @return string Empty string when there is nothing usable.
     */
    public static function normalise_code($code): string {
        return strtoupper(trim((string)$code));
    }

    /**
     * The recognition state of a qualification code.
     *
     * Returns STATE_UNKNOWN for a code with no row, which is the correct answer
     * rather than a failure: an unclassified program genuinely has no
     * established status, and every caller must handle that case explicitly.
     *
     * @param string $code Qualification/program code.
     * @return string One of the STATE_* constants.
     */
    public static function get_state(string $code): string {
        global $DB;

        $code = self::normalise_code($code);
        if ($code === '' || !self::table_ready()) {
            return self::STATE_UNKNOWN;
        }
        $state = $DB->get_field('local_rtocompliance_recognition', 'state',
            ['qualificationcode' => $code], IGNORE_MISSING);

        // An unrecognised stored value is treated as unknown rather than
        // trusted. The column is constrained by this class, but a hand-edited
        // row must not be able to assert something the code does not define.
        if ($state === false || !in_array((string)$state, self::get_states(), true)) {
            return self::STATE_UNKNOWN;
        }
        return (string)$state;
    }

    /**
     * Is this code nationally recognised training, and therefore in scope for NCVER?
     *
     * NOT CURRENTLY WIRED INTO THE EXPORT, AND THAT IS DELIBERATE (v6.4.4).
     *
     * This method's docblock used to say "an unclassified program is not exported".
     * That was false. nat_generator.php does not consult this class at all - it never
     * has - so nothing was ever held back, and the claim also appeared in the upgrade
     * banner, the classification page, the Student Records tooltip and the CLI help.
     * All of those are corrected.
     *
     * The decision, on finding that out: LEAVE THE EXPORT ALONE. Silently omitting
     * delivered training from a statutory return is a worse failure than reporting
     * it - under-reporting is itself a breach, and it is invisible until an auditor
     * finds the gap, whereas a wrongly-included program is visible in the file. So
     * unclassified programs are reported, and raised as a WARNING in AVETMISS
     * Validation (nat_validate.php) where a person sees them immediately before
     * lodging. A human decides; the plugin does not decide silently in either
     * direction.
     *
     * THE USI GATE IS DIFFERENT, ON PURPOSE. See usi_required_sql(): an unclassified
     * program still generates a USI chase, because there the asymmetry is reversed -
     * an unnecessary chase is wasted effort, a missing required USI is a breach.
     *
     * @param string $code
     * @return bool True only for a code established as nationally recognised.
     */
    public static function requires_avetmiss(string $code): bool {
        return self::get_state($code) === self::STATE_RECOGNISED;
    }

    /**
     * The full recognition record, or null when the code has never been seen.
     *
     * @param string $code
     * @return \stdClass|null
     */
    public static function get_record(string $code): ?\stdClass {
        global $DB;

        $code = self::normalise_code($code);
        if ($code === '' || !self::table_ready()) {
            return null;
        }
        $r = $DB->get_record('local_rtocompliance_recognition',
            ['qualificationcode' => $code], '*', IGNORE_MISSING);
        return $r ?: null;
    }

    /**
     * Record a human decision about a code. Outranks any register result.
     *
     * This is the only way STATE_NOT_RECOGNISED can be set, and the only way a
     * register result can be overridden. Who and when are stored so the
     * decision is auditable — an ASQA auditor asking why a program was left out
     * of a submission gets a name and a date, not a shrug.
     *
     * @param string $code Qualification/program code.
     * @param string $state One of the STATE_* constants.
     * @param string $notes Why. Shown on the classification report.
     * @param int|null $userid Defaults to the current user.
     * @return bool True on success.
     */
    public static function set_manual(string $code, string $state,
                                      string $notes = '', ?int $userid = null): bool {
        global $DB, $USER;

        $code = self::normalise_code($code);
        if ($code === '' || !in_array($state, self::get_states(), true)
                || !self::table_ready()) {
            return false;
        }
        $now = time();
        $existing = self::get_record($code);
        $data = (object)[
            'qualificationcode' => $code,
            'state'             => $state,
            'source'            => self::SOURCE_MANUAL,
            'setby'             => $userid ?? (int)($USER->id ?? 0),
            'settime'           => $now,
            'notes'             => $notes,
            'timemodified'      => $now,
        ];
        if ($existing) {
            $data->id = $existing->id;
            return $DB->update_record('local_rtocompliance_recognition', $data);
        }
        $data->timecreated = $now;
        return (bool)$DB->insert_record('local_rtocompliance_recognition', $data);
    }

    /**
     * Record what a National Register lookup said about a code.
     *
     * A 'found' result sets STATE_RECOGNISED. Anything else records the attempt
     * and LEAVES THE STATE ALONE — an unreachable register or a superseded code
     * must not be able to assert that a program is unaccredited.
     *
     * A manual override is never overwritten, however the lookup turns out.
     *
     * @param string $code Qualification/program code.
     * @param string $result found|notfound|error
     * @param string|null $title Register title, when found.
     * @return string The state after recording.
     */
    public static function record_register_result(string $code, string $result,
                                                   ?string $title = null): string {
        global $DB;

        $code = self::normalise_code($code);
        if ($code === '') {
            return self::STATE_UNKNOWN;
        }
        if (!in_array($result, ['found', 'notfound', 'error'], true)) {
            $result = 'error';
        }
        if (!self::table_ready()) {
            return self::STATE_UNKNOWN;
        }
        $now = time();
        $existing = self::get_record($code);

        // A person's decision outranks the register. Record that the lookup
        // happened, but do not touch the state or the override metadata.
        $ismanual = $existing && $existing->source === self::SOURCE_MANUAL;

        $state = $existing->state ?? self::STATE_UNKNOWN;
        if (!$ismanual && $result === 'found') {
            $state = self::STATE_RECOGNISED;
        }

        $data = (object)[
            'qualificationcode' => $code,
            'state'             => $state,
            'source'            => $ismanual ? self::SOURCE_MANUAL
                                    : ($result === 'found' ? self::SOURCE_REGISTER
                                        : ($existing->source ?? self::SOURCE_NONE)),
            'registertitle'     => $result === 'found' ? $title : ($existing->registertitle ?? null),
            'registerchecked'   => $now,
            'registerresult'    => $result,
            'timemodified'      => $now,
        ];
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('local_rtocompliance_recognition', $data);
        } else {
            $data->timecreated = $now;
            $DB->insert_record('local_rtocompliance_recognition', $data);
        }
        return $state;
    }

    /**
     * Make sure a code has a row, so it appears on the classification report.
     *
     * Called when a code is first seen — by an import, a course setting, an
     * enrolment. Creates an UNKNOWN row and nothing more. Never changes an
     * existing row, so it is safe to call on every sighting.
     *
     * A code nobody has classified must be visible. Silently absent is how the
     * original defect stayed invisible for so long.
     *
     * @param string $code
     * @return bool True if a row now exists.
     */
    public static function ensure_known(string $code): bool {
        global $DB;

        $code = self::normalise_code($code);
        if ($code === '' || !self::table_ready()) {
            return false;
        }
        if ($DB->record_exists('local_rtocompliance_recognition', ['qualificationcode' => $code])) {
            return true;
        }
        $now = time();
        return (bool)$DB->insert_record('local_rtocompliance_recognition', (object)[
            'qualificationcode' => $code,
            'state'             => self::STATE_UNKNOWN,
            'source'            => self::SOURCE_NONE,
            'registerresult'    => 'never',
            'timecreated'       => $now,
            'timemodified'      => $now,
        ]);
    }

    /**
     * Has this site classified anything yet?
     *
     * NOT A GATE. It was briefly used as one - "behave as before until something is
     * classified" - and that created a cliff: the moment the FIRST program was
     * classified the gate switched on for every program, and students in the still-
     * unclassified ones silently dropped off the USI list. The gates now handle
     * "not yet known" correctly on their own and need no such switch.
     *
     * Retained because it is a useful thing to tell an administrator: a site where
     * nothing is recognised has not run the classification yet.
     *
     * @return bool
     */
    public static function is_active(): bool {
        global $DB;

        if (!self::table_ready()) {
            return false;
        }
        return $DB->record_exists('local_rtocompliance_recognition',
            ['state' => self::STATE_RECOGNISED]);
    }

    /**
     * SQL fragment: does this student need a USI at all?
     *
     * A USI attaches to nationally recognised training, so a student enrolled only
     * in programs the RTO has explicitly declared NOT nationally recognised does not
     * need one. On one live site that was 1,064 of a 1,875 no-USI backlog: 57% of the
     * list was people who never needed a USI, and the real 811 were buried in it.
     *
     * THE RISK DIRECTION, WHICH IS THE WHOLE POINT OF THIS METHOD (v6.3.36):
     * An UNCLASSIFIED program still generates a chase. That is deliberate and it is
     * the opposite of how the NAT gate treats unknown, because the two have opposite
     * asymmetries:
     *
     *   - Chasing a USI that was not needed wastes some staff and student time. It is
     *     annoying and it is recoverable.
     *   - NOT chasing a USI that WAS needed is a compliance breach, found by an
     *     auditor rather than by us.
     *
     * So "we have not established this yet" must land on the safe side, which for a
     * USI means keep asking. Only a person saying "this program is not nationally
     * recognised" stops the chase.
     *
     * A first version of this method required STATE_RECOGNISED instead, and it was
     * wrong twice over. On upgrade, with nothing yet classified, the USI Missing
     * count went straight to zero - measured: 5 students needing a USI showed as 0.
     * Guarding that with an "inert until something is classified" check then created
     * a CLIFF: the moment ONE program was classified, students in every still-
     * unclassified program silently dropped off the list. Measured: two students, one
     * program classified, and the other student vanished. Both faults come from the
     * same mistake - treating "not yet known" as "not required".
     *
     * A student with no enrolments at all is still counted, matching the behaviour
     * before this feature existed: they are a profiled learner and nothing has yet
     * established that they are exempt from needing a USI.
     *
     * Returns a correlated expression against the student alias supplied, so it drops
     * into an existing query without changing its shape:
     *
     *     $sql .= " AND (s.usi IS NULL OR s.usi = '') AND " . recognition::usi_required_sql('s');
     *
     * @param string $studentalias Alias of {local_rtocompliance_students} in the query.
     * @return string SQL boolean expression, safe to concatenate (no parameters).
     */
    public static function usi_required_sql(string $studentalias = 's'): string {
        // The alias is interpolated, so it is constrained to a plain identifier here.
        // Callers pass a literal, but this class must not become an injection route.
        if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/i', $studentalias)) {
            $studentalias = 's';
        }

        // THE UPGRADE WINDOW (v6.4.4). On a live site the code is in place BEFORE the
        // upgrade runs - that is the gap between unzipping the plugin and clicking
        // Upgrade, and on a busy site somebody is using it. During that gap this table
        // does not exist yet, and every caller of this method put the table name
        // straight into its WHERE clause, so Student Records rendered 'Error reading
        // from database'. Measured, not theorised: dropping the table and loading the
        // page produced exactly that.
        //
        // Returning '1=1' means "every student needs a USI", which is the same answer
        // the plugin gave before program recognition existed. It over-counts rather
        // than under-counts, and the correct answer arrives the moment the upgrade
        // runs. Guarding in the class rather than at each call site means a caller
        // added later cannot reintroduce the fault.
        if (!self::table_ready()) {
            return '1=1';
        }

        $no = self::STATE_NOT_RECOGNISED;
        return "(
                    NOT EXISTS (
                        SELECT 1 FROM {local_rtocompliance_enrolments} usiany
                         WHERE usiany.studentid = {$studentalias}.id
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM {local_rtocompliance_enrolments} usienr
                          LEFT JOIN {local_rtocompliance_recognition} usirec
                                 ON usirec.qualificationcode = UPPER(TRIM(usienr.programcode))
                         WHERE usienr.studentid = {$studentalias}.id
                           AND (usirec.state IS NULL OR usirec.state <> '{$no}')
                    )
                )";
    }

    /**
     * Does this one student need a USI?
     *
     * Same rule as usi_required_sql() - see there for why an unclassified program
     * still counts.
     *
     * @param int $studentid {local_rtocompliance_students}.id
     * @return bool
     */
    public static function student_requires_usi(int $studentid): bool {
        global $DB;

        // No table yet (upgrade window): the pre-recognition answer was "everyone
        // needs a USI", which over-counts rather than under-counts.
        if (!self::table_ready()) {
            return true;
        }
        if (!$DB->record_exists('local_rtocompliance_enrolments', ['studentid' => $studentid])) {
            return true;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_rtocompliance_enrolments} e
               LEFT JOIN {local_rtocompliance_recognition} r
                      ON r.qualificationcode = UPPER(TRIM(e.programcode))
              WHERE e.studentid = :sid
                AND (r.state IS NULL OR r.state <> :no)",
            ['sid' => $studentid, 'no' => self::STATE_NOT_RECOGNISED]);
    }

    /**
     * How many codes sit in each state — for the classification report and the
     * upgrade summary.
     *
     * @return array state => count, every state present even at zero.
     */
    public static function count_by_state(): array {
        global $DB;

        $out = array_fill_keys(self::get_states(), 0);
        if (!self::table_ready()) {
            return $out;
        }
        $rows = $DB->get_records_sql(
            'SELECT state, COUNT(*) AS n FROM {local_rtocompliance_recognition} GROUP BY state');
        foreach ($rows as $row) {
            if (isset($out[$row->state])) {
                $out[$row->state] = (int)$row->n;
            }
        }
        return $out;
    }

    /**
     * A one-line summary for logs and the upgrade output.
     *
     * @return string
     */
    public static function summarise(): string {
        $c = self::count_by_state();
        $total = array_sum($c);
        if ($total === 0) {
            return 'Program recognition: no qualification codes recorded yet.';
        }
        $s = "Program recognition: {$c[self::STATE_RECOGNISED]} nationally recognised, "
           . "{$c[self::STATE_NOT_RECOGNISED]} not recognised, "
           . "{$c[self::STATE_UNKNOWN]} UNCLASSIFIED";
        if ($c[self::STATE_UNKNOWN] > 0) {
            // NOTHING IS HELD BACK FROM A NAT FILE. This message used to say the
            // opposite, which was false: nat_generator.php does not consult this
            // class at all. Silently omitting delivered activity from a statutory
            // return would be a worse failure than reporting it, so unclassified
            // programs are reported and raised in AVETMISS Validation instead.
            $s .= ' - an unclassified program is STILL REPORTED in NAT files, and its '
                . 'students are counted as needing a USI. Classifying it is what makes '
                . 'the USI count and the certificate type correct, and clears the '
                . 'warning in AVETMISS Validation. See Reports > Program recognition.';
        }
        return $s . '.';
    }
}
