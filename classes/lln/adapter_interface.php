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

// LLN ADAPTER (v4.2.50) — pluggable LLN/ACSF result provider.
//
// Any RTO can wire their preferred LLN assessment system into the
// pre-enrolment suitability flow by implementing this interface and
// registering the adapter in settings.  Two adapters ship by default:
//
//   - manual_adapter   reads the level the trainer entered on the
//                      send screen (lln_actual_level on the suitability
//                      record).  Default; preserves the v4.2.47 flow.
//
//   - webhook_adapter  POSTs the student context to a configured URL
//                      and reads the returned ACSF level.  RTOs plug
//                      their own LLN system (or our future Replit LLN
//                      endpoint) in here.
//
// Adapters MUST be fail-soft — if the upstream call errors, returning
// null lets the suitability flow continue using whatever level the
// trainer recorded manually (or none).

namespace local_rtocompliance\lln;

defined('MOODLE_INTERNAL') || die();

/**
 * Pluggable LLN adapter.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
interface adapter_interface {

    /**
     * Fetch the LLN/ACSF assessment level for this student in the
     * context of this qualification.
     *
     * @param int            $userid Moodle user id.
     * @param int            $tasid  TAS / qualification id.
     * @param \stdClass|null $suit   The suitability record (may carry
     *                               trainer-provided context like
     *                               lln_actual_level).
     * @return array|null            Either null (no result) or an array:
     *                               [
     *                                 'level'        => '1'..'5',
     *                                 'source'       => 'manual'|'webhook'|'<custom>',
     *                                 'assessed_at'  => int unix ts,
     *                                 'assessor'     => string (system / trainer name),
     *                               ]
     */
    public function get_level(int $userid, int $tasid, ?\stdClass $suit = null): ?array;

    /**
     * A short machine identifier — used in audit logs and the
     * `lln_source` column.  Lowercase, [a-z0-9_].
     */
    public function get_code(): string;

    /**
     * Display name shown to trainers and students (e.g. "Trainer entry",
     * "Acme LLN Online").
     */
    public function get_label(): string;
}
