## [v6.6.1] - 2026-09-16

Documentation and the AI assistant caught up with the exemption pathway, plus two
user-visible text defects. No behavioural change and no schema change.

### Fixed — the assistant contradicted the page

- **The assistant reported a different outstanding figure from the page the admin was
  looking at.** The USI Verification page now excludes recorded exemptions from *No USI
  recorded*; the assistant still counted every blank identifier field. The two numbers
  disagreed with nothing to say which was right. It now reports three distinct facts —
  the adjusted figure, the exempt figure, and the unadjusted total — and names which one
  the page is showing.
- **A breakdown by exemption ground** was added, so an offshore cohort that is *not*
  actually recorded as offshore is visible rather than buried in a single total.
- **It told an admin standing on an exempt student's record that the student had no
  identifier and could not be issued.** True of the field, false of the outcome — an
  exemption has cleared the issuance gate since v6.3.19. It now says the student meets
  the gate and explains why.
- **Six releases were invisible to the assistant.** Release notes are parsed out of
  `version.php`, and no note had been recorded there for v6.4.7 through v6.5.2 — so the
  assistant knew nothing about the saved-view work, the import fix, or the date-of-birth
  fix. All six are now recorded. The v6.6 note was present but indented by one space,
  which the parser's line anchor rejected, so it was silently skipped too.

### Fixed — documentation

- **`docs/usi-verification.md` made four false claims about USI exemptions:** that the
  exemption was captured only, that the plugin's student table had no column for it, that
  it was written to no NAT file, and that it did not release the certificate. All four
  were true when written and none is true now. The section is rewritten, and the two
  exemption grounds, the count card and the three filters are documented.
- **`docs/students-and-avetmiss.md`** now documents residential country and the overseas
  postcode value — what each is for, which view reads them, and why the postcode is gated
  on the country.

### Fixed — text defects, both pre-existing

- **A unicode escape sequence sat unprocessed in a PHP single-quoted string**, where the
  language does not interpret it, so the exemption-type language string carried a literal
  escape where a dash belonged.
- **The unit search box on the Statement of Attainment page** rendered a literal escape
  instead of an ellipsis, for the same reason: a JavaScript escape written into an HTML
  attribute.

## [v6.6] - 2026-09-16

Offshore online delivery: exempt from holding a Unique Student Identifier.

The plugin could already record *that* a student was exempt — a flag, a free-text
reason, who granted it and when. Nothing in the system did anything with it. The
collection standard defines a code to lodge in the identifier field for an exempt
client, and the exporter never consulted the flag, so it wrote ten blank bytes where
that code belongs. The USI page never consulted it either, so an exempt student was
counted under "No USI recorded" as though they were a compliance failure.

### Added

- **Exemption type.** A free-text reason cannot be turned into a reportable token
  without pattern-matching prose, so the code is now stored as its own value
  (`INTOFF` offshore, or `INDIV` individual) while the reason stays as the human
  explanation for audit. Existing exempt students are backfilled to `INTOFF`, the
  only ground the interface has ever described.
- **Residential country** on the student profile, drawn from the same area-code list
  as country of birth. It had no control anywhere in the interface, so it was empty
  for every student while still being exported — yet it is the field that establishes
  offshore study.
- **Offshore Online Delivery USI Exempt** count card and filter on the USI
  Verification page, so these students can be seen and filtered out. It matches on
  any of three grounds: the `INTOFF` code, a residential country outside Australia,
  or an overseas postcode.
- **Any recorded exemption** and **unadjusted no-USI** filters alongside it, so the
  adjusted and raw figures can be compared directly.

### Fixed

- **"No USI recorded" now excludes recorded exemptions.** The headline outstanding
  figure no longer counts students who are not required to hold an identifier. The
  unadjusted figure is still available as its own filter.
- **The overseas postcode value was rejected.** `OSPC` is a literal, not a number,
  and is required alongside the offshore code — both validators demanded four digits,
  so an offshore student could not be recorded correctly at all. It is now accepted,
  and only when the residential country is outside Australia, so it cannot be used to
  make a domestic student look exempt.
- **The export now lodges the exemption code** in the identifier field and pairs the
  overseas postcode in both the client and the enrolment record, as the standard
  requires. A real identifier always wins: the code is only ever lodged *in place of*
  an identifier the student does not have, never over one they do.
- **Date-of-birth off-by-one.** The date control stored midnight in the operator's
  timezone while the exporter formatted in Australia/Sydney, so a date entered from
  another timezone could export as the previous day. Dates are now re-encoded at
  midday Sydney on save.
- **The importer fabricated identifiers.** It searched each fixed-width line for
  anything USI-shaped, which matched suburb text — `PARRAMATTA` and `NKALLANGUR` both
  pass a USI character test. It now reads the identifier from its defined position,
  confirmed by three neighbouring anchor fields, and only falls back to searching when
  the layout cannot be confirmed. Exemption codes in the file are no longer discarded.

## [v6.5.2] - 2026-09-16

A second, separate cause of the same symptom.

### Fixed

- **A table's own key or id was not authoritative.** The column headings were appended
  to the identity even when an explicit key or id existed, which defeated the point of
  having one. A table with a column that appears only under some conditions changed
  identity when that column appeared, taking the saved-view namespace with it. One table
  does exactly this - a Qualifications column shown only when a qualification file is
  present. A key or id is now the identity on its own, and headings are the fallback
  only for tables carrying no key.

### Corrected

The v6.5.1 note said the arrow-in-heading fault affected three pages. It affected **two**
- USI Verification and Certificates. The Statement of Attainment page already wraps its
indicator in the recognised icon class and was never affected, and the alignment control
on the certificate template editor is a button label, not a table heading.

### Verified

The identity of the affected table computed with and without its conditional column
present now yields one value where it previously yielded two. Tables with no key still
fall back to headings, confirmed separately.

### Note

A view saved before this release on a page whose table carries an id needs saving once
more.

No schema change. Savepoint 2026091602.

## [v6.5.1] - 2026-09-16

A remembered saved view was lost when leaving the page and coming back.

### Fixed

- **The sort arrow was being folded into the table's identity.** The remembered view
  is stored against a page and a table, and a table with no explicit key is identified
  by its column headings. Several pages render the current-sort arrow as a character
  inside a plain styled span, and the identity routine strips decorative *elements* but
  not decorative *characters* - so the arrow became part of the identity. That identity
  changed whenever the sort column or direction changed, moving the saved-view namespace
  with it and making the remembered view invisible.

  This is why the fault looked like it only happened on navigation. A refresh keeps the
  same address and therefore the same sort, so the identity was unchanged and the view
  came back. Opening the page fresh used the default sort, produced a different identity,
  and found nothing remembered.

  The identity routine now strips sort-indicator characters as well as elements, so a
  table keeps one identity whichever column it is sorted by.

### Verified

The same heading normalised in its four sort states - ascending, descending, unsorted
and plain - now yields one identical value. Before the fix it yielded four different ones.

### Note

Any view saved on the **Certificates**, **USI Verification** or **Statement of
Attainment** pages before this release needs saving once more. The corrected identity is
a different namespace from the unstable one it replaces.

No schema change. Savepoint 2026091601.

## [v6.5] - 2026-09-16

Release roll-up. Four fixes, no schema change.

- **Saved views stay where you left them.** Applying a view is remembered against
  your account, so it survives a refresh, a new tab and a logout. A "Page default"
  button clears it.
- **The AVETMISS import no longer invents student identifiers.** The USI is read at
  its defined field position instead of being searched for by pattern, and the
  published exemption markers are recorded rather than discarded.
- **Imported USIs and residential country now reach the student record.** Both were
  missing from the staging sync. The USI fills blanks only and never overwrites.
- **Date of birth is no longer exported a day out** for users outside the export
  time zone.

Full detail for each is in the v6.4.8 and v6.4.9 entries below.

No schema change. Savepoint 2026091600.

## [v6.4.9] - 2026-09-16

The AVETMISS import was inventing student identifiers out of address text.

### Fixed

- **The NAT00080 reader searched for a USI instead of reading the USI field.** It
  scanned each record for any ten characters from the permitted set and voted on which
  offset looked most popular. That scan window covers the suburb field, and a ten-letter
  suburb drawn from the permitted set is indistinguishable from a real USI under a format
  test alone. Confirmed on a live site: `PARRAMATTA`, `NGREENACRE`, `NKALLANGUR` and
  `NPARANAQUE` had been stored as USIs, and comparing stored values against the lodged
  file found **40 distinct values that appear nowhere in that file** - all manufactured
  by the scan.
- **The same routine read twelve bytes and accepted ten to twelve.** Positions 160-161
  hold the State identifier, and state code `99` is made of permitted characters, so a
  clean ten-character USI followed by state 99 was stored as a twelve-character value.
  Three student records carried one.

### How it works now

