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
 * Hook callbacks for local_rtocompliance.
 *
 * @package   local_rtocompliance
 * @copyright 2025 LMS Labs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_rtocompliance\hook\before_standard_head_html_generation::class . '::callback',
        'priority' => 500,
    ],
    [
        // STRANDED-VERSION BANNER (v6.3.9): shown to site administrators on every page
        // while the recorded plugin version is higher than the installed files declare.
        // Registered at the top of the body rather than on the plugin's own pages because
        // an admin who never opens RTO Compliance still needs to know why Moodle is
        // refusing their updates.
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => \local_rtocompliance\hook\before_standard_top_of_body_html_generation::class . '::callback',
        'priority' => 500,
    ],
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => \local_rtocompliance\hook\before_footer_html_generation::class . '::callback',
        'priority' => 500,
    ],
];
