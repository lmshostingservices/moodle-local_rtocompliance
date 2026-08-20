# Credits and billing

<!-- pages: ai_usage_report.php, plugin_settings.php, certificates.php -->
<!-- summary: What consumes platform credits, what does not, and how to read the balance. -->

Certain actions consume platform credits held on lms-labs.com against this site.

- **Certificate issuance** — 5 credits per certificate (about A$0.50). A full qualification
  issues a Testamur and a Record of Results, so 10 credits per student.
- **AI assistant** — 1 credit per question.
- **USI verification** — handled by the platform against your provider code.

The balance is shown at the bottom of the plugin sidebar and on the AI Credit Usage page.

## What does not cost anything

**A refused certificate is free.** Every pre-issue check — no verified USI, missing RTO
details, an empty unit list — runs before the credit call, so a student who cannot be issued
never costs a credit. If a generation run reported nothing issued, nothing was charged.

The confirmation dialog quotes a cost *before* contacting the server, so seeing "this will
charge N credits" is not evidence that N credits were spent. From v6.3.13 the quote excludes
students who are held, so it matches what will really happen.

## Running out mid-run

A bulk run that exhausts the balance stops cleanly and reports how many students were not
attempted, rather than failing silently. Top up and re-run — already-issued students are
skipped, so re-running is safe and does not double-charge.
