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
 * RTO Compliance plugin — plugin_settings.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// PLUGIN-SETTINGS-WRAPPER (v5.2.87): Custom settings page that shows the RTO Compliance
// sidebar nav. Renders Moodle admin settings using the existing admin_settingpage objects
// so saves still go through admin_write_settings() — no duplicate config logic needed.
//
// All settings sections are accessible via the ?section= parameter. The page uses
// admin_externalpage_setup() for auth (moodle/site:config required), then renders the
// relevant admin_settingpage via output_html() on each setting.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

// Allowed section identifiers — prevents open-redirect / section injection.
$allowed_sections = [
    'local_rtocompliance_settings',
    'local_rtocompliance_api',
    'local_rtocompliance_certs',
    'local_rtocompliance_reportconfig',
    'local_rtocompliance_usi',
    'local_rtocompliance_autosurvey',
    'local_rtocompliance_asqa2025',
    'local_rtocompliance_statefunding',
    'local_rtocompliance_maintenance',
];

$section = optional_param('section', 'local_rtocompliance_settings', PARAM_ALPHANUMEXT);
if (!in_array($section, $allowed_sections)) {
    $section = 'local_rtocompliance_settings';
}

// Auth: site admins only (same restriction as admin_settingpage entries in settings.php).
admin_externalpage_setup('local_rtocompliance_plugin_settings');
require_login();

$thisurl = new moodle_url('/local/rtocompliance/plugin_settings.php', ['section' => $section]);
$PAGE->set_url($thisurl);
$PAGE->add_body_class('path-local-rtocompliance');
$PAGE->requires->css('/local/rtocompliance/styles.css');

// Handle form submission.
if ($data = data_submitted()) {
    require_sesskey();
    $count = admin_write_settings($data);
    $msg  = ($count > 0) ? get_string('changessaved') : get_string('nochanges');
    $type = ($count > 0)
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_INFO;
    redirect($thisurl, $msg, null, $type);
}

// Load the admin tree with the full settings so ->settings is populated on each page.
$adminroot    = admin_get_root(false, true);
$settingspage = $adminroot->locate($section, true);

// Tab definitions (section key => display label).
$tabs = [
    'local_rtocompliance_settings'    => 'RTO Details',
    'local_rtocompliance_api'         => 'Platform API',
    'local_rtocompliance_certs'       => 'Certificates',
    'local_rtocompliance_usi'         => 'USI Settings',
    'local_rtocompliance_autosurvey'  => 'Auto-Survey',
    'local_rtocompliance_asqa2025'    => 'ASQA 2025',
    'local_rtocompliance_statefunding' => 'State Funding',
    'local_rtocompliance_maintenance' => 'Maintenance',
];

// ── Output ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    'Plugin Settings',
    null,
    null,
    'settings'
);
echo local_rtocompliance_page_banner('Plugin Settings');

// Tab bar.
echo '<div class="rtoc-stabs" role="tablist">';
foreach ($tabs as $tabsection => $tablabel) {
    $active = ($tabsection === $section) ? ' rtoc-stab-active' : '';
    $taburl = (new moodle_url('/local/rtocompliance/plugin_settings.php', ['section' => $tabsection]))->out(false);
    echo '<a href="' . $taburl . '" class="rtoc-stab' . $active . '" role="tab">'
       . htmlspecialchars($tablabel) . '</a>';
}
echo '</div>';

echo '<div class="rtoc-settings-body">';

if (!$settingspage instanceof admin_settingpage) {
    echo $OUTPUT->notification(
        'Settings section "' . s($section) . '" not found. The plugin may need to be reinstalled.',
        'error'
    );
} elseif (empty($settingspage->settings)) {
    echo $OUTPUT->notification(
        'No settings are available for this section.',
        'info'
    );
} else {
    $formaction = $thisurl->out(false);
    echo '<form method="post" action="' . $formaction . '"'
       . ' enctype="multipart/form-data" id="adminsettings" autocomplete="on">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="return" value="' . s($section) . '">';

    foreach ($settingspage->settings as $setting) {
        // Headings ignore $data; all others use the stored value for pre-filling.
        $value = ($setting instanceof admin_setting_heading) ? '' : $setting->get_setting();
        echo $setting->output_html($value, '');
    }

    echo '<div class="rtoc-settings-save">';
    echo '<button type="submit" class="btn btn-primary">'
       . get_string('savechanges') . '</button>';
    echo '</div>';
    echo '</form>';
}

echo '</div>'; // .rtoc-settings-body
echo $OUTPUT->footer();
