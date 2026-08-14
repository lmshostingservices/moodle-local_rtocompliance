## v6.3.0 — 14 Aug 2026

### Added — AVETMISS profile lock: students must complete their data before they can train

- **PROFILE-GATE** (`lib.php`): a student enrolled in nationally recognised training who is missing mandatory AVETMISS data is now **held** at *My AVETMISS Profile* — on login and on every page — until they complete it. v5.9.314 set a session flag that the first page load consumed, so a student could click straight past the prompt and train for months with no date of birth on file (which blocks USI verification, certificate issuance and the NAT00080 submission).
  - `local_rtocompliance_profile_gate_check()` performs the redirect from `local_rtocompliance_extend_navigation()` (after `require_login()`, before any output), with a `before_standard_head_html_generation` backstop for page layouts that never build the global navigation.
  - Nobody can be locked out: an allowlist covers login, logout, password reset, site policies, Moodle's own required-profile form, admin pages, `pluginfile.php`, AJAX and web services; site administrators, "log in as" sessions, and holders of the new `local/rtocompliance:bypassprofilegate` capability (manager, course creator, teacher archetypes) are never held; and a user who lacks `local/rtocompliance:editownprofile` — and so could not use the destination page — is skipped rather than bounced into a permissions error.
  - The allowlist is matched against the Moodle-root-relative script path, so a site installed in a subdirectory (or at `/admin/`) behaves correctly.
- **SHARED-FIELD-DEFINITION** (`lib.php`): the mandatory-field list and the "is this actually answered?" rule (AVETMISS `@`/`@@`/`@@@@` not-stated sentinels do not count) now live in one place — `local_rtocompliance_avetmiss_all_fields()`, `_avetmiss_mandatory_fields()`, `_avetmiss_value_missing()`, `_get_missing_avetmiss_fields()`, `_calculate_profilecomplete()` — and are used by the gate, `my_profile.php` and `student_profile.php`. The three paths previously each carried their own copy of the list and drifted apart.
- **USI-NOT-A-BARRIER**: the USI is deliberately **not** required by the lock by default. A student cannot obtain one on demand, so requiring it to reach the site would hold them with no way to comply. It remains part of the definition of a complete profile (and of certificate issuance), and an administrator can tick it on.
- **SCOPE** (`lib.php`): `local_rtocompliance_user_requires_avetmiss()` now also matches students holding AVETMISS enrolment records created through Qual Builder (not only courses carrying the legacy `nationallyrecognised` flag), but only where the row is in-training, the outcome is not final, and the student still holds an **active Moodle enrolment** in that course — so past students are never chased for data they can no longer affect.
- **LOCKED MODE** (`my_profile.php`, `classes/form/student_profile_form.php`): lists exactly which fields are outstanding and why they are required, enforces them in form validation, removes the dead-end Cancel button, and returns the student to the page they originally wanted once the profile is complete.
- **SETTINGS**: *RTO Settings → RTO details → Student data enforcement* — enable/disable the lock and choose which fields it requires. New capability string and 13 new language strings.
- **TESTS** (`tests/profile_gate_test.php`): 14 new PHPUnit tests covering the sentinel rules, the completeness calculation, the setting, and every guard condition.

### Fixed — USI Verification page: stat cards, filters, exports and paging

- **SCOPED-STATS** (`usi_settings.php`): the stat cards were whole-of-site totals that never moved when the admin filtered by category, course or search. They are now recomputed inside the current scope in a single grouped query, sit directly above the filters, and act as one-click status filters with a visible active state.
- **COUNT-CORRECTNESS**: "Not yet verified" counted `usiverified = 0` outright, so it included every student with no USI at all and could read *higher* than "Students with a USI" (6,119 vs 1,060 on a live site). Every status filter now also requires a USI to be present, so the buckets are mutually exclusive and add up: *students with a USI = verified + not yet verified + failed + manual review*.
- **DYNAMIC-COURSE-FILTER**: choosing a Category now genuinely narrows the Course dropdown. The previous implementation set `option.hidden`, which browsers ignore inside a native `<select>`; the list is now rebuilt from real option nodes, shows a count, and says so when a category has no courses.
- **Added**: Clear filters button, removable active-filter chips, a 25/50/100/200 per-page selector, sortable column headers (applied to the exports too), a PDF export of the filtered view alongside the CSV, a proper empty state, sticky table headers, and an out-of-range page guard.
- **DOB ROUND-TRIP**: the missing-DOB backfill is now an explicit two-step download/upload with the accepted column names and date formats stated on screen; the exported template's header is plain `Date of birth` (the importer still accepts the older heading). The postcode field is no longer pre-filled from `$USER->city` (a suburb name in a 4-digit column).
- **SECURITY**: the CSV and PDF exports now enforce `require_sesskey()`.
- No DB schema changes. Savepoint 2026081400.

### Fixed — plugin version numbering restored to correct Moodle format

- **VERSION-FORMAT** (`version.php`, `db/upgrade.php`, `BUILD.md`): Moodle plugin versions are `YYYYMMDDXX` — 8 date digits plus a 2-digit counter, **10 digits**. `version.php` was correct (`2026080600`), but 764 of the 792 savepoints in `db/upgrade.php` used a 13-digit `YYYYMMDD` + 5-digit build number (e.g. `2026080500663`), which is numerically ~200x larger than any valid 10-digit version.
  - Consequence: a site that ran those steps stored a version **higher** than the one `version.php` declares, so Moodle refused to upgrade the plugin again — *"Downgrade of local_rtocompliance is not supported"*. It also meant `$oldversion` never matched the guards, so every upgrade re-ran all 792 steps.
  - All 792 savepoints (and their matching `if ($oldversion < N)` guards) are renumbered to a strictly ascending 10-digit sequence, `2025120400` → `2026081400`, the last of which equals `$plugin->version`.
  - Two further latent faults fixed by the same pass: savepoint `2026042100056` was used **twice**, and several savepoints were **out of numeric order** in file order. Either throws `downgrade_exception` part-way through an upgrade, leaving the plugin half-migrated.
  - `BUILD.md` documented the wrong format (`YYYYMMDDNNN`, 3-digit sequence) — corrected, with a validator command that checks digit count, uniqueness, ordering and the version ceiling.
  - **`cli/normalise_version.php`** repairs a site already stranded on a 13-digit stored version (dry run by default, `--execute` to apply). Nothing inside the plugin can do this automatically, because Moodle compares versions before it runs any plugin code.

## v5.9.341 — 30 Jul 2026

### Changed — UI styling engine, getting-started navigation, and Moodle 4.4–5.3 support

- **COMPAT-4.4-5.3** (`version.php`): declared support widened to Moodle 4.4 → 5.3 (`$plugin->requires = 2024042200`, `$plugin->supported = [404, 503]`).
- **HOOK-MIGRATION** (`lib.php`): removed the legacy `local_rtocompliance_before_footer()` callback that double-registered the table JS already injected by the `before_footer_html_generation` hook (and threw the “should be migrated to hook” debug notice on 4.4/4.5).
- **INSTALL-XML-FIX** (`db/install.xml`): added the `local_rtocompliance_qualunit_courses` table so **fresh installs** build it (it previously existed only in `upgrade.php`, breaking new installs of Qual Builder, Cert Hub and Course Map).
- **CAP-LANG** (`lang/en`): added the 5 missing capability strings (`viewtrainer`, `viewcerts`, `viewstudents`, `viewreports`, `managecerttemplates`).
- **GETTING-STARTED-NAV** (`lib.php`): left menu reordered to a first-step → last-step onboarding flow; added USI Verification + Qual Certificate Hub (previously off-menu); removed the dead “Testing Engine” link; registered the missing `info`/`file-check`/`search` icons; fixed the active-item matcher to respect `?section=` query strings.
- **LIGHT-SIDEBAR** (`lib.php`): recoloured the sidebar from near-black (`#0d1424`; group labels failed WCAG at 1.77:1) to a modern light slate surface with AA-contrast text.
- **UI-ENGINE** (`styles.css`): appended a single unified styling engine — one canonical token `:root`, overflow-safe premium data-tables (fixes the last-column overlap), cross-Bootstrap-4/5 badge + spacing shims so components render identically on 4.4 and 5.x, and a 4-tier responsive system.
- **BS5-MODALS** (`certificates.php`, `generate_course_certs.php`): added `data-bs-toggle`/`data-bs-target`/`data-bs-dismiss` alongside the Bootstrap-4 attributes so the bulk-generate modals open on Moodle 5.x.
- No DB schema changes. Savepoint 2026073000341.

## v4.9.179 — 20 May 2026

### Fixed — Auto-enrol wizard now uses Moodle categories (qualifications) not courses

- **FIX-AUTOENROL-CATEGORIES** (`data_import.php`): The auto-enrol wizard now correctly reflects the Moodle structure used by Australian RTOs — where each **Moodle category = qualification** and each **Moodle course = unit of competency**.
  - The dropdown in Step 3 now lists **Moodle categories** instead of individual courses.
  - Selecting a category enrols students into **every visible course (unit) inside that category** automatically.
  - Server-side automatch now checks the qualification code against the category name and idnumber field.
  - The enrolment loop pre-fetches all unit courses and their manual enrolment instances up-front (no N+1 queries).
  - The diagnostic table gains a "Units" column showing how many unit courses were found in the selected category.
- No DB schema changes. Marker savepoint 2026052000249.

## v4.9.178 — 20 May 2026

### Improved — Auto-enrol course dropdown: qualifications shown first, all courses available

- **FIX-AUTOENROL-COURSE-GROUPS** (`data_import.php`): The course dropdown in the auto-enrol wizard now groups courses into two sections:
  - **Qualifications** — courses whose name or shortname contains an Australian qualification code (e.g. `MEM20413`, `BSB30120`). These appear at the top and are the usual target for enrolment from a NAT file.
  - **Other Moodle courses** — all remaining courses (individual units, resources, etc.) listed below for cases where direct unit enrolment is needed.
- Both sections are always visible so admins are never locked out of any course.
- A short help note below the dropdown explains the grouping.

No DB schema changes. Savepoint 2026052000248 (marker only).

---

## v4.9.177 — 20 May 2026

### Fixed — Auto-enrol: replaced broken JS combobox with native browser dropdown

- **FIX-AUTOENROL-NATIVE-SELECT** (`data_import.php`): The custom JavaScript combobox search box was silently failing on some Moodle installations — typing in the search box produced no dropdown at all, leaving every qualification card as "Will skip (no enrolment)" with zero enrolments happening. Root cause: the custom JS combobox relied on complex event listeners and dynamic DOM manipulation that failed silently on certain Moodle themes/configurations. Fix: replaced the entire custom combobox with a native HTML `<select>` element. The browser's built-in dropdown works on every Moodle installation, every theme, every browser — no JavaScript required for the course selection to work.
- **Server-side automatch**: if the qualification code (e.g. `MEM20413`) appears in a Moodle course name or shortname, that course is now **pre-selected** directly in the HTML — the card header shows green "✓ Will enrol into: ..." without needing any JavaScript to run.
- The simplified JS block only updates the card header badge on select change and shows a confirm dialog if the form is submitted with nothing selected — even if this JS fails, the form still submits correctly.

No DB schema changes. Savepoint 2026052000247 (marker only).

---

## v4.9.176 — 20 May 2026

### Fixed — Auto-enrol combobox: typing a course name now works correctly

- **FIX-AUTOENROL-COMBOBOX-TYPING** (`data_import.php`): The course search box on the enrolment wizard required the user to click an item from the dropdown list — typing the course name alone and then clicking away did nothing, leaving the card as "Will skip". This was invisible to users who expected typing to be sufficient. Three fixes applied:
  1. **Auto-select on blur**: if the user types text and clicks away, and exactly one course matches what they typed, it is automatically selected for them.
  2. **Enter key support**: pressing Enter while typing now selects the first result in the dropdown list.
  3. **Typing warning**: if the user types text but multiple (or no) matches exist and they click away without selecting, a clear amber warning appears: "No course selected — please click on a course name from the dropdown list."

No DB schema changes. Savepoint 2026052000246 (marker only).

---

## v4.9.175 — 20 May 2026

### Fixed — Session write-close destroying all diagnostic data (root cause of invisible skip report)

- **FIX-SESSION-WRITECLOSE** (`data_import.php`): `write_close()` was called at line 1553 — the very first line of the doenrol handler, **before** the enrolment loop ran and **before** the session write at line 1972. `write_close()` closes the PHP session file; any subsequent writes to `$SESSION` update the in-memory PHP array but are **silently not persisted** to storage. The result: skip report always empty, diagnostic table never visible, no way to know why 0 students were enrolled. Fix: removed the premature `write_close()`. The session stays open for the duration of the handler (a few seconds — the inner loop is pure in-memory hash lookups thanks to the pre-fetch optimisation), then Moodle's `redirect()` closes it normally.