A NAT00080 record is fixed width. The USI occupies positions 150-159 and nothing else,
so when the layout is confirmed those ten bytes are read directly and no searching
happens. The layout is confirmed from three independent anchors that cannot all hold by
chance - Gender at 73, Date of birth at 74-81, Postcode at 82-85. If any anchor fails,
the record is not this layout and the previous methods run unchanged, so vendor exports
in other shapes are unaffected.

`INTOFF` and `INDIV` are no longer discarded silently. They are not identifiers and are
not stored as one, but each is recorded against the record so the exemption is visible.
Ten bytes that are neither blank, an exemption, nor valid are recorded as unreadable
rather than triggering a search that would invent a replacement.

### Verified

The live 6,244-record file was re-parsed with the position voter **deliberately pointed
at the suburb field**. All 6,244 records came back identical to a raw read of positions
150-159 - nothing fabricated, nothing lost.

No schema change. Savepoint 2026091514.

## [v6.4.8] - 2026-09-16

Imported USIs and residential country now reach the student record.

### Fixed

- **`local_rtocompliance_sync_student_demographics_from_staging()` omitted `usi` and
  `residentialcountry` from its SELECT list**, though both columns exist in the staging
  table and on the student record. An imported USI was parsed, stored in staging, and
  then never copied any further - and `residentialcountry` was left blank for every
  student on the site, which is why the offshore USI-exemption filter had nothing to
  read. On the reference site this cost 32 students a USI they had already supplied.

### Added

- **A chosen saved view now survives a refresh, a new tab and a logout.** Applying a
  view records it in a Moodle user preference, so it is reapplied the next time that
  page is opened with no filters of its own. Previously only the views themselves
  persisted - which one was active did not, so every page load returned to the default.
  A new "Page default" button clears the remembered view. Two new endpoint actions,
  `remember` and `forget`, neither of which accepts any state: they can only point at a
  view the user already owns in that exact page and table namespace.
- Redirect loops are structurally impossible rather than merely unlikely: the reapply
  navigation always stamps a marker on the URL, whether or not the view contributes a
  single query parameter, and the marker's presence is what suppresses a second reapply.
  Deleting the remembered view clears the preference, and a view whose payload has been
  purged is healed the next time the list is read. The preference is included in the
  privacy export and erasure paths alongside the existing two.

- **Date of birth was being lodged a day out for any user not in the export timezone.**
  `nat_generator::formatdate()` renders dates in a hardcoded `Australia/Sydney`, but
  Moodle's `date_selector` encodes the chosen day as *midnight in the user's own
  timezone*. Those are different instants, so for a user east of Sydney the exported
  calendar date fell on the previous day - NAT00080 positions 74-81, and every
  downstream identity match built on it. Reproduced and fixed: a date of birth is now
  stored at midday in the export timezone, which survives an eleven-hour shift in
  either direction without crossing into another calendar day, and the calendar day is
  re-derived in the timezone it was entered in so the day the user picked is the day
  that is kept. Verified across five timezones including a leap-day date. Applied at
  save time only - historical values are deliberately left untouched.

### Safety

- **The USI is fill-blank-only and is never overwritten.** A USI already on a record may
  have been verified against the USI Registry, so a stale or mis-keyed staging row must
  not be able to replace a verified identifier. The new branch requires the stored value
  to be empty *and* the incoming value to satisfy NCVER's format rule - exactly ten
  characters from A-H, J-N, P-Z and 2-9, never 0, 1, I or O.
- **`INTOFF` and `INDIV` are deliberately refused** by this path. They are AVETMISS
  exemption codes, not identifiers, they belong in the exemption columns, and writing
  one into `usi` would fail every downstream format check.
- `residentialcountry` carries no verification state, so it follows the same real-value
  rule as the other demographics and skips blanks and `@` placeholders.

### Verified

Four cases run through the real sync function against a database, not a mock: a blank
record takes the imported USI, a populated record is left untouched, an exemption code
is refused, and a ten-character value containing the forbidden letters is refused.

No schema change. Savepoint 2026091513.

## [v6.4.7] - 2026-09-15

The 6.4.6 blocker was in 6.4.6's own release note. No functional change.

### Fixed

- **`version.php` is a `.php` file, so the pipeline scans it** - and the note describing
  6.4.6's parameter-type fixes quoted the very type names the security check looks for,
  plus a no-space function example and a callable-dispatcher name. Three hits, all of
  them prose inside that file, no code involved. The phrases are reworded to describe
  the fixes without quoting the tokens.

  **The underlying mistake is worth recording:** when replicating the pipeline's checks
  locally to confirm 6.4.6 was clean, `version.php` was excluded from every search,
  on the reasoning that it is a changelog rather than logic. The pipeline makes no such
  distinction, so that verification could not have caught what the pipeline caught.
- **The safe-object-decode annotation in `classes/task/process_enrolment_task.php`** sat
  on the lines *above* the call rather than on the same line - the same placement error
  made with the saved-view JSON annotation in 6.4.6. The pipeline's same-line convention
  is now followed in both places. The two explanatory comments in that file which *named*
  the decode function were reworded for the same reason as the `version.php` prose: a
  comment mentioning a flagged function reads to the scanner exactly like a call to it.

### Note

`CHANGELOG.md` keeps the real parameter type names. It was present in the 6.4.6 package
carrying the same tokens and was not flagged, which establishes that the scan covers
`.php` and not `.md`.

### Verified

Every check re-run across the whole tree with **no file excluded**: zero hits for
unfiltered parameter types, request-data superglobals, PHP function spacing, blank lines
after a class brace, and AMD function spacing.

### Schema

None.

## [v6.4.6] - 2026-09-15

Release pipeline: two approval blockers and two style errors. No functional change.

### Fixed — approval blockers

- **Unfiltered request data.** `program_recognition.php` read the qualification code
  with `PARAM_RAW_TRIMMED`. Now `PARAM_TEXT` - not `PARAM_ALPHANUMEXT`, which would
  strip the colon from an RTO's internal key and the dot from a code like a page
  reference, turning a value the site legitimately uses into one it cannot match.
- **Request superglobals** in `recognition_ajax.php` and `saved_views_ajax.php`. Every
  value is now read through `optional_param()`: the session key as `PARAM_ALPHANUMEXT`,
  `action`/`page`/`table` as `PARAM_TEXT` deliberately (each is then validated against
  its own strict allow-list, so the job at this layer is to avoid **mangling** a
  legitimate value), view ids as `PARAM_ALPHANUM` since they are 12 hex characters, and
  the saved-view JSON blob as `PARAM_RAW` with the pipeline's same-line ignore comment -
  it is size-checked, then decoded with `JSON_THROW_ON_ERROR` and a depth limit, and any
  cleaning applied first would corrupt valid JSON. The saved-view deny-list, which
  refuses any request naming another user or an export, used to enumerate submitted
  request keys; it now asks for each forbidden name through `optional_param()`.

  **Both endpoints keep their POST-only check.** It was removed first and that was
  wrong: `$_SERVER` appears in around forty places in this plugin and the pipeline
  flagged only these two files, so the rule targets request *data*, not the request
  method. Dropping the check would have accepted a GET carrying a session key in the
  URL, where it is exposed to referrer headers and access logs.

### Fixed — coding style

- `function (` spacing in `classes/local/codelist_audit.php`,
  `classes/local/saved_views.php` and `recognition_ajax.php`.
- No blank line after a class opening brace, in 12 files.
- `call_user_func()` in `register_lookup.php` replaced with direct invocation of the
  injected test transport.
- The three AMD modules and their builds normalised to `function(` with **no** space -
  the opposite convention to PHP, and applying to `amd/` only. 900 replacements, every
  file re-parsed afterwards.
- `saved_views_ajax.php` now documents **in the file** why it has no top-level
  `require_capability()`: saved views span pages with *different* access rules, so one
  blanket check would state an access rule some of those pages do not have. The check is
  per page, via `can_access_page()`, before anything is read or written.

### Note — warnings deliberately left

The ALL-CAPS string warnings are acronyms (AVETMISS, TAE). The 21 multi-line-call and 59
comment-capitalisation warnings are pre-existing cosmetic style spread across files this
release does not otherwise touch; churning them would risk more than it fixes.

### Verified

Each failed check replicated locally - zero hits for all four. Both endpoints re-tested
end to end: GET refused, missing and bad session key refused, list/save/delete
round-tripping, the deny-list still firing, and a page value containing a dot and a table
key containing a colon both surviving intact.

### Schema

None.

## [v6.4.5] - 2026-09-15

AI assistant knowledge, brought up to date with everything added in 6.3.33 - 6.4.4.

### Added

- **`docs/program-recognition.md`.** Program recognition arrived in 6.3.36 as an entire
  new concept with a page of its own and no documentation at all, so the assistant could
  not answer a single question about it. The document covers why accreditation is looked
  up rather than asked about, why *unclassified* is a real state rather than a failure,
  why a lookup that finds nothing does **not** mean not-recognised, that classification
  does **not** change NAT files and why that is deliberate, that the USI gate treats
  unclassified the opposite way on purpose, where the codes are discovered from
  (including the qualification/course tree map), and that skill sets and single units
  resolve on the register the same way qualifications do. Routed to 7 pages.
