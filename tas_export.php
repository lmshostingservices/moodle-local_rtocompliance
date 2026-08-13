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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_rtocompliance_tas');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$context = context_system::instance();

$id = required_param('id', PARAM_INT);
$format = optional_param('format', 'html', PARAM_ALPHA);

$tas = $DB->get_record('local_rtocompliance_tas', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/rtocompliance/tas_export.php', ['id' => $id, 'format' => $format]));
$PAGE->set_pagelayout('print');

function format_section_content($content) {
    if (empty($content)) {
        return '<em class="text-muted">No content entered</em>';
    }
    return nl2br(htmlspecialchars($content));
}

function get_section_content($tas, $sectionnum) {
    switch ($sectionnum) {
        case 1:
            $content = [];
            $content[] = "Qualification Code: " . $tas->qualificationcode;
            $content[] = "Qualification Name: " . $tas->qualificationname;
            if (!empty($tas->traininggovlink)) {
                $content[] = "Training.gov.au: " . $tas->traininggovlink;
            }
            if (!empty($tas->scopedetails)) {
                $content[] = "Scope Details:\n" . $tas->scopedetails;
            }
            return implode("\n\n", $content);
        case 2:
            $content = [];
            if (!empty($tas->targetcohort)) {
                $content[] = "Target Learner Cohort:\n" . $tas->targetcohort;
            }
            if (!empty($tas->entryrequirements)) {
                $content[] = "Entry Requirements:\n" . $tas->entryrequirements;
            }
            if (!empty($tas->llnrequirements)) {
                $content[] = "LLN Requirements:\n" . $tas->llnrequirements;
            }
            if (!empty($tas->prerequisites)) {
                $content[] = "Prerequisites:\n" . $tas->prerequisites;
            }
            return implode("\n\n", $content);
        case 3:
            $content = [];
            if (!empty($tas->industryconsultation)) {
                $content[] = $tas->industryconsultation;
            }
            if (!empty($tas->jobroles)) {
                $content[] = "Job Roles & Outcomes:\n" . $tas->jobroles;
            }
            return implode("\n\n", $content);
        case 4:
            $content = [];
            if (!empty($tas->deliverymode)) {
                $modedata = $tas->deliverymode;
                if (is_string($modedata) && substr($modedata, 0, 1) === '[') {
                    $modes = json_decode($modedata, true);
                    if (is_array($modes)) {
                        $content[] = "Delivery Modes: " . implode(', ', $modes);
                    }
                } else {
                    $modes = ['classroom' => 'Classroom-based', 'workplace' => 'Workplace-based', 'online' => 'Online/Distance', 'blended' => 'Blended Delivery', 'mixed' => 'Mixed Mode'];
                    $content[] = "Primary Delivery Mode: " . ($modes[$modedata] ?? $modedata);
                }
            }
            if (!empty($tas->deliverystartdate)) {
                $content[] = "Delivery Start Date: " . userdate($tas->deliverystartdate, '%d %B %Y');
            }
            if (!empty($tas->durationweeks)) {
                $content[] = "Duration: " . $tas->durationweeks . " weeks";
            }
            if (!empty($tas->nominalhours)) {
                $content[] = "Nominal Hours: " . $tas->nominalhours;
            }
            if (!empty($tas->volumeoflearning)) {
                $content[] = "Volume of Learning: " . $tas->volumeoflearning . " hours";
            }
            if (!empty($tas->hoursperweek)) {
                $content[] = "Hours per Week per Unit: " . $tas->hoursperweek;
            }
            if (!empty($tas->deliveryschedule)) {
                $content[] = "\nDelivery Schedule:\n" . $tas->deliveryschedule;
            }
            if (!empty($tas->learningbreakdown)) {
                $content[] = "\nVolume of Learning Breakdown:\n" . $tas->learningbreakdown;
            }
            if (!empty($tas->volumejustification)) {
                $content[] = "\nTAS Volume of Learning Justification:\n" . $tas->volumejustification;
            }
            return implode("\n", $content);
        case 5:
            $content = [];
            if (!empty($tas->assessmentmethods)) {
                $content[] = "Assessment Methods:\n" . $tas->assessmentmethods;
            }
            if (!empty($tas->assessmentmapping)) {
                $content[] = "Assessment Mapping to Units:\n" . $tas->assessmentmapping;
            }
            if (!empty($tas->validationschedule)) {
                $content[] = "Assessment Validation Schedule:\n" . $tas->validationschedule;
            }
            return implode("\n\n", $content);
        case 6:
            $content = [];
            if (!empty($tas->trainerrequirements)) {
                $content[] = "Trainer/Assessor Requirements:\n" . $tas->trainerrequirements;
            }
            if (!empty($tas->supervisionarrangements)) {
                $content[] = "Supervision Arrangements:\n" . $tas->supervisionarrangements;
            }
            return implode("\n\n", $content);
        case 7:
            $content = [];
            if (!empty($tas->learningresources)) {
                $content[] = "Learning Resources & Materials:\n" . $tas->learningresources;
            }
            if (!empty($tas->facilities)) {
                $content[] = "Facilities & Equipment:\n" . $tas->facilities;
            }
            if (!empty($tas->technology)) {
                $content[] = "Technology Requirements:\n" . $tas->technology;
            }
            return implode("\n\n", $content);
        case 8:
            return $tas->thirdparty ?? '';
        case 9:
            $content = [];
            if (!empty($tas->learnersupport)) {
                $content[] = "Learner Support Services:\n" . $tas->learnersupport;
            }
            if (!empty($tas->accessibility)) {
                $content[] = "Accessibility & Reasonable Adjustments:\n" . $tas->accessibility;
            }
            return implode("\n\n", $content);
        case 10:
            $content = [];
            if (!empty($tas->marketinginfo)) {
                $content[] = "Pre-Enrolment Information:\n" . $tas->marketinginfo;
            }
            if (!empty($tas->feesinformation)) {
                $content[] = "Fees & Payment Information:\n" . $tas->feesinformation;
            }
            return implode("\n\n", $content);
        case 11:
            $content = [];
            if (!empty($tas->hasworkplacement)) {
                $content[] = "Work Placement Required: Yes";
                if (!empty($tas->placementhours)) {
                    $content[] = "Work Placement Hours: " . $tas->placementhours;
                }
                if (!empty($tas->placementdetails)) {
                    $content[] = "Placement Details:\n" . $tas->placementdetails;
                }
            } else {
                $content[] = "Work Placement Required: No";
            }
            return implode("\n", $content);
        case 12:
            return $tas->transitionplan ?? '';
        case 13:
            return $tas->riskmanagement ?? '';
        case 14:
            return $tas->complaintsprocess ?? '';
        case 15:
            return $tas->continuousimprovement ?? '';
        case 16:
            $content = [];
            $content[] = "Document Status: " . ucfirst($tas->status ?? 'draft');
            $content[] = "Version: " . ($tas->version ?? '1.0');
            if (!empty($tas->approvedby)) {
                $content[] = "Approved By: " . $tas->approvedby;
            }
            if (!empty($tas->approvaldate)) {
                $content[] = "Approval Date: " . userdate($tas->approvaldate, '%d %B %Y');
            }
            if (!empty($tas->nextreviewdate)) {
                $content[] = "Next Review Date: " . userdate($tas->nextreviewdate, '%d %B %Y');
            }
            if (!empty($tas->revisionnotes)) {
                $content[] = "Revision Notes:\n" . $tas->revisionnotes;
            }
            return implode("\n", $content);
        default:
            return '';
    }
}

$sectionDefinitions = [
    1 => ['title' => 'RTO & Training Product Details'],
    2 => ['title' => 'Target Learner Cohort & Entry Requirements'],
    3 => ['title' => 'Industry Consultation'],
    4 => ['title' => 'Training & Assessment Delivery Structure'],
    5 => ['title' => 'Assessment Plan & Validation'],
    6 => ['title' => 'Trainer & Assessor Requirements'],
    7 => ['title' => 'Learning Resources & Equipment'],
    8 => ['title' => 'Third-Party Arrangements'],
    9 => ['title' => 'Learner Support & Wellbeing'],
    10 => ['title' => 'Marketing & Pre-Enrolment Information'],
    11 => ['title' => 'Work Placement Requirements'],
    12 => ['title' => 'Transition, Expiry & Teach-Out Procedures'],
    13 => ['title' => 'Risk Management'],
    14 => ['title' => 'Complaints & Appeals'],
    15 => ['title' => 'Continuous Improvement'],
    16 => ['title' => 'TAS Approval & Review'],
];

$createdby = '';
if (!empty($tas->createdby)) {
    $creator = $DB->get_record('user', ['id' => $tas->createdby], 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
    if ($creator) {
        $createdby = fullname($creator);
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TAS Export - <?php echo htmlspecialchars($tas->qualificationcode); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1f2937;
            margin: 0;
            padding: 20px;
            background: #fff;
        }
        .header {
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 24pt;
            color: #3b82f6;
        }
        .header .subtitle {
            font-size: 14pt;
            color: #4b5563;
            margin: 0;
        }
        .meta-info {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        .meta-item label {
            font-size: 9pt;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .meta-item span {
            font-size: 11pt;
            color: #1f2937;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        .section {
            page-break-inside: avoid;
            margin-bottom: 24px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .section-header {
            background: #3b82f6;
            color: white;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-number {
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 10pt;
            font-weight: 600;
        }
        .section-title {
            font-size: 12pt;
            font-weight: 600;
            margin: 0;
        }
        .section-content {
            padding: 16px;
            background: #fff;
        }
        .section-content p {
            margin: 0 0 12px 0;
        }
        .section-content p:last-child {
            margin-bottom: 0;
        }
        .text-muted {
            color: #9ca3af;
            font-style: italic;
        }
        .toc {
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .toc h2 {
            margin: 0 0 16px 0;
            font-size: 14pt;
            color: #3b82f6;
        }
        .toc-list {
            list-style: none;
            padding: 0;
            margin: 0;
            column-count: 2;
            column-gap: 24px;
        }
        .toc-list li {
            padding: 4px 0;
            font-size: 10pt;
            break-inside: avoid;
        }
        .toc-list li span.num {
            display: inline-block;
            width: 24px;
            font-weight: 600;
            color: #3b82f6;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 9pt;
            color: #6b7280;
            text-align: center;
        }
        .completeness-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 6px;
        }
        .completeness-fill {
            height: 100%;
            background: #3b82f6;
            transition: width 0.3s;
        }
        @media print {
            body {
                padding: 0;
            }
            .section {
                page-break-inside: avoid;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 11pt;">
            Print / Save as PDF
        </button>
        <a href="<?php echo new moodle_url('/local/rtocompliance/tas.php'); ?>" style="margin-left: 10px; color: #3b82f6; text-decoration: none;">
            &larr; Back to TAS List
        </a>
    </div>

    <div class="header">
        <h1>Training and Assessment Strategy</h1>
        <p class="subtitle"><?php echo htmlspecialchars($tas->qualificationcode . ' - ' . $tas->qualificationname); ?></p>
    </div>

    <div class="meta-info">
        <div class="meta-item">
            <label>Qualification Code</label>
            <span><?php echo htmlspecialchars($tas->qualificationcode); ?></span>
        </div>
        <div class="meta-item">
            <label>Qualification Name</label>
            <span><?php echo htmlspecialchars($tas->qualificationname); ?></span>
        </div>
        <div class="meta-item">
            <label>Version</label>
            <span>v<?php echo htmlspecialchars($tas->version); ?></span>
        </div>
        <div class="meta-item">
            <label>Status</label>
            <span class="badge badge-<?php
                $safestatus = htmlspecialchars($tas->status ?? '', ENT_QUOTES, 'UTF-8');
                echo ($tas->status === 'approved' || $tas->status === 'published') ? 'success' :
                    ($tas->status === 'review' ? 'info' : 'warning');
            ?>"><?php echo htmlspecialchars(ucfirst($tas->status ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="meta-item">
            <label>Completeness</label>
            <span><?php echo ($tas->completeness ?? 0) . '%'; ?></span>
            <div class="completeness-bar">
                <div class="completeness-fill" style="width: <?php echo (int)($tas->completeness ?? 0); ?>%"></div>
            </div>
        </div>
        <div class="meta-item">
            <label>Created By</label>
            <span><?php echo htmlspecialchars($createdby ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="meta-item">
            <label>Created</label>
            <span><?php echo userdate($tas->timecreated, '%d %B %Y'); ?></span>
        </div>
        <div class="meta-item">
            <label>Last Modified</label>
            <span><?php echo userdate($tas->timemodified, '%d %B %Y'); ?></span>
        </div>
    </div>

    <div class="toc">
        <h2>Table of Contents</h2>
        <ul class="toc-list">
            <?php foreach ($sectionDefinitions as $num => $def): ?>
                <li><span class="num"><?php echo $num; ?>.</span> <?php echo $def['title']; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php foreach ($sectionDefinitions as $num => $def): ?>
        <div class="section" id="section-<?php echo $num; ?>">
            <div class="section-header">
                <span class="section-number">Section <?php echo $num; ?></span>
                <h3 class="section-title"><?php echo $def['title']; ?></h3>
            </div>
            <div class="section-content">
                <?php 
                $sectionContent = get_section_content($tas, $num);
                echo format_section_content($sectionContent);
                ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="footer">
        <p>Generated on <?php echo userdate(time(), '%d %B %Y at %H:%M'); ?> | RTO Compliance Management System</p>
        <p>This is an official Training and Assessment Strategy document for ASQA compliance purposes.</p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

echo $html;
