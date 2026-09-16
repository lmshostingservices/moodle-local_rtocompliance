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

A student who genuinely cannot obtain a USI is a real exemption in the legislation, and the
plugin supports it end to end. Open the student's record, set the **Exemption type** and the
**Reason for exemption**, and click **Record USI exemption**. The plugin also stores who
granted it and when, for audit. **Remove exemption** reverses it.

There are exactly two exemption types, because the collection standard defines exactly two
codes to lodge in place of an identifier:

- **`INTOFF`** — the student is studying wholly offshore, by online or distance delivery, and
  is not in Australia. This is the ground the great majority of exemptions rest on.
- **`INDIV`** — an individual exemption granted by the Student Identifiers Registrar.

The type is stored as its own value rather than being inferred from the reason text. That is
deliberate: guessing a reportable code out of prose is how address text once ended up in an
identifier field. The reason stays as the human explanation; the code is what gets lodged.

An exemption does three things:

1. **It clears the certificate gate.** An exempt student can be issued a Testamur, Record of
   Results or Statement of Attainment without a verified USI. The readiness panel shows the
   gate as *USI verified or exempt*.
2. **It is reported.** The export writes the exemption code into the USI field of both
   NAT00080 and NAT00085, and pairs it with the overseas postcode value where the standard
   requires that. Blank bytes are no longer sent where a code belongs.
3. **It stops inflating your outstanding count.** See below.

One rule worth knowing: **a real USI always wins.** If a student has an identifier on file,
that identifier is exported, and the exemption code is never written over it. The code is only
ever lodged *in place of* an identifier the student does not have.

## Offshore online delivery, and the outstanding count

**No USI recorded** on the USI Verification page counts students who are *required* to hold an
identifier and do not have one. It excludes recorded exemptions, because an exempt student is
not a compliance failure and counting them as one overstates the problem — sometimes badly.

There is a count card beside it, **Offshore online delivery, USI exempt**, and three filters:

- **Offshore Online Delivery USI Exempt** — students exempt on offshore grounds. A student
  matches on any of three signals: the `INTOFF` exemption code, a residential country outside
  Australia, or the overseas postcode value on their address.
- **Any recorded USI exemption** — both exemption types together.
- **No USI recorded (including exempt)** — the raw figure, for when you want to see what the
  adjustment is doing. The difference between this and *No USI recorded* is your exempt cohort.

Because they are filters and not only counts, you can pull the offshore cohort out of a chase
list, or exclude them from it.

If your offshore students are *not* appearing under the offshore filter, the usual cause is
that nothing on their record says they are offshore. Set **Residential country** on their
AVETMISS profile — see *Student records and AVETMISS data* — or record the exemption
explicitly.

**The two cards deliberately overlap, so do not expect them to sum.** A student whose address
says they are offshore but who has *no exemption recorded* appears under both: offshore by
address, and still outstanding because nobody has recorded the exemption. That is the right
answer for them — they are the students to act on. Recording the exemption moves them out of
*No USI recorded* and is also what puts a code in the NAT file for them.

## Why the profile gate does not demand a USI

By default the student profile completion gate excludes the USI from the fields it holds a
student on (an administrator can add it to the mandatory list, but should think hard first). A student cannot conjure a USI on demand — obtaining one takes a separate process
with the Registry — so holding them at a form until they have one would trap them. The USI is
required at *certificate issuance*, which is the point where it legally matters.
