<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify it under the terms of
// the GNU General Public License as published by the Free Software Foundation, either
// version 3 of the License, or (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY.
// See the GNU General Public License for more details. You should have received a copy of
// the GNU GPL along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * RTO Compliance plugin — faq.php.
 *
 * A beautiful, plain-English FAQ: 100 questions across 20 categories, rendered as a
 * pure-CSS accordion (no JavaScript, so it is CSP-safe). The same content
 * (local_rtocompliance_faq_data()) also trains the AI assistant.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/faq_content.php');

admin_externalpage_setup('local_rtocompliance_faq');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/rtocompliance/faq.php'));
$PAGE->set_title(get_string('faq_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('faq_title', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

$siteurl = rtrim((string) $CFG->wwwroot, '/');
$faq = local_rtocompliance_faq_data();
$totalq = 0;
foreach ($faq as $c) {
    $totalq += count($c['items']);
}

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('faq_title', 'local_rtocompliance'));

echo local_rtocompliance_page_banner(
    'Frequently Asked Questions',
    'Plain-English answers to the ' . $totalq . ' most common questions, grouped into ' . count($faq) . ' topics. New here? Start at the top — or jump to a topic below.'
);

// Self-contained styles (inline <style> is CSP-safe; inline <script> is not, so this page uses none).
echo <<<'CSS'
<style>
.rtoc-faq-wrap { max-width: 100%; }
.rtoc-faq-jump { display:flex; flex-wrap:wrap; gap:9px; margin:4px 0 26px; }
.rtoc-faq-jump a {
    display:inline-flex; align-items:center; gap:7px; text-decoration:none;
    background:#fff; border:1px solid #e2e8f0; border-radius:999px; padding:7px 14px;
    font-size:13.5px; font-weight:600; color:#334155; box-shadow:0 1px 2px rgba(15,23,42,.04);
    transition:all .15s ease;
}
.rtoc-faq-jump a:hover { border-color:#6366f1; color:#4338ca; background:#eef2ff; transform:translateY(-1px); }
.rtoc-faq-jump a .n { display:inline-flex;align-items:center;justify-content:center; width:20px;height:20px;border-radius:50%;
    background:#eef2ff;color:#4338ca;font-size:11px;font-weight:800; }
.rtoc-faq-cat { margin-bottom:30px; scroll-margin-top:80px; }
.rtoc-faq-cat-head { display:flex; align-items:center; gap:12px; margin:0 0 14px; }
.rtoc-faq-cat-num { flex:0 0 auto; width:34px;height:34px;border-radius:10px; display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; font-weight:800; font-size:15px; box-shadow:0 4px 10px -3px rgba(79,70,229,.5); }
.rtoc-faq-cat-title { font-size:20px; font-weight:800; color:#0f172a; margin:0; letter-spacing:-.01em; }
.rtoc-faq-cat-count { font-size:12.5px; color:#94a3b8; font-weight:600; margin-left:2px; }
.rtoc-faq-item { background:#fff; border:1px solid #e6e9ef; border-radius:12px; margin-bottom:10px; overflow:hidden;
    box-shadow:0 1px 2px rgba(15,23,42,.04); transition:border-color .15s ease, box-shadow .15s ease; }
.rtoc-faq-item[open] { border-color:#c7d2fe; box-shadow:0 6px 18px -8px rgba(79,70,229,.28); }
.rtoc-faq-q { cursor:pointer; list-style:none; padding:15px 18px; display:flex; align-items:center; gap:12px;
    font-size:15.5px; font-weight:650; color:#1e293b; line-height:1.4; }
.rtoc-faq-q::-webkit-details-marker { display:none; }
.rtoc-faq-q:hover { background:#f8fafc; }
.rtoc-faq-q .chev { flex:0 0 auto; margin-left:auto; width:22px;height:22px; color:#94a3b8; transition:transform .2s ease; }
.rtoc-faq-item[open] .rtoc-faq-q .chev { transform:rotate(180deg); color:#6366f1; }
.rtoc-faq-q .qmark { flex:0 0 auto; width:24px;height:24px;border-radius:7px; background:#eef2ff;color:#4338ca;
    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px; }
.rtoc-faq-a { padding:2px 18px 18px 54px; }
.rtoc-faq-a p { margin:0 0 12px; color:#475569; font-size:15px; line-height:1.68; }
.rtoc-faq-a a.rtoc-faq-golink { display:inline-flex; align-items:center; gap:6px; text-decoration:none; font-size:13.5px;
    font-weight:700; color:#4338ca; background:#eef2ff; border:1px solid #e0e7ff; border-radius:8px; padding:6px 12px; }
.rtoc-faq-a a.rtoc-faq-golink:hover { background:#e0e7ff; }
.rtoc-faq-top { display:inline-block; margin-top:6px; font-size:12.5px; color:#94a3b8; text-decoration:none; }
.rtoc-faq-top:hover { color:#6366f1; }
.rtoc-faq-help { margin:30px 0 6px; background:linear-gradient(135deg,#eef2ff,#faf5ff); border:1px solid #e0e7ff;
    border-radius:14px; padding:20px 24px; }
.rtoc-faq-help h3 { margin:0 0 6px; font-size:17px; color:#3730a3; font-weight:800; }
.rtoc-faq-help p { margin:0; color:#475569; font-size:14.5px; line-height:1.6; }
</style>
CSS;

echo '<div class="rtoc-faq-wrap" id="rtoc-faq-top">';

// Jump-to-topic chips.
echo '<div class="rtoc-faq-jump">';
foreach ($faq as $i => $c) {
    echo '<a href="#rtoc-faq-cat-' . $i . '"><span class="n">' . ($i + 1) . '</span>' . s($c['cat']) . '</a>';
}
echo '</div>';

$chev = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';

foreach ($faq as $i => $c) {
    echo '<section class="rtoc-faq-cat" id="rtoc-faq-cat-' . $i . '">';
    echo '<div class="rtoc-faq-cat-head">';
    echo '<span class="rtoc-faq-cat-num">' . ($i + 1) . '</span>';
    echo '<h2 class="rtoc-faq-cat-title">' . s($c['cat']) . ' <span class="rtoc-faq-cat-count">' . count($c['items']) . ' questions</span></h2>';
    echo '</div>';

    foreach ($c['items'] as $item) {
        echo '<details class="rtoc-faq-item">';
        echo '<summary class="rtoc-faq-q"><span class="qmark">Q</span><span>' . s($item['q']) . '</span>' . $chev . '</summary>';
        echo '<div class="rtoc-faq-a">';
        echo '<p>' . s($item['a']) . '</p>';
        $page = trim((string) ($item['page'] ?? ''));
        if ($page !== '') {
            $url = $siteurl . '/local/rtocompliance/' . $page;
            echo '<a class="rtoc-faq-golink" href="' . s($url) . '">Go to this page '
                . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></a>';
        }
        echo '</div>';
        echo '</details>';
    }
    echo '<a class="rtoc-faq-top" href="#rtoc-faq-top">&uarr; Back to top</a>';
    echo '</section>';
}

// Still-stuck helper.
echo '<div class="rtoc-faq-help">';
echo '<h3>Still stuck?</h3>';
echo '<p>Ask the <strong>AI Assistant</strong> at the bottom-right of any page (1 credit per question) — it knows this software and the ASQA 2025 Standards and will link you straight to the right page. For detailed how-to guides, open the <a href="' . s($siteurl . '/local/rtocompliance/support.php') . '">Support page</a>.</p>';
echo '</div>';

echo '</div>'; // wrap

echo $OUTPUT->footer();
