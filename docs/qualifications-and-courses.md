# Qualifications, units and Moodle courses

<!-- pages: qualbuilder.php, qualbuilder_edit.php, qualbuilder_semester.php, qualbuilder_autocreate.php, course_map.php, qualbuilder_results.php, tas.php -->
<!-- summary: How training products, units and Moodle courses relate, and why completion sometimes is not detected. -->

## The three layers

**Training product** — a qualification, skill set or single unit, created in the Qualification
Builder, ideally loaded from training.gov.au so its units and packaging rules are authoritative.

**Units** — the units of competency the product is made of, each with a national code.

**Moodle courses** — where delivery actually happens. A unit is linked to the course that
delivers it. The **Course Map** is the confirmed course → unit → qualification mapping that
certificates and completion detection read, so nothing has to guess from a course name.

## Variants and semester intakes

The same qualification code delivered in different intakes (2026 S1, an archived year) is kept
as separate products with different variants. Use the Semester Intake Builder to create one
product per intake with that intake's own course list.

Watch for **accidental duplicates**: two products with the same code and no meaningful variant
split the completer list between them, so neither shows everyone and the counts look wrong on
both.

## Why a completer is missing from a generation list

A student appears on the qualification generation list when they have finished every unit,
detected two independent ways:

1. Moodle course completion in every linked unit-course — needs completion tracking configured
   and calculated.
2. A competent outcome (Competent, RPL or Credit Transfer) recorded in the plugin's own
   results register for every selected unit — works with no Moodle completion setup at all.

If someone is missing, the usual causes are an unlinked unit, a course delivered under a
retired unit code with no course of its own, a Course Map that has not been rebuilt since the
products were created, or results that have not been synced from Moodle completions.

"Build Course Map from Links" fills the map from courses already linked to each unit, and only
adds missing rows.

## Semester copies of the same unit

Where each semester is a fresh copy of a course, the same unit of competency exists under many
course ids. Unit codes are resolved from the course itself — its ID number, or the national
code prefix in its full name — rather than from the shortname, which at many RTOs is a
semester code like "DIT 20S2". Duplicates collapse to one row per unit, keeping the competent
outcome and the latest completion date.
