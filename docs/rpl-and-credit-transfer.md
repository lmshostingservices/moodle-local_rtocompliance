# RPL and credit transfer: granting credit, and getting it onto a certificate

<!-- pages: rpl.php, rpl_edit.php, soa_issue.php, student_enrolments.php, student_profile.php, issue_certificate.php, qualbuilder_results.php -->
<!-- summary: How an RPL or credit transfer decision becomes a result the student holds, what the "In Results" column means, why an approved decision sometimes records nothing, and how credited units reach a Statement of Attainment. -->

Recognition of prior learning and credit transfer are two different things that end in the
same place — a unit the student holds without this RTO having delivered it.

- **RPL** (AVETMISS outcome **51**): the RTO assesses evidence of existing skills and
  knowledge against the unit, and grants it. There is an assessment; there is no delivery.
- **Credit transfer** (outcome **60**): the student already completed the unit somewhere
  else and holds AQF certification for it. National recognition means this RTO must accept
  it. There is neither delivery nor assessment — only authentication of the source.

Because neither involves delivery, both are recorded with delivery mode **90** (not
applicable), and a credit transfer is recorded with **zero scheduled hours** — national
recognition attracts none. Reversing a decision puts the enrolment's original delivery mode
and hours back.

## Two records, not one

This trips people up, so it is worth being explicit. Approving a decision writes **two**
things:

1. The **application record** in *RPL & Credit Transfer* — the assessor, the evidence, the
   rationale, the source documents. This is the audit trail for Standards 1.6 and 1.7.
2. The **result** in the student's results register — the unit, with outcome 51 or 60.

Only the second one produces anything. Statements of Attainment, qualification completion,
certificates and the AVETMISS NAT export all read the results register. An application
record showing *Approved* with no matching result is paperwork claiming a credit the student
does not actually hold.

The **In Results** column on the RPL & Credit Transfer register shows which state each
approved decision is in:

- **Recorded (60)** / **Recorded (51)** — the credit is in the results register. The unit is
  available on Student Results, the Statement of Attainment wizard and the NAT export.
- **Not recorded** — the decision is approved but nothing was written. Almost always the
  credit transfer source gate; see below.
- **Cannot post** — the record is approved but has no linked student, or no unit code, so
  there is nothing to write. Edit the record and add it.

## Why an approved credit transfer sometimes records nothing

Credit transfer is national recognition, so the RTO must sight and authenticate the original
certification from the issuing RTO before granting credit. Before outcome 60 is written, the
plugin requires **both**:

- an authenticated source — either the **USI transcript verified** tick, **or** an uploaded
  source certificate/transcript on the record; **and**
- the **source qualification code**.

If either is missing the application record still saves, but no result is written and the
page says so. Add the missing piece, set the decision to Approved and save again. From
v6.3.30 the page also confirms in plain words when the outcome *was* recorded, and names the
outcome, so there is no ambiguity either way.

## Getting a credited unit onto a Statement of Attainment

A credited unit has **no Moodle course** — the RTO did not run one. The multi-unit SoA
wizard used to build its list purely from Moodle course completions, so credited units were
invisible to it (fixed in v6.3.29). They now appear as ordinary rows, labelled *Credit
transfer (national recognition)* or *Recognition of prior learning* in place of a Moodle
category, and they group with the delivered units of the same qualification.

This applies to results **granted by a decision** — outcome 51 or 60, recorded manually. It is
deliberately not a general "show me everything in the register" switch: a historical result
brought in by a NAT or results import is not evidence that this RTO can certify the unit, and
is not offered here.

Two things follow from having no course:

- **Set the qualification code on the decision.** The credited unit is grouped by the
  qualification code stored on the application record, because there is no course to look it
  up from. A decision saved without one leaves the unit unmapped, and the qualification-level
  paths (testamur eligibility, partial SoA by qualification) will not count it.
- **The Course filter on the student picker cannot find these students.** They have no
  delivery course. Filter by **qualification** instead — that reads the qualification code on
  the decision and always works. The **category** filter also finds them, but only when the
  Qualification Builder product for that qualification has a Moodle category set on it (many do
  not), and only at the parent-category level, not a leaf semester folder.

## Getting the outcome right on the certificate

A Statement of Attainment must state the outcome the student actually holds. A unit granted
by credit transfer is *not* "Competent" — it is *Credit transfer/national recognition*, and
printing it as Competent claims this RTO assessed a unit it never saw. Every issuing path
reads the real outcome from the results register, including the manual *Issue certificate*
form, which types units as free text (v6.3.30 — before that it stamped every manually typed
unit as 20).

## Reversing or deleting a decision

- Changing an approved decision to **not approved** withdraws the credit from the results
  register.
- **Deleting** an approved record withdraws it too.
- Re-pointing an approved record at a **different unit or a different student** withdraws
  the credit from the old pair before granting the new one, so a typo correction cannot
  leave credit stranded with no decision behind it (v6.3.30).
- Deleting the **result** directly, from Student Results, does not touch the application
  record — the page warns when the result being deleted was granted by an approved decision,
  so the decision can be reversed there too.

## Dates

When the decision creates a **new** result — the usual case, because a credited unit has no
delivery enrolment to convert — its activity dates come from the assessor's **decision date**
if one is recorded, otherwise the day the record was saved (v6.3.30). This matters when
back-entering decisions: without it, a credit transfer decided in a previous collection period
lands in the current one, and both NAT files are wrong. A future decision date is ignored, so a
mistyped year cannot post the activity forward.

When the decision is granted **over an existing enrolment** the delivery dates are left alone.
They are real reported data and nothing stashes them for restoration if the decision is later
reversed, so overwriting them would be a one-way loss. Only a missing assessment date is filled
in. If those dates are wrong for the credited result, correct them on the enrolment in Student
Results.

## Competent outcomes, for reference

Only these four count as a positive final result anywhere in the plugin:

| Code | Meaning |
| --- | --- |
| 20 | Competency achieved / pass |
| 51 | RPL granted |
| 60 | Credit transfer / national recognition |
| 81 | Non-assessable activity — satisfactorily completed |

`52` (RPL **not** granted), `82` (not satisfactorily completed) and `85` (not yet started)
are **not** competent and never make a unit certifiable.
