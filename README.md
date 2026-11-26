# RTO Compliance (local_rtocompliance)

A complete ASQA 2025 Standards compliance and Student Management System (SMS)
for Australian Registered Training Organisations, built as a Moodle local plugin.

## Features

- AVETMISS 2.3 NAT import, validation and export
- Qualification Builder with training.gov.au integration and nominal-hours reference data
- Digital AQF certificates (Testamur, Statement of Attainment, Record of Results) with USI verification and QR authentication
- RPL and Credit Transfer register wired into student results and certificates
- Trainer/assessor credential, currency and supervision management
- Validation scheduling, complaints & appeals, continuous improvement
- Quality Indicator (AQTF LQ/EQ) surveys and reporting
- Governance, risk, insurance, fee protection and third-party registers
- Training and Assessment Strategy (TAS) generator
- Compliance Health command centre and ASQA Standards mapping
- Guided What / Why / How / Key Features help card on every page
- AI drafting, survey analysis and a compliance assistant, on LMS Labs credits or your own AI

## Bring your own AI

With a yearly LMS Labs licence, an administrator can choose the site's own AI for text in AI Grader
Central Config (`local_aiconfig`). The AI features then run on that AI, with no LMS Labs credits:
AI Suggest on the compliance forms, complaint and appeal drafts, survey analysis, student support
Auto Fill, validation methodology notes and the compliance assistant. Training.gov.au and USI
look-ups keep using LMS Labs. The assistant answers from the plugin's knowledge base, without the
LMS Labs ASQA document library.

## Requirements

- Moodle 4.4 – 5.3

## Installation

1. Copy this folder to `local/rtocompliance` in your Moodle site.
2. Log in as an administrator and complete the upgrade at Site administration → Notifications.
3. Purge all caches (Site administration → Development → Purge all caches).

## License

GNU GPL v3 or later — see the LICENSE file.

@copyright 2025 Essay Grader AI
