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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
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

$payload = cert_template_renderer::sample_payload($template->certtype);
$pdfdata = cert_template_renderer::render($template, $payload);

$filename = 'preview-' . $template->certtype . '-' . $template->id . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdfdata));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $pdfdata;
exit;
