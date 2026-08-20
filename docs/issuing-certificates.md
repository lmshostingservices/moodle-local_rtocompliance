# Choosing the right way to issue a certificate

<!-- pages: certificates.php, generate_qual_certs.php, generate_course_certs.php, qual_cert_hub.php, issue_certificate.php, soa_issue.php, reissue_cert.php, cert_templates.php -->
<!-- summary: Which of the five issuing routes to use, what each one produces, and how the register decides what to show. -->

There are five ways to produce a certificate. They differ in what they issue and who they
issue it to, not in quality — all of them write to the same register and all of them apply
the same pre-issue checks.

## The five routes

**Generate by Qualification** — bulk. Lists every student who has completed *every* unit of
one qualification and issues a **Testamur + Record of Results** to each selected student.
This is the normal end-of-course route.

**Generate by Course** — bulk, one Moodle course. The certificate type is decided per
student from the course: a nationally recognised course produces a **Statement of
Attainment**, a non-accredited or local course produces a **Completion Certificate**. This is
why Generate by Course can succeed for a student that Generate by Qualification refuses — a
Completion Certificate is not AQF certification and needs no USI.

**Qualification Certificate Hub** — the same qualification-level issuance as Generate by
Qualification, plus completion statistics, the automatic queue, and a one-click *Issue
Pending* for everyone outstanding. Use it when you want the picture as well as the action.

**Issue Multi-Unit SOA** — one student, a hand-picked set of units. Use it for a partial
qualification, a skill set, or a student leaving early.

**Issue Certificate** — one student, one certificate, fully manual.

## What the register shows

The Certificate Register lists every certificate with status *issued*, newest first. It
applies no other filter by default, so a certificate that was genuinely created appears
immediately, on page one.

If a certificate is missing from the register, it was not created. Look for the reason on the
generation page's summary banner or in the USI column — see *Why a certificate will not
issue*.

## Reissuing and superseding

Reissue produces a fresh certificate linked to the one it replaces, and marks the original
*superseded* so scanning its QR code says so rather than reporting it as valid. The audit
trail is the point: the original is never deleted.

## Templates

Certificate designs live in Certificate Templates. Each certificate type can have its own
design, and designs can differ by audience. A Record of Results and a Statement of Attainment
both render a three-column unit table — unit code, unit title, completion date — whose header
colour comes from Certificate Settings.

If a student's unit list is too long for one page the generation summary warns you, and the
certificate continues onto a second page. Reduce the font size or increase the unit table's
height on the template if you would rather it fit.
