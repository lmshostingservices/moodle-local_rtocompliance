<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Certificate template CRUD model + status workflow.
 *
 * Wraps the local_rtocompliance_certtmpl table.  All UI pages
 * (cert_templates.php, cert_template_edit.php, cert_template_action.php,
 * cert_template_preview.php) and the runtime renderer
 * (cert_template_renderer.php) go through this class — never touch the
 * table directly.
 *
 * Status workflow:
 *   draft     → submit_for_approval() → approved (only if validator passes)
 *   approved  → activate()            → approved + isactive=1 (the previously-active
 *                                        template for the same certtype is demoted
 *                                        to isactive=0; status stays approved)
 *   any       → archive()             → archived + isactive=0
 *   archived  → re-open via duplicate(), original stays archived
 *
 * design_json shape (canonical):
 *   {
 *     "page":   { "format": "A4", "orientation": "L", "width_mm": 297, "height_mm": 210,
 *                 "bg_color": "#ffffff", "bg_image_url": "/local/rtocompliance/cert_template_image.php?..." },
 *     "fields": [
 *       { "id": "f1",
 *         "kind": "dynamic" | "text" | "date" | "image" | "line" | "box",
 *         "dynamickey": "student.fullname" | ... | null,
 *         "x_mm": 50.0, "y_mm": 30.5,
 *         "w_mm": 100.0, "h_mm": 12.0,
 *         "font": "helvetica" | "times" | "courier",
 *         "fontsize": 14,
 *         "fontstyle": "" | "B" | "I" | "BI",
 *         "color": "#000000",
 *         "align": "L" | "C" | "R",
 *         "text": "Free text content (kind=text only)",
 *         "dateformat": "d M Y" (kind=date only),
 *         "imageurl": "..." (kind=image only — server-side resolved URL),
 *         "imageitemid": 12345 (kind=image only — Moodle file API itemid),
 *         "linewidth": 0.5 (kind=line/box only)
 *       }
 *     ]
 *   }
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/certificate_validator.php');

class cert_template {
    /**
     * v4.3.0 CERT-TEMPLATE-AUDIENCES — supported audience codes.
     *
     * The runtime selection rule is (certtype + audience) — admins can
     * activate ONE template per (certtype + audience) pair. The 'default'
     * audience is the back-compat catch-all used for every cert that
     * does not have an audience pinned at issue time and for every
     * legacy template that pre-dates v4.3.0.
     *
     * Lang strings live as cert_template_audience_<code>.
     *
     * @var string[]
     */
    const AUDIENCES = [
        'default',
        'apprentice',
        'traineeship',
        'school',
        'vetfee',
        'funded_state',
        'funded_commonwealth',
        'international',
        'private_fee',
    ];

    /** @var string Filearea for full-page background images */
    const FA_BG = 'cert_template_bg';

    /** @var string Filearea for per-field uploaded images (logo, seal, decorative) */
    const FA_IMAGE = 'cert_template_image';

    /** @var string CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — system-wide RTO branding (one logo + one CEO signature) */
    const FA_BRANDING = 'rto_branding';

    /** @var int Pseudo-itemid in FA_BRANDING for the RTO logo */
    const BRANDING_ITEMID_LOGO = 1;

    /**
     * Pseudo-itemid for the system-wide State / Territory Training Authority
     * logo (ASQA-COMPLIANCE-PASS-3, v4.2.60). Optional — only RTOs delivering
     * state-funded VET on a state contract need to display this logo on
     * testamurs / SoAs. v4.4.0 generalised: there is no single "STA logo"
     * (each state has its own funding-body branding), so this slot is now
     * one of two free-form "compliance logos" the admin can upload via
     * Site administration → Plugins → Local plugins → AI RTO Compliance.
     * No bundled fallback — empty unless the admin uploads a file.
     */
    const BRANDING_ITEMID_STA_LOGO = 3;

    /** @var int Pseudo-itemid in FA_BRANDING for the CEO signature image */
    const BRANDING_ITEMID_SIGNATURE = 2;

    /**
     * v4.4.0 NRT-LOGO-COMPLIANCE — pseudo-itemid for the official
     * ASQA-supplied NRT logo. Per the NRT Logo Conditions of Use
     * Policy ("can only be reproduced from hard or electronic copies
     * provided by the National VET Regulator"), every RTO should
     * upload the artwork ASQA sent them directly. If no admin upload
     * exists the renderer falls back to the bundled pix/nrt_logo.png
     * (visually correct PMS 343 green / PMS 192 red triangle in
     * Fritz-Quadrata-style serif, supplied for out-of-the-box use).
     */
    const BRANDING_ITEMID_NRT_LOGO = 4;

    /**
     * v4.4.0 — pseudo-itemid for the official AQF logo (uploaded by
     * the admin to override the bundled pix/aqf_logo.jpg fallback).
     * Optional on testamurs (the AQF recognition statement is required;
     * the AQF logo is permitted in addition).
     */
    const BRANDING_ITEMID_AQF_LOGO = 5;

    /**
     * v4.4.0 — pseudo-itemid for the organisation's seal / corporate
     * identifier / unique watermark. Required on testamur + SoA per
     * ASQA Practice Guide (Issue of VET qualifications and VET
     * statements of attainment, 2025-06-17, item 1(e)/2(e)).
     * Distinct from the RTO logo and the authenticity measure.
     */
    const BRANDING_ITEMID_ORG_SEAL = 6;

    /**
     * v5.9.320 CERT-ASSETS — secondary RTO logo slot. Some certs display
     * two RTO logos (e.g. a registered brand logo + a trading-name logo,
     * or a consortium arrangement).  Uploaded via RTO Settings; per-cert-type
     * visibility controlled by the secondary_logo_cert_types config key.
     */
    const BRANDING_ITEMID_SECONDARY_LOGO = 7;

    /**
     * v5.9.321 ORPHAN-FIX — second free-form compliance logo slot.  The first
     * slot (BRANDING_ITEMID_STA_LOGO = 3, settings key 'compliance_logo_1') has
     * always existed; this second slot (settings key 'compliance_logo_2') was
     * defined in settings.php since v4.4.0 but never assigned an itemid or wired
     * into the renderer. Dynamic key: 'compliance_logo_2'.
     */
    const BRANDING_ITEMID_COMPLIANCE_LOGO_2 = 9;

    /**
     * v5.9.320 CERT-ASSETS — system-wide certificate page background image.
     * Applied as the full-page background layer when a cert template has no
     * template-specific background image set (design_json page.bg_image_url
     * is empty). Per-cert-type visibility controlled by cert_background_cert_types.
     * Useful for a common watermark / texture that applies to e.g. testamur only.
     */
    const BRANDING_ITEMID_CERT_BG = 8;

    /** @var array Allowed certtype values — must match certificate_validator AQF_TEMPLATE_REQUIREMENTS keys */
    const CERT_TYPES = ['testamur', 'statement', 'record', 'completion'];

    /** @var array Allowed status values */
    const STATUSES = ['draft', 'approved', 'archived'];

    /**
     * The full catalogue of dynamic fields a template author can place on
     * the canvas.  Keys are the dynamickey values stored in design_json;
     * the resolver in cert_template_renderer.php translates them to live
     * data at render time.
     *
     * 'required_for' lists the certtypes for which this dynamic field
     * MUST be present on the canvas for the template to be ASQA-approvable.
     * 'group' is purely cosmetic (palette section heading).
     *
     * @return array
     */
    /**
     * FONTS (v6.2.63): curated Google-Fonts catalogue offered in the editor font picker.
     * Each entry: 'label' (display), 'google' (Google Fonts family for the webfont preview),
     * 'css' (canvas font-family), 'core' (the closest built-in TCPDF family used on the PDF
     * unless an embedded font file for the key is present in the plugin fonts/ directory).
     * The canvas always shows the real Google font (webfont); the PDF shows the real font when
     * embedded, otherwise the matching built-in family — so a certificate can never fail to render.
     *
     * @return array key => ['label','google','css','core']
     */
    public static function font_catalogue(): array {
        $sans = 'helvetica'; $serif = 'times'; $mono = 'courier';
        $mk = function ($label, $google, $stack, $core) {
            return ['label' => $label, 'google' => $google, 'css' => "'" . $google . "', " . $stack, 'core' => $core];
        };
        return [
            // Sans-serif.
            'roboto'        => $mk('Roboto', 'Roboto', 'sans-serif', $sans),
            'opensans'      => $mk('Open Sans', 'Open Sans', 'sans-serif', $sans),
            'lato'          => $mk('Lato', 'Lato', 'sans-serif', $sans),
            'montserrat'    => $mk('Montserrat', 'Montserrat', 'sans-serif', $sans),
            'poppins'       => $mk('Poppins', 'Poppins', 'sans-serif', $sans),
            'raleway'       => $mk('Raleway', 'Raleway', 'sans-serif', $sans),
            'nunito'        => $mk('Nunito', 'Nunito', 'sans-serif', $sans),
            'worksans'      => $mk('Work Sans', 'Work Sans', 'sans-serif', $sans),
            'inter'         => $mk('Inter', 'Inter', 'sans-serif', $sans),
            'sourcesans3'   => $mk('Source Sans 3', 'Source Sans 3', 'sans-serif', $sans),
            'ptsans'        => $mk('PT Sans', 'PT Sans', 'sans-serif', $sans),
            'oswald'        => $mk('Oswald', 'Oswald', 'sans-serif', $sans),
            'mulish'        => $mk('Mulish', 'Mulish', 'sans-serif', $sans),
            'rubik'         => $mk('Rubik', 'Rubik', 'sans-serif', $sans),
            // Serif.
            'merriweather'  => $mk('Merriweather', 'Merriweather', 'serif', $serif),
            'playfair'      => $mk('Playfair Display', 'Playfair Display', 'serif', $serif),
            'lora'          => $mk('Lora', 'Lora', 'serif', $serif),
            'ptserif'       => $mk('PT Serif', 'PT Serif', 'serif', $serif),
            'robotoslab'    => $mk('Roboto Slab', 'Roboto Slab', 'serif', $serif),
            'ebgaramond'    => $mk('EB Garamond', 'EB Garamond', 'serif', $serif),
            'cormorant'     => $mk('Cormorant Garamond', 'Cormorant Garamond', 'serif', $serif),
            'librebaskerville' => $mk('Libre Baskerville', 'Libre Baskerville', 'serif', $serif),
            'crimsonpro'    => $mk('Crimson Pro', 'Crimson Pro', 'serif', $serif),
            // Display / handwriting (certificates, signatures).
            'greatvibes'    => $mk('Great Vibes', 'Great Vibes', 'cursive', $serif),
            'pacifico'      => $mk('Pacifico', 'Pacifico', 'cursive', $serif),
            'dancingscript' => $mk('Dancing Script', 'Dancing Script', 'cursive', $serif),
            'caveat'        => $mk('Caveat', 'Caveat', 'cursive', $serif),
            'sacramento'    => $mk('Sacramento', 'Sacramento', 'cursive', $serif),
            'cinzel'        => $mk('Cinzel', 'Cinzel', 'serif', $serif),
            // Monospace.
            'robotomono'    => $mk('Roboto Mono', 'Roboto Mono', 'monospace', $mono),
            'sourcecodepro' => $mk('Source Code Pro', 'Source Code Pro', 'monospace', $mono),
        ];
    }

