# Why a certificate will not issue: the USI gate and the other pre-issue checks

<!-- pages: generate_qual_certs.php, generate_course_certs.php, qual_cert_hub.php, issue_certificate.php, soa_issue.php, certificates.php, students.php, usi_settings.php, student_profile.php -->
<!-- summary: The three checks that refuse a certificate before any credit is charged, what each one means, and exactly how to clear it. -->

This is the single most common support question: *"I selected a student, pressed Generate,
paid, and no certificate appeared in the register."*

Almost always, nothing was broken and **nothing was charged**. The certificate was refused by
a pre-issue check, and every one of those checks runs *before* credits are consumed.

## The three checks

A **Testamur**, a **Record of Results** and a **Statement of Attainment** are AQF
certification. All three are gated. A **Completion Certificate** for a non-accredited or
local course is not AQF certification and is not gated by any of these.

### 1. The student must have a USI that is VERIFIED

Under the Student Identifiers Act an RTO must not issue AQF certification to a student
without a Unique Student Identifier. The plugin enforces the stronger form of that rule: the
USI must have been **verified against the USI Registry**, not merely typed into a field. A
USI that is present but unverified is refused exactly like a blank one.

- *Refusal reason shown:* "No USI recorded" or "The USI on file has not been verified".
- *How to clear it:* open the student's profile, record their USI, and verify it. Bulk status
  for the whole cohort is on the USI Verification page.
- A student can complete every unit of a qualification and still be held here. Completing the
  training and being legally issuable are different questions.

### 2. The RTO's own identity must be configured

A Testamur must carry the RTO's legal name, its national provider code (RTO/TOID) and the
name of the authorised signatory. If any of those are unset the certificate would render with
AQF-required fields blank, so it is refused instead — for **every** student, not just some.

- *Refusal reason shown:* "Required RTO details are not configured: …"
- *How to clear it:* RTO Settings.
- If nobody on the site can issue anything, check this first. It is a site-wide block and it
  looks identical to a USI problem from the outside.

### 3. A Statement of Attainment or Record of Results must have something to say

A statement or record with no unit list **and** no qualification code would be an empty
compliance document. It is refused rather than produced.

- *Refusal reason shown:* "no units and no qualification code for this course/student".
- *How to clear it:* link the course to its unit in the Qualification Builder, or set the
  qualification code in the course settings.

## What you see on screen

From v6.3.13, every generation page — Generate by Qualification, Generate by Course, and the
Ready to Issue tab of the Qualification Certificate Hub — shows a **USI** column for every
student in the list, reading *Verified*, *Not verified*, *Missing*, *No student record*,
*Duplicate student record* (the person has more than one record, so their USI cannot be
resolved reliably), or *Not required* (for a Completion-Certificate-only course).

A student who would be refused **cannot be ticked**. Their tick box is disabled, the row is
tinted amber, the exact reason is printed beside their name, and an **Add / verify USI** link
opens their profile so it can be fixed on the spot. A count at the top of the page says how
many are held.

Select all eligible, None, the header tick box and the credit-cost confirmation all ignore
held rows, so the number of credits the confirmation quotes is the number that will actually
be spent.

## Credits

Certificates cost 5 credits each (about A$0.50). A qualification issues a Testamur and a
Record of Results, so 10 credits per student.

**A refused certificate costs nothing.** All three checks run before the credit call. If you
were quoted a charge in the confirmation dialog and then nothing appeared, no credits left
your balance — check the balance in the sidebar to confirm.

## The certificate queue

Students waiting on a certificate sit in the autocert queue with status *pending*. A student
held by one of these checks **stays pending on purpose**, so the row is not closed over a
certificate that was never produced.

**Nothing issues it automatically.** There is no scheduled task that processes this queue —
issuance is always started by a person. Once the USI is verified, either re-run the generation
page or use **Process Queue** on that qualification's Detail page in the Qualification
Certificate Hub. Keeping the row pending is what makes that possible; it is not a promise that
it will happen on its own.

This matters against the clock: certification must be issued within 30 days of the student
being assessed as competent. The plugin alerts on overdue issuance but does not resolve it.

Before v6.3.13 a held student was wrongly marked *complete*, and a completed queue row is
never re-queued — so the certificate could never appear, even after the USI was fixed. If a
student on an older build is missing a certificate they should have, and their queue row
reads complete, that is the cause.

## Force regenerate

Ticking *Force regenerate* voids the student's existing certificate and issues a replacement.
From v6.3.13 the old certificate is superseded **only after** the replacement exists. On
earlier builds it was voided first, so a refused replacement left the student holding nothing
at all while the summary reported it as a success.
