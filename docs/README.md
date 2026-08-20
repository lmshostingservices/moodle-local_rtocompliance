# About this documentation

<!-- summary: How the docs/ folder works and how to edit it. -->

Every `.md` file in this folder is read by the RTO Compliance AI assistant at runtime and
becomes part of what it knows. There is no training step and no build step: edit a file,
save it, and the next question the assistant is asked uses the new text.

This folder is the **authoritative** description of how the software behaves. Where these
documents and the on-page help disagree, these documents are right and the help text needs
updating.

## Writing a document

Plain markdown. No PHP, no escaping, no special syntax.

- The first `#` heading is the document's title.
- Add `<!-- pages: students.php, usi_settings.php -->` anywhere in the file to associate it
  with plugin pages. When an admin asks a question from one of those pages, the assistant
  receives this document in full; from anywhere else it receives only the summary. That is
  how the knowledge base stays large without every question carrying every word.
- Add `<!-- summary: one sentence -->` to control that one-line summary. Without it the
  first ordinary line of the document is used.

## What the assistant also knows, without anyone writing it down

- **The recent release notes.** The newest ten are parsed straight out of `version.php`, each
  trimmed to a summary length, so each new version
  explains its own changes to the assistant the moment it is installed. Nothing here needs to
  be updated when a bug is fixed — the release note is the record.
- **Facts about the site it is running on.** Which required RTO details are unset, how many
  students hold a verified USI, what is waiting in the certificate queue, and the record
  behind the page the admin is currently looking at. This is read-only and can be turned off
  in Plugin Settings → AI Assistant.

## What to write here, and what not to

Write the things a person needs to understand rather than look up: why a rule exists, what
the compliance obligation behind it is, what to do when something is refused. Do not restate
button labels — the assistant already receives the page inventory and the on-page help.
