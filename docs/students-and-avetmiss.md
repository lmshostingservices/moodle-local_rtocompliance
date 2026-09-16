# Student records and AVETMISS data

<!-- pages: students.php, student_profile.php, student_enrolments.php, nat_validate.php, data_import.php, download_nat.php -->
<!-- summary: What the plugin stores about a student, where it comes from, and what makes a record reportable. -->

## The plugin only reads Moodle

The plugin never creates, edits or deletes Moodle courses, user accounts, enrolments or
categories. It reads them — enrolments, course completions, users, category tree — and writes
only to its own `local_rtocompliance_*` tables. Anything that looks like the plugin changing
Moodle is a misreading; the one exception historically was unsuspending accounts during
certificate generation, and that was removed.

## What makes a record reportable

AVETMISS reporting needs identity (name, date of birth, USI), address and residency, prior
education, disability and language fields, and the enrolment detail — programme, units,
outcomes, dates, funding source and delivery mode.

The plugin tracks a `profilecomplete` flag over the full set, and separately a shorter list
of **mandatory profile fields** that the student profile gate holds a student on. The two are
not the same list, and the difference is intentional: the strict flag drives certificate
readiness and NAT validation, the shorter one drives what a student is asked to fill in.

## Client identifiers

The NCVER Client Identifier uniquely distinguishes an individual within *your* organisation.
The binding requirement is persistence, not format: it must stay the same across every year,
subject and programme for that person, and an RTO must not generate a different one each time
someone re-enrols. Another provider's student number has no claim on yours — what must be
preserved is what *this* RTO previously submitted.

Where a student has no stored client identifier, the NAT export falls back to their bare
Moodle user id, computed at export time and never stored. That is stable only while the field
stays blank: entering a real identifier later changes what the STA sees and reads as a
different person.

## Residential country and the overseas postcode

**Residential country** on the AVETMISS profile is the field that establishes offshore study.
It is drawn from the same NCVER area-code list as country of birth, and it defaults to
Australia (`1101`). Until it had a control in the interface nobody could set it, so it was
empty on every student while still being exported.

It matters in two places. It is what the **Offshore Online Delivery USI Exempt** view on the
USI Verification page matches on when no exemption has been recorded explicitly, and it gates
the overseas postcode.

**Postcode** is normally four digits. For a student living outside Australia the collection
standard uses the literal value `OSPC` instead, and the export pairs that with an offshore USI
exemption code. Both validators used to demand four digits and rejected `OSPC` outright, so an
offshore student could not be recorded correctly at all. `OSPC` is now accepted — but only when
the residential country is set to something other than Australia, so it cannot be used to make
a domestic student look exempt.

## Dates of birth before 1970

A date of birth before 1 January 1970 is a negative Unix timestamp. Anywhere the code once
treated "not greater than zero" as "not answered", such a student was permanently marked
incomplete. That is fixed, but it is worth knowing as the explanation for an older student
whose profile refuses to count as complete however many times they fill it in.

## Outcome identifiers: the twelve that exist

The authority is the NCVER *AVETMISS data element definitions*, edition 2.3, under
CLASSIFICATION SCHEME for Outcome identifier — national. Twelve codes are current:

| Code | Meaning |
|---|---|
| 20 | Competency achieved / pass |
| 30 | Competency not achieved / fail |
| 40 | Withdrawn / discontinued |
| 41 | Non-assessable enrolment — module completed |
| 51 | Recognition of prior learning granted |
| 52 | Recognition of prior learning not granted |
| 60 | Credit transfer / national recognition |
| 61 | Superseded subject |
| 70 | Continuing enrolment |
| 81 | Non-assessable enrolment — satisfactorily completed |
| 82 | Non-assessable enrolment — withdrawn or not satisfactorily completed |
| 85 | Not yet started |

Codes `41` and `85` were added on 1 January 2018. Several codes people still expect are
**gone**: `50` was deleted in 2007, `53` and `54` on 1 January 2012, `90` on 1 January
2018, and `10` was recoded between 1999 and 2002. `00` never existed at all — where it
appears in data it is a schema default that was never overwritten, not a reported value.

A record holding a code that is not in that list cannot be lodged. **AVETMISS Code-list
Integrity** under Data & Reporting lists every field holding a value the standard does
not define, with how many students each affects. It is read-only; repair is a separate,
explicit step.

## What AVETMISS does and does not govern

AVETMISS governs the NAT files you lodge with NCVER. It says nothing whatsoever about
what an RTO prints on a certificate.

This distinction matters because it is a common and expensive mistake. The reporting
standard defines no abbreviations for a transcript — no "NYC", no "Not Yet Competent";
those words do not appear in the specification or the data element definitions. What
gets printed on certification documentation is governed by the Standards for RTOs and
the AQF, and the reference for it is ASQA's sample forms of AQF certification
documentation, which establishes C, NYC, CT and RPL. Do not reason from a reporting
rule to a printing rule, in either direction.
