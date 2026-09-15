# Program recognition: is this program nationally recognised training?

<!-- pages: program_recognition.php, students.php, nat_validate.php, natexport.php, codelist_audit.php, qualbuilder.php, data_import.php -->
<!-- summary: How the plugin establishes whether a program is nationally recognised training, why unclassified is a real state rather than a failure, and what classification does and does not change. -->

Three things hang off the answer: whether a student needs a Unique Student Identifier,
which certificate they can be issued, and whether their activity belongs in the
AVETMISS collection at all. So the plugin keeps one answer per program code, in one
place, and the Program Recognition page is where you see it.

## Why it is looked up rather than asked about

Accreditation is a fact, not a local preference. A qualification code either appears on
the National Register at training.gov.au or it does not. So the primary source is a
lookup, not a checkbox.

Before this existed there were three disagreeing routes and nothing reconciled them: a
VET flag read from the NAT00030 record (which AVETMISS Release 8.0 deleted, so on any
current file it arrives empty), a tick-box on the Moodle course defaulting to
unticked, and — when that was empty — a **regular expression on the course title**,
treating a leading Australian-looking code as proof of accreditation. A guess must
never feed a compliance decision. On one live site the measured result was 1,064
students being chased for a USI they did not need.

Press **Check all unclassified codes against the National Register**. It works through
the codes in small batches with a progress bar, saves as it goes, and can be stopped
and restarted without losing anything. On a real site 61 of 67 codes classified
themselves in one pass.

## Three states, and why "unclassified" is not a failure

| State | What it means |
|---|---|
| **Nationally recognised** | On the National Register, or you have said so. |
| **Not nationally recognised** | **You** have explicitly said it is not. Only a person can set this. |
| **Unclassified** | Nobody has established either way. |

A yes/no flag cannot tell "we know this is not accredited" apart from "nobody has said
yet", and collapsing the second into the first is exactly the old defect — every
unclassified program silently asserting a definite answer. Unclassified is a real,
reportable state that claims nothing.

**A lookup that finds nothing does not mean not-recognised.** The register may be
unreachable, your API key may be missing, the code may be superseded, or it may be your
own internal code. The plugin records what the lookup said and leaves the state
unclassified. Only you can assert "not nationally recognised", and when you do, your
name and the date are stored — an auditor asking why a program was treated as
non-accredited gets a name and a date, not a shrug.

## What classification actually changes

**It does not change your NAT files.** An unclassified program's activity is still
reported. Nothing is held back.

That is a deliberate choice. Silently leaving delivered training out of a statutory
return is a worse failure than reporting it: under-reporting is itself a breach, and it
is invisible until an auditor finds the gap, whereas a wrongly-included program is
visible in the file you lodged. So the plugin does not decide silently in either
direction — it reports, and it tells you.

Where it tells you is **AVETMISS Validation**, which raises a *Program not classified*
warning for every unclassified program that has activity, with its enrolment and
student counts. Work through those before you lodge.

What classification does change:

- **The USI count.** A student enrolled only in programs you have marked *not*
  nationally recognised drops out of the "USI missing" figure on Student Records.
- **Which certificate is appropriate.** AQF certification belongs to nationally
  recognised training; a non-accredited course gets a completion certificate.

Note that the USI gate treats unclassified the *opposite* way to how you might expect,
and on purpose: an unclassified program still counts its students as needing a USI.
Chasing a USI you did not need wastes somebody's afternoon. Failing to collect one you
did need is a breach. Only your explicit "not nationally recognised" stops the chase.

## Where the codes come from

Every code the site uses is discovered automatically, from the enrolments, the courses
table, the imported AVETMISS programme records, Qual Builder, and the qualification /
course tree map. You should not have to type a code in.

Two things to know about that:

- **A code with no program code cannot be classified at all.** An enrolment with a
  blank program code has nothing to attach an answer to. It is also mishandled at
  export: it is dropped from NAT00030 and NAT00130, but still written into NAT00120
  with a blank course identifier — so the activity is lodged against no program. Fix
  these with *Repair program codes* on the Students page. AVETMISS Validation counts
  them for you.
- **Skill sets and single units are looked up the same way.** A category in Moodle may
  be a qualification or a skill set, and a course may be a standalone unit. Qual
  Builder records which of those a product is, and the register answers for all three —
  a skill set code and a unit code both resolve.

## What the register check will not do

- It will never mark anything *not* nationally recognised. Only you can.
- It will never overwrite a decision you have recorded, even if you re-run it.
- If it cannot reach the register — no API key, no network — it records the attempt and
  leaves the state alone. "We could not ask" is never stored as "the register does not
  have it".

So it is always safe to press the button again.
