<?php
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
