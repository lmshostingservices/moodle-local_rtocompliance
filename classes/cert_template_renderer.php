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
 * TCPDF renderer for visual certificate templates.
 *
 * Takes a template + the cert/user payload, resolves every dynamic field
 * to live data, and emits raw PDF bytes that match the editor canvas
 * coordinate-for-coordinate (everything stored in mm, page is whatever
 * the design says — typically A4 landscape).
 *
 * Used by lib.php → local_rtocompliance_render_certificate_pdf_string()
 * when an active approved template exists, and by cert_template_preview.php
 * when the admin clicks Preview in the editor.
 *
 * @package    local_rtocompliance
 * @copyright  2026 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/cert_template.php');

class cert_template_renderer {
    /**
     * Render a template to raw PDF bytes.
     *
     * @param \stdClass $template      template row
     * @param array     $payload       resolved dynamic data (see resolve_payload())
     * @return string raw PDF bytes
     */
    public static function render(\stdClass $template, array $payload, string $orientation_override = ''): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $design = cert_template::decode_design($template);
        // v4.2.48 BUG-MAY2-AUDIT — hydrate image filesystem paths from
        // stored itemids BEFORE rendering. Without this, $page['bg_image_path']
        // and per-field image_path keys are never set and the renderer
        // silently skips every uploaded background and per-field image.
        $design = self::hydrate_image_paths($design);
        $page   = $design['page'] ?? [];
        // NRT-ORIENT-OVERRIDE (v4.4.7): allow caller (e.g. cert_test.php) to force
        // portrait or landscape regardless of what the template design says.
        $templateOrientation = ($page['orientation'] ?? 'L') === 'P' ? 'P' : 'L';
        $orientation = ($orientation_override === 'P' || $orientation_override === 'L')
            ? $orientation_override
            : $templateOrientation;
        $format = $page['format'] ?? 'A4';
        $width  = (float) ($page['width_mm']  ?? 297);
        $height = (float) ($page['height_mm'] ?? 210);

        // ORIENTATION-OVERRIDE-DIMENSION-FIX (v4.4.14): when the requested
        // orientation differs from the template's designed orientation, the old
        // code kept $width/$height as-is (e.g. 297×210 for a landscape template)
        // then passed orientation='P' to TCPDF.  TCPDF swapped the dimensions
        // to 210×297 portrait, but ALL element x/y positions were still designed
        // for the 297mm-wide landscape page — causing content to appear on the
        // right half with a large blank strip on the left, and elements near
        // x=297 to clip off the right edge entirely.
        //
        // Fix: swap the page dimensions to match the overridden orientation AND
        // proportionally scale every field's x/y/w/h so the layout fills the
        // new page correctly.  Font sizes are unchanged (pt is absolute).  The
        // result is a proportionally rescaled layout rather than a blank page.
        if ($orientation !== $templateOrientation) {
            $origW  = $width;
            $origH  = $height;
            $width  = $origH;              // new page width  = old page height
            $height = $origW;              // new page height = old page width
            $scaleX = $width  / $origW;    // e.g. 210/297 ≈ 0.707  (L→P)
            $scaleY = $height / $origH;    // e.g. 297/210 ≈ 1.414  (L→P)
            $scaledFields = [];
            foreach (($design['fields'] ?? []) as $field) {
                $field['x_mm'] = round(($field['x_mm'] ?? 0) * $scaleX, 2);
                $field['y_mm'] = round(($field['y_mm'] ?? 0) * $scaleY, 2);
                $field['w_mm'] = round(($field['w_mm'] ?? 0) * $scaleX, 2);
                $field['h_mm'] = round(($field['h_mm'] ?? 0) * $scaleY, 2);
                $scaledFields[] = $field;
            }
            $design['fields'] = $scaledFields;
        }

