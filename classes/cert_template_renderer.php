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
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/cert_template.php');

class cert_template_renderer {
    /**
     * ROR-PAGE-COUNT (v5.9.350): how many pages the ror_table field occupied in
     * the most recently rendered certificate.  Reset to 1 at the start of every
     * render() call so callers can read it immediately after render() returns.
     * Incremented inside render_field() each time a ror_table continuation page
     * is added.  Value is always ≥ 1 (1 = single-page, i.e. no overflow).
     *
     * @var int
     */
    public static $last_ror_page_count = 1;

    /**
     * Render a template to raw PDF bytes.
     *
     * @param \stdClass $template      template row
     * @param array     $payload       resolved dynamic data (see resolve_payload())
     * @return string raw PDF bytes
     */
    public static function render(\stdClass $template, array $payload, string $orientation_override = '', bool $singlepageview = false): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        // ROR-PAGE-COUNT (v5.9.350): reset before every render so the caller
        // always sees the page count for THIS certificate, not a stale value.
        self::$last_ror_page_count = 1;

        $design = cert_template::decode_design($template);
        // v4.2.48 BUG-MAY2-AUDIT — hydrate image filesystem paths from
        // stored itemids BEFORE rendering. Without this, $page['bg_image_path']
        // and per-field image_path keys are never set and the renderer
        // silently skips every uploaded background and per-field image.
        $design = self::hydrate_image_paths($design);
        // v5.9.361: guarantee the mandatory certificate number + verification QR on
        // every render, including older templates saved before this rule.
        $design = cert_template::ensure_mandatory_fields($design);
        // STUDENT-DETAILS-TABLE AUTO-ADOPT (v6.2.52): on a Record of Results, upgrade the legacy
        // stacked identity rows ("Name of student:" etc.) into the shaded student details table
        // at render time so existing templates show the new table without a rebuild. No-op when
        // the table is already present or the layout is non-standard; never mutates saved data.
        if ((string)($payload['certtype'] ?? '') === 'record') {
            $design = cert_template::upgrade_record_identity_to_table($design);
        }
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
        if ($singlepageview) {
            // LIVE-PREVIEW-VIEW (v6.2.25): open the PDF single-page, fit-to-page and with
            // NO thumbnail/bookmark side panel, so the in-editor live preview shows the whole
            // certificate at once instead of the browser viewer's dual (thumbnail) layout.
            $pdf->SetDisplayMode('fullpage', 'SinglePage', 'UseNone');
        }
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage($orientation, [$width, $height]);

        // Background image — two sources resolved in priority order:
        // 1. Template-specific background (bg_image_path set in the template design_json).
        // 2. v5.9.320 CERT-ASSETS system-wide background from RTO Settings (cert.background__path),
        //    applied as a fallback ONLY when the template has no own background set.  This lets
        //    admins upload a single watermark/texture that appears on e.g. testamur only.
        $bg_to_paint = '';
        if (!empty($page['bg_image_path']) && file_exists($page['bg_image_path'])) {
            $bg_to_paint = $page['bg_image_path'];
        } elseif (!empty($payload['cert.background__path']) && file_exists($payload['cert.background__path'])) {
            $bg_to_paint = $payload['cert.background__path'];
        }

        // ROR-CONTINUATION-BG (v5.9.349): paint_page_background() is now the
        // single place that applies background colour + image; called here for
        // page 1 and reused by render_field() for every ror_table continuation
        // page so branded watermarks appear consistently throughout.
        self::paint_page_background($pdf, $page, $bg_to_paint, $width, $height);

        // F4 (v5.9.390): render-time compliance backstop. Approval-time validation
        // already blocks forbidden elements, but a pinned template, an override, or a
        // legacy design could still carry one. Drop any element that is forbidden for
        // THIS certificate type before it can reach the PDF: the NRT (nationally
        // recognised training) logo must never appear on a Record of Results or a
        // non-accredited Completion certificate, and the USI must never appear on a
        // Testamur or Statement of Attainment.
        $rendercerttype = (string) ($payload['certtype'] ?? '');
        $forbiddenkeys = [
            'record'     => ['nrt_logo'],
            'completion' => ['nrt_logo'],
            'attendance' => ['nrt_logo'],
            'testamur'   => ['student.usi'],
            'statement'  => ['student.usi'],
        ];
        $blocked = $forbiddenkeys[$rendercerttype] ?? [];

        // Render each field in document order.
        foreach (($design['fields'] ?? []) as $field) {
            if ($blocked && in_array(($field['dynamickey'] ?? ''), $blocked, true)) {
                continue; // Forbidden element for this cert type — never paint it.
            }
            self::render_field($pdf, $field, $payload, $width, $height, $page, $bg_to_paint);
        }

