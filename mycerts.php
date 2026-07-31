<?php
// v4.7.104 STUDENT-CERT-PORTAL — Enhanced student-facing certificate document area.
//
// Replaces the basic v4.x card list with a proper certificate portfolio page:
//   - Clean grouped layout by certificate type (Testamur, SoA, RoR, Completion)
//   - Stat summary: total certs, cert types present
//   - Year filter tabs
//   - Per-cert: download PDF, verify link, cert number, qualification, issued date
//   - For SoA: expandable units of competency list
//   - Students see only their own certs; admins can view any user (userid param)

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

$userid = optional_param('userid', $USER->id, PARAM_INT);

if ($userid != $USER->id) {
    require_capability('local/rtocompliance:viewall', context_system::instance());
}

$context = context_user::instance($userid);
require_capability('local/rtocompliance:viewown', $context);

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Year filter
$filteryear = optional_param('year', 0, PARAM_INT);

$PAGE->set_url('/local/rtocompliance/mycerts.php', ['userid' => $userid, 'year' => $filteryear]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mycertificates', 'local_rtocompliance') . ' — ' . fullname($user));
$PAGE->set_heading(fullname($user) . ' — ' . get_string('mycertificates', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('mycertificates', 'local_rtocompliance'), null, null, 'certificates');

// ── Fetch all issued certs ─────────────────────────────────────────────────
$params = ['userid' => $userid, 'status' => 'issued'];
$yearsql = '';
if ($filteryear > 0) {
    $yearsql = ' AND EXTRACT(YEAR FROM to_timestamp(issuedate)) = :year ';
    $params['year'] = $filteryear;
}

$certs = $DB->get_records_sql(
    "SELECT * FROM {local_rtocompliance_certs}
     WHERE userid = :userid AND status = :status {$yearsql}
     ORDER BY issuedate DESC",
    $params
);

$certtypes = local_rtocompliance_get_certificate_types();

// Collect years for filter tabs
$allyears = $DB->get_fieldset_sql(
    "SELECT DISTINCT EXTRACT(YEAR FROM to_timestamp(issuedate))::int AS yr
     FROM {local_rtocompliance_certs}
     WHERE userid = :userid AND status = 'issued'
     ORDER BY yr DESC",
    ['userid' => $userid]
);

echo html_writer::start_div('certificates-container');

// ── Page header ────────────────────────────────────────────────────────────
echo html_writer::start_div('certificates-header');
echo html_writer::start_div('d-flex align-items-center justify-content-between flex-wrap gap-2');
echo html_writer::tag('h2', get_string('mycertificates', 'local_rtocompliance'));
if ($userid != $USER->id) {
    // Admin viewing another user
    echo html_writer::tag('span',
        'Viewing: ' . fullname($user),
        ['class' => 'badge badge-info']
    );
}
echo html_writer::end_div();
echo html_writer::end_div();

if (empty($certs)) {
    // ── Empty state ────────────────────────────────────────────────────────
    echo html_writer::start_div('no-deadlines text-center py-5');
    echo html_writer::tag('div',
        '<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="opacity:0.3">'.
        '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
        ['class' => 'mb-3 text-muted']
    );
    echo html_writer::tag('h4', 'No certificates yet', ['class' => 'text-muted']);
    echo html_writer::tag('p',
        'Your certificates will appear here once your trainer or assessor issues them after completing a course or qualification.',
        ['class' => 'text-muted']
    );
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ── Stat cards ─────────────────────────────────────────────────────────────
$typecounts = [];
foreach ($certs as $cert) {
    $typecounts[$cert->certtype] = ($typecounts[$cert->certtype] ?? 0) + 1;
}

echo html_writer::start_div('rtoc-stat-cards mb-4');
echo html_writer::start_div('rtoc-stat-card');
echo html_writer::tag('div', count($certs), ['class' => 'rtoc-stat-number']);
echo html_writer::tag('div', 'Total Certificates', ['class' => 'rtoc-stat-label']);
echo html_writer::end_div();
foreach ($typecounts as $type => $count) {
    echo html_writer::start_div('rtoc-stat-card');
    echo html_writer::tag('div', $count, ['class' => 'rtoc-stat-number']);
    echo html_writer::tag('div', $certtypes[$type] ?? $type, ['class' => 'rtoc-stat-label']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

// ── Year filter tabs ───────────────────────────────────────────────────────
if (count($allyears) > 1) {
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-4']);
    $activeclass = $filteryear === 0 ? 'nav-link active' : 'nav-link';
    echo html_writer::tag('li',
        html_writer::link(
            new moodle_url('/local/rtocompliance/mycerts.php', ['userid' => $userid]),
            'All Years',
            ['class' => $activeclass]
        ),
        ['class' => 'nav-item']
    );
    foreach ($allyears as $yr) {
        $activeclass = $filteryear === (int)$yr ? 'nav-link active' : 'nav-link';
        echo html_writer::tag('li',
            html_writer::link(
                new moodle_url('/local/rtocompliance/mycerts.php', ['userid' => $userid, 'year' => $yr]),
                (string) $yr,
                ['class' => $activeclass]
            ),
            ['class' => 'nav-item']
        );
    }
    echo html_writer::end_tag('ul');
}

// ── Group by cert type ─────────────────────────────────────────────────────
$typeorder = ['testamur', 'record', 'statement', 'completion'];
$grouped   = [];
foreach ($certs as $cert) {
    $grouped[$cert->certtype][] = $cert;
}

// Sort by typeorder
$orderedgroups = [];
foreach ($typeorder as $t) {
    if (!empty($grouped[$t])) {
        $orderedgroups[$t] = $grouped[$t];
    }
}
// Append any unexpected types
foreach ($grouped as $t => $items) {
    if (!isset($orderedgroups[$t])) {
        $orderedgroups[$t] = $items;
    }
}

foreach ($orderedgroups as $grouptype => $groupcerts) {
    $grouplabel = $certtypes[$grouptype] ?? $grouptype;

    // Section heading
    echo html_writer::start_div('mb-4');
    echo html_writer::tag('h4', $grouplabel, ['class' => 'mb-3 border-bottom pb-2']);

    echo html_writer::start_div('certificates-grid');

    foreach ($groupcerts as $cert) {
        // Parse units for SoA
        $unitsdata = [];
        if ($cert->certtype === 'statement' && !empty($cert->units)) {
            $decoded = json_decode($cert->units, true);
            if (is_array($decoded)) {
                $unitsdata = $decoded;
            }
        }

        $cardid = 'cert-card-' . $cert->id;

        echo html_writer::start_div('certificate-card');

        // Type badge
        $typebadge = [
            'testamur'   => 'badge-primary',
            'record'     => 'badge-info',
            'statement'  => 'badge-success',
            'completion' => 'badge-secondary',
        ][$cert->certtype] ?? 'badge-secondary';
        echo html_writer::tag('span', $grouplabel, ['class' => 'badge ' . $typebadge . ' mb-2']);

        // Qualification
        if ($cert->qualificationcode || $cert->qualificationname) {
            echo html_writer::tag('h5',
                ($cert->qualificationcode ? $cert->qualificationcode . ' — ' : '') . $cert->qualificationname,
                ['class' => 'mb-1']
            );
        } else {
            echo html_writer::tag('h5', $grouplabel, ['class' => 'mb-1']);
        }

        // Cert number
        echo html_writer::tag('p',
            html_writer::tag('strong', 'Certificate No: ') .
            html_writer::tag('span', $cert->certnumber, ['class' => 'certificate-number']),
            ['class' => 'mb-1']
        );

        // Issue date
        echo html_writer::tag('p',
            html_writer::tag('strong', 'Issued: ') .
            userdate($cert->issuedate, '%d %B %Y'),
            ['class' => 'mb-1 text-muted']
        );

        // Expiry date
        if (!empty($cert->expirydate)) {
            echo html_writer::tag('p',
                html_writer::tag('strong', 'Expires: ') .
                userdate($cert->expirydate, '%d %B %Y'),
                ['class' => 'mb-1 text-muted']
            );
        }

        // Units for SoA (collapsible)
        if ($cert->certtype === 'statement' && !empty($unitsdata)) {
            $unitsid = 'units-' . $cert->id;
            echo html_writer::tag('p',
                html_writer::link('#' . $unitsid,
                    count($unitsdata) . ' unit' . (count($unitsdata) > 1 ? 's' : '') . ' of competency',
                    [
                        'class'          => 'small text-info',
                        'data-toggle'    => 'collapse',
                        'aria-expanded'  => 'false',
                        'aria-controls'  => $unitsid,
                    ]
                ),
                ['class' => 'mb-1']
            );
            echo html_writer::start_div('collapse', ['id' => $unitsid]);
            echo html_writer::start_tag('ul', ['class' => 'small text-muted pl-3 mt-1']);
            foreach ($unitsdata as $unit) {
                $unittext = '';
                if (!empty($unit['code'])) {
                    $unittext .= $unit['code'] . ' — ';
                }
                $unittext .= $unit['name'] ?? '';
                echo html_writer::tag('li', htmlspecialchars($unittext));
            }
            echo html_writer::end_tag('ul');
            echo html_writer::end_div();
        }

        // Actions
        echo html_writer::start_div('certificate-actions mt-3');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/download_cert.php', ['id' => $cert->id]),
            html_writer::tag('span', '') . ' Download PDF',
            ['class' => 'btn btn-sm btn-primary', 'title' => 'Download your certificate as a PDF']
        );
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/verify.php', ['token' => $cert->verifytoken]),
            'Verify',
            ['class' => 'btn btn-sm btn-outline-secondary', 'target' => '_blank', 'title' => 'Open the public verification page for this certificate']
        );
        echo html_writer::end_div();

        echo html_writer::end_div(); // .certificate-card
    }

    echo html_writer::end_div(); // .certificates-grid
    echo html_writer::end_div(); // mb-4 group
}

echo html_writer::end_div(); // .certificates-container

echo $OUTPUT->footer();