        $pdf = new \pdf($orientation, 'mm', $format, true, 'UTF-8', false);
        $pdf->SetCreator('RTO Compliance — Certificate Template Builder');
        $pdf->SetAuthor($payload['rto.name'] ?? 'Training Organisation');
        $pdf->SetTitle(($template->name ?? 'Certificate') . ' — ' . ($payload['student.fullname'] ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage($orientation, [$width, $height]);

        // Background fill colour (only paint if not pure white — TCPDF default).
        if (!empty($page['bg_color']) && strtolower($page['bg_color']) !== '#ffffff') {
            [$r, $g, $b] = self::hex_to_rgb($page['bg_color']);
            $pdf->Rect(0, 0, $width, $height, 'F', [], [$r, $g, $b]);
        }

        // Background image (full page).
        if (!empty($page['bg_image_path']) && file_exists($page['bg_image_path'])) {
            self::paint_image($pdf, $page['bg_image_path'], 0, 0, $width, $height);
        }

        // Render each field in document order.
        foreach (($design['fields'] ?? []) as $field) {
            self::render_field($pdf, $field, $payload, $width, $height);
        }

        return $pdf->Output('certificate.pdf', 'S');
    }

    /**
     * Render a single field onto the PDF at its mm coordinates.
     */
    private static function render_field(\pdf $pdf, array $field, array $payload, float $pagew, float $pageh): void {
        $kind = $field['kind'] ?? 'text';
        $x    = (float) ($field['x_mm'] ?? 0);
        $y    = (float) ($field['y_mm'] ?? 0);
        $w    = (float) ($field['w_mm'] ?? 100);
        $h    = (float) ($field['h_mm'] ?? 10);

        // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — dynamic image fields
        // (rto.logo, signatory.signature, nrt_logo, aqf_logo,
        // state_training_authority_logo) render as TCPDF images, not text.
        if ($kind === 'dynamic') {
            $dk = $field['dynamickey'] ?? '';
            // FIX-ORG-SEAL-IMAGE (v5.0.3): organisation_seal was missing from the image
            // keys list, so even when the admin uploaded a seal via the Branding panel,
            // the PDF rendered nothing (the field fell through to text rendering, which
            // returned '' for image keys and silently skipped the field).
            $imagekeys = ['rto.logo', 'signatory.signature', 'nrt_logo', 'aqf_logo', 'state_training_authority_logo', 'organisation_seal'];
            if (in_array($dk, $imagekeys, true)) {
                $imgkey = $dk . '__path';
                $imgpath = $payload[$imgkey] ?? '';
                if ($imgpath && file_exists($imgpath)) {
                    // NRT-LOGO-ASPECT (v4.4.7): pass h=0 so TCPDF auto-calculates
                    // height from the image's natural aspect ratio. With an explicit
                    // h the PNG gets stretched to fill the template box regardless of
                    // its proportions — clearly visible on landscape certs where the
                    // NRT logo appears wider than it is tall.
                    self::paint_image($pdf, $imgpath, $x, $y, $w, 0);
                }
                return;
            }
        }

        if ($kind === 'line') {
            $lw = (float) ($field['linewidth'] ?? 0.5);
            [$r, $g, $b] = self::hex_to_rgb($field['color'] ?? '#000000');
            $pdf->SetDrawColor($r, $g, $b);
            $pdf->SetLineWidth($lw);
            $pdf->Line($x, $y, $x + $w, $y + $h);
            return;
        }

        if ($kind === 'box') {
            $lw = (float) ($field['linewidth'] ?? 0.5);
            [$r, $g, $b] = self::hex_to_rgb($field['color'] ?? '#000000');
            $pdf->SetDrawColor($r, $g, $b);
            $pdf->SetLineWidth($lw);
            $pdf->Rect($x, $y, $w, $h, 'D');
            return;
        }

        if ($kind === 'image') {
            $imagepath = $field['image_path'] ?? '';
            if ($imagepath && file_exists($imagepath)) {
                self::paint_image($pdf, $imagepath, $x, $y, $w, $h);
            }
            return;
        }

        // ROR-TABLE-FIX (v5.9.246): Record of Results row-by-row rendering.
        // When kind === 'ror_table', we render the three unit columns
        // (semester, name, result) one row at a time, measuring each row's
        // required height from col2 (names) before advancing Y — so all
        // three columns stay in perfect vertical sync regardless of how
        // many lines each unit name wraps to.  The old approach of three
        // independent fixed-height MultiCells caused cols 1 & 3 to clip
        // after ~11 units whenever col2 contained long wrapping names.
        //
        // Task #128/#129: Multi-page continuation — when rows overflow the field
        // boundary, AddPage() is called and remaining rows continue from the same
        // top-of-field Y position on the new page. Optional column headers (attrs
        // col1_header, col2_header, col3_header) are rendered both at the start of
        // the table and again at the top of each continuation page (Task #129).
        if ($kind === 'ror_table') {
            $rowsjson = $payload['qualification.units_ror_rows_json'] ?? '[]';
            $rows = json_decode($rowsjson, true);
            if (!is_array($rows) || empty($rows)) {
                return;
            }

            // Column widths: stored in field attrs; gaps are auto-distributed.
            $c1w = (float)($field['col1_w'] ?? 30.0);
            $c2w = (float)($field['col2_w'] ?? 110.0);
            $c3w = (float)($field['col3_w'] ?? 36.0);
            // Distribute any remaining width as equal gaps between columns.
            $totalCols = $c1w + $c2w + $c3w;
            $gap = $totalCols < $w ? ($w - $totalCols) / 2.0 : 2.0;

            $c1x = $x;
            $c2x = $x + $c1w + $gap;
            $c3x = $x + $c1w + $gap + $c2w + $gap;

            $font     = self::sanitise_font($field['font'] ?? 'helvetica');
            $fontsize = (float)($field['fontsize'] ?? 10);
            $maxY     = $y + $h;

            // Derive page orientation from page dimensions for continuation pages.
            $pageOrientation = ($pagew > $pageh) ? 'L' : 'P';

            // Optional column header labels — re-rendered on every page (Task #129).
            $col1Header = (string)($field['col1_header'] ?? '');
            $col2Header = (string)($field['col2_header'] ?? '');
            $col3Header = (string)($field['col3_header'] ?? '');
            $hasHeaders = ($col1Header !== '' || $col2Header !== '' || $col3Header !== '');

            $pdf->SetFont($font, '', $fontsize);
            $pdf->SetTextColor(0, 0, 0);

            // Helper closure: render bold header row at $atY, return new Y after.
            $renderHeaderRow = function(float $atY) use (
                $pdf, $font, $fontsize, $c1w, $c2w, $c3w, $c1x, $c2x, $c3x,
                $col1Header, $col2Header, $col3Header
            ): float {
                $pdf->SetFont($font, 'B', $fontsize);
                $hh1 = $pdf->getStringHeight($c1w, $col1Header, false, true, '', 0);
                $hh2 = $pdf->getStringHeight($c2w, $col2Header, false, true, '', 0);
                $hh3 = $pdf->getStringHeight($c3w, $col3Header, false, true, '', 0);
                $hdrH = max($hh1, $hh2, $hh3, 5.0);
                $pdf->MultiCell($c1w, 0, $col1Header, 0, 'L', false, 0, $c1x, $atY, true, 0, false, true, $hdrH, 'T', false);
                $pdf->MultiCell($c2w, 0, $col2Header, 0, 'L', false, 0, $c2x, $atY, true, 0, false, true, $hdrH, 'T', false);
                $pdf->MultiCell($c3w, 0, $col3Header, 0, 'L', false, 0, $c3x, $atY, true, 0, false, true, $hdrH, 'T', false);
                $pdf->SetFont($font, '', $fontsize);
                return $atY + $hdrH;
            };

            $curY = $y;

            // Render headers at the top of the first page.
            if ($hasHeaders) {
                $curY = $renderHeaderRow($curY);
            }

            foreach ($rows as $row) {
                $sem    = isset($row['semester']) ? (string)$row['semester'] : '';
                $name   = isset($row['name'])     ? (string)$row['name']     : '';
                $result = isset($row['result'])   ? (string)$row['result']   : '';

                // Measure each cell's required height; the tallest governs the row.
                $h1 = $pdf->getStringHeight($c1w, $sem,    false, true, '', 0);
                $h2 = $pdf->getStringHeight($c2w, $name,   false, true, '', 0);
                $h3 = $pdf->getStringHeight($c3w, $result, false, true, '', 0);
                $rowH = max($h1, $h2, $h3, 4.0); // minimum 4 mm per row

                // Overflow → add continuation page and re-render headers (Tasks #128 & #129).
                if ($curY + $rowH > $maxY) {
                    $pdf->AddPage($pageOrientation, [$pagew, $pageh]);
                    $curY = $y;      // same field-top position on the new page
                    $maxY = $y + $h; // reset page boundary
                    if ($hasHeaders) {
                        $curY = $renderHeaderRow($curY);
                    }
                }

                // ln=0 → cursor stays; we place each cell at explicit (x, y).
                $pdf->MultiCell($c1w, 0, $sem,    0, 'L', false, 0, $c1x, $curY, true, 0, false, true, $rowH, 'T', false);
                $pdf->MultiCell($c2w, 0, $name,   0, 'L', false, 0, $c2x, $curY, true, 0, false, true, $rowH, 'T', false);
                $pdf->MultiCell($c3w, 0, $result, 0, 'L', false, 0, $c3x, $curY, true, 0, false, true, $rowH, 'T', false);

                $curY += $rowH;
            }
            return;
        }

        // text | date | dynamic — all render as text (or QR for the qrcode dynamickey).
        $text = self::resolve_text($field, $payload);

        // QR code is a special dynamic kind.
        if ($kind === 'dynamic' && ($field['dynamickey'] ?? '') === 'qrcode') {
            $verifyurl = $payload['verify.url'] ?? '';
            if ($verifyurl !== '') {
                $style = [
                    'border' => false,
                    'padding' => 0,
                    'fgcolor' => [0, 0, 0],
                    'bgcolor' => false,
                ];
                $pdf->write2DBarcode($verifyurl, 'QRCODE,M', $x, $y, min($w, $h), min($w, $h), $style, 'N');
            }
            return;
        }

        if ($text === '' || $text === null) {
            return;
        }

        $font     = $field['font'] ?? 'helvetica';
        $fontstyle= $field['fontstyle'] ?? '';
        $fontsize = (float) ($field['fontsize'] ?? 12);
        $align    = $field['align'] ?? 'L';
        [$r, $g, $b] = self::hex_to_rgb($field['color'] ?? '#000000');

        $pdf->SetFont(self::sanitise_font($font), $fontstyle, $fontsize);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY($x, $y);
        // MultiCell so long strings wrap inside the field box; isHTML=false; auto-padding 0.
        // TEXT-CLIP-FIX (v4.4.8): pass 0 as the line-height (2nd param) so TCPDF
        // auto-sizes each line from the active font size. Previously $h (the template-
        // defined field height, e.g. 10–12 mm) was passed as BOTH the line height AND
        // the maxh cap. When a large font (e.g. 28pt ≈ 9.9mm) fills nearly the entire
        // field box, TCPDF clips the bottom of the glyphs because the line-height
        // leaves no room for internal leading. With line-height=0 each line is sized
        // correctly for the font; the 14th-param maxh ($h) still caps total output to
        // the template-defined field boundary so content never overflows into adjacent
        // elements.
        $pdf->MultiCell($w, 0, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $h, 'T', false);
    }

    /**
     * v4.2.49 BUG-MAY2-AUDIT2 — paint a raster or SVG image onto the PDF.
     *
     * TCPDF's Image() only handles PNG/JPEG/GIF; SVG must use ImageSVG().
     * The bundled compliance assets (pix/nrt_logo.svg, aqf_logo.svg,
     * sta_logo.svg) are SVG, so calling Image() on them silently failed
     * and left the NRT/AQF/STA logo placeholders blank on every cert.
     */
    private static function paint_image(\pdf $pdf, string $path, float $x, float $y, float $w, float $h): void {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            if ($ext === 'svg' || $ext === 'svgz') {
                $pdf->ImageSVG($path, $x, $y, $w, $h, '', '', '', 0, false);
            } else {
                $pdf->Image($path, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0);
            }
        } catch (\Throwable $e) {
            debugging('Cert template image paint failed for ' . $path . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Resolve the visible text for a text/date/dynamic field.
     */
    private static function resolve_text(array $field, array $payload): string {
        $kind = $field['kind'] ?? 'text';

        if ($kind === 'text') {
            return (string) ($field['text'] ?? '');
        }

        if ($kind === 'date') {
            // v4.2.49 BUG-MAY2-AUDIT2 — the editor's date-format dropdown
            // offers PHP date() tokens ('d M Y', 'd/m/Y', 'D, j F Y',
            // 'F j, Y'). The previous code passed those to userdate(),
            // which interprets strftime tokens — so every date field
            // rendered as the literal format string. Use PHP date().
            $fmt = $field['dateformat'] ?? 'd M Y';
            $ts  = $payload['cert.issuedate_ts'] ?? time();
            return date($fmt, (int) $ts);
        }

        if ($kind === 'dynamic') {
            $dk = $field['dynamickey'] ?? '';
            if (isset($payload[$dk])) {
                return (string) $payload[$dk];
            }
            return '';
        }

        return (string) ($field['text'] ?? '');
    }

    /**
     * Build the dynamic-data payload used to resolve fields at render
     * time.  Pulls from the cert row, the user row, the qualification
     * (if linked), and the per-tenant USI/RTO config.
     *
     * @param \stdClass $cert  row from local_rtocompliance_certs
     * @param \stdClass $user  row from {user}
     * @return array
     */
    public static function resolve_payload(\stdClass $cert, \stdClass $user): array {
        global $CFG;

        // v4.2.48 BUG-MAY2-AUDIT — student.dob (and any other custom user
        // profile field referenced via $user->profile_field_*) is empty
        // unless the custom-fields helper has been called. Be defensive:
        // require the lib, then load fields when the user has a real id.
        if (!empty($user->id) && empty($user->profile)) {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            try {
                profile_load_custom_fields($user);
            } catch (\Throwable $e) {
                // Non-fatal — fields just stay empty.
                debugging('profile_load_custom_fields failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $rtoname        = get_config('local_rtocompliance', 'rtoname')        ?: 'Training Organisation';
        $rtocode        = get_config('local_rtocompliance', 'rtocode')        ?: '';
        $signatoryname  = get_config('local_rtocompliance', 'signatoryname')  ?: '';
        $signatorytitle = get_config('local_rtocompliance', 'signatorytitle') ?: '';
        $aqfstatement   = get_config('local_rtocompliance', 'aqfstatement')
            ?: 'This qualification is recognised within the Australian Qualifications Framework.';

        // QR codes point to the AI Grader central registry so the certificate
        // remains verifiable even if this Moodle server changes domain or goes offline.
        // Falls back to the local Moodle verify.php if no platform URL is configured.
        $platformurl = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
        $verifyurl   = $platformurl . '/verify/' . urlencode($cert->verifytoken ?? '');

        // Look up student profile for USI if available.
        $usi = '';
        if (!empty($user->id)) {
            global $DB;
            $student = $DB->get_record('local_rtocompliance_students', ['userid' => $user->id]);
            if ($student && !empty($student->usi)) {
                $usi = $student->usi;
            }
        }

        // Course/activity title for non-accredited "completion" certificates —
        // falls back to qualification.name (so a single field can be re-used
        // across template types).
        $coursetitle = '';
        if (!empty($cert->coursetitle)) {
            $coursetitle = $cert->coursetitle;
        } else if (!empty($cert->qualificationname)) {
            $coursetitle = $cert->qualificationname;
        } else if (!empty($cert->coursefullname)) {
            $coursetitle = $cert->coursefullname;
        }

        // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — pull branding image paths
        // (RTO logo + CEO signature) so the renderer can paint them where
        // the rto.logo / signatory.signature dynamic fields are placed.
        $rtologopath = cert_template::get_branding_path(cert_template::BRANDING_ITEMID_LOGO) ?? '';
        $sigpath     = cert_template::get_branding_path(cert_template::BRANDING_ITEMID_SIGNATURE) ?? '';

        // v4.4.0 NRT-LOGO-COMPLIANCE — admin-uploaded artwork is preferred
        // over the bundled fallback. NRT and AQF have ASQA-supplied PNG/JPG
        // bundled in pix/ (correct ASQA colours + typography). The two
        // generic compliance-logo slots (state_training_authority_logo,
        // organisation_seal) have no bundled fallback because they are
        // RTO-specific and the v4.2.43-v4.3.0 placeholder SVGs were not
        // legally usable. Resolution order: FA_BRANDING upload (preferred)
        // → admin_setting_configstoredfile upload → bundled fallback.
        $nrtlogopath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            \local_rtocompliance\cert_template::BRANDING_ITEMID_NRT_LOGO,
            'nrt_logo_file',
            'nrt_logo.png'
        );
        $aqflogopath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            \local_rtocompliance\cert_template::BRANDING_ITEMID_AQF_LOGO,
            'aqf_logo_file',
            'aqf_logo.jpg'
        );
        $stalogopath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            \local_rtocompliance\cert_template::BRANDING_ITEMID_STA_LOGO,
            'compliance_logo_1',
            ''
        );
        $orgsealpath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            \local_rtocompliance\cert_template::BRANDING_ITEMID_ORG_SEAL,
            'organisation_seal_file',
            ''
        );

        // FIX-SOA-DUPCODE (v5.2.70): qualificationname is sometimes stored with the
        // code already prepended (e.g. "MEM13014A Apply principles...") — either because
        // the course/unit resolution included it, or because the admin typed it that way.
        // If we naively build "CODE NAME" we get "MEM13014A MEM13014A Apply principles...".
        // Strip the code prefix (and any separating space/dash/em-dash) before building
        // the auto-generated statements. Only the human-readable name goes after the code.
        $stmt_qcode = trim((string)($cert->qualificationcode ?? ''));
        $stmt_qname = trim((string)($cert->qualificationname ?? ''));
        if ($stmt_qcode !== '' && strpos($stmt_qname, $stmt_qcode) === 0) {
            // Name starts with the code — strip it (plus any separator chars).
            $stmt_qname = ltrim(substr($stmt_qname, strlen($stmt_qcode)), " \t-\xe2\x80\x94\xe2\x80\x93"); // double-quoted: bytes not escapes
        }

        $partofstmt = '';
        if ($stmt_qcode !== '' && $stmt_qname !== '') {
            // SOA-WORDING-FIX (v5.9.265): only generate auto-statements for QUALIFICATION
            // codes (Australian pattern: 2-8 uppercase letters + 5-6 digits, e.g. BSB30120,
            // TLI50119).  Unit codes have a 3-4 digit suffix (TLIK2010, BSBCMM311) and must
            // NOT trigger these sentences — they embed a unit code into qualification wording
            // which is factually wrong ("form part of TLIK2010 Computer Applications").
            // Require at least 5 trailing digits to distinguish qual codes from unit codes.
            if (preg_match('/^[A-Z]{2,10}[0-9]{5,6}[A-Z]?$/', $stmt_qcode)) {
                $partofstmt = 'These competencies form part of ' . $stmt_qcode . ' ' . $stmt_qname . '.';
            }
        }

        // v4.6.103 FIX-CERT-UNITS-ISSUEDATE — two column-name bugs in resolve_payload():
        // (1) $cert->unitsofcompetency does not exist — the DB column is $cert->units
        //     (JSON-encoded array of {code,name,outcome}).  Read and format it.
        // (2) $cert->timeissued does not exist — the DB column is $cert->issuedate.
        //     The fallback to time() meant every template-rendered cert showed
        //     today's date instead of the actual issue date.
        // ROR-3COL-FIX (v5.9.220): also build the 3-column payload keys for
        // Record of Results (qualification.units_col_semester / _col_names / _col_results)
        // so the starter template can render Semester/Year | Units | Results correctly.
        $unitsText = '';
        $unitsSemester = '';
        $unitsNames    = '';
        $unitsResults  = '';

        // AVETMISS 8 outcome codes → human-readable labels for the Results column.
        $_outcomeLabels = [
            '20' => 'Competent',          '30' => 'Not Yet Competent',
            '40' => 'Withdrawn',          '41' => 'RPL Granted',
            '42' => 'Credit Transfer',    '51' => 'RPL Granted',
            '52' => 'RPL Not Granted',    '60' => 'RCC Granted',
            '70' => 'Continuing',         '81' => 'Non-assessable',
            '82' => 'Non-assessable',     '90' => 'Superseded',
        ];

        $issuets = !empty($cert->issuedate) ? (int)$cert->issuedate : time();

        // Derive cert-level semester as fallback when no per-unit semester is stored.
        $_certMonth = (int) date('n', $issuets);
        $_certYear  = date('Y', $issuets);
        $_certSemester = ($_certMonth <= 6 ? 'Sem 1 ' : 'Sem 2 ') . $_certYear;

        if (!empty($cert->units)) {
            $unitsArr = json_decode($cert->units, true);
            if (is_array($unitsArr)) {
                $lines = [];
                $_col1 = []; $_col2 = []; $_col3 = [];
                foreach ($unitsArr as $u) {
                    $code    = isset($u['code'])    ? trim((string)$u['code'])    : '';
                    $name    = isset($u['name'])    ? trim((string)$u['name'])    : '';
                    $outcome = isset($u['outcome']) ? trim((string)$u['outcome']) : '';

                    // SOA-DUPCODE-UNIT-FIX (v5.9.264): strip the unit code prefix from the
                    // unit name if the name already starts with the code (e.g. Moodle stores
                    // "TLIK2010 Computer Applications" as both code="TLIK2010" and
                    // name="TLIK2010 Computer Applications").  Without this the display line
                    // becomes "TLIK2010 — TLIK2010 Computer Applications" (code shown twice).
                    // Strip leading code + any separator (space, dash, em-dash, en-dash).
                    if ($code !== '' && $name !== '' && strpos($name, $code) === 0) {
                        $name = ltrim(substr($name, strlen($code)), " \t-\xe2\x80\x94\xe2\x80\x93");
                    }

                    // Flat units text (used by SOA templates and legacy RoR templates).
                    // NOTE: double-quoted "\xe2\x80\x94" = UTF-8 em dash (U+2014).
                    // Single-quoted '\xe2\x80\x94' would print the literal escape sequence — do not revert.
                    if ($code !== '' && $name !== '') {
                        $lines[] = $code . " \xe2\x80\x94 " . $name;
                    } elseif ($code !== '') {
                        $lines[] = $code;
                    } elseif ($name !== '') {
                        $lines[] = $name;
                    }

                    // 3-column keys for Record of Results.
                    // Per-unit semester is stored as 'year' or 'semester' if available;
                    // falls back to cert-level semester derived from the issue date.
                    if (!empty($u['semester'])) {
                        $_col1[] = trim((string)$u['semester']);
                    } elseif (!empty($u['year'])) {
                        $_col1[] = trim((string)$u['year']);
                    } else {
                        $_col1[] = $_certSemester;
                    }

                    if ($code !== '' && $name !== '') {
                        $_col2[] = $code . ' — ' . $name;
                    } elseif ($code !== '') {
                        $_col2[] = $code;
                    } else {
                        $_col2[] = $name;
                    }

                    $_col3[] = $_outcomeLabels[$outcome] ?? ($outcome !== '' ? $outcome : '');
                }
                $unitsText     = implode("\n", $lines);
                $unitsSemester = implode("\n", $_col1);
                $unitsNames    = implode("\n", $_col2);
                $unitsResults  = implode("\n", $_col3);

                // ROR-TABLE-FIX (v5.9.246): build the structured row array for the
                // ror_table field kind so the renderer can do row-by-row height-sync.
                $_rorRows = [];
                foreach ($unitsArr as $i => $u) {
                    $_rorRows[] = [
                        'semester' => isset($_col1[$i]) ? $_col1[$i] : '',
                        'name'     => isset($_col2[$i]) ? $_col2[$i] : '',
                        'result'   => isset($_col3[$i]) ? $_col3[$i] : '',
                    ];
                }
                $unitsRorRowsJson = json_encode($_rorRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } elseif (!empty($cert->certtype) && in_array($cert->certtype, ['record', 'statement'], true)
                  && !empty($cert->qualificationcode) && !empty($cert->userid)) {
            // ROR-RENDER-FALLBACK (v5.9.254 + v5.9.264): certs saved before v5.9.226 have
            // units=NULL because the auto-population fix only applied to new issuances.
            // Also covers the case where check_qualification_completion() returned empty at
            // issue time (e.g. programcode mismatch in the enrolments table).
            // v5.9.264 extends this fallback to certtype='statement' (SOA) certs — previously
            // only 'record' was covered, so old SOA certs with units=NULL showed no unit list.
            // Fetch units at render time from local_rtocompliance_enrolments so every
            // download/pack/view of an existing cert shows the correct unit table.
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_rtocompliance_students') &&
                $dbman->table_exists('local_rtocompliance_enrolments')) {
                $_stud = $DB->get_record('local_rtocompliance_students',
                    ['userid' => (int)$cert->userid], 'id', IGNORE_MISSING);
                if ($_stud) {
                    require_once(__DIR__ . '/certificate_validator.php');
                    $_comp = \local_rtocompliance\certificate_validator::check_qualification_completion(
                        $_stud->id, $cert->qualificationcode
                    );
                    // SOA-UNITCODE-FALLBACK (v5.9.265): if qualificationcode is a single unit
                    // code (old-style single-unit SOA), check_qualification_completion() returns
                    // empty because it looks up by programcode.  Try a direct enrolment lookup
                    // by unitcode = qualificationcode so the unit appears on the cert.
                    if (empty($_comp['units']) && $dbman->table_exists('local_rtocompliance_enrolments')) {
                        $_unitEnrols = $DB->get_records_select(
                            'local_rtocompliance_enrolments',
                            'studentid = :sid AND unitcode = :uc',
                            ['sid' => $_stud->id, 'uc' => $cert->qualificationcode],
                            'id ASC',
                            'unitcode, unitname, outcomeidentifier, activitystartdate'
                        );
                        if (!empty($_unitEnrols)) {
                            $_comp = ['units' => []];
                            foreach ($_unitEnrols as $_ue) {
                                $_comp['units'][] = [
                                    'code'    => trim((string)($_ue->unitcode    ?? '')),
                                    'name'    => trim((string)($_ue->unitname    ?? '')),
                                    'outcome' => trim((string)($_ue->outcomeidentifier ?? '20')),
                                ];
                            }
                        }
                    }

                    if (!empty($_comp['units'])) {
                        $lines  = []; $_col1 = []; $_col2 = []; $_col3 = []; $_rorRows = [];
                        foreach ($_comp['units'] as $_u) {
                            $_code    = isset($_u['code'])     ? trim((string)$_u['code'])    : '';
                            $_name    = isset($_u['name'])     ? trim((string)$_u['name'])    : '';
                            $_outcome = isset($_u['outcome'])  ? trim((string)$_u['outcome']) : '20';
                            $_sem     = isset($_u['semester']) ? trim((string)$_u['semester']): $_certSemester;
                            // SOA-DUPCODE-UNIT-FIX (v5.9.264): strip code prefix from name (same as primary branch).
                            if ($_code !== '' && $_name !== '' && strpos($_name, $_code) === 0) {
                                $_name = ltrim(substr($_name, strlen($_code)), " \t-\xe2\x80\x94\xe2\x80\x93");
                            }
                            if ($_code !== '' && $_name !== '') {
                                $lines[] = $_code . " \xe2\x80\x94 " . $_name;
                            } elseif ($_code !== '') { $lines[] = $_code; }
                            else { $lines[] = $_name; }
                            $_col1[] = $_sem;
                            if ($_code !== '' && $_name !== '') {
                                $_col2[] = $_code . ' — ' . $_name;
                            } elseif ($_code !== '') { $_col2[] = $_code; }
                            else { $_col2[] = $_name; }
                            $_col3[]    = $_outcomeLabels[$_outcome] ?? ($_outcome !== '' ? $_outcome : '');
                            $_rorRows[] = ['semester' => $_sem, 'name' => end($_col2), 'result' => end($_col3)];
                        }
                        $unitsText        = implode("\n", $lines);
                        $unitsSemester    = implode("\n", $_col1);
                        $unitsNames       = implode("\n", $_col2);
                        $unitsResults     = implode("\n", $_col3);
                        $unitsRorRowsJson = json_encode($_rorRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            }
        }

        return [
            'student.fullname'                          => fullname($user),
            'student.usi'                               => $usi,
            'student.dob'                               => isset($user->profile_field_dob) ? $user->profile_field_dob : '',
            'qualification.code'                        => $cert->qualificationcode ?? '',
            'qualification.name'                        => $cert->qualificationname ?? '',
            'qualification.units'                       => $unitsText,
            // ROR-3COL-FIX (v5.9.220): 3 separate column keys for Record of Results.
            // Use these in the template design instead of qualification.units so that
            // Semester/Year, Unit name, and Result each render in their own column.
            'qualification.units_col_semester'          => $unitsSemester,
            'qualification.units_col_names'             => $unitsNames,
            'qualification.units_col_results'           => $unitsResults,
            // ROR-TABLE-FIX (v5.9.246): structured row array for ror_table field kind.
            'qualification.units_ror_rows_json'         => $unitsRorRowsJson ?? '[]',
            'qualification.partofstatement'             => $partofstmt,
            // ASQA-AUDIT-2 (v5.2.44) — auto-generate with qualification code inserted before
            // "course" per ASQA Sample Forms fact sheet p.4:
            // "These competencies were attained in completion of {CODE} course in {NAME}."
            // Previously sourced from a static admin setting which meant the qual code
            // had to be typed manually and could drift out of sync with the cert record.
            // FIX-SOA-DUPCODE (v5.2.70): use the deduped $stmt_qcode/$stmt_qname vars
            // built above so the code never appears twice in this sentence either.
            // SOA-WORDING-FIX (v5.9.265): same 5-digit-suffix guard as $partofstmt above —
            // only generate for qualification codes, never for unit codes.
            'qualification.completionofcoursestatement' => (
                $stmt_qcode !== '' && $stmt_qname !== '' && preg_match('/^[A-Z]{2,10}[0-9]{5,6}[A-Z]?$/', $stmt_qcode)
                    ? 'These competencies were attained in completion of ' . $stmt_qcode . ' course in ' . $stmt_qname . '.'
                    : ''
            ),
            'cert.coursetitle'                          => $coursetitle,
            'cert.number'                               => $cert->certnumber ?? '',
            'cert.issuedate'                            => userdate($issuets, '%d %B %Y'),
            'cert.issuedate_ts'                         => $issuets,
            'cert.completiondate'                       => !empty($cert->timecompleted) ? userdate($cert->timecompleted, '%d %B %Y') : '',
            'rto.name'                                  => $rtoname,
            'rto.code'                                  => $rtocode,
            'rto.logo'                                  => '',
            'rto.logo__path'                            => $rtologopath,
            'signatory.name'                            => $signatoryname,
            'signatory.title'                           => $signatorytitle,
            'signatory.signature'                       => '',
            'signatory.signature__path'                 => $sigpath,
            // Mandatory phrases (typed text fields).
            // FIX-MANDATORY-WORDING (v5.2.38): read from admin settings so RTOs can
            // adjust wording (e.g. capitalisation) without editing code. Leave blank
            // in settings to use the built-in ASQA default wording.
            'certify_statement'                         => get_config('local_rtocompliance', 'certify_statement') ?: 'This is to certify that',
            'attained_statement'                        => get_config('local_rtocompliance', 'attained_statement') ?: 'has fulfilled the requirements for',
            // ASQA SoA fact sheet (p.4): SoA uses different phrases to testamur.
            'soa_intro_statement'                       => get_config('local_rtocompliance', 'soa_intro_statement') ?: 'This is a statement that',
            'soa_attained_statement'                    => get_config('local_rtocompliance', 'soa_attained_statement') ?: 'has attained',
            'statement_of_attainment_heading'           => get_config('local_rtocompliance', 'statement_of_attainment_heading') ?: 'Statement of Attainment',
            'record_of_results_heading'                 => get_config('local_rtocompliance', 'record_of_results_heading') ?: 'Record of Results',
            'aqf_statement'                             => $aqfstatement,
            'not_a_testamur_statement'                  => get_config('local_rtocompliance', 'not_a_testamur_statement') ?: 'A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS. THIS IS NOT A TESTAMUR.',
            // Compliance.
            'nrt_logo'                                  => '',
            'nrt_logo__path'                            => $nrtlogopath,
            'aqf_logo'                                  => '',
            'aqf_logo__path'                            => $aqflogopath,
            'state_training_authority_logo'             => '',
            'state_training_authority_logo__path'       => $stalogopath,
            'organisation_seal'                         => '',
            'organisation_seal__path'                   => $orgsealpath,
            'authenticity_measure'                      => 'Verify at: ' . $verifyurl,
            // ASQA-COMPLIANCE-PASS-2 (v4.2.59) — optional descriptors now
            // sourced from admin settings (ASQA-mandated certificate elements
            // page) so RTOs configure them once per site.
            'industry_descriptor'                       => get_config('local_rtocompliance', 'industrydescriptor')      ?: '',
            'occupational_stream'                       => get_config('local_rtocompliance', 'occupationalstream')      ?: '',
            'australian_apprenticeship'                 => get_config('local_rtocompliance', 'apprenticeshipstatement') ?: '',
            'language_statement'                        => get_config('local_rtocompliance', 'languagestatement')       ?: '',
            'skill_set_statement'                       => get_config('local_rtocompliance', 'skillsetstatement')       ?: '',
            'verify.url'                                => $verifyurl,
        ];
    }

    /**
     * Sample payload for the in-editor preview — used by
     * cert_template_preview.php so the admin can see what the template
     * looks like with realistic data before activating.
     */
    public static function sample_payload(string $certtype): array {
        global $CFG;
        $issuets = time();

        // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — branding paths.
        // v4.4.0 NRT-LOGO-COMPLIANCE — admin upload preferred, bundled
        // ASQA artwork (.png/.jpg) as fallback. STA + organisation seal
        // are admin-upload-only (no bundled fallback).
        $rtologopath = cert_template::get_branding_path(cert_template::BRANDING_ITEMID_LOGO) ?? '';
        $sigpath     = cert_template::get_branding_path(cert_template::BRANDING_ITEMID_SIGNATURE) ?? '';
        $nrtlogopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_NRT_LOGO, 'nrt_logo_file', 'nrt_logo.png');
        $aqflogopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_AQF_LOGO, 'aqf_logo_file', 'aqf_logo.jpg');
        $stalogopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_STA_LOGO, 'compliance_logo_1', '');
        $orgsealpath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_ORG_SEAL, 'organisation_seal_file', '');

        $shared = [
            'rto.logo__path'                       => $rtologopath,
            'signatory.signature__path'            => $sigpath,
            'nrt_logo__path'                       => $nrtlogopath,
            'aqf_logo__path'                       => $aqflogopath,
            'state_training_authority_logo__path'  => $stalogopath,
            'organisation_seal__path'              => $orgsealpath,
            'rto.logo'                             => '',
            'signatory.signature'                  => '',
            'nrt_logo'                             => '',
            'aqf_logo'                             => '',
            'state_training_authority_logo'        => '',
            'organisation_seal'                    => '',
            'certify_statement'                    => 'This is to certify that',
            'attained_statement'                   => 'has fulfilled the requirements for',
            'soa_intro_statement'                  => 'This is a statement that',
            'soa_attained_statement'               => 'has attained',
            'statement_of_attainment_heading'      => 'Statement of Attainment',
            'record_of_results_heading'            => 'Record of Results',
            'authenticity_measure'                 => 'Verify at: ' . rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/') . '/verify/CERT-2026-PREVIEW',
            'industry_descriptor'                  => 'Business Services',
            'occupational_stream'                  => '',
            'australian_apprenticeship'            => '',
            'language_statement'                   => 'These unit/modules have been delivered and assessed in [insert language].',
            'skill_set_statement'                  => '',
            'qualification.partofstatement'        => 'These competencies form part of BSB30120 Certificate III in Business.',
            'qualification.completionofcoursestatement' => 'These competencies were attained in completion of BSB30120 course in Certificate III in Business.',
        ];

        // Certificate-of-Completion (non-accredited) — different sample data
        // emphasising course title rather than qualification code/units.
        if ($certtype === 'completion') {
            return array_merge($shared, [
                'student.fullname'           => 'Jane Citizen',
                'student.usi'                => '',
                'student.dob'                => '',
                'qualification.code'         => '',
                'qualification.name'         => 'Workplace First Aid (Non-Accredited)',
                'qualification.units'        => '',
                'cert.coursetitle'           => 'Workplace First Aid (Non-Accredited)',
                'cert.number'                => 'COMP-2026-PREVIEW',
                'cert.issuedate'             => userdate($issuets, '%d %B %Y'),
                'cert.issuedate_ts'          => $issuets,
                'cert.completiondate'        => userdate($issuets - (3 * DAYSECS), '%d %B %Y'),
                'rto.name'                   => get_config('local_rtocompliance', 'rtoname')        ?: 'National Compliance Training',
                'rto.code'                   => get_config('local_rtocompliance', 'rtocode')        ?: '',
                'signatory.name'             => get_config('local_rtocompliance', 'signatoryname')  ?: 'Dr A. Authorised',
                'signatory.title'            => get_config('local_rtocompliance', 'signatorytitle') ?: 'Course Coordinator',
                'aqf_statement'              => '',
                'not_a_testamur_statement'   => '',
                'verify.url'                 => rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/') . '/verify/COMP-2026-PREVIEW',
            ]);
        }

        // Record of Results sample includes USI; testamur/SOA samples
        // intentionally blank USI so a sneaky author who drags the field
        // onto a testamur preview gets visual confirmation of nothing
        // (validator still hard-blocks Submit-for-approval).
        $usisample = ($certtype === 'record') ? 'AB12CD34EF' : '';

        return array_merge($shared, [
            'student.fullname'           => 'Jane Citizen',
            'student.usi'                => $usisample,
            'student.dob'                => '01/01/1990',
            'qualification.code'         => 'BSB30120',
            'qualification.name'         => 'Certificate III in Business',
            'qualification.units'        => "BSBCMM311 — Apply critical thinking skills in a team environment\nBSBCRT311 — Apply critical thinking skills\nBSBPEF301 — Organise personal work priorities\nBSBSUS211 — Participate in sustainable work practices\nBSBTWK301 — Use inclusive work practices",
            // ROR-3COL-FIX (v5.9.220): 3-column payload for the Record of Results preview.
            'qualification.units_col_semester' => "Sem 1 2024\nSem 1 2024\nSem 2 2024\nSem 2 2024\nSem 1 2025",
            'qualification.units_col_names'    => "BSBCMM311 — Apply critical thinking skills in a team environment\nBSBCRT311 — Apply critical thinking skills\nBSBPEF301 — Organise personal work priorities\nBSBSUS211 — Participate in sustainable work practices\nBSBTWK301 — Use inclusive work practices",
            'qualification.units_col_results'  => "Competent\nCompetent\nCompetent\nCompetent\nCompetent",
            // ROR-TABLE-FIX (v5.9.246): structured row array for ror_table field kind.
            'qualification.units_ror_rows_json' => json_encode([
                ['semester' => 'Sem 1 2024', 'name' => 'BSBCMM311 — Apply critical thinking skills in a team environment', 'result' => 'Competent'],
                ['semester' => 'Sem 1 2024', 'name' => 'BSBCRT311 — Apply critical thinking skills',                     'result' => 'Competent'],
                ['semester' => 'Sem 2 2024', 'name' => 'BSBPEF301 — Organise personal work priorities',                  'result' => 'Competent'],
                ['semester' => 'Sem 2 2024', 'name' => 'BSBSUS211 — Participate in sustainable work practices',          'result' => 'Competent'],
                ['semester' => 'Sem 1 2025', 'name' => 'BSBTWK301 — Use inclusive work practices',                       'result' => 'Competent'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cert.coursetitle'           => 'Certificate III in Business',
            'cert.number'                => 'CERT-2026-PREVIEW',
            'cert.issuedate'             => userdate($issuets, '%d %B %Y'),
            'cert.issuedate_ts'          => $issuets,
            'cert.completiondate'        => userdate($issuets - (15 * DAYSECS), '%d %B %Y'),
            'rto.name'                   => get_config('local_rtocompliance', 'rtoname')        ?: 'National Compliance Training',
            'rto.code'                   => get_config('local_rtocompliance', 'rtocode')        ?: '50918',
            'signatory.name'             => get_config('local_rtocompliance', 'signatoryname')  ?: 'Dr A. Authorised',
            'signatory.title'            => get_config('local_rtocompliance', 'signatorytitle') ?: 'Chief Executive Officer',
            'aqf_statement'              => get_config('local_rtocompliance', 'aqfstatement')   ?: 'This qualification is recognised within the Australian Qualifications Framework.',
            'not_a_testamur_statement'   => get_config('local_rtocompliance', 'not_a_testamur_statement') ?: 'A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS. THIS IS NOT A TESTAMUR.',
            'verify.url'                 => rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/') . '/verify/CERT-2026-PREVIEW',
        ]);
    }

    /**
     * Helper: parse #rrggbb to [r, g, b] integers 0–255.
     */
    private static function hex_to_rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [0, 0, 0];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * TCPDF accepts only a small set of font names without registering
     * extras.  Coerce arbitrary user input to one of the safe defaults.
     */
    private static function sanitise_font(string $font): string {
        $font = strtolower(trim($font));
        $allowed = ['helvetica', 'times', 'courier'];
        return in_array($font, $allowed, true) ? $font : 'helvetica';
    }

    /**
     * Resolve background image fileitemid → server filesystem path that
     * TCPDF can read.  Called by the renderer/preview page when assembling
     * the final design before render().
     *
     * @param int $itemid
     * @return string|null path or null if not found
     */
    public static function resolve_bg_image_path(?int $itemid): ?string {
        if (empty($itemid)) {
            return null;
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_rtocompliance', cert_template::FA_BG, $itemid, 'sortorder, filename', false);
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            // Copy to a temp file so TCPDF (which expects a real path) can read it.
            $tmp = make_request_directory() . '/' . $file->get_filename();
            $file->copy_content_to($tmp);
            return $tmp;
        }
        return null;
    }

    /**
     * Same for per-field uploaded images.
     */
    public static function resolve_field_image_path(?int $itemid): ?string {
        if (empty($itemid)) {
            return null;
        }
        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_rtocompliance', cert_template::FA_IMAGE, $itemid, 'sortorder, filename', false);
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $tmp = make_request_directory() . '/' . $file->get_filename();
            $file->copy_content_to($tmp);
            return $tmp;
        }
        return null;
    }

    /**
     * Hydrate a design array's bg_image_path and per-field image_path
     * fields by resolving their itemids to TCPDF-readable filesystem
     * paths.  Call this immediately before render() to keep the model
     * (which only stores itemids) clean.
     *
     * @param array $design
     * @return array hydrated design
     */
    public static function hydrate_image_paths(array $design): array {
        if (!empty($design['page']['bg_itemid'])) {
            $path = self::resolve_bg_image_path((int) $design['page']['bg_itemid']);
            if ($path) {
                $design['page']['bg_image_path'] = $path;
            }
        }
        if (!empty($design['fields']) && is_array($design['fields'])) {
            foreach ($design['fields'] as &$field) {
                if (($field['kind'] ?? '') === 'image' && !empty($field['imageitemid'])) {
                    $path = self::resolve_field_image_path((int) $field['imageitemid']);
                    if ($path) {
                        $field['image_path'] = $path;
                    }
                }
            }
            unset($field);
        }
        return $design;
    }
}