        return $pdf->Output('certificate.pdf', 'S');
    }

    /**
     * ROR-CONTINUATION-BG (v5.9.349): paint the page background colour and/or
     * image onto the current TCPDF page.  Extracted from render() so that the
     * ror_table continuation-page logic in render_field() can reuse it without
     * duplicating the colour/image painting rules.
     *
     * @param \pdf   $pdf          active TCPDF instance
     * @param array  $page         $design['page'] config array
     * @param string $bg_to_paint  resolved filesystem path for background image ('' = none)
     * @param float  $width        page width in mm
     * @param float  $height       page height in mm
     */
    private static function paint_page_background(\pdf $pdf, array $page, string $bg_to_paint, float $width, float $height): void {
        // Background fill colour (only paint if not pure white — TCPDF default).
        if (!empty($page['bg_color']) && strtolower($page['bg_color']) !== '#ffffff') {
            [$r, $g, $b] = self::hex_to_rgb($page['bg_color']);
            $pdf->Rect(0, 0, $width, $height, 'F', [], [$r, $g, $b]);
        }
        if ($bg_to_paint !== '') {
            self::paint_image($pdf, $bg_to_paint, 0, 0, $width, $height);
        }
    }

    /**
     * Render a single field onto the PDF at its mm coordinates.
     *
     * @param \pdf   $pdf          active TCPDF instance
     * @param array  $field        field definition from the template design
     * @param array  $payload      resolved dynamic data
     * @param float  $pagew        page width in mm (used when adding continuation pages)
     * @param float  $pageh        page height in mm
     * @param array  $page         $design['page'] config (for continuation-page backgrounds)
     * @param string $bg_to_paint  resolved background image path ('' = none)
     */
    private static function render_field(\pdf $pdf, array $field, array $payload, float $pagew, float $pageh, array $page = [], string $bg_to_paint = ''): void {
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
            $imagekeys = ['rto.logo', 'rto.secondary_logo', 'signatory.signature', 'nrt_logo', 'aqf_logo', 'state_training_authority_logo', 'compliance_logo_2', 'organisation_seal'];
            if (in_array($dk, $imagekeys, true)) {
                $imgkey = $dk . '__path';
                $imgpath = $payload[$imgkey] ?? '';
                if ($imgpath && file_exists($imgpath)) {
                    // FIT-CENTRE-ALL-LOGOS (v6.2.28): render EVERY logo / seal / signature
                    // fitted inside its w×h box with aspect preserved and centred (TCPDF 'CM').
                    // Previously only nrt_logo/organisation_seal used fit-box, while rto.logo,
                    // the secondary logo, signature and the aqf/state/compliance logos were
                    // width-locked with auto height (h passed as 0). That ignored the box
                    // HEIGHT — so the PDF diverged from the editor canvas (which fits inside
                    // the box) and an auto-design-detected box could overflow downward. Fit-box
                    // respects BOTH the width and the height the author set, never distorts the
                    // artwork, and matches the on-screen preview exactly.
                    self::paint_image_fit($pdf, $imgpath, $x, $y, $w, $h);
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
        // ROR-OVERFLOW-CONTINUATION (v5.9.348): when a qualification has more
        // rows than fit in the ror_table field (e.g. 30+ units), the renderer
        // now adds continuation pages automatically instead of silently clipping.
        // Each continuation page uses the same field area ($x/$y/$w/$h) so the
        // table columns remain aligned without any template changes. Existing
        // single-page records (< ~25 units) are completely unaffected.
        // STYLE-A-UNITS-TABLE (v5.9.447): the ror_table field kind now draws the
        // shaded 3-column units table — Unit Code | Unit Title | Completion Date —
        // with a coloured header bar, white bold headings, zebra rows and thin
        // borders. Column widths come from the field's col1_w/col2_w/col3_w
        // (reinterpreted as code | title | date). Applies to the Record of Results.
        if ($kind === 'ror_table') {
            // ROR-5COL (v6.2.51): a Record of Results (col3mode='result') now renders the
            // full ASQA-mapped five-column table — Enrolment Date | Unit Code | Unit Title |
            // Result | Completion Date — with a result-code legend beneath. Statements of
            // Attainment (col3mode='date') keep the existing three-column layout unchanged.
            if (($field['col3mode'] ?? 'date') === 'result') {
                self::render_ror_full_table($pdf, $field, $payload, $pagew, $pageh, $page, $bg_to_paint);
                return;
            }
            $c1w = (float)($field['col1_w'] ?? 30.0);
            $c2w = (float)($field['col2_w'] ?? 110.0);
            $c3w = (float)($field['col3_w'] ?? 36.0);
            self::render_units_table($pdf, $field, $payload, $pagew, $pageh, $page, $bg_to_paint, [$c1w, $c2w, $c3w]);
            return;
        }

        // STUDENT-DETAILS-TABLE (v6.2.51): the Record of Results header identity block —
        // STUDENT NAME | USI | QUALIFICATION — rendered as one shaded three-column table
        // (matching the units table styling) instead of stacked "Name of student:" lines.
        if ($kind === 'dynamic' && ($field['dynamickey'] ?? '') === 'student.detailstable') {
            self::render_student_details_table($pdf, $field, $payload);
            return;
        }

        // STYLE-A-SOA-ROUTE (v5.9.447): the Statement of Attainment templates carry
        // the unit list as a dynamic 'qualification.units' text field. Route it to
        // the same shaded table so the SoA gets the identical layout with no
        // template changes. Column widths are derived from the field box width
        // (code ~26mm, date ~30mm, title fills the rest). If no structured rows
        // exist, fall through to the legacy flat-text rendering below.
        if ($kind === 'dynamic' && ($field['dynamickey'] ?? '') === 'qualification.units') {
            $traw = $payload['qualification.units_table_rows_json'] ?? '[]';
            $trows = json_decode($traw, true);
            if (is_array($trows) && !empty($trows)) {
                $codew = 34.0;   // wider code column for the 12pt unit code
                $datew = 30.0;
                $titlew = max(40.0, $w - $codew - $datew);
                self::render_units_table($pdf, $field, $payload, $pagew, $pageh, $page, $bg_to_paint, [$codew, $titlew, $datew]);
                return;
            }
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
                // QR-CENTRE (v5.9.449): draw the QR square (30mm on the updated
                // templates, or the box size if smaller) centred within the field box.
                $qs = min($w, $h);
                $qx = $x + ($w - $qs) / 2.0;
                $qy = $y + ($h - $qs) / 2.0;
                $pdf->write2DBarcode($verifyurl, 'QRCODE,M', $qx, $qy, $qs, $qs, $style, 'N');
            }
            return;
        }

        if ($text === '' || $text === null) {
            return;
        }

        $font     = $field['font'] ?? 'helvetica';
        $fontstyle= $field['fontstyle'] ?? '';
        // NO-MIN-FONT (v6.2.52): the forced 12pt minimum was removed — the author's chosen font
        // size is honoured exactly on every certificate. (The MultiCell maxh cap $h still
        // contains anything that would overflow the field box.)
        $fontsize = (float) ($field['fontsize'] ?? 12);
        if ($fontsize <= 0) { $fontsize = 12.0; }
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
        // NO-MIN-HEIGHT (v6.2.52): honour the author's field height exactly (the 10mm floor was
        // tied to the old 12pt minimum, now removed). A tiny epsilon keeps maxh positive.
        $maxh = max(1.0, (float) $h);
        $pdf->MultiCell($w, 0, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, $maxh, 'T', false);
    }

    /**
     * STYLE-A-UNITS-TABLE (v5.9.447) — draw the shaded 3-column units table used
     * on both the Statement of Attainment and the Record of Results.
     *
     * Layout (Style A):
     *   • Header bar filled with the admin 'certheadercolour' (default = site
     *     brand colour), white bold labels: Unit Code | Unit Title | Completion Date.
     *   • Body rows: zebra striping (alternate light fill), thin grey cell borders.
     *   • Columns: Unit Code (left), Unit Title (left-aligned), Completion Date
     *     (centre). Row height auto-syncs to the tallest cell (the title wraps).
     *   • Overflow spills onto continuation pages that repeat the header row and
     *     the branded background, so long qualifications never clip.
     *   • A 12pt minimum font floor is enforced (per the "minimum 12" requirement).
     *
     * @param array $colw [code_w, title_w, date_w] in mm (scaled to fill the box).
     */
    private static function render_units_table(\pdf $pdf, array $field, array $payload, float $pagew, float $pageh, array $page, string $bg_to_paint, array $colw): void {
        $rows = json_decode($payload['qualification.units_table_rows_json'] ?? '[]', true);
        if (!is_array($rows) || empty($rows)) {
            return;
        }

        $x = (float)($field['x_mm'] ?? 0);
        $y = (float)($field['y_mm'] ?? 0);
        $w = (float)($field['w_mm'] ?? 180);
        $h = (float)($field['h_mm'] ?? 115);

        // WIDER-CODE-COL (v5.9.449): the unit code column is held at a minimum width so
        // a 12pt unit code (up to ~12 characters) sits on ONE line; the title column
        // takes whatever remains and wraps long unit names onto a second line. The date
        // column keeps its requested width. Widths fill the box exactly (no scaling).
        $c1w = max((float)($colw[0] ?? 34.0), 34.0);   // Unit Code — wide enough for a 12pt code.
        $c3w = (float)($colw[2] ?? 30.0);              // Completion Date.
        $c2w = $w - $c1w - $c3w;                        // Unit Title fills the rest.
        if ($c2w < 30.0) {                              // Guard on a very narrow box.
            $c2w = max(30.0, $w - $c1w);
            $c3w = max(0.0, $w - $c1w - $c2w);
        }
        $c1x = $x;
        $c2x = $x + $c1w;
        $c3x = $x + $c1w + $c2w;

        $font   = self::sanitise_font($field['font'] ?? 'helvetica');
        // NO-MIN-FONT (v6.2.52): honour the author's chosen size — no forced 12pt floor.
        $basefs = (float)($field['fontsize'] ?? 11);
        if ($basefs <= 0) { $basefs = 11.0; }

        // Colours.
        [$hr, $hg, $hb] = self::hex_to_rgb($payload['cert.header_colour'] ?? '#0f6cbf');
        $zebra  = [246, 248, 251];   // very light blue-grey for alternate rows.
        $border = [203, 213, 225];   // thin slate-200 cell borders.
        $bodytx = [30, 41, 59];      // slate-800 body text.

        $padx = 1.6;
        $pdf->setCellPaddings($padx, 1.0, $padx, 1.0);

        // ROR-RESULTS-COLUMN (v6.2.9): the third column shows the assessment RESULT for a
        // Record of Results (col3mode='result') and the completion date for a Statement of
        // Attainment (default 'date').
        $col3mode = (($field['col3mode'] ?? 'date') === 'result') ? 'result' : 'date';
        // SoA date column header simply reads "DATE" (v6.2.52, per request).
        $c3head   = ($col3mode === 'result') ? 'RESULTS' : 'DATE';

        // AUTO-FIT (v6.2.52): shrink the body font (from the author's size down to a low floor)
        // so the fixed columns — unit code and the date/result column — always fit on ONE line;
        // the unit title wraps. Guarantees the unit code can never be clipped or truncated.
        $fitcells = [];
        foreach ($rows as $r) {
            $fitcells[] = ['text' => (string)($r['code'] ?? ''), 'w' => $c1w, 'bold' => false];
            $v3 = ($col3mode === 'result') ? (string)($r['result'] ?? '') : (string)($r['date'] ?? '');
            $fitcells[] = ['text' => $v3, 'w' => $c3w, 'bold' => false];
        }
        $bodyfs = self::fit_font_to_columns($pdf, $font, $basefs, $fitcells, $padx);

        // Uniform TWO-ROW header: font shrinks so the longest heading word fits its column, and
        // the bar is a fixed two-line height so every caps heading fits neatly.
        $heads = ['UNIT CODE', 'UNIT TITLE', $c3head];
        $hwid  = [$c1w, $c2w, $c3w];
        $headwords = [];
        foreach ($heads as $i => $hd) {
            foreach (explode(' ', $hd) as $word) {
                $headwords[] = ['text' => $word, 'w' => $hwid[$i], 'bold' => true];
            }
        }
        $headfs = self::fit_font_to_columns($pdf, $font, min($basefs, 10.0), $headwords, $padx);
        $headH  = 2 * ($headfs * 0.3528 * 1.15) + 2.6;

        $maxY = $y + $h;
        $curY = $y;

        // Closure to paint the header bar at the current Y.
        $drawHeader = function () use (&$curY, $pdf, $font, $headfs, $headH,
            $hr, $hg, $hb, $border, $c1w, $c2w, $c3w, $c1x, $c2x, $c3x, $c3head): void {
            $pdf->SetFont($font, 'B', $headfs);
            $pdf->SetFillColor($hr, $hg, $hb);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetDrawColor($border[0], $border[1], $border[2]);
            $pdf->SetLineWidth(0.2);
            $pdf->MultiCell($c1w, $headH, 'UNIT CODE',  1, 'L', true, 0, $c1x, $curY, true, 0, false, true, $headH, 'M', false);
            $pdf->MultiCell($c2w, $headH, 'UNIT TITLE', 1, 'L', true, 0, $c2x, $curY, true, 0, false, true, $headH, 'M', false);
            $pdf->MultiCell($c3w, $headH, $c3head,      1, 'C', true, 0, $c3x, $curY, true, 0, false, true, $headH, 'M', false);
            $curY += $headH;
        };

        $drawHeader();

        $rowidx = 0;
        foreach ($rows as $row) {
            $code  = isset($row['code'])  ? (string)$row['code']  : '';
            $title = isset($row['title']) ? (string)$row['title'] : '';
            $date  = isset($row['date'])  ? (string)$row['date']  : '';
            // ROR-RESULTS-COLUMN (v6.2.9): third-column value follows col3mode.
            $col3val = ($col3mode === 'result')
                ? (isset($row['result']) ? (string)$row['result'] : '')
                : $date;

            $pdf->SetFont($font, '', $bodyfs);
            // The title column wraps, so it governs the row height.
            $th = $pdf->getStringHeight($c2w, $title, false, true, '', 0);
            $rowH = max($th, $bodyfs * 0.3528 * 1.15 + 2.4);

            // Continuation page when the next row would overflow the field box.
            if ($curY + $rowH > $maxY + 0.5) {
                $pdf->AddPage('', [$pagew, $pageh]);
                self::$last_ror_page_count++;
                self::paint_page_background($pdf, $page, $bg_to_paint, $pagew, $pageh);
                $curY = $y;
                $drawHeader();
            }

            $fill = ($rowidx % 2 === 1);
            if ($fill) {
                $pdf->SetFillColor($zebra[0], $zebra[1], $zebra[2]);
            }
            $pdf->SetTextColor($bodytx[0], $bodytx[1], $bodytx[2]);
            $pdf->SetDrawColor($border[0], $border[1], $border[2]);
            $pdf->SetLineWidth(0.15);
            $pdf->SetFont($font, '', $bodyfs);
            // Code + date/result are guaranteed to fit on one line (auto-fit); the title wraps.
            $pdf->MultiCell($c1w, $rowH, $code,  1, 'L', $fill, 0, $c1x, $curY, true, 0, false, true, $rowH, 'M', false);
            $pdf->MultiCell($c2w, $rowH, $title, 1, 'L', $fill, 0, $c2x, $curY, true, 0, false, true, $rowH, 'M', false);
            $pdf->MultiCell($c3w, $rowH, $col3val, 1, 'C', $fill, 0, $c3x, $curY, true, 0, false, true, $rowH, 'M', false);

            $curY += $rowH;
            $rowidx++;
        }

        // Reset cell padding so later fields are unaffected.
        $pdf->setCellPaddings(0, 0, 0, 0);
    }

    /**
     * FIT-TO-COLUMN (v6.2.52) — return the largest font size (pt), starting from the author's
     * chosen size and stepping down to a low safety floor, at which EVERY supplied cell's text
     * fits on a single line inside its column width (minus horizontal cell padding). Used to
     * auto-size table body + header fonts so fixed-width cells (unit codes, dates, result codes,
     * header words) can never wrap or clip. There is NO forced minimum — the floor only stops
     * the search from producing unreadable microtype when a box is genuinely tiny.
     *
     * @param array $cells each ['text' => string, 'w' => colWidthMm, 'bold' => bool]
     * @param float $padx  horizontal cell padding per side (mm)
     * @param float $floor smallest size to try (pt)
     * @return float fitted font size (pt)
     */
    private static function fit_font_to_columns(\pdf $pdf, string $font, float $startpt, array $cells, float $padx, float $floor = 6.0): float {
        $fs = max($floor, $startpt);
        while ($fs > $floor) {
            $ok = true;
            foreach ($cells as $c) {
                $avail = (float)($c['w'] ?? 0) - 2 * $padx - 0.3; // usable width with a hair of safety.
                if ($avail <= 0) {
                    continue;
                }
                $text = (string)($c['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $pdf->SetFont($font, !empty($c['bold']) ? 'B' : '', $fs);
                if ($pdf->GetStringWidth($text) > $avail) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                break;
            }
            $fs -= 0.5;
        }
        return $fs;
    }

    /**
     * ROR-5COL (v6.2.51) — draw the full ASQA-mapped Record of Results units table:
     *   ENROLMENT DATE | UNIT CODE | UNIT TITLE | RESULT | COMPLETION DATE
     * with a coloured header bar (caps labels), zebra body rows, thin borders, unit-title
     * wrapping that governs row height, automatic continuation pages, and a result-code
     * legend (C / NYC / CT / RPL) printed directly beneath the table for student reference.
     *
     * Reads [{code,title,date,enroldate,result}] rows from
     * qualification.units_table_rows_json (built in resolve_payload / sample_payload).
     */
    private static function render_ror_full_table(\pdf $pdf, array $field, array $payload, float $pagew, float $pageh, array $page, string $bg_to_paint): void {
        $rows = json_decode($payload['qualification.units_table_rows_json'] ?? '[]', true);
        if (!is_array($rows) || empty($rows)) {
            return;
        }

        $x = (float)($field['x_mm'] ?? 0);
        $y = (float)($field['y_mm'] ?? 0);
        $w = (float)($field['w_mm'] ?? 180);
        $h = (float)($field['h_mm'] ?? 115);

        // Column widths (mm). Enrolment / completion dates and the result code are fixed;
        // the unit title takes whatever remains and wraps. Date columns are wide enough for
        // the caps header ("COMPLETION DATE") and a "12 Feb 2024" value on one line. Guard a
        // very narrow box.
        $enrolw  = 26.0;   // ENROLMENT DATE
        $codew   = 29.0;   // UNIT CODE (holds a ~12-char code; auto-fit shrinks font if longer)
        $resultw = 18.0;   // RESULT — wide enough that the "RESULT" heading keeps a legible size
        $compw   = 26.0;   // COMPLETION DATE
        $titlew  = $w - $enrolw - $codew - $resultw - $compw;   // UNIT TITLE fills the rest.
        if ($titlew < 30.0) {
            // Shrink the fixed columns proportionally so the title keeps 30mm.
            $fixed = $enrolw + $codew + $resultw + $compw;
            $avail = max(1.0, $w - 30.0);
            $scale = $avail / $fixed;
            $enrolw *= $scale; $codew *= $scale; $resultw *= $scale; $compw *= $scale;
            $titlew = $w - $enrolw - $codew - $resultw - $compw;
        }
        $colx = [
            $x,
            $x + $enrolw,
            $x + $enrolw + $codew,
            $x + $enrolw + $codew + $titlew,
            $x + $enrolw + $codew + $titlew + $resultw,
        ];
        $colw = [$enrolw, $codew, $titlew, $resultw, $compw];
        $colhead = ['ENROLMENT DATE', 'UNIT CODE', 'UNIT TITLE', 'RESULT', 'COMPLETION DATE'];
        $colalign = ['C', 'L', 'L', 'C', 'C'];

        $font   = self::sanitise_font($field['font'] ?? 'helvetica');
        // NO-MIN-FONT (v6.2.52): honour the author's chosen size — no forced 12pt floor.
        $basefs = (float)($field['fontsize'] ?? 11);
        if ($basefs <= 0) { $basefs = 11.0; }

        [$hr, $hg, $hb] = self::hex_to_rgb($payload['cert.header_colour'] ?? '#0f6cbf');
        $zebra  = [246, 248, 251];
        $border = [203, 213, 225];
        $bodytx = [30, 41, 59];

        $padx = 1.4;
        $pdf->setCellPaddings($padx, 1.0, $padx, 1.0);

        // AUTO-FIT (v6.2.52): shrink the BODY font (from the author's size down to a low safety
        // floor) until every fixed-width cell — enrolment date, unit code, result, completion
        // date — fits on ONE line. Unit titles remain free to wrap. This guarantees a unit code
        // can never be clipped or truncated, whatever the column width or code length.
        $fitcells = [];
        foreach ($rows as $r) {
            $fitcells[] = ['text' => (string)($r['enroldate'] ?? ''), 'w' => $colw[0], 'bold' => false];
            $fitcells[] = ['text' => (string)($r['code'] ?? ''),      'w' => $colw[1], 'bold' => false];
            $fitcells[] = ['text' => (string)($r['result'] ?? ''),    'w' => $colw[3], 'bold' => true];
            $fitcells[] = ['text' => (string)($r['date'] ?? ''),      'w' => $colw[4], 'bold' => false];
        }
        $bodyfs = self::fit_font_to_columns($pdf, $font, $basefs, $fitcells, $padx);

        // Uniform TWO-ROW header (v6.2.52): the header font shrinks so the longest heading WORD
        // fits its column, and the bar is a fixed two-line height — so every caps heading fits
        // and the bar reads evenly whether a label is one word ("RESULT") or two ("COMPLETION
        // DATE").
        $headwords = [];
        foreach ($colhead as $i => $hd) {
            foreach (explode(' ', $hd) as $word) {
                $headwords[] = ['text' => $word, 'w' => $colw[$i], 'bold' => true];
            }
        }
        $headfs = self::fit_font_to_columns($pdf, $font, min($basefs, 10.0), $headwords, $padx);
        $headH  = 2 * ($headfs * 0.3528 * 1.15) + 2.6;

        $maxY = $y + $h;
        $curY = $y;

        $drawHeader = function () use (&$curY, $pdf, $font, $headfs, $headH,
            $hr, $hg, $hb, $border, $colw, $colx, $colhead, $colalign): void {
            $pdf->SetFont($font, 'B', $headfs);
            $pdf->SetFillColor($hr, $hg, $hb);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetDrawColor($border[0], $border[1], $border[2]);
            $pdf->SetLineWidth(0.2);
            for ($i = 0; $i < 5; $i++) {
                $pdf->MultiCell($colw[$i], $headH, $colhead[$i], 1, $colalign[$i], true, 0,
                    $colx[$i], $curY, true, 0, false, true, $headH, 'M', false);
            }
            $curY += $headH;
        };

        $drawHeader();

        $rowidx = 0;
        foreach ($rows as $row) {
            $vals = [
                isset($row['enroldate']) ? (string)$row['enroldate'] : '',
                isset($row['code'])      ? (string)$row['code']      : '',
                isset($row['title'])     ? (string)$row['title']     : '',
                isset($row['result'])    ? (string)$row['result']    : '',
                isset($row['date'])      ? (string)$row['date']      : '',
            ];

            $pdf->SetFont($font, '', $bodyfs);
            // The title column wraps, so it governs the row height.
            $th = $pdf->getStringHeight($colw[2], $vals[2], false, true, '', 0);
            $rowH = max($th, $bodyfs * 0.3528 * 1.15 + 2.4);

            if ($curY + $rowH > $maxY + 0.5) {
                $pdf->AddPage('', [$pagew, $pageh]);
                self::$last_ror_page_count++;
                self::paint_page_background($pdf, $page, $bg_to_paint, $pagew, $pageh);
                $curY = $y;
                $drawHeader();
            }

            $fill = ($rowidx % 2 === 1);
            if ($fill) {
                $pdf->SetFillColor($zebra[0], $zebra[1], $zebra[2]);
            }
            $pdf->SetTextColor($bodytx[0], $bodytx[1], $bodytx[2]);
            $pdf->SetDrawColor($border[0], $border[1], $border[2]);
            $pdf->SetLineWidth(0.15);
            for ($i = 0; $i < 5; $i++) {
                // Result column bold so the outcome code stands out. Fixed columns are guaranteed
                // (by the fit above) to fit on one line; only the title may wrap.
                $pdf->SetFont($font, ($i === 3 ? 'B' : ''), $bodyfs);
                $pdf->MultiCell($colw[$i], $rowH, $vals[$i], 1, $colalign[$i], $fill, 0,
                    $colx[$i], $curY, true, 0, false, true, $rowH, 'M', false);
            }

            $curY += $rowH;
            $rowidx++;
        }

        // RESULT-KEY (v6.2.51): legend printed beneath the table for student reference.
        $keyfs = max(8.0, $bodyfs - 1.5);
        $keyH  = $keyfs * 0.3528 * 1.15 + 2.4;
        $keyY  = $curY + 2.0;
        $keytext = 'Result key:   C = Competent      NYC = Not Yet Competent      '
                 . 'CT = Credit Transfer      RPL = Recognition of Prior Learning';
        $pdf->setCellPaddings(1.4, 1.0, 1.4, 1.0);
        $pdf->SetFont($font, 'I', $keyfs);
        $pdf->SetTextColor(71, 85, 105);          // slate-600.
        $pdf->SetFillColor(244, 247, 251);        // very light key band.
        $pdf->SetDrawColor($border[0], $border[1], $border[2]);
        $pdf->SetLineWidth(0.15);
        $pdf->MultiCell($w, $keyH, $keytext, 0, 'L', true, 1, $x, $keyY, true, 0, false, true, 0, 'T', false);

        $pdf->setCellPaddings(0, 0, 0, 0);
    }

    /**
     * STUDENT-DETAILS-TABLE (v6.2.51) — draw the Record of Results identity block as one
     * shaded three-column table: STUDENT NAME | USI | QUALIFICATION. Header bar uses the
     * admin header colour with white bold caps labels; a single data row below carries the
     * live values (qualification is "CODE TITLE" with a single space, no hyphen). The row
     * height auto-syncs to the tallest cell so a long qualification title wraps cleanly.
     */
    private static function render_student_details_table(\pdf $pdf, array $field, array $payload): void {
        $x = (float)($field['x_mm'] ?? 0);
        $y = (float)($field['y_mm'] ?? 0);
        $w = (float)($field['w_mm'] ?? 180);

        $name = (string)($payload['student.fullname'] ?? '');
        $usi  = (string)($payload['student.usi'] ?? '');
        $qcode = trim((string)($payload['qualification.code'] ?? ''));
        $qname = trim((string)($payload['qualification.name'] ?? ''));
        // Single space between code and title, never a hyphen/dash (v6.2.51). Avoid
        // duplicating the code when the name already begins with it.
        if ($qcode !== '' && $qname !== '' && strpos($qname, $qcode) === 0) {
            $qname = ltrim(substr($qname, strlen($qcode)), " \t-\xe2\x80\x94\xe2\x80\x93");
        }
        $qual = trim($qcode . ' ' . $qname);

        // Columns: name 34% | USI 26% | qualification 40%.
        $c1w = $w * 0.34;
        $c2w = $w * 0.26;
        $c3w = $w - $c1w - $c2w;
        $colx = [$x, $x + $c1w, $x + $c1w + $c2w];
        $colw = [$c1w, $c2w, $c3w];
        $head = ['STUDENT NAME', 'USI', 'QUALIFICATION'];
        $vals = [$name, $usi, $qual];

        $font   = self::sanitise_font($field['font'] ?? 'helvetica');
        // NO-MIN-FONT (v6.2.52): honour the author's chosen size — no forced 12pt floor.
        $basefs = (float)($field['fontsize'] ?? 12);
        if ($basefs <= 0) { $basefs = 12.0; }

        [$hr, $hg, $hb] = self::hex_to_rgb($payload['cert.header_colour'] ?? '#0f6cbf');
        $border = [203, 213, 225];
        $bodytx = [30, 41, 59];

        $padx = 1.6;
        $pdf->setCellPaddings($padx, 1.2, $padx, 1.2);

        // AUTO-FIT (v6.2.52): name + USI must never wrap, so shrink the body font (from the
        // author's size) until both fit their columns on one line. The qualification column is
        // the widest and is free to wrap onto a second line.
        $fitcells = [
            ['text' => $name, 'w' => $c1w, 'bold' => true],
            ['text' => $usi,  'w' => $c2w, 'bold' => true],
        ];
        $bodyfs = self::fit_font_to_columns($pdf, $font, $basefs, $fitcells, $padx);

        // Uniform TWO-ROW header (fits "QUALIFICATION" / "STUDENT NAME" cleanly).
        $headwords = [];
        foreach ($head as $i => $hd) {
            foreach (explode(' ', $hd) as $word) {
                $headwords[] = ['text' => $word, 'w' => $colw[$i], 'bold' => true];
            }
        }
        $hfs   = self::fit_font_to_columns($pdf, $font, min($basefs, 10.0), $headwords, $padx);
        $headH = 2 * ($hfs * 0.3528 * 1.15) + 2.6;
        $pdf->SetFont($font, 'B', $hfs);
        $pdf->SetFillColor($hr, $hg, $hb);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetDrawColor($border[0], $border[1], $border[2]);
        $pdf->SetLineWidth(0.2);
        for ($i = 0; $i < 3; $i++) {
            $pdf->MultiCell($colw[$i], $headH, $head[$i], 1, 'C', true, 0,
                $colx[$i], $y, true, 0, false, true, $headH, 'M', false);
        }

        // Data row — height governed by the tallest wrapped cell (qualification).
        $dataY = $y + $headH;
        $pdf->SetFont($font, '', $bodyfs);
        $rowH = $bodyfs * 0.3528 * 1.15 + 2.6;
        foreach ($vals as $i => $v) {
            $ch = $pdf->getStringHeight($colw[$i], $v, false, true, '', 0);
            if ($ch > $rowH) {
                $rowH = $ch;
            }
        }
        $pdf->SetTextColor($bodytx[0], $bodytx[1], $bodytx[2]);
        $pdf->SetDrawColor($border[0], $border[1], $border[2]);
        $pdf->SetLineWidth(0.15);
        // Student name + USI slightly emphasised. Values CENTRED under their headings (v6.2.62).
        for ($i = 0; $i < 3; $i++) {
            $pdf->SetFont($font, ($i < 2 ? 'B' : ''), $bodyfs);
            $pdf->MultiCell($colw[$i], $rowH, $vals[$i], 1, 'C', false, 0,
                $colx[$i], $dataY, true, 0, false, true, $rowH, 'M', false);
        }

        $pdf->setCellPaddings(0, 0, 0, 0);
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
     * FIT-CENTRE-IMAGE (v5.9.449) — paint an image scaled to fit inside the w×h box
     * while preserving its aspect ratio, centred both horizontally and vertically.
     * Used for the compliance logos (NRT, organisation seal) so they render at the
     * box height with the box width matching the artwork and the logo centred — never
     * stretched. Raster images use TCPDF's 'CM' fit-box; SVG scales into the box.
     */
    private static function paint_image_fit(\pdf $pdf, string $path, float $x, float $y, float $w, float $h): void {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            if ($ext === 'svg' || $ext === 'svgz') {
                // ImageSVG has no fit-box; centre-align within the box (aspect kept by w=0).
                $pdf->ImageSVG($path, $x, $y, $w, $h, '', '', 'C', 0, false);
            } else {
                // 15th param 'CM' = fit inside the box, centre-middle, aspect preserved.
                $pdf->Image($path, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 0, 'CM');
            }
        } catch (\Throwable $e) {
            debugging('Cert template image fit-paint failed for ' . $path . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * v5.9.320 CERT-ASSETS — check whether a branding asset is configured to
     * apply to the current cert type.
     *
     * Reads the multi-checkbox config key (e.g. 'secondary_logo_cert_types').
     * The value is a comma-separated list of cert type identifiers stored by
     * admin_setting_configmulticheckbox.  An empty/unset value means "all
     * cert types" — this preserves backwards-compatible behaviour for existing
     * assets (org seal, signature, etc.) when an RTO first upgrades.
     *
     * @param string $config_key  Plugin config key holding the cert-type list.
     * @param string $certtype    The cert type being rendered ('testamur', etc.).
     * @return bool  True when the asset should be included in the render.
     */
    private static function asset_applies_to_certtype(string $config_key, string $certtype): bool {
        $raw = get_config('local_rtocompliance', $config_key);
        if ($raw === false || $raw === null || $raw === '') {
            // APPLIES-TO-DEFAULT-FIX (v6.2.12): a Moodle configmulticheckbox default is only
            // written to the DB when the settings page is SAVED. If the admin uploaded an asset
            // but never opened/saved Certificate Settings, this config is empty — and the old
            // fallback of "apply to every cert type" leaked the (testamur) background image and
            // org seal onto the Statement of Attainment, Record of Results and Certificate of
            // Completion. Fall back to the SAME default the settings form defines so an unsaved
            // setting behaves exactly like the intended default instead of the opposite.
            $formdefaults = [
                'cert_background_cert_types' => ['testamur'],              // settings.php default
                'org_seal_cert_types'        => ['testamur', 'statement'], // settings.php default
            ];
            if (array_key_exists($config_key, $formdefaults)) {
                return in_array($certtype, $formdefaults[$config_key], true);
            }
            return true; // unknown key — preserve the original permissive behaviour
        }
        // admin_setting_configmulticheckbox stores selected keys as
        // comma-separated values (e.g. "testamur,statement").
        $allowed = array_map('trim', explode(',', $raw));
        return in_array($certtype, $allowed, true);
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
     * SOA-EPOCH-DATE-FIX (v6.3.11): normalise a per-unit date value taken from the
     * stored units JSON into a UNIX timestamp.
     *
     * The units JSON is written by several paths and they did not agree on a
     * format: certificate_validator stores a raw timestamp, while the multi-unit
     * SoA issuer (soa_ajax.php) stored a formatted 'd/m/Y' display string. The
     * renderer used a bare (int) cast, and (int)'03/07/2026' === 3 — i.e. three
     * seconds after the epoch — so every unit on a multi-unit Statement of
     * Attainment printed "01 Jan 1970". Accept both, plus the other display
     * formats certs in the wild may already hold, and return 0 when the value is
     * empty or unparseable so the caller's register backfill/blank path applies.
     *
     * Day-first is assumed for slash/dot separated values ('03/07/2026' is
     * 3 July 2026, Australian convention, never 7 March) — strtotime() would read
     * a slash date as US month-first, so those are parsed explicitly.
     *
     * @param mixed $value raw value from the stored units JSON
     * @return int UNIX timestamp, or 0 when not determinable
     */
    private static function normalise_unit_date($value): int {
        if ($value === null || $value === '' || is_array($value)) {
            return 0;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }
        // Pure digits (optionally negative — pre-1970 dates) = already a timestamp.
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        // Day-first numeric dates: d/m/Y, d-m-Y, d.m.Y (2- or 4-digit year).
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$#', $raw, $m)) {
            $day   = (int) $m[1];
            $month = (int) $m[2];
            $year  = (int) $m[3];
            if ($year < 100) {
                $year += ($year < 70) ? 2000 : 1900;
            }
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return (int) mktime(0, 0, 0, $month, $day, $year);
            }
            return 0;
        }
        // ISO (Y-m-d) and textual forms ('05 Jan 2024', '5 January 2024').
        $ts = strtotime($raw);
        return ($ts === false) ? 0 : (int) $ts;
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

        // F1 (v5.9.389): prefer the RTO identity settings SNAPSHOTTED on the cert at
        // issue time (cert.issuesnapshot) so re-rendering a historical certificate
        // shows the RTO name/code, authorised signatory and AQF statement AS THEY WERE
        // WHEN ISSUED — a later change to these settings must not retroactively rewrite
        // past certificates (as-issued integrity, mirroring the USI snapshot). Falls
        // back to live config for certs issued before the snapshot column existed.
        global $SITE;
        $issuesnap = [];
        if (!empty($cert->issuesnapshot)) {
            $decodedsnap = json_decode($cert->issuesnapshot, true);
            if (is_array($decodedsnap)) {
                $issuesnap = $decodedsnap;
            }
        }
        $snapcfg = function ($key, $default) use ($issuesnap) {
            if (array_key_exists($key, $issuesnap) && $issuesnap[$key] !== null && $issuesnap[$key] !== '') {
                return $issuesnap[$key];
            }
            return get_config('local_rtocompliance', $key) ?: $default;
        };
        // v5.9.442: fall back to the real Moodle site name (the RTO's own name) rather
        // than the generic "Training Organisation" when the RTO legal name isn't set yet,
        // so an issued certificate never prints a placeholder provider name.
        $rtoname        = $snapcfg('rtoname', format_string($SITE->fullname));
        $rtocode        = $snapcfg('rtocode', '');
        $signatoryname  = $snapcfg('signatoryname', '');
        $signatorytitle = $snapcfg('signatorytitle', '');
        $aqfstatement   = $snapcfg('aqfstatement',
            'This qualification is recognised within the Australian Qualifications Framework.');

        // QR codes point to the AI Grader central registry so the certificate
        // remains verifiable even if this Moodle server changes domain or goes offline.
        // Falls back to the local Moodle verify.php if no platform URL is configured.
        // v5.9.365 SELF-HOSTED-VERIFY: verify on THIS Moodle site's own public verify.php
        // (the standard Moodle certificate-plugin model), not a third-party vendor domain.
        $verifyurl = (new \moodle_url('/local/rtocompliance/verify.php',
            ['token' => $cert->verifytoken ?? '']))->out(false);

        // USI: prefer the value SNAPSHOTTED on the cert at issue time (v5.9.305
        // stores cert.usi) so re-rendering a historical certificate always shows
        // what was verified when it was issued — a later USI correction must not
        // retroactively rewrite past certificates (forensic audit trail, ASQA).
        // Fall back to the live student USI only for old certs issued before the
        // snapshot column existed (cert.usi empty).
        $usi = '';
        if (!empty($cert->usi)) {
            $usi = $cert->usi;
        } else if (!empty($user->id)) {
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
        //
        // FIX-RTO-LOGO-SETTINGS-GAP (v5.9.318): get_branding_path() only looked at
        // the FA_BRANDING filearea (cert template branding panel upload).  The RTO
        // logo uploaded through the main RTO Settings page (admin_setting_configstoredfile,
        // filearea 'logo') was never consulted, so the cert was blank unless the admin
        // also uploaded the logo separately on the cert template branding panel.
        // resolve_compliance_asset_path() checks FA_BRANDING first, then falls back to
        // the admin settings filearea — the same chain NRT/AQF logos already use.
        // v5.9.320 CERT-ASSETS: certtype is used to filter per-cert-type assets.
        // Falls back to empty string so applies-to checks pass for certs without
        // a certtype (e.g. legacy rows — they see all assets, safe default).
        $certtype = !empty($cert->certtype) ? $cert->certtype : '';

        $rtologopath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            cert_template::BRANDING_ITEMID_LOGO,
            'logo',   // admin_setting_configstoredfile filearea from RTO Settings page
            ''
        );

        // FIX-SIG-SETTINGS-GAP (v5.9.320): signature previously resolved only via
        // get_branding_path() (the cert template branding panel), so RTOs who uploaded
        // the CEO signature through RTO Settings → Certificate Elements got a blank cert.
        // Now uses the same resolve_compliance_asset_path() chain as every other asset:
        // FA_BRANDING filearea (branding panel) → admin_setting_configstoredfile
        // (RTO Settings, filearea 'ceo_signature_file') → no bundled fallback.
        // Per-cert-type visibility: ceo_signature_cert_types (empty = all types).
        $sigpath = self::asset_applies_to_certtype('ceo_signature_cert_types', $certtype)
            ? \local_rtocompliance\cert_template::resolve_compliance_asset_path(
                cert_template::BRANDING_ITEMID_SIGNATURE, 'ceo_signature_file', ''
              )
            : '';

        // v5.9.320 CERT-ASSETS — secondary logo slot (e.g. brand logo + trading-name logo,
        // or consortium branding). Per-cert-type visibility: secondary_logo_cert_types.
        $seclogopath = self::asset_applies_to_certtype('secondary_logo_cert_types', $certtype)
            ? \local_rtocompliance\cert_template::resolve_compliance_asset_path(
                cert_template::BRANDING_ITEMID_SECONDARY_LOGO, 'secondary_logo', ''
              )
            : '';

        // v5.9.320 CERT-ASSETS — system-wide cert background image. Applied as the
        // full-page background layer when the cert template has no template-specific
        // bg set. Per-cert-type visibility: cert_background_cert_types.
        $certbgpath = self::asset_applies_to_certtype('cert_background_cert_types', $certtype)
            ? \local_rtocompliance\cert_template::resolve_compliance_asset_path(
                cert_template::BRANDING_ITEMID_CERT_BG, 'cert_background_file', ''
              )
            : '';

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

        // v5.9.320: org seal now also respects per-cert-type applies-to config.
        $orgsealpath = self::asset_applies_to_certtype('org_seal_cert_types', $certtype)
            ? \local_rtocompliance\cert_template::resolve_compliance_asset_path(
                \local_rtocompliance\cert_template::BRANDING_ITEMID_ORG_SEAL,
                'organisation_seal_file', ''
              )
            : '';

        // v5.9.321 ORPHAN-FIX: compliance_logo_2 was defined in settings.php but never
        // assigned a branding itemid or wired into the renderer.  Now resolved as
        // BRANDING_ITEMID_COMPLIANCE_LOGO_2 = 9.  Dynamic key: 'compliance_logo_2'.
        $complogo2path = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            cert_template::BRANDING_ITEMID_COMPLIANCE_LOGO_2, 'compliance_logo_2', ''
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

        // QUAL-CODE-MATCH (v6.2.27): accept BOTH training-package qualification codes
        // (letters-then-digits, e.g. BSB30120, TLI50822) AND nationally-recognised
        // accredited-course codes (digits-then-state letters, e.g. 10904NAT, 22522VIC,
        // 52885WA). Unit codes (letters + only 3-4 digits, e.g. BSBCMM311) still do NOT
        // match, so a unit code never leaks into qualification wording. When the
        // qualification NAME is unavailable (e.g. it equalled the code and was stripped
        // above), render the code alone instead of leaving the whole line blank — this is
        // the "inserts a blank item" defect the accredited-course RTOs were hitting.
        $isqualcode = (bool) preg_match('/^([A-Z]{2,10}[0-9]{5,6}[A-Z]?|[0-9]{4,6}[A-Z]{2,4})$/', $stmt_qcode);
        $partofstmt = '';
        if ($stmt_qcode !== '' && $isqualcode) {
            $partofstmt = ($stmt_qname !== '')
                ? 'These competencies form part of ' . $stmt_qcode . ' ' . $stmt_qname . '.'
                : 'These competencies form part of ' . $stmt_qcode . '.';
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
        // OUTCOME-LABEL-FIX (v5.9.334): '60' was incorrectly labelled 'RCC Granted'.
        // 'RCC' (Recognition of Current Competency) is a discontinued pre-2010 term;
        // AVETMISS 8 code 60 = Credit Transfer. Fixed to 'Credit Transfer'.
        // Also added '61' (Credit Transfer Not Granted) for completeness.
        $_outcomeLabels = [
            '20' => 'Competent',                   '30' => 'Not Yet Competent',
            '40' => 'Withdrawn',                   '51' => 'RPL Granted',
            '52' => 'RPL Not Granted',             '60' => 'Credit Transfer',
            '61' => 'Credit Transfer Not Granted', '70' => 'Continuing Enrolment',
            '81' => 'Non-assessable Satisfactory', '82' => 'Non-assessable Unsatisfactory',
            '90' => 'Superseded',
        ];

        // RESULT-CODE-MAP (v6.2.51): the Record of Results RESULT column prints the
        // short nationally-recognised transcript code (C / NYC / CT / RPL …) rather
        // than the long outcome label, per the ASQA sample Record of Results. Keyed by
        // AVETMISS outcome identifier. A code legend is printed beneath the table.
        $_outcomeCodes = [
            '20' => 'C',    '30' => 'NYC',  '40' => 'W',    '51' => 'RPL',
            '52' => 'RPL',  '60' => 'CT',   '61' => 'CT',   '70' => 'CE',
            '81' => 'NA',   '82' => 'NA',   '90' => 'S',
        ];
        // Helper: map a stored outcome to its transcript code. Accepts an already-short
        // code (e.g. a legacy row storing "C"/"NYC" directly) or an AVETMISS number.
        $_toResultCode = function ($outcome) use ($_outcomeCodes): string {
            $o = trim((string) $outcome);
            if ($o === '') {
                return '';
            }
            if (isset($_outcomeCodes[$o])) {
                return $_outcomeCodes[$o];
            }
            // Already a letter code — normalise common textual results to codes.
            $upper = strtoupper($o);
            $textmap = [
                'COMPETENT' => 'C', 'NOT YET COMPETENT' => 'NYC', 'CREDIT TRANSFER' => 'CT',
                'RPL GRANTED' => 'RPL', 'RECOGNITION OF PRIOR LEARNING' => 'RPL',
            ];
            if (isset($textmap[$upper])) {
                return $textmap[$upper];
            }
            // Fall back to the raw value (already a short code like C/NYC/CT/RPL).
            return $o;
        };

        $issuets = !empty($cert->issuedate) ? (int)$cert->issuedate : time();

        // Derive cert-level semester as fallback when no per-unit semester is stored.
        $_certMonth = (int) date('n', $issuets);
        $_certYear  = date('Y', $issuets);
        $_certSemester = ($_certMonth <= 6 ? 'Sem 1 ' : 'Sem 2 ') . $_certYear;

        // COMPLETION-DATE-MAP (v5.9.447): when the stored units JSON predates the
        // date-capture fix, backfill each unit's completion date from the register
        // in a single query keyed by unit code, so old certs still populate the
        // Completion Date column of the Style A units table.
        global $DB;
        $_unitDateMap = [];
        // ENROL-DATE-MAP (v6.2.51): parallel map of each unit's ENROLMENT (activity start)
        // date, keyed by unit code, so the Record of Results can populate its new
        // "Enrolment Date" column even when the stored units JSON predates date capture.
        $_unitStartMap = [];
        if (!empty($cert->userid)
                && !empty($cert->certtype)
                && in_array($cert->certtype, ['record', 'statement'], true)) {
            $_dbman = $DB->get_manager();
            if ($_dbman->table_exists('local_rtocompliance_students') &&
                $_dbman->table_exists('local_rtocompliance_enrolments')) {
                $_stud2 = $DB->get_record('local_rtocompliance_students',
                    ['userid' => (int)$cert->userid], 'id', IGNORE_MISSING);
                if ($_stud2) {
                    $_dateEnrols = $DB->get_records('local_rtocompliance_enrolments',
                        ['studentid' => (int)$_stud2->id], '',
                        'id, unitcode, activityenddate, activitystartdate');
                    foreach ($_dateEnrols as $_de) {
                        $_uc = strtoupper(trim((string)($_de->unitcode ?? '')));
                        if ($_uc === '') {
                            continue;
                        }
                        $_dts = !empty($_de->activityenddate) ? (int)$_de->activityenddate
                              : (!empty($_de->activitystartdate) ? (int)$_de->activitystartdate : 0);
                        if ($_dts > 0 && empty($_unitDateMap[$_uc])) {
                            $_unitDateMap[$_uc] = $_dts;
                        }
                        if (!empty($_de->activitystartdate) && empty($_unitStartMap[$_uc])) {
                            $_unitStartMap[$_uc] = (int)$_de->activitystartdate;
                        }
                    }
                }
            }
        }

        if (!empty($cert->units)) {
            $unitsArr = json_decode($cert->units, true);
            if (is_array($unitsArr)) {
                $lines = [];
                $_col1 = []; $_col2 = []; $_col3 = [];
                $_tableRows = [];
                foreach ($unitsArr as $u) {
                    $code    = isset($u['code'])    ? trim((string)$u['code'])    : '';
                    $name    = isset($u['name'])    ? trim((string)$u['name'])    : '';
                    $outcome = isset($u['outcome']) ? trim((string)$u['outcome']) : '';

                    // SOA-DUPCODE-UNIT-FIX (v5.9.264): strip the unit code prefix from the
                    // unit name if the name already starts with the code (e.g. Moodle stores
                    // "ABC12345 Computer Applications" as both code="ABC12345" and
                    // name="ABC12345 Computer Applications").  Without this the display line
                    // becomes "ABC12345 — ABC12345 Computer Applications" (code shown twice).
                    // Strip leading code + any separator (space, dash, em-dash, en-dash).
                    if ($code !== '' && $name !== '' && strpos($name, $code) === 0) {
                        $name = ltrim(substr($name, strlen($code)), " \t-\xe2\x80\x94\xe2\x80\x93");
                    }

                    // Flat units text (used by SOA templates and legacy RoR templates).
                    // v5.9.446: unit CODE first, then the name, separated by a single space
                    // (no hyphen/dash between them).
                    if ($code !== '' && $name !== '') {
                        $lines[] = $code . ' ' . $name;
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
                        $_col2[] = $code . ' ' . $name;
                    } elseif ($code !== '') {
                        $_col2[] = $code;
                    } else {
                        $_col2[] = $name;
                    }

                    $_col3[] = $_outcomeLabels[$outcome] ?? ($outcome !== '' ? $outcome : '');

                    // STYLE-A-TABLE (v5.9.447): per-unit row for the shaded 3-column
                    // units table — Unit Code | Unit Title | Completion Date.
                    // Completion date is the per-unit timestamp captured at issue
                    // time ('date'), or a legacy 'completiondate', else the register
                    // backfill map, else blank. Title is the code-stripped name.
                    // v6.3.11: normalise_unit_date() instead of a bare (int) cast —
                    // stored values may be timestamps OR 'd/m/Y' strings (see helper).
                    $_uts = 0;
                    if (!empty($u['date'])) {
                        $_uts = self::normalise_unit_date($u['date']);
                    }
                    if ($_uts <= 0 && !empty($u['completiondate'])) {
                        $_uts = self::normalise_unit_date($u['completiondate']);
                    }
                    if ($_uts <= 0 && $code !== '' && isset($_unitDateMap[strtoupper($code)])) {
                        $_uts = (int)$_unitDateMap[strtoupper($code)];
                    }
                    // ENROL-DATE (v6.2.51): per-unit enrolment (activity start) date for the
                    // Record of Results "Enrolment Date" column — stored key, else start map.
                    $_ets = 0;
                    if (!empty($u['enroldate'])) {
                        $_ets = self::normalise_unit_date($u['enroldate']);
                    }
                    if ($_ets <= 0 && !empty($u['activitystartdate'])) {
                        $_ets = self::normalise_unit_date($u['activitystartdate']);
                    }
                    if ($_ets <= 0 && $code !== '' && isset($_unitStartMap[strtoupper($code)])) {
                        $_ets = (int)$_unitStartMap[strtoupper($code)];
                    }
                    $_tableRows[] = [
                        'code'     => $code,
                        'title'    => ($name !== '' ? $name : $code),
                        'date'     => $_uts > 0 ? date('d M Y', $_uts) : '',
                        // v6.2.51 Record of Results columns.
                        'enroldate' => $_ets > 0 ? date('d M Y', $_ets) : '',
                        'result'    => $_toResultCode($outcome),
                    ];
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
                $unitsTableRowsJson = json_encode($_tableRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                        $lines  = []; $_col1 = []; $_col2 = []; $_col3 = []; $_rorRows = []; $_tableRows = [];
                        foreach ($_comp['units'] as $_u) {
                            $_code    = isset($_u['code'])     ? trim((string)$_u['code'])    : '';
                            $_name    = isset($_u['name'])     ? trim((string)$_u['name'])    : '';
                            $_outcome = isset($_u['outcome'])  ? trim((string)$_u['outcome']) : '20';
                            $_sem     = isset($_u['semester']) ? trim((string)$_u['semester']): $_certSemester;
                            // v6.3.11: normalise_unit_date() — see helper; stored values
                            // may be timestamps or formatted strings.
                            $_uts2 = !empty($_u['date']) ? self::normalise_unit_date($_u['date']) : 0;
                            if ($_uts2 <= 0 && $_code !== '' && isset($_unitDateMap[strtoupper($_code)])) {
                                $_uts2 = (int)$_unitDateMap[strtoupper($_code)];
                            }
                            // ENROL-DATE (v6.2.51): enrolment (activity start) date for this unit.
                            $_ets2 = !empty($_u['enroldate']) ? self::normalise_unit_date($_u['enroldate']) : 0;
                            if ($_ets2 <= 0 && !empty($_u['activitystartdate'])) {
                                $_ets2 = self::normalise_unit_date($_u['activitystartdate']);
                            }
                            if ($_ets2 <= 0 && $_code !== '' && isset($_unitStartMap[strtoupper($_code)])) {
                                $_ets2 = (int)$_unitStartMap[strtoupper($_code)];
                            }
                            // SOA-DUPCODE-UNIT-FIX (v5.9.264): strip code prefix from name (same as primary branch).
                            if ($_code !== '' && $_name !== '' && strpos($_name, $_code) === 0) {
                                $_name = ltrim(substr($_name, strlen($_code)), " \t-\xe2\x80\x94\xe2\x80\x93");
                            }
                            if ($_code !== '' && $_name !== '') {
                                $lines[] = $_code . ' ' . $_name;
                            } elseif ($_code !== '') { $lines[] = $_code; }
                            else { $lines[] = $_name; }
                            $_col1[] = $_sem;
                            if ($_code !== '' && $_name !== '') {
                                $_col2[] = $_code . ' ' . $_name;
                            } elseif ($_code !== '') { $_col2[] = $_code; }
                            else { $_col2[] = $_name; }
                            $_col3[]    = $_outcomeLabels[$_outcome] ?? ($_outcome !== '' ? $_outcome : '');
                            $_rorRows[] = ['semester' => $_sem, 'name' => end($_col2), 'result' => end($_col3)];
                            $_tableRows[] = [
                                'code'     => $_code,
                                'title'    => ($_name !== '' ? $_name : $_code),
                                'date'     => $_uts2 > 0 ? date('d M Y', $_uts2) : '',
                                // v6.2.51 Record of Results columns.
                                'enroldate' => $_ets2 > 0 ? date('d M Y', $_ets2) : '',
                                'result'    => $_toResultCode($_outcome),
                            ];
                        }
                        $unitsText        = implode("\n", $lines);
                        $unitsSemester    = implode("\n", $_col1);
                        $unitsNames       = implode("\n", $_col2);
                        $unitsResults     = implode("\n", $_col3);
                        $unitsRorRowsJson = json_encode($_rorRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $unitsTableRowsJson = json_encode($_tableRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            }
        }

        return [
            // F4 (v5.9.390): certtype travels in the payload so render() can apply a
            // render-time backstop that drops elements forbidden for this cert type.
            'certtype'                                  => $cert->certtype ?? '',
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
            // STYLE-A-TABLE (v5.9.447): [{code,title,date}] rows for the shaded
            // 3-column units table (Unit Code | Unit Title | Completion Date) drawn
            // on both the Statement of Attainment and the Record of Results.
            'qualification.units_table_rows_json'       => $unitsTableRowsJson ?? '[]',
            // Header bar fill colour (admin setting; defaults to the site brand colour).
            'cert.header_colour'                        => local_rtocompliance_cert_header_colour(),
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
            // SOA-STATEMENT-DEDUPE (v5.9.406): the "completion of course" statement is
            // for accredited SHORT COURSES, and the "form part of" statement is for
            // units contributing to a QUALIFICATION — a single SoA is one or the other,
            // never both. They were being generated together from the same qual code,
            // printing two near-identical overlapping sentences. Suppress this one
            // whenever the "form part of" line is populated, so templates that still
            // carry both fields (created before this fix) render only the correct one.
            // COMPLETION-STMT-FIX (v6.2.27): compute independently from the (broadened)
            // qualification-code test so accredited SHORT-COURSE codes (digits-first, e.g.
            // 10904NAT) — the exact case this line is meant for — no longer render blank.
            // A template normally carries either this line OR the "form part of" line, not
            // both; if the qualification name is missing, render the code alone.
            'qualification.completionofcoursestatement' => (
                $isqualcode && $stmt_qcode !== ''
                    ? 'These competencies were attained in completion of ' . $stmt_qcode
                        . ($stmt_qname !== '' ? ' course in ' . $stmt_qname : ' course') . '.'
                    : ''
            ),
            'cert.coursetitle'                          => $coursetitle,
            'cert.number'                               => $cert->certnumber ?? '',
            'cert.issuedate'                            => userdate($issuets, '%d %B %Y'),
            'cert.issuedate_ts'                         => $issuets,
            'cert.completiondate'                       => !empty($cert->timecompleted) ? userdate($cert->timecompleted, '%d %B %Y') : '',
            'rto.name'                                  => $rtoname,
            // TOID-PREFIX (v6.2.51): render as "TOID 30772" on every issued certificate.
            'rto.code'                                  => self::format_rto_code((string) $rtocode),
            'rto.logo'                                  => '',
            'rto.logo__path'                            => $rtologopath,
            // v5.9.320 CERT-ASSETS: secondary logo (two-logo certs).
            'rto.secondary_logo'                        => '',
            'rto.secondary_logo__path'                  => $seclogopath,
            'signatory.name'                            => $signatoryname,
            'signatory.title'                           => $signatorytitle,
            'signatory.signature'                       => '',
            'signatory.signature__path'                 => $sigpath,
            // v5.9.320 CERT-ASSETS: system-wide certificate background image.
            // Injected as page bg fallback when the cert template has no own bg.
            'cert.background__path'                     => $certbgpath,
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
            // v5.9.321 ORPHAN-FIX: compliance_logo_2 wired.
            'compliance_logo_2'                         => '',
            'compliance_logo_2__path'                   => $complogo2path,
            // v5.9.321 ORPHAN-FIX: certfooter wired as cert.footer payload key.
            // Template authors can now place a cert.footer dynamic field anywhere.
            'cert.footer'                               => get_config('local_rtocompliance', 'certfooter') ?: '',
            // v5.9.366 ENABLEQR-GATE: the verification QR code is MANDATORY and
            // always rendered (verify.url is always populated below). The enableqr
            // setting now only controls whether the human-readable "Verify at: <url>"
            // TEXT line is shown alongside the QR — some RTOs prefer the QR alone.
            // Default (unset) = shown; set to '0' = hidden. The QR is never affected.
            'authenticity_measure'                      => ($verifyurl !== '' && (string) get_config('local_rtocompliance', 'enableqr') !== '0')
                ? 'Verify at: ' . $verifyurl : '',
            // ASQA-COMPLIANCE-PASS-2 (v4.2.59) — optional descriptors now
            // sourced from admin settings (ASQA-mandated certificate elements
            // page) so RTOs configure them once per site.
            // OPTIONAL-DESCRIPTORS (v6.2.55): industry descriptor + occupational stream are
            // qualification-specific values (blank unless the RTO sets them in settings — no
            // universal default is meaningful). The language statement and the Australian
            // Apprenticeship line carry ASQA suggested wording as a default when unset, so a
            // field the author deliberately placed is never blank on the issued document.
            'industry_descriptor'                       => get_config('local_rtocompliance', 'industrydescriptor')      ?: '',
            'occupational_stream'                       => get_config('local_rtocompliance', 'occupationalstream')      ?: '',
            'australian_apprenticeship'                 => get_config('local_rtocompliance', 'apprenticeshipstatement') ?: 'Completed through an Australian Apprenticeship.',
            'language_statement'                        => get_config('local_rtocompliance', 'languagestatement')       ?: 'These units/modules have been delivered and assessed in English.',
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
        // PREVIEW-MATCHES-ISSUED (v6.2.15): the live preview must show EXACTLY what the
        // issued certificate will show. The full-page background image and the organisation
        // seal are per-cert-type assets (cert_background_cert_types / org_seal_cert_types) —
        // e.g. the decorative background is usually testamur-only. Previously sample_payload
        // ignored that config and painted them on every cert type, so the Statement of
        // Attainment / Record of Results / Completion previews wrongly showed the testamur
        // background. Now the preview honours the same applies-to check as real issuance.
        $rtologopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_LOGO, 'logo', '');
        $sigpath     = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_SIGNATURE, 'ceo_signature_file', '');
        $seclogopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_SECONDARY_LOGO, 'secondary_logo', '');
        $certbgpath  = self::asset_applies_to_certtype('cert_background_cert_types', $certtype)
            ? cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_CERT_BG, 'cert_background_file', '')
            : '';
        $nrtlogopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_NRT_LOGO, 'nrt_logo_file', 'nrt_logo.png');
        $aqflogopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_AQF_LOGO, 'aqf_logo_file', 'aqf_logo.jpg');
        $stalogopath = cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_STA_LOGO, 'compliance_logo_1', '');
        $orgsealpath = self::asset_applies_to_certtype('org_seal_cert_types', $certtype)
            ? cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_ORG_SEAL, 'organisation_seal_file', '')
            : '';

        $shared = [
            'certtype'                             => $certtype,
            // STYLE-A-TABLE (v5.9.447): header bar colour + sample table rows so the
            // in-editor preview and Send-a-test render the shaded units table.
            'cert.header_colour'                   => local_rtocompliance_cert_header_colour(),
            'qualification.units_table_rows_json'  => '[]',
            'rto.logo__path'                       => $rtologopath,
            'rto.secondary_logo'                   => '',
            'rto.secondary_logo__path'             => $seclogopath,
            'signatory.signature__path'            => $sigpath,
            'cert.background__path'                => $certbgpath,
            'nrt_logo__path'                       => $nrtlogopath,
            'aqf_logo__path'                       => $aqflogopath,
            'state_training_authority_logo__path'  => $stalogopath,
            'organisation_seal__path'              => $orgsealpath,
            'rto.logo'                             => '',
            'signatory.signature'                  => '',
            'nrt_logo'                             => '',
            'aqf_logo'                             => '',
            'state_training_authority_logo'        => '',
            'compliance_logo_2'                    => '',
            'compliance_logo_2__path'              => cert_template::resolve_compliance_asset_path(cert_template::BRANDING_ITEMID_COMPLIANCE_LOGO_2, 'compliance_logo_2', ''),
            'organisation_seal'                    => '',
            'cert.footer'                          => get_config('local_rtocompliance', 'certfooter') ?: '',
            'certify_statement'                    => 'This is to certify that',
            'attained_statement'                   => 'has fulfilled the requirements for',
            'soa_intro_statement'                  => 'This is a statement that',
            'soa_attained_statement'               => 'has attained',
            'statement_of_attainment_heading'      => 'Statement of Attainment',
            'record_of_results_heading'            => 'Record of Results',
            'authenticity_measure'                 => 'Verify at: ' . (new \moodle_url('/local/rtocompliance/verify.php', ['token' => 'PREVIEWTOKEN']))->out(false),
            // EDITOR-SAMPLES (v6.2.55): every optional descriptor shows representative ASQA
            // wording on the canvas so the field is never invisible when dragged on (the
            // "blank when I add it" report). At issue time these resolve from RTO settings
            // (language statement defaults to English so it is never blank on a testamur/RoR).
            'industry_descriptor'                  => get_config('local_rtocompliance', 'industrydescriptor')      ?: 'Business Services',
            'occupational_stream'                  => get_config('local_rtocompliance', 'occupationalstream')      ?: 'Administration',
            'australian_apprenticeship'            => get_config('local_rtocompliance', 'apprenticeshipstatement') ?: 'Completed through an Australian Apprenticeship.',
            'language_statement'                   => get_config('local_rtocompliance', 'languagestatement')       ?: 'These units/modules have been delivered and assessed in English.',
            'skill_set_statement'                  => get_config('local_rtocompliance', 'skillsetstatement')       ?: 'These units form part of the [skill set name] skill set.',
            'qualification.partofstatement'        => 'These competencies form part of BSB30120 Certificate III in Business.',
            // EDITOR-SAMPLE-FIX (v6.2.27): give the completion-of-course statement a real
            // sample so dragging it onto the canvas shows representative text instead of an
            // empty box (the "inserts a blank item" report). At issue time the two
            // statements are computed independently from the qualification code.
            'qualification.completionofcoursestatement' => 'These competencies were attained in completion of 10904NAT course in Diploma of Social Media Marketing.',
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
                // TOID-PREFIX (v6.2.51): preview mirrors the issued cert ("TOID <code>").
                'rto.code'                   => self::format_rto_code((string) (get_config('local_rtocompliance', 'rtocode') ?: '')),
                'signatory.name'             => get_config('local_rtocompliance', 'signatoryname')  ?: 'Dr A. Authorised',
                'signatory.title'            => get_config('local_rtocompliance', 'signatorytitle') ?: 'Course Coordinator',
                'aqf_statement'              => '',
                'not_a_testamur_statement'   => '',
                'verify.url'                 => (new \moodle_url('/local/rtocompliance/verify.php', ['token' => 'PREVIEWTOKEN']))->out(false),
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
            'qualification.units'        => "BSBCMM311 Apply critical thinking skills in a team environment\nBSBCRT311 Apply critical thinking skills\nBSBPEF301 Organise personal work priorities\nBSBSUS211 Participate in sustainable work practices\nBSBTWK301 Use inclusive work practices",
            // ROR-3COL-FIX (v5.9.220): 3-column payload for the Record of Results preview.
            'qualification.units_col_semester' => "Sem 1 2024\nSem 1 2024\nSem 2 2024\nSem 2 2024\nSem 1 2025",
            'qualification.units_col_names'    => "BSBCMM311 Apply critical thinking skills in a team environment\nBSBCRT311 Apply critical thinking skills\nBSBPEF301 Organise personal work priorities\nBSBSUS211 Participate in sustainable work practices\nBSBTWK301 Use inclusive work practices",
            'qualification.units_col_results'  => "Competent\nCompetent\nCompetent\nCompetent\nCompetent",
            // ROR-TABLE-FIX (v5.9.246): structured row array for ror_table field kind.
            'qualification.units_ror_rows_json' => json_encode([
                ['semester' => 'Sem 1 2024', 'name' => 'BSBCMM311 Apply critical thinking skills in a team environment', 'result' => 'Competent'],
                ['semester' => 'Sem 1 2024', 'name' => 'BSBCRT311 Apply critical thinking skills',                     'result' => 'Competent'],
                ['semester' => 'Sem 2 2024', 'name' => 'BSBPEF301 Organise personal work priorities',                  'result' => 'Competent'],
                ['semester' => 'Sem 2 2024', 'name' => 'BSBSUS211 Participate in sustainable work practices',          'result' => 'Competent'],
                ['semester' => 'Sem 1 2025', 'name' => 'BSBTWK301 Use inclusive work practices',                       'result' => 'Competent'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // STYLE-A-TABLE (v5.9.447): [{code,title,date}] preview rows for the
            // shaded units table on the SoA / Record of Results.
            // STYLE-A / ROR-5COL: rows carry enrolment date + result code + completion date
            // so both the 3-column SoA table and the 5-column Record of Results table preview
            // with realistic data (results vary to show C / RPL / CT / NYC).
            'qualification.units_table_rows_json' => json_encode([
                ['code' => 'BSBCMM311', 'title' => 'Apply critical thinking skills in a team environment', 'enroldate' => '12 Feb 2024', 'result' => 'C',   'date' => '15 Mar 2024'],
                ['code' => 'BSBCRT311', 'title' => 'Apply critical thinking skills',                        'enroldate' => '12 Feb 2024', 'result' => 'C',   'date' => '02 May 2024'],
                ['code' => 'BSBPEF301', 'title' => 'Organise personal work priorities',                     'enroldate' => '12 Feb 2024', 'result' => 'RPL', 'date' => '18 Mar 2024'],
                ['code' => 'BSBTEC301', 'title' => 'Design and produce business documents',                 'enroldate' => '05 Jan 2024', 'result' => 'CT',  'date' => '05 Jan 2024'],
                ['code' => 'BSBSUS211', 'title' => 'Participate in sustainable work practices',             'enroldate' => '12 Feb 2024', 'result' => 'NYC', 'date' => ''],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cert.coursetitle'           => 'Certificate III in Business',
            'cert.number'                => 'CERT-2026-PREVIEW',
            'cert.issuedate'             => userdate($issuets, '%d %B %Y'),
            'cert.issuedate_ts'          => $issuets,
            'cert.completiondate'        => userdate($issuets - (15 * DAYSECS), '%d %B %Y'),
            'rto.name'                   => get_config('local_rtocompliance', 'rtoname')        ?: 'National Compliance Training',
            // TOID-PREFIX (v6.2.51): preview mirrors the issued cert ("TOID <code>").
            'rto.code'                   => self::format_rto_code((string) (get_config('local_rtocompliance', 'rtocode') ?: '30772')),
            'signatory.name'             => get_config('local_rtocompliance', 'signatoryname')  ?: 'Dr A. Authorised',
            'signatory.title'            => get_config('local_rtocompliance', 'signatorytitle') ?: 'Chief Executive Officer',
            'aqf_statement'              => get_config('local_rtocompliance', 'aqfstatement')   ?: 'This qualification is recognised within the Australian Qualifications Framework.',
            'not_a_testamur_statement'   => get_config('local_rtocompliance', 'not_a_testamur_statement') ?: 'A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS. THIS IS NOT A TESTAMUR.',
            'verify.url'                 => (new \moodle_url('/local/rtocompliance/verify.php', ['token' => 'PREVIEWTOKEN']))->out(false),
        ]);
    }

    /**
     * TOID-PREFIX (v6.2.51) — format the RTO code for display on every certificate
     * as "TOID 30772" rather than the bare number. Idempotent: if the stored code
     * already begins with a recognised prefix (TOID / RTO / RTO ID / Provider),
     * it is returned unchanged so it never double-prefixes. Empty stays empty.
     *
     * @param string $code raw RTO/TOID code from config or snapshot
     * @return string prefixed code (e.g. "TOID 30772"), or '' when no code is set
     */
    public static function format_rto_code(string $code): string {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        // Already carries an identifying prefix — leave as-is.
        if (preg_match('/^\s*(TOID|RTO\s*ID|RTO|Provider(\s*(No|Number|Code))?)\b/i', $code)) {
            return $code;
        }
        return 'TOID ' . $code;
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
     * TCPDF accepts only a small set of built-in fonts without embedding extras.
     * FONTS (v6.2.63): a certificate font may now be a Google-font key chosen in the editor. The
     * editor canvas previews the real typeface (webfont); on the PDF we render it in the CLOSEST
     * built-in family (from the font catalogue's 'core') so the certificate always renders and can
     * never fail on a missing font. If a TCPDF-ready embedded font file for the key has been added
     * to the plugin's fonts/ directory (fonts/<key>.php, generated from the TTF), it is used
     * verbatim so the PDF matches the editor exactly. Any error falls back to helvetica.
     */
    private static function sanitise_font(string $font): string {
        global $CFG;
        $font = strtolower(trim($font));
        $core = ['helvetica', 'times', 'courier'];
        if (in_array($font, $core, true)) {
            return $font;
        }
        try {
            $catalogue = cert_template::font_catalogue();
            if (isset($catalogue[$font])) {
                // Exact embedded font present? (admin/dev dropped a TCPDF font file in fonts/.)
                $fontfile = $CFG->dirroot . '/local/rtocompliance/fonts/' . $font . '.php';
                if (is_readable($fontfile)) {
                    return $font; // TCPDF loads fonts/<key>.php by name from its font path.
                }
                // Otherwise render in the closest built-in family.
                return $catalogue[$font]['core'] ?? 'helvetica';
            }
        } catch (\Throwable $e) {
            // Fall through to the safe default.
        }
        return 'helvetica';
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
