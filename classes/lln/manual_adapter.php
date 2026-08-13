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
 * RTO Compliance plugin — manual_adapter.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
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
