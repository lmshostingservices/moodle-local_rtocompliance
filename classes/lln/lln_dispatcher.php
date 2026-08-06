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

// LLN DISPATCHER (v4.2.50) — picks the active adapter and fetches the
// student's ACSF level.  Always falls back to the manual adapter if the
// configured one fails or returns null, so trainer-entered levels are
// never silently lost.

namespace local_rtocompliance\lln;

defined('MOODLE_INTERNAL') || die();

class lln_dispatcher {
    /**
     * Build the active adapter from the `lln_adapter` site setting.
     * Defaults to manual.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
    public static function get_active_adapter(): adapter_interface {
        $code = (string) get_config('local_rtocompliance', 'lln_adapter');
        if ($code === 'webhook') {
            return new webhook_adapter();
        }
        return new manual_adapter();
    }

    /**
     * Fetch a level for this student/qualification.  Tries the active
     * adapter first; if it returns null (and the active adapter isn't
     * already manual), falls back to the manual adapter.
     *
     * @return array|null see adapter_interface::get_level().
     */
    public static function fetch_for(int $userid, int $tasid, ?\stdClass $suit = null): ?array {
        $active = self::get_active_adapter();
        $result = $active->get_level($userid, $tasid, $suit);
        if ($result !== null) {
            return $result;
        }
        if ($active->get_code() !== 'manual') {
            return (new manual_adapter())->get_level($userid, $tasid, $suit);
        }
        return null;
    }

    /**
     * Map of available adapter codes → human labels for the settings UI.
     */
    public static function available_adapters(): array {
        return [
            'manual'  => get_string('lln_adapter_manual',  'local_rtocompliance'),
            'webhook' => get_string('lln_adapter_webhook', 'local_rtocompliance'),
        ];
    }
}