    public static function get_dynamic_field_catalogue(): array {
        // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — rebuilt to match the ASQA
        // "Issuing AQF qualifications and statements of attainment" Fact
        // Sheet exactly. Critical changes from v4.2.40-v4.2.42:
        //   - student.usi REMOVED from testamur + statement (USI must NOT
        //     appear on those documents; it is recorded internally only).
        //   - student.usi remains REQUIRED for record (the unofficial
        //     transcript/record-of-results which DOES include the USI).
        //   - nrt_logo REQUIRED for testamur + statement (was not even
        //     in the catalogue before).
        //   - aqf_logo, state_training_authority_logo, authenticity_measure,
        //     industry_descriptor, occupational_stream, australian_apprenticeship,
        //     language_statement, skill_set_statement, qualification.partofstatement,
        //     qualification.completionofcoursestatement, certify_statement,
        //     attained_statement, record_of_results_heading, rto.logo —
        //     all NEW first-class typed fields so authors don't have to
        //     hand-type the mandatory phrases as plain text.
        return [
            // ── Student ─────────────────────────────────────────────────
            'student.fullname'                       => ['label' => 'Student full name',                       'group' => 'Student',       'required_for' => ['testamur', 'statement', 'record', 'completion'], 'sample' => 'Jane Citizen'],
            'student.usi'                            => ['label' => 'Unique Student Identifier (USI)',         'group' => 'Student',       'required_for' => [],                                                'sample' => 'AB12CD34EF', 'forbidden_for' => ['testamur', 'statement']],
            'student.dob'                            => ['label' => 'Date of birth',                           'group' => 'Student',       'required_for' => [],                                                'sample' => '01/01/1990'],
            // STUDENT-DETAILS-TABLE (v6.2.51): one formatted table — STUDENT NAME | USI |
            // QUALIFICATION — for the Record of Results header. Placing it satisfies the
            // ASQA student-name + qualification-code + qualification-name requirements
            // (the three values live inside the table), so the separate fields aren't needed.
            'student.detailstable'                   => ['label' => 'Student details table (Name / USI / Qualification)', 'group' => 'Student', 'required_for' => [],                                    'sample' => '[Student details table]', 'forbidden_for' => ['testamur', 'statement']],

            // ── Qualification / Course ──────────────────────────────────
            // ASQA-RECORD-COMPLIANCE (v5.2.54): 'statement' removed from required_for on
            // qualification.code and qualification.name. On a SoA the unit code/name
            // appears inside the qualification.units table, not as a separate field.
            // The statement validator does not check for qualificationCode/qualificationName.
            'qualification.code'                     => ['label' => 'Qualification code',                      'group' => 'Qualification', 'required_for' => ['testamur', 'record'],                            'sample' => 'BSB30120'],
            'qualification.name'                     => ['label' => 'Qualification name',                      'group' => 'Qualification', 'required_for' => ['testamur', 'record'],                            'sample' => 'Certificate III in Business'],
            // UNITS-TABLE (v6.2.55): the Statement of Attainment units table. Placing this on a
            // SoA draws the shaded Unit Code | Unit Title | Date table. (The Record of Results
            // uses the dedicated "Units of competency table" field kind from the palette, which
            // draws the 5-column Enrolment Date | Unit Code | Unit Title | Result | Completion
            // Date table with the result-code key.) The legacy separate RoR Col 1/2/3 text
            // fields were removed from the palette in v6.2.55 to avoid confusion — there is now
            // one units table per certificate type. Existing templates that still carry the old
            // column keys keep rendering (the payload still provides them).
            'qualification.units'                    => ['label' => 'Units of competency table (Statement of Attainment)',  'group' => 'Qualification', 'required_for' => ['statement'],                           'sample' => 'BSBCMM311 Apply critical thinking skills…'],
            'qualification.partofstatement'          => ['label' => '"These competencies form part of" line',  'group' => 'Qualification', 'required_for' => [],                                                'sample' => 'These competencies form part of BSB30120 Certificate III in Business.'],
            'qualification.completionofcoursestatement' => ['label' => '"Completion of course" statement (auto-built with qual code)', 'group' => 'Qualification', 'required_for' => [],                                     'sample' => 'These competencies were attained in completion of BSB30120 course in Certificate III in Business.'],
            'cert.coursetitle'                       => ['label' => 'Course / activity name',                  'group' => 'Qualification', 'required_for' => ['completion'],                                    'sample' => 'Workplace First Aid (Non-Accredited)'],

            // ── Certificate metadata ────────────────────────────────────
            'cert.number'                            => ['label' => 'Certificate number',                      'group' => 'Certificate',   'required_for' => ['testamur', 'statement', 'record', 'completion'], 'sample' => 'CERT-2026-000123'],
            'cert.issuedate'                         => ['label' => 'Date of issue',                           'group' => 'Certificate',   'required_for' => ['testamur', 'statement', 'record', 'completion'], 'sample' => '01 May 2026'],
            'cert.completiondate'                    => ['label' => 'Completion date',                         'group' => 'Certificate',   'required_for' => [],                                                'sample' => '15 April 2026'],

            // ── RTO ─────────────────────────────────────────────────────
            'rto.name'                               => ['label' => 'RTO organisation name',                   'group' => 'RTO',           'required_for' => ['testamur', 'statement', 'record', 'completion'], 'sample' => 'National Compliance Training'],
            'rto.code'                               => ['label' => 'RTO code (TOID/RTO ID)',                  'group' => 'RTO',           'required_for' => ['testamur', 'statement', 'record'],               'sample' => 'TOID 50918'],
            'rto.logo'                               => ['label' => 'RTO logo (from Branding panel)',          'group' => 'RTO',           'required_for' => [],                                                'sample' => '[RTO logo]'],
            'rto.secondary_logo'                     => ['label' => 'Secondary RTO / partner logo (from Branding panel)', 'group' => 'RTO',  'required_for' => [],                                                'sample' => '[Secondary logo]'],

            // ── Signatory ───────────────────────────────────────────────
            'signatory.name'                         => ['label' => 'Authorised signatory name',               'group' => 'Signatory',     'required_for' => ['testamur', 'statement', 'record'],               'sample' => 'Dr A. Authorised'],
            'signatory.title'                        => ['label' => 'Signatory position/title',                'group' => 'Signatory',     'required_for' => [],                                                'sample' => 'Chief Executive Officer'],
            'signatory.signature'                    => ['label' => 'Signatory signature (from Branding panel)', 'group' => 'Signatory',   'required_for' => [],                                                'sample' => '[CEO signature image]'],

            // ── Mandatory ASQA phrases (typed text fields) ──────────────
            // Testamur phrases (ASQA Sample Forms fact sheet p.2)
            'certify_statement'                      => ['label' => '"This is to certify that" (testamur opening)',          'group' => 'Mandatory phrases', 'required_for' => ['testamur'],   'forbidden_for' => ['statement'], 'sample' => 'This is to certify that'],
            'attained_statement'                     => ['label' => '"has fulfilled the requirements for" (testamur)',       'group' => 'Mandatory phrases', 'required_for' => ['testamur'],   'forbidden_for' => ['statement'], 'sample' => 'has fulfilled the requirements for'],
            // SoA phrases (ASQA Sample Forms fact sheet p.4) — different wording to testamur
            'soa_intro_statement'                    => ['label' => '"This is a statement that" (SoA opening)',              'group' => 'Mandatory phrases', 'required_for' => ['statement'],  'forbidden_for' => ['testamur'], 'sample' => 'This is a statement that'],
            'soa_attained_statement'                 => ['label' => '"has attained" (SoA attainment phrase)',                'group' => 'Mandatory phrases', 'required_for' => ['statement'],  'forbidden_for' => ['testamur'], 'sample' => 'has attained'],
            'statement_of_attainment_heading'        => ['label' => '"Statement of Attainment" heading',                    'group' => 'Mandatory phrases', 'required_for' => ['statement'],                                   'sample' => 'Statement of Attainment'],
            'record_of_results_heading'              => ['label' => '"Record of Results" heading',                          'group' => 'Mandatory phrases', 'required_for' => ['record'],                                      'sample' => 'Record of Results'],
            'aqf_statement'                          => ['label' => 'AQF recognition statement',                            'group' => 'Mandatory phrases', 'required_for' => ['testamur'],                                    'sample' => 'This qualification is recognised within the Australian Qualifications Framework.'],
            'not_a_testamur_statement'               => ['label' => 'NOT-A-TESTAMUR statement',                             'group' => 'Mandatory phrases', 'required_for' => ['statement'],                                   'sample' => 'A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS. THIS IS NOT A TESTAMUR.'],

            // ── Compliance marks (logos + authenticity + seal) ──────────
            // v4.4.0 NRT-LOGO-COMPLIANCE — nrt_logo is now FORBIDDEN on
            // record/attendance/completion per the NRT Logo Conditions
            // of Use Policy ("must not be depicted on other testamurs
            // or transcripts of results"). Validator hard-blocks Submit
            // for approval if dragged onto a forbidden cert type.
            // organisation_seal is now REQUIRED on testamur + statement
            // per ASQA Practice Guide item 1(e)/2(e) (separate from the
            // RTO logo and the authenticity measure).
            // AUTHENTICITY-MEASURE-COMPLETION (v4.4.9): NRT logo is FORBIDDEN on
            // record/attendance/completion per the NRT Logo Conditions of Use
            // ("must not be depicted on other testamurs or transcripts of results").
            // NOTE FOR RTO ADMINS: Because your Certificate of Completion is NOT a
            // nationally-recognised-training (AQF) document, the NRT logo must NOT
            // appear on it. Use your own RTO logo and an authenticity measure instead.
            'nrt_logo'                               => ['label' => 'NRT logo — Nationally Recognised Training (required for AQF certs only; FORBIDDEN on completion/attendance)', 'group' => 'Compliance', 'required_for' => ['testamur', 'statement'], 'sample' => '[NRT logo]', 'forbidden_for' => ['record', 'attendance', 'completion']],
            'aqf_logo'                               => ['label' => 'AQF logo (optional)',                     'group' => 'Compliance',    'required_for' => [],                                                'sample' => '[AQF logo]'],
            'state_training_authority_logo'          => ['label' => 'Compliance logo 1 (state funder, etc.)',  'group' => 'Compliance',    'required_for' => [],                                                'sample' => '[Compliance logo]'],
            'compliance_logo_2'                      => ['label' => 'Compliance logo 2 (additional funder/partner)', 'group' => 'Compliance', 'required_for' => [],                                            'sample' => '[Compliance logo 2]'],
            'organisation_seal'                      => ['label' => 'Organisation seal / corporate identifier / watermark', 'group' => 'Compliance', 'required_for' => [],                                       'sample' => '[Org seal]'],
            // AUTHENTICITY-MEASURE-COMPLETION (v4.4.9): ASQA fact sheet requires an
            // AUTHENTICITY MEASURE on testamur, record, and statement. It is also
            // strongly recommended for completion/attendance (fraud reduction).
            // What the RTO must supply: the authenticity_measure field renders the
            // certificate verification URL automatically (e.g. "Verify at:
            // https://yourrto.com/verify/CERT-2026-000123"). For a physical seal,
            // watermark, or corporate identifier, upload it in the Branding panel —
            // it will be overlaid on the PDF as the organisation_seal field.
            'authenticity_measure'                   => ['label' => 'Authenticity measure — verification URL (ASQA required on AQF certs; strongly recommended on all certs)', 'group' => 'Compliance', 'required_for' => ['testamur', 'statement', 'record'], 'recommended_for' => ['completion', 'attendance'], 'sample' => 'Verify at: https://example.com/verify/CERT-2026-000123'],

            // ── Optional descriptors ────────────────────────────────────
            'industry_descriptor'                    => ['label' => 'Industry descriptor (optional)',          'group' => 'Optional descriptors', 'required_for' => [],                                         'sample' => 'Business Services'],
            'occupational_stream'                    => ['label' => 'Occupational stream (optional)',          'group' => 'Optional descriptors', 'required_for' => [],                                         'sample' => 'Administration'],
            'australian_apprenticeship'              => ['label' => 'Australian Apprenticeship statement',     'group' => 'Optional descriptors', 'required_for' => [],                                         'sample' => 'Completed under an Australian Apprenticeship.'],
            'language_statement'                     => ['label' => 'Language of issue statement',             'group' => 'Optional descriptors', 'required_for' => [],                                         'sample' => 'These unit/modules have been delivered and assessed in [insert language].'],
            'skill_set_statement'                    => ['label' => 'Skill set statement (SOA only)',          'group' => 'Optional descriptors', 'required_for' => [],                                         'sample' => 'These units form part of the Workplace First Aid skill set.'],

            // ── Verification ────────────────────────────────────────────
            'qrcode'                                 => ['label' => 'Verification QR code',                    'group' => 'Verification',  'required_for' => [],                                                'sample' => '[QR]'],
            'verify.url'                             => ['label' => 'Verification URL (text)',                 'group' => 'Verification',  'required_for' => [],                                                'sample' => 'https://example.com/verify/CERT-2026-000123'],
        ];
    }

    /**
     * Returns the empty/default design used when a template is first
     * created — kept as a thin wrapper around build_starter_design() for
     * backwards compatibility with v4.2.40-v4.2.42 callers.
     *
     * @param string $certtype
     * @return array
     */
    public static function get_default_design(string $certtype): array {
        return self::build_starter_design($certtype);
    }

    /**
     * CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — Pre-populated, professionally
     * laid-out starter design for each cert type.  Every mandatory
     * dynamic field is placed at sensible default coordinates so the
     * admin opens the editor to a complete, ASQA-compliant template
     * they can immediately preview, and just tweak rather than build
     * from scratch.
     *
     * v4.2.58 (RTO-CHOICE): both PORTRAIT and LANDSCAPE variants are
     * available for every cert type — admins choose the orientation
     * their RTO prefers.  Default orientations match the ASQA fact
     * sheet diagrams (testamur LANDSCAPE, statement/record PORTRAIT,
     * completion LANDSCAPE) but the alternate orientation is fully
     * supported.  Pass $orientation = 'P'|'L' to pick explicitly.
     *
     * @param string      $certtype
     * @param string|null $orientation 'P' = portrait, 'L' = landscape, null = default for certtype
     * @return array
     */
    public static function build_starter_design(string $certtype, ?string $orientation = null): array {
        $o = $orientation ?: self::default_orientation($certtype);
        switch ($certtype) {
            case 'testamur':   $design = $o === 'P' ? self::starter_testamur_portrait()   : self::starter_testamur();    break;
            case 'statement':  $design = $o === 'L' ? self::starter_statement_landscape() : self::starter_statement();   break;
            case 'record':     $design = $o === 'L' ? self::starter_record_landscape()    : self::starter_record();      break;
            case 'completion': $design = $o === 'P' ? self::starter_completion_portrait() : self::starter_completion();  break;
            default:           $design = self::blank_page('L');
        }
        // v5.9.361: certificate number + verification QR are mandatory on every cert.
        return self::ensure_mandatory_fields($design);
    }