- **The no-USI-on-documents rule**, added to `docs/certificates-usi-gate.md`. This is
  the likeliest support question after 6.3.36 - *"the USI used to be on our Record of
  Results and now it is gone"* - and the assistant had no answer for it. The section
  gives ASQA's rule, frames it as the mirror image of the gate the rest of that document
  describes (a student must **have** a verified USI; the document must **not show** it),
  lists what was removed, and states that already-issued documents are not
  retrospectively changed and need reissuing.
- **The twelve outcome identifiers**, added to `docs/students-and-avetmiss.md`, with the
  dead codes named and dated - `50` deleted 2007, `53`/`54` deleted 2012, `90` deleted
  2018, `10` recoded 1999-2002, `00` never existed - and the code-list integrity report
  pointed at. It closes with the distinction that caused an argument worth recording:
  AVETMISS governs the NAT files, **not** what is printed. The reporting standard defines
  no transcript abbreviations at all.
- **Live recognition facts on the relevant pages.** `page_facts()` returned nothing for
  `program_recognition.php`, so the assistant could explain what classification means but
  not answer "how many of mine are unclassified?". It now reports the three counts, how
  many unclassified codes actually carry activity (an unclassified code with no
  enrolments costs nobody anything), and the count of enrolments with no program code -
  on `program_recognition.php`, `nat_validate.php` and `natexport.php` only.

### Verified

- The release notes needed no work: `knowledge.php` parses them out of `version.php`, so
  every build already explains itself the moment it is installed.
- Docs route correctly to 7 pages; knowledge base is 35,606 of its 160,000 characters.
- Facts are correct on the three pages and absent on unrelated ones.
- With the recognition table dropped, `page_facts()` returns 0 facts and no exception.

### Schema

None.

## [v6.4.4] - 2026-09-15

Two defects, both found while checking 6.4.3 over for a live install.

### Fixed

- **The plugin claimed something about NAT files that was not true, in five places.** The
  upgrade banner, the Program Recognition page intro, the "Programs unclassified" tooltip
  on Student Records, the classify CLI help and `requires_avetmiss()`'s own docblock all
  said an unclassified program is *excluded* from NAT files until somebody classifies it.
  It is not. `nat_generator.php` does not reference the recognition class at all -
  "recognition" appears in it only inside comments - so nothing was ever held back. On a
  compliance product that is the worst kind of wrong: an auditor reading that tooltip
  would assume activity was being withheld while it was being lodged.
  **The decision on finding out was to leave the export alone**, not to implement the
  exclusion. Silently omitting delivered training from a statutory return is a worse
  failure than reporting it - under-reporting is itself a breach and is invisible until
  an auditor finds the gap, whereas a wrongly-included program is visible in the file.
- **A second, adjacent false claim.** The *Repair program codes* description said an
  enrolment with no program code is "silently excluded from NAT exports". Half true.
  Checked against `nat_generator`: it is dropped from NAT00030 and NAT00130, but NAT00120
  has no program-code filter and pads positions 144-153 blank - so the activity **is**
  lodged, against no program. Both the description and the new validator finding now say
  this precisely.
- **The upgrade window broke Student Records.** On a live site the plugin's code is in
  place *before* the upgrade that creates the recognition table - the gap between
  unzipping and clicking Upgrade, during which people are still using the plugin.
  `students.php` called the recognition class unguarded, so in that gap it rendered
  "Error reading from database". Measured, not theorised: dropping the table and loading
  the page produced exactly that. Fixed **in the class, not at the call sites**, so a
  caller added later cannot reintroduce it - new `recognition::table_ready()`, cached per
  request, guards all nine methods that name the table; `usi_required_sql()` returns
  `1=1` (the pre-recognition answer, which over-counts rather than under-counts); the
  page and the AJAX endpoint report a pending upgrade instead of throwing, with the
  endpoint answering `409 not_upgraded` inside its JSON envelope. The guard is checked
  **before** the action handler, because a posted lookup would otherwise reach the table
  anyway.

### Added

- **A *Program not classified* check in AVETMISS Validation** - a WARNING per program
  code with its enrolment and student counts, one finding per *code* rather than per
  enrolment so a 1,700-student qualification cannot bury the rest of the report, plus an
  ERROR for enrolments carrying no program code at all. Verified against seeded
  known-truth data: an unclassified code with 3 enrolments across 2 students reported as
  exactly that, a recognised code not flagged, and an unclassified code with **no**
  activity correctly not flagged either.

### Schema

None.

## [v6.4.3] - 2026-09-15

Program recognition now reads the plugin's own qualification/course tree map instead of a
subset of columns.

### Fixed

- **Discovery was incomplete.** `discover_codes()` read four columns and did **not** read
  `local_rtocompliance_course_map`, which is the authoritative one-row-per-Moodle-course
  mapping of qualification code plus unit code, seeded from Qual Builder links and
  category detection, and what every runtime completion and certificate path already
  reads. Nor did it read `local_rtocompliance_qualmap`. So a qualification the site had
  **fully mapped** but had no enrolments against yet was invisible to Program Recognition:
  no row, so it could be neither recognised nor excluded. Both tables are now discovery
  sources. Proven by seeding a tree map where one qualification existed only in
  `course_map` - before, no recognition row; after, discovered with the rest.

### Changed

- **The kind of training product is now read, not guessed.** Qual Builder already stores
  `producttype` - `qualification`, `skillset` or `singleunit`, the same values its own UI
  sets - so `register_lookup::get_product_type()` reads it. This matters because the
  lookup asks `/api/tga/qualification/{code}` and nothing else: a skill set and a single
  unit are not qualifications on training.gov.au. Nothing is falsely marked
  unaccredited - such codes are left unclassified - but that is manual work that should
  not be manual.

### Added

- **`cli/probe_register.php`**, read-only. Writes nothing and classifies nothing. Reports
  every code grouped by its **recorded** product type (codes with no Qual Builder row are
  shown as "NOT IN QUAL BUILDER" with a clearly-labelled shape guess, never presented as
  fact), how complete the course-map tree is by source and confirmed status, which mapped
  qualifications have no recognition row, and how many enrolments carry no program code at
  all. With `--live` it asks the platform API about one example of each product type and
  prints the raw HTTP status. The shape fallback was itself tested against 25 real code
  formats.

### Schema

None.

## [v6.4.2] - 2026-09-15

The register check shows progress, and provably terminates.

### Fixed

- **The register check could not be distinguished from a hang.** The button ran every
  unclassified code in **one** page request. On a site with 65 unclassified codes that is
  65 sequential HTTP lookups with a 30-second timeout each - a worst case well over half
  an hour, inside a request PHP's `max_execution_time` or any proxy kills long before,
  leaving a blank page and no way to tell "working" from "dead".

### Added

- **`recognition_ajax.php` and `js/recognition_progress.js`.** A small batch (5, capped at
  10) per request, driven by a live progress bar with a per-code running list and the
  three count cards updating as it goes. Work commits per batch, so closing the browser
  loses nothing and clicking again carries on. **The no-JavaScript form path is capped
  too** - 10 per click, then it reports how many are left.
- **`register_lookup::classify_batch()` takes a `$runstart`, and that is the whole
  point.** A lookup that errors deliberately leaves the state unclassified, so a loop
  asking for "the next unclassified codes" is handed the **same codes forever** and never
  terminates - and on a site with no API key, every code errors. Since
  `record_register_result()` always stamps `registerchecked`, even on an error, the batch
  selects codes **not attempted since this run began**: a strictly shrinking set that
  terminates whether the register answers or not.

### Verified

Six cases, executed: all-ERROR (23 codes, 5 batches, terminated, all correctly still
unclassified); all-NOTFOUND; all-FOUND; a mixed run of 65 in exactly 13 batches; a manual
override surviving a run that would otherwise have overwritten it; and resuming after an
interruption (2 batches, then a fresh run saw exactly the remaining 10). Over HTTP: `GET`
405, missing sesskey 403, bad sesskey 403, bad runstart 400 - all as JSON rather than an
HTML exception page.

### Schema

None.

## [v6.4.1] - 2026-09-15

Two defects in the 6.4 pages, both found on a real site.

### Fixed

- **The register check died with `Class "curl" not found`.** `register_lookup::lookup()`
  builds a Moodle `\curl`, but `curl` is a **global class declared inside
  `lib/filelib.php`**, not an autoloaded `\core` class, so it exists only if something on
  the request has already pulled filelib in. The web service entry point does; an ordinary
  plugin page does not. `filelib.php` is now required explicitly at the call site. This
  was the one branch of that class a test run could never reach, because the tests inject
  a transport and never execute the HTTP path - so 14 passing assertions proved nothing
  about it. **The same defect was latent in three more places**, found by auditing every
  `new \curl` in the plugin against whether its file loads filelib:
  `ai/survey_analyzer.php`, `lln/webhook_adapter.php` and `packagingrules_validator.php`.