- **DIAG-QUALCODE-MISMATCH** (`data_import.php`): When `clientids_db = 0` (the DB query finds no client IDs for the enrolment wizard's qualcode), the diagnostic table now expands with a supplementary amber row showing: (a) all distinct qualcodes actually stored in the DB for this import, (b) the total row count for this importid with no qualcode filter. This makes qualcode case/spacing mismatches immediately visible — if the wizard sends `MEM20413` but the DB stores `Mem20413 ` or a blank, the amber row will show it.

No DB schema changes. Savepoint 2026052000245 (marker only).

---

## v4.9.174 — 20 May 2026

### Added — Enrolment diagnostic table

- **DIAG-AUTOENROL** (`data_import.php`): A blue "Enrolment diagnostic" card now appears on the results page after every enrolment run. It shows a per-qualification table with columns: DB rows found, Phase 1 matched, Phase 2 fallback, Phase 2 created, Phase 2 create-fail, Phase 3 already-enrolled, Phase 3 enrolled, Phase 3 enrol-fail. Rows with zero DB rows are highlighted amber; rows with students found but zero enrolled are highlighted red. This makes it immediately obvious whether the problem is "no records found in the DB for that importid+qualcode" vs "records found but all skipped at a specific phase".

No DB schema changes. No AMD changes. Savepoint 2026052000244 (marker only).

---

## v4.9.173 — 20 May 2026

### Fixed — Silent enrolment failures now visible + placeholder-email collision

- **FIX-AUTOENROL-ENROLFAILED-VISIBLE** (`data_import.php`): `enrol_user()` exceptions were silently swallowed — only emitted to PHP error log via `debugging()` which is invisible unless `DEBUG_DEVELOPER` is on. The admin saw "0 enrolled, 0 skipped" with no indication anything went wrong. Fixed: exceptions are now captured in `$aeSkipped` with reason `enrolfailed` and the actual Moodle exception message. They appear in the on-screen skip report and the CSV download, making the failure immediately visible and actionable.

- **FIX-AUTOENROL-PLACEHOLDER-COLLISION** (`data_import.php`): On a second or subsequent import run, students with no email address had their `clientid@no-email.placeholder` address already stored in Moodle from the first run. `user_create_user()` was called again with the same placeholder email → duplicate-email exception → `createfailed` for every email-less student on re-import. Fixed: before creating a new account, check if `$useEmail` (real or placeholder) already exists in `$moodleUserByEmail`. If yes, use that existing account and skip to Phase 3.

No DB schema changes. No AMD changes. Savepoint 2026052000243 (marker only).

---

## v4.9.172 — 20 May 2026

### Fixed — Auto-enrolment matching fallback + results display

- **FIX-AUTOENROL-MATCH-FALLBACK** (`data_import.php`): Root cause of "0 students enrolled" discovered and fixed. In email-match mode, `$moodleUserByUsername` was set to `null`, so when email matching failed (student changed their email, old NAT data, etc.) the code jumped straight to `user_create_user()` with `username=clientid`. If that username already existed (from a previous import or a manually-created student account — extremely common), `user_create_user()` threw a duplicate-username exception → caught as `createfailed` → all students land in the skip report → 0 enrolments displayed. Fix: build **all three** lookup maps (`username`, `idnumber`, `email`) unconditionally regardless of match method. The matching loop now does a two-step match: (1) primary match using the admin-selected method (email or student ID), (2) cross-mode fallback — email mode also tries idnumber then username; studentid mode also tries email. `user_create_user()` is only attempted if **every** existing-account path has been exhausted. On most sites with existing student accounts this will completely eliminate `createfailed` errors.

- **FIX-AUTOENROL-RESULTS-BREAKDOWN** (`data_import.php`): Post-enrolment results card now shows a pill breakdown: "X matched by email", "Y matched by student ID (fallback)", "Z new accounts created", "W already enrolled". Easier to diagnose at a glance how students were resolved.

- **FIX-AUTOENROL-RESULTS-EMPTY** (`data_import.php`): Results card now shows a contextual message when 0 enrolments happen: distinguishes between "all already enrolled", "no courses selected" (go back and select), and "see skip report". Previously showed a generic green tick even when nothing happened.

- **UX-AUTOENROL-MATCHBANNER** (`data_import.php`): Step 3 page now shows an info banner explaining which match method is active and what it does. Email mode: explains email-first + student-ID fallback. Student ID mode: explains idnumber/username check + creation. Makes it clear to admins why some students might be created vs matched.

- **UX-AUTOENROL-EXPLAINER** (`data_import.php`): Step 3 explainer card rewritten with clearer heading "Step 3 — Enrol students into Moodle courses" and a success confirmation of how many students and enrolments were saved.

No DB schema changes. No AMD changes. Savepoint 2026052000242 (marker only).

---

## v4.9.171 — 20 May 2026

### Fixed — NAT00120 parser reading wrong columns for every field

- **BUG-NAT00120-FIELDPOS** (`data_import.php`): A previous "fix" in v4.9.126 incorrectly inserted a "Training organisation delivery location identifier" field at positions 10-19 in the NAT00120 parser — a field that does **not** exist at that position in the AVETMISS 8.0 standard. This shifted every subsequent field 10 positions too far into the line:
  - `clientid` was reading position 20-29 (the unit code) instead of 10-19 (the actual client ID)
  - `unitcode` was reading position 30-41 instead of 20-31
  - `qualcode` was reading position 42-51 instead of 32-41 — **reading the activity start date** instead of the TGA qualification code. Confirmed empirically: "0101200804" = start date `01/01/2008` + first 2 bytes of end date; "0101201308" = `01/01/2013` + `08`.
  - `startdate` was reading position 52-59 (the end date) instead of 42-49
  - `enddate`, `outcome`, `fundingsource`, `studyreason`, and `supervisedhours` were all similarly misaligned
  - Fixed all positions to match the correct AVETMISS 8.0 standard (NCVER spec, 0-indexed): clientid=10, unitcode=20, qualcode=32, startdate=42, enddate=50, outcome=58, fundingsource=60, studyreason=94, scheduled hours=130, hours attended=134.

**Impact**: Any import done with v4.9.126–v4.9.170 stored incorrect data in the enrolment table — qualcodes were start-date fragments, clientids were unit-code substrings, and student-to-enrolment matching was broken. Re-import after upgrading to v4.9.171 to get correctly parsed data.

No DB schema changes. No AMD changes. Savepoint 2026052000241 (marker only).

---

## v4.9.170 — 20 May 2026

### Fixed — Three bugs on the course-matching screen after NAT import

- **BUG-DOENROL-PLACEHOLDER** (`data_import.php`): The search input placeholder on the doenrol screen rendered as the literal text `\xe2\x80\xa6` instead of `…`. Root cause: the PHP string on line 2314 used single quotes — PHP only interprets `\xNN` hex escapes in double-quoted strings or heredocs; in single-quoted strings they are output literally. Fixed by replacing the raw bytes with the HTML entity `&#8230;` which is safe in any quoting context.

- **BUG-DOENROL-QUALCODE-LABEL** (`data_import.php`): Each qualification group card showed only a bare number badge (e.g. `0101200804`) with no explanation. These codes are Wisenet's internal Program Enrolment Identifiers stored at NAT00120 position 43–52 — not TGA qualification codes. No qualification name is available because NAT00080 is not parsed. Added a clear "Program code:" label before the badge so admins understand what the number represents.

- **BUG-DOENROL-AUTODETECT** (`data_import.php`): Clicking "Auto-Detect Courses" appeared to do nothing. The button click handler was working correctly and the result message was being set, but the message `<div>` sits above the qualification cards and was off-screen after the user scrolled down. Added `scrollIntoView({behavior:'smooth', block:'nearest'})` after revealing the message so it is always visible. Clarified the "no matches" text to explain that auto-detect only works when the Moodle course name or shortname includes the program code.

No DB schema changes. No AMD changes. Savepoint 2026052000240 (marker only).

---

## v4.9.169 — 20 May 2026

### Fixed — Database error on student names with accented characters

- **BUG-ENCODING-NATIMPORT** (`data_import.php`): MySQL `dml_write_exception — Incorrect string value: '\xE9'` when importing a NAT file containing a student with an accented name (e.g. "DRISCOLL, CHé"). AVETMISS NAT files from some SMS vendors (including Wisenet) are encoded in ISO-8859-1 / Windows-1252 rather than pure ASCII. The raw byte `\xE9` (latin-1 `é`) is not a valid UTF-8 sequence and MySQL's `utf8`/`utf8mb4` column rejects it. Fixed in `local_rtocompliance_nat_lines()`: after reading each line, `mb_check_encoding()` detects non-UTF-8 content and `mb_convert_encoding()` converts it from ISO-8859-1 to UTF-8. Lines that are already valid UTF-8 (the common case) are passed through unchanged — zero cost for standard exports.

No DB schema changes. No AMD changes. Savepoint 2026052000239 (marker only).

---

## v4.9.168 — 20 May 2026

### Fixed — Fatal memory exhaustion on large NAT file imports

- **BUG-MEMORY-NATIMPORT** (`data_import.php`): PHP fatal `Allowed memory size of 134217728 bytes exhausted` when uploading and importing large NAT exports (RTOs with 10 000+ students or 50 000+ enrolment rows). Root cause: `file_get_contents()` loaded the entire NAT file into a string, then `preg_split()` exploded it into a full PHP array of all lines at once. With multiple files processed in the same request (`$studentmap` + `$enrolments` + `$completions` arrays all growing simultaneously), peak memory easily exceeded the default 128 MB limit.

  Two-part fix:
  1. `raise_memory_limit(MEMORY_HUGE)` added at all four processing entry points — `parse_nat_group()`, `save_nat_groups()`, the Step 1 upload handler, and the `finalizenat` action handler. `MEMORY_HUGE` maps to 512 MB on standard Moodle installs, which is sufficient for any realistic Australian RTO dataset.
  2. New `local_rtocompliance_nat_lines(array $file): Generator` helper streams disk-backed (`tmppath`) NAT files line-by-line via `fgets()` instead of loading the whole file. For legacy in-memory `content` entries the already-normalised string is split with `explode()`. NAT00080 and NAT00130-as-students use a two-pass approach: first 100 lines are collected for USI column detection, then the generator is re-opened for the full parse — eliminating the large intermediate `$lines` array entirely.

No DB schema changes. No AMD changes. Savepoint 2026052000238 (marker only).

---

## v4.9.167 — 20 May 2026

### Fixed — Auto-enrol: re-enrolment blocked by withdrawn records + wrong commencingprogramid on multi-unit courses

- **BUG-REENROL-WITHDRAWN** (`process_enrolment_task.php`): The duplicate-enrolment guard in `process_enrolment_created()` used `record_exists()` with no status filter, so a withdrawn record (outcome `40`) was treated as an existing active enrolment. A student who was unenrolled from Moodle and then re-enrolled never received a new active RTO enrolment record — they remained permanently withdrawn in AVETMISS with no way to record the re-enrolment without manual admin intervention. Fixed by replacing both `record_exists()` calls (qual-builder path and nationally-recognised fallback path) with `record_exists_sql()` that excludes `status = 'withdrawn'` rows. Re-enrolment now correctly creates a fresh active record alongside the prior withdrawn record (preserving the withdrawal history for AVETMISS audit trail).

- **BUG-COMMENCING-LOOP** (`process_enrolment_task.php`): `resolve_commencing_id()` was called inside the `foreach ($qualunits)` loop. On a course with 2+ units for the same qualification the first `insert_record()` wrote an `active` row; the second call to `resolve_commencing_id()` found that row and returned `'3'` (Continuing) instead of `'1'` (Commencing), even when the student had never previously enrolled in the program. Fixed by building a per-programcode cache (`$commencing_by_program[]`) before any inserts, so all units in the same qualification share the same resolution snapshot. Multi-qualification courses (units from different quals in one course) also handled correctly — each qualification gets its own independently resolved commencing ID.

No DB schema changes. No AMD changes. Savepoint 2026052000237 (marker only).

---

## v4.9.166 — 20 May 2026

### Fixed — USI extraction silently fails for formats where auto-detected position is correct

- **FIX-METHOD0-10CHAR-FALLBACK**: When the voter correctly identifies the USI column (e.g. position 154 with 19/20 votes), Method 0 in `parse_nat00080()` reads 12 chars and validates the whole string against the USI charset `[2-9A-HJ-NP-Z]`. Australian USIs are exactly 10 chars; in NAT00080 fixed-width exports the 10-char USI is immediately followed by a 2-char check digit (e.g. `05`). The digit `0` is not in the USI charset (0 and 1 are excluded to prevent false positives from numeric fields). So the 12-char candidate `832HXX8RQW05` failed the regex — `0` in the check digit is outside the allowed set — Method 0 returned null, and the fallback methods (1 at pos 149, 2 at pos 90) also failed because this format's USI is at 154. Zero USIs extracted despite the voter being exactly right. Fix: if the 12-char read fails charset validation, fall back and try just the first 10 chars. Vendor formats that genuinely use 12-char USIs (all 12 chars from the USI charset) continue to work unchanged. No DB schema changes. Savepoint 2026052000236 (marker only).

---

## v4.9.165 — 20 May 2026

### Fixed — Step 2 banner now shows confirmed column number and a live USI example

- **FIX-NAT00080-TABDELIM-BANNER**: When a tab-delimited NAT00080 is detected (sentinel `-2`), the green "USI codes detected automatically" success banner in Step 2 now includes an inline badge reading "Tab-delimited format — USI is in column 3 (e.g. **MNJ4UPAPDX**)", where the example USI is pulled live from the first matched record in the preview. This confirms to the admin exactly which column the parser is reading from and what a valid USI looks like — eliminating any guesswork. No parser changes, no DB schema changes. Savepoint 2026052000235 (marker only).

---

## v4.9.164 — 20 May 2026

### Fixed — NAT00080 tab-delimited format support (multi-SMS-vendor format flexibility)

- **FIX-NAT00080-TABDELIM**: Some SMS vendors (e.g. VLearnLMS) export NAT00080 in a tab-delimited format with a quoted first field (`"<clientid><SURNAME, FIRSTNAME>"\t<demographics>\t<USI>05\t<address>`). The existing byte-offset parser could not handle this because the name field is variable-length — every record's USI landed at a different absolute byte position, so the vote-based detector never reached its 2-vote threshold and returned -1 (no format detected). All three fallback methods also failed silently. The result was zero USIs extracted and "No USI codes detected" shown on the Step 2 preview for every student.
  - `local_rtocompliance_detect_nat00080_usi_pos()`: detects tab-delimited format early (≥2 of first 5 lines contain `\t`) and returns sentinel `-2` instead of running the voting loop, which would always fail.
  - `local_rtocompliance_parse_nat00080()`: new tab-split path inserted before the fixed-width parser. Splits on `\t`, strips surrounding quotes from field 0 (clientid + name), extracts sex/DOB from field 1 (positions 2 and 3–10 within the demographics column), and reads the USI from the first 10 chars of field 2. Also returns `firstname` and `familyname` split from the `SURNAME, FIRSTNAME` format in field 0 — these populate the auto-created Moodle account without needing NAT00085.
  - `local_rtocompliance_find_usi_candidates()`: tab-delimited early path builds candidate entries from field 2 so the column-picker UI is not empty when this format is uploaded.
  - `local_rtocompliance_nat_format_label()`: label added for `-2` → "Tab-delimited SMS export (quoted name field, USI in 3rd column)".
  - Step 2 confirmation UI: USI success banner condition widened from `$effUsiPos >= 0` to `$effUsiPos >= 0 || $effUsiPos === -2` so the green "USI codes detected automatically" message fires for tab-delimited files.

No DB schema changes. Savepoint 2026052000234 (marker only).

---

## v4.9.163 — 20 May 2026

### Fixed — NAT auto-enrolment: four bugs fixed in account creation and skip reporting

- **FIX-AUTOENROL-PASSWORD-POLICY**: `random_string(20)` (used when auto-creating Moodle accounts for students without one) only generates lowercase letters and digits — which fails Moodle's standard password policy (`passwordpolicy=1`, the default). `user_create_user()` validates the password against the site policy **before** hashing it; when validation fails it throws a `moodle_exception` caught by the surrounding `catch(\Exception)` block, silently counting that student as a `createfailed` skip. The result: admins saw "0 students enrolled" with no explanation. Fix: replaced `random_string()` with `local_rtocompliance_generate_policy_password()` — a new helper that constructs a 12-character password guaranteed to contain at least one uppercase letter, one lowercase letter, one digit, and one non-alphanumeric character, satisfying Moodle's default policy on every site.
- **FIX-AUTOENROL-FORCE-PASSWORD-CHANGE**: Auto-created accounts now have `auth_forcepasswordchange` set to `1` via `set_user_preference()` immediately after creation. The admin-generated password is random and never shown to the student; this ensures they are prompted to set their own password on first login.
- **FIX-AUTOENROL-CREATEFAILED-REPORT**: The on-screen skip report after enrolment only had three reason buckets: `nostudent`, `noemail`, `nouser`. The `createfailed` reason (thrown when `user_create_user()` fails for any reason) was not in the map — those students vanished silently from the UI. The CSV download already labelled them correctly, but the accordion on the results page never showed them. Fixed: added `createfailed` to both `$byReason` and `$reasonInfo`, with a clear explanation directing the admin to look for a duplicate username in Moodle.
- **FIX-PREVIEW-ROW-COUNT**: The Step 2 confirmation preview heading read "First 12 student records" even when only 2–3 rows were present (used `max(count, 12)` instead of `count`). Fixed to show the actual number of rows, with correct singular/plural ("1 student record" vs "3 student records").
- **FIX-SUBMIT-DESCRIPTION**: The "Confirm & Enrol" submit area still said "Only students who already have a Moodle account (matched by email) will be enrolled" — stale copy from before account auto-creation was added in v4.9.161. Updated to accurately describe the current behaviour (match by email or student number, auto-create accounts for unmatched students, force password reset on first login).

No DB schema changes. Savepoint 2026052000233 (marker only).

---

## v4.9.143 — 17 May 2026

### Re-release — fresh version integer for Moodle upgrade recognition

- Same student picker improvements as v4.9.142, re-issued with version integer `2026051700213` to ensure Moodle's upgrade detector fires on all installs.

---

## v4.9.142 — 17 May 2026

### Improved — Student picker in Issue Multi-Unit SOA

- **Surname first:** Students now display as "Smith, John (email@...)" instead of "John Smith (email@...)", matching the alphabetical sort order so the list is easy to scan.
- **Live typeahead:** Replaced the plain dropdown with a proper search-as-you-type input. Type any part of surname, first name, or email to filter instantly.
- **Two-line results:** Surname + first name on line one (surname bold), email in smaller muted text below — no more truncated labels.
- **Highlighted matches:** The text you typed is highlighted yellow in the results.
- **Keyboard navigation:** Arrow keys move through results, Enter selects, Escape closes.
- **Result count:** Shows "42 students matching 'smi'" at the top of the dropdown.
- **× clear button:** Appears after selecting a student so you can easily switch to someone else without reloading the page.

No DB schema changes. Savepoint 2026051600212 (marker only).

---

## v4.5.100 — 12 May 2026

### Fixed — MEM20413 group packaging rules root-cause fix

- **Group A shows "Min 1" instead of "Min 7" / Group B shows "Min 1" instead of "1 unit only":** True root cause identified and fixed. MEM20413 stores its packaging rules across **multiple TGA content bundle items** — the intro totals are in item 1, and the Group A / Group B detail lines ("A minimum of 7 units from Group A…", "A maximum of 1 unit from Group B…") are in separate subsequent items. The previous code had a `break` that stopped processing after the first matching item, so group detail items were never reached and the group min/max values were never parsed, triggering the `min:1` fallback for all groups.
  - **`server/tgaService.ts`**: Removed the `break` from the packaging-rules content-bundle loop. The loop now accumulates `rulesText` from **all** matching items; `rawHtml` still captures only the first match for display. This ensures Group A and Group B detail items are both included in the text passed to the routes.ts regex parser.
  - **`server/routes.ts` (maxM handler)**: When `maxM` fires ("maximum of N units from Group B") and no explicit `min` has been parsed yet for that group, the handler now sets `min = max` when `max ≤ 1`. Previously this gave `{ min: 0, max: 1 }` → displayed as "optional". Correct result is `{ min: 1, max: 1 }` → displayed as "1 unit only".
  - **`amd/src/qualbuilder_edit.js` + `amd/build/` (src, build, min.js)**: Group section-header `reqLabel` and summary-panel group label changed from `"select minimum of N"` to `"Min N"` for open-ended minimum rules (where `min > 0` and `max ≥ 999`). Summary-panel separator also updated from `"min&nbsp;"` to `"Min "` for consistency with the section headers.

### Housekeeping
- `version.php` already at `2026051200170` / release `4.5.100`.
- `db/upgrade.php` savepoint added for `2026051200170`.
- `BUILD_INFO.json` bumped to `4.5.100`.
- ZIP rebuilt as `local_rtocompliance_v4.5.100.zip`.

---

## v4.5.98 — 10 May 2026

### Fixed — Two bugs from errors_10_May_2026_(2).docx

- **TAS Section 2 prerequisite AI citing wrong standard (Bug 1):** AI-generated content for the Entry Requirements and Prerequisites fields was showing "ASQA Standard 5.1" which is incorrect. Corrected to **Outcome Standard 2.2** in both the AI guidance config (`server/routes.ts` — `asqaGuide` for `entryrequirements` and `prerequisites`) and the help button text (`lang/en/local_rtocompliance.php` — `entryrequirements_help` and `prerequisites_help`).

- **MEM20413 qualbuilder Group A min=1 / Group B max not shown (Bug 2):** Root-cause fix in `server/tgaService.ts`: TGA packaging rules HTML uses `<strong>` tags around numbers (e.g. `Plus <strong>7 units</strong> from the following elective units (Group A)`). These inline tags were being replaced with newlines, splitting the sentence across three lines and causing all regexes to miss the connection between the number and the group. Fix: inline tags (`strong`, `em`, `b`, `i`, `u`, `s`, `span`) are now stripped cleanly without adding newlines; only block-level tags (`p`, `div`, `h1–h6`) add line breaks. Additionally: content bundle detection trigger broadened to also catch "N units from Group" and "Group [A-Z] + N units" patterns; `parenM` regex added for the "(Group A)" suffix format with exact-count semantics (min = max = N); `minM` and `maxM` character classes extended to include `()` so `(Group B)` suffixes match correctly.

### Housekeeping
- `version.php` bumped to `2026051000168` / release `4.5.98`.
- `db/upgrade.php` savepoint added for `2026051000168`.

---

## v4.5.97 — 10 May 2026

### Fixed — Six bugs from errors_10_May_2026.docx
- **TGA Packaging Rules min display (Bug 1):** Group A showing "min 1" instead of "min 7" for MEM-series and similar qualifications. The TGA rules text parser regexes (`minM`, `maxM`, `fromM`) now use a permissive middle-section pattern `(?:[A-Za-z ]{0,60}?)` that handles any intervening words (e.g. "units must be selected from") between the quantity and "Group X".
- **TAS Delete button (Bug 2):** Added Delete button to each row of the TAS list (`tas.php`). Requires `moodle/site:config` capability, sesskey CSRF check, and a confirm dialog. Deleted successfully shows a Moodle success notification.
- **Standard citations — Learning Resources / Facilities / Technology (Bug 3):** Section 7 fields were citing Standard 1.3 (wrong). Corrected to **Standard 1.8** in both the AI hint (`server/routes.ts`) and the help button text (`lang/en/local_rtocompliance.php`).
- **Standard citations — Work Placement (Bug 4a):** Section 8 was citing "Standard 1.3 and the National Work Placement Guidelines" (wrong). Corrected to **Outcome Standards 1.1(2e); 1.2; 2.1(2c(iv))** in both files. **Assessment Plan Notes (Bug 4b):** was citing "Clause 1.8" (wrong). Corrected to **Outcome Standards 1.3-1.4**.
- **Work Placement AI fabricating hours (Bug 4c):** AI `systemHint` for the Placement Details field now explicitly instructs the model to check `hasworkplacement` and `placementhours` from context. If either is 0/unset, the AI correctly states no mandatory work placement is required and describes simulated activities instead of inventing hours.
- **Completeness % never reaching 100% without work placement (Bug 5):** Section 8 (Work Placement) now always counts as complete on save — `hasworkplacement=0` is a valid deliberate answer ("no WP required") and must not block a TAS from reaching 100% (`tas_edit.php`).

### Housekeeping
- `version.php` bumped to `2026051000167` / release `4.5.97`.
- `db/upgrade.php` savepoints added for `2026051000166` (v4.5.96) and `2026051000167` (v4.5.97).

---

## v4.3.0 — 2 May 2026

### Major — Cert Template Audiences
- **Audience dimension on cert templates.** A single RTO can now keep one ACTIVE template per (cert type + audience) — so apprentices, school-based students, VET-FEE / VET Student Loan students, state-funded students, Commonwealth-funded students, CRICOS / international students and private fee-for-service students can each have their own testamur, Statement of Attainment, Record of Results and Certificate of Completion design for the SAME qualification code. Nine standard audience codes ship out of the box: `default`, `apprentice`, `traineeship`, `school`, `vetfee`, `funded_state`, `funded_commonwealth`, `international`, `private_fee`.
- **Issue-time pinning.** When a certificate is issued, the resolved template id is written onto the cert row (`local_rtocompliance_certs.certtmplid`). A later reissue / redownload uses the SAME template even if the active template for that (certtype + audience) slot has since been swapped — so the original signed PDF is reproducible byte-for-byte.
- **Per-template payload overrides.** Templates may now carry a `designjson.overrides{}` block (e.g. an audience-specific apprenticeship statement, language statement, RoQF or industry descriptor). The renderer merges these into the resolved payload before drawing — no need to re-type every field per audience.
- **Audience-aware activation.** Activating a new "Apprentices" testamur only demotes the previously-active Apprentices testamur — your Default, CRICOS, VETiS and other audience templates stay active in their own slots.
- **Default fallback.** If a particular audience has no template yet, the system falls back to the `default` audience template for that certtype, so audiences can be rolled out one at a time with zero risk.

### Schema (savepoint 2026050200070, idempotent — `field_exists` / `index_exists` guarded)
- `local_rtocompliance_certtmpl` gains `audience` char(32) NOT NULL DEFAULT `'default'` + `audiencelabel` char(255) nullable + new index `(certtype, audience, isactive)`.
- `local_rtocompliance_certs` gains `certtmplid` int(10) nullable + index on `certtmplid`.
- All pre-existing template rows back-fill to `audience='default'` via the column DEFAULT, so legacy templates remain the active default-audience template for their certtype and behaviour is unchanged for sites that never touch the new dropdown. Existing certs keep `certtmplid=NULL` and re-pick at render time (legacy behaviour) until they're reissued.
- `db/install.xml` updated for fresh installs (XMLDB VERSION bumped to `20260502`).

### Code
- `classes/cert_template.php` — added `AUDIENCES` const list (9 standard codes), audience parameter on `create()`, new `set_audience()` for in-editor re-targeting, new `pick_for_audience()` and `pick_for_cert()` runtime selectors, audience-scoped activation demotion in `activate()`, and seed routine now pins `audience='default'` on system starters.
- `lib.php` — render dispatcher switched from `get_active_template()` to `pick_for_cert()` (honours `cert.certtmplid` → `cert.audience` → default → any active). `designjson.overrides{}` now merge into the resolved payload before draw.
- `issue_certificate.php` — audience picker on the issue form; resolved template id pinned onto `cert.certtmplid` at insert time so reissues use the same design.
- `cert_templates.php` — audience picker on the create form; new audience badge column on the templates list.
- `cert_template_edit.php` — audience picker + label override in the editor's left panel; saved via `set_audience()` in the POST handler.
- `lang/en/local_rtocompliance.php` — audience codes, labels and helper strings.

### Sync points
`version.php` (2026050200070, '4.3.0') / `BUILD_INFO.json` (4.3.0) / `db/install.xml` (XMLDB VERSION 20260502) / `server/routes.ts` (`local_rtocompliance_v4.3.0.zip`) / `client/src/lib/pluginConfig.ts` (4.3.0 + changelog) / `replit.md` (changelog appended). No AMD module changes — the AMD triple-match guarantee is unaffected.

---

## v4.2.0 — 28 Apr 2026

### Fixed (4 additional production bugs — third audit pass)
- **BUG-21 `send_completion_survey_task.php`** — Survey DB record inserted BEFORE `message_send()`. If `message_send()` threw any exception (e.g. no message processor configured, SMTP failure), the record was left with `status='pending'` and the outer SQL `ls.id IS NULL` filter permanently blocked that user from ever being re-queued for a survey. Fix: generate the `$accesstoken` and build the full message object first, attempt `message_send()`, then only insert the DB record inside the `try` block after a successful send. Failed sends leave no orphaned record and the user will be retried on the next cron run.
- **BUG-22 `observer.php` `user_graded`** — `assessmentdate` column set to `time()` (the moment the cron event handler ran), not the actual grading timestamp. On a busy Moodle site the `user_graded` event can be dequeued and processed hours after the grade was entered, recording a meaningless wall-clock time. Fixed to use `$event->timecreated` — the exact Unix timestamp Moodle recorded when the grading event fired.
- **BUG-23 `nat_generator.php` `generate_nat00130`** — `$DB->get_manager()` and `->table_exists('local_rtocompliance_certs')` were called inside the `foreach ($completions as $comp)` loop, causing one redundant schema-metadata lookup per completion record. On an RTO with hundreds of qualification completions this multiplied schema queries proportionally. Hoisted both calls above the loop; result cached in `$certs_table_exists` for the duration of the export.
- **BUG-24 `external.php` `tga_search_qualification` + `tga_search_unit`** — Both web-service functions used raw `curl_init()` / `curl_exec()` / `curl_close()` instead of Moodle's `\curl` class. Raw handles bypass Moodle's proxy, SSL CA certificate, and redirect configuration (Site Administration → HTTP → curl settings). On Moodle sites behind an institutional proxy or with custom CA bundles these calls silently failed or produced SSL errors. Replaced both with `new \curl()` / `setopt()` / `get()` — identical to the fix already applied to `ajax.php` `tga_qualification` and `generate_resolution` actions in v4.1.7 (BUG-10).

---

## v4.1.9 — 28 Apr 2026

### Fixed (20 critical production bugs — second audit pass)
- **BUG-1 `process_enrolment_task.php`** — `process_enrolment_deleted` used `get_record()` and returned on null; replaced with `get_records()` + loop to handle Qual Builder multi-unit enrolments where one student has N unit records per qualification.
- **BUG-2 `process_enrolment_task.php`** — `process_course_completed` same single-record pattern; replaced with `get_records()` loop so all unit completion records receive `programoutcome='01'`.
- **BUG-3 `send_completion_survey_task.php`** — survey DB insert fired before user existence check; moved `$DB->record_exists` check before insert to prevent orphaned survey records for deleted users.
- **BUG-4 `send_completion_survey_task.php`** — `date('Y')` for survey year used UTC server time; replaced with `DateTimeZone('Australia/Sydney')` to get correct AEST year.
- **BUG-5 `nat_generator.php` `formatdate()`** — called `date()` without timezone, producing UTC-offset dates; replaced with `DateTime::setTimezone('Australia/Sydney')` to produce DDMMYYYY in correct local date.
- **BUG-6 `nat_generator.php` NAT00060/NAT00120** — period filter used `activitystartdate`-only (startdate within period); corrected to period-overlap: `startdate<=periodend AND (enddate>=periodstart OR enddate IS NULL)`. Ongoing enrolments no longer dropped from exports mid-year.
- **BUG-7 `nat_generator.php` NAT00120 MAIN-fallback** — same wrong startdate-only filter as bug 6; corrected to same period-overlap logic.
- **BUG-8 `nat_generator.php` NAT00130** — deduplication missing; multiple completions for same student+qual produced duplicate rows; added `DISTINCT ON` subquery keyed on `studentid+programcode`.
- **BUG-9 `nat_generator.php` `validate_enrolment_data()`** — count query tallied all enrolments, not just in-period ones; corrected count query to use same period-overlap filter.
- **BUG-10 `nat_generator.php`** — `deliverylocationid` MAIN fallback was `''` (empty string); AVETMISS rejects blank location codes; changed fallback to `'MAIN'`.
- **BUG-11 `nat_generator.php` NAT00080/85** — `??` (null-coalescing) on `labourforcestatus`/`prioreducationflag` only replaces `NULL`, not empty string `''`; changed to `?:` so both NULL and `''` get correct AVETMISS not-stated codes (`'@@'`/`'@'`).
- **BUG-12 `nat_generator.php` NAT00130 `issuedflag`** — hardcoded `'Y'`; now checks `local_rtocompliance_certs` for a real `status IN ('issued','active')` record; falls back to `'N'` if none found.
- **BUG-13 `webhook.php`** — `'usi_certificate_path'` was in `$ALLOWED_KEYS` whitelist; a subsequent platform push with a stale path could silently overwrite the path set by the base64 cert handler; removed from whitelist entirely.
- **BUG-14 `ajax.php`** — no length cap on `description`/`subject`/`groundsforappeal`/`originaldecision` free-text fields passed directly into AI prompt; added `substr()` caps (2000/500/2000/1000 chars) to prevent API payload bombs.
- **BUG-15 `process_enrolment_task.php`** — `programcompletedyear` wrote `date('Y')` (current UTC server year); a cron run crossing midnight Jan 1 in UTC writes the wrong year. Now extracts year from `activityenddate` using `Australia/Sydney` timezone via `DateTime`.
- **BUG-16 `usi_verification_service.php`** — `log_verification_attempt()` called `table_exists()` on every write; 25 schema metadata lookups per batch; cached in `static` variable, now called at most once per PHP request.
- **BUG-17 `cleanup_expired_certificates.php`** — `count_records_select` then `delete_records_select` TOCTOU race; concurrent cron could read stale count before either delete; replaced with delete-first pattern.
- **BUG-18 `observer.php`** — `table_exists('local_rtocompliance_qualunits')` called on every site-wide enrolment event (both created and deleted handlers); cached in `static` variable for both handlers.
- **BUG-19 `nat_generator.php` NAT00020 MAIN-fallback check** — same wrong `activitystartdate`-only filter as bugs 6/7; corrected to period-overlap filter to stay consistent with post-fix NAT00120 logic.
- **BUG-20 `nat_generator.php` NAT00080/85** — duplicate of bug-11 scope: `labourforcestatus ??` / `prioreducationflag ??` — resolved together with bug-11 fix (`?:` throughout).

---

## v4.1.8 — 28 Apr 2026

### Fixed
- **HOTFIX `lang/en/local_rtocompliance.php` line 2845** — Rule 9B Building Classification strings had a literal backslash before `$string` (written as `\\$string`), causing `ParseError` on plugin install/upgrade. Fixed to plain `$string`.

---

## v4.1.7 — 28 Apr 2026

### Fixed (15 critical production bugs)
- **BUG-1 `external.php`** — `get_certificates()` queried non-existent table `local_rtocompliance_certificates`; all column names wrong (`studentid`→`userid`, `dateissued`→`issuedate`, `certificatetype`→`certtype`, `certificatenumber`→`certnumber`). Rewritten with correct JOIN on `local_rtocompliance_certs` and `verificationurl` built from `verifytoken`.
- **BUG-2 `compliance_predictor.php`** — same wrong table name; checked `status='pending'` which doesn't exist. Now correctly checks `emailsent=0 AND status='issued'` for 7-day email delay alerts.
- **BUG-3 `alerts.php`** — all three action blocks (acknowledge, resolve, dismiss) used wrong table `local_rtocompliance_alerts`; corrected to `local_rtocompliance_ai_alerts`.
- **BUG-4 `my_profile.php`** — raw `$data` from form (including `submitbutton`, `sesskey`, etc.) passed directly to `$DB->update_record()/insert_record()`, causing DML exception. Added `validcolumns` filter matching `student_profile.php`.
- **BUG-5 `my_profile.php`** — `profilecomplete` only checked 6 of 11 AVETMISS-required fields; added `indigenousstatus`, `countryofbirth`, `languageathome`, `labourforcestatus`, `highestschoollevel`.
- **BUG-6 `nat_generator.php` NAT00080** — no reporting-period filter; exported ALL students. Added `WHERE EXISTS` subquery on `local_rtocompliance_enrolments` to include only students active in the period.
- **BUG-7 `nat_generator.php` NAT00085** — same missing year-filter; fixed identically to NAT00080.
- **BUG-8 `nat_generator.php` NAT00120** — enrolments with `outcomeidentifier='00'` were silently skipped with `continue`, removing them from the AVETMISS file entirely. Now converted to `'70'` (Continuing) instead.
- **BUG-9 `student_enrolments.php`** — all 4 sites that create new enrolments defaulted `outcomeidentifier='00'` (invalid AVETMISS); changed to `'70'` (Continuing).
- **BUG-10 `ajax.php`** — `generate_resolution` action used raw `curl_init()` bypassing Moodle proxy/SSL config; replaced with `new \curl()` matching the existing `tga_qualification` action pattern.
- **BUG-11 `certificates.php`** — `lateIssued30` SQL compared both `timecreated` and `issuedate` against the same `-31 days` threshold, flagging ALL old certs. Fixed to `WHERE issuedate > timecreated + 30*DAYSECS`.
- **BUG-12 `certificates.php`** — `$isLate` display check used `$cert->issuedate < $thirtyDaysAgo` (absolute wall-clock time, not duration). Fixed to `($cert->issuedate - $cert->timecreated) > 30*DAYSECS`.
- **BUG-13 `nat_generator.php` NAT00085** — title default was `'@@'` (coded "not stated"), invalid for a free-text field. Changed to `'    '` (4 spaces = AVETMISS text not-stated).
- **BUG-14 `qualbuilder_results.php`** — `countsql` was built by prepending `SELECT COUNT(*)` to the full `$sql` which included `ORDER BY u.lastname, u.firstname`; causes a fatal SQL error on PostgreSQL. Strip `ORDER BY` before building count SQL.
- **BUG-15 `nat_generator.php` NAT00120** — active enrolments with null/0 `activityenddate` produced 8 spaces in the NAT file. Changed to use `$this->periodend` as the end date for active enrolments.
- **BUG-16 `transition_edit.php`** — after `$DB->set_field('enrol', 'status', ...)`, Moodle's enrolment instances cache was not invalidated; change was invisible until cache rebuilt. Added `\cache::make('core', 'enrolinstances')->delete($linkedcourseid)`.
- **BUG-17 `usi_verification_service.php`** — rate limit check+increment non-atomic; concurrent requests could both pass the check before either incremented, allowing 2× the allowed API call rate. Wrapped in Moodle named lock via `lock_config::get_lock_factory`.

---

## v4.1.6 — 28 Apr 2026

### Added
- **Validation report document as URL** — the "Report Document Filename" field on the Validation Event form has been renamed to "Report Document URL". Paste any accessible URL (Google Drive, SharePoint, OneDrive, etc.) and it will appear as a "View Report" button in both lists:
  - **Validation Schedule tab:** "Manage" button is still present for editing; a separate "View Report" button (opens new tab) appears beside it when a URL is stored.
  - **Completed Events tab:** "View Report" button now opens the actual document URL (primary, opens new tab). A smaller "Edit" button replaces the old "View Report" → edit link.
  - Records without a URL show only the Manage/Edit button as before — no broken links.
- **DB upgrade step 2026042800116:** widens `reportdocument` column from `char(255)` to `char(500)` so full Google Drive / SharePoint URLs fit without truncation.

---

## v4.1.5 — 28 Apr 2026

### Added
- **AI Suggest for Validation Methodology Notes** — a small "⚡ AI Suggest" button (5 credits / 5¢) appears directly below the "Additional Methodology Notes" textarea on the Validation Event edit form. Clicking it reads the currently ticked methodology checkboxes and the product name/code, calls the new `/api/rto/ai-methodology-suggest` endpoint (server-side, GPT-4o-mini), and fills the textarea with 2–4 sentences of ASQA-compliant prose describing how those methods were applied and how findings will inform continuous improvement. Status message shows remaining credit balance. User must review and edit the text before saving. No methodology selected → validation message shown.
- **New server endpoint `/api/rto/ai-methodology-suggest`** — accepts `{ methods[], productname, productcode }` + `X-API-Key` header. Returns `{ success, text, creditsUsed, creditsRemaining }`. Cost: 5 credits.

---

## v4.1.4 — 28 Apr 2026

### Fixed
- **Plugin-wide checkbox left-alignment** — checkboxes in all mform contexts (validation methodology, transition form, trainer form, student enrolment form, etc.) were appearing centred rather than left-aligned. Three root causes patched in `styles.css`:
  - **(A) `felement.fgroup` (addGroup checkboxes):** Bootstrap may set `display:flex` on `.felement`; the existing `justify-content:flex-start` fix had no effect because `display:flex` was not overridden. Added `display:block !important` to force block layout on the group container, ensuring each `fcheckbox` span renders left-aligned.
  - **(B) `fitem_fcheckbox` (advcheckbox rows):** The general `.col-form-label` rule sets `width:100%` which consumed the full row width in row-direction flex, pushing the checkbox off-screen. Overridden with `width:auto; max-width:80%; flex:0 1 auto` in checkbox-row context.
  - **(C) `form-check` / `form-check-inline`:** Bootstrap's `display:inline-flex` on `.form-check-inline` caused inline checkbox+label pairs to centre under any parent `text-align:center`. Override to `display:flex; justify-content:flex-start` with `position:static` on the checkbox input.

---

## v4.1.3 — 28 Apr 2026

### Added
- **Training Product Transitions — Moodle enrolment integration** — new "Linked Moodle Course" field on the Transition edit form (select any course from your Moodle instance). When "Enrolments Closed" is ticked and saved, the plugin automatically disables self-enrolment on the linked Moodle course by setting `{enrol}.status = 1` for all self-enrolment instances. Unticking and saving re-enables self-enrolment. A confirmation note appears on the redirect: *"Self-enrolment disabled on linked Moodle course."*
- **Training Product Transitions list — new "Enrolments" column** — shows per-transition enrolment state at a glance:
  - "✓ Closed in Moodle" (green) — linked course, enrolments closed, self-enrolment disabled
  - "Still Open" (red) — linked course, teach-out deadline PASSED, enrolments still open — action required
  - "Open" (grey) — linked course, deadline not yet reached
  - "Closed (manual)" — no linked course, flag ticked manually
  - "⚠ No Moodle control" (amber) — no linked course, deadline passed
- **DB migration** — `linkedcourseid` (nullable INT) added to `local_rtocompliance_transitions` (upgrade step 2026042800113)

---

## v4.1.2 — 28 Apr 2026

### Added
- **Student Handbook URL — new RTO setting** — a new "Student Handbook URL" field has been added to Plugin Settings directly below the existing "Website" field. Accepts a full URL (e.g. `https://yourrto.edu.au/student-handbook`). Automatically prefixes `https://` if no scheme is supplied.
- **Standard 2.1 — Student Obligations card "Show Evidence" button updated** — now links directly to the configured Student Handbook URL (opens in a new tab), matching what ASQA auditors expect to see as evidence of Standard 2.1 compliance (pre-enrolment provision of the handbook). When the URL is not yet configured, the button falls back to the Student Declaration records page and shows an amber notice prompting the admin to set the URL in Plugin Settings.
- **Standard 2.1 — Student Obligations card "Send Declaration to Students" button now always visible** — previously this button was silently lost when `evidenceHtml` was set on the card (the `renderCards` function only appended `extraButtons` in the default code path, not the custom `evidenceHtml` path). Fixed: the "Send Declaration to Students" button is now embedded directly inside `evidenceHtml` for both the handbook-configured and fallback states.

---

## v4.1.1 — 28 Apr 2026

### Fixed / Tests
- **NAT file export — comprehensive PHPUnit test suite** — `tests/nat_generator_test.php` expanded from 21 to 40 tests. New coverage:
  - **Record-length assertions for all 10 NAT files**: NAT00010 (448), NAT00020 (180), NAT00030 (130), NAT00060 (123), NAT00080 (327), NAT00085 (557), NAT00090 (12), NAT00100 (13), NAT00120 (158), NAT00130 (72) — every record's byte-width is now enforced.
  - **Field-position checks**: DOB DDMMYYYY at pos 74-81 of NAT00080; USI at pos 150-159; NAT00030 nominal hours zero-padded at pos 111-114; NAT00060 VET flag 'Y' at pos 119; NAT00120 delivery location at pos 11-20; NAT00120 tuition fee at pos 118-122; NAT00130 program ID at pos 11-20; NAT00130 issued flag 'Y' at pos 39.
  - **Outcome '00' skip**: enrolments with `outcomeidentifier='00'` or empty must not appear in NAT00120.
  - **UTF-8 transliteration**: `Hélène Müller` → `HELENE MULLER` in NAT00080 without corrupting 327-byte record length.
  - **Float tuition fee rounding** (Bug 4): `1500.75` → `01501` in NAT00120 pos 118-122.
  - **NAT00085 survey email fallback** (Bug 40): `surveycontactemail` wins over Moodle login email; falls back correctly when blank.
  - **NAT00020 MAIN location fallback**: verified when no locations table entries exist.
  - **NAT00100 placeholder '@@'**: students with `prioreducationflag='Y'` but no achievements get a `@@` placeholder record, not an empty file.
  - **NAT00130 genuine-completions-only**: `programoutcome '03'/'04'/'05'` excluded; only `'01'`/`'02'` included.
  - **Multi-disability count**: NAT00090 count = total type entries, not students.
  - **Multi-prior count**: NAT00100 count = total achievement records across all students.
  - **Bug fix**: `test_record_counts_are_accurate` previously asserted `NAT00100 = 1` but the test student has no `prioreducationflag='Y'`, so the correct expected value is 0. This would have caused a false failure on every run.

---

## v4.1.0 — 28 Apr 2026

### Added
- **Trainer Input — View / Edit / PDF / Delete for saved records** — the Saved Support Records table now has four action buttons per row:
  - **View** — opens a modal showing all 6 ASQA-labelled support fields (LLN, Adjustments, Referrals, Interventions, Diversity, Wellbeing) formatted in clearly labelled sections. Modal has Download PDF and Edit shortcut buttons in the header.
  - **Edit** — loads the full record back into the input form. Save button changes to amber "Update Support Record". On update, the original record date and ID are preserved. Clear button and modal Edit button both cancel edit mode.
  - **Download PDF** — opens a print-ready browser window with the record formatted as an ASQA-compliant document: RTO header, student/LLN/risk summary cards (risk colour-coded), all 6 sections, trainer signature block, and privacy footer. Includes Print / Save as PDF and Close buttons.
  - **Delete** — unchanged, removes record from localStorage.
- **Risk level colour-coding** in the records table (High = red, Medium = amber, Low = green).

---

## v4.0.99 — 28 Apr 2026

### Added
- **Auto Fill (AI) — real AI endpoint** — `student_support_input.php` Auto Fill button now calls `/api/rto/ai-support-autofill` (50 credits, ½¢) instead of inserting hardcoded template text. GPT-4o-mini generates ASQA-compliant per-student support text for all 6 fields (LLN observations, reasonable adjustments, support service referrals, intervention strategies, diversity & inclusion, wellbeing notes) tailored to the student's actual LLN level (ACSF) and risk level.
- **Backend endpoint** — `POST /api/rto/ai-support-autofill` added to routes.ts. Validates API key, enforces 50-credit gate, prompts gpt-4o-mini with ASQA Standards 2.3/2.4/2.5/2.6 context, validates 6-field JSON response, deducts credits.
- **Markdown strip** — `stripMd()` applied to all returned fields before populating textareas (guards against any stray `**bold**` or `## heading` symbols).
- **Credit label** — "50 credits (½¢)" shown inline next to the Auto Fill button. On success, status message shows credits used and credits remaining.
- **API config** — `student_support_input.php` now reads `local_rtocompliance.apikey` and `local_rtocompliance.apiurl` (same resolution chain as `tas_edit.php`) and passes them to inline JS via `data-api-key` / `data-api-base` attributes.

---

## v4.0.98 — 28 Apr 2026

### Fixed
- **Markdown rendering in AI suggestions** — AI-generated text is now stripped of markdown symbols (`**bold**`, `## headings`, `*italic*`) before being inserted into plain-text textareas. Previously, asterisks appeared literally (e.g. `**Trainer/Assessor Requirements**`). Applies plugin-wide: `ai_suggest.js` (TAS / all registered fields), `complaint_form.php` (Resolution Details), `appeal_form.php` (Grounds for Appeal, Appeal Outcome Reason).
- **AI suggestion modal preview** — Suggestion text in the modal now renders markdown as formatted HTML (bold, italic, headings displayed correctly) before the user accepts it.

---

## v4.0.97 — 28 Apr 2026

### Added
- **AI assist — Grounds for Appeal** — "Generate with AI" button added below the Grounds for Appeal textarea in `appeal_edit.php`. Uses the appeal type, reference, and original decision as context to generate a structured ASQA Clause 6.2-compliant grounds statement (what decision is being appealed, why it was incorrect or unfair, supporting standards references, outcome sought). Same `generate_resolution` ajax action with `context_type=grounds_for_appeal`.

## v4.0.96 — 28 Apr 2026

### Fixed
- **BUG: Complaint / Appeal save error — PARAM_ALPHANUMEXT on reference field** — editing an existing complaint (or appeal) whose reference contained slashes, spaces, or dots (e.g. `COMP/2025/001`, `COMP.2025.001`) caused the form to silently sanitise the reference to `COMP20251` on submission. The duplicate-reference validation then compared the sanitised value against records in the DB (which still held the original value), either returning a false "duplicate reference" validation error that prevented saving, or — in rare cases where another complaint happened to match the stripped version — a `dml_multiple_records_exception` PHP error. Fix: `setType('reference', PARAM_TEXT)` in both `complaint_form.php` and `appeal_form.php` preserves the reference value exactly as entered.
- **complaint_edit.php: DB save now shows the real error** — wrapped `update_record` / `insert_record` in a `try/catch (\dml_exception)` block so that any unexpected database error is surfaced as a readable Moodle notification (redirecting back to the complaint edit page) instead of an unformatted fatal PHP error page.

### Added
- **AI assist — Resolution Details (complaint)** — a "Generate with AI" button appears directly below the Resolution textarea in the Resolution Details section of complaint_edit.php. Clicking reads the complaint's category, subcategory, subject, description, priority, and status then calls the platform AI (`lms-labs.com/api/moodle/course-assistant/chat`) and inserts a professional 150–250 word resolution draft into the textarea. Requires `local_aiconfig` (site ID + API key) to be configured.
- **AI assist — Appeal Outcome Reason** — identical "Generate with AI" button below the Outcome Reason textarea in appeal_edit.php, using appeal type, grounds for appeal, original decision, and outcome as context.
- **ajax.php: `generate_resolution` action** — new AJAX handler powering both AI assist buttons above. Sends an ASQA-framed prompt to the platform AI and returns the generated text. Works for both `context_type=complaint` and `context_type=appeal`.

## v4.0.95 — 28 Apr 2026

### Fixed
- **BUG: improvement_form.php QuickForm duplicate element name** — `improvement_edit.php` (both Add Improvement Action and Edit) threw a `PEAR_Error: element 'actionplan' already exists in HTML_QuickForm::addElement()` on every page load. Root cause: the section header element on line 108 and the textarea element on line 110 both registered under the name `'actionplan'`. Moodle's QuickForm does not allow two elements to share a name. Fix: header element renamed to `'actionplan_hdr'`. This eliminates both the PHP error and the "screen flash" visible to users before the form rendered correctly.

## v4.0.94 — 28 Apr 2026

### FEAT: AI Suggest on Training Transitions form

The Transition Plan and Additional Information (Notes) fields on the Training Transitions edit form now have AI Suggest sparkle buttons — identical to the AI Suggest experience on TAS forms, trainer edit, and other compliance pages.

**How it works:**
- AI button appears to the right of the `Transition Plan` textarea and below the `Notes` textarea
- Clicking generates a single ASQA-guided suggestion (5 credits) using the ASQA Standard 1.26 / Standard 1.12 compliance context already configured for the `transitionplan` field
- The AI automatically picks up the old product code/name, new product code/name, and transition type as context, so suggestions are specific to the actual products being transitioned
- The Notes field uses the existing generic compliance notes guidance
- 5 credits per generation, keyword refinement supported

**Files changed:** `transition_edit.php`, `js/ai_suggest.js`

---

## v4.0.93 — 28 Apr 2026

### FIX: Delete button hover — text was invisible (red-on-red)

The qualbuilder table had a scoped CSS override on `.btn-outline-danger:hover` that set `color: #b91c1c !important` (dark red text) while the global rule applied a solid red background — making the "Delete" label invisible on hover.

Fixed by updating the qualbuilder hover rule to match the global pattern: red gradient background + white text + matching red border.

**Files changed:** `styles.css`

## v4.0.92 — 28 Apr 2026

### RULE9B: ASQA Class 9B Building Classification — Delivery Locations

New "Rule 9B" column in the Delivery Locations table showing whether each location holds the required Class 9B building classification for VET delivery.

**What was added:**

**DB:** New `rule9b_approved` (TINYINT, default 0) field on `local_rtocompliance_locations`. Upgrade step `2026042800101` adds the column via `xmldb_field` with guard.

**Edit form (`location_edit.php`):**
- New collapsible "ASQA Compliance — Rule 9B Building Classification" header section
- `advcheckbox` field with built-in Moodle help button
- Help text explains Class 9B under the National Construction Code (assembly/education buildings) and the ASQA Standards requirement

**Locations table (`locations.php`):**
- New "Rule 9B" column rendered before Actions
- **Green badge** (✓ *Rule 9B Approved*) — gradient green background, emerald text, subtle shadow; hover tooltip explains full ASQA requirement
- **Red badge** (✗ *Not 9B Approved*) — gradient red background, dark red text; hover tooltip prompts admin to update the record
- Inline SVG icons (checkmark / ✕) sized to match the badge font

**CSS (`styles.css`):**
- `.rtoc-badge` — base inline-flex pill style shared for future compliance badges
- `.rtoc-badge--9b-yes` — green gradient + emerald border/text + hover shadow
- `.rtoc-badge--9b-no` — red gradient + rose border/text + hover shadow

**Lang (`lang/en/local_rtocompliance.php`):**
- `rule9b_header`, `rule9b_approved`, `rule9b_approved_help`, `rule9b_badge_yes`, `rule9b_badge_no`, `rule9b_col`

**Schema (`db/install.xml`):** `rule9b_approved` added after `status` field.

## v4.0.91 — 28 Apr 2026

### FIX: Vocational Competency Evidence checkboxes — left-aligned

The checkbox group rendered by Moodle's form API was centre-aligned.
Added CSS rules targeting `#fgroup_id_vocationalcompetencygroup .felement` to force left-alignment, matching the rest of the trainer edit form.

**Files changed:** `styles.css`

## v4.0.90 — 28 Apr 2026

### FIX: Vocational Competency column — JSON display corruption

**Problem:** The `vocationalqualifications` DB field sometimes stores JSON objects (e.g. from a TGA lookup or import): `{"code":"BSB50420","title":"Diploma of Business"}`. The table renderer was truncating the raw JSON string mid-way, displaying `{"code":"BSB50420","title":"` as readable text.

**Fix in `trainers.php`:**
- New helper `rtoc_decode_vocqual()` — silently decodes JSON; supports single objects `{code, title}`, arrays of qualification objects, and plain-text (no change for existing data).
- If JSON decodes cleanly → displays as `BSB50420 — Diploma of Business` (hover title shows full text).
- If JSON is malformed / no readable text could be extracted → shows amber warning icon + "Qualification not recorded — update profile" with instructive hover tooltip instead of raw garbage text.
- Plain-text values (the majority of existing records) continue to display unchanged.

**Files changed:** `trainers.php`

## v4.0.89 — 28 Apr 2026

### ROLE-TIPS

ASQA practice guide hover tooltips on trainer/assessor role badges (1A, 1B, 1C, 1D, 1E, 2A, 2B, 2C, 3A, 3B) in the Trainer & Assessor Register table.

**What was added:**
- Each badge now carries a `data-rtoc-tip` attribute with full ASQA Standards context
- A JS floating tooltip engine (appended to `<body>`) renders multi-paragraph tooltips positioned above/below the badge with automatic flip and viewport clamping
- Keyboard accessible (focusin/focusout) and screen-reader compatible (`role="tooltip"` + `aria-label`)
- Tooltip auto-hides on scroll/resize

**Tooltip content per role code (ASQA Trainer & Assessor Qualifications Practice Guide):**
- **1A** — Independent Trainer & Assessor. Full TAE + vocational qual + industry currency. Clause 1.13 (2015) / Standard 3 (2025)
- **1B** — Trainer Only. Full TAE + pairs with assessor. Cannot assess independently. Clause 1.14 / Standard 3.3
- **1C** — Working Towards TAE (holds vocational qual). Supervised delivery. Clause 1.14 exception / Standard 3.4
- **1D** — Working Towards TAE (industry expert, no qual). Closely supervised. Clause 1.14 exception / Standard 3.4
- **1E** — Secondary/Tertiary Teaching Qualification. TAE equivalent. Clause 1.13 / Standard 3.1
- **2A** — Industry Expert (no TAE). Training support only, paired with TAE trainer. Clause 1.14 / Standard 3.3
- **2B** — Industry Expert (Assessment Support). Under direction of assessor. Clause 1.15 / Standard 3.5
- **2C** — Industry Expert (Assessment Judgement Only). Supervised. Clause 1.15 / Standard 3.5
- **3A** — Validator with TAE. Can lead validation. Clause 1.9 (2015) / Standard 1.9 (2025)
- **3B** — Industry Expert Validator. Participates under TAE-qualified lead. Clause 1.9

**Files changed:** `trainers.php`, `styles.css` (CSS + JS inline)

## v4.0.88 — 28 Apr 2026

### AI-SUGGEST-FIX

Fixed "AI Suggest" button on trainer Delivery Scope / Notes fields throwing "Unknown field" error.

**Root cause:** The server-side `TAS_AI_FIELD_CONFIGS` registry in `routes.ts` was missing entries for:
- `notes` — generic compliance note field (used in Delivery Scope, Risk, RPL forms)
- `scopenotes` — Approved Delivery Scope trainer field

When the JS called `/api/rto/ai-suggest` with `field: "notes"`, the server returned `{ success: false, error: "Unknown field: notes" }` which the modal displayed as an error.

**Changes:**
- `server/routes.ts`: Added `scopenotes` config (Standard 3/1.13 ASQA guide — trainer delivery scope documentation) and `notes` config (general compliance record-keeping guidance) to `TAS_AI_FIELD_CONFIGS`
- `js/ai_suggest.js`: Added `scopenotes` to client-side `FIELD_REGISTRY` so the "Approved Delivery Scope" textarea also gets an AI Suggest button (previously only `notes` did)

No DB schema changes.

## v4.0.87 — 28 Apr 2026

### USI-VERIFY-DISPLAY

Comprehensive USI verification status display in students.php, modelled on VETtrak/WISENET/aXcelerate patterns.

**students.php changes:**
- SQL SELECT now includes `s.usiverifieddate`
- New `$usicell` logic renders five distinct states by `usiverified` integer code:
  - **0 — Not yet verified:** amber shield badge + "Not yet verified" + blue "Verify via usi.gov.au →" button
  - **1 — Verified:** green shield badge + "Verified via **usi.gov.au**" + verification date
  - **2 — Failed:** red circle-X badge + "Verification failed" + "Retry ↻" button
  - **3 — Pending:** blue clock badge + "Verification pending"
  - **4 — Manual review:** purple star badge + "Needs manual review" + "Verify" button
- USI identifier displayed in `<code class="rtoc-usi-code">` monospace chip
- Inline SVG icons (shield, checkmark, clock, circle-X) embedded per badge — no external icon dependency
- Three new filter dropdown options: *USI Verified (usi.gov.au)*, *USI Not Yet Verified*, *USI Verification Failed*
- SQL filter conditions added for `usiverified=1`, `usiverified=0` (with USI present), `usiverified=2`
- Inline AJAX handler: clicking any verify button GETs `student_usi_verify.php?ajax=1`, shows spinner, replaces cell HTML in-place without page reload

**New file — student_usi_verify.php:**
- Accepts `profileid` (INT) + `sesskey` (CSRF) + `ajax` flag
- Requires `local/rtocompliance:manage` capability
- Calls `usi_verification_service::verify_student_usi($id)`
- Returns JSON `{success, html, message}` on ajax=1; redirects with notification otherwise

**styles.css:** Added ~100 lines of USI badge CSS (`.rtoc-usi-code`, `.rtoc-usi-badge`, status variants, `.rtoc-usi-verify-btn`, `.rtoc-usi-spinner`, keyframe `rtoc-usi-spin`)

## v4.0.86 — 28 Apr 2026

### CSS-FIX

Left-aligned disability type checkboxes in the student profile form. Previously the "Disability Type" checkbox group (Physical, Intellectual, Learning, Mental Illness, etc.) was centred in the form element column. Fixed by adding targeted CSS rules to `styles.css` targeting `#fgroup_id_disabilitytypesgroup` and `.felement.fgroup` to enforce `text-align: left` and `align-items: flex-start`.

- version.php → 2026042800095.

---

## v4.0.85 — 28 Apr 2026

### QPR-GROUP-FIX

Fixed Group A/B/C/D elective units not appearing in QPR (Qualification Packaging Rules) validation results.

**Root cause**: The TGA unit grid REST API (`/api/unitgrid`) only returns `isEssential: true/false` — it never includes group letters (A, B, C, D…). Group assignments are only present in the TGA content bundle HTML, encoded as `<strong>Group A Animation</strong>` headings above `<ntr-tcref data-nrt-code="XXXX">` unit code elements.

**Fixes applied**:

1. **`tgaService.ts` — `parseUnitGroupsFromHtml()` (new function)**: Splits the content bundle HTML on `<strong>Group [A-Z]…</strong>` headings to build a `unitGroupMap: Record<string, string>` (unitCode → groupLetter). A two-pass strategy is used: the first pass scans **all** bundle items for group headings; the second pass finds the packaging rules text item. After the packaging rules try/catch, group letters are applied to units — overwriting the placeholder `"Elective"` value with the actual letter (`"A"`, `"B"`, etc.).

2. **`server/routes.ts` — `enrichedUnits` group extraction**: Updated `groupCode` extraction regex to also accept a bare single uppercase letter as a valid group code (e.g. `u.group = "A"` after the tgaService fix), in addition to the previous `"Group A"` / `"A - "` patterns.

3. **`packagingrules_validator.php` (applied in v4.0.84)**: Added DB fallback to infer group requirements from stored `electivegroup` values when the TGA API returns empty `groupRequirements`, plus last-resort inference with `min: 0`.

- version.php → 2026042800094.

---

## v4.0.84 — 28 Apr 2026

### AUDIT-FIX-8BUGS

Full Moodle data linkage audit completed pre-deployment — 8 bugs fixed:

1. **qualbuilder_results.php — undefined `$context` (Critical)**: `$PAGE->set_context($context)` referenced `$context` which was never declared in this file. `admin_externalpage_setup()` does not set a local `$context` variable. Fixed: replaced with `$PAGE->set_context(context_system::instance())`.

2. **process_enrolment_task.php — only first unit per course auto-created (Critical)**: `get_record_sql` (singular) was used to find qual units for a course. A single Moodle course linked to two or more qual units across different qualbuilder entries would only auto-create ONE RTO enrolment (the first unit), not one per unit. Fixed: changed to `get_records_sql` + loop; one `local_rtocompliance_enrolments` row is now inserted per linked qual unit, matching the behaviour of the manual CSV import. Extracted `resolve_commencing_id()` private helper to avoid code duplication. Duplicate-key guard on each insert.

3. **qualbuilder_courses.php autodetect — CSRF vulnerability (High)**: The autodetect action (which writes `courseid` values to `local_rtocompliance_qualunits`) was triggered by a plain GET link with no sesskey check. Fixed: added `require_sesskey()` at action entry and embedded `sesskey()` in the generated link URL.

4. **enrolment_form.php — all visible courses loaded into `<select>` (High)**: `$DB->get_records_menu('course', ['visible' => 1])` with no LIMIT loaded every visible Moodle course into the form. On sites with thousands of courses this causes slow page loads. Fixed: query now returns only courses linked to a qual builder unit or flagged nationally-recognised in course settings; falls back to all visible courses if neither table has data (clean installs).

5. **student_enrolments.php — all courses loaded for name lookup (High)**: `$DB->get_records_menu('course', null)` loaded every course (including hidden ones) to display course names in the student enrolment table. Fixed: query now scoped to only the courseids present in the student's enrolment records using `get_in_or_equal`.

6. **trainers.php countsql — missing `{user}` JOIN inflates paging count (Medium-High)**: The count SQL (`SELECT COUNT(*) FROM {local_rtocompliance_trainers} t`) omitted the `JOIN {user}` that the main SELECT includes. Orphaned trainer records (userid deleted from Moodle users) were counted but not returned, causing the paging bar to report more pages than actually exist. Fixed: `countsql` now includes `JOIN {user} u ON u.id = t.userid` before the WHERE clause.

7. **observer.php `user_enrolment_deleted` — tasks queued for ALL courses (Medium)**: The deletion observer queued a withdrawal task for every course unenrolment on the entire Moodle site, even non-RTO courses. `user_enrolment_created` had a proper nationally-recognised/qual-builder filter, but `user_enrolment_deleted` did not. Fixed: added the same filter — returns early if the course is neither nationally recognised nor linked to a qual unit.

8. **qualbuilder_results.php — enrolment lookup drops duplicate unitcodes (Medium)**: Both the HTML table and the CSV export used `$DB->get_records('local_rtocompliance_enrolments', [...], '', 'unitcode, outcomeidentifier')`. Moodle's `get_records()` keys the result array by the first specified field (`unitcode`); if a student has two enrolments for the same unitcode (e.g., one active and one withdrawn), only the last DB row was retained — silently dropping the other. Fixed: replaced with `get_records_sql()` using a `CASE status` priority sort (active → completed → hold → withdrawn) and explicit `first-wins` keying, so the most relevant enrolment is always shown.

- version.php → 2026042800090.

---

## v4.0.83 — 28 Apr 2026

### FIX-DROPDOWN-BODY-APPEND

- **Trainer & Assessor Register — Edit button**: Replaced Bootstrap dropdown with a custom body-appended action menu. The menu is now a plain `<div>` appended directly to `document.body` using `position: absolute` with document-relative coordinates, so it escapes ALL overflow containers and CSS transform stacking contexts that Moodle themes may apply. Fully visible on first click.
- **Student Records — Actions button**: Same custom body-appended menu approach. Click Actions ▼ to see Edit Profile and Enrolments links, always fully visible regardless of horizontal scroll position or theme transforms.
- **student_support.php — duplicate Diversity & Inclusion Policies section removed**: The policy list (Open PDF / Download links) on the Student Support page was a duplicate of the card in Marketing Information → Standard 2.1 Cards. The section is now removed from Student Support. Policy management is handled exclusively via the Diversity and Inclusion Policies (Standard 2.5) card which links to the RTO website.
- version.php → 2026042800089.

---

## v4.0.82 — 27 Apr 2026

### FIX-MARKETING-POLICIES

- **Standard 2.1 cards → Policies card redesigned**: Renamed from "Pre-Enrolment Documents (Policies)" to "Diversity and Inclusion Policies (Standard 2.5)" to match ASQA Standard 2.5 labelling.
- **Inline policy list removed**: The card previously listed all policy PDFs with Open/Download buttons — the same list shown again when clicking Show Evidence. This redundant section is now removed.
- **Show Evidence** now links to "Declaration by Students" (student_declaration_send.php) rather than repeating the policy list.
- **RTO website link** now shown in the card body — "Policies are published on the RTO's website. Access via the Policies menu item there." with an Open RTO Website button. If the RTO website URL is not configured in RTO Settings, a warning is shown instead.
- version.php → 2026042700086.

---

## v4.0.81 — 27 Apr 2026

### BUMP

- Version increment only — no code changes. Ensures Moodle upgrade detection fires cleanly on all installations upgrading from v4.0.80 or earlier. version.php → 2026042700083.

---

## v4.0.80 — 27 Apr 2026

### ALL-13-COLUMNS-FLAT

**Trainer & Assessor Register**
- Table redesigned from 8-column + expandable detail row to all 13 columns visible in the main table per document specification
- Columns in order: Trainer Name, Role, TAE Credential, TAE Achieved, Status under TGA, Vocational Competency, Units Being Delivered, LLN Capability, VET Currency, Industry Currency, CPD Points, Next Review Date, Edit Trainer
- Expandable detail row (▶/▼ toggle) removed entirely
- Table min-width updated from 860px to 1500px; horizontal scroll remains active

---

## v4.0.79 — 27 Apr 2026

### TRAINER-REGISTER-REDESIGN + STICKY-ACTIONS-COL

**Trainer & Assessor Register**
- Reduced from 15 visible columns to 8 primary columns: Trainer Name, TAE Credential, Status, WWCC, Police Check, CPD Hours, Next Review, Actions
- Secondary compliance fields (Role, TAE Achieved, Vocational Competency, Units Being Delivered, Industry Experience, LLN Capability, VET Currency Date, Industry Currency, Credential Policy) now shown in an expandable detail row per trainer — toggled with a ▶/▼ button in the Actions column
- trainers-table CSS min-width reduced from 1600 px to 860 px now that the column count matches real-world screen widths

**Student Records + Trainer Register — Sticky Actions Column**
- Actions column is now position:sticky on the right edge in both the Student Records table and the Trainer Register table — it stays visible at all times regardless of how far left the user has scrolled, with a subtle left-side shadow to signal there is more content to the left
- Edit/Delete buttons are therefore always reachable on any screen width without scrolling to the rightmost edge

---


# RTO Compliance - Changelog

## [4.0.78] - 2026-04-27

### Bug Fixes

- **FIX-RTO-TABLE-OVERFLOW** (`styles.css`):
  Student Records and Trainer & Assessor Register tables were clipping their
  rightmost columns instead of scrolling horizontally. Two root causes:
  (1) The `.rtoc-table-wrapper` override block at the end of `styles.css` was
  missing `width: 100%` — in flex/block contexts the wrapper could shrink below
  the table `min-width`, so the scroll threshold was never reached.
  (2) `white-space: nowrap` was only applied to `thead th` cells; `tbody td`
  cells were free to wrap text, which made columns narrow and appear invisible.
  Fix: added `width: 100%` to the wrapper rule and added
  `white-space: nowrap` to ALL `th` and `td` cells inside `.rtoc-table-wrapper`
  for both `generaltable` (student records) and `trainers-table` (trainer register).
  The trainers table retains its `min-width: 1600px` floor; the students table
  retains `min-width: 1000px`. Both tables now produce a horizontal scrollbar
  when the viewport is narrower than those thresholds.

- **FIX-RTO-DECL-SELECT** (`student_declaration_send.php`):
  The Student Declaration send page previously showed "Sending to: 60 students"
  with a single "Send Declaration" button that would email every non-admin student
  regardless of whether they had already been sent or completed the declaration.
  Replaced the bulk-send confirmation UI with a full interactive selection table:
  - Filter bar with counts: All / Not Sent / Sent—Pending / Completed
  - Search box (name or email)
  - Per-student rows with declaration status badge, date sent, and date completed
  - Checkbox per row (students who have already Completed are pre-disabled to
    prevent accidental re-send)
  - "Select all visible" header checkbox with indeterminate state support
  - Sticky send bar at top showing live count: "Send Declaration to N selected"
  - Button is disabled until at least 1 student is checked
  The POST handler now accepts `userids[]` (array of selected IDs) instead of
  `userid=0` (all students). Existing deduplication logic (skip if pending or
  already agreed) is preserved. Single-student shortcut from student profile
  pages (`userid=N` param) continues to work. No DB schema changes.

## [4.0.77] - 2026-04-27

### Fixed
- **Problem 4 — Student Obligations "Send Declaration" blocked**: `student_declaration_send.php` required `managestudents` capability which doesn't exist in `access.php`. Changed to `local/rtocompliance:manage` so admin users can reach the send page.
- **Problem 5 — Trainer/Assessor field drops entire enrolment save**: `assessoruserid` select had no `setType(PARAM_INT)` in `enrolment_form.php`, causing the "None" option (`''`) to be written into an INT foreign-key column. DB strict mode coerces empty string to 0, violating the FK constraint and silently rolling back the full `update_record` call. Added `setType('assessoruserid', PARAM_INT)` and a `null` guard before DB save.
- **Problem 1 — State-specific fields hidden in student profile**: The "State-Specific Fields" section (QLD LUI, VIC Cohort ID, NSW Smart & Skilled ID, WA RAPT ID) had no `setExpanded(true)` call. Once a user collapsed that section, Moodle's user-preference system kept it collapsed on every return visit. Added `$mform->setExpanded('statespecific', true)` to force it open.

## [4.0.76] - 2026-04-27

### Changed
- Version bump — no code changes. Increments plugin timestamp so Moodle upgrade
  detection fires cleanly for sites upgrading from before v4.0.75.

## [4.0.75] - 2026-04-27

### Fixed
- **URL scheme guard (marketing_cards.php)**
  — Added `trim()` before the scheme check so leading/trailing whitespace in the
  saved setting no longer produces `https:// nct.edu.au`. Changed regex from
  `#^https?://#` to `#://#` so non-http schemes (ftp://, rtsp://, etc.) are passed
  through unchanged rather than receiving a spurious `https://` prefix.
- **Action dropdown: stale `position:fixed` after rapid open/close**
  — The previous `show.bs.dropdown + requestAnimationFrame` approach queued a rAF
  that fired AFTER `hide.bs.dropdown` had already cleared the inline styles, leaving
  `position:fixed` set on a closed menu. Replaced with `shown.bs.dropdown` (fires
  after the menu is fully rendered) — no rAF needed, styles are correct first time.
  Removed the redundant second `show.bs.dropdown` registration. Applied to both
  students.php and trainers.php.
- **Action dropdown: menu overflows viewport bottom and right edges**
  — Added viewport flip/clamp logic in the `shown.bs.dropdown` handler. Menu now
  flips above the toggle when it would overflow the bottom of the screen, and
  clamps to the right edge when it would overflow horizontally. Both checks use
  accurate `menu.offsetHeight`/`menu.offsetWidth` values (available in `shown`
  but not in `show`).
- **Students page: negative `$page` parameter accepted by PARAM_INT**
  — `PARAM_INT` does not reject negative values. `$page = -1` produced a negative
  DB offset which Moodle DML clamped silently, but the paging bar rendered garbled
  links. Added `max(0, ...)` guard.
- **CSS: table visually detaches from container on wide screens**
  — `min-width: 1000px` left a wide blank strip beside sparse tables on 1440px+
  screens. Changed to `min-width: max(1000px, 100%)` so the table fills the full
  container when the screen is wide and still overflows to trigger scroll when
  narrow. Same change applied to trainers table floor (`max(1600px, 100%)`).
- **Statistics cards: "Students with Profile" could exceed "Total Students"**
  — `$stats['withprofile']` was a raw count of all `local_rtocompliance_students`
  records with no role/trainer filter. If a trainer had a student profile record,
  the counter was higher than `$stats['total']`. Applied the same trainer-exclusion
  LEFT JOIN so both headline numbers are consistent.
- **Declaration send: crash when student user is deleted mid-session**
  — `core_user::get_user()` returns `false` for a deleted or non-existent user.
  Calling `fullname(false)` immediately throws "Accessing property of non-object".
  Added explicit `=== false` guard that renders a safe "student not found" message
  with a link back to Student Records instead of crashing.

## [4.0.74] - 2026-04-27

### Fixed
- **Table columns hidden / no horizontal scroll (Students + Trainer & Assessor pages)**
  — Root cause: existing CSS rule sets `width:100%` on `.generaltable` and `.trainers-table`
  with equal specificity to our scroll-wrapper rule, so that property was never overridden.
  Tables always filled their container exactly, so `min-width: 1000px/1600px` never exceeded
  the container and the scroll wrapper never activated. Fixed by adding `width: auto !important`
  inside `.rtoc-table-wrapper table` so each table expands to its natural/min-width and the
  wrapper scrolls horizontally as intended.
- **Show Evidence button → 404 on Moodle server**
  — If the RTO website URL was saved without a scheme (e.g. `nct.edu.au` instead of
  `https://nct.edu.au`), browsers resolve it as a relative path on the current server.
  Fixed by auto-prepending `https://` when the stored value matches no `http(s)://` prefix.
- **Student Declaration send — debug warning on single-student send page**
  — `core_user::get_user()` returns NULL for phonetic name fields when users never set them.
  PHP `isset()` returns false for NULL, so `fullname()` fired the "missing fields" debug
  warning on the GET render path. Fixed by normalising the 4 fields to `''` after fetch.
- **Teachers appearing in student records**
  — Extended Moodle role shortname exclusion to include `trainer`, `assessor`,
  `trainerassessor` (common custom RTO role names). Additionally added a LEFT JOIN against
  `local_rtocompliance_trainers` so anyone registered as a trainer in the RTO plugin is
  excluded from student records regardless of their Moodle role assignment. Both fixes
  applied to the stats query (Total Students card) and the main listing query.

## [4.0.73] - 2026-04-27

### Fixed
- **Action button dropdowns inaccessible in Student Records and Trainer & Assessor pages**
  — Root cause: Bootstrap dropdown-menus are `position:absolute` inside
  `.rtoc-table-wrapper` which sets `overflow-x:auto`. Per the CSS spec, when
  `overflow-x` is non-visible, `overflow-y` is forced to `auto` too, creating a clip
  boundary that hides the dropdown before users can interact with it. Fixed in both
  `students.php` and `trainers.php` by adding a JS event listener on
  `show.bs.dropdown` / `shown.bs.dropdown` that repositions the menu to
  `position:fixed` (aligned to the toggle button's viewport coordinates), escaping
  the scroll container entirely. On `hide.bs.dropdown` / `hidden.bs.dropdown` the
  inline styles are removed, restoring default Bootstrap behaviour for the next open.

## [4.0.72] - 2026-04-27

### Fixed
- **CSS wrapper spacing** — `.rtoc-table-wrapper` now carries `width: 100%` (prevents
  collapse inside flex containers) and `margin-bottom: var(--rtoc-space-lg)` to compensate
  for the `margin: 0` applied to child tables. Without this, content below the scroll
  container was flush against the table bottom.
- **CSS duplicate block removed** — redundant `.rtoc-table-scroll` rule block in section 15
  (lines 3323–3332) fully removed; section 7 is the single authoritative source.
- **Marketing cards — dead `courseLine` variable** — `buildCards()` was computing a course
  code/title string that was assigned to a local variable and never referenced anywhere.
  Removed to keep the function clean.
- **Marketing cards — phantom whitespace on Training Product card** — `renderCards()` was
  emitting an empty `<div>` with `line-height:1.6` when `content` is an empty string,
  adding a few pixels of gap between the title and the Show Evidence button. Div is now
  skipped when `content` is falsy.
- **students.php `$perpage` unbounded** — a crafted URL with `perpage=999999` could cause
  a single query to load every user row. Clamped to the range 10–200.

## [4.0.71] - 2026-04-27

### Fixed
- **Student list teacher filter** — main query upgraded from `NOT IN (SELECT ...)` to
  `LEFT JOIN` derived table with BOTH `shortname` AND `archetype` exclusion. Previously
  teachers/managers with non-standard role shortnames could appear in the student list
  because the archetype check was missing from the list query (it was only in the stats query).
- **Table horizontal scroll** — `students.php` `generaltable` `min-width` raised to 1000 px;
  `trainers.php` 15-column `trainers-table` `min-width` set to 1600 px. Combined with the
  existing `overflow-x: auto` on `.rtoc-table-wrapper`, columns no longer disappear off the
  right edge. Added `-webkit-overflow-scrolling: touch` for smooth iOS scrolling.
- **Marketing Information page** — removed the AI Auto-Fill intro card and the Compliance
  Self-Check section. Training Product Information card simplified to title + Show Evidence
  button only (no course code/duration/mode/location detail rows).
- **Student Declaration email** — `$tempuser` now carries the four extended Moodle name
  fields (`firstnamephonetic`, `lastnamephonetic`, `middlename`, `alternatename`) copied
  from the SQL-fetched `$u` object. Eliminates Moodle `fullname()` debug warning.
- **Survey send email** — external-recipient `$tempuser` in `survey_send.php` gains the
  same four name fields (set to empty string). Eliminates the same `fullname()` debug
  warning for employer/third-party survey recipients.

## [4.0.70] - 2026-04-27

### Fixed
- **Student stats query performance** — `NOT IN (SELECT ...)` correlated subquery
  replaced with a `LEFT JOIN` derived table (`staff.userid IS NULL`). MySQL no longer
  re-executes the inner query for every outer row. Result is identical; query is
  significantly faster on installs with thousands of users.
- **Trainer delete — GET → POST** — the Delete Trainer action is now a `<form
  method="post">` instead of a GET hyperlink. A browser prefetch engine or automated
  link scanner could have silently deleted a trainer record by following the old GET
  URL. `confirm_sesskey()` in `trainer_edit.php` accepts `sesskey` from POST params
  with no other changes required.

## [4.0.69] - 2026-04-27

### Fixed
- **Missing lang string `invalidtoken`** — invalid or expired declaration links now
  show a clean, readable message to students instead of a PHP fatal error page.
- **Declaration resend deduplication** — `student_declaration_send.php` now checks
  for an existing pending (`status='sent'`) or completed (`agreed=1`) declaration
  record before inserting a new one. Students who already have an outstanding or
  completed declaration are skipped; the success message reports how many were sent
  vs skipped.
- **Removed misleading `NO_LOGIN_REQUIRED` constant** from `student_declaration_respond.php`
  — it is not a Moodle constant and had no effect. Replaced with a comment explaining
  the intentional no-login pattern.
- **Duplicate `$siteadminlist` build** in `student_declaration_send.php` refactored —
  the site-admin exclusion list is now built once at the top of the file and reused
  in both the POST send path and the GET preview query (`$siteadminlistp` removed).
- **Policy URL settings** — `PARAM_URL` replaced with `PARAM_LOCALURL` for all five
  policy document URL admin settings so that relative paths like `/files/policy.pdf`
  are accepted in addition to full `https://` URLs. Field descriptions updated to
  explain both formats.

## [4.0.68] - 2026-04-27

### Fixed
- Bootstrap 4/5 compatibility: dropdown buttons in `students.php` (Actions menu)
  and `trainers.php` (Edit menu) now carry both `data-toggle` + `data-bs-toggle`
  and `dropdown-menu-right` + `dropdown-menu-end` so they work correctly on
  Moodle 4.x (Bootstrap 4) and Moodle 5.x (Bootstrap 5).

## [4.0.67] - 2026-04-26

### Changed
- Version bump: version.php → 2026042600067, BUILD_INFO.json, CHANGELOG.md and
  db/upgrade.php all synced. No code changes — ensures Moodle recognises a new
  release on environments that missed v4.0.66.

## [4.0.66] - 2026-04-26

### Fixed
- Student Records action buttons → Bootstrap dropdown so Edit Profile and Enrolments
  are reachable on every screen width.
- Student stats count now excludes teacher/manager roles by shortname AND archetype.
- Training Product Info "Show Evidence" links to the RTO public website; shows amber
  notice if the URL is not yet configured in settings.
- Student Obligations card: "Send Declaration" button added; new
  `student_declaration_send.php` and `student_declaration_respond.php` implement the
  ASQA-prescribed 7-item checklist with per-item ticks, typed signature and timestamp.
  DB: `local_rtocompliance_declarations` table created on first use.
- Policy links render Open PDF + Download buttons when a URL is configured; show a red
  "not configured" notice with a link to RTO Settings when blank.
- Trainers Edit cell is now a dropdown (Edit Trainer / Delete Trainer) labelled primary
  blue and always accessible.
- `survey_send.php` inserts with `status='sent'` (was `'pending'`) — dashboard count
  is now always accurate.
- `qi_export.php` flushes the output buffer before sending CSV headers for a clean
  download with no leading HTML.

## [4.0.61] - 2026-04-23

### Changed
- Version bump: all 7 release locations synced (version.php, db/upgrade.php, BUILD_INFO.json,
  CHANGELOG.md, pluginConfig.ts, server/routes.ts, public/downloads/ ZIP). No code changes
  beyond the three bug fixes already applied in v4.0.60. version.php → 2026042300061.

## [4.0.60] - 2026-04-23

### Fixed
- **TAS Generator accordion sections won't open**: Bootstrap's `stretched-link::after`
  pseudo-element (on `a.fheader`) was escaping its containing block because `.ftoggler`
  lacked `position:relative`. The `::after` expanded to cover a large ancestor element
  (z-index:1), intercepting and swallowing click events before they reached the accordion
  toggle logic. Fixed by adding `position:relative` to `.ftoggler` so the stretched-link
  is properly scoped to the header row only.
- **Validation Register / Locations — buttons not clickable**: Two compounding issues:
  (1) `overflow-x:hidden` on `.rtoc-main-content` was implicitly forcing `overflow-y:auto`
  (per CSS spec), creating an unintended internal scroll container that broke
  `position:fixed/sticky` scoping and could intercept pointer events from outside the
  container's clip region. Replaced with `overflow-x:clip` which clips without creating a
  scroll container (no BFC side-effects). (2) Gradient hero header buttons had `z-index:1`
  which could lose to undeclared-z-index pseudo-elements in certain stacking contexts.
  Raised button `z-index` to 2 and added explicit `pointer-events:auto !important` to
  guarantee clickability in all browser stacking scenarios.
- **Collapsible sections — JavaScript fallback**: Added a self-contained plain-JS
  fallback handler (`initCollapsibleFallback`) that directly toggles `fieldset.collapsible`
  sections on header click. This ensures TAS accordion sections open/close even if
  Moodle's `core/collapsible_section` AMD module is delayed or fails to initialise due
  to a RequireJS race condition. The fallback is idempotent — it detects and defers to
  Moodle's own handler if it fires first. No PHP, DB, or capability changes.

## [4.0.59] - 2026-04-22

### Fixed
- **CRITICAL SUPERBUG — menus and navigation unclickable on 32 sub-pages**: All edit and
  sub-pages of the RTO Compliance plugin (trainer_edit, tas_edit, qualbuilder_edit,
  supervision_edit, complaint_edit, appeal_edit, audit, auditlog, alerts, ai_analysis,
  deadlines, feeprotection_edit, governance_edit, improvement_edit, insurance_edit,
  issue_certificate, location_edit, qi_report, qualbuilder_courses, qualbuilder_unit,
  qualbuilder_validate, student_enrolments, survey_responses, survey_send,
  tas_consultation, tas_export, thirdparty_edit, trainer_currency, trainer_voccomp,
  transition_edit, validation_edit, validator_edit) were using raw `require_login()` +
  `require_capability()` + `$PAGE->set_context()` + `$PAGE->set_pagelayout('admin')`
  instead of `admin_externalpage_setup()`. This prevented Moodle from initialising the
  admin navigation tree on these pages, causing all Moodle toolbar menus, breadcrumbs,
  and navigation buttons to render broken or completely unclickable. Fixed by replacing
  the legacy auth block with `admin_externalpage_setup()` using the appropriate parent
  page key for each file. Pages with tighter capabilities (:managetrainers,
  :managesurveys, :issuecerts, :viewall) retain their explicit `require_capability()`
  call after `admin_externalpage_setup()`. The same root cause was previously fixed for
  the 19 main list pages in v4.0.16 — now addressed comprehensively for ALL 32
  remaining admin sub-pages. No DB schema changes.

All notable changes to this plugin will be documented in this file.

## [3.8.61] - 2026-04-02

### Fixed
- Sidebar not displaying on Moodle admin pages — definitive three-layer fix.
  1. `render_nav_header()` now calls `inject_sidebar_once()` directly so every page that renders a nav header injects the sidebar straight into the page body immediately, instead of relying solely on `before_footer` callbacks (which can silently fail on certain Moodle themes/configurations). `index.php` (dashboard), `data_import.php`, and `testing.php` also inject the sidebar directly after `$OUTPUT->header()` since they do not call `render_nav_header()`.
  2. The JS init block now immediately moves `#rtoc-sidebar`, `#rtoc-sidebar-overlay`, and `#rtoc-mobile-btn` to be direct children of `document.body`. This is a critical fix: Moodle Boost and some custom themes apply CSS `transform` to `.drawers-fixed` or other ancestor elements; when a `position: fixed` element is inside a transformed ancestor it renders relative to that ancestor instead of the viewport, causing the sidebar to appear off-screen.
  3. Critical CSS properties on `#rtoc-sidebar` (`display`, `position`, `z-index`, `top`, `left`, `width`) now include `!important` to survive any theme-level CSS overrides. The collapsed-width rule also gains `!important` to correctly override the base rule when the sidebar is toggled closed.
- `testing.php` was missing `$PAGE->add_body_class('path-local-rtocompliance')`, preventing `styles.css` scoped rules from applying on that page.
- Fixed hook class docstring which incorrectly stated only "Table sorting JavaScript" is injected (sidebar is also injected).

## [3.8.50] - 2026-04-02

### Added
- **AVETMISS Data Import** — full NAT file import pipeline now lives inside Moodle (no longer hosted externally on lms-labs.com).
  - New `data_import.php` page registered as a Moodle admin external page (`local_rtocompliance_dataimport`).
  - PHP NAT file parser supports: `NAT00010` (RTO details), `NAT00080` (student demographics), `NAT00085` (contact details), `NAT00120` (training activity/enrolments), `NAT00130` (qualification completions).
  - Files grouped by timestamp suffix to process multi-year exports correctly.
  - Upload form accepts multiple `.txt` files; RTO identifier and collection year are auto-detected.
  - Import history list with summary counts (students, enrolments, completions, flagged records).
  - Detail view with tabbed Students / Enrolments / Completions panels, search, and per-import delete with confirmation.
  - Students flagged automatically when USI is missing, DOB is absent, sex is unspecified, or `@@` placeholder markers are present.
- **4 new DB tables**: `local_rtocompliance_avetmiss`, `local_rtocompliance_avetmiss_student`, `local_rtocompliance_avetmiss_enrolment`, `local_rtocompliance_avetmiss_completion`.
- **42 lang strings** added.
- `settings.php` nav entry updated to point to `/local/rtocompliance/data_import.php`.
- `lib.php` sidebar link updated to use local path.
- Upgrade savepoint `2026040200108`.

## [3.8.49] - 2026-04-02

### Added
- **Bulk suitability checklist sending** — send the pre-enrolment checklist to multiple students at once from `students.php`.
  - Per-row checkboxes + Select All header checkbox.
  - Sticky bulk action bar appears when one or more students are selected; shows count and **Send Suitability Checklist** button.
  - New `suitability_bulk.php` handles `bulk_send` action (sends to all selected students) and `fill_gaps` action.
  - **Fill Compliance Gaps** admin button sends to all students who have not yet received a checklist for the current qualification; eligible students (no existing suitability record) are auto-detected.
  - **Auto-send on enrolment** setting: when enabled, the checklist is emailed automatically at the moment of Moodle course enrolment; the qualification used is selected via a configurable TAS dropdown in settings.
  - Helper functions (`local_rtocompliance_get_or_create_suitability_checklist_items`, `local_rtocompliance_send_suitability_checklist`) moved to `lib.php` for reuse across pages.
- Upgrade savepoint `2026040200107`.

## [3.8.48] - 2026-04-02

### Added
- **Pre-Enrolment Suitability Checklist** — gate enrolments against TAS entry requirements before a student is formally enrolled.
  - `suitability_send.php`: admin selects a student + qualification, previews the auto-generated Yes/No questions parsed from the TAS `entryrequirements` field, and sends a token-linked email.
  - `suitability_form.php`: public page (no Moodle login required); student answers each requirement Yes/No, signs a declaration, and submits.
  - `suitability_view.php`: admin view of all answers with read-only badge table; for `not_suitable` records an override form with mandatory notes textarea is shown.
  - All-Yes → `status='suitable'`. Any-No → `status='not_suitable'` + admin notification email with failed requirements and direct override link.
  - Admin override records who overrode, when, and the written justification (`status='override_suitable'`).
  - `students.php` updated with **Suitability** column showing badge + action button per status (Send / Awaiting + Resend / Suitable + View / Not Suitable + View Override / Override: Suitable + View).
- **2 new DB tables**: `local_rtocompliance_suitability` (one row per send: student, qualification, token, status, override notes, timestamps) and `local_rtocompliance_suitability_answers` (one row per question/answer pair).
- **31 lang strings** added to `lang/en/local_rtocompliance.php`.
- Upgrade savepoint `2026040200106`.

## [3.8.47] - 2026-04-02

### Added
- **Smart Cohort & Entry Requirements Builder** in `tas_edit.php`: structured multi-step entry requirements block with cohort audience selector (school leavers, career changers, industry workers, international students), language/literacy/numeracy toggles, prerequisite qualification fields, and specific entry criteria checklist.
- **`tas_consultation.php`**: new consultation helper page with structured dropdowns for feedback channels (12 types), training delivery modes (10 options), and assessment methods (10 options).
- Upgrade savepoint `2026040200105`.

## [3.8.1] - 2026-03-28

### Changed
- **VERSION-BUMP**: Routine release confirming all v3.8.0 Smart Qualification Builder deliverables are correctly packaged and served. AMD `nominalhours_autofill.js` verified: `src` = `build` = `min` (md5 `a8ebe23fd8e5cb0a61499d4a030a5a5a`). No code changes. No DB schema changes.

## [3.8.0] - 2026-03-28

### Added
- **Smart Qualification Builder** (`qualbuilder_edit.php` rewritten): Enter a TGA code and click **Load from TGA** to auto-populate the qualification name, AQF level, nominal hours, and packaging rules in one step — no manual entry needed.
- **Group-aware unit sections**: Units from TGA are now displayed in the exact groups that appear in the Training Package — Core, Group A, Group B, Group C, Group D, General Electives, and Imported Units — matching the official packaging structure.
- **Live compliance dashboard**: Real-time green ✓ / amber ⚠ / red ✗ status cards for each packaging rule (core units, group requirements, total units, Moodle links) update instantly as you select or deselect units.
- **Inline Moodle course mapping**: Each unit row now has a course dropdown filtered to the selected Moodle category. Switching the category refreshes all dropdowns simultaneously.
- **Moodle category auto-suggestion**: Keywords from the qualification title are matched against existing Moodle categories and a suggested category appears as a click-to-accept pill.
- **One-click atomic save**: A single AJAX call (`qualbuilder_auto_build`) saves the product metadata and all units in one transaction — no page reload, no multi-step workflow.
- **New Moodle web service** `local_rtocompliance_tga_get_builder_data`: fetches TGA packaging data, grouped units, AQF level, and Moodle category/course lists in one call.
- **New Moodle web service** `local_rtocompliance_qualbuilder_auto_build`: atomically saves or updates a training product and its complete unit list.
- **New Express endpoint** `GET /api/tga/qualbuilder/:code`: returns qual details, packaging rules, and grouped/classified units from the TGA REST API.

## [3.7.98] - 2026-03-28

### Fixed
- **NCVER nominal hours lookup**: Added `apiurl` setting to the API Settings admin page (`Site admin → Plugins → Local plugins → AI RTO Compliance → API Settings`). Previously the API base URL had no admin UI entry, so it could never be saved to the Moodle plugin config table — `get_config('local_rtocompliance', 'apiurl')` always returned `false` and fell through to the hardcoded fallback. While the fallback was correct, some Moodle setups require the setting to be explicitly stored. Admins can now configure the API base URL (default: `https://lms-labs.com`) via the standard Moodle admin interface.

## [3.7.97] - 2026-03-28

### Fixed
- **RTO-USI-005 false positive**: `usi_pending` diagnostic test was counting ALL students with `usiverified = 0` (the default for every row), not just students who actually have a USI value entered. On a site with 51 students but only 2 USIs entered, the test was reporting "51 unverified" — a completely misleading result. All five status counts (`verified`, `unverified`, `failed`, `pending`, `review`) now use `count_records_select` with `usi IS NOT NULL AND usi != ''` scope. The output message is also corrected from the confusing "X student(s) have a USI entered but have never been verified (of Y with USI)" to the clearer "X of Y student(s) with a USI entered have not yet been verified".

## [3.7.96] - 2026-03-28

### Changed
- **VERSION-BUMP**: Routine release. Adds the missing `upgrade.php` savepoint for v3.7.95 (`2026032700017` block was absent), which caused an upgrade loop on sites updating from v3.7.94. No code changes. No DB schema changes.

## [3.7.95] - 2026-03-27

### Fixed
- **Testing Engine — 5 failing automated tests**:
  1. `infra_caps`: Added missing `viewcerts` and `viewstudents` capabilities to `access.php` so the capability check passes.
  2. `qual_table`: Corrected table names to `qualbuilder` / `qualunits` matching the actual DB schema (was using wrong names).
  3. `comp_risk`: Corrected table name to `local_rtocompliance_risks` (was using wrong name).
  4. `trainer_credentials`: Fixed test data generator to create trainers with `status = active` so the credential coverage check has trainers to assess.
  5. `nat_locations`: Test data generator now creates 3 WA delivery locations so the NAT00020 check has data to validate.

## [3.7.94] - 2026-03-27

### Added
- **Testing Engine** (`testing.php`): Full QA testing panel accessible via RTO Compliance → Testing Engine in Moodle admin. Runs 37 automated tests covering Infrastructure, Students, Qualifications, Certificates, Trainers, NAT/AVETMISS Export, Quality Indicators, 10 Compliance document registers, CRICOS, USI, and Audit Logging. Tests perform real DB checks against live Moodle data. Features: per-test approval system with notes, full test history log, localStorage state persistence (`rto_testing_state_v2`), AJAX-driven execution with sesskey, and "Run Full System Test" mode.
- **Settings nav entry**: `settings.php` updated with `local_rtocompliance_testing` admin page pointing to `testing.php`, positioned between Transitions and Help & Support.
- **Lang strings**: `$string['testing']` and `$string['testing_desc']` added to `lang/en/local_rtocompliance.php`.
- **upgrade.php savepoint**: `2026032700016` block added (no DB schema changes required).

## [3.7.93] - 2026-03-27

### Fixed
- **Missing DB tables for existing installations**: Four tables defined in `install.xml` (created on fresh installs only) were absent from `upgrade.php`, causing `dml_read_exception` errors on existing sites: `local_rtocompliance_locations`, `local_rtocompliance_cricos_attendance`, `local_rtocompliance_cricos_progress`, `local_rtocompliance_cricos_scv`. All four now created in the v3.7.93 upgrade step with full DDL matching `install.xml`. Guard: `if(!$dbman->table_exists(...))` prevents re-creation on fresh installs.

### Added
- **Nominal hours NCVER auto-lookup** in Qualification Builder (`qualbuilder_edit.php` + `qualbuilder_unit.php`): "Lookup NCVER Hours" button next to the Nominal Hours field. Entering a qualification or unit code triggers an NCVER lookup (on blur + 800 ms debounce) and fills the field automatically. Implemented via new `local_rtocompliance/nominalhours_autofill` AMD module.

## [3.7.81] - 2026-03-27

### Fixed
- **NULL SAFETY**: Added `empty($PAGE->url)` guard before `$PAGE->url->get_path()` in both
  `classes/hook/before_footer_html_generation.php` and `lib.php` — prevents fatal PHP error
  if `$PAGE->url` is not yet initialised when the hook fires

## [3.7.80] - 2026-03-27

### Fixed
- **CRITICAL SUPERBUG — admin menus unclickable on all RTOC pages**: All 19 main RTOC pages
  were missing `admin_externalpage_setup()`, so Moodle never initialised the admin navigation
  tree. The admin navbar rendered empty/broken, making every menu button unclickable.
  Fixed by replacing `require_login() + require_capability() + $PAGE->set_context() +
  $PAGE->set_pagelayout('admin')` with `admin_externalpage_setup('local_rtocompliance_XXX')`
  on all 19 pages. Pages with tighter capabilities (issuecerts, managetrainers, exportnat,
  managesurveys) retain a `require_capability()` after the setup call. Pages with dynamic
  URL params (tab/pagination) retain a `$PAGE->set_url()` after setup.

## [3.7.78] - 2026-03-26

### Fixed
- **CRITICAL BUG FIX**: Site admin primary/secondary navigation menus STILL hidden on RTO Compliance pages
  - ROOT CAUSE: The debug error popup (position:fixed; z-index:99999) in the Moodle 5 hook callback had an unscoped DOMContentLoaded PHP error scanner that queried ALL `.alert-warning` and `.alert-danger` elements on the page. Standard Moodle notification elements (e.g., "Configure RTO details" warning) matched, triggering the full-screen overlay that covered navigation menus
  - v3.7.76 only scoped `window.onerror` and `window.fetch` — the DOMContentLoaded scanner and the overlay itself were never removed
  - FIX: Completely removed the debug error popup from `classes/hook/before_footer_html_generation.php` — only table sorting JS remains
  - The legacy lib.php callback (Moodle 4.x) already had only table sorting and was not affected

## [3.7.42] - 2026-01-01

### Fixed
- **CRITICAL BUG FIX**: TAE status calculation now uses TAE expiry date (not nextreviewdate)
  - Previously: Status was incorrectly calculated from `nextreviewdate` field
  - Now: Status is calculated from `taeexpirydate` field with correct logic:
    - No expiry date = **Current** (TAE qualifications typically don't expire)
    - Expiry date in future = **Current**
    - Expiry within 30 days = **Expiring**
    - Expiry in past = **Expired**
    - No TAE credential = **Missing TAE**
- **CRITICAL BUG FIX**: `credentialrole` field expanded from VARCHAR(5) to VARCHAR(255)
  - Previously: Saving multiple roles like "1A,1B,3A,3B" caused database error
  - Now: Comma-separated roles save correctly

### Added
- **TAE Expiry Date** field in trainer edit form with clear guidance
- **Debug Tooltips** on trainer status badges showing calculation reasoning:
  - Hover over status badge or ⓘ icon to see:
    - TAE Credential value
    - TAE Date Achieved
    - TAE Expiry Date
    - Next Review Date (informational)
    - Today's date
    - Calculation logic used
    - Final result
- **Status Reason Text** displayed below status badge for maximum visibility
- **Missing TAE** status for trainers without TAE credential or "Working Towards"

### Changed
- Form now shows clear explanation of TAE expiry date purpose
- Status display includes calculation reasoning for tester debugging

## [3.7.27] - 2025-12-25

### Fixed
- CRITICAL: TAS Export now correctly reads data from individual database columns instead of non-existent JSON `sections` field
- Added `get_section_content()` function to map TAS sections to correct database fields (targetcohort, entryrequirements, industryconsultation, etc.)
- Fixed Trainers & Assessors "Filter by Status" dropdown - boolean attributes now conditionally added instead of using false value
- Fixed HTML select attribute bug where `selected="false"` was still selecting options

## [3.7.26] - 2025-12-25

### Added
- Credential Policy column showing manager sign-off status in Trainers & Assessors table

### Fixed
- Trainer status filter now uses correct conditional attribute rendering for Moodle html_writer

## [3.7.25] - 2025-12-22

### Changed
- Migrated `before_footer` callback to Moodle 5.0+ hook system
- Added `classes/hook/before_footer_html_generation.php` for Moodle 5 compatibility
- Updated `db/hooks.php` to register new hook callback
- Legacy callback in lib.php now skips when Moodle 5 hook is available
- Full backward compatibility with Moodle 4.x maintained

## [3.7.20] - 2025-12-18

### Fixed
- Improved qualbuilder_results.php navigation to show qualification code in breadcrumb

## [3.7.19] - 2025-12-18

### Fixed
- Fixed "Error reading from database" on governance.php Material Changes tab
- Corrected column names: `effectivedate`, `notificationdeadline`, `asqanotificationdate`

## [3.7.18] - 2025-12-18

### Fixed
- CRITICAL: Added missing `require_once(__DIR__ . '/lib.php')` to 17 pages
- Fixed "undefined function local_rtocompliance_render_nav_header" errors on:
  - audit.php, complaints.php, feeprotection.php, governance.php
  - insurance.php, natexport.php, qualbuilder.php, students.php
  - supervision.php, support.php, surveys.php, tas.php
  - thirdparty.php, trainers.php, transitions.php, validation.php
- Complete navigation header audit of all 50+ pages
- Fixed practice_guides.php - incorrect argument order for nav header function

## [3.7.16] - 2025-12-18

### Fixed
- Ensured nav header function loads correctly (caching issue fix)
- Fuzzy/blurry text on Get Started Guide step cards - removed backdrop-filter blur effect
- Added font smoothing properties for crisp text rendering
- Increased text contrast and added subtle text shadows for better readability

## [3.7.14] - 2025-12-18

### Fixed
- Better error handling for qualbuilder_results.php when accessed without required ID parameter
- User-friendly redirect with error message instead of cryptic "required parameter missing" error
- Added language strings for error messages

## [3.7.13] - 2025-12-18

### Fixed
- Added nav header with breadcrumbs to practice guide detail pages (e.g., /practice_guides.php?guide=training)
- Breadcrumb now shows full hierarchy: Dashboard / Practice Guides / [Guide Name]
- Removed duplicate "Back to Practice Guides" link (navigation is now in header bar only)

## [3.7.12] - 2025-12-18

### Fixed
- Consistent 14px base font size across all pages (practice_guides.php was using 11-15px)
- Moved inline styles from practice_guides.php to styles.css for consistency
- Standardized typography: 14px body text, 12px meta/badges, 16px+ headings

## [3.7.11] - 2025-12-18

### Fixed
- Removed duplicate "Back to Dashboard" buttons from practice_guides.php, alerts.php, audit.php
- All pages now have consistent navigation using only the nav header bar

## [3.7.10] - 2025-12-18

### Added
- Navigation header with Dashboard button and breadcrumbs on ALL 50+ pages
- Sub-pages show parent page in breadcrumb trail for easy navigation
- "Getting Started" link in admin menu now goes directly to Dashboard

### Changed
- Renamed "Quick Access" to "Getting Started" in navigation menu
- Dashboard link now appears at top of admin menu

## [3.7.9] - 2025-12-18

### Fixed
- **CRITICAL**: Fixed fatal error "Cannot require a CSS file after `<head>` has been printed" that broke the plugin
- Removed CSS loading from nav header function (CSS is auto-loaded by Moodle from styles.css)
- Removed custom styling on Site Administration pages - now uses standard Moodle admin styling as requested

### Changed
- Admin category pages (Site Administration > RTO Compliance) now use default Moodle styling
- Reduced styles.css by 156 lines (removed `.path-admin` selectors)

## [3.7.8] - 2025-12-18

### Added
- Consistent navigation header with Dashboard button across all plugin pages
- Breadcrumb navigation showing current page location
- Help button in navigation header linking to support page

### Changed
- All pages now have quick access back to the Compliance Dashboard

## [3.7.7] - 2025-12-17

### Added
- Get Started banner on Dashboard with 6-step workflow guide
- Quick access cards linking to key setup tasks
- Visual step indicators with icons

## [3.7.6] - 2025-12-17

### Added
- Premium glassmorphism UI styling
- Responsive card layouts for all management pages
- Status badges with color-coded indicators
- Search and filter functionality on list pages

## [3.7.5] - 2025-12-16

### Added
- Training and Assessment Strategy (TAS) document generator
- PDF export for TAS documents
- Support page with documentation links

## [3.7.0] - 2025-12-15

### Added
- Qualification Builder with training.gov.au integration
- Unit of Competency management
- Course linking for qualifications
- Packaging rules validation

## [3.6.0] - 2025-12-14

### Added
- Student Results tracking system
- Enrolment management
- Certificate issuance and verification
- USI verification integration

## [3.5.0] - 2025-12-13

### Added
- Trainer compliance management
- Supervision log tracking
- Credential expiry monitoring
- Scheduled status update tasks

## [3.0.0] - 2025-12-10

### Added
- Initial ASQA 2025 compliant release
- AVETMISS 2.3 NAT file export
- Quality Indicator surveys
- Complaints and appeals register
- Third-party arrangements tracking
- Governance and ADC management
- Fee protection register
- Insurance register
- Validation scheduling
- Audit logging system
