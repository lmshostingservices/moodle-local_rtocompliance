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
 * RTO Compliance plugin — certificate_validator.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/avetmiss_codes.php');

class certificate_validator {
    // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — rules rebuilt to match the
    // ASQA "Issuing AQF qualifications and statements of attainment"
    // Fact Sheet exactly. Critical changes:
    //   - USI removed from required_fields for testamur+statement;
    //     ENFORCED as a hard ERROR (forbidden) further below.
    //   - nrt_logo + nrt_statement (qualification.code+name) required for
    //     testamur+statement.
    //   - aqfStatementText only required for testamur (was already correct).
    //   - certify_statement / attained_statement now required for testamur.
    //   - statement_of_attainment_heading now required for statement.
    //   - record_of_results_heading now required for record.
    //   - authenticity_measure required on testamur+statement+record.
    private const AQF_TEMPLATE_REQUIREMENTS = [
        'testamur' => [
            'required_fields' => [
                'organisationName'      => 'Organisation (RTO) name is required',
                'rtoCode'               => 'RTO code (TOID) must appear on a testamur',
                'signatoryName'         => 'An authorised signatory name is required',
                'qualificationCode'     => 'Qualification code is required',
                'qualificationName'     => 'Qualification name is required',
                'certifyStatement'      => 'The "This is to certify that" line is required on a testamur',
                'attainedStatement'     => 'The "has fulfilled the requirements for" line is required on a testamur',
                'aqfStatementText'      => 'AQF recognition statement is required for testamur certificates',
                'nrtLogo'               => 'The Nationally Recognised Training (NRT) logo is required on a testamur',
                'issueDate'             => 'A date of issue is required on a testamur (ASQA Sample Forms fact sheet p.2)',
                'authenticityMeasure'   => 'An authenticity measure (organisation seal, verification URL or QR) is required',
            ],
            'recommended_fields' => [
                'rtoLogo'            => 'RTO logo enhances professional appearance — upload via the Branding panel',
                'aqfLogo'           => 'AQF logo is permitted (and recommended) on a testamur',
                'signatoryTitle'    => 'Including the signatory position/title strengthens the authorised person section',
                'signatorySignature'=> 'A signatory signature image adds authenticity (ASQA mandates the authorised person; a signature is optional)',
                'organisationSeal'  => 'An organisation seal / corporate identifier is one accepted form of the authenticity measure',
                'includeQrCode'     => 'QR code verification improves certificate security'
            ],
            'mandatory_statement' => null
        ],
        'statement' => [
            'required_fields' => [
                'organisationName'           => 'Organisation (RTO) name is required',
                'rtoCode'                    => 'RTO code (TOID) must appear on a Statement of Attainment',
                'signatoryName'              => 'An authorised signatory name is required',
                'unitsOfCompetency'          => 'Units of competency table must be on a Statement of Attainment',
                'soaHeading'                 => 'The "Statement of Attainment" heading is required',
                'soaIntroStatement'          => 'The "This is a statement that" opening phrase is required on a Statement of Attainment (ASQA fact sheet p.4)',
                'soaAttainedStatement'       => 'The "has attained" phrase is required on a Statement of Attainment (ASQA fact sheet p.4)',
                'headerText'                 => 'The mandatory NOT-A-TESTAMUR statement is required',
                'nrtLogo'                    => 'The Nationally Recognised Training (NRT) logo is required',
                'issueDate'                  => 'A date of issue is required on a Statement of Attainment (ASQA fact sheet p.4)',
                'authenticityMeasure'        => 'An authenticity measure (organisation seal, verification URL or QR) is required',
            ],
            'recommended_fields' => [
                'rtoLogo'                              => 'RTO logo enhances professional appearance',
                'signatoryTitle'                       => 'Including the signatory position/title strengthens the authorised person section',
                'signatorySignature'                   => 'A signatory signature image adds authenticity (ASQA mandates the authorised person; a signature is optional)',
                'organisationSeal'                     => 'An organisation seal / corporate identifier is one accepted form of the authenticity measure',
                'qualificationPartOfStatement'         => 'A "These competencies form part of [code and title of qualification]" line is the ASQA-recommended statement showing how the units relate to a qualification (Sample forms of AQF certification documentation). This is the only relationship statement needed on a Statement of Attainment.',
                'includeQrCode'                        => 'QR code verification improves certificate security'
            ],
            'mandatory_statement' => 'A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS. THIS IS NOT A TESTAMUR.'
        ],
        'record' => [
            'required_fields' => [
                'organisationName'      => 'Organisation (RTO) name is required',
                // ASQA fact sheet p.3: "RTO NAME, CODE and LOGO" are all listed as
                // mandatory elements on the Record of Results.
                'rtoCode'               => 'RTO code (TOID) must appear on a Record of Results (ASQA fact sheet p.3)',
                // ASQA fact sheet p.3: "Name of qualification: CODE and FULL TITLE of
                // VET qualification" is mandatory on the Record of Results.
                'qualificationCode'     => 'Qualification code is required on a Record of Results (ASQA fact sheet p.3)',
                'qualificationName'     => 'Qualification name is required on a Record of Results (ASQA fact sheet p.3)',
                'signatoryName'         => 'An authorised signatory name is required',
                'unitsOfCompetency'     => 'Units of competency table must be on a Record of Results',
                'rorHeading'            => 'The "Record of Results" heading is required',
                'issueDate'             => 'A date of issue is required on a Record of Results (ASQA fact sheet p.3)',
                'authenticityMeasure'   => 'An authenticity measure (organisation seal, verification URL or QR) is required',
            ],
            'recommended_fields' => [
                'rtoLogo'        => 'RTO logo enhances professional appearance (ASQA fact sheet p.3 includes logo)',
                'signatoryTitle' => 'Including the signatory position/title strengthens the authorised person section',
                'studentUSI'     => 'Student USI (or ID number) may be included on a Record of Results to authenticate the student — optional (ASQA fact sheet p.3)',
                'includeQrCode'  => 'QR code verification improves certificate security'
            ],
            'mandatory_statement' => null
        ],
        'attendance' => [
            'required_fields' => [
                'organisationName' => 'Organisation name is required for all certificates'
            ],
            'recommended_fields' => [
                'signatoryName'       => 'Including signatory name adds credibility',
                'includeQrCode'       => 'QR code verification improves certificate security',
                // AUTHENTICITY-MEASURE-COMPLETION (v4.4.9): ASQA fact sheet
                // "Sample forms of AQF certification documentation" specifies
                // AUTHENTICITY MEASURE on all cert types as a fraud-reduction
                // control (seal, watermark, document number, or verification URL).
                // Completion/attendance are non-accredited so it is RECOMMENDED,
                // not required. The authenticity_measure dynamic field renders
                // the certificate verification URL automatically.
                'authenticityMeasure' => 'An authenticity measure (cert number, verification URL, or organisational seal) reduces fraud risk — the RTO must supply this. Add the "Authenticity measure" field from the Dynamic fields palette; it will render as a verification URL. For a physical seal or watermark, upload via the Branding panel.',
            ],
            'mandatory_statement' => null
        ],
        // CERT-OF-COMPLETION (v4.2.41) — non-accredited training. Looser
        // than testamur/statement/record because there is no AQF requirement,
        // no USI requirement, no nationally-recognised-training logo. Just
        // student name + course/activity name + completion date + RTO.
        'completion' => [
            'required_fields' => [
                'organisationName' => 'Organisation name is required for all certificates'
            ],
            'recommended_fields' => [
                'signatoryName'       => 'Including a signatory name adds credibility',
                'signatoryTitle'      => 'Including the signatory position improves clarity',
                'includeQrCode'       => 'QR code verification improves certificate security',
                // AUTHENTICITY-MEASURE-COMPLETION (v4.4.9): see attendance note above.
                'authenticityMeasure' => 'An authenticity measure (cert number, verification URL, or organisational seal) reduces fraud risk — the RTO must supply this. Add the "Authenticity measure" field from the Dynamic fields palette; it will render as a verification URL. For a physical seal or watermark, upload via the Branding panel.',
            ],
            'mandatory_statement' => null
        ]
    ];

