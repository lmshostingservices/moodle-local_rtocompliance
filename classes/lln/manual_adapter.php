<?php
// LLN ADAPTER (v4.2.50) — manual / trainer-entry adapter (default).
//
// Reads the ACSF level the trainer recorded on the suitability_send
// screen.  This is the v4.2.47 baseline behaviour — wrapping it in an
// adapter makes the rest of the system uniform regardless of which
// LLN provider is configured.

namespace local_rtocompliance\lln;

defined('MOODLE_INTERNAL') || die();

class manual_adapter implements adapter_interface {

    public function get_code(): string {
        return 'manual';
    }

    public function get_label(): string {
        return get_string('lln_adapter_manual', 'local_rtocompliance');
    }

    public function get_level(int $userid, int $tasid, ?\stdClass $suit = null): ?array {
        if (!$suit) {
            return null;
        }
        $level = isset($suit->lln_actual_level) ? trim((string) $suit->lln_actual_level) : '';
        if (!in_array($level, ['1', '2', '3', '4', '5'], true)) {
            return null;
        }
        // Use the existing assessed_at / assessor if already stamped, else fall back.
        $assessedat = !empty($suit->lln_assessed_at) ? (int) $suit->lln_assessed_at
            : (int) ($suit->timecreated ?? time());
        $assessor   = !empty($suit->lln_assessor) ? (string) $suit->lln_assessor
            : get_string('lln_assessor_trainer', 'local_rtocompliance');
        return [
            'level'        => $level,
            'source'       => 'manual',
            'assessed_at'  => $assessedat,
            'assessor'     => $assessor,
        ];
    }
}
