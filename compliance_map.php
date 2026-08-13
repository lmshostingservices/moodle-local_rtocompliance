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
 * RTO Compliance plugin — compliance_map.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * COMPLIANCE MAP (v5.9.383)
 *
 * The full directory of RTO Compliance features, organised by the Standards for
 * RTOs 2025 Quality Areas. Moved here from the dashboard (which was cramped into
 * six skinny columns) and given a responsive layout — each Quality Area is a
 * full-width section whose cards reflow to the screen width.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_compliancemap');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/rtocompliance/compliance_map.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('compliancemap', 'local_rtocompliance'));
$PAGE->set_heading(get_string('compliancemap', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

/**
 * Local icon helper (mirrors the dashboard's minimal inline set; falls back to a
 * generic file glyph). Kept self-contained so this page has no hard dependency.
 */
function cmap_icon(string $name): string {
    $p = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    $paths = [
        'clipboard-list' => '<path d="M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M9 2v2h6V2"/><path d="M9 12h6M9 16h6"/>',
        'check-circle'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'badge-check'    => '<path d="M12 2l2.4 2.4 3.4-.6.6 3.4L21 12l-2.6 2.4.6 3.4-3.4-.6L12 22l-2.4-2.4-3.4.6-.6-3.4L3 12l2.6-2.4-.6-3.4 3.4.6z"/><polyline points="9 12 11 14 15 10"/>',
        'repeat'         => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'refresh-cw'     => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'building-2'     => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18"/><path d="M9 7h6M9 11h6M9 15h6"/>',
        'info'           => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'clipboard-check' => '<path d="M9 2h6a2 2 0 0 1 2 2v0H7v0a2 2 0 0 1 2-2z"/><rect x="4" y="4" width="16" height="18" rx="2"/><polyline points="9 14 11 16 15 12"/>',
        'users'          => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
        'message-circle' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'user-check'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
        'graduation-cap' => '<path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1 2 3 6 3s6-2 6-3v-5"/>',
        'building'       => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/>',
        'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'link'           => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'wallet'         => '<path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
        'shield'         => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'award'          => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'download'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'bar-chart-2'    => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'briefcase'      => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'file-clock'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><circle cx="12" cy="15" r="3"/><path d="M12 14v1l1 1"/>',
        'calendar'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'settings'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'book-open'      => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    ];
    return $p . ($paths[$name] ?? '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>') . '</svg>';
}

$moduleCategories = local_rtocompliance_compliance_modules();

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('compliancemap', 'local_rtocompliance'));
echo local_rtocompliance_page_banner(get_string('compliancemap', 'local_rtocompliance'));
?>
<style>
.cmap-wrap{max-width:1200px;margin:0 auto;padding:4px 2px 40px;}
.cmap-lead{color:#64748b;font-size:14px;margin:0 0 22px;}
.cmap-section{margin-bottom:24px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;}
.cmap-section-head{padding:12px 18px;font-weight:700;font-size:15px;color:#fff;}
.cmap-section-head .clause-ref{font-weight:500;opacity:.85;font-size:12.5px;}
.cmap-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;padding:16px 18px;}
.cmap-card{display:flex;gap:12px;align-items:flex-start;text-decoration:none;background:#f8fafc;border:1px solid #eef2f7;border-radius:10px;padding:13px 14px;transition:box-shadow .15s,transform .15s,border-color .15s;}
.cmap-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.08);transform:translateY(-1px);border-color:#dbe3ee;text-decoration:none;}
.cmap-ico{flex:0 0 34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#eef2ff;color:#4338ca;}
.cmap-info{min-width:0;}
.cmap-title{display:block;font-weight:700;font-size:13.5px;color:#111827;line-height:1.3;}
.cmap-desc{display:block;font-size:12px;color:#6b7280;margin-top:3px;line-height:1.4;}
.cmap-clauses{display:block;font-size:10.5px;color:#9ca3af;margin-top:5px;}
.cmap-amber .cmap-section-head{background:#d97706;} .cmap-amber .cmap-ico{background:#fef3c7;color:#92400e;}
.cmap-blue  .cmap-section-head{background:#2563eb;} .cmap-blue  .cmap-ico{background:#dbeafe;color:#1e40af;}
.cmap-green .cmap-section-head{background:#059669;} .cmap-green .cmap-ico{background:#d1fae5;color:#065f46;}
.cmap-purple .cmap-section-head{background:#7c3aed;} .cmap-purple .cmap-ico{background:#ede9fe;color:#5b21b6;}
.cmap-rose  .cmap-section-head{background:#e11d48;} .cmap-rose  .cmap-ico{background:#ffe4e6;color:#9f1239;}
.cmap-teal  .cmap-section-head{background:#0d9488;} .cmap-teal  .cmap-ico{background:#ccfbf1;color:#115e59;}
.cmap-slate .cmap-section-head{background:#475569;} .cmap-slate .cmap-ico{background:#e2e8f0;color:#334155;}
</style>
<div class="cmap-wrap">
  <p class="cmap-lead">Every feature, organised by the Standards for RTOs 2025 Quality Areas (effective 1 July 2025). Click any card to open that area.</p>
<?php
foreach ($moduleCategories as $category) {
    $color = $category['color'] ?? 'slate';
    echo '<div class="cmap-section cmap-' . s($color) . '">';
    echo '<div class="cmap-section-head">' . $category['title'] . '</div>';
    echo '<div class="cmap-cards">';
    foreach ($category['modules'] as $module) {
        echo '<a class="cmap-card" href="' . (new moodle_url($module['url']))->out() . '">';
        echo '<span class="cmap-ico">' . cmap_icon($module['icon']) . '</span>';
        echo '<span class="cmap-info">';
        echo '<span class="cmap-title">' . $module['title'] . '</span>';
        echo '<span class="cmap-desc">' . $module['desc'] . '</span>';
        if (!empty($module['standards'])) {
            echo '<span class="cmap-clauses">Clauses: ' . s($module['standards']) . '</span>';
        }
        echo '</span></a>';
    }
    echo '</div></div>';
}
?>
</div>
<?php
echo $OUTPUT->footer();