    /**
     * Maps the AQF_TEMPLATE_REQUIREMENTS field-keys (which were originally
     * designed for the legacy site-wide config layout) to the dynamickey
     * values used by the new visual template builder (cert_template.php).
     *
     * If a required_field key is in this map, validate_template_design()
     * will look for a field on the canvas whose dynamickey matches the
     * mapped value.  Any required_field key not in the map is treated as
     * a site-config check (handled by validate_certificate_issuance()),
     * not a canvas check.
     */
    private const REQUIREMENT_TO_DYNAMICKEY = [
        'organisationName'      => 'rto.name',
        'signatoryName'         => 'signatory.name',
        'signatoryTitle'        => 'signatory.title',
        'signatorySignature'    => 'signatory.signature',
        'aqfStatementText'      => 'aqf_statement',
        'headerText'            => 'not_a_testamur_statement',
        'rtoCode'               => 'rto.code',
        'rtoLogo'               => 'rto.logo',
        'includeQrCode'         => 'qrcode',
        // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — new keys.
        'qualificationCode'     => 'qualification.code',
        'qualificationName'     => 'qualification.name',
        'unitsOfCompetency'     => 'qualification.units',
        'studentUSI'            => 'student.usi',
        'certifyStatement'      => 'certify_statement',
        'attainedStatement'     => 'attained_statement',
        'soaIntroStatement'     => 'soa_intro_statement',
        'soaAttainedStatement'  => 'soa_attained_statement',
        'soaHeading'            => 'statement_of_attainment_heading',
        'rorHeading'            => 'record_of_results_heading',
        'nrtLogo'               => 'nrt_logo',
        'aqfLogo'               => 'aqf_logo',
        'organisationSeal'      => 'organisation_seal',
        'authenticityMeasure'   => 'authenticity_measure',
        'issueDate'             => 'cert.issuedate',
        'qualificationPartOfStatement'             => 'qualification.partofstatement',
        'qualificationCompletionOfCourseStatement' => 'qualification.completionofcoursestatement',
    ];