    /**
     * v5.9.361 MANDATORY-FIELDS: guarantee the certificate NUMBER and the
     * verification QR are present on every design; append at a sensible default
     * position if missing (admins can reposition in the editor). Called from
     * build_starter_design() and from the renderer on every render.
     *
     * @param array $design canonical design array (page + fields)
     * @return array design with cert.number and qrcode guaranteed present
     */
    public static function ensure_mandatory_fields(array $design): array {
        $fields = $design['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }
        $isp = (($design['page']['orientation'] ?? 'L') === 'P');
        $pw  = (float) ($design['page']['width_mm']  ?? ($isp ? 210 : 297));
        $ph  = (float) ($design['page']['height_mm'] ?? ($isp ? 297 : 210));

        $haskey = function (string $key) use ($fields): bool {
            foreach ($fields as $f) {
                if (($f['kind'] ?? '') === 'dynamic' && ($f['dynamickey'] ?? '') === $key) {
                    return true;
                }
            }
            return false;
        };
        $maxid = 0;
        foreach ($fields as $f) {
            $maxid = max($maxid, (int) preg_replace('/[^0-9]/', '', (string) ($f['id'] ?? 'f0')));
        }
        $nextid = 'f' . ($maxid + 1);

        if (!$haskey('qrcode')) {
            $qs = 22.0;
            $fields[] = self::mkf($nextid, 'dynamic', [
                'dynamickey' => 'qrcode',
                'x_mm' => round($pw - $qs - 8, 1), 'y_mm' => round($ph - $qs - 10, 1),
                'w_mm' => $qs, 'h_mm' => $qs,
            ]);
        }
        if (!$haskey('cert.number')) {
            $fields[] = self::mkf($nextid, 'dynamic', [
                'dynamickey' => 'cert.number',
                'x_mm' => 15, 'y_mm' => round($ph - 12, 1), 'w_mm' => round($pw - 45, 1), 'h_mm' => 5,
                'fontsize' => 8, 'align' => 'R', 'color' => '#666666',
            ]);
        }

        $design['fields'] = $fields;
        return self::normalise_design($design);
    }

    /**
     * LAYOUT-RULES (v5.9.449) — global certificate layout rules applied to EVERY
     * cert type, for both freshly-built starters and existing saved templates
     * (this runs from ensure_mandatory_fields(), which the renderer and the editor
     * both call, so the PDF and the editor canvas stay perfectly in sync — no
     * risky one-off DB migration and no divergence):
     *
     *   • Remove the small "DATE" and "AUTHORISED PERSON" caption labels — the
     *     issue date and signatory name/title still print; only the tiny captions go.
     *   • The NRT logo, the organisation seal and the verification QR render 30mm
     *     tall (aspect preserved and centred inside their box by the renderer).
     *
     * Idempotent: running it repeatedly yields the same design.
     *
     * @param array $design canonical design array (page + fields)
     * @return array normalised design
     */
    public static function normalise_design(array $design): array {
        $fields = $design['fields'] ?? [];
        if (!is_array($fields)) {
            return $design;
        }
        $out = [];
        foreach ($fields as $f) {
            $kind = $f['kind'] ?? '';
            if ($kind === 'text') {
                $t = strtoupper(trim((string) ($f['text'] ?? '')));
                if ($t === 'DATE' || $t === 'AUTHORISED PERSON') {
                    continue; // drop the caption label on every cert type
                }
            }
            if ($kind === 'dynamic') {
                $dk = $f['dynamickey'] ?? '';
                // RESPECT-USER-SIZE (v6.2.25): previously the NRT logo, organisation seal
                // and QR code were force-sized here on every render (h=30mm; QR 30x30),
                // which meant resizing them in the editor had NO effect on the rendered
                // certificate — the preview always showed their original size. Now we only
                // supply a sensible default size when the field has no usable size of its
                // own, so the size set in the editor is honoured. The renderer still
                // preserves each image's aspect ratio and centres it within the box.
                $hh = (float) ($f['h_mm'] ?? 0);
                $ww = (float) ($f['w_mm'] ?? 0);
                if ($dk === 'nrt_logo' || $dk === 'organisation_seal') {
                    if ($hh <= 0) { $f['h_mm'] = 30; }
                } else if ($dk === 'qrcode') {
                    if ($hh <= 0) { $f['h_mm'] = 30; }
                    if ($ww <= 0) { $f['w_mm'] = 30; }
                }
            }
            $out[] = $f;
        }
        $design['fields'] = $out;
        return $design;
    }

    /**
     * STUDENT-DETAILS-TABLE AUTO-ADOPT (v6.2.52) — at render time, upgrade a Record of Results
     * design that still uses the legacy stacked identity block ("Name of student:" / "USI:" /
     * "Name of qualification:" label rows + the student.fullname / student.usi /
     * qualification.code / qualification.name fields) into the single shaded
     * student.detailstable field, so existing templates show the new table WITHOUT the author
     * having to rebuild. Purely a render-time transform — the saved template is never modified.
     *
     * Conservative gate (avoids surprising a customised layout): only fires when BOTH the
     * student.fullname field AND the standard "Name of student:" caption are present, and only
     * when a student.detailstable is not already on the canvas. Idempotent.
     *
     * @param array $design decoded design (page + fields)
     * @return array design with the identity block replaced by student.detailstable when eligible
     */
    public static function upgrade_record_identity_to_table(array $design): array {
        $fields = $design['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            return $design;
        }

        $identitykeys = ['student.fullname', 'student.usi', 'qualification.code', 'qualification.name'];
        $labeltexts   = ['name of student', 'usi', 'name of qualification'];

        $group = [];        // indices of fields that form the legacy identity block.
        $hasname = false;   // student.fullname dynamic field present?
        $hasstdlabel = false; // the standard "Name of student:" caption present?
        foreach ($fields as $i => $f) {
            $kind = $f['kind'] ?? '';
            if ($kind === 'dynamic') {
                $dk = $f['dynamickey'] ?? '';
                if ($dk === 'student.detailstable') {
                    return $design; // Already upgraded — nothing to do.
                }
                if (in_array($dk, $identitykeys, true)) {
                    $group[$i] = $f;
                    if ($dk === 'student.fullname') {
                        $hasname = true;
                    }
                }
            } else if ($kind === 'text') {
                $t = strtolower(trim(rtrim((string)($f['text'] ?? ''), ': ')));
                if (in_array($t, $labeltexts, true)) {
                    $group[$i] = $f;
                    if ($t === 'name of student') {
                        $hasstdlabel = true;
                    }
                }
            }
        }

        if (!$hasname || !$hasstdlabel || empty($group)) {
            return $design; // Not the standard stacked layout — leave the author's design alone.
        }

        // Bounding box of the identity block so the table lands in the same place/width.
        $minx = null; $miny = null; $maxr = null;
        foreach ($group as $f) {
            $fx = (float)($f['x_mm'] ?? 0);
            $fy = (float)($f['y_mm'] ?? 0);
            $fw = (float)($f['w_mm'] ?? 0);
            $minx = ($minx === null) ? $fx : min($minx, $fx);
            $miny = ($miny === null) ? $fy : min($miny, $fy);
            $maxr = ($maxr === null) ? ($fx + $fw) : max($maxr, $fx + $fw);
        }

        // IDENTITY-BLOCK-POSITION (v6.3.17): the replacement field must take the
        // POSITION of the block it replaces, not be appended to the end of the array.
        // render() paints fields in array order, and a ror_table that overflows adds
        // continuation pages — which leaves the cursor on the last page. Appending the
        // identity table put it AFTER the ror_table, so on any Record of Results long
        // enough to need a second page the student's name, USI and qualification painted
        // onto that second page while page 1 kept a blank gap where the block should be.
        // Page 1 alone was unattributable to anyone. Reported 20 Aug 2026 against
        // CBF-ROR-2026-0002 and CBF-ROR-2026-0007 (both two pages).
        $insertat = min(array_keys($group));
        $newfield = [
            'id'         => 'sdtauto',
            'kind'       => 'dynamic',
            'dynamickey' => 'student.detailstable',
            'x_mm'       => (float)$minx,
            'y_mm'       => (float)$miny,
            'w_mm'       => max(60.0, (float)$maxr - (float)$minx),
            'h_mm'       => 22.0,
            'font'       => 'helvetica',
            'fontsize'   => 12,
            'fontstyle'  => '',
            'align'      => 'L',
            'color'      => '#000000',
        ];

        $out = [];
        foreach ($fields as $i => $f) {
            if ($i === $insertat) {
                $out[] = $newfield;   // identity table lands where the stacked block was
            }
            if (!isset($group[$i])) {
                $out[] = $f;
            }
        }
        $design['fields'] = $out;
        return $design;
    }

    /**
     * Default orientation per cert type — matches the ASQA Fact Sheet
     * sample diagrams (testamur is landscape/ceremonial, statement and
     * record are portrait/document, completion is landscape).
     *
     * @param string $certtype
     * @return string 'P' or 'L'
     */
    public static function default_orientation(string $certtype): string {
        switch ($certtype) {
            case 'testamur':   return 'L';
            case 'statement':  return 'P';
            case 'record':     return 'P';
            case 'completion': return 'L';
        }
        return 'L';
    }

    /**
     * Returns BOTH starter designs (portrait + landscape) for a cert
     * type.  Used by the seed routine and by the "New Template" UI to
     * offer the admin both orientations as a starting point.
     *
     * @param string $certtype
     * @return array ['portrait' => array, 'landscape' => array]
     */
    public static function get_starter_designs(string $certtype): array {
        return [
            'portrait'  => self::build_starter_design($certtype, 'P'),
            'landscape' => self::build_starter_design($certtype, 'L'),
        ];
    }

    /** @return array A blank page design with no fields. */
    private static function blank_page(string $orientation): array {
        $w = ($orientation === 'P') ? 210 : 297;
        $h = ($orientation === 'P') ? 297 : 210;
        return [
            'page' => [
                'format'       => 'A4',
                'orientation'  => $orientation,
                'width_mm'     => $w,
                'height_mm'    => $h,
                'bg_color'     => '#ffffff',
                'bg_image_url' => '',
            ],
            'fields' => [],
        ];
    }

    /**
     * Helper: build a dynamic field with default styling.
     */
    private static function mkf(string &$id, string $kind, array $overrides): array {
        $defaults = [
            'id'          => $id,
            'kind'        => $kind,
            'dynamickey'  => null,
            'x_mm'        => 30.0,
            'y_mm'        => 30.0,
            'w_mm'        => 100.0,
            'h_mm'        => 10.0,
            'font'        => 'helvetica',
            'fontsize'    => 12,
            'fontstyle'   => '',
            'color'       => '#000000',
            'align'       => 'L',
            'text'        => '',
            'dateformat'  => 'd M Y',
            'imageurl'    => '',
            'imageitemid' => 0,
            'linewidth'   => 0.5,
        ];
        $f = array_merge($defaults, $overrides);
        $next = (int) preg_replace('/[^0-9]/', '', $id) + 1;
        $id = 'f' . $next;
        return $f;
    }

    /** A4 Landscape testamur — ceremonial layout. */
    private static function starter_testamur(): array {
        // ASQA-COMPLIANCE-PASS-4 (v4.2.61): paints all four optional text
        // descriptors (industry/occupational/apprenticeship/language) per
        // ASQA fact sheet page 4. They render blank when the matching
        // admin setting is empty, so they're invisible until an RTO sets
        // a value (no clutter for RTOs that don't need them). DATE and
        // AUTHORISED PERSON labels added per fact sheet diagram.
        $design = self::blank_page('L');
        $id = 'f1';
        $fields = [];
        // Branding row.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',  'x_mm' => 15,  'y_mm' => 15, 'w_mm' => 50, 'h_mm' => 25]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'nrt_logo',  'x_mm' => 232, 'y_mm' => 15, 'w_mm' => 50, 'h_mm' => 25]);
        // RTO identity.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',          'x_mm' => 30,  'y_mm' => 44,  'w_mm' => 237, 'h_mm' => 9,  'fontsize' => 16, 'fontstyle' => 'B', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.code',          'x_mm' => 30,  'y_mm' => 53,  'w_mm' => 237, 'h_mm' => 5,  'fontsize' => 9,  'align' => 'C', 'color' => '#666666']);
        // Recipient.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'certify_statement', 'x_mm' => 30,  'y_mm' => 66,  'w_mm' => 237, 'h_mm' => 7,  'fontsize' => 13, 'fontstyle' => 'I', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.fullname',  'x_mm' => 20,  'y_mm' => 76,  'w_mm' => 257, 'h_mm' => 14, 'fontsize' => 28, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'attained_statement','x_mm' => 30,  'y_mm' => 94,  'w_mm' => 237, 'h_mm' => 7,  'fontsize' => 13, 'fontstyle' => 'I', 'align' => 'C']);
        // Qualification block.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.code','x_mm' => 30,  'y_mm' => 103, 'w_mm' => 237, 'h_mm' => 7,  'fontsize' => 14, 'fontstyle' => 'B', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.name','x_mm' => 30,  'y_mm' => 111, 'w_mm' => 237, 'h_mm' => 10, 'fontsize' => 18, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        // v4.2.61 — Optional fact-sheet descriptors. Render blank when admin setting empty.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'industry_descriptor',       'x_mm' => 30, 'y_mm' => 124, 'w_mm' => 237, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'occupational_stream',       'x_mm' => 30, 'y_mm' => 128, 'w_mm' => 237, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'australian_apprenticeship', 'x_mm' => 30, 'y_mm' => 132, 'w_mm' => 237, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'language_statement',        'x_mm' => 30, 'y_mm' => 136, 'w_mm' => 237, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        // AQF statement + AQF logo.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'aqf_statement',     'x_mm' => 30,  'y_mm' => 144, 'w_mm' => 237, 'h_mm' => 8, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'aqf_logo',          'x_mm' => 134, 'y_mm' => 156, 'w_mm' => 30, 'h_mm' => 16]);
        // Signature block bottom-left.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature', 'x_mm' => 25, 'y_mm' => 175, 'w_mm' => 60, 'h_mm' => 14]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 25, 'y_mm' => 189, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        // v4.2.61 — fact sheet labels.
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 25, 'y_mm' => 190, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'color' => '#888888', 'text' => 'AUTHORISED PERSON']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',    'x_mm' => 25, 'y_mm' => 194, 'w_mm' => 70, 'h_mm' => 6, 'fontsize' => 11, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',   'x_mm' => 25, 'y_mm' => 200, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 9, 'color' => '#666666']);
        // Cert metadata bottom-right.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',       'x_mm' => 197, 'y_mm' => 185, 'w_mm' => 80, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 197, 'y_mm' => 190, 'w_mm' => 80, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'align' => 'R', 'color' => '#888888', 'text' => 'DATE']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',    'x_mm' => 197, 'y_mm' => 194, 'w_mm' => 80, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        // CERT-QR-ONLY-DEFAULT (v5.9.406): the verification QR code (auto-added by
        // ensure_mandatory_fields) is the default authenticity measure. The
        // "Verify at: <url>" text line is no longer on the starter — RTOs can drag
        // the "Authenticity measure" field from the palette if they want it too.
        // ASQA-ORG-SEAL (v5.2.55): organisation_seal is required on testamur — place in
        // centre bottom area (x=100-197 is clear of the left signature block and right
        // metadata). Admin can reposition in the drag-and-drop editor.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'organisation_seal',   'x_mm' => 104, 'y_mm' => 174, 'w_mm' => 88, 'h_mm' => 22]);

        $design['fields'] = $fields;
        return $design;
    }

    /** A4 Portrait Statement of Attainment.
     * v4.2.61 ASQA-COMPLIANCE-PASS-4: paints completionofcoursestatement,
     * skill_set_statement and language_statement per fact sheet page 4
     * (optional descriptors). DATE + AUTHORISED PERSON labels added.
     */
    private static function starter_statement(): array {
        $design = self::blank_page('P');
        $id = 'f1';
        $fields = [];
        // NOT-A-TESTAMUR banner (mandatory per ASQA fact sheet page 4).
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'not_a_testamur_statement', 'x_mm' => 15, 'y_mm' => 8, 'w_mm' => 180, 'h_mm' => 12, 'fontsize' => 9, 'fontstyle' => 'B', 'align' => 'C', 'color' => '#7c2d12']);
        // Header.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',                    'x_mm' => 15,  'y_mm' => 24, 'w_mm' => 50, 'h_mm' => 22]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'nrt_logo',                    'x_mm' => 145, 'y_mm' => 24, 'w_mm' => 50, 'h_mm' => 22]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',                    'x_mm' => 15,  'y_mm' => 50, 'w_mm' => 180, 'h_mm' => 8, 'fontsize' => 14, 'fontstyle' => 'B', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.code',                    'x_mm' => 15,  'y_mm' => 58, 'w_mm' => 180, 'h_mm' => 5, 'fontsize' => 9,  'align' => 'C', 'color' => '#666666']);
        // Heading.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'statement_of_attainment_heading', 'x_mm' => 15, 'y_mm' => 70, 'w_mm' => 180, 'h_mm' => 12, 'fontsize' => 24, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        // Recipient — SoA uses "This is a statement that [NAME] has attained" (ASQA p.4).
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'soa_intro_statement',         'x_mm' => 15, 'y_mm' => 86, 'w_mm' => 180, 'h_mm' => 6, 'fontsize' => 11, 'fontstyle' => 'I', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.fullname',            'x_mm' => 15, 'y_mm' => 94, 'w_mm' => 180, 'h_mm' => 12, 'fontsize' => 20, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'soa_attained_statement',      'x_mm' => 15, 'y_mm' => 110, 'w_mm' => 180, 'h_mm' => 6, 'fontsize' => 11, 'fontstyle' => 'I', 'align' => 'C']);
        // Units.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.units',         'x_mm' => 15, 'y_mm' => 120, 'w_mm' => 180, 'h_mm' => 70, 'fontsize' => 10]);
        // SOA-STATEMENT-DEDUPE (v5.9.406): the ASQA-mandated "form part of" line is
        // the SINGLE compliance statement on the default SoA — it auto-builds from the
        // qualification code + name at render time. The near-identical
        // "completion of course" statement (for accredited short courses, not
        // qualifications) was ALSO on the starter, producing two overlapping sets of
        // the same sentence. It has been removed from the default and remains available
        // in the field palette for RTOs issuing SoAs for accredited courses. The box is
        // now 10mm tall so a two-line qualification title wraps cleanly instead of
        // colliding with the descriptors below, and the descriptors are re-spaced.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.partofstatement','x_mm' => 15, 'y_mm' => 191, 'w_mm' => 180, 'h_mm' => 10, 'fontsize' => 10, 'fontstyle' => 'I', 'align' => 'C']);
        // v4.2.61 — Optional descriptors per fact sheet page 4. Render blank when unused.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'skill_set_statement',         'x_mm' => 15, 'y_mm' => 202, 'w_mm' => 180, 'h_mm' => 6, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'language_statement',          'x_mm' => 15, 'y_mm' => 209, 'w_mm' => 180, 'h_mm' => 6, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        // Signature.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature',         'x_mm' => 20, 'y_mm' => 220, 'w_mm' => 60, 'h_mm' => 14]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 20, 'y_mm' => 234, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        // v4.2.61 — fact sheet labels.
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 20, 'y_mm' => 235, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'color' => '#888888', 'text' => 'AUTHORISED PERSON']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',              'x_mm' => 20, 'y_mm' => 239, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 10, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',             'x_mm' => 20, 'y_mm' => 244, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 9, 'color' => '#666666']);
        // Cert metadata right column.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',                 'x_mm' => 110, 'y_mm' => 235, 'w_mm' => 85, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 110, 'y_mm' => 240, 'w_mm' => 85, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'align' => 'R', 'color' => '#888888', 'text' => 'DATE']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',              'x_mm' => 110, 'y_mm' => 244, 'w_mm' => 85, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        // CERT-QR-ONLY-DEFAULT (v5.9.406): QR-only default; verify-URL text removed from starter.
        // ASQA-ORG-SEAL (v5.2.55): organisation_seal required on statement — placed below
        // the footer content (y=257, page is 297mm so plenty of room). Admin can reposition.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'organisation_seal',            'x_mm' => 55, 'y_mm' => 257, 'w_mm' => 100, 'h_mm' => 18]);

        $design['fields'] = $fields;
        return $design;
    }

    /** A4 Portrait Record of Results — ONLY cert type allowed to display USI.
     * v4.2.61 ASQA-COMPLIANCE-PASS-4: paints language_statement per fact
     * sheet page 4 (optional descriptor). DATE + AUTHORISED PERSON labels
     * added.
     */
    private static function starter_record(): array {
        $design = self::blank_page('P');
        $id = 'f1';
        $fields = [];
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',                  'x_mm' => 15,  'y_mm' => 15, 'w_mm' => 50, 'h_mm' => 22]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',                  'x_mm' => 70, 'y_mm' => 18, 'w_mm' => 125, 'h_mm' => 8, 'fontsize' => 14, 'fontstyle' => 'B', 'align' => 'R']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.code',                  'x_mm' => 70, 'y_mm' => 26, 'w_mm' => 125, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'record_of_results_heading', 'x_mm' => 15, 'y_mm' => 45, 'w_mm' => 180, 'h_mm' => 12, 'fontsize' => 22, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        // STUDENT-DETAILS-TABLE (v6.2.51): the student identity block is now one formatted
        // three-column table — STUDENT NAME | USI | QUALIFICATION — replacing the stacked
        // "Name of student: / USI: / Name of qualification:" label rows. The table renders
        // its own shaded header and a single data row (qualification = "CODE TITLE", one
        // space, no hyphen). It satisfies the ASQA student-name + qualification code/name
        // requirements on its own.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.detailstable', 'x_mm' => 15, 'y_mm' => 64, 'w_mm' => 180, 'h_mm' => 22, 'fontsize' => 12]);
        // ROR-RESULTS-TABLE (v6.2.9 / ROR-5COL v6.2.51): the Record of Results uses the single
        // styled units table, which draws its OWN shaded header. col3mode='result' selects the
        // full five-column ASQA layout — Enrolment Date | Unit Code | Unit Title | Result |
        // Completion Date — with a result-code legend (C / NYC / CT / RPL) printed beneath.
        $fields[] = self::mkf($id, 'ror_table', ['x_mm' => 15, 'y_mm' => 92, 'w_mm' => 180, 'h_mm' => 112, 'fontsize' => 10, 'col1_w' => 34, 'col2_w' => 110, 'col3_w' => 36, 'col3mode' => 'result']);
        // v4.2.61 — Optional language statement.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'language_statement',        'x_mm' => 15, 'y_mm' => 220, 'w_mm' => 180, 'h_mm' => 5, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        // Signature + metadata.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature',       'x_mm' => 20, 'y_mm' => 232, 'w_mm' => 60, 'h_mm' => 14]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 20, 'y_mm' => 246, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        // v4.2.61 — fact sheet labels.
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 20, 'y_mm' => 247, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'color' => '#888888', 'text' => 'AUTHORISED PERSON']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',            'x_mm' => 20, 'y_mm' => 251, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 10, 'fontstyle' => 'B']);
        // ASQA-RECORD-COMPLIANCE (v5.2.54): signatoryTitle added — ASQA p.3 shows AUTHORISED PERSON.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',           'x_mm' => 20, 'y_mm' => 256, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 9, 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',               'x_mm' => 110, 'y_mm' => 247, 'w_mm' => 85, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        // ASQA-AUDIT-DATE (v4.4.11): "DATE" label was 7pt italic grey — illegible.
        // Changed to "Date of issue:" 8pt regular #444444. Date value bumped to
        // 10pt bold #222222 for clear visibility per ASQA fact sheet.
        // Authenticity measure widened to full page width and centred so the
        // full verify URL is never truncated (was 85mm right-aligned — most URLs
        // were cut off; ASQA requires the measure to be legible and usable).
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 110, 'y_mm' => 252, 'w_mm' => 85, 'h_mm' => 4, 'fontsize' => 8, 'align' => 'R', 'color' => '#444444', 'text' => 'Date of issue:']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',            'x_mm' => 110, 'y_mm' => 256, 'w_mm' => 85, 'h_mm' => 6, 'fontsize' => 10, 'fontstyle' => 'B', 'align' => 'R', 'color' => '#222222']);
        // CERT-QR-ONLY-DEFAULT (v5.9.406): QR-only default; verify-URL text removed from starter.

        $design['fields'] = $fields;
        return $design;
    }

    /**
     * v4.2.58 — A4 PORTRAIT testamur (alternative orientation).
     * Same ASQA-mandated elements as the landscape testamur, packed
     * vertically: branding row at top, recipient block centred, qual
     * code+name centred below, AQF statement, signatory block bottom-
     * left, cert metadata bottom-right.
     */
    private static function starter_testamur_portrait(): array {
        // ASQA-COMPLIANCE-PASS-4 (v4.2.61): paints all four optional text
        // descriptors (industry/occupational/apprenticeship/language) per
        // ASQA fact sheet page 4. Render blank when admin setting empty.
        // DATE + AUTHORISED PERSON labels added per fact sheet diagram.
        $design = self::blank_page('P');
        $id = 'f1';
        $fields = [];
        // Branding row.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',           'x_mm' => 15,  'y_mm' => 15, 'w_mm' => 50, 'h_mm' => 22]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'nrt_logo',           'x_mm' => 145, 'y_mm' => 15, 'w_mm' => 50, 'h_mm' => 22]);
        // RTO name + code.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',           'x_mm' => 15, 'y_mm' => 45,  'w_mm' => 180, 'h_mm' => 9, 'fontsize' => 16, 'fontstyle' => 'B', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.code',           'x_mm' => 15, 'y_mm' => 54,  'w_mm' => 180, 'h_mm' => 5, 'fontsize' => 10, 'align' => 'C', 'color' => '#666666']);
        // Centre block.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'certify_statement',  'x_mm' => 15, 'y_mm' => 78,  'w_mm' => 180, 'h_mm' => 8, 'fontsize' => 13, 'fontstyle' => 'I', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.fullname',   'x_mm' => 15, 'y_mm' => 90,  'w_mm' => 180, 'h_mm' => 14, 'fontsize' => 26, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'attained_statement', 'x_mm' => 15, 'y_mm' => 110, 'w_mm' => 180, 'h_mm' => 8, 'fontsize' => 13, 'fontstyle' => 'I', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.code', 'x_mm' => 15, 'y_mm' => 122, 'w_mm' => 180, 'h_mm' => 7, 'fontsize' => 14, 'fontstyle' => 'B', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.name', 'x_mm' => 15, 'y_mm' => 130, 'w_mm' => 180, 'h_mm' => 12, 'fontsize' => 18, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        // v4.2.61 — Optional fact-sheet descriptors. Render blank when empty.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'industry_descriptor',       'x_mm' => 15, 'y_mm' => 145, 'w_mm' => 180, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'occupational_stream',       'x_mm' => 15, 'y_mm' => 149, 'w_mm' => 180, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'australian_apprenticeship', 'x_mm' => 15, 'y_mm' => 153, 'w_mm' => 180, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'language_statement',        'x_mm' => 15, 'y_mm' => 157, 'w_mm' => 180, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        // AQF statement + AQF logo.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'aqf_statement',      'x_mm' => 15, 'y_mm' => 165, 'w_mm' => 180, 'h_mm' => 10, 'fontsize' => 10, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        // ASQA-ORG-SEAL (v5.2.55): organisation_seal required on testamur — placed in
        // the 20mm gap between aqf_statement (ends y=175) and aqf_logo (starts y=195).
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'organisation_seal',  'x_mm' => 55, 'y_mm' => 176, 'w_mm' => 100, 'h_mm' => 17]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'aqf_logo',           'x_mm' => 90, 'y_mm' => 195, 'w_mm' => 30, 'h_mm' => 16]);
        // Signature bottom-left.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature', 'x_mm' => 20, 'y_mm' => 220, 'w_mm' => 60, 'h_mm' => 14]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 20, 'y_mm' => 234, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        // v4.2.61 — fact sheet labels.
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 20, 'y_mm' => 235, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'color' => '#888888', 'text' => 'AUTHORISED PERSON']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',     'x_mm' => 20, 'y_mm' => 239, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 10, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',    'x_mm' => 20, 'y_mm' => 244, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 9, 'color' => '#666666']);
        // Cert metadata bottom-right.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',        'x_mm' => 110, 'y_mm' => 235, 'w_mm' => 85, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 110, 'y_mm' => 240, 'w_mm' => 85, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'align' => 'R', 'color' => '#888888', 'text' => 'DATE']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',     'x_mm' => 110, 'y_mm' => 244, 'w_mm' => 85, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        // CERT-QR-ONLY-DEFAULT (v5.9.406): QR-only default; verify-URL text removed from starter.

        $design['fields'] = $fields;
        return $design;
    }

    /**
     * v4.2.58 — A4 LANDSCAPE Statement of Attainment (alternative orientation).
     * Same elements as the portrait SOA, with a wider units-of-competency
     * column and a side-by-side recipient/units layout.
     */
    private static function starter_statement_landscape(): array {
        // ASQA-COMPLIANCE-PASS-4 (v4.2.61): paints completionofcoursestatement,
        // skill_set_statement and language_statement per fact sheet page 4
        // (optional descriptors). DATE + AUTHORISED PERSON labels added.
        $design = self::blank_page('L');
        $id = 'f1';
        $fields = [];
        // NOT-A-TESTAMUR banner.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'not_a_testamur_statement',       'x_mm' => 15,  'y_mm' => 6, 'w_mm' => 267, 'h_mm' => 10, 'fontsize' => 9, 'fontstyle' => 'B', 'align' => 'C', 'color' => '#7c2d12']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',                       'x_mm' => 15,  'y_mm' => 18, 'w_mm' => 45, 'h_mm' => 20]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'nrt_logo',                       'x_mm' => 237, 'y_mm' => 18, 'w_mm' => 45, 'h_mm' => 20]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',                       'x_mm' => 15,  'y_mm' => 41, 'w_mm' => 267, 'h_mm' => 7, 'fontsize' => 14, 'fontstyle' => 'B', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.code',                       'x_mm' => 15,  'y_mm' => 48, 'w_mm' => 267, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'C', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'statement_of_attainment_heading','x_mm' => 15,  'y_mm' => 58, 'w_mm' => 267, 'h_mm' => 11, 'fontsize' => 22, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        // Recipient — SoA uses "This is a statement that [NAME] has attained" (ASQA p.4).
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'soa_intro_statement',            'x_mm' => 15,  'y_mm' => 74, 'w_mm' => 267, 'h_mm' => 6, 'fontsize' => 11, 'fontstyle' => 'I', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.fullname',               'x_mm' => 15,  'y_mm' => 82, 'w_mm' => 267, 'h_mm' => 12, 'fontsize' => 22, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'soa_attained_statement',         'x_mm' => 15,  'y_mm' => 98, 'w_mm' => 267, 'h_mm' => 6, 'fontsize' => 11, 'fontstyle' => 'I', 'align' => 'C']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.units',            'x_mm' => 30,  'y_mm' => 108, 'w_mm' => 237, 'h_mm' => 48, 'fontsize' => 10]);
        // SOA-STATEMENT-DEDUPE (v5.9.406): single ASQA "form part of" statement,
        // auto-built from qual code + name; box widened to 8mm for two-line wrap.
        // Redundant "completion of course" statement removed from the default
        // (still available in the palette). Descriptors re-spaced below it.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.partofstatement',  'x_mm' => 30,  'y_mm' => 158, 'w_mm' => 237, 'h_mm' => 8, 'fontsize' => 10, 'fontstyle' => 'I', 'align' => 'C']);
        // v4.2.61 — Optional descriptors per fact sheet page 4. Render blank when unused.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'skill_set_statement',         'x_mm' => 30, 'y_mm' => 167, 'w_mm' => 237, 'h_mm' => 5, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'language_statement',          'x_mm' => 30, 'y_mm' => 172, 'w_mm' => 237, 'h_mm' => 5, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        // ASQA-ORG-SEAL (v5.2.55): organisation_seal required on statement — placed in the
        // clear centre band (x=95–197, y=180–207) between the left signature block and the
        // right cert-metadata column. Admin can reposition in the drag-and-drop editor.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'organisation_seal',           'x_mm' => 95, 'y_mm' => 180, 'w_mm' => 102, 'h_mm' => 25]);
        // Signature.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature', 'x_mm' => 20, 'y_mm' => 180, 'w_mm' => 60, 'h_mm' => 12]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 20, 'y_mm' => 192, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        // v4.2.61 — fact sheet labels.
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 20, 'y_mm' => 193, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'color' => '#888888', 'text' => 'AUTHORISED PERSON']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',     'x_mm' => 20, 'y_mm' => 197, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 10, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',    'x_mm' => 20, 'y_mm' => 202, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 9, 'color' => '#666666']);
        // SOA-LANDSCAPE-OVERFLOW (v5.2.57): right column was y=193→211mm on a 210mm page.
        // cert.number h reduced 5→4, cert.issuedate h reduced 5→4 and shifted up 1mm;
        // authenticity_measure shifted up 2mm. Bottom now 205+4=209mm. All within bounds.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',        'x_mm' => 200, 'y_mm' => 193, 'w_mm' => 82, 'h_mm' => 4, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 200, 'y_mm' => 197, 'w_mm' => 82, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'align' => 'R', 'color' => '#888888', 'text' => 'DATE']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',     'x_mm' => 200, 'y_mm' => 201, 'w_mm' => 82, 'h_mm' => 4, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        // CERT-QR-ONLY-DEFAULT (v5.9.406): QR-only default; verify-URL text removed from starter.

        $design['fields'] = $fields;
        return $design;
    }

    /**
     * v4.2.58 — A4 LANDSCAPE Record of Results (alternative orientation).
     * Wider units-of-competency table; otherwise same elements as the
     * portrait Record of Results (USI included — only cert type allowed
     * to display USI).
     */
    private static function starter_record_landscape(): array {
        $design = self::blank_page('L');
        $id = 'f1';
        $fields = [];
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',                  'x_mm' => 15, 'y_mm' => 12, 'w_mm' => 45, 'h_mm' => 20]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',                  'x_mm' => 65, 'y_mm' => 15, 'w_mm' => 217, 'h_mm' => 8, 'fontsize' => 14, 'fontstyle' => 'B', 'align' => 'R']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.code',                  'x_mm' => 65, 'y_mm' => 23, 'w_mm' => 217, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'record_of_results_heading', 'x_mm' => 15, 'y_mm' => 38, 'w_mm' => 267, 'h_mm' => 11, 'fontsize' => 22, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        // ASQA-COMPLIANCE-PASS-2 (v4.2.59) — fact sheet labels + qualification line.
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 15, 'y_mm' => 55, 'w_mm' => 45, 'h_mm' => 6, 'fontsize' => 10, 'fontstyle' => 'B', 'text' => 'Name of student:']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.fullname',          'x_mm' => 60, 'y_mm' => 55, 'w_mm' => 222, 'h_mm' => 6, 'fontsize' => 12, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 15, 'y_mm' => 63, 'w_mm' => 45, 'h_mm' => 6, 'fontsize' => 10, 'fontstyle' => 'B', 'text' => 'USI:']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.usi',               'x_mm' => 60, 'y_mm' => 63, 'w_mm' => 222, 'h_mm' => 6, 'fontsize' => 11, 'font' => 'courier']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 15, 'y_mm' => 71, 'w_mm' => 45, 'h_mm' => 6, 'fontsize' => 10, 'fontstyle' => 'B', 'text' => 'Name of qualification:']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.code',        'x_mm' => 60, 'y_mm' => 71, 'w_mm' => 25, 'h_mm' => 6, 'fontsize' => 11, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'qualification.name',        'x_mm' => 86, 'y_mm' => 71, 'w_mm' => 196, 'h_mm' => 6, 'fontsize' => 11]);
        // ROR-RESULTS-TABLE (v6.2.9): styled units table with its own shaded header
        // (Unit Code | Unit Title | Results). col3mode='result' shows the assessment outcome
        // (Competent / Not Yet Competent) per the ASQA Record of Results sample. The duplicate
        // text-field header row is removed — the table draws its own header.
        $fields[] = self::mkf($id, 'ror_table', ['x_mm' => 15, 'y_mm' => 82, 'w_mm' => 267, 'h_mm' => 86, 'fontsize' => 10, 'col1_w' => 40, 'col2_w' => 175, 'col3_w' => 48, 'col3mode' => 'result']);
        // v4.2.61 — Optional language statement.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'language_statement',        'x_mm' => 15, 'y_mm' => 171, 'w_mm' => 267, 'h_mm' => 4, 'fontsize' => 9, 'fontstyle' => 'I', 'align' => 'C', 'color' => '#444444']);
        // ASQA-AUDIT-DATE (v4.4.11): landscape footer was printing off the page.
        // A4 landscape = 210mm tall; old layout had cert.issuedate at y=202+5=207
        // and authenticity_measure at y=207+4=211 — both clipped by the bottom
        // margin (usable area ends ~y=200). Fix: sig moved to y=175 (h=10),
        // right-side cert.number/date alongside the sig area, authenticity_measure
        // moved to y=195 spanning the full 267mm width so the verify URL always
        // fits. "DATE" label → "Date of issue:" 8pt #444444. Date → 10pt bold.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature',       'x_mm' => 20, 'y_mm' => 175, 'w_mm' => 60, 'h_mm' => 10]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 20, 'y_mm' => 185, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        // v4.2.61 — fact sheet labels.
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 20, 'y_mm' => 186, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 7, 'fontstyle' => 'I', 'color' => '#888888', 'text' => 'AUTHORISED PERSON']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',            'x_mm' => 20, 'y_mm' => 190, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 10, 'fontstyle' => 'B']);
        // ASQA-RECORD-COMPLIANCE (v5.2.54): signatoryTitle added — ASQA p.3 shows AUTHORISED PERSON.
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',           'x_mm' => 20, 'y_mm' => 194, 'w_mm' => 70, 'h_mm' => 4, 'fontsize' => 9, 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',               'x_mm' => 200, 'y_mm' => 175, 'w_mm' => 82, 'h_mm' => 4, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 200, 'y_mm' => 179, 'w_mm' => 82, 'h_mm' => 4, 'fontsize' => 8, 'align' => 'R', 'color' => '#444444', 'text' => 'Date of issue:']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',            'x_mm' => 200, 'y_mm' => 183, 'w_mm' => 82, 'h_mm' => 5, 'fontsize' => 10, 'fontstyle' => 'B', 'align' => 'R', 'color' => '#222222']);
        // CERT-QR-ONLY-DEFAULT (v5.9.406): QR-only default; verify-URL text removed from starter.

        $design['fields'] = $fields;
        return $design;
    }

    /**
     * v4.2.58 — A4 PORTRAIT Certificate of Completion (alternative orientation).
     * Non-accredited training — no NRT/AQF/USI. Just student name +
     * course/activity name + completion date + RTO + signature.
     */
    private static function starter_completion_portrait(): array {
        $design = self::blank_page('P');
        $id = 'f1';
        $fields = [];
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',         'x_mm' => 80, 'y_mm' => 15, 'w_mm' => 50, 'h_mm' => 25]);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 15, 'y_mm' => 55, 'w_mm' => 180, 'h_mm' => 12, 'fontsize' => 24, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times', 'text' => 'Certificate of Completion']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 15, 'y_mm' => 80, 'w_mm' => 180, 'h_mm' => 8, 'fontsize' => 13, 'fontstyle' => 'I', 'align' => 'C', 'text' => 'This certificate is awarded to']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.fullname', 'x_mm' => 15, 'y_mm' => 92, 'w_mm' => 180, 'h_mm' => 14, 'fontsize' => 26, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 15, 'y_mm' => 115, 'w_mm' => 180, 'h_mm' => 8, 'fontsize' => 13, 'fontstyle' => 'I', 'align' => 'C', 'text' => 'in recognition of completion of']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.coursetitle', 'x_mm' => 15, 'y_mm' => 127, 'w_mm' => 180, 'h_mm' => 14, 'fontsize' => 18, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',         'x_mm' => 15, 'y_mm' => 150, 'w_mm' => 180, 'h_mm' => 8, 'fontsize' => 12, 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature', 'x_mm' => 20, 'y_mm' => 220, 'w_mm' => 60, 'h_mm' => 18]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 20, 'y_mm' => 240, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',   'x_mm' => 20, 'y_mm' => 242, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 10, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',  'x_mm' => 20, 'y_mm' => 247, 'w_mm' => 70, 'h_mm' => 5, 'fontsize' => 9, 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',      'x_mm' => 110, 'y_mm' => 242, 'w_mm' => 85, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',   'x_mm' => 110, 'y_mm' => 247, 'w_mm' => 85, 'h_mm' => 5, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        // ASQA-COMPLETION-AUDIT (v5.2.56): authenticity_measure added — completion validator
        // has it in recommended_fields and the REQUIREMENT_TO_DYNAMICKEY maps it to a
        // canvas check, so omitting it triggers a warning on every new completion template.
        // CERT-QR-ONLY-DEFAULT (v5.9.406): QR-only default; verify-URL text removed from starter.

        $design['fields'] = $fields;
        return $design;
    }

    /** A4 Landscape Certificate of Completion (non-accredited) — no NRT/AQF/USI. */
    private static function starter_completion(): array {
        $design = self::blank_page('L');
        $id = 'f1';
        $fields = [];
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.logo',         'x_mm' => 124, 'y_mm' => 15, 'w_mm' => 50, 'h_mm' => 25]);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 30, 'y_mm' => 50, 'w_mm' => 237, 'h_mm' => 12, 'fontsize' => 26, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times', 'text' => 'Certificate of Completion']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 30, 'y_mm' => 75, 'w_mm' => 237, 'h_mm' => 8, 'fontsize' => 14, 'fontstyle' => 'I', 'align' => 'C', 'text' => 'This certificate is awarded to']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'student.fullname', 'x_mm' => 20, 'y_mm' => 88, 'w_mm' => 257, 'h_mm' => 16, 'fontsize' => 32, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'text',    ['x_mm' => 30, 'y_mm' => 110, 'w_mm' => 237, 'h_mm' => 8, 'fontsize' => 14, 'fontstyle' => 'I', 'align' => 'C', 'text' => 'in recognition of completion of']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.coursetitle', 'x_mm' => 30, 'y_mm' => 122, 'w_mm' => 237, 'h_mm' => 12, 'fontsize' => 22, 'fontstyle' => 'B', 'align' => 'C', 'font' => 'times']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'rto.name',         'x_mm' => 30, 'y_mm' => 142, 'w_mm' => 237, 'h_mm' => 8, 'fontsize' => 12, 'align' => 'C', 'color' => '#444444']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.signature', 'x_mm' => 25, 'y_mm' => 160, 'w_mm' => 60, 'h_mm' => 18]);
        $fields[] = self::mkf($id, 'line',    ['x_mm' => 25, 'y_mm' => 180, 'w_mm' => 70, 'h_mm' => 0, 'linewidth' => 0.4]);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.name',   'x_mm' => 25, 'y_mm' => 182, 'w_mm' => 70, 'h_mm' => 6, 'fontsize' => 11, 'fontstyle' => 'B']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'signatory.title',  'x_mm' => 25, 'y_mm' => 188, 'w_mm' => 70, 'h_mm' => 6, 'fontsize' => 9, 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.number',      'x_mm' => 197, 'y_mm' => 182, 'w_mm' => 80, 'h_mm' => 6, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        $fields[] = self::mkf($id, 'dynamic', ['dynamickey' => 'cert.issuedate',   'x_mm' => 197, 'y_mm' => 188, 'w_mm' => 80, 'h_mm' => 6, 'fontsize' => 9, 'align' => 'R', 'color' => '#666666']);
        // ASQA-COMPLETION-AUDIT (v5.2.56): authenticity_measure added — same reason as
        // portrait. y=196 ends at 201mm, safely within the 210mm landscape page.
        // CERT-QR-ONLY-DEFAULT (v5.9.406): QR-only default; verify-URL text removed from starter.

        $design['fields'] = $fields;
        return $design;
    }

    /**
     * CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — RTO branding helpers.
     *
     * Returns the served URL of the system-wide RTO logo (or null if
     * never uploaded). The logo lives in the FA_BRANDING filearea on the
     * system context with pseudo-itemid BRANDING_ITEMID_LOGO.
     */
    public static function get_branding_logo_url(): ?string {
        return self::get_branding_display_url(self::BRANDING_ITEMID_LOGO, 'logo', '');
    }

    /** Returns served URL of the system-wide CEO signature image, or null. */
    public static function get_branding_signature_url(): ?string {
        return self::get_branding_display_url(self::BRANDING_ITEMID_SIGNATURE, 'ceo_signature_file', '');
    }

    /**
     * ASQA-COMPLIANCE-PASS-3 (v4.2.60) — Returns served URL of the system-
     * wide State / Territory Training Authority logo (uploaded by the admin
     * via the cert template branding panel), or null if none uploaded.
     */
    public static function get_branding_sta_logo_url(): ?string {
        return self::get_branding_display_url(self::BRANDING_ITEMID_STA_LOGO, 'compliance_logo_1', '');
    }

    /** v4.4.0 — Returns served URL of the admin-uploaded NRT logo, or null. */
    public static function get_branding_nrt_logo_url(): ?string {
        return self::get_branding_display_url(self::BRANDING_ITEMID_NRT_LOGO, 'nrt_logo_file', 'nrt_logo.png');
    }

    /** v4.4.0 — Returns served URL of the admin-uploaded AQF logo, or null. */
    public static function get_branding_aqf_logo_url(): ?string {
        return self::get_branding_display_url(self::BRANDING_ITEMID_AQF_LOGO, 'aqf_logo_file', 'aqf_logo.jpg');
    }

    /** v4.4.0 — Returns served URL of the admin-uploaded organisation seal, or null. */
    public static function get_branding_org_seal_url(): ?string {
        return self::get_branding_display_url(self::BRANDING_ITEMID_ORG_SEAL, 'organisation_seal_file', '');
    }

    /**
     * v4.4.0 — Resolve a compliance asset filesystem path for the renderer.
     * Prefers admin-uploaded artwork (system-context FA_BRANDING by
     * pseudo-itemid), then falls back to admin_setting_configstoredfile
     * uploads (component='local_rtocompliance', given filearea), then to
     * the bundled file at pix/$bundledname (or '' if no bundled fallback).
     *
     * @param int    $itemid       BRANDING_ITEMID_* pseudo-itemid
     * @param string $settingfilearea  filearea name of the matching admin_setting_configstoredfile (or '' to skip)
     * @param string $bundledname  filename inside pix/ to fall back to (or '' for none)
     * @return string  fs path or '' if nothing resolvable
     */
    public static function resolve_compliance_asset_path(int $itemid, string $settingfilearea, string $bundledname): string {
        global $CFG;
        // 1. FA_BRANDING upload by pseudo-itemid (preferred — matches existing pattern).
        $path = self::get_branding_path($itemid);
        if (!empty($path)) {
            return $path;
        }
        // 2. admin_setting_configstoredfile upload (system context, plugin component, named filearea, itemid 0).
        if (!empty($settingfilearea)) {
            $fs = get_file_storage();
            $context = \context_system::instance();
            $files = $fs->get_area_files($context->id, 'local_rtocompliance', $settingfilearea,
                0, 'sortorder, filename', false);
            foreach ($files as $f) {
                if ($f->is_directory()) {
                    continue;
                }
                $tmp = make_request_directory() . '/' . $f->get_filename();
                $f->copy_content_to($tmp);
                return $tmp;
            }
        }
        // 3. Bundled fallback in pix/.
        if (!empty($bundledname)) {
            $candidate = $CFG->dirroot . '/local/rtocompliance/pix/' . $bundledname;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /** Returns served URL of a branding file by pseudo-itemid, or null. */
    public static function get_branding_url(int $itemid): ?string {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_rtocompliance', self::FA_BRANDING,
            $itemid, 'sortorder, filename', false);
        foreach ($files as $f) {
            if ($f->is_directory()) {
                continue;
            }
            return \moodle_url::make_pluginfile_url(
                $f->get_contextid(), $f->get_component(), $f->get_filearea(),
                $f->get_itemid(), $f->get_filepath(), $f->get_filename()
            )->out(false);
        }
        return null;
    }

    /**
     * FIX-EDITOR-BRANDING-CHAIN (v5.9.406) — Returns the served URL of a
     * branding asset using the SAME resolution chain as the TCPDF renderer's
     * resolve_compliance_asset_path(): (1) FA_BRANDING pseudo-itemid upload,
     * then (2) the admin_setting_configstoredfile upload (component
     * 'local_rtocompliance', named $settingfilearea, itemid 0 — this is where
     * RTO Settings / Certificate Settings store logo/NRT logo/signature/seal),
     * then (3) the bundled pix/ fallback. The editor canvas was previously only
     * checking (1), so assets uploaded via the settings pages rendered on the
     * issued PDF but showed as "[RTO logo]" text placeholders in the editor.
     *
     * @param int    $itemid          BRANDING_ITEMID_* pseudo-itemid
     * @param string $settingfilearea filearea of the matching admin_setting_configstoredfile ('' to skip)
     * @param string $bundledname     filename inside pix/ to fall back to ('' for none)
     * @return string|null served URL or null if nothing resolvable
     */
    public static function get_branding_display_url(int $itemid, string $settingfilearea = '', string $bundledname = ''): ?string {
        global $CFG;
        // 1. FA_BRANDING pseudo-itemid upload (cert template branding panel).
        $url = self::get_branding_url($itemid);
        if (!empty($url)) {
            return $url;
        }
        // 2. admin_setting_configstoredfile upload (RTO/Certificate Settings pages).
        if (!empty($settingfilearea)) {
            $fs = get_file_storage();
            $context = \context_system::instance();
            $files = $fs->get_area_files($context->id, 'local_rtocompliance', $settingfilearea,
                0, 'sortorder, filename', false);
            foreach ($files as $f) {
                if ($f->is_directory()) {
                    continue;
                }
                return \moodle_url::make_pluginfile_url(
                    $f->get_contextid(), $f->get_component(), $f->get_filearea(),
                    $f->get_itemid(), $f->get_filepath(), $f->get_filename()
                )->out(false);
            }
        }
        // 3. Bundled fallback in pix/.
        if (!empty($bundledname)) {
            $candidate = $CFG->dirroot . '/local/rtocompliance/pix/' . $bundledname;
            if (file_exists($candidate)) {
                return (new \moodle_url('/local/rtocompliance/pix/' . $bundledname))->out(false);
            }
        }
        return null;
    }

    /** Returns server filesystem path of a branding file (for TCPDF), or null. */
    public static function get_branding_path(int $itemid): ?string {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_rtocompliance', self::FA_BRANDING,
            $itemid, 'sortorder, filename', false);
        foreach ($files as $f) {
            if ($f->is_directory()) {
                continue;
            }
            $tmp = make_request_directory() . '/' . $f->get_filename();
            $f->copy_content_to($tmp);
            return $tmp;
        }
        return null;
    }

    /**
     * Replace the branding file at the given pseudo-itemid with the
     * uploaded $_FILES entry. Returns true on success.
     */
    public static function save_branding_file(int $itemid, array $upload): bool {
        if (empty($upload['tmp_name']) || !empty($upload['error'])) {
            return false;
        }
        $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
        if (!in_array($upload['type'], $allowed, true)) {
            return false;
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, 'local_rtocompliance', self::FA_BRANDING, $itemid);
        $filerecord = (object) [
            'contextid' => $context->id,
            'component' => 'local_rtocompliance',
            'filearea'  => self::FA_BRANDING,
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => clean_filename($upload['name']),
            'mimetype'  => $upload['type'],
        ];
        try {
            $fs->create_file_from_pathname($filerecord, $upload['tmp_name']);
            return true;
        } catch (\Throwable $e) {
            debugging('Branding upload failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /** Delete a branding file by pseudo-itemid. */
    public static function delete_branding_file(int $itemid): void {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, 'local_rtocompliance', self::FA_BRANDING, $itemid);
    }

    /**
     * Create a new draft template.
     *
     * v5.9.327 CERT-CREATE-ORIENTATION: $orientation param added so the
     * create form can pre-select Portrait or Landscape at creation time.
     * Previously create() always used the cert-type default orientation
     * (testamur→L, statement→P, record→P, completion→L) regardless of
     * what the admin wanted.  Admins had to open the edit designer and
     * manually change the Page orientation — confusing UX, especially for
     * RTOs who prefer portrait testamurs.
     *
     * @param string      $certtype
     * @param string      $name
     * @param string      $audience
     * @param string|null $audiencelabel
     * @param string|null $orientation   'P' = portrait, 'L' = landscape, null = type default
     * @return int new template id
     */
    public static function create(string $certtype, string $name,
            string $audience = 'default', ?string $audiencelabel = null,
            ?string $orientation = null): int {
        global $DB, $USER;

        if (!in_array($certtype, self::CERT_TYPES, true)) {
            throw new \invalid_parameter_exception('Unknown certtype: ' . $certtype);
        }
        // v4.3.0 — coerce audience to a known value; unknown codes fall
        // back to 'default' so a stale/typo'd POST never breaks creation.
        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = 'default';
        }
        $audiencelabel = ($audiencelabel !== null && trim($audiencelabel) !== '')
            ? trim($audiencelabel) : null;

        // Coerce orientation — only 'P' and 'L' are valid; anything else uses the
        // cert-type default so a malformed POST never breaks creation.
        if ($orientation !== 'P' && $orientation !== 'L') {
            $orientation = null; // build_starter_design picks type default
        }

        $now = time();
        $record = (object) [
            'certtype'      => $certtype,
            'audience'      => $audience,
            'audiencelabel' => $audiencelabel,
            'name'          => $name,
            'status'        => 'draft',
            'isactive'      => 0,
            'designjson'    => json_encode(self::build_starter_design($certtype, $orientation)),
            'bgitemid'      => null,
            'lastvalidation' => null,
            'createdby'     => $USER->id,
            'approvedby'    => null,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'timeapproved'  => null,
        ];

        return (int) $DB->insert_record('local_rtocompliance_certtmpl', $record);
    }

    /**
     * v4.3.0 CERT-TEMPLATE-AUDIENCES — change a template's audience and/or
     * label. Called from cert_template_edit.php's POST handler so admins
     * can re-target a template after creation. Does NOT touch isactive —
     * if the new (certtype+audience) pair already has an active template
     * the admin must explicitly re-activate via the list page so the
     * swap is visible and audited.
     *
     * @param int         $id            template id
     * @param string      $audience      one of self::AUDIENCES (defaults coerce)
     * @param string|null $audiencelabel optional admin-facing label
     * @return void
     */
    public static function set_audience(int $id, string $audience,
            ?string $audiencelabel = null): void {
        global $DB;
        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = 'default';
        }
        $label = ($audiencelabel !== null && trim($audiencelabel) !== '')
            ? trim($audiencelabel) : null;
        $DB->update_record('local_rtocompliance_certtmpl', (object) [
            'id'            => $id,
            'audience'      => $audience,
            'audiencelabel' => $label,
            'timemodified'  => time(),
        ]);
    }

    /**
     * v4.3.0 CERT-TEMPLATE-AUDIENCES — pick the active approved template
     * for a (certtype + audience) pair. Returns null if no active
     * template exists for that combination.
     *
     * @param string $certtype
     * @param string $audience
     * @return \stdClass|null
     */
    public static function pick_for_audience(string $certtype, string $audience): ?\stdClass {
        global $DB;
        // v5.9.365: get_records so >1 active row can't fatal the render dispatcher.
        $rs = $DB->get_records('local_rtocompliance_certtmpl', [
            'certtype' => $certtype,
            'audience' => $audience,
            'status'   => 'approved',
            'isactive' => 1,
        ], 'timemodified DESC, id DESC', '*', 0, 1);
        return $rs ? reset($rs) : null;
    }

    /**
     * v4.3.0 CERT-TEMPLATE-AUDIENCES — runtime template picker used by
     * the lib.php render dispatcher. Selection precedence:
     *
     *   1. cert->certtmplid (explicit pin recorded at issue time, OR a
     *      reissue of an older cert) — guarantees stable design across
     *      reissues even if active templates have since changed.
     *   2. (certtype + cert->audience) if the cert carries an audience
     *      hint set by the issuer at issue time. (Audience may be
     *      attached as a transient property by issue_certificate.php
     *      before this call, since the certs table itself does NOT
     *      store audience — only the chosen certtmplid is persisted.)
     *   3. (certtype + 'default') — the back-compat audience pre-v4.3.0.
     *   4. Any active approved template for the certtype (covers sites
     *      whose only template happens to be flagged with a non-default
     *      audience but is still the only choice for that certtype).
     *
     * Returns null if nothing matches — the caller (lib.php) then falls
     * through to the legacy ASQA-compliant TCPDF generator.
     *
     * @param \stdClass $cert
     * @return \stdClass|null
     */
    public static function pick_for_cert(\stdClass $cert): ?\stdClass {
        global $DB;

        if (!empty($cert->certtmplid)) {
            // F2 (v5.9.389): only render the pinned template if it is STILL APPROVED.
            // save_design() rewrites designjson in place and flips the row to
            // draft/inactive, so without this filter a mid-edit, unvalidated draft
            // would leak onto an already-issued certificate on re-download. If the
            // pinned template is no longer approved, fall through to the current
            // approved template for this certtype/audience.
            $r = $DB->get_record('local_rtocompliance_certtmpl',
                ['id' => (int) $cert->certtmplid, 'status' => 'approved']);
            if ($r) {
                return $r;
            }
        }

        if (!empty($cert->audience) && !empty($cert->certtype)) {
            $picked = self::pick_for_audience((string) $cert->certtype, (string) $cert->audience);
            if ($picked) {
                return $picked;
            }
        }

        if (!empty($cert->certtype)) {
            $picked = self::pick_for_audience((string) $cert->certtype, 'default');
            if ($picked) {
                return $picked;
            }
            return self::get_active_template((string) $cert->certtype);
        }

        return null;
    }

    /**
     * Get a single template record by id.
     *
     * @param int $id
     * @return \stdClass|false
     */
    public static function get(int $id) {
        global $DB;
        return $DB->get_record('local_rtocompliance_certtmpl', ['id' => $id]);
    }

    /**
     * Decode design_json safely.  Returns the canonical empty design for
     * the certtype if the field is null/empty/invalid.
     *
     * @param \stdClass $template
     * @return array
     */
    public static function decode_design(\stdClass $template): array {
        if (empty($template->designjson)) {
            return self::get_default_design($template->certtype);
        }
        $design = json_decode($template->designjson, true);
        if (!is_array($design)) {
            return self::get_default_design($template->certtype);
        }
        // Defensive defaults — older saves may be missing keys.
        if (!isset($design['page'])) {
            $design['page'] = self::get_default_design($template->certtype)['page'];
        }
        if (!isset($design['fields']) || !is_array($design['fields'])) {
            $design['fields'] = [];
        }
        return $design;
    }

    /**
     * List all templates, newest-first, optionally filtered by certtype
     * and/or status.
     *
     * @param string|null $certtype
     * @param string|null $status
     * @return array
     */
    public static function list_all(?string $certtype = null, ?string $status = null): array {
        global $DB;
        $where = [];
        $params = [];
        if ($certtype !== null) {
            $where[] = 'certtype = :certtype';
            $params['certtype'] = $certtype;
        }
        if ($status !== null) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $sql = 'SELECT * FROM {local_rtocompliance_certtmpl}';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY certtype ASC, isactive DESC, timemodified DESC';
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Get the active approved template for a certtype, or null.
     * Used by lib.php at render time.
     *
     * @param string $certtype
     * @return \stdClass|null
     */
    public static function get_active_template(string $certtype): ?\stdClass {
        global $DB;
        // v5.9.365: get_records so >1 active row can't fatal callers.
        $rs = $DB->get_records('local_rtocompliance_certtmpl', [
            'certtype' => $certtype,
            'status'   => 'approved',
            'isactive' => 1,
        ], 'timemodified DESC, id DESC', '*', 0, 1);
        return $rs ? reset($rs) : null;
    }

    /**
     * Save an in-progress design.  Status is forced to draft because any
     * edit invalidates a previously-passed validation.  isactive is also
     * cleared so the change does NOT silently take effect for issued
     * certificates — the admin must re-submit and re-activate.
     *
     * The validator is run and its output is persisted to lastvalidation
     * so the list page can show a status badge without re-running it.
     *
     * @param int   $id
     * @param array $design
     * @param string|null $name optional rename
     * @return array validation result
     */
    public static function save_design(int $id, array $design, ?string $name = null): array {
        global $DB;

        $template = self::get($id);
        if (!$template) {
            throw new \invalid_parameter_exception('Template not found: ' . $id);
        }

        // Persist any background image itemid bubbled into the design.
        $bgitemid = isset($design['page']['bg_itemid']) ? (int) $design['page']['bg_itemid'] : null;

        $validation = certificate_validator::validate_template_design($template->certtype, $design);

        $update = (object) [
            'id'             => $id,
            'designjson'     => json_encode($design),
            'bgitemid'       => $bgitemid ?: null,
            'lastvalidation' => json_encode($validation),
            'status'         => 'draft',
            'isactive'       => 0,
            'timemodified'   => time(),
        ];
        if ($name !== null && $name !== '') {
            $update->name = $name;
        }

        $DB->update_record('local_rtocompliance_certtmpl', $update);

        return $validation;
    }

    /**
     * Promote draft → approved.  Refuses if the validator returns errors.
     *
     * @param int $id
     * @return array ['ok' => bool, 'validation' => array]
     */
    public static function submit_for_approval(int $id): array {
        global $DB, $USER;
        $template = self::get($id);
        if (!$template) {
            throw new \invalid_parameter_exception('Template not found: ' . $id);
        }
        if ($template->status === 'archived') {
            return ['ok' => false, 'validation' => ['errors' => [['message' => 'Archived templates cannot be re-submitted.  Duplicate the template first.']], 'warnings' => []]];
        }

        $design = self::decode_design($template);
        $validation = certificate_validator::validate_template_design($template->certtype, $design);

        if (!empty($validation['errors'])) {
            // Persist the failed validation so the UI can show the errors.
            $DB->update_record('local_rtocompliance_certtmpl', (object) [
                'id'             => $id,
                'lastvalidation' => json_encode($validation),
                'timemodified'   => time(),
            ]);
            return ['ok' => false, 'validation' => $validation];
        }

        $DB->update_record('local_rtocompliance_certtmpl', (object) [
            'id'             => $id,
            'status'         => 'approved',
            'approvedby'     => $USER->id,
            'timeapproved'   => time(),
            'timemodified'   => time(),
            'lastvalidation' => json_encode($validation),
        ]);

        return ['ok' => true, 'validation' => $validation];
    }

    /**
     * Activate an approved template.  Demotes any other active template
     * for the same certtype to isactive=0 (status stays approved).
     *
     * @param int $id
     * @return bool
     */
    public static function activate(int $id): bool {
        global $DB;
        $template = self::get($id);
        if (!$template) {
            throw new \invalid_parameter_exception('Template not found: ' . $id);
        }
        if ($template->status !== 'approved') {
            return false;
        }

        // v4.3.0 CERT-TEMPLATE-AUDIENCES — demotion is now scoped to the
        // (certtype + audience) pair, not the certtype alone, so an
        // admin can keep separate active templates for default/apprentice/
        // school/etc. Older rows that were created before v4.3.0 carry
        // audience='default' via the schema DEFAULT, so back-compat is
        // automatic: activating a "default audience" template still
        // demotes the previous default-audience template, exactly as
        // before.
        $audience = !empty($template->audience) ? $template->audience : 'default';

        // ROR-3COL-FIX (v5.9.220): scope demotion to the same orientation (portrait
        // vs landscape) so an admin can keep both a portrait AND a landscape template
        // active simultaneously.  Previously the WHERE clause only filtered on
        // certtype + audience, so activating a landscape RoR template silently
        // deactivated the portrait one (and vice versa) — the Activate button then
        // had no visible effect for whichever orientation was activated second.
        $targetDesign = self::decode_design($template);
        $targetOrientation = $targetDesign['page']['orientation'] ?? 'L';

        $transaction = $DB->start_delegated_transaction();
        try {
            // ACTIVATE-AUDIENCE-FIX (v5.9.230): old templates created before the
            // audience feature (v4.3.0) have audience='' (empty string) in the DB.
            // get_records() with audience='default' never matches those rows, so
            // clicking Activate left them flagged isactive=1 — their ACTIVE badge
            // persisted and the new template appeared not to activate.  When the
            // target audience is 'default', also match empty-string and NULL rows
            // so all legacy templates are correctly demoted.
            if ($audience === 'default') {
                $currentlyActive = $DB->get_records_sql(
                    "SELECT * FROM {local_rtocompliance_certtmpl}
                     WHERE certtype = :certtype
                       AND (audience = 'default' OR audience = '' OR audience IS NULL)
                       AND isactive = 1",
                    ['certtype' => $template->certtype]
                );
            } else {
                $currentlyActive = $DB->get_records('local_rtocompliance_certtmpl', [
                    'certtype' => $template->certtype,
                    'audience' => $audience,
                    'isactive' => 1,
                ]);
            }
            foreach ($currentlyActive as $_existing) {
                if ((int) $_existing->id === $id) {
                    continue; // Skip the target template itself.
                }
                // v5.9.365 ACTIVATE-SINGLE-FIX: demote EVERY other active template for this
                // (certtype+audience) regardless of orientation — exactly one may be active,
                // else get_active_template()/pick_for_audience() throw dml_multiple_records.
                $DB->update_record('local_rtocompliance_certtmpl', (object) [
                    'id'           => (int) $_existing->id,
                    'isactive'     => 0,
                    'timemodified' => time(),
                ]);
            }
            $DB->update_record('local_rtocompliance_certtmpl', (object) [
                'id'           => $id,
                'isactive'     => 1,
                'timemodified' => time(),
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return true;
    }

    /**
     * DEACTIVATE (v5.9.408) — clear the active flag WITHOUT archiving. The
     * template stays status='approved' so it keeps its ACTIVE-badge slot free
     * and can be re-activated later with one click. This is the "make the
     * template non-active" control on the templates list: while no template is
     * active for a cert type, issuance falls back to the built-in ASQA starter.
     * (archive() also clears isactive, but it additionally flips the row to
     * 'archived' status, taking it out of the normal activate/edit flow.)
     *
     * @param int $id
     * @return bool true if the row was updated
     */
    public static function deactivate(int $id): bool {
        global $DB;
        $template = self::get($id);
        if (!$template) {
            return false;
        }
        $DB->update_record('local_rtocompliance_certtmpl', (object) [
            'id'           => $id,
            'isactive'     => 0,
            'timemodified' => time(),
        ]);
        return true;
    }

    /**
     * Archive a template (active or otherwise).  Always clears isactive.
     *
     * @param int $id
     * @return void
     */
    public static function archive(int $id): void {
        global $DB;
        $DB->update_record('local_rtocompliance_certtmpl', (object) [
            'id'           => $id,
            'status'       => 'archived',
            'isactive'     => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Hard-delete a template — only allowed for drafts that have never
     * been approved.  Approved/archived rows are kept for audit.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool {
        global $DB;
        $template = self::get($id);
        if (!$template) {
            return false;
        }
        // Allow deleting: (a) draft templates that were never approved, OR
        // (b) archived templates (safe to remove — they can no longer be activated).
        $isDraftNeverApproved = ($template->status === 'draft' && empty($template->timeapproved));
        $isArchived = ($template->status === 'archived');
        if (!$isDraftNeverApproved && !$isArchived) {
            return false;
        }
        // v4.2.49 BUG-MAY2-AUDIT2 — clean up uploaded files (background +
        // any per-field images keyed by template id) so deleting a draft
        // does not orphan blobs in moodledata.
        try {
            $fs = get_file_storage();
            $ctxid = \context_system::instance()->id;
            $fs->delete_area_files($ctxid, 'local_rtocompliance', self::FA_BG, $id);
            $fs->delete_area_files($ctxid, 'local_rtocompliance', self::FA_IMAGE, $id);
        } catch (\Throwable $e) {
            debugging('Cert template file cleanup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $DB->delete_records('local_rtocompliance_certtmpl', ['id' => $id]);
        return true;
    }

    /**
     * FORCE-DELETE (v5.9.408) — admin-initiated hard delete of ANY template
     * regardless of status (draft / approved / active / archived). Unlike
     * delete() (which keeps approved rows for audit), this is the explicit
     * "Delete" button on the Certificate Templates list: an admin who wants to
     * clear out a template and regenerate a fresh one from the current ASQA
     * starter can do so in one click. Deleting the ACTIVE template for a cert
     * type is safe — issuance/preview automatically falls back to the built-in
     * ASQA starter design until a new template is activated. Uploaded files
     * (background + per-field images) are cleaned up so no blobs are orphaned.
     *
     * @param int $id template id
     * @return bool true if a row was deleted
     */
    public static function force_delete(int $id): bool {
        global $DB;
        $template = self::get($id);
        if (!$template) {
            return false;
        }
        try {
            $fs = get_file_storage();
            $ctxid = \context_system::instance()->id;
            $fs->delete_area_files($ctxid, 'local_rtocompliance', self::FA_BG, $id);
            $fs->delete_area_files($ctxid, 'local_rtocompliance', self::FA_IMAGE, $id);
        } catch (\Throwable $e) {
            debugging('Cert template force-delete file cleanup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $DB->delete_records('local_rtocompliance_certtmpl', ['id' => $id]);
        return true;
    }

    /**
     * Duplicate any template into a new draft (status reset, isactive
     * cleared, name suffixed with " (copy)").
     *
     * @param int $id
     * @return int new template id
     */
    /**
     * ASQA-COMPLIANCE-PASS-3 (v4.2.60) — Reset a template's design back to
     * the ASQA-recommended starter for its certtype + orientation. Used by
     * the "Reset to ASQA starter" button on the cert templates list so an
     * admin who's accidentally broken a template can recover in one click
     * without losing the template record (status / approval / activation).
     *
     * Status is preserved; only the design_json is replaced.
     *
     * @param int $id template id
     * @return bool true on success
     */
    public static function reset_to_starter(int $id): bool {
        global $DB;
        $tpl = self::get($id);
        if (!$tpl) {
            return false;
        }
        $design = json_decode($tpl->designjson ?? '', true);
        $orientation = $design['page']['orientation'] ?? null;
        $newdesign = self::build_starter_design($tpl->certtype, $orientation);
        $DB->update_record('local_rtocompliance_certtmpl', (object) [
            'id'           => $id,
            'designjson'   => json_encode($newdesign, JSON_UNESCAPED_SLASHES),
            'timemodified' => time(),
        ]);
        return true;
    }

    public static function duplicate(int $id): int {
        global $DB, $USER;
        $template = self::get($id);
        if (!$template) {
            throw new \invalid_parameter_exception('Template not found: ' . $id);
        }
        $now = time();
        $oldid = (int) $template->id;
        unset($template->id);
        $template->name        = $template->name . ' (copy)';
        $template->status      = 'draft';
        $template->isactive    = 0;
        $template->approvedby  = null;
        $template->timeapproved = null;
        $template->createdby   = $USER->id;
        $template->timecreated = $now;
        $template->timemodified = $now;
        $newid = (int) $DB->insert_record('local_rtocompliance_certtmpl', $template);

        // v5.9.366 DUPLICATE-FILE-COPY: previously the copy inherited the source's
        // bgitemid and per-field imageitemids verbatim, so both templates pointed at
        // the SAME stored files. Deleting the original then deleted the copy's images
        // too (delete() purges FA_BG/FA_IMAGE by itemid). Copy the files into the new
        // template's own itemids and repoint the design so the duplicate is standalone.
        try {
            $fs = get_file_storage();
            $ctxid = \context_system::instance()->id;
            $design = self::decode_design($template); // uses the (unchanged) source designjson.
            $changed = false;

            // Background image — FA_BG itemid == template id.
            foreach ($fs->get_area_files($ctxid, 'local_rtocompliance', self::FA_BG, $oldid, 'id', false) as $bgf) {
                if ($bgf->is_directory()) {
                    continue;
                }
                $fs->create_file_from_storedfile([
                    'contextid' => $ctxid, 'component' => 'local_rtocompliance', 'filearea' => self::FA_BG,
                    'itemid' => $newid, 'filepath' => '/', 'filename' => $bgf->get_filename(),
                ], $bgf);
            }
            if (!empty($design['page']['bg_itemid'])) {
                $design['page']['bg_itemid'] = $newid;
                $changed = true;
            }

            // Per-field images — FA_IMAGE itemid == (template id * 1000 + field index).
            if (!empty($design['fields']) && is_array($design['fields'])) {
                foreach ($design['fields'] as $i => &$fld) {
                    if (($fld['kind'] ?? '') !== 'image' || empty($fld['imageitemid'])) {
                        continue;
                    }
                    $olditem = (int) $fld['imageitemid'];
                    $newitem = $newid * 1000 + (int) $i;
                    foreach ($fs->get_area_files($ctxid, 'local_rtocompliance', self::FA_IMAGE, $olditem, 'id', false) as $imf) {
                        if ($imf->is_directory()) {
                            continue;
                        }
                        $fs->create_file_from_storedfile([
                            'contextid' => $ctxid, 'component' => 'local_rtocompliance', 'filearea' => self::FA_IMAGE,
                            'itemid' => $newitem, 'filepath' => '/', 'filename' => $imf->get_filename(),
                        ], $imf);
                    }
                    $fld['imageitemid'] = $newitem;
                    $changed = true;
                }
                unset($fld);
            }

            if ($changed) {
                $DB->update_record('local_rtocompliance_certtmpl', (object) [
                    'id'           => $newid,
                    'designjson'   => json_encode($design, JSON_UNESCAPED_SLASHES),
                    'bgitemid'     => !empty($design['page']['bg_itemid']) ? (int) $design['page']['bg_itemid'] : 0,
                    'timemodified' => time(),
                ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal — the duplicate still exists, just still referencing source files.
            debugging('Cert template duplicate file copy failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $newid;
    }

    /**
     * v4.2.58 — SEED DEFAULT TEMPLATES.
     *
     * Run from db/upgrade.php on the v4.2.58 savepoint.  For every cert
     * type that has NO templates in the database yet, this method
     * creates BOTH the portrait and landscape starter designs as
     * APPROVED templates, and ACTIVATES the one matching the ASQA fact
     * sheet default orientation (testamur=L, statement=P, record=P,
     * completion=L).  Existing templates are never touched.
     *
     * Why seed?  Before v4.2.58 the legacy hard-coded TCPDF generator
     * in lib.php was the silent fallback when no active template
     * existed — and that legacy generator was missing five ASQA-
     * mandated elements (NRT logo, signatory block, correct AQF
     * wording, correct attainment wording, authenticity measure).
     * Seeding compliant templates on first install means the
     * dispatcher always has a template to render, and the legacy
     * fallback (now also ASQA-compliant) is only ever hit if an admin
     * explicitly deactivates everything.
     *
     * @return array ['testamur' => int_count_added, 'statement' => ..., 'record' => ..., 'completion' => ...]
     */
    public static function seed_default_templates_if_empty(): array {
        global $DB;
        $now = time();
        $added = ['testamur' => 0, 'statement' => 0, 'record' => 0, 'completion' => 0];

        foreach (['testamur', 'statement', 'record', 'completion'] as $certtype) {
            // Skip if any templates already exist for this cert type.
            if ($DB->record_exists('local_rtocompliance_certtmpl', ['certtype' => $certtype])) {
                continue;
            }
            $defaultorientation = self::default_orientation($certtype); // 'P' or 'L'

            foreach (['L', 'P'] as $orientation) {
                $design = self::build_starter_design($certtype, $orientation);
                $isactive = ($orientation === $defaultorientation) ? 1 : 0;
                $orientationlabel = ($orientation === 'L') ? 'Landscape' : 'Portrait';
                $certtypelabel = ucfirst($certtype === 'statement' ? 'Statement of Attainment'
                                       : ($certtype === 'record' ? 'Record of Results'
                                       : ($certtype === 'completion' ? 'Certificate of Completion'
                                       : 'Testamur')));

                $record = new \stdClass();
                $record->name          = 'Default ' . $certtypelabel . ' (' . $orientationlabel . ')';
                $record->certtype      = $certtype;
                // v4.3.0 — system-seeded starters are always the 'default'
                // audience template. Admins create per-audience variants
                // via cert_templates.php once they need them.
                $record->audience      = 'default';
                $record->audiencelabel = null;
                $record->status        = 'approved';
                $record->isactive      = $isactive;
                $record->designjson    = json_encode($design, JSON_UNESCAPED_SLASHES);
                $record->createdby     = 0; // System-seeded.
                $record->approvedby    = 0; // System-seeded (treated as auto-approved).
                $record->timeapproved  = $now;
                $record->timecreated   = $now;
                $record->timemodified  = $now;
                $DB->insert_record('local_rtocompliance_certtmpl', $record);
                $added[$certtype]++;
            }
        }
        return $added;
    }
}