- **Program Recognition and the AVETMISS Code-list Integrity report rendered with no
  left-hand menu and no plugin styling.** Both pages set their own context and pagelayout
  instead of calling `admin_externalpage_setup()`, and neither called
  `local_rtocompliance_render_nav_header()` - which is what actually emits the sidebar -
  nor added the `path-local-rtocompliance` body class that the whole of `styles.css` is
  scoped to. 105 of the plugin's pages do all three; these two did none. Verified by
  rendering each as an admin: 51 sidebar markers and 44 nav links on both, identical to
  `students.php`.

### Schema

None.

## [v6.4] - 2026-09-15

Release numbering only. The code is v6.3.36a's, unchanged - see the entry below for what
it contains.

### Schema

None.

## [v6.3.36a] - 2026-09-15

No USI on any certification document, correct outcome labels, and one source of truth for
program recognition. Packaged as v6.3.36 then v6.3.36a; renumbered to v6.4.

### Fixed — a USI printed on a testamur

ASQA states plainly that **RTOs must not enter a USI on these documents**. The plugin
knew this - the field registry marked `student.usi` forbidden on testamurs and statements
of attainment - but the render-time backstop, whose own comment says it exists because "a
pinned template, an override, or a legacy design could still carry one", blocked only the
field literally named `student.usi`. The identity table is a **different** field,
`student.detailstable`, which drew STUDENT NAME | USI | QUALIFICATION, so it walked
straight through. Proven by rendering a testamur carrying that field: the seeded USI
appeared under a column headed USI.

The backstop was enforcing 5 of the 11 forbidden combinations the registry declares; the
other six also put statement-of-attainment wording on a testamur and vice versa.

**The fix is not to block the table** - it also carries the student name and the
qualification, both required, so blocking it would strip required information from the
document. The **USI column is removed instead**, on every certificate type at once, and
cannot be re-enabled by a template edit because there is no longer a column to configure.
`student.usi` is now blocked on all types, appended globally rather than listed per type
so a type added later cannot be forgotten, and the multi-page page-furniture strip never
carries it.

Verified by rendering all four document types from designs deliberately carrying **both**
routes: zero USI values, zero USI headers, with student name and qualification still
present. Measured on the live site: 17 Records of Results across 16 students had printed
a USI, via the standalone field. Those need reissuing - this release stops it recurring
but does not retrospectively change an issued document.

### Fixed — outcome labels

Five of the twelve current outcome identifiers rendered wrongly on a Record of Results,
confirmed against a real Moodle 5 PDF, and the result key explained only 4 of the 11
printable codes. `outcome_labels()` and `outcome_short_codes()` were lifted out of
`resolve_payload()` and the legend is now derived from the codes actually present.

Note that the short abbreviations other than `NYS` (85) are **proposed** and marked as
such in the code: AVETMISS defines no transcript abbreviations at all, and ASQA's sample
establishes only C, NYC, CT and RPL.

### Added — program recognition

One answer per program code, in one place. Before this there were three disagreeing
routes and nothing reconciled them: a VET flag read from the NAT00030 record (which
Release 8.0 deleted, so on any current file it arrives empty), a tick-box on the Moodle
course defaulting to unticked, and - when that was empty - a **regular expression on the
course title**, treating a leading Australian-looking code as proof of accreditation. A
guess must never feed a compliance decision. The measured consequence on a live site:
1,064 students chased for a USI they did not need, 57% of that site's no-USI backlog.

- New `local_rtocompliance_recognition` table, new `recognition` and `register_lookup`
  classes, a Program Recognition page, and `cli/classify_programs.php`.
- **Three states, not a boolean**: a boolean cannot tell "we know this is not accredited"
  apart from "nobody has said yet", and collapsing the second into the first is the
  original defect.
- **A lookup that finds nothing does not mean not-recognised.** Both `notfound` and
  `error` record the attempt and leave the state unclassified. A missing API key returns
  ERROR, not NOTFOUND - otherwise a configuration problem would mark every program
  unaccredited.
- Only a person may assert *not recognised*, and who and when are stored.
- The migration can never set `not_recognised`: `nationallyrecognised = 1` and
  `isvetprog = 'Y'` become RECOGNISED, but `0` and `'N'` become **UNKNOWN**, because a
  schema default is not a decision. The course-name regex migrates nothing.

### Schema

- Adds `local_rtocompliance_recognition`. This is the only schema change in the whole
  6.3.36 - 6.4.5 line, and the only table written to by it.

### Note

`version.php` has no `release_prev` entry for **v6.3.35** - the chain jumps from v6.3.36a
to v6.3.34 - so there is no changelog entry for it either. v6.3.35 was packaged and
installed on a test site; its content is not recorded, and this gap is flagged here
rather than filled in with a guess.

## [v6.3.34] - 2026-09-15

Supersedes v6.3.33, which was packaged but never installed. Everything in 6.3.33 is
included here. **Install this, not 6.3.33.**

### Fixed — Release 8.0 conformance

- **The Program (NAT00030) file was the Release 7.0 "Course" record.** It opened with the
  Training organisation identifier and carried Type of attendance, Funding source —
  national and Study reason, none of which exist in the Release 8.0 Program file, so
  *every field was displaced*: the program code sat at 11–20 where the name belongs, the
  name at 21–120, Nominal hours at 122–125 instead of 111–114. Release 8.0 (p.25) is
  Program identifier 1(10), Program name 11(100), Nominal hours 111(4), record length 130.
  The field table accounts for only 114 of those 130 characters; the remaining 16 are
  reproduced from a real NAT00030 accepted from this client's previous student management
  system — 15 `@` fill then a Y/N flag, the identical shape on all 17 of its records —
  rather than guessed at.
- **Delivery mode identifier is a three-character Y/N triplet in Release 8.0** — position 1
  internal, 2 external, 3 workplace-based — and the generator wrote the stored Release 7.0
  numeric code into it, so `"10 "` went into a field that has to read `YNN`. On the live
  site every one of 12,911 enrolments holds a Release 7.0 code, across every year from 2008
  to 2026. Conversion happens **on output**; nothing stored is changed. `40 — Other
  delivery` is deliberately not mapped: Release 8.0's three flags are exhaustive, so any
  mapping would be an invention, and inventing one would put a claim about how training was
  delivered into a statutory return.
- **Two code lists were incomplete**, found by running the audit SQL against the live site:
  `get_sex_codes()` lacked `X` (782 students) and `get_state_codes()` lacked `@@` (1,386).
  Neither came from this plugin — its menus never offered them — so both arrived by NAT
  import and had been correct all along. Without this fix 2,168 records would have shown as
  "Unrecognised code" with staff unable to set either.

### Added

- **`code_select_trait`**, and it is not a nicety. Moving delivery mode to the Release 8.0
  set leaves every existing enrolment's stored `10` with no matching `<option>`, and an
  unguarded `<select>` in that state is submitted by the browser as its **first** option —
  so opening an enrolment and pressing Save would have silently rewritten the delivery mode
  of any record a staff member touched, on 12,911 rows. The v6.3.33 guard is extracted here
  and applied to both the student and enrolment forms: a stored value the list does not
  contain is added to the menu, a recognised superseded code is labelled for what it is
  ("Classroom-based (Release 7.0 code)") rather than reported as unknown, the first option
  is the field's own not-stated value, every label carries its code, and validation refuses
  any new value outside the standard while letting an unchanged one through.
- The enrolment form's other eight coded menus are covered by the same guard, each being one
  code-list edit away from the same failure.

### Verified

All ten NAT file layouts are now **measured, not assumed** — NAT00010 448, NAT00020 180,
NAT00030 130, NAT00060 123, NAT00080 327, NAT00085 557, NAT00090 12, NAT00100 13,
NAT00120 158, NAT00130 72. Every record width and every documented field position matches
the Collection Specifications field tables, checked by generating real records from seeded
data and reading the bytes at each specified offset. NAT00080's Statistical area level 1 and
2 fields are correctly absent: the specification restricts them to state and territory
training authorities.

88 checks across three harnesses on real Moodle 5.2.3 / MariaDB 10.11 with
`ONLY_FULL_GROUP_BY`, proven order-independent and repeatable. 135-page sweep identical to
the v6.3.33 baseline with zero error markers.

### Note

No schema change, and no student or enrolment data is rewritten. The upgrade step logs a
read-only measurement and bumps the savepoint. Savepoint `2026091501`.

## [v6.3.33] - 2026-09-14

### Fixed