    /**
     * Validate a template design (the JSON payload produced by the
     * visual builder) against the same AQF_TEMPLATE_REQUIREMENTS rule
     * set used for legacy templates.
     *
     * Required fields that map to a dynamickey must appear on the canvas
     * (i.e. as a field in $design['fields']) for the template to be
     * approvable.  Recommended fields produce warnings only.  Required
     * fields that don't have a canvas equivalent (none currently — all
     * mapped) would be treated as site-config issues handled separately.
     *
     * The mandatory_statement check for 'statement' is enforced by
     * requiring the not_a_testamur_statement dynamic field — the field
     * itself injects the canonical statement at render time.
     *
     * @param string $certtype   testamur|statement|record
     * @param array  $design     decoded design_json
     * @return array ['isCompliant' => bool, 'errors' => [...], 'warnings' => [...]]
     */
    public static function validate_template_design(string $certtype, array $design): array {
        $errors = [];
        $warnings = [];

        if (!isset(self::AQF_TEMPLATE_REQUIREMENTS[$certtype])) {
            $errors[] = [
                'field' => 'certtype',
                'rule' => 'Valid certificate type required',
                'message' => 'Unknown certificate type: ' . $certtype,
                'severity' => 'error',
            ];
            return ['isCompliant' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // Build a quick index of dynamickeys present on the canvas.
        $dynamickeys_on_canvas = [];
        $has_ror_table = false;
        if (!empty($design['fields']) && is_array($design['fields'])) {
            foreach ($design['fields'] as $field) {
                if (!empty($field['kind']) && $field['kind'] === 'dynamic' && !empty($field['dynamickey'])) {
                    $dynamickeys_on_canvas[$field['dynamickey']] = true;
                }
                // ROR-5COL (v6.2.51): the Record of Results units table is a first-class
                // 'ror_table' field kind, not a dynamic 'qualification.units' field. The whole
                // table (Enrolment Date | Unit Code | Unit Title | Result | Completion Date) IS
                // the units-of-competency requirement, so treat its presence as satisfying it.
                if (!empty($field['kind']) && $field['kind'] === 'ror_table') {
                    $has_ror_table = true;
                }
            }
        }
        // ROR-5COL (v6.2.51): an ror_table on the canvas satisfies the units-of-competency
        // requirement exactly as a dynamic qualification.units field would.
        if ($has_ror_table) {
            $dynamickeys_on_canvas['qualification.units'] = true;
        }
        // STUDENT-DETAILS-TABLE (v6.2.51): the student details table carries the student name
        // and the qualification code + title inside it, so its presence satisfies each of those
        // separate ASQA requirements without the individual fields being placed on the canvas.
        if (!empty($dynamickeys_on_canvas['student.detailstable'])) {
            $dynamickeys_on_canvas['student.fullname']  = true;
            $dynamickeys_on_canvas['qualification.code'] = true;
            $dynamickeys_on_canvas['qualification.name'] = true;
        }

        $rules = self::AQF_TEMPLATE_REQUIREMENTS[$certtype];

        // Required: each rule field maps to a dynamickey that must be on the canvas.
        foreach ($rules['required_fields'] as $rulekey => $message) {
            if (!isset(self::REQUIREMENT_TO_DYNAMICKEY[$rulekey])) {
                continue; // Site-config check, not a canvas check.
            }
            $needed = self::REQUIREMENT_TO_DYNAMICKEY[$rulekey];

            // RTO-IDENTITY-IN-LOGO (v6.2.27): when the admin has confirmed (in RTO / Branding
            // settings) that their RTO logo artwork already displays the RTO name and code,
            // do not require them as SEPARATE placed text fields. ASQA still requires the name
            // and code to appear on the document — the logo carries them — so this only relaxes
            // the "separate field" canvas check, not the underlying ASQA obligation.
            if (($rulekey === 'organisationName' || $rulekey === 'rtoCode')
                    && (string) get_config('local_rtocompliance', 'logo_includes_rto_identity') === '1') {
                continue;
            }

            // FIX-QR-AUTHENTICITY (v5.0.3) + SEAL-AS-AUTHENTICITY (v6.2.27): the
            // authenticityMeasure requirement is satisfied by ANY of: authenticity_measure
            // (text URL), qrcode (QR image), verify.url (plain URL text), or an
            // organisation_seal. Per the ASQA "Sample forms of AQF certification
            // documentation" fact sheet the seal, corporate identifier, unique watermark
            // and/or document number are EXAMPLE FORMS of the single authenticity measure —
            // so a seal on the canvas satisfies it without a separate URL/QR.
            $satisfied = !empty($dynamickeys_on_canvas[$needed]);
            if (!$satisfied && $rulekey === 'authenticityMeasure') {
                $satisfied = !empty($dynamickeys_on_canvas['qrcode'])
                          || !empty($dynamickeys_on_canvas['verify.url'])
                          || !empty($dynamickeys_on_canvas['organisation_seal']);
            }

            if (!$satisfied) {
                $errors[] = [
                    'field' => $needed,
                    'rule' => 'AQF Mandatory Requirement',
                    'message' => $rulekey === 'authenticityMeasure'
                        ? $message . ' — add either the "Authenticity measure" field (renders as a verification URL) OR the "Verification QR code" field to the canvas.'
                        : $message . ' — drag the "' . $needed . '" field onto the canvas.',
                    'severity' => 'error',
                    'aqfReference' => 'AQF Qualifications Issuance Policy',
                ];
            }
        }

        // Recommended: produce warnings only.
        foreach ($rules['recommended_fields'] as $rulekey => $message) {
            if (!isset(self::REQUIREMENT_TO_DYNAMICKEY[$rulekey])) {
                continue;
            }
            $needed = self::REQUIREMENT_TO_DYNAMICKEY[$rulekey];

            // FIX-QR-AUTHENTICITY (v5.0.3): same OR-logic for recommended authenticityMeasure.
            $satisfied = !empty($dynamickeys_on_canvas[$needed]);
            if (!$satisfied && $rulekey === 'authenticityMeasure') {
                $satisfied = !empty($dynamickeys_on_canvas['qrcode'])
                          || !empty($dynamickeys_on_canvas['verify.url'])
                          || !empty($dynamickeys_on_canvas['organisation_seal']);
            }
            // FIX-QR-AUTHENTICITY (v5.0.3): suppress the "add QR code" recommendation
            // when any authenticity measure (authenticity_measure or verify.url) is
            // already on the canvas — the template is already compliant.
            if (!$satisfied && $rulekey === 'includeQrCode') {
                $satisfied = !empty($dynamickeys_on_canvas['authenticity_measure'])
                          || !empty($dynamickeys_on_canvas['verify.url']);
            }

            if (!$satisfied) {
                $warnings[] = [
                    'field' => $needed,
                    'rule' => 'Recommended Practice',
                    'message' => $message . ' — consider dragging the "' . $needed . '" field onto the canvas.',
                    'severity' => 'warning',
                ];
            }
        }

        // Sanity: at least one of student.fullname must be present for any cert type.
        if (empty($dynamickeys_on_canvas['student.fullname'])) {
            $errors[] = [
                'field' => 'student.fullname',
                'rule' => 'AQF Mandatory Requirement',
                'message' => 'The student\'s full name must appear on every certificate — drag the "student.fullname" field onto the canvas.',
                'severity' => 'error',
                'aqfReference' => 'AQF Qualifications Issuance Policy',
            ];
        }

        // v4.4.0 NRT-LOGO-COMPLIANCE — every dynamic field with a
        // 'forbidden_for' entry in the catalogue is HARD-BLOCKED on the
        // listed certtypes. Currently this enforces the NRT Logo
        // Conditions of Use Policy: "must not be depicted on other
        // testamurs or transcripts of results" — i.e. forbidden on
        // record/attendance/completion. Generic so future fields can
        // declare their own forbidden_for without renderer changes.
        $catalogue = \local_rtocompliance\cert_template::get_dynamic_field_catalogue();
        foreach ($catalogue as $dynkey => $meta) {
            $forbidden = $meta['forbidden_for'] ?? [];
            if (!is_array($forbidden) || empty($forbidden)) {
                continue;
            }
            if (in_array($certtype, $forbidden, true) && !empty($dynamickeys_on_canvas[$dynkey])) {
                $errors[] = [
                    'field' => $dynkey,
                    'rule' => 'ASQA Compliance — field forbidden on this certificate type',
                    'message' => 'The "' . ($meta['label'] ?? $dynkey) . '" field is not permitted on a '
                        . $certtype . ' template — remove it from the canvas.',
                    'severity' => 'error',
                    'aqfReference' => $dynkey === 'nrt_logo'
                        ? 'NRT Logo Specifications and Conditions of Use'
                        : 'ASQA Practice Guide',
                ];
            }
        }

        // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — USI is FORBIDDEN on testamur
        // and statement of attainment per the ASQA fact sheet (USI must be
        // recorded internally only, never appear on the issued document).
        // This is a HARD ERROR that blocks Submit-for-approval.
        if (in_array($certtype, ['testamur', 'statement'], true)
            && !empty($dynamickeys_on_canvas['student.usi'])) {
            $errors[] = [
                'field' => 'student.usi',
                'rule' => 'ASQA Compliance — USI must NOT appear',
                'message' => 'The Unique Student Identifier (USI) must NOT appear on a '
                    . ($certtype === 'testamur' ? 'testamur' : 'Statement of Attainment')
                    . '. Remove the "student.usi" field from the canvas. (USI is recorded internally and may only appear on a Record of Results.)',
                'severity' => 'error',
                'aqfReference' => 'ASQA Issuing AQF Qualifications Fact Sheet',
            ];
        }

        // Sanity: page must be A4 (landscape or portrait) — TCPDF supports others
        // but ASQA/AQF practice is A4.
        $page = $design['page'] ?? [];
        if (($page['format'] ?? '') !== 'A4') {
            $warnings[] = [
                'field' => 'page.format',
                'rule' => 'Recommended Practice',
                'message' => 'A4 is the standard page size for Australian VET certificates.',
                'severity' => 'warning',
            ];
        }

        // Geometry: every field must fit inside the page.
        $w = (float) ($page['width_mm']  ?? 297);
        $h = (float) ($page['height_mm'] ?? 210);
        if (!empty($design['fields']) && is_array($design['fields'])) {
            foreach ($design['fields'] as $field) {
                $x = (float) ($field['x_mm'] ?? 0);
                $y = (float) ($field['y_mm'] ?? 0);
                $fw = (float) ($field['w_mm'] ?? 0);
                $fh = (float) ($field['h_mm'] ?? 0);
                if ($x < 0 || $y < 0 || ($x + $fw) > $w + 0.5 || ($y + $fh) > $h + 0.5) {
                    $warnings[] = [
                        'field' => $field['id'] ?? '?',
                        'rule' => 'Layout',
                        'message' => 'Field "' . ($field['dynamickey'] ?? $field['kind'] ?? 'untitled') . '" extends outside the page boundary.',
                        'severity' => 'warning',
                    ];
                }
            }
        }

        return [
            'isCompliant' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * LIVE-VALIDATION (v6.2.16) — render the ASQA validator panel HTML for a
     * validate_template_design() result. This is the SINGLE source of truth for
     * the panel markup: both the server-rendered initial state (cert_template_edit.php)
     * and the AJAX live re-validation endpoint (cert_template_validate.php) call
     * this, so the panel that appears on page-load and the panel that redraws after
     * every field add/delete are byte-for-byte identical. Previously the panel was
     * only rendered on the edit page, so adding a field via a "Fix" button never
     * cleared the corresponding recommendation until a full save+reload — the exact
     * "the error doesn't disappear even when you follow its command" bug.
     *
     * @param array $validation result of validate_template_design()
     * @param array|null $catalogue dynamic-field catalogue (defaults to cert_template's)
     * @return string HTML for the inside of #rtoc-tmpl-validation
     */
    public static function render_validation_panel_html(array $validation, ?array $catalogue = null): string {
        if ($catalogue === null) {
            $catalogue = \local_rtocompliance\cert_template::get_dynamic_field_catalogue();
        }
        $fixlabel = get_string('cert_template_validation_fix', 'local_rtocompliance');

        $rendervalitem = function ($item) use ($fixlabel, $catalogue) {
            $msg = s($item['message']);
            $key = $item['field'] ?? '';
            $hasField = is_string($key) && $key !== '' && isset($catalogue[$key]);
            // Never offer a "Fix" (add-the-field) button for a "must NOT appear"
            // error — the remedy there is to REMOVE the field, not add it.
            $isNotUsiError = !(strpos((string)($item['rule'] ?? ''), 'USI must NOT appear') !== false);
            $btn = '';
            if ($hasField && $isNotUsiError) {
                $btn = ' ' . \html_writer::tag('button', $fixlabel, [
                    'type'        => 'button',
                    'class'       => 'btn btn-sm btn-outline-primary py-0 px-2 ml-1',
                    'data-fix-key' => $key,
                    'style'       => 'font-size:0.75rem;line-height:1.2;',
                ]);
            }
            return \html_writer::tag('li', $msg . $btn);
        };

        if (empty($validation['errors']) && empty($validation['warnings'])) {
            return \html_writer::div(get_string('cert_template_validation_passed', 'local_rtocompliance'),
                'alert alert-success small mb-0 p-2');
        }

        $out = '';
        if (!empty($validation['errors'])) {
            $out .= \html_writer::tag('div',
                \html_writer::tag('strong', get_string('cert_template_validation_errors', 'local_rtocompliance')) .
                \html_writer::start_tag('ul', ['class' => 'mb-0 pl-3']) .
                implode('', array_map($rendervalitem, $validation['errors'])) .
                \html_writer::end_tag('ul'),
                ['class' => 'alert alert-danger small mb-2 p-2']);
        }
        if (!empty($validation['warnings'])) {
            $out .= \html_writer::tag('div',
                \html_writer::tag('strong', get_string('cert_template_validation_warnings', 'local_rtocompliance')) .
                \html_writer::start_tag('ul', ['class' => 'mb-0 pl-3']) .
                implode('', array_map($rendervalitem, $validation['warnings'])) .
                \html_writer::end_tag('ul'),
                ['class' => 'alert alert-warning small mb-0 p-2']);
        }
        return $out;
    }

    public static function validate_template($template, $templatetype) {
        $errors = [];
        $warnings = [];
        
        if (!isset(self::AQF_TEMPLATE_REQUIREMENTS[$templatetype])) {
            $errors[] = [
                'field' => 'templateType',
                'rule' => 'Valid template type required',
                'message' => 'Please select a valid certificate type (Testamur, Statement of Attainment, Record of Results, or Certificate of Attendance)',
                'severity' => 'error'
            ];
            return [
                'isCompliant' => false,
                'errors' => $errors,
                'warnings' => $warnings
            ];
        }
        
        $rules = self::AQF_TEMPLATE_REQUIREMENTS[$templatetype];
        
        foreach ($rules['required_fields'] as $field => $message) {
            if (empty($template[$field])) {
                $errors[] = [
                    'field' => $field,
                    'rule' => 'AQF Mandatory Requirement',
                    'message' => $message,
                    'severity' => 'error',
                    'aqfReference' => 'AQF Qualifications Issuance Policy'
                ];
            }
        }
        
        foreach ($rules['recommended_fields'] as $field => $message) {
            if (empty($template[$field])) {
                $warnings[] = [
                    'field' => $field,
                    'rule' => 'Recommended Practice',
                    'message' => $message,
                    'severity' => 'warning'
                ];
            }
        }
        
        if ($rules['mandatory_statement'] && $templatetype === 'statement') {
            if (empty($template['headerText'])) {
                $errors[] = [
                    'field' => 'headerText',
                    'rule' => 'AQF Statement of Attainment Requirement',
                    'message' => 'Statement of Attainment must include the mandatory header text',
                    'severity' => 'error',
                    'aqfReference' => 'AQF Statement of Attainment Policy'
                ];
            } else if (stripos($template['headerText'], 'STATEMENT OF ATTAINMENT') === false ||
                stripos($template['headerText'], 'ONE OR MORE') === false ||
                stripos($template['headerText'], 'NOT A TESTAMUR') === false) {
                $errors[] = [
                    'field' => 'headerText',
                    'rule' => 'AQF Statement of Attainment Requirement',
                    'message' => 'Statement of Attainment must include: (1) "Statement of Attainment is issued when an individual has completed one or more accredited units" AND (2) "This is not a testamur" disclaimer',
                    'severity' => 'error',
                    'aqfReference' => 'AQF Statement of Attainment Policy'
                ];
            }
        }
        
        if ($templatetype === 'testamur' || $templatetype === 'statement') {
            if (!empty($template['aqfStatementText'])) {
                $aqftext = strtolower($template['aqfStatementText']);
                if (strpos($aqftext, 'australian qualifications framework') === false &&
                    strpos($aqftext, 'aqf') === false) {
                    $warnings[] = [
                        'field' => 'aqfStatementText',
                        'rule' => 'AQF Recognition',
                        'message' => 'AQF statement should reference the Australian Qualifications Framework',
                        'severity' => 'warning'
                    ];
                }
            }
        }
        
        return [
            'isCompliant' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    public static function get_template_compliance_summary() {
        $rtoname = get_config('local_rtocompliance', 'rtoname');
        $rtocode = get_config('local_rtocompliance', 'rtocode');
        $signatoryname = get_config('local_rtocompliance', 'signatoryname');
        $signatorytitle = get_config('local_rtocompliance', 'signatorytitle');
        
        $issues = [];
        $warnings = [];
        
        if (empty($rtoname)) {
            $issues[] = 'Organisation name is not configured - certificates cannot be issued without this';
        }
        if (empty($rtocode)) {
            $warnings[] = 'RTO Code is not configured - recommended for AQF compliance';
        }
        if (empty($signatoryname)) {
            $issues[] = 'Authorised signatory name is not configured - required for testamur and statement certificates';
        }
        if (empty($signatorytitle)) {
            $warnings[] = 'Signatory title is not configured - recommended for professional presentation';
        }
        
        $logopath = get_config('local_rtocompliance', 'logopath');
        if (empty($logopath) || !file_exists($logopath)) {
            $warnings[] = 'Organisation logo is not configured or file not found';
        }
        
        $sealpath = get_config('local_rtocompliance', 'sealpath');
        if (empty($sealpath) || !file_exists($sealpath)) {
            $warnings[] = 'Organisation seal is not configured or file not found';
        }
        
        return [
            'isCompliant' => count($issues) === 0,
            'canIssueTestamur' => !empty($rtoname) && !empty($signatoryname),
            'canIssueStatement' => !empty($rtoname) && !empty($signatoryname),
            'canIssueRecord' => !empty($rtoname) && !empty($signatoryname),
            'canIssueAttendance' => !empty($rtoname),
            'issues' => $issues,
            'warnings' => $warnings,
            'configuredFields' => [
                'rtoName' => !empty($rtoname),
                'rtoCode' => !empty($rtocode),
                'signatoryName' => !empty($signatoryname),
                'signatoryTitle' => !empty($signatorytitle),
                'logo' => !empty($logopath) && file_exists($logopath),
                'seal' => !empty($sealpath) && file_exists($sealpath)
            ]
        ];
    }
    
    public static function format_validation_errors($errors) {
        if (empty($errors)) {
            return '';
        }
        
        $formatted = [];
        foreach ($errors as $error) {
            $msg = $error['message'];
            if (!empty($error['aqfReference'])) {
                $msg .= ' (Reference: ' . $error['aqfReference'] . ')';
            }
            $formatted[] = $msg;
        }
        
        return implode("\n", $formatted);
    }

    public static function can_issue_testamur($userid, $qualificationcode) {
        global $DB;
        
        $errors = [];
        $warnings = [];

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        
        if (!$student) {
            $errors[] = get_string('error_no_profile', 'local_rtocompliance');
            return ['can_issue' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        if (empty($student->usi)) {
            $errors[] = get_string('error_usi_required', 'local_rtocompliance');
        } else {
            $validation = avetmiss_codes::validate_usi($student->usi);
            if (!$validation['valid']) {
                $errors[] = get_string('error_usi_invalid', 'local_rtocompliance') . ': ' . $validation['error'];
            } else if ((int) $student->usiverified !== \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED) {
                // C-P1-1 (v5.9.387): a well-formed USI is not enough — the Student
                // Identifiers Act requires a USI VERIFIED against the Registry before
                // AQF certification is issued. USI-VERIFIED-ACCURACY (v6.2.8): require
                // STATUS_VERIFIED(1) — pending(3), failed(2) and manual-review(4) are NOT
                // verified (the old empty() test wrongly let pending through). Format-valid but unverified (or Registry-
                // rejected) USIs must not pass. Genuine exemptions use the documented
                // bypass on the programmatic issuer.
                $errors[] = get_string('error_usi_unverified', 'local_rtocompliance');
            }
        }

        if (!$student->profilecomplete) {
            $profileErrors = [];
            if (!empty($student->validationerrors)) {
                $profileErrors = json_decode($student->validationerrors, true) ?: [];
            }
            if (!empty($profileErrors)) {
                $warnings[] = get_string('profileincomplete', 'local_rtocompliance') . ' ' . implode(', ', array_column($profileErrors, 'message'));
            }
        }

        $holds = self::check_holds($student->id);
        if (!empty($holds)) {
            foreach ($holds as $hold) {
                $errors[] = get_string('error_hold_active', 'local_rtocompliance') . ': ' . $hold['reason'];
            }
        }

        // F3 (v5.9.389): mandatory RTO identity (name, provider code, signatory) must
        // be configured, or the certificate would render those AQF-required fields blank.
        if (function_exists('local_rtocompliance_missing_cert_settings')) {
            foreach (local_rtocompliance_missing_cert_settings() as $missingsetting) {
                $errors[] = 'Required RTO detail not configured: ' . $missingsetting;
            }
        }

        if (!empty($qualificationcode)) {
            $completion = self::check_qualification_completion($student->id, $qualificationcode);

            // v4.6.103 FIX-TESTAMUR-COMPLETION-GATE — when no enrolment records exist
            // in local_rtocompliance_enrolments for this student+qualification, the
            // check returned complete=false and all_finalized=false, adding two HARD
            // errors that blocked all testamur issuance for RTOs whose completions are
            // tracked via Moodle's native course completions rather than the plugin's
            // enrolments table.  Empty-enrolment result = "no data to validate against"
            // (a warning), not "qualification definitively incomplete" (an error).
            if (($completion['units_completed'] ?? 0) === 0 && ($completion['units_total'] ?? 0) === 0) {
                // No enrolment records at all — warn but don't block.
                $warnings[] = get_string('no_enrolments_for_qual', 'local_rtocompliance');
            } else {
                if (!$completion['complete']) {
                    $errors[] = get_string('error_qualification_incomplete', 'local_rtocompliance');
                    foreach ($completion['missing_units'] as $unit) {
                        $warnings[] = get_string('missing_unit', 'local_rtocompliance') . ': ' . $unit;
                    }
                }
                if (!$completion['all_finalized']) {
                    $errors[] = get_string('error_outcomes_not_finalized', 'local_rtocompliance');
                }
            }
        }

        return [
            'can_issue' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public static function can_issue_statement($userid, $units = []) {
        global $DB;
        
        $errors = [];
        $warnings = [];

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        
        if (!$student) {
            $errors[] = get_string('error_no_profile', 'local_rtocompliance');
            return ['can_issue' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        if (empty($units)) {
            $units = $DB->get_records('local_rtocompliance_enrolments', [
                'studentid' => $student->id,
                'status' => 'completed',
            ]);
        }

        if (empty($units)) {
            $errors[] = get_string('error_no_units', 'local_rtocompliance');
            return ['can_issue' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        $competentoutcomes = avetmiss_codes::get_completion_outcomes();
        $hascompetent = false;
        
        foreach ($units as $unit) {
            $outcome = is_array($unit) ? ($unit['outcomeidentifier'] ?? '') : ($unit->outcomeidentifier ?? '');
            if (in_array($outcome, $competentoutcomes)) {
                $hascompetent = true;
                break;
            }
        }
        
        if (!$hascompetent) {
            $errors[] = get_string('error_no_competent_units', 'local_rtocompliance');
        }

        if (empty($student->usi)) {
            $errors[] = get_string('error_usi_required', 'local_rtocompliance');
        } else {
            $validation = avetmiss_codes::validate_usi($student->usi);
            if (!$validation['valid']) {
                $errors[] = get_string('error_usi_invalid', 'local_rtocompliance') . ': ' . $validation['error'];
            } else if ((int) $student->usiverified !== \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED) {
                // C-P1-1 (v5.9.387): a well-formed USI is not enough — the Student
                // Identifiers Act requires a USI VERIFIED against the Registry before
                // AQF certification is issued. USI-VERIFIED-ACCURACY (v6.2.8): require
                // STATUS_VERIFIED(1) — pending(3), failed(2) and manual-review(4) are NOT
                // verified (the old empty() test wrongly let pending through). Format-valid but unverified (or Registry-
                // rejected) USIs must not pass. Genuine exemptions use the documented
                // bypass on the programmatic issuer.
                $errors[] = get_string('error_usi_unverified', 'local_rtocompliance');
            }
        }

        $holds = self::check_holds($student->id);
        if (!empty($holds)) {
            foreach ($holds as $hold) {
                $errors[] = get_string('error_hold_active', 'local_rtocompliance') . ': ' . $hold['reason'];
            }
        }

        // F3 (v5.9.389): mandatory RTO identity must be configured (see can_issue_testamur).
        if (function_exists('local_rtocompliance_missing_cert_settings')) {
            foreach (local_rtocompliance_missing_cert_settings() as $missingsetting) {
                $errors[] = 'Required RTO detail not configured: ' . $missingsetting;
            }
        }

        $hasContinuing = false;
        foreach ($units as $unit) {
            $outcome = is_array($unit) ? ($unit['outcomeidentifier'] ?? '') : ($unit->outcomeidentifier ?? '');
            if (in_array($outcome, ['70', '90', '00'])) {
                $hasContinuing = true;
                break;
            }
        }
        
        if ($hasContinuing) {
            $warnings[] = get_string('warning_continuing_units', 'local_rtocompliance');
        }

        return [
            'can_issue' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public static function can_issue_attendance($userid) {
        global $DB;
        
        $errors = [];
        $warnings = [];

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);

        $holds = [];
        if ($student) {
            $holds = self::check_holds($student->id);
        }

        if (!empty($holds)) {
            foreach ($holds as $hold) {
                $errors[] = get_string('error_hold_active', 'local_rtocompliance') . ': ' . $hold['reason'];
            }
        }

        $warnings[] = get_string('attendance_nonaccredited_only', 'local_rtocompliance');

        return [
            'can_issue' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public static function check_holds($studentid) {
        global $DB;

        $holds = [];

        $enrolmentHolds = $DB->get_records_sql(
            "SELECT * FROM {local_rtocompliance_enrolments} 
             WHERE studentid = ? AND status = 'hold' AND (holduntil IS NULL OR holduntil > ?)",
            [$studentid, time()]
        );

        foreach ($enrolmentHolds as $hold) {
            $holds[] = [
                'type' => 'enrolment_hold',
                'enrolment_id' => $hold->id,
                'reason' => $hold->holdreason ?: get_string('hold_no_reason', 'local_rtocompliance'),
                'until' => $hold->holduntil ? userdate($hold->holduntil) : get_string('indefinite', 'local_rtocompliance'),
            ];
        }

        return $holds;
    }

    public static function check_qualification_completion($studentid, $qualificationcode) {
        global $DB;

        // PROGRAMCODE-CASE-FIX (v5.9.254): exact-match get_records() fails when
        // programcode is stored in a different case (e.g. 'mem20413' vs 'MEM20413')
        // or with leading/trailing whitespace. Use a case-insensitive SQL LIKE
        // and TRIM as the primary search, then also accept an empty programcode
        // match as a secondary fallback so legacy enrolments without a programcode
        // are not invisible during certificate issuance and rendering.
        $qualUpper = strtoupper(trim($qualificationcode));
        $enrolments = $DB->get_records_sql(
            "SELECT * FROM {local_rtocompliance_enrolments}
              WHERE studentid = :sid
                AND UPPER(TRIM(programcode)) = :qcode",
            ['sid' => $studentid, 'qcode' => $qualUpper]
        );

        if (empty($enrolments)) {
            return [
                'complete' => false,
                'all_finalized' => false,
                'missing_units' => [],
                'units_completed' => 0,
                'units_required' => 0,
                'message' => get_string('no_enrolments_for_qual', 'local_rtocompliance'),
            ];
        }

        $completionOutcomes = avetmiss_codes::get_completion_outcomes();
        $continuingOutcomes = avetmiss_codes::get_continuing_outcomes();
        
        $completed = 0;
        $continuing = 0;
        $units = [];
        $missingUnits = [];

        foreach ($enrolments as $enrol) {
            if (in_array($enrol->outcomeidentifier, $completionOutcomes)) {
                $completed++;
                // ROR-SEMESTER-FIX (v5.9.226): derive semester from activityenddate
                // (when the unit was resulted) so Col 1 of the Record of Results shows
                // the correct "Sem 1/2 YYYY" rather than falling back to the cert issue date.
                // Falls back to activitystartdate if enddate is absent.
                $_ts = !empty($enrol->activityenddate) ? (int)$enrol->activityenddate : 0;
                if ($_ts <= 0 && !empty($enrol->activitystartdate)) {
                    $_ts = (int)$enrol->activitystartdate;
                }
                $_semLabel = '';
                if ($_ts > 0) {
                    $_month    = (int)date('n', $_ts);
                    $_year     = date('Y', $_ts);
                    $_semLabel = ($_month <= 6 ? 'Sem 1 ' : 'Sem 2 ') . $_year;
                }
                $units[] = [
                    'code'      => $enrol->unitcode,
                    'name'      => $enrol->unitname,
                    'outcome'   => $enrol->outcomeidentifier,
                    'semester'  => $_semLabel,
                    // COMPLETION-DATE (v5.9.447): raw completion timestamp (unit
                    // resulted date) so the certificate Completion Date column can
                    // format it. Falls back to activitystartdate above when the
                    // end date is absent; 0 when neither is present.
                    'date'      => $_ts,
                    'finalized' => true,
                ];
            } else if (in_array($enrol->outcomeidentifier, $continuingOutcomes)) {
                $continuing++;
                $missingUnits[] = $enrol->unitcode . ' (' . $enrol->unitname . ')';
            }
        }

        $allFinalized = ($continuing == 0);

        return [
            'complete' => $completed > 0 && $continuing == 0,
            'all_finalized' => $allFinalized,
            'missing_units' => $missingUnits,
            'units_completed' => $completed,
            'units_total' => count($enrolments),
            'units' => $units,
        ];
    }

    public static function validate_certificate_issuance($certtype, $userid, $data = []) {
        switch ($certtype) {
            case 'testamur':
            case 'record':
                return self::can_issue_testamur($userid, $data['qualificationcode'] ?? '');
            case 'statement':
                return self::can_issue_statement($userid, $data['units'] ?? []);
            case 'attendance':
                return self::can_issue_attendance($userid);
            default:
                return ['can_issue' => false, 'errors' => [get_string('error_unknown_certtype', 'local_rtocompliance')], 'warnings' => []];
        }
    }

    public static function get_student_avetmiss_status($userid) {
        global $DB;
        
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        
        if (!$student) {
            return [
                'has_profile' => false,
                'profile_complete' => false,
                'ready_for_cert' => false,
                'errors' => [get_string('error_no_profile', 'local_rtocompliance')],
            ];
        }

        $mandatoryFields = [
            'usi' => get_string('usi', 'local_rtocompliance'),
            'dateofbirth' => get_string('dateofbirth', 'local_rtocompliance'),
            'sex' => get_string('sex', 'local_rtocompliance'),
            'postcode' => get_string('residentialpostcode', 'local_rtocompliance'),
            'statecode' => get_string('residentialstate', 'local_rtocompliance'),
            'suburb' => get_string('residentialsuburb', 'local_rtocompliance'),
        ];

        $fields = [];
        $requiredComplete = 0;

        foreach ($mandatoryFields as $field => $label) {
            $value = $student->$field ?? '';
            $isComplete = !empty($value) && $value !== '@' && $value !== '@@' && $value !== '@@@@';
            
            $fields[$field] = [
                'label' => $label,
                'value' => $value,
                'complete' => $isComplete,
                'required' => true,
            ];

            if ($isComplete) {
                $requiredComplete++;
            }
        }

        $optionalFields = [
            'countryofbirth' => get_string('countryofbirth', 'local_rtocompliance'),
            'languageathome' => get_string('languageathome', 'local_rtocompliance'),
            'indigenousstatus' => get_string('atsi', 'local_rtocompliance'),
            'disabilityflag' => get_string('disability', 'local_rtocompliance'),
            'highestschoollevel' => get_string('schoollevel', 'local_rtocompliance'),
        ];

        foreach ($optionalFields as $field => $label) {
            $value = $student->$field ?? '';
            $isComplete = !empty($value) && $value !== '@' && $value !== '@@';
            
            $fields[$field] = [
                'label' => $label,
                'value' => $value,
                'complete' => $isComplete,
                'required' => false,
            ];
        }

        $totalRequired = count($mandatoryFields);
        $totalComplete = 0;
        foreach ($fields as $f) {
            if ($f['complete']) {
                $totalComplete++;
            }
        }

        return [
            'has_profile' => true,
            'fields' => $fields,
            'total' => count($fields),
            'complete' => $totalComplete,
            'percentage' => round(($totalComplete / count($fields)) * 100),
            'required_complete' => $requiredComplete,
            'total_required' => $totalRequired,
            'ready_for_cert' => $requiredComplete >= $totalRequired,
            'profile_complete' => (bool) $student->profilecomplete,
            // USI-VERIFIED-ACCURACY (v6.2.8): only usiverified===1 (STATUS_VERIFIED) is truly
            // verified. (bool) cast wrongly treated usiverified===3 (pending/stuck) as verified.
            'usi_verified' => ((int) $student->usiverified === 1),
        ];
    }

    public static function get_issuable_units($userid) {
        global $DB;

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        if (!$student) {
            return [];
        }

        $completionOutcomes = avetmiss_codes::get_completion_outcomes();
        $outcomes = array_map(function ($o) { return "'$o'"; }, $completionOutcomes);
        
        // FIX-ISSUABLE-STATUS (v5.2.35): The previous filter used e.status = 'completed'
        // but enrolments default to status='active' and many never get promoted to 'completed'
        // even when the outcome is finalised (e.g. outcome 20 set via NAT import or manual
        // grading). The AVETMISS outcome code IS the authoritative completion signal —
        // a competent outcomeidentifier means the training activity is done regardless of
        // the status field value.  Replaced with status != 'withdrawn' so that legitimately
        // withdrawn enrolments are still excluded while all other active/completed/hold
        // enrolments with competent outcomes are correctly surfaced.
        $enrolments = $DB->get_records_sql(
            "SELECT e.* FROM {local_rtocompliance_enrolments} e
             WHERE e.studentid = ? 
             AND e.outcomeidentifier IN (" . implode(',', $outcomes) . ")
             AND e.status != 'withdrawn'
             ORDER BY e.programcode, e.unitcode",
            [$student->id]
        );

        $issuedCerts = $DB->get_records('local_rtocompliance_certs', [
            'userid' => $userid,
            'certtype' => 'statement',
            'status' => 'issued',
        ]);

        $issuedUnits = [];
        foreach ($issuedCerts as $cert) {
            if (!empty($cert->units)) {
                $units = json_decode($cert->units, true) ?: [];
                foreach ($units as $unit) {
                    $issuedUnits[$unit['code']] = $cert->certnumber;
                }
            }
        }

        $result = [];
        foreach ($enrolments as $enrol) {
            $result[] = [
                'id' => $enrol->id,
                'unitcode' => $enrol->unitcode,
                'unitname' => $enrol->unitname,
                'programcode' => $enrol->programcode,
                'programname' => $enrol->programname,
                'outcome' => $enrol->outcomeidentifier,
                'activityenddate' => $enrol->activityenddate,
                'already_issued' => isset($issuedUnits[$enrol->unitcode]),
                'issued_cert' => $issuedUnits[$enrol->unitcode] ?? null,
            ];
        }

        return $result;
    }

    public static function get_issuable_qualifications($userid) {
        global $DB;

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        if (!$student) {
            return [];
        }

        $programs = $DB->get_records_sql(
            "SELECT DISTINCT programcode, programname 
             FROM {local_rtocompliance_enrolments} 
             WHERE studentid = ? AND programcode IS NOT NULL AND programcode != ''
             ORDER BY programcode",
            [$student->id]
        );

        $result = [];
        foreach ($programs as $program) {
            $completion = self::check_qualification_completion($student->id, $program->programcode);
            
            $issuedCert = $DB->get_record('local_rtocompliance_certs', [
                'userid' => $userid,
                'qualificationcode' => $program->programcode,
                'certtype' => 'testamur',
                'status' => 'issued',
            ]);

            $result[] = [
                'programcode' => $program->programcode,
                'programname' => $program->programname,
                'complete' => $completion['complete'],
                'units_completed' => $completion['units_completed'],
                'units_total' => $completion['units_total'],
                'all_finalized' => $completion['all_finalized'],
                'missing_units' => $completion['missing_units'],
                'already_issued' => !empty($issuedCert),
                'issued_cert' => $issuedCert->certnumber ?? null,
            ];
        }

        return $result;
    }
}
