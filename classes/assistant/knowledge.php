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

namespace local_rtocompliance\assistant;

defined('MOODLE_INTERNAL') || die();

/**
 * Assistant knowledge sources (v6.3.14).
 *
 * Before this class the assistant's knowledge lived entirely in hand-maintained PHP arrays,
 * which had two consequences. Prose describing behaviour had to be edited in three separate
 * places and nothing enforced it, so the help text drifted away from the code with every
 * release — after v6.3.13 the Generate by Qualification help still said "tick each one you
 * want to certify" even though held students can no longer be ticked. And the assistant had
 * no access whatsoever to the site it was installed on, so it could describe the software in
 * general but could never answer "why can't I issue for this student".
 *
 * Three sources fix that:
 *
 *  - {@see docs()}          — a docs/*.md tree, the authoritative narrative documentation.
 *                             Plain markdown, editable by anyone, no PHP escaping.
 *  - {@see release_notes()} — parsed straight out of version.php, so EVERY future release
 *                             documents itself to the assistant with no separate doc edit.
 *                             This is what makes the knowledge auto-update per version.
 *  - {@see site_facts()}    — read-only facts about this specific site: which required RTO
 *                             details are unset, how many students hold a verified USI, what
 *                             is sitting in the autocert queue, and the record behind the
 *                             page the admin is looking at.
 *
 * Everything here is read-only and cached. Nothing in this class writes.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class knowledge {

    /**
     * Hard ceiling on the assembled knowledge base, in characters.
     *
     * This is sent with EVERY question, so it is a per-question cost, not a one-off. Measured
     * against the real files: the v6.3.13 base assembled to ~115k characters, and v6.3.14 to
     * ~134k with page context (~136k worst case). The bulk of that is the FAQ and support-centre
     * blocks, which is why they are now assembled LAST — a cut takes them rather than the live
     * site facts, the page's own documentation, or the answering rules.
     *
     * 160k is a backstop against a runaway document, not a working limit: nothing in the
     * shipped content comes close. Do not lower it below the measured size without moving
     * content out of the prompt first — an earlier 65k value silently truncated every section
     * this release added, on every question.
     *
     * @var int
     */
    public const KB_MAX_CHARS = 160000;

    /** @var int Longest single release note kept, in characters. */
    private const RELEASE_NOTE_MAX = 650;

    /** @var int How many releases back to describe. */
    private const RELEASE_LIMIT = 10;

    /**
     * The docs/*.md tree, parsed and cached.
     *
     * Each file's first level-1 heading is its title. An optional HTML comment of the form
     * `<!-- pages: generate_qual_certs.php, qual_cert_hub.php -->` anywhere in the file
     * associates it with plugin pages, which is what lets the knowledge base send the FULL
     * text of the document relevant to the page the admin is on and only a one-line summary
     * of the rest. A `<!-- summary: ... -->` comment overrides the derived summary.
     *
     * @return array<int, array{file:string,title:string,summary:string,pages:string[],body:string}>
     */
    public static function docs(): array {
        $dir = self::plugin_dir() . '/docs';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md');
        if ($files === false || empty($files)) {
            return [];
        }
        sort($files);

        // Key the cache on the file set AND their modification times, so editing a doc on
        // disk takes effect on the next question without a cache purge.
        $stamp = [];
        foreach ($files as $file) {
            $stamp[] = basename($file) . ':' . (int) @filemtime($file) . ':' . (int) @filesize($file);
        }
        // ONE key, with the stamp INSIDE the payload. Hashing the stamp into the key instead
        // would mint a brand-new entry on every documentation edit and never reclaim the old
        // ones — correct, but the store grows without bound on a site where the docs are
        // actively worked on.
        $stampsig = sha1(implode('|', $stamp));
        $cached   = self::cache_get('docs');
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stampsig && isset($cached['docs'])) {
            return $cached['docs'];
        }

        $docs = [];
        foreach ($files as $file) {
            $body = @file_get_contents($file);
            if ($body === false || trim($body) === '') {
                continue;
            }
            $body = str_replace(["\r\n", "\r"], "\n", $body);

            $title = basename($file, '.md');
            if (preg_match('/^#\s+(.+)$/m', $body, $m)) {
                $title = trim($m[1]);
            }

            // ANCHORED (/m + ^...$): a directive is a comment that is the WHOLE line. Without
            // the anchors, docs/README.md — which documents the syntax by writing it out —
            // bound itself to the pages in its own example and then had the example deleted
            // from its body by the strip below. The unanchored /s form was also latently
            // dangerous: one unterminated comment would swallow the file to the next '-->'.
            $pages = [];
            if (preg_match('/^<!--\s*pages:\s*(.+?)\s*-->\s*$/im', $body, $m)) {
                foreach (explode(',', $m[1]) as $p) {
                    $p = trim($p);
                    if ($p !== '' && preg_match('/^[a-z0-9_\-]+\.php$/i', $p)) {
                        $pages[] = $p;
                    }
                }
            }

            $summary = '';
            if (preg_match('/^<!--\s*summary:\s*(.+?)\s*-->\s*$/im', $body, $m)) {
                $summary = trim(preg_replace('/\s+/', ' ', $m[1]));
            } else {
                // First non-heading, non-comment, non-blank line.
                foreach (explode("\n", $body) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '<!--') === 0) {
                        continue;
                    }
                    $summary = preg_replace('/\s+/', ' ', $line);
                    break;
                }
            }

            // Strip the directive comments — they are metadata, not content.
            $clean = preg_replace('/^<!--\s*(?:pages|summary):.*?-->\s*$/im', '', $body);

            $docs[] = [
                'file'    => basename($file),
                'title'   => $title,
                'summary' => \core_text::substr((string) $summary, 0, 300),
                'pages'   => $pages,
                'body'    => trim((string) $clean),
            ];
        }

        self::cache_set('docs', ['stamp' => $stampsig, 'docs' => $docs]);
        return $docs;
    }

    /**
     * Release notes, parsed out of version.php.
     *
     * This is the mechanism that keeps the assistant current without anyone remembering to
     * write documentation: the release note is already mandatory on every build, so parsing
     * it means the next version explains itself the moment it is installed.
     *
     * version.php declares the current release plus a long tail of `$plugin->release_prev`
     * lines, each with its note in a trailing comment. PHP is last-assignment-wins so only
     * the final release_prev matters at runtime, but every line is a genuine historical note
     * and they are listed newest-first.
     *
     * @param  int $limit how many releases to return
     * @return array<int, array{version:string,note:string}>
     */
    public static function release_notes(int $limit = self::RELEASE_LIMIT): array {
        $path = self::plugin_dir() . '/version.php';
        if (!is_readable($path)) {
            return [];
        }

        $stampsig = sha1((int) @filemtime($path) . ':' . (int) @filesize($path) . ':' . $limit);
        $cached   = self::cache_get('relnotes');
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stampsig && isset($cached['notes'])) {
            return $cached['notes'];
        }

        $src = (string) @file_get_contents($path);
        if ($src === '') {
            return [];
        }
        $src = str_replace(["\r\n", "\r"], "\n", $src);

        $out  = [];
        $seen = [];
        // Matches both $plugin->release and $plugin->release_prev, with the note in the
        // trailing // comment. Notes routinely contain apostrophes, so the version string is
        // captured from the single-quoted literal and the note from everything after '//'.
        $pattern = '/^\$plugin->release(?:_prev)?\s*=\s*\'([^\']+)\'\s*;\s*\/\/\s*(.*)$/m';
        if (preg_match_all($pattern, $src, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $version = trim($m[1]);
                $note    = trim($m[2]);
                if ($version === '' || $note === '' || isset($seen[$version])) {
                    continue;
                }
                // Strip the leading "vX.Y.Z " the notes conventionally repeat.
                $note = preg_replace('/^v?' . preg_quote($version, '/') . '\s+/i', '', $note);
                // Some notes carry a "PREV: ..." tail describing an earlier release. It used
                // to be stripped on the assumption that every such release has its own line
                // further down; that is not always true (v6.3.8 exists only inside v6.3.9's
                // tail). The tail is now kept, though in practice RELEASE_NOTE_MAX usually
                // truncates before reaching it — this is deliberately a summary of recent
                // releases, not a complete changelog.
                $note = trim(preg_replace('/\s+/', ' ', (string) $note));
                if ($note === '') {
                    continue;
                }
                $seen[$version] = true;
                // Mark a truncated note, so the model can tell "that is all there was" from
                // "the rest was cut" and does not answer confidently from half a sentence.
                if (\core_text::strlen($note) > self::RELEASE_NOTE_MAX) {
                    $note = \core_text::substr($note, 0, self::RELEASE_NOTE_MAX) . '… [note truncated]';
                }
                $out[] = ['version' => $version, 'note' => $note];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        self::cache_set('relnotes', ['stamp' => $stampsig, 'notes' => $out]);
        return $out;
    }

    /**
     * Read-only facts about THIS site.
     *
     * Returns plain strings ready to drop into the prompt. Every query is wrapped: a broken
     * or partially-upgraded table must degrade the assistant's context, never break the
     * assistant. Cached for two minutes — an admin asking three follow-up questions about
     * the same problem should not re-run the counts three times.
     *
     * @return string[] one fact per line, already phrased for the prompt
     */
    public static function site_facts(): array {
        global $DB;

        $cachekey = 'sitefacts';
        $cached   = self::cache_get($cachekey);
        if (is_array($cached) && isset($cached['facts'], $cached['expires'])
                && is_array($cached['facts']) && $cached['expires'] > time()) {
            return $cached['facts'];
        }

        $facts = [];

        // ── RTO identity: the single most common reason issuance is refused site-wide ──
        try {
            $missing = \local_rtocompliance_missing_cert_settings();
            if (!empty($missing)) {
                $facts[] = 'RTO details NOT configured: ' . implode(', ', $missing)
                    . '. While these are unset, EVERY Testamur, Record of Results and Statement of '
                    . 'Attainment is refused before any credit is charged. Fix in RTO Settings.';
            } else {
                $facts[] = 'RTO details (legal name, national provider code, authorised signatory) are configured.';
            }
        } catch (\Throwable $e) {
            self::debug('site_facts: RTO settings check failed', $e);
        }

        // ── USI health — the reason a specific student cannot be issued ──
        try {
            $total = (int) $DB->count_records('local_rtocompliance_students');
            if ($total > 0) {
                $verified = (int) $DB->count_records('local_rtocompliance_students',
                    ['usiverified' => \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED]);
                $blank = (int) $DB->count_records_select('local_rtocompliance_students',
                    $DB->sql_isempty('local_rtocompliance_students', 'usi', true, true) . ' OR usi IS NULL');
                $facts[] = 'Students on this site: ' . $total . '. USI verified with the Registry: ' . $verified
                    . '. No USI recorded at all: ' . $blank . '. The remaining '
                    . max(0, $total - $verified - $blank) . ' have a USI on file that is not yet verified.';
                $facts[] = 'A student is only issuable for a Testamur, Record of Results or Statement of '
                    . 'Attainment when their USI is VERIFIED — a USI that is merely present is not enough.';
            }
        } catch (\Throwable $e) {
            self::debug('site_facts: USI counts failed', $e);
        }

        // ── Certificates issued ──
        try {
            $certs = $DB->get_records_sql(
                "SELECT certtype, COUNT(*) AS n
                   FROM {local_rtocompliance_certs}
                  WHERE status = 'issued'
               GROUP BY certtype"
            );
            if (!empty($certs)) {
                $parts = [];
                foreach ($certs as $c) {
                    $parts[] = $c->certtype . ': ' . (int) $c->n;
                }
                $facts[] = 'Certificates issued on this site by type — ' . implode(', ', $parts) . '.';
            } else {
                $facts[] = 'No certificates have been issued on this site yet.';
            }
        } catch (\Throwable $e) {
            self::debug('site_facts: cert counts failed', $e);
        }

        // ── Autocert queue ──
        try {
            $pending = (int) $DB->count_records('local_rtocompliance_autocerts', ['status' => 'pending']);
            if ($pending > 0) {
                $facts[] = $pending . ' student(s) are sitting in the certificate queue with status '
                    . 'pending. A student held for want of a verified USI stays pending on purpose so the '
                    . 'row is not closed over a missing certificate — but NOTHING issues it automatically. '
                    . 'Once the USI is verified, someone must run Process Queue on that qualification\'s '
                    . 'Detail page in the Qualification Certificate Hub, or re-run the generation page.';
            }
        } catch (\Throwable $e) {
            self::debug('site_facts: autocert count failed', $e);
        }

        // ── Qualifications ──
        try {
            $quals = (int) $DB->count_records('local_rtocompliance_qualbuilder', ['status' => 'active']);
            $facts[] = $quals . ' active qualification(s) in the Qualification Builder.';
        } catch (\Throwable $e) {
            self::debug('site_facts: qual count failed', $e);
        }

        // ── Student profile gate ──
        try {
            $gate = \get_config('local_rtocompliance', 'enforceprofile');
            $facts[] = 'The student profile completion gate is currently '
                . (((string) $gate === '0' || $gate === false) ? 'OFF' : 'ON') . '.';
        } catch (\Throwable $e) {
            self::debug('site_facts: profile gate check failed', $e);
        }

        self::cache_set($cachekey, ['facts' => $facts, 'expires' => time() + 120]);
        return $facts;
    }

    /**
     * Facts about the specific record the admin is looking at.
     *
     * The widget sends the ids of the record on screen, so when someone asks "why can't I
     * issue this?" while sitting on a qualification's generation page, the assistant can
     * answer about THAT qualification instead of in the abstract.
     *
     * @param  string $script basename of the current page, e.g. 'generate_qual_certs.php'
     * @param  array  $params whitelisted integer parameters lifted from the page URL
     * @return string[] one fact per line
     */
    public static function page_facts(string $script, array $params): array {
        global $DB;

        // The parameters come from the browser, so they are a claim about what is on screen
        // rather than proof of it. Only honour a userid on pages where a userid genuinely
        // identifies the record being viewed — otherwise the widget on any page becomes a
        // lookup for any user id. Everyone who can reach the assistant already holds a
        // capability that lets them read these students, so this bounds scope rather than
        // preventing disclosure.
        $userpages = [
            'student_profile.php', 'student_enrolments.php', 'students.php',
            'generate_qual_certs.php', 'generate_course_certs.php', 'qual_cert_hub.php',
            'qualbuilder_results.php', 'issue_certificate.php', 'soa_issue.php',
            'certificates.php', 'usi_settings.php',
        ];

        $facts  = [];
        $qualid = (int) ($params['qualid'] ?? 0);
        $userid = in_array($script, $userpages, true) ? (int) ($params['userid'] ?? 0) : 0;

        try {
            if ($qualid > 0 && in_array($script, ['generate_qual_certs.php', 'qual_cert_hub.php'], true)) {
                $qual = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualid],
                    'id, qualificationcode, qualificationname', IGNORE_MISSING);
                if ($qual) {
                    $facts[] = 'The admin is looking at qualification ' . $qual->qualificationcode
                        . ' — ' . $qual->qualificationname . '.';
                }
            }

            if ($userid > 0) {
                // Not IGNORE_MULTIPLE: with more than one student row that silently picks one,
                // so the assistant could report "verified, can be issued" while the generation
                // page shows the row disabled as a duplicate. Detect it instead.
                $sturows = $DB->get_records('local_rtocompliance_students', ['userid' => $userid],
                    'id ASC', 'id, usi, usiverified');
                $stu     = empty($sturows) ? false : reset($sturows);
                // fullname() must get a COMPLETE user object: called with a partial one it
                // emits a debugging notice per alternate-name field on developer-mode sites.
                $user = \core_user::get_user($userid, '*', IGNORE_MISSING);
                $name = $user ? \fullname($user) : ('user #' . $userid);
                if (count($sturows) > 1) {
                    $facts[] = $name . ' has MORE THAN ONE RTO Compliance student record, so their USI '
                        . 'cannot be resolved reliably and certificate issuance is held. Merge or remove '
                        . 'the duplicate in Student Records.';
                } else if (!$stu) {
                    $facts[] = $name . ' has no RTO Compliance student record, so no USI is on file and no '
                        . 'nationally recognised certificate can be issued for them.';
                } else if (trim((string) $stu->usi) === '') {
                    $facts[] = $name . ' has NO USI recorded. That is why they cannot be selected for a '
                        . 'Testamur or Record of Results. No credits are charged for a refused certificate.';
                } else if (!\local_rtocompliance_usi_is_verified($stu->usiverified)) {
                    $facts[] = $name . ' has a USI on file but it is NOT verified with the USI Registry, '
                        . 'so certificate issuance is refused until it is verified.';
                } else {
                    $facts[] = $name . ' has a verified USI and can be issued certificates.';
                }
            }
        } catch (\Throwable $e) {
            self::debug('page_facts failed', $e);
        }

        return $facts;
    }

    /**
     * The page-URL ids the assistant can do something useful with.
     *
     * @return string[]
     */
    public static function page_param_keys(): array {
        return ['qualid', 'courseid', 'userid', 'studentid', 'certid'];
    }

    /**
     * Reduce whatever the widget sent to a whitelist of positive integer ids.
     *
     * Deliberately narrow. v6.3.14 accepted the page's whole query string and parsed it here,
     * which forced an untyped request parameter; the values were only ever used as integers,
     * so there was no reason for the unfiltered string to cross the boundary at all. Each id
     * is now read with clean_param(PARAM_INT) and anything else is dropped silently — an
     * unexpected key, a non-scalar, a zero or a negative id yields nothing.
     *
     * @param  array $input decoded JSON, untrusted
     * @return array<string,int>
     */
    public static function filter_page_params(array $input): array {
        $out = [];
        foreach (self::page_param_keys() as $key) {
            if (!isset($input[$key]) || !is_scalar($input[$key])) {
                continue;
            }
            $val = (int) \clean_param($input[$key], PARAM_INT);
            if ($val > 0) {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /**
     * Is the assistant allowed to see this site's own data?
     *
     * Site facts are sent to the assistant broker along with the question. That is the RTO's
     * own data going to the RTO's own platform, but it is still a choice an administrator
     * should be able to make, so it has a setting. Default on: without it the assistant
     * cannot answer the questions admins actually ask.
     *
     * @return bool
     */
    public static function site_context_enabled(): bool {
        $val = \get_config('local_rtocompliance', 'assistant_site_context');
        return ($val === false) || ((string) $val !== '0');
    }

    /**
     * Absolute path to the plugin directory.
     *
     * @return string
     */
    private static function plugin_dir(): string {
        return dirname(dirname(__DIR__));
    }

    /**
     * Read from the assistant knowledge cache.
     *
     * @param  string $key
     * @return mixed|null null when absent or unavailable
     */
    private static function cache_get(string $key) {
        try {
            $cache = \cache::make('local_rtocompliance', 'assistant_kb');
            $val   = $cache->get($key);
            return ($val === false) ? null : $val;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Write to the assistant knowledge cache.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    private static function cache_set(string $key, $value): void {
        try {
            $cache = \cache::make('local_rtocompliance', 'assistant_kb');
            $cache->set($key, $value);
        } catch (\Throwable $e) {
            // A cache that will not write is a performance problem, not a correctness one.
            self::debug('assistant KB cache write failed', $e);
        }
    }

    /**
     * Developer-mode diagnostic.
     *
     * @param  string     $context
     * @param  \Throwable $e
     * @return void
     */
    private static function debug(string $context, \Throwable $e): void {
        \debugging('local_rtocompliance assistant knowledge — ' . $context . ': ' . $e->getMessage(),
            DEBUG_DEVELOPER);
    }
}