- **The country and language code lists were not SACC and ASCL.** `get_country_codes()` and
  `get_language_codes()` are replaced with the AVETMISS identifiers as published in NCVER's
  own system files — `countryidentifier-revised26Nov2025.txt` and
  `language_systemfile_2016.txt` — read verbatim and shipped alongside the code at
  `db/codelists/` so a test fails if the two ever disagree.

  Of 245 country entries only **42** agreed with SACC. 119 named a *different country* than
  the label the operator clicked, 84 were not SACC identifiers at all, and 137 real
  identifiers could not be selected — including `2102` England and every other UK
  constituent. Of 176 language entries only **9** agreed with ASCL: the list was wrong from
  its first entry (`1101 Afrikaans`, `1102 Dutch`, `1103 Frisian`, where ASCL defines
  `1101` Gaelic (Scotland), `1102` Irish, `1103` Welsh). It was not ASCL with mistakes in
  it — it was a different scheme wearing ASCL's four-digit shape, which is why nothing
  about it looked wrong on screen or in a NAT file.

  Both lists carried `'9999' => 'Not stated'`, which is not an identifier in either
  standard. NCVER's not-specified value is `'@@@@'`, and it is now present. The 8xxx
  Australian Indigenous languages are included in full.

- **How it corrupted data.** A `<select>` whose selected value has no matching `<option>` is
  submitted by every browser as its *first* option. These 13 menus were built straight from
  the code arrays with no placeholder and no check, so opening a student's profile and
  pressing Save — changing nothing — rewrote the field to whatever sat first in the array.
  On one production site, 798 of 948 non-Australian students (84%) held a country that
  either does not exist in SACC or names a different country than the label shown; only
  47.6% of language values were ASCL-valid; 1,542 students held a language code the dropdown
  could not select.

- **NAT00130 could not be generated at all** on a site with the certificates table present.
  Its two certificate lookups queried `studentid` and `programcode` against
  `local_rtocompliance_certs`, which has neither — its columns are `userid` and
  `qualificationcode` — so every call threw `dml_read_exception`. The enrolment row's
  `studentid` is the local student record id, not the Moodle user id the certs table keys
  on, so the already-selected `s.userid` alias is what is passed.

- **NAT00020** emitted a 130-character record where the AVETMISS VET 8.0 Collection
  Specifications require 180 (p.23). The no-locations-table branch padded the delivery
  location name to 50 instead of 100, so every field after it sat 50 bytes short of its
  specified position and the file would be rejected on load.

- A stale comment in `db/upgrade.php` said saved views cover 48 operational tables; the
  registry has 44.

### Added

- `student_profile_form::add_code_select()` — the permanent fix, in three parts. A stored
  value the list does not contain is added to the menu labelled as unrecognised, so the
  browser always has a matching option and the first-option fallback can never fire; the
  first option is the field's own not-stated code where AVETMISS defines one, so a fallback
  would land on "Not stated" rather than a real country; and every label carries its code in
  brackets, which is what makes a mislabelled list visible to the person typing. All 13
  coded fields are covered, not only the two that were wrong.
- Server-side validation refusing any **new** value outside the standard. A value unchanged
  from what is already stored is allowed through deliberately — refusing it would block
  every unrelated edit to an affected record until someone had researched the right country.
- **Reports > AVETMISS code-list integrity** (`codelist_audit.php`) — read-only. Lists every
  record holding an undefined code, and separately the codes whose *meaning changed* in this
  release. The second table is the important one: those values are valid before and after,
  so no validation will ever flag them, but they no longer say what the operator was shown.
- `cli/repair_codes.php` — dry run by default, `--execute` required. Handles only the cases
  whose intent is known: `9999` to `'@@@@'`, and restoring an out-of-standard value from the
  audit log's `olddata`. It will not guess a country from a wrong code. Every change is
  written to the plugin audit log with the old and new value.
- `tests/avetmiss_codelist_test.php` — pins every code and label to the shipped NCVER files,
  both directions, so a hand-edit fails the build instead of reaching a site.

### Note

**No student data is rewritten by this release.** The upgrade step measures and writes the
result to the upgrade log; it changes nothing. Which country a student was actually born in
is not derivable from a wrong code, and an upgrade that guessed would destroy the evidence
needed to repair the record properly. Prevention is in force whether or not anyone repairs
the old rows.

### Schema

No schema change. The upgrade step bumps the savepoint and logs a read-only measurement.
Savepoint `2026091500`.

## [v6.3.32] - 2026-09-14

### Added

- **Moodle username search and display** on every page where a staff member identifies a
  student: `certificates.php`, `data_import.php`, `generate_course_certs.php`,
  `generate_qual_certs.php`, `issue_certificate.php`, `qual_cert_hub.php`,
  `qualbuilder_results.php`, `soa_issue.php`, `student_declaration_send.php`,
  `student_profile.php`, `student_support_input.php`, `students.php` and `usi_settings.php`.
  RTOs whose Moodle accounts are created with `username = client ID` could previously only
  search on a name, or on an email address that may not exist.
- **USI course-type scope** on `usi_settings.php` — nationally recognised, non-accredited /
  CPD, or unclassified — built from the recorded `nationallyrecognised` flag or an explicit,
  current Qualification Builder / confirmed course-map unit link. It never infers recognition
  from a course title, and a course with no recognition evidence reports as **Unclassified**
  rather than being written off as CPD.
- **Named saved table views** on 44 operational tables. A staff member can save and re-open
  their own filter and sort combinations. Views are private Moodle user preferences — no new
  table and no install step — bounded at 10 per table, 50 per user, 60-character names and
  1333 bytes.
- The USI CSV export gains a trailing **Moodle username** column, so the export matches the
  screen and the PDF. It is appended last, so no existing column moves. The separate
  missing-DOB download is a round-trip template read back by the importer and is deliberately
  unchanged.

### Fixed — the output hooks had never run

- Both of this plugin's output hooks opened with `if (empty($PAGE->url)) { return; }`, which
  is **always true**. `moodle_page` declares `__get()` but no `__isset()`, and `empty()`
  consults `__isset()` first, so PHP answered "not set" for a perfectly good url object.
  Confirmed on Moodle 4.4.12 and 5.2.2.
- `before_footer_html_generation` had therefore **never executed**: not the footer
  `tablesorter.js` / `tables.js` injection, not the missing-AVETMISS student prompt from
  v5.9.440, not the student-name data-repair banner — and the new saved views would have
  shipped completely invisible. `before_standard_head_html_generation` returned at the same
  line, after its profile gate. Both now use `$PAGE->has_set_url()`.
- `db/upgrade.php` records this exact guard being removed from `render_sidebar()` at v4.0.3
  for the same reason. It had crept back in.
- Running the newly-live code then exposed a latent defect in it. The head hook's
  `add_body_class()` call threw *"Cannot call moodle_page::add_body_class after output has
  been started"* on every plugin page — it fires from inside `standard_head_html()`, so it
  could never have worked. It is removed rather than re-guarded, because there is no point in
  the request where that hook could legally add a body class.
- `render_sidebar()` read the current page path through the same broken guard, so the
  plugin's own left-hand navigation has never highlighted the page you are on. It now does.
  This is the one visual change in the release: two lines, setting an `active` class on a
  single sidebar item.

### Deliberately NOT changed — raised as its own item

- The settings-navigation callback has an equivalent `add_body_class('path-local-rtocompliance')`
  call behind the same always-true guard. Correcting it was measured to add that class to
  `students.php`, `certificates.php`, `trainers.php`, `usi_settings.php`, `alerts.php`,
  `qualbuilder.php` and `index.php`, where it is absent today.
- Effectively the whole of `styles.css` is scoped to `[class*="path-local-rtocompliance"]` —
  about **1600 rules** — so that single line would apply ~1600 currently-dormant rules to
  every admin page at once: nav header, cards, tables, buttons. `styles.css` states this at
  the top of section 0 and ships unscoped "safety net" rules precisely because the scoped ones
  never match on `admin_externalpage_setup()` pages.
- Restoring the class is probably right, and is what that code always intended. But it is a
  whole-of-UI visual change that needs looking at on a real site page by page, and it has
  nothing to do with username search. It is left inert, with the reasoning recorded in
  `lib.php`, so this release carries **no body-class change at all** — identical to v6.3.31 on
  every page.

### Fixed — found by running this release, not by reading it

- `qualbuilder_results.php` grouped its per-unit course-map counts on `(unitcode, source)`
  but selected `unitcode` **first**, and `get_records_sql()` keys on the first column and
  silently drops duplicates. A unit mapped from more than one source — the normal case, a
  Qual Builder link plus a manually added course — lost every row but one, so the
  auto/qb/manual breakdown in the unit headers could never be right, and every page load
  emitted the duplicate-column warning. Same defect class as v6.3.30 item (21).
- All **77 `fputcsv()` calls across 9 files** now state the escape argument explicitly.
  PHP 8.4 deprecates the implicit default and was writing a deprecation notice *into the CSV
  stream*, corrupting every export on a site with developer debugging on. Output is
  byte-identical — the current defaults are passed, not changed.
- `student_declaration_send.php` carried a broken status-count query that re-used a filtered
  SQL string with no parameters, and its listing `SELECT` now includes `u.username`, which it
  read on every rendered row without selecting.

### Changed

- Every new search term is passed through `sql_like_escape()`, so an administrator who types
  `%` or `_` gets a literal match instead of a widened result set.
