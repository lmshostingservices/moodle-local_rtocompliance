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
 * RTO Compliance plugin — cert_template_validate.php.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// LIVE-VALIDATION (v6.2.16) — AJAX endpoint that re-runs the ASQA validator on the
// current (unsaved) template design and returns the freshly rendered validator-panel
// HTML. The editor calls this after every field add / delete / dynamickey change and
// swaps the panel, so a recommendation or error clears the instant the author follows
// it — no save + full page reload required.
//
// This runs the EXACT SAME certificate_validator::validate_template_design() the edit
// page and the submit-for-approval gate run, and renders the panel through the EXACT
// SAME certificate_validator::render_validation_panel_html(). Single source of truth:
// the live panel can never disagree with what the server would decide on save.
//
// Read-only: this endpoint NEVER writes. It validates the posted design in memory and
// returns HTML. Nothing is persisted until the author clicks Save draft.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/certificate_validator.php');
require_once(__DIR__ . '/classes/cert_template.php');

use local_rtocompliance\certificate_validator;
use local_rtocompliance\cert_template;

require_login();
require_capability('local/rtocompliance:managecerttemplates', context_system::instance());
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

try {
    $certtype = required_param('certtype', PARAM_ALPHA);
    $designjson = required_param('design', PARAM_RAW); // pipeline-ignore: PARAM_RAW — JSON blob, json_decode()'d by validate_template_design(); never stored or echoed raw.

    // Note: we deliberately do NOT restrict to cert_template::CERT_TYPES here.
    // validate_template_design() is the single source of truth and handles every
    // rule-set key (including 'attendance') plus unknown types gracefully, so the
    // live panel always mirrors exactly what the server would decide on save.

    // Guard against oversized payloads.
    if (strlen($designjson) > 4 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Design payload too large to validate.']);
        exit;
    }

    $design = json_decode($designjson, true);
    if (!is_array($design)) {
        echo json_encode(['ok' => false, 'error' => 'Malformed design payload.']);
        exit;
    }

    $validation = certificate_validator::validate_template_design($certtype, $design);
    $html = certificate_validator::render_validation_panel_html($validation);

    echo json_encode([
        'ok'          => true,
        'html'        => $html,
        'isCompliant' => !empty($validation['isCompliant']),
        'errorcount'  => count($validation['errors'] ?? []),
        'warningcount' => count($validation['warnings'] ?? []),
    ]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Validation failed: ' . $e->getMessage()]);
}
