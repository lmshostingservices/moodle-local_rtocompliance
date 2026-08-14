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
 * RTO Compliance plugin — cert_template_preview.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// CERT-TEMPLATE-BUILDER (v4.2.40) — preview endpoint.
//
// Streams a TCPDF render of the current saved design using sample payload
// data so the user can sanity-check the layout before Submitting/Activating.
// Always renders inline (Content-Disposition: inline) so the browser's PDF
// viewer opens it in a new tab.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/cert_template_renderer.php');

use local_rtocompliance\cert_template;
use local_rtocompliance\cert_template_renderer;

$id = required_param('id', PARAM_INT);

require_login();
require_capability('local/rtocompliance:managecerttemplates', context_system::instance());

$template = cert_template::get($id);
if (!$template) {
    throw new moodle_exception('invalidaccess');
}

// LIVE-PREVIEW (v5.9.366) — when the editor POSTs the current (unsaved) design,
// render THAT instead of the saved row so the preview reflects edits immediately.
// Requires a valid sesskey + the same managecerttemplates capability already
// enforced above. Falls back to the saved template on any malformed payload.
// The synthetic template goes through the identical render()/resolve path as a
// real issue, so the preview is byte-for-byte what will be issued.
$livejson = optional_param('designjson', '', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — JSON document, json_decode()'d immediately and rejected if it does not decode
if ($livejson !== '' && confirm_sesskey()) {
    $decoded = json_decode($livejson, true);
    if (is_array($decoded) && isset($decoded['page'])) {
        $template = (object) [
            'id'         => $template->id,
            'certtype'   => $template->certtype,
            'audience'   => $template->audience ?? 'default',
            'name'       => $template->name,
            'designjson' => json_encode($decoded, JSON_UNESCAPED_SLASHES),
        ];
    }
}

$payload = cert_template_renderer::sample_payload($template->certtype);

// F5 (v5.9.390): apply the template's per-template payload overrides in the preview
// too, exactly as the real issue path does (lib.php), so a template that stamps an
// audience-specific statement via designjson.overrides{} previews faithfully.
try {
    $previewdesign = cert_template::decode_design($template);
    if (!empty($previewdesign['overrides']) && is_array($previewdesign['overrides'])) {
        foreach ($previewdesign['overrides'] as $ovkey => $ovval) {
            if ($ovval === '' || $ovval === null) {
                continue;
            }
            $payload[(string) $ovkey] = $ovval;
        }
    }
} catch (\Throwable $eov) {
    debugging('cert preview overrides apply failed (non-fatal): ' . $eov->getMessage(), DEBUG_DEVELOPER);
}

// Render with the single-page / full-page / no-sidebar viewer preference so the in-editor
// live preview shows the whole certificate instead of the browser's dual thumbnail view.
$pdfdata = cert_template_renderer::render($template, $payload, '', true);

$filename = 'preview-' . $template->certtype . '-' . $template->id . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdfdata));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $pdfdata;
exit;