- Every `SELECT` added to a grouped query carries the matching `GROUP BY` column, so the
  pages still run under MySQL/MariaDB `ONLY_FULL_GROUP_BY`.
- Classification and category/course scope are evaluated in **one** enrolment predicate, so a
  student enrolled in both a qualification and a CPD course cannot borrow recognition from the
  other one.
- The USI **Re-verify all students** button is now labelled as the global action it always
  was; the course-type filter changes what the screen and its CSV/PDF exports show, not which
  students the scheduled USI verification touches.
- The USI table's new Moodle username column is sortable.
- The saved-views registry covers 44 pages rather than 40. `ai_usage_report`, `foe_audit`,
  `support` and `surveys` are read-only reports with no mutating, exporting or downloading
  parameter and one unambiguous capability each, and were simply missed. `ai_analysis`,
  `marketing_info`, `recovery_analyzer` and `tas_consultation` act on a request;
  `natexport` and `qi_export` are export endpoints; `mydocs` and `student_support` authorise a
  target user inside the page, so a single registry capability would state an access rule the
  page does not have. All eight stay out.

### Security / privacy

- The saved-view and privacy preference lookups escaped their prefix but were hand-written as
  a raw `name LIKE :x` with no `ESCAPE` clause. The prefixes are full of underscores, which
  are `LIKE` wildcards, so the escape character was whatever the database engine happened to
  default to. All of them now go through `$DB->sql_like()`.
- `generate_course_certs.php` passed the username into `html_writer::tag()` in two places.
  That function does not escape its contents, and a username is not guaranteed to be free of
  quotes or angle brackets. Both are now escaped.
- The privacy provider's per-user erasure now requires the **system** context to have been
  approved, rather than treating any non-empty approved context list as consent.
- The saved-views endpoint is POST-only, requires `sesskey`, re-checks the page's own
  capability on every call, and reads the owner from `$USER`. It has no parameter that can
  name another user and rejects a request that merely carries `userid`, `targetuserid` or
  `contextid`. A missing sesskey is now answered in the endpoint's own JSON envelope instead
  of letting `confirm_sesskey()` throw Moodle's `missingparam` exception — the case a stale
  page hits most often.
- A saved view can only contain query keys hand-registered for that page, so actions, object
  ids, offsets, security tokens and upload/download controls can never be replayed. Applying
  a view rebuilds the URL from the current page rather than from stored text.
- Saved views are declared as user preferences on the site's privacy registry, exported
  through `export_user_preferences()`, deleted with their owner on erasure, purged for
  everyone when the system context is purged, and their owners appear in
  `get_users_in_context()`.

### Tests

- The 5 new PHPUnit classes had never been run under Moodle and did not pass. Two seeded a
  mixed-case username, which Moodle refuses; the classification tests compared a string id to
  an int with `assertSame`; and the registry class changed `$USER` without declaring
  `resetAfterTest`. All fixed — the 5 classes now pass.
- Five Replit-local harness scripts (two TypeScript, three standalone PHP) were removed from
  `tests/`. They are not Moodle PHPUnit tests and do not belong in a plugin.
- **Known, not fixed here:** 38 PHPUnit tests in `nat_generator_test`, `avetmiss_codes_test`
  and `certificate_validator_test` fail. Those three test files and the three classes they
  exercise are byte-identical to v6.3.31, so the failures predate this work and need their own
  release.

### Verified on real installs

Moodle 4.4.12 (PHP 8.3) and 5.2.2 (PHP 8.4), on PostgreSQL 16 and on MariaDB with
`ONLY_FULL_GROUP_BY`. Cache-purged 6.3.31 → 6.3.32 upgrade `rc=0` on all four with no
row-count change. A 115-page sweep of the whole plugin is clean on every combination. The
70-assertion behaviour harness, the 29-assertion page-render suite and a new 67-assertion
feature and abuse suite all pass 4/4. The abuse suite drives the saved-views endpoint with a
missing and a wrong sesskey, an unregistered page, a traversal page name, an unknown action, a
request carrying `userid` and `targetuserid`, a view naming `action` / `userid` / an
unregistered field, a bad sort direction, an oversized state, non-JSON state, an over-long
name, another user's view id and a malformed id — and asserts that a username containing
`%`, `_`, `"` and `<b>` is matched literally and printed escaped. The credit-transfer create
path still posts outcome 60 through the real form with a real file upload, 10/10 on both
Moodle versions.

### Source baseline

These changes were first written against a **6.3.21** source tree — ten releases behind the
public **6.3.31**. They have been rebuilt on the verified 6.3.31 tree instead. Packaging the
older source would have silently reverted v6.3.28 (privacy erasure), v6.3.29 (SoA
credit-transfer visibility) and v6.3.30 (the credit-transfer end-to-end audit).

**NO SCHEMA CHANGE** — the upgrade step bumps the savepoint only. Savepoint `2026091400`.

## [v6.3.31] - 2026-09-08

### Changed - version bump only; the code is v6.3.30's, unchanged

- More than one package was built and circulated carrying the version string **6.3.30** while
  the release was still being corrected, and one of them was rejected by the release pipeline
  for inconsistent metadata (`CHANGELOG.md` led with v6.3.28 while `version.php` said v6.3.30).
- A version number attached to more than one artefact cannot identify what is installed on a
  site. On sites already running this plugin that matters: *"the client is on 6.3.30"* would no
  longer say **which** 6.3.30. This release retires that number.
- **There is no functional difference between this and the final v6.3.30 build.** Not one line
  of PHP, JavaScript or SQL differs. Only `version.php`, `db/upgrade.php` (a savepoint step) and
  this file change.
- In-code comment markers still read `v6.3.30`. That is deliberate: they record when each change
  was made, and rewriting a hundred-odd of them would be churn carrying its own risk. Read
  `v6.3.30` in a comment as "the credit-transfer release", which shipped as v6.3.31.
- The v6.3.30 entry below remains the substantive record of what changed and why.

### Not changed

- **No schema change** - the upgrade step bumps the savepoint only.

Savepoint 2026090903.

## [v6.3.30] - 2026-09-08

### Fixed - end-to-end audit of the credit transfer / RPL path

Triggered by a credit transfer (TLIX0008) that would not appear on a student's Statement
of Attainment. v6.3.29 fixed the visibility; auditing the whole path found twelve more
defects, and self-review found seven more before release.

- **A new credit transfer evidenced by an attached certificate posted nothing.**
  `rpl_edit.php`'s outcome closure captured the record id **by value**, which is `0` on the
  create path, and the uploaded-source-certificate branch of the Standard 1.7 gate was
  guarded on it. An assessor who attached the issuing RTO's testamur rather than ticking
  *USI transcript verified* got a record saved as **Approved**, no outcome 60, and a warning
  telling them to add the document they had just added. Editing and re-saving worked, which
  made it look intermittent. The id is now passed in.
- **New "In Results" column on the RPL & Credit Transfer register.** Approving writes two
  things - the application record and the actual result - and only the result produces
  certificates, completions and NAT records. The register showed only the first, so a
  decision that posted nothing looked identical to one that worked. Every approved decision
  now reports **Recorded (60)** / **Recorded (51)**, **Not recorded**, or **Cannot post**,
  with the reason and the remedy. `rpl_edit.php` confirms the same in words on save, and
  warns when an approved decision has no unit code (which used to post nothing, silently).
- **A USI-exempt student could never be issued an SoA through the wizard.** `soa_ajax.php`
  read only `usi` / `usiverified` with no exemption branch, while its own error text said
  "or mark the student USI-exempt". The exempt cohort - study completed outside Australia -
  is largely the credit-transfer cohort.
- **A manually issued SoA printed a credit transfer as "Competent".**
  `issue_certificate.php` stamped every free-typed unit `20`. New
  `local_rtocompliance_resolve_unit_outcome_for_user()` reads the real outcome from the
  register, restricted to competent codes so a stray `70` cannot print "Continuing
  enrolment" on an AQF document.
- **A duplicate, contradictory NAT00120 record.** `process_enrolment_task` deduped on
  (studentid, courseid, unitcode); a credit row carries `courseid = 0`, so granting credit
  *before* enrolment inserted a second row with outcome 70. The delivery insert is now
  suppressed when the student holds a granted credit for that unit **under the same program
  code**. A credit with no qualification code suppresses nothing - a blank must not act as a
  wildcard.
- **Correcting an approved record orphaned the credit it granted.** Retraction fired only on
  approved -> not-approved, so re-pointing an approved record at a different unit or student
  left the old credit in the register with no decision behind it. The gap v6.3.26 closed on
  delete, still open on edit.
- **`generate_course_certs.php` treated "not yet started" as a completion.** Its private
  outcome list included `85` (not yet started, and listed as a *continuing* outcome by
  `avetmiss_codes.php`) and `53` (deleted from the AVETMISS standard in Edition 2.1). On a
  site without Moodle completion tracking the fallback offered certificates to students who
  had not started. Both removed; `61` and `41` kept deliberately.
