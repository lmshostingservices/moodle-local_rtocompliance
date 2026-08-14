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
 * RTO Compliance plugin — upgrade.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_rtocompliance upgrade from the given old version.
 *
 * HISTORY COLLAPSE (v6.3.0, 13 Aug 2026): every pre-6.3.0 savepoint used the
 * retired 13-digit version scheme (YYYYMMDD0NNNN), which Moodle Marketplace
 * rejects and which exceeds every correctly numbered 10-digit build, blocking
 * all future upgrades on sites that ran them. All historical step blocks have
 * been removed in favour of this single 10-digit terminal savepoint:
 *   - Fresh installs get the complete current schema from db/install.xml.
 *   - Sites already on a 6.2.x build have executed every historical step;
 *     their schema is current, so no steps are lost.
 *   - Sites whose stored version is still 13-digit cannot upgrade at all until
 *     their mdl_config_plugins version row is reset below this build's version
 *     (see version.php VERSION HYGIENE note) — after that reset this terminal
 *     savepoint brings them straight to current.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_rtocompliance_upgrade($oldversion) {
    if ($oldversion < 2026081320) {
        upgrade_plugin_savepoint(true, 2026081320, 'local', 'rtocompliance');
    }
    return true;
}
