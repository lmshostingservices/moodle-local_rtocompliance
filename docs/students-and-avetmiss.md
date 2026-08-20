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

## Dates of birth before 1970

A date of birth before 1 January 1970 is a negative Unix timestamp. Anywhere the code once
treated "not greater than zero" as "not answered", such a student was permanently marked
incomplete. That is fixed, but it is worth knowing as the explanation for an older student
whose profile refuses to count as complete however many times they fill it in.