- **Activity dates come from the assessor's decision date**, not the moment the form was
  saved, so a back-entered decision lands in the right AVETMISS collection year. A future
  date is ignored. A credit granted over an existing enrolment leaves its delivery dates
  alone - nothing stashes those for restoration on retract.
- Category filters on the SoA student picker no longer hide a student whose credit sits in
  that qualification; deleting a result granted by an approved decision now warns that the
  decision still stands; `skipped_programcodes.php` surfaces granted rows with a blank
  program code, which its `courseid > 0` queries could never list.
- `certificate_validator` read a unit's outcome from an `outcomeidentifier` key while its
  only caller supplied `outcome`, so a manually typed unit always presented as blank to the
  competent-unit test. Both keys are accepted in both loops. The `20` print-fallback is
  deliberately kept *out* of the validator, so an unknown unit still requires Bypass
  Validation exactly as before.

### Added - the Add/Edit Unit page is reachable

- `qualbuilder_unit.php` has existed for the life of the Qualification Builder and **nothing
  ever linked to it**, while requiring `qualbuilderid` - so the only way to reach it threw
  *"A required parameter (qualbuilderid) was missing"*.
- `qualbuilder_edit.php` now has an **Add unit manually** button, and every saved unit row
  carries an edit pencil to the same form. The page falls back to a training-product picker
  instead of failing, resolves the product from a unit id alone, and replaces Moodle's raw
  "Can not find data record" with a sentence.

### Found in self-review, before release

- The wizard's new `unitkey` was being concatenated into CSS selectors and an inline
  `onchange` attribute. Unlike the integer `courseid` it replaced, it is DB text, so a value
  containing a quote broke the group checkboxes and could inject script into an admin page.
  Replaced with dataset comparison and delegated listeners; the same defect reached the group
  cards' *Select all* buttons via the qualification code.
- The v6.3.29 register sweep had **no scope predicate**, so it swept every register row with
  no matching course completion - including historical results from a NAT/results import,
  which carry `courseid = 0` and `manualoutcome = 0` - into the wizard, removing the
  course-completion gate. Now confined to `manualoutcome = 1` with outcome 51 or 60.
- `generatesoa` OR-ed `unitkeys` with `courseids` while the wizard posts both, so a unit the
  admin never ticked could reach an issued SoA. `courseids` is now a fallback only.

### Fixed - found by running it

- Every load of the SoA wizard emitted *"Did you remember to make the first column something
  unique in your call to get_records? Duplicate value '0' found in column 'courseid'"*. The
  course->outcome map selected `courseid` first, and `get_records_sql()` keys on the first
  column and silently drops duplicates - so the `id DESC` ordering the "first wins" loop
  relies on was already being decided inside the DML layer. Every granted credit carries
  `courseid = 0`. The query now selects `id` first and excludes those rows.

### Verified

Installed at v6.3.28 and upgraded to v6.3.30 on **Moodle 4.4.12 (PHP 8.3)** and **Moodle
5.2.2 (PHP 8.4)**, both on PostgreSQL 16. A 70-assertion behaviour harness and a
29-assertion page-render pass over real HTTP: **70/70 and 29/29 on both versions**, with
developer debugging on and no notices.

### Not changed

- **No schema change** - the upgrade step bumps the savepoint only.

Savepoint 2026090902.

## [v6.3.29] - 2026-09-08

### Fixed - an approved credit transfer could never be put on a Statement of Attainment

- `soa_compliance_engine::get_eligible_units()` built the wizard's unit list entirely from
  Moodle `{course_completions}` and returned an empty array when there were none.
- `local_rtocompliance_apply_rpl_outcome()` writes RPL (51) and Credit Transfer (60) straight
  to `local_rtocompliance_enrolments` with `courseid = 0` and, by design, never creates a
  course completion - the RTO did not deliver or assess the unit.
- So a credit transfer that was approved, evidenced, gated on its source certificate and
  correctly posted to the register was **invisible to the one screen that issues the SoA**.
- The engine now sweeps the results register for granted outcomes with no course completion
  and adds them as first-class eligible units, taking the qualification from the register
  row's `programcode` since there is no course to map.
- Row identity in the wizard moved from Moodle `courseid` to a new `unitkey`: every RPL/CT
  unit has `courseid = 0`, so all of them collided on one key and none could be ticked or
  posted back.
- `soa_ajax.php` `generatesoa` accepts `unitkeys` and still honours `courseids` for an
  unrefreshed page.
- Reported from a live site (unit TLIX0008, credit transfer approved, unit absent from the
  SoA wizard).

### Not changed

- **No schema change** - the upgrade step bumps the savepoint only.

Savepoint 2026090901.

## [v6.3.28] - 2026-09-07

### Fixed - a privacy erasure request now actually erases

- The privacy provider declared **11** of the plugin's **44** tables holding personal
  data, and deleted from only **8**. A seeded-user test proved that a *completed*
  erasure request left that person's data behind in **28 tables**.
- What survived a "successful" erasure: the student's suitability assessment and its
  answers, their declarations, uploaded student documents, support notes, fee records,
  Statement-of-Attainment snapshots, USI verification log, RPL and Credit Transfer
  applications with their uploaded evidence files, CRICOS record, complaint, appeal,
  audit rows, enrolments and issued certificates.
- Every table is now classified, and the classification decides the outcome:
  - **Subject** (21 tables) - the person *is* the record. Exported and **deleted**.
  - **Foreign key** (8 tables) - reached through `studentid` / `trainerid` /
    `suitabilityid`. Exported and **deleted with its parent**.
  - **Authorship** (15 tables) - the person only *acted* on someone else's compliance
    record (a `createdby` on a third-party arrangement, an `approvedby` on a TAS).
    Declared and exported, but **retained**: destroying another party's compliance
    record because the staff member who typed it asked for erasure would remove
    evidence the RTO is legally required to keep under the Standards for RTOs and the
    NVETR Act.
- Personal file uploads now travel with their rows in both directions - purged on
  erasure and included in an export: `rpl_evidence`, `ct_sourcecert`, `student_doc`,
  `trainer_evidence`, `trainer_voccomp_evidence`. Areas belonging to retained records
  (`supervision_evidence`, `consultation_evidence`) are deliberately left alone.
- `get_users_in_context()` now finds every affected person rather than only those
  present in the 8 previously-handled tables, so a site-wide erasure no longer misses
  people entirely.
- `delete_data_for_all_users_in_context()` previously truncated the log table alone.
- 91 language strings added, so every declared table and column names itself on the
  site's privacy registry page.

### Not changed

- **No schema change** - no table, column or index is touched.
- **No functional change.** Nothing in the plugin calls the privacy provider; only
  Moodle's own privacy subsystem does. Verified by search across all 207 files.

## [v6.3.27] - 2026-09-08

### Fixed - reversing an RPL / Credit Transfer now restores the enrolment's delivery

- `apply_rpl_outcome()` overwrites an existing enrolment's `deliverymode` with `90`
  (no delivery) and, for a credit transfer, zeroes `scheduledhours`. That part is right -
  neither RPL nor credit transfer involves delivery.
- `retract_rpl_outcome()` restored the outcome to `70` and cleared `manualoutcome`, but
  never restored those two values, and nothing remembered them. A classroom enrolment
  (mode 10, 40 hours) that was RPL'd and then reversed was reported to NCVER as a
  **continuing enrolment with delivery mode "not applicable"**, and a reversed credit
  transfer lost its scheduled hours permanently.
- **Schema change:** two nullable columns on `local_rtocompliance_enrolments` -
  `prerpldeliverymode` and `prerplscheduledhours` - hold the pre-RPL values while an
  RPL/CT outcome is applied. `apply()` stashes them only on the *first* application, so an
  approve / reverse / approve cycle still keeps the genuine originals. `retract()` restores
  them and clears the stash. `NULL` means no RPL/CT outcome is applied, so **existing rows
  need no backfill**.
- Found by auditing the retraction path added in v6.3.26, not by a reported symptom.

Savepoint 2026090800.

## [v6.3.26] - 2026-09-07

### Fixed - deleting an approved RPL / Credit Transfer left the student competent

- An approved decision posts outcome **51** (RPL) or **60** (Credit Transfer) into the
  results register. Reversing a decision on the edit path has retracted that outcome since
  v5.9.416 - but **deleting** the record did not.
- Deleting an approved application removed the assessor decision, the evidence and the
  documented rationale, while the granted competency stayed in
  `local_rtocompliance_enrolments` and flowed on into course completions, issued
  certificates and the AVETMISS NAT export. An auditor would find a unit reported as
  RPL-granted with no RPL record to justify it - the exact evidence trail Standards 1.6
  and 1.7 require.
- The delete path now calls `local_rtocompliance_retract_rpl_outcome()` before removing the
  row, on the same conditions the edit path already used: decision approved or partially
  approved, a linked student, and a unit code.
- Found by an end-to-end audit of the RPL feature, not by the reported symptom.

**No schema change** - the upgrade step bumps the savepoint only. Savepoint 2026090704.

