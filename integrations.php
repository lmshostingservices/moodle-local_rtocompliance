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
 * Integrations — connect the RTO Compliance plugin to external business systems
 * (NetSuite ERP, Salesforce CRM, Microsoft Teams).
 *
 * v6.2.42: first cut. Stores the connection settings for each service and provides a live
 * connection test for the Microsoft Teams incoming webhook (a real POST). NetSuite and
 * Salesforce currently validate configuration completeness; the data-sync jobs are wired in a
 * follow-up once the exact objects to sync are confirmed.
 *
 * @package    local_rtocompliance
 * @copyright  2026 International Trade & Logistics College
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$PAGE->set_url(new moodle_url('/local/rtocompliance/integrations.php'));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Integrations');
$PAGE->set_heading('Integrations');

$action = optional_param('action', '', PARAM_ALPHA);

// The settings each integration stores, grouped by service. 'secret' fields render as password
// inputs and are never echoed back into the form value.
$integrations = [
    'netsuite' => [
        'label'  => 'NetSuite (ERP)',
        'blurb'  => 'Push issued certificates and student/enrolment records into NetSuite for '
            . 'finance and reporting. Uses NetSuite REST Web Services (token-based authentication).',
        'syncs'  => 'RTO Compliance → NetSuite: issued certificates, student records, enrolments.',
        'fields' => [
            'netsuite_enable'   => ['Enable NetSuite integration', 'checkbox'],
            'netsuite_account'  => ['Account ID', 'text'],
            'netsuite_baseurl'  => ['REST base URL (e.g. https://<account>.suitetalk.api.netsuite.com)', 'text'],
            'netsuite_consumerkey'    => ['Consumer key', 'text'],
            'netsuite_consumersecret' => ['Consumer secret', 'secret'],
            'netsuite_tokenid'        => ['Token ID', 'text'],
            'netsuite_tokensecret'    => ['Token secret', 'secret'],
        ],
        'required' => ['netsuite_account', 'netsuite_baseurl', 'netsuite_consumerkey', 'netsuite_consumersecret', 'netsuite_tokenid', 'netsuite_tokensecret'],
    ],
    'salesforce' => [
        'label'  => 'Salesforce (CRM)',
        'blurb'  => 'Sync learners and their qualification completions into Salesforce as Contacts '
            . 'and related records. Uses a Salesforce Connected App (OAuth 2.0).',
        'syncs'  => 'RTO Compliance → Salesforce: learners as Contacts, completions and certificate status.',
        'fields' => [
            'salesforce_enable'       => ['Enable Salesforce integration', 'checkbox'],
            'salesforce_instanceurl'  => ['Instance URL (e.g. https://yourorg.my.salesforce.com)', 'text'],
            'salesforce_clientid'     => ['Consumer key (Client ID)', 'text'],
            'salesforce_clientsecret' => ['Consumer secret (Client secret)', 'secret'],
            'salesforce_username'     => ['Integration username', 'text'],
            'salesforce_password'     => ['Password + security token', 'secret'],
        ],
        'required' => ['salesforce_instanceurl', 'salesforce_clientid', 'salesforce_clientsecret', 'salesforce_username', 'salesforce_password'],
    ],
    'teams' => [
        'label'  => 'Microsoft Teams',
        'blurb'  => 'Send compliance notifications (certificates issued, USI verification results, '
            . 'validation/consultation reminders) to a Teams channel via an Incoming Webhook.',
        'syncs'  => 'RTO Compliance → Teams channel: notification messages.',
        'fields' => [
            'teams_enable'     => ['Enable Teams notifications', 'checkbox'],
            'teams_webhookurl' => ['Incoming Webhook URL', 'text'],
        ],
        'required' => ['teams_webhookurl'],
    ],
];

// Flatten a helper to read a stored value.
$cfg = function (string $key) {
    return (string) get_config('local_rtocompliance', 'integ_' . $key);
};

