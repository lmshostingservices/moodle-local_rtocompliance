<?php
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