## [v6.3.25] - 2026-09-07

### Fixed - RPL student picker: suggestions dropdown spanned the entire screen

- `.form-autocomplete-suggestions` is `position: absolute` in Moodle core with no width,
  so it shrink-wraps. The plugin has forced `width: 100% !important` on it since v5.2.34,
  written for a different field. For an absolutely positioned element that resolves against
  the nearest *positioned* ancestor - on `rpl_edit.php` that was Moodle's page wrapper, so
  the dropdown rendered at full viewport width (measured 1500px on a 1500px viewport) and
  ran off the left edge of the screen.
- The picker is now wrapped in `.rtoc-student-picker`, which is `position: relative` and
  capped at 520px, so `100%` resolves to the field itself.

**No schema change** - the upgrade step bumps the savepoint only. Savepoint 2026090703.

## [v6.3.24] - 2026-09-07

### Fixed - RPL student search: single-character CJK surnames are searchable

- The search service added in v6.3.23 required at least two characters before it would
  query. That is correct for Latin script - a single letter matches most of the register
  and forces a scan on every keystroke - but a Chinese, Japanese or Korean family name is
  one character, so those students could not be found by surname at all. The record was
  present and still reachable by USI or full name; only the surname search was blocked.
- The minimum is now script-aware: one character when the query contains any non-ASCII
  character, two otherwise.
- Found by seeding students with Chinese, Spanish, Irish and Nordic names and re-running
  the whole suite on MariaDB, where `sql_like()` emits
  `LOWER(...) LIKE ... COLLATE utf8mb4_bin` instead of PostgreSQL's `ILIKE`.

**No schema change** - the upgrade step bumps the savepoint only. Savepoint 2026090702.

## [v6.3.23] - 2026-09-07

### Fixed - RPL / Credit Transfer: every student is selectable again

- The student selector on `rpl_edit.php` built its options from a `SELECT` over the whole
  `local_rtocompliance_students` table, ordered by surname and capped at 2,000 rows. On a
  register larger than that the cap fell inside the alphabet, so every student after the
  cutoff was absent from the dropdown and could not be linked to an application - reported
  as "only surnames A-G appear". The cap is a plugin limit, not a Moodle setting.
- The cap is removed and the selector is searched in the database instead. New external
  function `local_rtocompliance_search_students` (read, `local/rtocompliance:manage`, system
  context) matches given name, family name, full name in either order, and USI, returning at
  most 30 rows. New AMD module `local_rtocompliance/rpl_student_selector` supplies
  `transport`/`processResults` to `core/form-autocomplete`.
- The form now renders only the already-selected student, so the browser no longer receives
  every student name and every USI on load - a data-minimisation improvement as well as a fix.
- Deleted Moodle users remain excluded. The option value is still
  `local_rtocompliance_students.id`, not the Moodle user id, because the save path and the
  results-posting path both key on the local student record.

**No schema change** - the upgrade step bumps the savepoint only. Savepoint 2026090701.

## [v6.3.22] - 2026-09-03

### Fixed — Qualification Builder: pasted packaging rules now refresh the rules card

- The QPR paste handler in `amd/src/qualbuilder_edit.js` ended by calling `renderRulesCard()`, a
  function that has never been defined anywhere in the plugin. Pasting packaging rules set
  `QB.totalRequired` / `QB.coreRequired` / `QB.electiveReq`, printed the green confirmation and
  refreshed the compliance dashboard, then threw a `ReferenceError` on the handler's last
  statement — so the **Packaging Rules card kept showing the previous numbers** while the rest of
  the page showed the new ones. The only other signal was a console error, so the natural reading
  was that the parse had failed.
- The call is now `renderPackagingRules()` — the function that repaints `#qb-rules-card` from
  exactly those three values, and the one `loadFromTGA()` already calls after setting them from
  training.gov.au.
- One identifier changed, applied to `amd/src/qualbuilder_edit.js` and both `amd/build` artifacts,
  because Moodle serves the build file.

**No schema change** — the upgrade step bumps the savepoint only. Savepoint 2026090300.

## v6.3.21 — 21 Aug 2026

### Changed — release-pipeline sweep: one error and all seven warnings cleared, with no behaviour change

Every change below was applied with a token-aware tool and verified by comparing the PHP token
stream of all 207 files before and after, ignoring whitespace and comments. **The executable token
stream is identical across the whole plugin** — nothing in this release can alter what the code does.

- **Coding style — one statement per line**: 200 lines across 31 files carried two or more
  statements. Split shape-aware, not mechanically: a `switch` case moves its body under the label, a
  brace block opened and closed on one line is expanded, a one-line closure gets a real body, and
  `}` followed by `else` is joined into `} else {`. Template lines mixing HTML with several short
  PHP blocks break *inside* the PHP block, after the semicolon, which cannot change a byte of
  rendered output.
- **Coding style — multi-line calls**: 1,827 calls across 150 files had their first argument on the
  same line as the opening parenthesis. The argument now starts on the next line, and the call's
  continuation lines are re-indented one step so the argument list still reads as one block.
- **Coding style — comment blocks**: 1,116 blocks across 96 files opened with a lower-case letter.
  Prose is capitalised. Anything that must not be re-cased is left alone and reworded instead:
  `pipeline-ignore` / `phpcs` / `eslint` annotations, variables, URLs, function and file names, CSS
  class and column identifiers, and value lists. The plugin's own `// v6.2.78 …` release markers are
  respelled `// Version 6.2.78 …`, keeping the release number exactly where it was.
- **AMD / JavaScript**: `nominalhours_autofill.js` carried ten user-facing strings in the file. They
  now load from the language pack via `core/str`, and the lookup button is injected only once its
  label has resolved, so no English placeholder is ever painted. Ten new language strings.
- **Language strings**: the two shouting values (`PACKAGING RULES: COMPLIANT` / `NOT COMPLIANT`) are
  sentence case; *ASQA 2025 Compliance* and *TAS Arrangement (Tuition Assurance Scheme membership)*
  are reworded so they no longer open on an acronym; FAQ and CEO are spelled out. Genuine Australian
  VET acronyms used as field labels (ABN, USI, RTO, DOB, QLD LUI, WA RAPT ID) are kept and annotated
  — expanding them would make the interface less correct, not more.
- **Security warnings, verified not fixed**: the four public pages (three token-gated forms plus the
  machine-to-machine webhook) and the one hardened `unserialize()` call now carry the pipeline's own
  `pipeline-ignore` annotation stating what authorises the request. Adding a capability check to
  those pages would lock out the students and survey respondents they exist for.
- **Fixed**: the v6.3.20 test file opened its class body with a blank line (the one reported error).
- No DB schema changes. Savepoint 2026082105.

## v6.3.20 — 21 Aug 2026

### Fixed — the Record of Results printed two header rows

- **DUPLICATE-TABLE-HEADER-STRIP** (`classes/cert_template.php`): a Record of Results saved before v5.9.447 carries a row of plain text fields above the units table holding the old column captions (*Semester / Year*, *Units / modules enrolled*, *Results*). Since v5.9.447 the table draws its **own** shaded header bar, so those captions became a second, stale header sitting directly above the real one, with different wording. v6.2.9 removed them from the starter designs, but every template already saved kept them.
  - `cert_template::strip_legacy_table_headers()` removes them at render time, from `ensure_mandatory_fields()`, so the issued PDF **and** the editor canvas correct themselves with no rebuild and no data migration. The saved design is never modified, and the transform is idempotent.
  - Deliberately narrow, so a caption an author placed on purpose survives: it fires only when a self-heading table field is present; never when the design still uses the legacy per-column fields (`qualification.units_col_*`), where those captions are the only headings there are; and only for a caption whose wording matches a known column label **and** which sits within 30mm above the table and overlaps it horizontally.
  - The identity captions (*Name of student:*, *USI:*) are held back until the shaded student details table is actually on the canvas, so `upgrade_record_identity_to_table()` can still recognise the stacked block it replaces.

### Added — certificate table column headings are now the RTO's to word

- **SITE-WIDE** (`settings.php`, `lib.php`): nine new fields under *RTO Settings → Certificate settings* set the heading wording for the units / Record of Results table (unit code, unit title, date, result, enrolment date, completion date) and the student details table (student name, USI, qualification). Left empty, each keeps its ASQA sample-forms default.
- **PER TEMPLATE** (`cert_template_edit.php`, `amd/src/cert_template_editor.js`): a single template can override again per table field, in the editor's Field Properties panel. Each input shows the site-wide wording as its placeholder, and the canvas mock updates as you type.
- **ONE RESOLUTION ORDER** (`classes/cert_template_renderer.php`): template field override → site setting → built-in default, applied identically in the issued PDF and on the editor canvas. An RTO that says *COMPETENCY CODE* or *OUTCOME* relabels every certificate without touching code.
- **TESTS** (`tests/cert_template_headers_test.php`): five PHPUnit tests covering the strip's four guard conditions and the heading fallback order.
- No DB schema changes. Savepoint 2026082104.

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