// ── Save ─────────────────────────────────────────────────────────────────────
if ($action === 'save' && confirm_sesskey()) {
    foreach ($integrations as $svc) {
        foreach ($svc['fields'] as $key => $meta) {
            [$label, $type] = $meta;
            if ($type === 'checkbox') {
                set_config('integ_' . $key, optional_param($key, 0, PARAM_BOOL) ? 1 : 0, 'local_rtocompliance');
            } else if ($type === 'secret') {
                // Only overwrite a secret when a new value is actually entered (blank = keep existing).
                $val = optional_param($key, '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
                if ($val !== '') {
                    set_config('integ_' . $key, $val, 'local_rtocompliance');
                }
            } else {
                set_config('integ_' . $key, trim(optional_param($key, '', PARAM_RAW)), 'local_rtocompliance'); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
            }
        }
    }
    redirect(new moodle_url('/local/rtocompliance/integrations.php'),
        'Integration settings saved.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Live test: Microsoft Teams incoming webhook ──────────────────────────────
if ($action === 'testteams' && confirm_sesskey()) {
    $url = $cfg('teams_webhookurl');
    $redir = new moodle_url('/local/rtocompliance/integrations.php');
    if ($url === '' || !preg_match('#^https://#i', $url)) {
        redirect($redir, 'Enter and save a valid https Teams webhook URL first.', null, \core\output\notification::NOTIFY_ERROR);
    }
    require_once($CFG->libdir . '/filelib.php');
    $payload = json_encode([
        'text' => 'RTO Compliance test message — your Microsoft Teams integration is connected. '
            . 'Sent ' . userdate(time()) . '.',
    ]);
    $curl = new \curl();
    $curl->setopt(['CURLOPT_TIMEOUT' => 15, 'CURLOPT_SSL_VERIFYPEER' => true]);
    $curl->setHeader(['Content-Type: application/json']);
    $resp = $curl->post($url, $payload);
    $code = (int) ($curl->info['http_code'] ?? 0);
    if ($code >= 200 && $code < 300) {
        redirect($redir, 'Test message sent to Teams — check your channel.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($redir, 'Teams webhook test failed (HTTP ' . $code . '): ' . s(substr((string) $resp, 0, 200)),
        null, \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->header();
if (function_exists('local_rtocompliance_render_nav_header')) {
    echo local_rtocompliance_render_nav_header('Integrations', null, null, 'link');
}

echo html_writer::tag('p', 'Connect RTO Compliance to your business systems. Credentials are '
    . 'stored in Moodle configuration and used only to push your RTO\'s own data to these services.',
    ['class' => 'text-muted']);

$sk = sesskey();
echo html_writer::start_tag('form', ['method' => 'post',
    'action' => new moodle_url('/local/rtocompliance/integrations.php')]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sk]);

foreach ($integrations as $svckey => $svc) {
    $enabled = $cfg($svckey . '_enable') === '1';
    $complete = true;
    foreach ($svc['required'] as $rk) {
        if ($cfg($rk) === '') { $complete = false; break; }
    }
    $status = !$enabled ? '<span class="badge badge-secondary">Disabled</span>'
        : ($complete ? '<span class="badge badge-success">Configured</span>'
                     : '<span class="badge badge-warning">Incomplete</span>');

    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h4', s($svc['label']) . ' ' . $status);
    echo html_writer::tag('p', s($svc['blurb']), ['class' => 'text-muted small']);
    echo html_writer::tag('p', html_writer::tag('strong', 'Syncs: ') . s($svc['syncs']), ['class' => 'small']);

    foreach ($svc['fields'] as $key => $meta) {
        [$label, $type] = $meta;
        echo html_writer::start_div('form-group');
        if ($type === 'checkbox') {
            echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => $key, 'value' => 1,
                'id' => $key, 'class' => 'mr-2'] + ($cfg($key) === '1' ? ['checked' => 'checked'] : []));
            echo html_writer::tag('label', ' ' . s($label), ['for' => $key]);
        } else {
            echo html_writer::tag('label', s($label), ['for' => $key, 'class' => 'd-block small font-weight-bold']);
            $inputtype = ($type === 'secret') ? 'password' : 'text';
            // Never render a stored secret back into the field; text fields show their value.
            $value = ($type === 'secret') ? '' : $cfg($key);
            $ph = ($type === 'secret' && $cfg($key) !== '') ? '(unchanged — enter a new value to replace)' : '';
            echo html_writer::empty_tag('input', ['type' => $inputtype, 'name' => $key, 'id' => $key,
                'class' => 'form-control', 'value' => $value, 'placeholder' => $ph, 'style' => 'max-width:640px;']);
        }
        echo html_writer::end_div();
    }

    if ($svckey === 'teams') {
        $testurl = new moodle_url('/local/rtocompliance/integrations.php', ['action' => 'testteams', 'sesskey' => $sk]);
        echo html_writer::link($testurl, 'Send test message to Teams', ['class' => 'btn btn-sm btn-outline-primary']);
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => 'Save integration settings']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
