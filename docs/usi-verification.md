# USI verification: what it is and how this plugin does it

<!-- pages: usi_settings.php, students.php, student_profile.php, student_usi_verify.php -->
<!-- summary: What a verified USI means, how the plugin verifies against the Registry, and what each status value means. -->

A Unique Student Identifier is a lifetime reference number for a student's Australian VET
record. An RTO must collect it and must verify it, and must not issue AQF certification
without it.

## Present is not the same as verified

The plugin distinguishes three states, and the difference matters:

- **Missing** — no USI recorded at all.
- **Not verified** — a USI is stored, but the Registry has not confirmed that it belongs to
  this student, with this name and date of birth. A typo looks exactly like this.
- **Verified** — the Registry confirmed it.

Only *Verified* permits a Testamur, Record of Results or Statement of Attainment to issue.
This is deliberately stricter than "we have something in the field", because an unverified
USI is as likely to be a transcription error as a real identifier, and a certificate issued
against a wrong USI is a reporting defect that surfaces years later.

## How verification happens

Open a student and click **Verify USI**. The machine credential that talks to the Registry is
held on the lms-labs.com platform rather than in the Moodle site, and the plugin calls the
platform to verify against your provider code. The result is written back to the student
record with the date.

The USI Verification page shows the status of the whole cohort and lets you work through the
gaps.

## Formatting

A USI is ten characters, letters and digits, and the Registry lookup is **case-sensitive**.
The plugin stores USIs upper-cased and compares case-insensitively, so a student whose USI
was captured in lower case is not treated as having a different USI and does not silently
lose an existing verification.

## Students who cannot get one

A student who genuinely cannot obtain a USI — for example wholly-offshore delivery — is a
real exemption in the legislation. The plugin has a **USI exemption** field on the student's
AVETMISS profile, but it is captured only — there is no column for it on the plugin's student
table and it is not written to any NAT file.

It does **not**, however, release the certificate. The issuance gate has a bypass parameter
in the code, but nothing anywhere passes it — so an exempt student is still refused, and the
refusal message's advice to "mark the student USI-exempt / override" cannot currently be
acted on. If you hit this, treat it as a gap to raise rather than a setting to find.

## Why the profile gate does not demand a USI

By default the student profile completion gate excludes the USI from the fields it holds a
student on (an administrator can add it to the mandatory list, but should think hard first). A student cannot conjure a USI on demand — obtaining one takes a separate process
with the Registry — so holding them at a form until they have one would trap them. The USI is
required at *certificate issuance*, which is the point where it legally matters.
