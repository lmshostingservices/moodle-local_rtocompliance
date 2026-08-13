/**
 * Qualification Builder edit page  -  AMD module.
 *
 * Called via $PAGE->requires->js_call_amd() with no inline arguments.
 * All initialisation data is read from a <script type="application/json"
 * id="qb-init-data"> DOM element written by qualbuilder_edit.php.
 *
 * Using a DOM element avoids embedding large JSON payloads directly inside
 * a RequireJS require() call, which caused "Uncaught SyntaxError: Invalid or
 * unexpected token" inside first.js and the "No define call for core/first"
 * cascade that hid Moodle's site-admin navigation on this page.
 *
 * @module local_rtocompliance/qualbuilder_edit
 */
define('local_rtocompliance/qualbuilder_edit', ['jquery', 'core/ajax', 'core/notification'], function($, ajax, notification) {
    'use strict';

    /**
     * Initialise the qualification builder.
     *
     * Reads the INIT payload from the <script type="application/json" id="qb-init-data">
     * element embedded by qualbuilder_edit.php rather than accepting it as an argument.
     * This prevents RequireJS SyntaxErrors from large or complex inline JSON payloads.
     */
    function init() {
        var dataEl = document.getElementById('qb-init-data');
        var INIT = {};
        if (dataEl) {
            try {
                INIT = JSON.parse(dataEl.textContent || dataEl.innerText || '{}');
            } catch (e) {
                // If the payload is malformed (e.g. corrupted DB data), fail gracefully.
                // Without this catch, the SyntaxError would propagate up through RequireJS,
                // trigger "No define call for core/first", and kill Moodle site-admin navigation.
                console.error('local_rtocompliance/qualbuilder_edit: QB INIT JSON parse failed - ' +
                    'check qb-init-data DOM element for encoding issues.', e);
                INIT = {};
            }
        }

    // ===== STATE =====
    var QB = {
        id:            INIT.qualbuilderid,
        producttype:   (INIT.product && INIT.product.producttype) || 'qualification',
        code:          (INIT.product && INIT.product.qualificationcode) || '',
        name:          (INIT.product && INIT.product.qualificationname) || '',
        streamname:    (INIT.product && INIT.product.streamname) || '',
        aqflevel:      (INIT.product && INIT.product.aqflevel) || 0,
        categoryid:    (INIT.product && INIT.product.categoryid) || 0,
        semesterid:    0,   // leaf semester category used for course-dropdown filtering only (not saved to DB)
        categoryTree:  INIT.categoryTree || [],   // {id,name,parent,idnumber}[] -- full cat tree from PHP
        nominalhours:  (INIT.product && INIT.product.nominalhours) || 0,
        status:        (INIT.product && INIT.product.status) || 'draft',
        totalRequired: (INIT.product && INIT.product.totalunits) || 0,
        coreRequired:  (INIT.product && INIT.product.coreunitcount) || 0,
        electiveReq:   (INIT.product && INIT.product.electivecount) || 0,
        groupRules:    INIT.existingGroupRules || {},
        rulesText:     [],
        tgaUnits:      [],
        pointsRequired:        (INIT.product && INIT.product.electiverules && INIT.product.electiverules.pointsRequired) || 0,
        pointsSystem:          !!(INIT.product && INIT.product.electiverules && INIT.product.electiverules.pointsSystem),
        corePointsRequired:    (INIT.product && INIT.product.electiverules && INIT.product.electiverules.corePointsRequired) || 0,
        electivePointsRequired:(INIT.product && INIT.product.electiverules && INIT.product.electiverules.electivePointsRequired) || 0,
        currentUnits:  (INIT.existingUnits || []).map(function(u) {
            return {
                id: u[0] || 0,
                unitcode: u[1] || '',
                unitname: u[2] || '',
                unittype: u[3] || 'elective',
                electivegroup: u[4] || '',
                nominalhours: u[5] || 0,
                courseid: u[6] || 0,
                creditpoints: u[7] || 0,
                variants: u[8] || [],  // [8] extra course IDs that also deliver this unit
            };
        }),
        categories: [],
        courses: [],
        unitCodeMap: {},   // unitcode.toUpperCase() → [{id, category, shortname}] — PHP-built, O(1) lookup
        suggestedCategoryId: 0,
        tgaLoaded: false,
        wwwroot: INIT.wwwroot,
    };

    // ===== SEMESTER PICKER HELPER =====
    // Picks the best default semester from a list of category children.
    // Strategy: skip "Archive" folders, then sort by name DESCENDING so "S2" > "S1",
    // "Term 2" > "Term 1", "26 DIFF S2" > "26 DIFF S1", etc.
    // Falls back to all children (including archives) if everything is archived.
    // Never uses category ID as a tiebreaker — Moodle assigns IDs in creation order,
    // which has no relationship to delivery order (S1 can be created after S2).
    function pickDefaultSemester(semKids) {
        if (!semKids || !semKids.length) return null;
        var active = semKids.filter(function(c) {
            return c.name.toLowerCase().indexOf('archive') === -1;
        });
        var pool = active.length ? active : semKids;
        var sorted = pool.slice().sort(function(a, b) {
            return a.name < b.name ? 1 : a.name > b.name ? -1 : 0;
        });
        return sorted[0];
    }

    // ===== SETUP =====
    function setup() {
        // Restore form state from product data
        if (INIT.product) {
            $('#qb-type-select').val(QB.producttype);
            $('#qb-code-input').val(QB.code);
            $('#qb-name-input').val(QB.name);
            if (QB.streamname) $('#qb-stream-input').val(QB.streamname);
            if (QB.aqflevel) $('#qb-aqf-select').val(QB.aqflevel);
            if (QB.nominalhours) $('#qb-nominalhours-input').val(QB.nominalhours);
            $('#qb-status-select').val(QB.status);

            // Two-level picker: initialise root + semester selects for an existing record.
            // PHP pre-selects the correct option in #qb-qualcat-root (root or parent-of-saved).
            // We read it back here, populate the semester dropdown, and if the saved
            // categoryid was itself a child (semester), select it in the semester dropdown.
            if (QB.categoryid && QB.categoryTree.length) {
                var savedCat = QB.categoryTree.find(function(c) { return c.id === QB.categoryid; });
                if (savedCat && savedCat.parent === 0) {
                    // Saved value IS a root category
                    $('#qb-qualcat-root').val(QB.categoryid);
                    populateSemesterDropdown(QB.categoryid);
                    // Auto-select the most recent (highest-ID) semester child so that
                    // QB.semesterid is non-zero before loadFromTGA fires mapAllCourses.
                    // Without this, semid=0 causes cross-semester pool pollution.
                    var setupSemKids = QB.categoryTree.filter(function(c) { return c.parent === QB.categoryid; });
                    var setupDefault = pickDefaultSemester(setupSemKids);
                    if (setupDefault) {
                        QB.semesterid = setupDefault.id;
                        $('#qb-semester-select').val(setupDefault.id);
                    }
                } else if (savedCat && savedCat.parent > 0) {
                    // savedCat.parent > 0 can mean two things:
                    // (a) savedCat is a leaf semester  → root = savedCat.parent
                    // (b) savedCat is a nested qual root (has children but parent != 0)
                    //     e.g. "Diploma Int'l Freight Fwding" nested under "Miscellaneous"
                    // Distinguish using the same hasChildren test used in acceptCategoryAndMapAll.
                    var savedHasChildren = QB.categoryTree.some(function(c) { return c.parent === savedCat.id; });
                    if (savedHasChildren) {
                        // (b) Nested qual root — QB.categoryid already correct from INIT; keep it.
                        $('#qb-qualcat-root').val(QB.categoryid);
                        populateSemesterDropdown(QB.categoryid);
                        var setupNestKids = QB.categoryTree.filter(function(c) { return c.parent === QB.categoryid; });
                        var nestDefault = pickDefaultSemester(setupNestKids);
                        if (nestDefault) {
                            QB.semesterid = nestDefault.id;
                            $('#qb-semester-select').val(nestDefault.id);
                        }
                    } else {
                        // (a) Leaf semester — root = its parent
                        var rootId = savedCat.parent;
                        QB.categoryid  = rootId;
                        QB.semesterid  = savedCat.id;
                        $('#qb-qualcat-root').val(rootId);
                        $('#qb-category-select').val(rootId);
                        populateSemesterDropdown(rootId);
                        $('#qb-semester-select').val(savedCat.id);
                    }
                } else {
                    // Category not found in tree -- just ensure root picker reflects the value
                    $('#qb-qualcat-root').val(QB.categoryid);
                    populateSemesterDropdown(QB.categoryid);
                }
            }
        }

        bindEvents();

        // Silently pre-load courses for the current category so that Map All works
        // immediately when editing an existing record without reloading TGA first.
        // QB.courses starts as [] and is only filled inside loadFromTGA(); without this
        // prefetch, clicking Map All on a re-opened record does nothing because
        // findCourseForUnit() returns undefined for every unit against an empty list.
        if (QB.categoryid > 0 && !QB.courses.length) {
            refreshCourses(QB.categoryid, null);
        }

        if (QB.currentUnits.length > 0 || QB.totalRequired > 0) {
            showComplianceCard();
            renderComplianceDashboard();
            renderUnitBuilder();
        }
    }

    function bindEvents() {
        $('#qb-load-tga-btn, #qb-reload-tga-btn').on('click', loadFromTGA);
        $('#qb-code-input').on('keydown', function(e) { if (e.which === 13) { e.preventDefault(); loadFromTGA(); } });
        // -- Two-level category picker --------------------------------------------
        // Step 1: qualification root changed
        $('#qb-qualcat-root').on('change', function() {
            var rootId = parseInt($(this).val()) || 0;
            QB.categoryid = rootId;
            QB.semesterid = 0;
            // Keep hidden legacy select in sync (saveQualification reads it)
            $('#qb-category-select').val(rootId);
            $('#qb-category-suggestion').hide();
            populateSemesterDropdown(rootId);
            // If this root has no children, treat it as also the semester (flat structure)
            var hasChildren = QB.categoryTree.some(function(c) { return c.parent === rootId; });
            if (!hasChildren && rootId > 0) {
                QB.semesterid = rootId;
                if (QB.currentUnits.length > 0) {
                    var mapped = mapAllCourses();
                    renderUnitBuilder();
                    renderComplianceDashboard();
                    showToast('Category set. Auto-linked ' + mapped + ' / ' + QB.currentUnits.length + ' units to Moodle courses.');
                }
            }
        });

        // Step 2: semester / intake changed
        // Always refreshCourses first so QB.unitCodeMap is populated for the new semid scope.
        $('#qb-semester-select').on('change', function() {
            QB.semesterid = parseInt($(this).val()) || 0;
            if (QB.currentUnits.length > 0 && QB.categoryid > 0) {
                showLoading('Loading courses for semester...');
                refreshCourses(QB.categoryid, function() {
                    hideLoading();
                    var mapped = mapAllCourses();
                    renderUnitBuilder();
                    renderComplianceDashboard();
                    showToast('Semester set. Auto-linked ' + mapped + ' / ' + QB.currentUnits.length + ' units to Moodle courses.');
                });
            }
        });

        // Hidden legacy select: kept for suggestion pills and any path that sets it directly
        $('#qb-category-select').on('change', function() {
            QB.categoryid = parseInt($(this).val()) || 0;
            $('#qb-category-suggestion').hide();
            if (QB.currentUnits.length > 0 && QB.categoryid > 0) {
                showLoading('Loading courses...');
                refreshCourses(QB.categoryid, function() {
                    hideLoading();
                    var mapped = mapAllCourses();
                    renderUnitBuilder();
                    renderComplianceDashboard();
                    showToast('Category changed. Auto-linked ' + mapped + ' / ' + QB.currentUnits.length + ' units to Moodle courses.');
                });
            }
        });
        $('#qb-save-btn').on('click', saveQualification);
        $('#qb-map-all-btn').on('click', function() {
            if (!QB.currentUnits.length) { showToast('No units loaded yet. Load from TGA first.'); return; }
            // Guard: if no semester is selected yet, auto-select the most recent (highest-ID)
            // semester child — same logic used in loadFromTGA().  Without this guard, mapAllCourses
            // runs with semid=0, which pools ALL semesters and links units to whichever semester's
            // courses happen to score highest — typically the wrong one.
            if (QB.categoryid > 0 && !QB.semesterid) {
                var mapBtnSemKids = QB.categoryTree.filter(function(c) { return c.parent === QB.categoryid; });
                var mapBtnDefault = pickDefaultSemester(mapBtnSemKids);
                if (mapBtnDefault) {
                    QB.semesterid = mapBtnDefault.id;
                    $('#qb-semester-select').val(mapBtnDefault.id);
                }
            }
            // Always refresh courses before mapping so QB.unitCodeMap is current.
            if (QB.categoryid > 0) {
                showLoading('Refreshing course list...');
                refreshCourses(QB.categoryid, function() {
                    hideLoading();
                    var mapped = mapAllCourses();
                    renderUnitBuilder();
                    renderComplianceDashboard();
                    showToast('Auto-linked ' + mapped + ' / ' + QB.currentUnits.length + ' units to Moodle courses.');
                });
                return;
            }
            var mapped = mapAllCourses();
            renderUnitBuilder();
            renderComplianceDashboard();
            showToast('Auto-linked ' + mapped + ' / ' + QB.currentUnits.length + ' units to Moodle courses.');
        });
        $(document).on('change', '.qb-unit-cb', onUnitToggle);
        $(document).on('change', '.qb-course-sel', onCourseChange);
        $(document).on('change', '.qb-group-sel', onGroupChange);
        // Compact badge click: reveal the hidden dropdown for that unit.
        $(document).on('click', '.qb-linked-badge', function() {
            var $badge  = $(this);
            var $parent = $badge.closest('.qb-unit-course');
            $badge.hide();
            $parent.find('.qb-course-sel').show().focus();
        });

        // QPR PASTE BOX: parse pasted text and apply to QB state.
        $(document).on('click', '#qb-qpr-parse-btn', function() {
            var text   = $('#qb-qpr-paste-text').val() || '';
            var $res   = $('#qb-qpr-parse-result');
            if (!text.trim()) {
                $res.html('<span style="color:#b45309">Please paste the packaging rules text first.</span>');
                return;
            }
            var parsed = parseQprText(text);
            if (!parsed.total && !parsed.core && !parsed.elective) {
                $res.html('<span style="color:#dc2626">\u2717 Could not find unit counts in that text. ' +
                    'Make sure you paste the full packaging rules section (look for text like ' +
                    '\"A total of X units of competency comprising: Y core units, plus Z elective units\").</span>');
                return;
            }
            // Apply to QB state
            if (parsed.total)    { QB.totalRequired = parsed.total; }
            if (parsed.core)     { QB.coreRequired  = parsed.core; }
            if (parsed.elective) { QB.electiveReq   = parsed.elective; }
            $res.html('<span style="color:#166534">\u2713 Found: ' +
                parsed.total + ' total (' + parsed.core + ' core + ' + parsed.elective + ' elective). ' +
                'Click <strong>Save Qualification</strong> to store these values.</span>');
            // Refresh the dashboard and rules card with new values (paste box disappears).
            renderComplianceDashboard();
            renderRulesCard();
        });

        // QB-VARIANTS: show the hidden select when the + button is clicked.
        $(document).on('click', '.qb-variant-add-btn', function(e) {
            e.stopPropagation();
            var $btn  = $(this);
            var $wrap = $btn.closest('.qb-variant-add-wrap');
            $btn.hide();
            $wrap.find('.qb-variant-add').show().focus();
        });

        // QB-VARIANTS: hide the select and restore the + button if user clicks away without picking.
        $(document).on('focusout', '.qb-variant-add', function() {
            var $sel = $(this);
            setTimeout(function() {
                if (!$sel.is(':focus')) {
                    var $wrap = $sel.closest('.qb-variant-add-wrap');
                    if ($wrap.length) {
                        $sel.hide().val('0');
                        $wrap.find('.qb-variant-add-btn').show();
                    }
                }
            }, 180);
        });

        // QB-VARIANTS: dismiss the info banner for the rest of the session.
        $(document).on('click', '.qb-variants-info-dismiss', function() {
            $(this).closest('.qb-variants-info').slideUp(150);
            try { sessionStorage.setItem('qb_variants_info_dismissed', '1'); } catch(e) {}
        });

        // QB-VARIANTS: remove a variant chip when the × is clicked.
        $(document).on('click', '.qb-variant-remove', function(e) {
            e.stopPropagation();
            var code     = String($(this).data('unitcode'));
            var courseid = parseInt($(this).data('courseid')) || 0;
            var unit = QB.currentUnits.find(function(u) { return u.unitcode === code; });
            if (unit && courseid) {
                unit.variants = (unit.variants || []).filter(function(id) { return id !== courseid; });
                renderUnitBuilder();
            }
        });

        // QB-VARIANTS: add a variant when a course is chosen from the + dropdown.
        $(document).on('change', '.qb-variant-add', function() {
            var $sel     = $(this);
            var code     = String($sel.data('unitcode'));
            var courseid = parseInt($sel.val()) || 0;
            if (!courseid) return;
            var unit = QB.currentUnits.find(function(u) { return u.unitcode === code; });
            if (unit) {
                if (!unit.variants) unit.variants = [];
                if (unit.variants.indexOf(courseid) === -1) { unit.variants.push(courseid); }
                renderUnitBuilder();
            }
        });

        // FIX-QB-BADGE-RESTORE (v5.9.273): if the user opened the dropdown via badge
        // click but then clicked away without changing anything, show the badge again.
        // Previously the badge stayed hidden until the next renderUnitBuilder() call,
        // making the row look permanently "in edit mode".
        $(document).on('focusout', '.qb-course-sel', function() {
            var $sel    = $(this);
            var $parent = $sel.closest('.qb-unit-course');
            // Only restore in compact (linked) mode — unlinked rows never show a badge.
            if (!$parent.hasClass('linked')) { return; }
            var $badge = $parent.find('.qb-linked-badge');
            // Badge was the visible state before; put it back if it's hidden.
            if ($badge.is(':hidden')) {
                $sel.hide();
                $badge.show();
            }
        });

        // FIX-QB-BADGE-RESTORE: delegated handler for category suggestion pills.
        // Previously suggestCategory() called .on('click') inline after each .html()
        // update, so every TGA reload stacked another handler on the same elements.
        // A single delegated bind here fires exactly once per click regardless of
        // how many times the suggestion HTML has been replaced.
        $(document).on('click', '.qb-suggestion-pill', function() {
            var catId = parseInt($(this).data('catid'));
            if (catId) { acceptCategoryAndMapAll(catId); }
        });
        $(document).on('click', '#qb-add-imported-btn', showAddImportedForm);
        $(document).on('click', '#qb-imported-save-btn', saveImportedUnit);
        $(document).on('click', '#qb-imported-cancel-btn', cancelImportedUnit);
        $(document).on('click', '.qb-del-unit-btn', deleteUnit);

        // -- Unit search: debounce 180 ms so rapid typing doesn't flicker --
        $('#qb-unit-search').on('input', function() {
            clearTimeout(_unitFilterTimer);
            _unitFilterTimer = setTimeout(applyUnitFilter, 180);
        });

        // Clear search on Escape
        $('#qb-unit-search').on('keydown', function(e) {
            if (e.which === 27) {
                $(this).val('');
                applyUnitFilter();
            }
        });

        // Type filter buttons -- toggle active, then reapply
        $(document).on('click', '.qb-type-btn', function() {
            $('#qb-unit-type-btns .qb-type-btn').removeClass('active');
            $(this).addClass('active');
            applyUnitFilter();
        });
    }

    // ===== COURSE REFRESH =====
    // Fetches the Moodle course list + pre-built unit-code map for a given qualification
    // categoryid without triggering a full TGA reload.
    function refreshCourses(categoryid, callback) {
        // Pass current unit codes so the PHP supplement scan stays scoped to this
        // qual's units only (same filter tga_get_builder_data uses).  Prefer the
        // TGA unit list (authoritative, full qual) over the saved builder list
        // (may be incomplete mid-edit).  Fall back to empty string → unfiltered.
        var unitcodeList = '';
        if (QB.tgaUnits && QB.tgaUnits.length) {
            unitcodeList = QB.tgaUnits.map(function(u) { return u.unitcode.toUpperCase(); }).join(',');
        } else if (QB.currentUnits && QB.currentUnits.length) {
            unitcodeList = QB.currentUnits.map(function(u) { return u.unitcode.toUpperCase(); }).join(',');
        }
        ajax.call([{
            methodname: 'local_rtocompliance_get_courses_for_category',
            args: { categoryid: categoryid || 0, unitcodes: unitcodeList }
        }])[0].done(function(data) {
            QB.courses     = data.courses     || [];
            QB.unitCodeMap = buildUnitCodeMap(data.unitcodemap || []);
            if (typeof callback === 'function') { callback(); }
        }).fail(function() {
            if (typeof callback === 'function') { callback(); }
        });
    }

    // ===== UNIT CODE MAP =====
    // Converts the flat [{unitcode, courseid, category, shortname}] array returned by
    // PHP into a keyed map: unitcode.toUpperCase() → [{id, category, shortname}].
    // Multiple entries per code are normal — same unit may exist in S1 and S2.
    function buildUnitCodeMap(entries) {
        var map = {};
        (entries || []).forEach(function(e) {
            var uc = (e.unitcode || '').toUpperCase();
            if (!uc || uc.length < 6) return;
            if (!map[uc]) map[uc] = [];
            map[uc].push({ id: e.courseid, category: e.category, shortname: e.shortname || '' });
        });
        console.log('[QB] unitCodeMap: ' + Object.keys(map).length + ' unit codes from ' +
            (entries || []).length + ' course-code entries');
        return map;
    }

    // ===== TGA LOADING =====
    function loadFromTGA() {
        var code = $('#qb-code-input').val().trim().toUpperCase();
        if (!code) { showError('Please enter a qualification/skill set/unit code.'); return; }
        QB.code = code;
        $('#qb-code-input').val(code);
        showLoading('Loading ' + code + ' from training.gov.au...');

        ajax.call([{
            methodname: 'local_rtocompliance_tga_get_builder_data',
            args: { code: code, categoryid: QB.categoryid || 0 }
        }])[0].done(function(data) {
            hideLoading();
            if (!data.success) {
                showTgaError(data.error || code + ' not found on training.gov.au. Check the code and try again.');
                return;
            }

            var qual     = JSON.parse(data.qualification || '{}');
            QB.rulesText     = JSON.parse(data.packagingrules || '[]');
            QB.totalRequired = data.totalunits || 0;
            QB.coreRequired  = data.corerequired || 0;
            QB.electiveReq   = data.electiverequired || 0;
            QB.groupRules    = JSON.parse(data.grouprules || '{}');
            QB.pointsRequired         = data.pointsrequired || 0;
            QB.pointsSystem           = !!(data.pointssystem);
            QB.corePointsRequired    = data.corepointsrequired || 0;
            QB.electivePointsRequired = data.electivepointsrequired || 0;
            // tgaUnits: normalise array-of-objects from web service
            QB.tgaUnits = (data.units || []).map(function(u) {
                return {
                    unitcode:      u.unitcode || u[0] || '',
                    unitname:      u.unitname || u[1] || '',
                    iscore:        !!(u.iscore || u[2]),
                    electivegroup: u.electivegroup || u[3] || '',
                    grouplabel:    u.grouplabel || u[4] || '',
                    nominalhours:  u.nominalhours || u[5] || 0,
                    creditpoints:  u.creditpoints || 0,
                };
            });
            QB.categories    = data.moodlecategories || [];
            QB.courses       = data.moodlecourses || [];
            QB.unitCodeMap   = buildUnitCodeMap(data.unitcodemap || []);
            QB.tgaLoaded     = true;

            // Auto-fill product form fields
            if (qual.title) { QB.name = qual.title; $('#qb-name-input').val(qual.title); }
            if (qual.aqfLevel) { QB.aqflevel = qual.aqfLevel; $('#qb-aqf-select').val(qual.aqfLevel); }
            if (qual.type) {
                var t = qual.type.toLowerCase();
                if (t.indexOf('qualification') !== -1) $('#qb-type-select').val('qualification');
                else if (t.indexOf('skill set') !== -1) $('#qb-type-select').val('skillset');
                else if (t.indexOf('unit') !== -1) $('#qb-type-select').val('singleunit');
            }

            // Show TGA banner
            var bannerHtml = '<strong>' + escH(qual.code || code) + '</strong>  -  ' + escH(qual.title || '');
            if (qual.aqfLevel) bannerHtml += ' &nbsp;|&nbsp; AQF Level ' + qual.aqfLevel;
            if (QB.totalRequired) bannerHtml += ' &nbsp;|&nbsp; ' + QB.totalRequired + ' units (' + QB.coreRequired + ' core + ' + QB.electiveReq + ' elective)';
            if (QB.pointsSystem) bannerHtml += ' &nbsp;|&nbsp; <span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 7px;font-weight:700">POINTS SYSTEM  -  ' + QB.pointsRequired + ' pts required</span>';
            $('#qb-tga-banner-text').html(bannerHtml);
            $('#qb-tga-banner').show();
            $('#qb-tga-error').hide();

            // Show packaging rules
            renderPackagingRules();

            // Step 1: Populate units (fresh build or merge existing)
            if (QB.currentUnits.length === 0) {
                autoAddCoreUnits();
            } else {
                // Merge TGA data: update names/hours for existing units if they were blank
                QB.tgaUnits.forEach(function(tu) {
                    var existing = QB.currentUnits.find(function(u) { return u.unitcode === tu.unitcode; });
                    if (existing) {
                        if (!existing.nominalhours && tu.nominalhours) existing.nominalhours = tu.nominalhours;
                        if (!existing.unitname && tu.unitname) existing.unitname = tu.unitname;
                    }
                });
                // STALE-UNIT-PURGE: remove non-imported units that do not appear in the new
                // TGA unit list. This handles the case where the admin changes the qual code
                // on an existing record — the old units from the previous qual must not stay
                // in QB.currentUnits because mapAllCourses() will re-link them to courses and
                // getCompliance() will count them, making the compliance counter show the wrong
                // numbers (e.g. "10/10 linked" while every displayed TGA unit shows Not linked).
                QB.currentUnits = QB.currentUnits.filter(function(u) {
                    if (u.unittype === 'imported') return true; // always keep imported units
                    return QB.tgaUnits.some(function(tu) { return tu.unitcode === u.unitcode; });
                });
                // If the purge removed all non-imported units (qual code completely changed),
                // auto-add the new qual's core units so the admin doesn't see an empty builder.
                if (!QB.currentUnits.some(function(u) { return u.unittype !== 'imported'; })) {
                    autoAddCoreUnits();
                }
            }

            // Step 2: Bulk fill nominal hours from TGA data and auto-sum total
            bulkFillNominalHours();

            // Step 3: If category already set (editing existing), auto-link all courses now.
            // Always ensure a semester is selected first — mapAllCourses with semid=0 searches
            // across ALL semesters simultaneously, producing cross-semester pollution where
            // e.g. S1 courses get linked to a S2 record silently.
            if (QB.categoryid > 0 && QB.currentUnits.length > 0) {
                if (!QB.semesterid) {
                    var tgaSemKids = QB.categoryTree.filter(function(c) { return c.parent === QB.categoryid; });
                    var tgaDefault = pickDefaultSemester(tgaSemKids);
                    if (tgaDefault) {
                        QB.semesterid = tgaDefault.id;
                        $('#qb-semester-select').val(tgaDefault.id);
                    }
                }
                var preLinked = mapAllCourses();
                if (preLinked > 0) {
                    showToast('Auto-linked ' + preLinked + ' / ' + QB.currentUnits.length + ' units to Moodle courses.');
                }
            }

            // Step 4: Suggest / auto-accept category (only if not already set)
            if (!QB.categoryid) {
                suggestCategory(qual.title || '', qual.code || QB.code || '');
            }

            showComplianceCard();
            renderComplianceDashboard();
            renderUnitBuilder();

        }).fail(function(err) {
            hideLoading();
            showTgaError('TGA fetch failed: ' + (err.message || 'Unknown error. Ensure the API URL is configured in plugin settings.'));
        });
    }

    function autoAddCoreUnits() {
        // SEMID-PRELINK-FIX (v5.9.255): do NOT call findCourseForUnit() here.
        // autoAddCoreUnits() is called at step 1 of loadFromTGA(), BEFORE QB.semesterid
        // is set (that happens at step 3, lines ~396-402). Calling findCourseForUnit()
        // with semid=0 drops into rootPool — all courses across ALL semesters — and links
        // units to a cross-semester mix before the admin's semester selection is known.
        // mapAllCourses() fires immediately after semesterid is set; let IT do all linking.
        QB.tgaUnits.forEach(function(tu) {
            if (!tu.iscore) return;
            var code = tu.unitcode;
            if (QB.currentUnits.find(function(u) { return u.unitcode === code; })) return;
            QB.currentUnits.push({
                id: 0,
                unitcode: code,
                unitname: tu.unitname,
                unittype: 'core',
                electivegroup: '',
                nominalhours: tu.nominalhours || 0,
                courseid: 0,   // always start unlinked; mapAllCourses() links correctly
                creditpoints: tu.creditpoints || 0,
            });
        });
    }

    // ===== MOODLE COURSE/CATEGORY SUGGESTIONS =====
    // qualCode: the TGA qualification code (e.g. "TLI50822")  --  used for exact match
    function suggestCategory(qualTitle, qualCode) {
        if (!QB.categories.length) return;

        if (qualCode) {
            var codeUC = qualCode.trim().toUpperCase();

            // Tier 0a: idnumber exact match (highest confidence -- set by admin or auto-build).
            // Italc stores TLI50119/TLI50822/TLI50816 as category.idnumber on their qual roots.
            // This is an unambiguous match -- auto-accept immediately.
            var idnumMatch = QB.categories.find(function(cat) {
                return cat.idnumber && cat.idnumber.trim().toUpperCase() === codeUC;
            });
            if (idnumMatch) {
                acceptCategoryAndMapAll(idnumMatch.id);
                return;
            }

            // Tier 0b: category name contains the qual code (e.g. "BSB50420 Diploma of...")
            // Auto-accept immediately, no user click needed.
            var exactMatch = QB.categories.find(function(cat) {
                return cat.name.toUpperCase().indexOf(codeUC) !== -1;
            });
            if (exactMatch) {
                acceptCategoryAndMapAll(exactMatch.id);
                return;
            }
        }

        // Tier 1: fuzzy keyword match against qual title  --  show suggestion pills
        if (!qualTitle) return;
        var stopwords = ['certificate', 'diploma', 'advanced', 'graduate', 'course', 'with', 'and', 'the', 'for', 'in', 'of', 'to', 'a', 'iii', 'iv', 'vi', 'vii', 'viii'];
        var words = qualTitle.toLowerCase().split(/[\s,\/\-]+/).filter(function(w) {
            return w.length > 3 && stopwords.indexOf(w) === -1;
        });
        if (!words.length) return;

        var scored = QB.categories.map(function(cat) {
            var n = cat.name.toLowerCase();
            var score = 0;
            words.forEach(function(w) {
                if (n.indexOf(w) !== -1) {
                    score += w.length;
                    if (new RegExp('\\b' + w + '\\b').test(n)) score += 2;
                }
            });
            return { cat: cat, score: score };
        }).filter(function(s) { return s.score > 0; });

        scored.sort(function(a, b) { return b.score - a.score; });
        var top = scored.slice(0, 3);
        if (!top.length) return;

        QB.suggestedCategoryId = top[0].cat.id;
        var html = '';
        top.forEach(function(s, i) {
            var cls = i === 0 ? 'qb-suggestion-pill primary' : 'qb-suggestion-pill';
            var prefix = i === 0 ? '\u2713 Best match: ' : '\uD83D\uDCCC Also: ';
            html += '<span class="' + cls + '" data-catid="' + s.cat.id + '">' +
                prefix + '<strong>' + escH(s.cat.name) + '</strong>' +
                '<small style="opacity:.7;margin-left:4px"> - click to accept &amp; auto-link courses</small></span>';
        });
        // FIX-QB-BADGE-RESTORE (v5.9.273): removed inline .on('click') binding here.
        // The delegated handler in bindEvents() now handles all .qb-suggestion-pill clicks,
        // preventing handler accumulation on repeated TGA reloads.
        $('#qb-category-suggestion').html(html).show();
    }

    function acceptCategoryAndMapAll(catId) {
        var treeEntry = QB.categoryTree.find(function(c) { return c.id === catId; });

        // Use child-presence to distinguish a qualification root from a semester leaf.
        // Checking parent===0 alone is unreliable: qual roots that are nested under a
        // site-level grouping (e.g. "Miscellaneous") have parent>0 even though they ARE
        // qual roots, causing them to be misidentified as semesters.  A category with
        // children is definitionally a root; a category with no children is a leaf semester.
        var hasChildren = QB.categoryTree.some(function(c) { return c.parent === catId; });

        if (hasChildren) {
            // catId is a qualification root (has semester children).
            // Set root, then auto-select the most recent (highest-ID) semester child.
            QB.categoryid = catId;
            $('#qb-qualcat-root').val(catId);
            $('#qb-category-select').val(catId);
            populateSemesterDropdown(catId);
            var accSemKids = QB.categoryTree.filter(function(c) { return c.parent === catId; });
            var accDefault = pickDefaultSemester(accSemKids);
            if (accDefault) {
                QB.semesterid = accDefault.id;
                $('#qb-semester-select').val(accDefault.id);
            } else {
                QB.semesterid = 0;
            }
        } else if (treeEntry && treeEntry.parent > 0) {
            // catId is a leaf semester -- set root = parent, semester = this
            QB.categoryid = treeEntry.parent;
            QB.semesterid = catId;
            $('#qb-qualcat-root').val(treeEntry.parent);
            $('#qb-category-select').val(treeEntry.parent);
            populateSemesterDropdown(treeEntry.parent);
            $('#qb-semester-select').val(catId);
        } else {
            // Flat root (parent=0, no children) -- treat as both root and semester
            QB.categoryid = catId;
            QB.semesterid = catId;
            $('#qb-qualcat-root').val(catId);
            $('#qb-category-select').val(catId);
            populateSemesterDropdown(catId);
        }

        $('#qb-category-suggestion').hide();
        // Always refresh courses so QB.unitCodeMap reflects the newly selected category.
        showLoading('Loading courses...');
        refreshCourses(QB.categoryid, function() {
            hideLoading();
            var mapped = mapAllCourses();
            renderUnitBuilder();
            renderComplianceDashboard();
            var total = QB.currentUnits.length;
            if (mapped === total && total > 0) {
                showToast('\u2713 All ' + total + ' units auto-linked to Moodle courses.');
            } else if (mapped > 0) {
                showToast('Auto-linked ' + mapped + ' / ' + total + ' units. ' + (total - mapped) + ' need manual linking.');
            } else {
                showToast('Category set. Pick a semester above, then use "Map All Courses" to link units.');
            }
        });
    }

    // Populate (or clear) the semester dropdown based on the selected root category.
    // Reads QB.categoryTree so no server round-trip is needed.
    function populateSemesterDropdown(rootId) {
        var $sem = $('#qb-semester-select');
        $sem.empty().append('<option value="0">\u2014 select semester \u2014</option>');
        if (!rootId) {
            $sem.prop('disabled', true);
            return;
        }
        var children = QB.categoryTree.filter(function(c) { return c.parent === rootId; });
        if (!children.length) {
            $sem.prop('disabled', true);
            return;
        }
        children.forEach(function(c) {
            $sem.append('<option value="' + c.id + '">' + escH(c.name) + '</option>');
        });
        $sem.prop('disabled', false);
    }

    function mapAllCourses() {
        var mapped = 0;
        QB.currentUnits.forEach(function(unit) {
            var course = findCourseForUnit(unit.unitcode);
            if (course && course.id !== unit.courseid) {
                unit.courseid = course.id;
                // Update the DOM dropdown too
                var $sel = $('.qb-course-sel[data-unitcode="' + unit.unitcode + '"]');
                if ($sel.length) {
                    $sel.val(course.id);
                    $sel.closest('.qb-unit-course').addClass('linked').removeClass('unlinked');
                }
            } else if (!course && QB.semesterid && unit.courseid > 0) {
                // STALE-LINK-CLEAR (v5.9.256): when a semester IS selected and this unit
                // has no course in that semester, clear the stale link from the previous
                // semester. Without this, switching semesters leaves old courseids in place
                // and mapAllCourses() never reduces the linked count below its initial value.
                unit.courseid = 0;
                unit.variants = [];
                var $clr = $('.qb-course-sel[data-unitcode="' + unit.unitcode + '"]');
                if ($clr.length) {
                    $clr.val(0);
                    $clr.closest('.qb-unit-course').removeClass('linked').addClass('unlinked');
                }
            }
            if (unit.courseid > 0) mapped++;

            // QB-VARIANTS: auto-populate all other courses in the semester that share
            // this unit code. They are stored in unit.variants so the reconciler watches
            // all of them, not just the primary linked course.
            if (QB.semesterid) {
                var uc = unit.unitcode.toUpperCase();
                var semid = QB.semesterid;
                var mapEntries = QB.unitCodeMap[uc] || [];
                unit.variants = mapEntries
                    .filter(function(e) { return e.category === semid && e.id !== unit.courseid; })
                    .map(function(e) { return e.id; });
            }
        });

        return mapped;
    }

    function findCourseForUnit(unitcode) {
        var semid = QB.semesterid || 0;
        var catid = QB.categoryid || QB.suggestedCategoryId || 0;
        var uc    = unitcode.toUpperCase();

        // ── PRIMARY: SQL-derived unit-code map (PHP regex on idnumber+shortname+fullname) ──
        // Direct O(1) dictionary lookup — no string guessing, no fuzzy false-positives.
        // PHP extracts unit codes from every course in the category subtree before sending
        // data to the browser, so this map is authoritative and complete.
        var mapEntries = QB.unitCodeMap[uc];
        if (mapEntries && mapEntries.length) {
            var chosen = null;
            if (semid) {
                // Exact semester match only — no fallbacks of any kind.
                // If the course isn't in the selected semester folder, show "-- Not linked --"
                // and let the admin link it manually.  All previous cross-package and
                // cross-semester fallbacks caused incorrect auto-links and have been removed
                // (v5.9.271).  A unit that genuinely lives in the selected semester will be
                // found here; anything else is left unlinked.
                for (var mi = 0; mi < mapEntries.length; mi++) {
                    if (mapEntries[mi].category === semid) { chosen = mapEntries[mi]; break; }
                }
                if (!chosen) { return null; }
            } else {
                // No semester selected — nothing to match against.
                return null;
            }
            // Resolve to the full course object from QB.courses (needed for id, shortname, etc.)
            var resolved = QB.courses.find(function(c) { return c.id === chosen.id; });
            if (resolved) {
                console.log('[QB] ' + uc + ' → ' + resolved.shortname + ' (map, cat=' + resolved.category + ')');
                return resolved;
            }
        }

        // No map entry for this unit at all — no course has this unit code in name/idnumber.
        console.log('[QB] ' + uc + ': no match found (not in unitCodeMap)');
        return null;
    }

    // Bulk-fill nominal hours for all current units from TGA data, then auto-sum total hours
    function bulkFillNominalHours() {
        QB.currentUnits.forEach(function(unit) {
            if (!unit.nominalhours) {
                var tu = QB.tgaUnits.find(function(t) { return t.unitcode === unit.unitcode; });
                if (tu && tu.nominalhours) unit.nominalhours = tu.nominalhours;
            }
        });
        // Sum all selected units and update the total nominal hours field
        var total = QB.currentUnits.reduce(function(s, u) { return s + (u.nominalhours || 0); }, 0);
        if (total > 0) {
            QB.nominalhours = total;
            $('#qb-nominalhours-input').val(total);
        }
    }

    function refreshCourseDropdowns() {
        // After compact-mode change: re-render the whole builder
        renderUnitBuilder();
        renderComplianceDashboard();
    }

    // ===== PACKAGING RULES =====
    function renderPackagingRules() {
        if (!QB.totalRequired && !QB.rulesText.length && !QB.pointsRequired) { $('#qb-rules-card').hide(); return; }
        var summary = '';
        if (QB.totalRequired) summary += '<strong>Total units: ' + QB.totalRequired + '</strong>';
        if (QB.coreRequired)  summary += (summary ? ' &nbsp;|&nbsp; ' : '') + 'Core: <strong>' + QB.coreRequired + '</strong>';
        if (QB.electiveReq)   summary += ' &nbsp;|&nbsp; Elective: <strong>' + QB.electiveReq + '</strong>';
        if (QB.pointsSystem && QB.pointsRequired) {
            summary += ' &nbsp;|&nbsp; <span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 8px;font-weight:700">\u2605 Credit Points Required: ' + QB.pointsRequired + '</span>';
        }
        // Show group rules inline
        var grpKeys = Object.keys(QB.groupRules).sort();
        if (grpKeys.length) {
            summary += '<br><small class="text-muted">Groups: ';
            summary += grpKeys.map(function(g) {
                var r = QB.groupRules[g];
                var rmin = r.min || 0; var rmax = r.max || 999;
                var s;
                if (rmin > 0 && rmin === rmax && rmax < 999) {
                    s = 'Group ' + g + ': ' + (rmin === 1 ? '1 unit only' : rmin + ' units only');
                } else {
                    s = 'Group ' + g + ': Min ' + rmin;
                    if (rmax < 999) s += ', max ' + rmax;
                }
                return s;
            }).join(' &nbsp;|&nbsp; ');
            summary += '</small>';
        }
        var html = summary ? '<div style=\"margin-bottom:8px\">' + summary + '</div>' : '';
        if (QB.rulesText.length) {
            html += '<ul>';
            QB.rulesText.forEach(function(line) { if (line.trim()) html += '<li>' + escH(line) + '</li>'; });
            html += '</ul>';
        }
        $('#qb-rules-content').html(html);
        $('#qb-rules-card').show();
    }

    // ===== COMPLIANCE DASHBOARD =====
    function showComplianceCard() { $('#qb-compliance-card').show(); }

    function getCompliance() {
        var coreUnits = QB.currentUnits.filter(function(u) { return u.unittype === 'core'; });
        var elecUnits = QB.currentUnits.filter(function(u) { return u.unittype !== 'core'; });
        var total = QB.currentUnits.length;
        var linked = QB.currentUnits.filter(function(u) { return u.courseid > 0; }).length;
        var hours  = QB.currentUnits.reduce(function(s,u) { return s + (u.nominalhours||0); }, 0);
        var groups = {};
        elecUnits.forEach(function(u) {
            if (u.electivegroup) groups[u.electivegroup] = (groups[u.electivegroup]||0) + 1;
        });

        // Credit points: resolve from TGA unit data first (authoritative), fall back to stored value.
        // Break down into core vs elective so the dashboard can show both totals.
        var totalPoints = 0, corePoints = 0, electivePoints = 0;
        if (QB.pointsSystem) {
            QB.currentUnits.forEach(function(u) {
                var tu = QB.tgaUnits.find(function(t) { return t.unitcode === u.unitcode; });
                var pts = (tu && tu.creditpoints) ? tu.creditpoints : (u.creditpoints || 0);
                totalPoints += pts;
                if (u.unittype === 'core') {
                    corePoints += pts;
                } else {
                    electivePoints += pts;
                }
            });
        }

        // Per-type Moodle link counts (used by compound dashboard cards).
        var coreLinked = coreUnits.filter(function(u) { return u.courseid > 0; }).length;
        var elecLinked = elecUnits.filter(function(u) { return u.courseid > 0; }).length;

        return {
            coreUnits: coreUnits, elecUnits: elecUnits,
            total: total, linked: linked, hours: hours, groups: groups,
            coreLinked: coreLinked, elecLinked: elecLinked,
            totalPoints: totalPoints, corePoints: corePoints, electivePoints: electivePoints,
        };
    }

    /**
     * Parse packaging-rule text pasted by the admin and return { total, core, elective }.
     * Handles both bullet-point and prose formats from training.gov.au.
     */
    function parseQprText(text) {
        var result = { total: 0, core: 0, elective: 0 };
        // "A total of 18 units of competency" / "18 units of competency"
        var tm = text.match(/(?:total\s+of\s+)?(\d+)\s+units?\s+of\s+competency/i)
               || text.match(/total\s+of\s+(\d+)\s+units?/i)
               || text.match(/comprising\s+a\s+total\s+of\s+(\d+)/i);
        if (tm) { result.total = parseInt(tm[1], 10); }
        // "15 core units" / "10 core units listed below"
        var cm = text.match(/(\d+)\s+core\s+units?/i);
        if (cm) { result.core = parseInt(cm[1], 10); }
        // "3 general elective units" / "2 elective units"
        var em = text.match(/(\d+)\s+(?:general\s+)?elective\s+units?/i);
        if (em) { result.elective = parseInt(em[1], 10); }
        // Derive missing values from the two we have
        if (result.total && result.core && !result.elective) {
            result.elective = result.total - result.core;
        }
        if (result.total && result.elective && !result.core) {
            result.core = result.total - result.elective;
        }
        if (!result.total && result.core && result.elective) {
            result.total = result.core + result.elective;
        }
        return result;
    }

    function renderComplianceDashboard() {
        var c = getCompliance();
        var html = '';

        // QPR PASTE BOX — shown when TGA couldn't return structured packaging rule counts.
        // This can happen when a qualification's packagingInformation is null in TGA's REST API.
        // The admin pastes the text from training.gov.au and we parse it client-side.
        // Show on: (a) after a TGA load that returned no counts, (b) page load with a saved
        // record that has totalunits=0 but already has units in the builder.
        var needsPasteBox = QB.totalRequired === 0 && !QB.pointsSystem &&
            (QB.tgaLoaded || (QB.currentUnits.length > 0 && QB.coreRequired === 0));
        if (needsPasteBox) {
            var tgaLink = QB.code
                ? 'https://training.gov.au/Training/Details/' + encodeURIComponent(QB.code)
                : 'https://training.gov.au';
            html += '<div class="qb-qpr-paste" id="qb-qpr-paste-box">' +
                '<span class="qb-qpr-paste-icon">\u26A0\uFE0F</span>' +
                '<div class="qb-qpr-paste-body">' +
                '<strong>Packaging rules not found automatically</strong> \u2014 ' +
                'TGA did not return structured packaging data for <strong>' + escH(QB.code || 'this qualification') + '</strong>. ' +
                'Visit <a href="' + tgaLink + '" target="_blank" rel="noopener">training.gov.au</a>, ' +
                'open the qualification, scroll to the <em>Packaging Rules</em> section, ' +
                'select all the text there and paste it below.' +
                '<textarea id="qb-qpr-paste-text" class="qb-qpr-paste-textarea form-control" ' +
                    'placeholder="Paste the full packaging rules text from training.gov.au here\u2026" rows="4"></textarea>' +
                '<button type="button" id="qb-qpr-parse-btn" class="btn btn-sm btn-warning qb-qpr-parse-btn">Parse Rules</button>' +
                '<span id="qb-qpr-parse-result" class="qb-qpr-parse-result"></span>' +
                '</div></div>';
        }

        // Core card: primary metric is Moodle linking — the whole page is about linking
        // units to Moodle courses, not counting ticked checkboxes.
        // Value: "X / Y linked" where X = linked to Moodle, Y = required by packaging rules.
        if (QB.coreRequired > 0) {
            var coreAllLinked = c.coreLinked >= QB.coreRequired;
            var cSt = coreAllLinked ? 'pass' : (c.coreLinked > 0 ? 'warn' : 'fail');
            var cIc = coreAllLinked ? '\u2713' : (c.coreLinked > 0 ? '\u26A0' : '\u2717');
            html += statusCard(cSt, cIc, 'Core Units', c.coreLinked + ' / ' + QB.coreRequired + ' linked');
        }

        // Group cards  -  all groups present in either groupRules or currentUnits
        var allGroups = {};
        Object.keys(QB.groupRules).forEach(function(g) { allGroups[g] = true; });
        c.elecUnits.forEach(function(u) { if (u.electivegroup) allGroups[u.electivegroup] = true; });
        Object.keys(allGroups).sort().forEach(function(grp) {
            var rule = QB.groupRules[grp] || {};
            var cnt = c.groups[grp] || 0;
            var min = rule.min || 0;
            var max = rule.max || 999;
            var pass = min === 0 ? true : (cnt >= min && cnt <= max);
            var warn = min > 0 && cnt > 0 && cnt < min;
            var req = min > 0 ? 'min ' + min : 'optional';
            if (rule.max && rule.max < 999) req += ', max ' + rule.max;
            html += statusCard(pass ? 'pass' : (warn ? 'warn' : 'fail'),
                pass ? '\u2713' : (warn ? '\u26A0' : '\u2717'),
                'Group ' + grp, cnt + ' selected' + (min > 0 ? ' (' + req + ')' : ''));
        });

        // Elective card: same philosophy as Core — show linked/required as primary metric.
        if (QB.electiveReq > 0 && Object.keys(QB.groupRules).length === 0) {
            var elecAllLinked = c.elecLinked >= QB.electiveReq;
            var eSt = elecAllLinked ? 'pass' : (c.elecLinked > 0 ? 'warn' : 'fail');
            var eIc = elecAllLinked ? '\u2713' : (c.elecLinked > 0 ? '\u26A0' : '\u2717');
            html += statusCard(eSt, eIc, 'Elective Units', c.elecLinked + ' / ' + QB.electiveReq + ' linked');
        }

        // Credit points cards (engineering/metalwork points-based qualifications)
        if (QB.pointsSystem && QB.pointsRequired > 0) {
            // Core points  -  show pass/fail if threshold is known, otherwise informational
            if (c.coreUnits.length > 0) {
                if (QB.corePointsRequired > 0) {
                    var cpp = c.corePoints >= QB.corePointsRequired;
                    var cpw = c.corePoints > 0 && !cpp;
                    html += statusCard(cpp ? 'pass' : (cpw ? 'warn' : 'fail'),
                        cpp ? '\u2605' : (cpw ? '\u26A0' : '\u2717'),
                        'Core Points', c.corePoints + ' / ' + QB.corePointsRequired + ' pts');
                } else {
                    html += statusCard('info', '\u2605', 'Core Points', c.corePoints + ' pts selected');
                }
            }
            // Elective points  -  show pass/fail if threshold is known, otherwise informational
            if (c.elecUnits.length > 0) {
                if (QB.electivePointsRequired > 0) {
                    var epp = c.electivePoints >= QB.electivePointsRequired;
                    var epw = c.electivePoints > 0 && !epp;
                    html += statusCard(epp ? 'pass' : (epw ? 'warn' : 'fail'),
                        epp ? '\u2606' : (epw ? '\u26A0' : '\u2717'),
                        'Elective Points', c.electivePoints + ' / ' + QB.electivePointsRequired + ' pts');
                } else {
                    html += statusCard('info', '\u2606', 'Elective Points', c.electivePoints + ' pts selected');
                }
            }
            // Total points vs required
            var pp = c.totalPoints >= QB.pointsRequired;
            var pw = c.totalPoints > 0 && !pp;
            html += statusCard(pp ? 'pass' : (pw ? 'warn' : 'fail'),
                pp ? '\u2605' : (pw ? '\u26A0' : '\u2717'),
                'Total Points', c.totalPoints + ' / ' + QB.pointsRequired + ' pts');
        }

        // Total Units card: shows LINKED/required — "how many units are fully set up in Moodle?"
        //
        // BUG: was c.total (QB.currentUnits.length) which counts all mandatory core units
        // that are auto-added when TGA loads, regardless of whether the admin has linked them.
        // Result: 10 core units are force-added → c.total=10 on page load before any work is done,
        // and c.total=12 when 2 electives are ticked even though only 7 units are actually linked.
        // This caused "10/12 selected" on load and "✓ 12/12 selected" (GREEN) when only 7 linked.
        //
        // Fix: use c.linked (Moodle-linked count) matching the Core and Elective cards.
        // Example: 5 core linked + 2 elective linked = "⚠ 7 / 12 linked" (was "✓ 12/12 selected").
        if (QB.totalRequired > 0) {
            var totAllLinked = c.linked >= QB.totalRequired;
            var totSt = totAllLinked ? 'pass' : (c.linked > 0 ? 'warn' : 'fail');
            var totIc = totAllLinked ? '\u2713' : (c.linked > 0 ? '\u26A0' : '\u2717');
            html += statusCard(totSt, totIc, 'Total Units', c.linked + ' / ' + QB.totalRequired + ' linked');
        }

        // Nominal hours info
        if (c.hours > 0) {
            html += statusCard('info', '\uD83D\uDD50', 'Nominal Hours', c.hours + ' hrs total');
        }

        // -- Auto-suggest missing units --
        if (QB.tgaLoaded && QB.tgaUnits.length > 0) {
            var suggestions = [];
            // Check each group for shortfalls
            Object.keys(QB.groupRules).sort().forEach(function(grp) {
                var rule = QB.groupRules[grp]; var min = rule.min || 0;
                var cnt = c.groups[grp] || 0;
                var shortfall = min - cnt;
                if (shortfall > 0) {
                    // Find TGA units in this group that are not selected
                    var available = QB.tgaUnits.filter(function(u) {
                        return u.electivegroup === grp && !QB.currentUnits.find(function(cu) { return cu.unitcode === u.unitcode; });
                    });
                    // BUG-JS-1 FIX: Off-by-one. shortfall + 1 showed one extra suggestion
                    // beyond what the packaging rules actually require. Use shortfall only.
                    available.slice(0, shortfall).forEach(function(u) {
                        suggestions.push({ grp: grp, unit: u, reason: 'Need ' + shortfall + ' more in Group ' + grp });
                    });
                }
            });
            // Check core shortfall
            if (QB.coreRequired > 0 && c.coreUnits.length < QB.coreRequired) {
                var missingCore = QB.tgaUnits.filter(function(u) {
                    return u.iscore && !QB.currentUnits.find(function(cu) { return cu.unitcode === u.unitcode; });
                });
                missingCore.slice(0, 3).forEach(function(u) {
                    suggestions.push({ grp: 'Core', unit: u, reason: 'Core unit not yet added' });
                });
            }
            if (suggestions.length > 0) {
                var sfHtml = '<div class=\"qb-autofix\">' +
                    '<div class=\"qb-autofix-title\">\uD83D\uDCA1 Suggested units to complete packaging rules:</div>';
                suggestions.slice(0, 8).forEach(function(s) {
                    sfHtml += '<div class=\"qb-autofix-item\">' +
                        '<span class=\"qb-badge qb-badge-group\" style=\"min-width:60px;text-align:center\">' + escH(s.grp) + '</span>' +
                        '<span class=\"qb-unit-code\">' + escH(s.unit.unitcode) + '</span>' +
                        '<span class=\"qb-unit-name\">' + escH(s.unit.unitname) + '</span>' +
                        '<span class=\"text-muted\" style=\"font-size:0.75rem;margin-left:auto\">' + escH(s.reason) + '</span>' +
                        '</div>';
                });
                sfHtml += '</div>';
                html += sfHtml;
            }
        }

        // === Overall QPR banner ===
        // Show whenever packaging rules are known  -  either from DB (totalRequired) or a points system.
        // Previously gated on QB.tgaLoaded which silently hid the banner for existing qualifications
        // that never triggered a TGA session in the current page load.
        if (QB.totalRequired > 0 || QB.pointsSystem) {
            var qprFail = false;
            if (QB.coreRequired > 0 && c.coreUnits.length < QB.coreRequired) qprFail = true;
            if (QB.totalRequired > 0 && !QB.pointsSystem && c.total < QB.totalRequired) qprFail = true;
            if (QB.electiveReq > 0 && Object.keys(QB.groupRules).length === 0 && c.elecUnits.length < QB.electiveReq) qprFail = true;
            // Collect engine errors so they can be shown to the user (not just qprFail flag)
            var engineErrors = [];
            // Group-based engine
            if (Object.keys(QB.groupRules).length > 0) {
                var vqResult = validateQualification(QB.currentUnits, {groups: QB.groupRules});
                if (!vqResult.valid) {
                    qprFail = true;
                    engineErrors = engineErrors.concat(vqResult.errors || []);
                }
            }
            // Safety-net: when TGA is loaded and the unit pool contains elective units,
            // but the user has selected zero electives, the qualification cannot be compliant.
            // This catches qualifications where electiveReq was not derivable from packaging rules
            // text (e.g. TGA REST returned no totalUnits) but elective units clearly exist.
            if (QB.tgaLoaded && !QB.pointsSystem && Object.keys(QB.groupRules).length === 0) {
                var tgaElecPoolCount = QB.tgaUnits.filter(function(u) { return !u.iscore; }).length;
                if (tgaElecPoolCount > 0 && c.elecUnits.length === 0) {
                    qprFail = true;
                    engineErrors.push(
                        'No elective units selected  -  ' + tgaElecPoolCount +
                        ' elective unit' + (tgaElecPoolCount !== 1 ? 's are' : ' is') +
                        ' available in the TGA pool. Select the required elective units to satisfy the packaging rules.'
                    );
                }
            }
            // Points-based engine
            if (QB.pointsSystem && QB.pointsRequired > 0) {
                var eqResult = evaluateQualification(QB.currentUnits, {
                    totalRequired:    QB.pointsRequired,
                    coreRequired:     QB.corePointsRequired || 0,
                    electiveRequired: QB.electivePointsRequired || 0,
                });
                if (!eqResult.valid) {
                    qprFail = true;
                    engineErrors = engineErrors.concat(eqResult.errors || []);
                }
            }
            var linkOk = c.total > 0 && c.linked === c.total;
            var banner;
            if (!qprFail && linkOk) {
                banner = '<div class=\"qb-qpr-banner pass\">\u2713 QPR COMPLIANT \u2014 All packaging rules satisfied and all units linked to Moodle courses</div>';
            } else if (!qprFail) {
                // Packaging rules met but some units still unlinked — show AMBER, not green.
                // Green would imply the qualification is delivery-ready, which it is not until
                // every unit has a Moodle course link.
                var unlinkedN = c.total - c.linked;
                banner = '<div class=\"qb-qpr-banner warn\">\u26A0 PACKAGING RULES MET \u2014 ' + unlinkedN + ' unit' + (unlinkedN !== 1 ? 's' : '') + ' still need Moodle course links</div>';
            } else {
                banner = '<div class=\"qb-qpr-banner fail\">\u2717 NOT YET COMPLIANT \u2014 Packaging rules not yet satisfied</div>';
                if (engineErrors.length > 0) {
                    banner += '<div class=\"qb-engine-errors\">' +
                        engineErrors.map(function(e) {
                            return '<div class=\"qb-engine-error-item\">\u2717 ' + escH(e) + '</div>';
                        }).join('') +
                        '</div>';
                }
            }
            html = banner + html;
        }

        $('#qb-compliance-cards').html(html || '<span class=\"text-muted\">Load from TGA to see compliance status.</span>');
    }

    function statusCard(cls, icon, label, value, sub) {
        var subHtml = sub ? '<div class=\"qb-status-sub\">' + sub + '</div>' : '';
        return '<div class=\"qb-status-card ' + cls + '\">' +
            '<div class=\"qb-status-icon\">' + icon + '</div>' +
            '<div><div class=\"qb-status-label\">' + label + '</div>' +
            '<div class=\"qb-status-value\">' + value + '</div>' + subHtml + '</div></div>';
    }

    // Section-header pill showing Moodle link count ("X / Y linked") for core sections.
    // Separate from statusPill() which shows selected/checkbox count for group/elective pools.
    function linkedPill(linked, required) {
        if (required === 0) return linked > 0 ? linked + ' linked' : '';
        var cls = linked >= required ? 'text-success' : (linked > 0 ? 'text-warning' : 'text-danger');
        var icon = linked >= required ? '\u2713' : (linked > 0 ? '\u26A0' : '\u2717');
        return '<span class=\"' + cls + '\">' + icon + ' ' + linked + ' / ' + required + ' linked</span>';
    }

    // ===== UNIT BUILDER =====
    /**
     * FIX-QB-MOODLE-ORDER (v5.9.272): Sort QB.tgaUnits in place so that the unit list
     * rendered by the Qualbuilder matches the top-to-bottom order of courses on the
     * Moodle "Manage course categories" page.
     *
     * Each linked unit's Moodle course carries a `sortorder` value (added to the PHP
     * web-service response in this version).  Units are sorted ascending by that value.
     * Units not yet linked to any course (sortorder unknown) go to the END of their
     * section so they don't displace matched units.
     *
     * Only fires when TGA is loaded and courses are available; safe to call multiple
     * times (idempotent — same input always produces same output).
     */
    function sortTgaUnitsByMoodleOrder() {
        if (!QB.tgaLoaded || !QB.courses.length) { return; }
        // Build a sortorder map: unitcode → course.sortorder (from the linked course).
        var orderMap = {};
        QB.currentUnits.forEach(function(cu) {
            if (cu.courseid > 0) {
                var course = QB.courses.find(function(c) { return c.id === cu.courseid; });
                if (course && course.sortorder != null) {
                    orderMap[cu.unitcode] = course.sortorder;
                }
            }
        });
        QB.tgaUnits.sort(function(a, b) {
            var sa = (orderMap[a.unitcode] != null) ? orderMap[a.unitcode] : 999999999;
            var sb = (orderMap[b.unitcode] != null) ? orderMap[b.unitcode] : 999999999;
            return sa - sb;
        });
    }

    function renderUnitBuilder() {
        var html = '';
        // Sort TGA units to match Moodle course order before rendering.
        sortTgaUnitsByMoodleOrder();
        // Reset manual-group mode; re-set below if applicable (no TGA unit-level group data).
        QB.manualGroupMode = false;
        QB.groupRuleKeys = [];

        // Group TGA units
        var tgaCore = QB.tgaUnits.filter(function(u) { return u.iscore; });
        var tgaElec = QB.tgaUnits.filter(function(u) { return !u.iscore; });
        var tgaGroups = {};
        tgaElec.forEach(function(u) {
            var g = u.electivegroup || '';
            if (!tgaGroups[g]) tgaGroups[g] = [];
            tgaGroups[g].push(u);
        });

        // If no TGA loaded but we have existing units, show them
        var showRawExisting = !QB.tgaLoaded && QB.currentUnits.length > 0;

        // QB-VARIANTS info banner — shown when a semester is selected and at least one
        // unit has a primary course linked.  Dismissed once per session via sessionStorage.
        var hasLinked = QB.currentUnits.some(function(u) { return u.courseid > 0; });
        if (QB.semesterid && hasLinked) {
            var dismissed = false;
            try { dismissed = sessionStorage.getItem('qb_variants_info_dismissed') === '1'; } catch(e) {}
            if (!dismissed) {
                html += '<div class="qb-variants-info">' +
                    '<span class="qb-variants-info-icon">\u2139\uFE0F</span>' +
                    '<div class="qb-variants-info-body">' +
                    '<strong>How course variants work</strong>' +
                    '<button type="button" class="qb-variants-info-dismiss" title="Dismiss">\u00D7</button>' +
                    '<br>Each unit has one <strong>primary course</strong> (the white/green badge \u2014 click it to change) ' +
                    'plus optional <strong>variant chips</strong> for other Moodle courses that deliver the same unit. ' +
                    'Variants are auto-detected from courses in this semester whose short name contains the unit code. ' +
                    'Click <code>\u00D7</code> on a chip to remove a stream you don\u2019t want watched. Click <code>+</code> to add one manually.' +
                    '<br><strong>Why it matters:</strong> the system watches <em>all</em> linked courses. ' +
                    'When a student completes their course \u2014 whichever stream they\u2019re in \u2014 their AVETMISS enrolment record is created automatically, ' +
                    'and once they\u2019re Competent in every unit their Testamur (certificate) is issued without anyone having to click anything.' +
                    '<br><strong>Example:</strong> TLIX0037 is delivered by three trainers in separate courses ' +
                    '(<code>TLIX0037 26S1 \u2013 EL</code>, <code>TLIX0037 26S1 \u2013 CD</code>, <code>TLIX0037 26S1 \u2013 ND</code>). ' +
                    'Without variants, only students in the primary course get their cert. With variants, all three streams are watched \u2014 every student gets their cert.' +
                    '</div></div>';
            }
        }

        if (showRawExisting) {
            html += renderExistingUnitsSection();
        } else {
            // === CORE UNITS ===
            var coreRows = '';
            if (tgaCore.length > 0) {
                tgaCore.forEach(function(tu) {
                    var existing = QB.currentUnits.find(function(u) { return u.unitcode === tu.unitcode; });
                    coreRows += unitRow(tu.unitcode, tu.unitname, 'core', '', tu.nominalhours, existing ? existing.courseid : 0, true, true, false, tu.creditpoints || 0);
                });
            } else {
                var existingCore = QB.currentUnits.filter(function(u) { return u.unittype === 'core'; });
                if (existingCore.length > 0) {
                    existingCore.forEach(function(u) {
                        coreRows += unitRow(u.unitcode, u.unitname, 'core', '', u.nominalhours, u.courseid, true, true, false, u.creditpoints || 0);
                    });
                } else {
                    coreRows = '<div class=\"qb-empty-section\">No core units yet. Click \"Load from TGA\" to auto-populate.</div>';
                }
            }
            var coreReqLabel = QB.coreRequired > 0 ? ' <small class=\"text-muted\">(all ' + QB.coreRequired + ' required)</small>' : '';
            var coreLinkedForPill = QB.currentUnits.filter(function(u){return u.unittype==='core' && u.courseid > 0;}).length;
            html += section('CORE', 'Core Units' + coreReqLabel, tgaCore.length > 0 ? linkedPill(coreLinkedForPill, QB.coreRequired) : '', 'core', coreRows);

            // === ELECTIVE GROUPS (A through Y and beyond) ===
            Object.keys(tgaGroups).filter(function(g){return g!=='';}).sort().forEach(function(grp) {
                var rule = QB.groupRules[grp] || {};
                var units = tgaGroups[grp];
                var selectedCount = QB.currentUnits.filter(function(u) { return u.unittype !== 'core' && u.electivegroup === grp; }).length;
                var min = rule.min || 0;
                var max = rule.max || 999;
                var reqLabel = (min > 0 && min === max && max < 999)
                    ? (min === 1 ? '1 unit only' : min + ' units only')
                    : (min > 0 ? 'Min ' + min + (max < 999 ? ', max ' + max : '') : 'optional');
                var rows = '';
                units.forEach(function(tu) {
                    var existing = QB.currentUnits.find(function(u) { return u.unitcode === tu.unitcode; });
                    rows += unitRow(tu.unitcode, tu.unitname, 'elective', grp, tu.nominalhours, existing ? existing.courseid : 0, !!existing, false, false, tu.creditpoints || 0);
                });
                html += section('GROUP', 'Group ' + grp + ' <small class=\"text-muted\">' + reqLabel + '</small>', statusPill(selectedCount, min, min === 0), grp, rows);
            });

            // === GENERAL ELECTIVES (no group code) ===
            if (tgaGroups[''] && tgaGroups[''].length > 0) {
                // Check if the packaging rules define groups but the TGA API returned no group
                // assignments on individual units (common for Cert II and some older qualifications).
                var hasGroupRules = QB.groupRules && Object.keys(QB.groupRules).length > 0;
                var hasTaggedGroups = Object.keys(tgaGroups).filter(function(g){return g!=='';}).length > 0;
                // FIX-QB-MANUAL-GROUP: expose flags so unitRow can render group assignment dropdowns.
                QB.manualGroupMode = hasGroupRules && !hasTaggedGroups;
                QB.groupRuleKeys = hasGroupRules ? Object.keys(QB.groupRules).sort() : [];
                var groupNotice = '';
                if (hasGroupRules && !hasTaggedGroups) {
                    var ruleKeys = Object.keys(QB.groupRules).sort().join(', ');
                    groupNotice = '<div class="alert alert-info qb-group-notice" style="font-size:12px;margin-bottom:8px;">'
                        + '<strong>Note:</strong> The packaging rules for this qualification define elective groups ('
                        + ruleKeys.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                        + '), but the TGA API has not assigned group codes to individual units. '
                        + 'All elective units are listed below. Refer to '
                        + '<a href="https://training.gov.au" target="_blank" rel="noopener">training.gov.au</a> '
                        + 'to identify which units belong to each group when making your selection.'
                        + '</div>';
                }
                var genRows = '';
                tgaGroups[''].forEach(function(tu) {
                    var existing = QB.currentUnits.find(function(u) { return u.unitcode === tu.unitcode; });
                    genRows += unitRow(tu.unitcode, tu.unitname, 'elective', '', tu.nominalhours, existing ? existing.courseid : 0, !!existing, false, false, tu.creditpoints || 0);
                });
                var genSel = QB.currentUnits.filter(function(u) { return u.unittype !== 'core' && u.unittype !== 'imported' && !u.electivegroup; }).length;
                // FIX-QB-SECTION-PILL: show '' (not '0 selected') when nothing is checked yet.
                html += section('ELECTIVE', 'General Electives', genSel > 0 ? genSel + ' selected' : '', 'elective', groupNotice + genRows);
            }
        }

        // === IMPORTED UNITS ===
        var importedUnits = QB.currentUnits.filter(function(u) { return u.unittype === 'imported'; });
        var importedRows = '';
        importedUnits.forEach(function(u) {
            importedRows += unitRow(u.unitcode, u.unitname, 'imported', '', u.nominalhours, u.courseid, true, false, true, u.creditpoints || 0);
        });
        var addForm = '<div class=\"qb-add-imported-form\" id=\"qb-add-imported-form\">' +
            '<div class=\"qb-form-row\">' +
            '<div class=\"qb-form-group\"><label>Unit Code</label><input type=\"text\" id=\"qb-imp-code\" class=\"form-control form-control-sm\" placeholder=\"UNITCODE\" style=\"width:130px;text-transform:uppercase\"></div>' +
            '<div class=\"qb-form-group\" style=\"flex:1\"><label>Unit Name</label><input type=\"text\" id=\"qb-imp-name\" class=\"form-control form-control-sm\" placeholder=\"Unit name\"></div>' +
            '<div class=\"qb-form-group\"><label>Hours</label><input type=\"number\" id=\"qb-imp-hours\" class=\"form-control form-control-sm\" placeholder=\"0\" style=\"width:70px\" min=\"0\"></div>' +
            '<div class=\"qb-form-group\" style=\"align-self:flex-end;display:flex;gap:6px\">' +
            '<button type=\"button\" class=\"btn btn-success btn-sm\" id=\"qb-imported-save-btn\">Add</button>' +
            '<button type=\"button\" class=\"btn btn-outline-secondary btn-sm\" id=\"qb-imported-cancel-btn\">Cancel</button></div></div></div>';
        var importedSection = section('IMPORTED', 'Imported Units <small class=\"text-muted\">(not in TGA unit list)</small>', importedUnits.length > 0 ? importedUnits.length + ' units' : '', 'imported',
            importedRows +
            '<div class=\"qb-empty-section\" style=\"' + (importedUnits.length ? 'display:none' : '') + '\" id=\"qb-imported-empty\">No imported units added yet.</div>' +
            addForm +
            '<div style=\"padding:8px 14px\"><button type=\"button\" class=\"btn btn-outline-secondary btn-sm\" id=\"qb-add-imported-btn\">+ Add Imported Unit</button></div>');
        html += importedSection;

        // === DUPLICATE UNITS + OTHER MOODLE COURSES ===
        // After all TGA sections, scan every course in the selected semester and sort
        // them into two buckets:
        //
        //  DUPLICATE UNITS — course shares a unit code with a TGA unit but is NOT the
        //    primary linked course for that unit (e.g. two versions of TLIX0037 exist in
        //    Moodle: 26S2 standard and 26S2-ND).  These sit in their own section so the
        //    RTO can see and select the alternate version.
        //
        //  OTHER MOODLE COURSES — course has a unit code not present in TGA for this
        //    qualification at all.  The RTO can tick these to include them as imported units.
        //
        //  Courses that are already the primary linked course for a TGA unit are shown
        //  above in Core/Elective sections and are skipped here entirely.
        if (QB.semesterid && QB.courses.length) {
            var tgaCodeSet = {};
            QB.tgaUnits.forEach(function(tu) { tgaCodeSet[tu.unitcode.toUpperCase()] = true; });

            var moodleExtraRows = '';

            QB.courses.filter(function(c) { return c.category === QB.semesterid; }).forEach(function(c) {
                // Collect all unit codes this course maps to.
                var courseCodes = [];
                Object.keys(QB.unitCodeMap).forEach(function(uc) {
                    if (QB.unitCodeMap[uc].some(function(e) { return e.id === c.id; })) {
                        courseCodes.push(uc);
                    }
                });

                // If ANY code is in the TGA list the course is already shown above — skip.
                if (courseCodes.some(function(uc) { return tgaCodeSet[uc]; })) { return; }

                // No TGA code at all — "Other Moodle Courses".
                var bestCode = courseCodes[0] || '';
                var isSelected = !!QB.currentUnits.find(function(u) {
                    return u.courseid === c.id ||
                        (bestCode && u.unitcode.toUpperCase() === bestCode);
                });
                moodleExtraRows += unitRow(bestCode, c.fullname, 'imported', '', 0,
                    isSelected ? c.id : 0, isSelected, false, true, 0);
            });

            if (moodleExtraRows) {
                html += section(
                    '',
                    'Other Moodle Courses <small class=\"text-muted\">(in semester, unit code not in TGA for this qualification)</small>',
                    '',
                    'moodle-extra',
                    moodleExtraRows
                );
            }
        }

        $('#qb-unit-builder').html(html);
        applyUnitFilter();
        $('#qb-unit-search-bar').show();
    }

    // ---------------------------------------------------------------
    // Live unit search + type filter
    // Runs after every renderUnitBuilder() call so the filter state
    // is preserved across TGA reloads, checkbox toggles, etc.
    // ---------------------------------------------------------------
    var _unitFilterTimer = null;

    function applyUnitFilter() {
        var term  = ($('#qb-unit-search').val() || '').trim().toLowerCase();
        var type  = $('#qb-unit-type-btns .qb-type-btn.active').data('type') || 'all';

        var totalVisible = 0;
        var totalRows    = 0;

        // Walk every section and hide/show rows, then hide empty sections.
        $('#qb-unit-builder .qb-section').each(function() {
            var $section  = $(this);
            var sectionId = $section.attr('id') || '';
            // Imported section always visible regardless of type filter
            var isImported = sectionId === 'qb-section-imported';

            var sectionVisible = 0;

            $section.find('.qb-unit-row').each(function() {
                var $row    = $(this);
                var code    = ($row.data('unitcode') || '').toLowerCase();
                var utype   = ($row.data('unittype') || '').toLowerCase();
                var $info   = $row.find('.qb-unit-info');
                var name    = ($info.text() || '').toLowerCase();

                totalRows++;

                // Type filter
                var typeOk = isImported || type === 'all'
                    || (type === 'core'     && utype === 'core')
                    || (type === 'elective' && utype !== 'core');

                // Text match -- code starts-with wins, otherwise anywhere in code or name
                var textOk = term === ''
                    || code.indexOf(term) === 0
                    || code.indexOf(term) !== -1
                    || name.indexOf(term) !== -1;

                if (typeOk && textOk) {
                    $row.show();
                    sectionVisible++;
                    totalVisible++;
                } else {
                    $row.hide();
                }
            });

            // Hide/show the whole section block
            if (isImported) {
                $section.show(); // never hide imported
            } else {
                $section.toggle(sectionVisible > 0);
            }
        });

    }

    function renderExistingUnitsSection() {
        // Fallback: show existing units grouped by type when no TGA data loaded.
        // Groups A, B, C, etc. are rendered as separate sections to match the TGA-loaded view.
        var html = '';
        var coreU = QB.currentUnits.filter(function(u) { return u.unittype === 'core'; });
        var elecU = QB.currentUnits.filter(function(u) { return u.unittype === 'elective'; });

        if (coreU.length) {
            var rows = '';
            coreU.forEach(function(u) { rows += unitRow(u.unitcode, u.unitname, 'core', '', u.nominalhours, u.courseid, true, true, false, u.creditpoints || 0); });
            // Use linkedPill for consistency with the TGA-loaded path: core section
            // always shows linked count, not just the raw unit count.
            var fallbackCoreLinked = coreU.filter(function(u) { return u.courseid > 0; }).length;
            var fallbackCorePill   = QB.coreRequired > 0
                ? linkedPill(fallbackCoreLinked, QB.coreRequired)
                : (coreU.length + ' units');
            html += section('CORE', 'Core Units', fallbackCorePill, 'core', rows);
        }
        if (elecU.length) {
            var grps = {};
            elecU.forEach(function(u) {
                var g = u.electivegroup || '';
                if (!grps[g]) grps[g] = [];
                grps[g].push(u);
            });
            // Render named groups (A, B, C, ...) as separate sections.
            Object.keys(grps).filter(function(g) { return g !== ''; }).sort().forEach(function(g) {
                var rule = QB.groupRules[g] || {};
                var min = rule.min || 0;
                var max = rule.max || 999;
                var reqLabel = (min > 0 && min === max && max < 999)
                    ? (min === 1 ? '1 unit only' : min + ' units only')
                    : (min > 0 ? 'Min ' + min + (max < 999 ? ', max ' + max : '') : 'optional');
                var grows = '';
                grps[g].forEach(function(u) { grows += unitRow(u.unitcode, u.unitname, 'elective', g, u.nominalhours, u.courseid, true, false, false, u.creditpoints || 0); });
                html += section('GROUP', 'Group ' + g + ' <small class=\"text-muted\">' + reqLabel + '</small>', statusPill(grps[g].length, min, min === 0), g, grows);
            });
            // Render ungrouped electives.
            if (grps[''] && grps[''].length) {
                var erows = '';
                grps[''].forEach(function(u) { erows += unitRow(u.unitcode, u.unitname, 'elective', '', u.nominalhours, u.courseid, true, false, false, u.creditpoints || 0); });
                html += section('ELECTIVE', 'Elective Units', grps[''].length + ' units', 'elective', erows);
            }
        }
        return html;
    }

    function section(badgeType, titleHtml, statusHtml, sectionId, rowsHtml) {
        var badgeCls = { 'CORE':'qb-badge-core','GROUP':'qb-badge-group','ELECTIVE':'qb-badge-elective','IMPORTED':'qb-badge-imported' };
        var cls = badgeCls[badgeType] || 'qb-badge-elective';
        // Empty badgeType = no badge label rendered (used for Duplicate/Extra Moodle sections).
        var badgeHtml = badgeType ? '<span class=\"qb-badge ' + cls + '\">' + badgeType + '</span>' : '';
        return '<div class=\"qb-section\" id=\"qb-section-' + sectionId + '\">' +
            '<div class=\"qb-section-header\">' +
            badgeHtml +
            '<h5>' + titleHtml + '</h5>' +
            (statusHtml ? '<span class=\"qb-section-status\">' + statusHtml + '</span>' : '') +
            '</div>' +
            '<div class=\"qb-unit-rows\">' + rowsHtml + '</div></div>';
    }

    function statusPill(current, required, optional) {
        if (optional || required === 0) return current > 0 ? current + ' selected' : '';
        var cls = current >= required ? 'text-success' : (current > 0 ? 'text-warning' : 'text-danger');
        var icon = current >= required ? '\u2713' : (current > 0 ? '\u26A0' : '\u2717');
        return '<span class=\"' + cls + '\">' + icon + ' ' + current + ' / ' + required + ' selected</span>';
    }

    /**
     * FIX-QB-SECTION-PILL (v5.9.270): centralised section-header status pill updater.
     * Previously each caller (onUnitToggle, deleteUnit, onCourseChange) had its own
     * inline copy with subtle differences, so some paths left stale counts.
     * Now all pill updates go through this single function.
     *
     * @param {string} type  unittype of the changed unit ('core','elective','imported')
     * @param {string} group electivegroup of the changed unit ('' for ungrouped electives)
     */
    function updateSectionPill(type, group) {
        if (type === 'core') {
            var coreLinkedCount = QB.currentUnits.filter(function(u) { return u.unittype === 'core' && u.courseid > 0; }).length;
            $('#qb-section-core .qb-section-status').html(linkedPill(coreLinkedCount, QB.coreRequired) || '');
        } else if (group) {
            var grpCount = QB.currentUnits.filter(function(u) { return u.electivegroup === group && u.unittype !== 'core'; }).length;
            var grpRule  = QB.groupRules[group] || {};
            var grpMin   = grpRule.min || 0;
            var grpPill  = statusPill(grpCount, grpMin, grpMin === 0);
            $('#qb-section-' + group + ' .qb-section-status').html(grpPill || (grpCount > 0 ? grpCount + ' selected' : ''));
        } else if (type === 'imported') {
            var impCount = QB.currentUnits.filter(function(u) { return u.unittype === 'imported'; }).length;
            $('#qb-section-imported .qb-section-status').html(impCount > 0 ? impCount + ' units' : '');
        } else {
            // General electives (type=elective, no group)
            var genCount = QB.currentUnits.filter(function(u) { return u.unittype !== 'core' && u.unittype !== 'imported' && !u.electivegroup; }).length;
            $('#qb-section-elective .qb-section-status').html(genCount > 0 ? genCount + ' selected' : '');
        }
    }

    function unitRow(code, name, type, group, hours, courseid, checked, isCore, isImported, creditpts) {
        var opts = buildCourseOptions(courseid, code); // FIX-QB-DROPDOWN-ROOTPOOL (v5.9.278)
        var checkedAttr = checked ? ' checked' : '';
        var rowCls = 'qb-unit-row' + (checked ? ' selected' : '');
        var hoursHtml = hours > 0 ? '<span class=\"qb-unit-hours\">' + hours + 'h</span>' : '';
        var ptsHtml = (QB.pointsSystem && creditpts > 0) ? '<span class=\"qb-unit-points\">\u2605' + creditpts + 'pts</span>' : '';
        var checkHtml = isCore
            ? '<span class=\"qb-unit-lock\" title=\"Core  -  required\">\uD83D\uDD12</span>'
            : '<input type=\"checkbox\" class=\"qb-unit-cb\" data-unitcode=\"' + escH(code) + '\" data-unittype=\"' + type + '\" data-unitgroup=\"' + escH(group) + '\"' + checkedAttr + '>';
        var delBtn = (isImported || !isCore)
            ? '<button type=\"button\" class=\"qb-del-unit-btn btn btn-sm btn-outline-danger\" data-unitcode=\"' + escH(code) + '\" style=\"padding:1px 6px;font-size:0.75rem;visibility:hidden\">\u2717</button>'
            : '';
        // QB-VARIANTS: look up variant course IDs for this unit from QB.currentUnits.
        var unitState = QB.currentUnits.find(function(u) { return u.unitcode === code; });
        var unitVariants = unitState ? (unitState.variants || []) : [];

        // Course cell: primary badge + variant chips + add dropdown.
        // Unlinked units keep the plain dropdown until a primary is chosen.
        var courseCell;
        if (courseid > 0) {
            var lc = QB.courses.find(function(x) { return x.id === courseid; });
            var cshort = lc ? escH((lc.shortname || lc.fullname || '').substring(0, 26)) : 'Linked';
            // Primary badge (click reveals change dropdown — existing behaviour).
            var primaryHtml = '<span class=\"qb-linked-badge\" data-unitcode=\"' + escH(code) + '\" title=\"Primary course \u2014 click to change\">\u2713 ' + cshort + '</span>' +
                '<select class=\"form-control form-control-sm qb-course-sel\" data-unitcode=\"' + escH(code) + '\" style=\"display:none\">' + opts + '</select>';

            // Variant chips.
            var variantChipsHtml = '';
            unitVariants.forEach(function(vid) {
                var vc = QB.courses.find(function(x) { return x.id === vid; });
                if (!vc) return;
                var vshort = escH((vc.shortname || vc.fullname || '').substring(0, 22));
                variantChipsHtml += '<span class=\"qb-variant-chip\" data-unitcode=\"' + escH(code) + '\" data-courseid=\"' + vid + '\" title=\"' + vshort + '\">' +
                    vshort + '<span class=\"qb-variant-remove\" data-unitcode=\"' + escH(code) + '\" data-courseid=\"' + vid + '\">\u00D7</span></span>';
            });

            // Add-variant: a small + circle button that reveals the select on click.
            var addVariantHtml = '';
            if (QB.semesterid) {
                var usedMap = {};
                usedMap[courseid] = true;
                unitVariants.forEach(function(vid) { usedMap[vid] = true; });
                var availableCourses = QB.courses.filter(function(c) { return c.category === QB.semesterid && !usedMap[c.id]; });
                if (availableCourses.length > 0) {
                    var addOpts = '<option value=\"0\">\u2014 select a course \u2014</option>';
                    availableCourses.forEach(function(c) {
                        addOpts += '<option value=\"' + c.id + '\">' + escH((c.shortname || c.fullname || '').substring(0, 40)) + '</option>';
                    });
                    addVariantHtml = '<span class=\"qb-variant-add-wrap\">' +
                        '<button type=\"button\" class=\"qb-variant-add-btn\" data-unitcode=\"' + escH(code) + '\" ' +
                        'title=\"Add another course that delivers this unit (e.g. a different trainer\u2019s class). The reconciler watches all linked courses \u2014 students in any of them get their AVETMISS record and certificate automatically.\">+</button>' +
                        '<select class=\"qb-variant-add form-control form-control-sm\" data-unitcode=\"' + escH(code) + '\" style=\"display:none\">' + addOpts + '</select>' +
                        '</span>';
                }
            }

            courseCell = '<div class=\"qb-unit-course linked\">' +
                primaryHtml + variantChipsHtml + addVariantHtml + '</div>';
        } else {
            courseCell = '<div class=\"qb-unit-course unlinked\">' +
                '<span style=\"color:#f59e0b;font-size:0.8rem;margin-right:4px\" title=\"Not linked\">\u26A0</span>' +
                '<select class=\"form-control form-control-sm qb-course-sel\" data-unitcode=\"' + escH(code) + '\">' + opts + '</select>' +
                '</div>';
        }
        // FIX-QB-MANUAL-GROUP: When packaging rules define groups but TGA has no unit-level
        // group codes, show a dropdown so the admin can assign units to groups manually.
        var groupHtml = '';
        if (type === 'elective' && QB.manualGroupMode) {
            var groupOpts = '<option value=\"\">-- No group --</option>';
            (QB.groupRuleKeys || []).forEach(function(g) {
                groupOpts += '<option value=\"' + escH(g) + '\"' + (group === g ? ' selected' : '') + '>Group ' + escH(g) + '</option>';
            });
            groupHtml = '<select class=\"qb-group-sel form-control form-control-sm\" data-unitcode=\"' + escH(code) + '\" title=\"Assign this unit to a packaging group\" style=\"width:90px;font-size:0.7rem;margin-left:4px;display:inline-block;vertical-align:middle;height:auto;padding:1px 4px;\">' + groupOpts + '</select>';
        } else if (group) {
            groupHtml = '<span class=\"qb-badge qb-badge-group\" style=\"font-size:0.7rem;margin-left:4px\">Grp&nbsp;' + escH(group) + '</span>';
        }
        return '<div class=\"' + rowCls + '\" data-unitcode=\"' + escH(code) + '\" data-unittype=\"' + type + '\" data-unitgroup=\"' + escH(group) + '\" data-unitname=\"' + escH(name) + '\"' +
            ' onmouseover=\"this.querySelector && this.querySelector(\'.qb-del-unit-btn\') && (this.querySelector(\'.qb-del-unit-btn\').style.visibility=\'visible\')\"' +
            ' onmouseout=\"this.querySelector && this.querySelector(\'.qb-del-unit-btn\') && (this.querySelector(\'.qb-del-unit-btn\').style.visibility=\'hidden\')\">'+
            '<div class=\"qb-unit-check\">' + checkHtml + '</div>' +
            '<div class=\"qb-unit-info\"><span class=\"qb-unit-code\">' + escH(code) + '</span>' +
            '<span class=\"qb-unit-name\">' + escH(name) + '</span>' + hoursHtml + ptsHtml +
            groupHtml + '</div>' +
            courseCell +
            '<div style=\"width:28px;flex-shrink:0\">' + delBtn + '</div>' +
            '</div>';
    }

    // FIX-QB-DROPDOWN-ROOTPOOL (v5.9.278): buildCourseOptions previously cascaded
    // semester → rootPool → ALL when the semester pool was empty, producing a
    // QB-ALL-SEM-COURSES (v5.9.285): Show EVERY course in the selected semester so
    // the RTO can freely choose which Moodle course links to which TGA unit.
    // Previously only unit-code-matched courses appeared; now ALL semester courses
    // are listed with matched courses (auto-link candidates) floated to the top,
    // followed by a divider, then the remaining courses in the semester.
    // When no semester is selected the dropdown is empty (only "-- Not linked --").
    function buildCourseOptions(selectedId, unitcode) {
        var semid = QB.semesterid || 0;
        var matchedIds = {};

        if (semid) {
            var uc = unitcode ? String(unitcode).toUpperCase() : '';
            var mapEntries = (uc && QB.unitCodeMap && QB.unitCodeMap[uc]) ? QB.unitCodeMap[uc] : [];
            mapEntries.forEach(function(m) {
                if (m.category === semid) { matchedIds[m.id] = true; }
            });
        }

        // Build pool: all semester courses, matched first then unmatched.
        var allSem     = semid ? QB.courses.filter(function(c) { return c.category === semid; }) : [];
        var matched    = allSem.filter(function(c) { return  matchedIds[c.id]; });
        var unmatched  = allSem.filter(function(c) { return !matchedIds[c.id]; });
        var pool       = matched.concat(unmatched);
        var matchedCnt = matched.length;

        // Always include currently-selected course even if cross-semester (admin override).
        if (selectedId && !pool.find(function(c) { return c.id === selectedId; })) {
            var extra = QB.courses.find(function(c) { return c.id === selectedId; });
            if (extra) { pool = pool.concat([extra]); }
        }

        var html = '<option value=\"0\">-- Not linked --</option>';
        pool.forEach(function(c, idx) {
            // Visual separator between unit-code-matched and remaining semester courses.
            if (matchedCnt > 0 && idx === matchedCnt) {
                html += '<option disabled>\u2500\u2500 Other courses in semester \u2500\u2500</option>';
            }
            var sel = c.id === selectedId ? ' selected' : '';
            html += '<option value=\"' + c.id + '\"' + sel + '>' + escH(c.shortname) + ' \u2014 ' + escH(c.fullname.substring(0, 55)) + '</option>';
        });
        return html;
    }

    // ===== UNIT INTERACTIONS =====
    function onUnitToggle() {
        var $cb = $(this);
        var code  = $cb.data('unitcode');
        var type  = $cb.data('unittype');
        var group = $cb.data('unitgroup');
        var $row  = $cb.closest('.qb-unit-row');
        var checked = $cb.is(':checked');
        $row.toggleClass('selected', checked);

        if (checked) {
            if (!QB.currentUnits.find(function(u) { return u.unitcode === code; })) {
                var tu = QB.tgaUnits.find(function(u) { return u.unitcode === code; });
                var courseid = parseInt($row.find('.qb-course-sel').val()) || 0;
                QB.currentUnits.push({
                    id: 0,
                    unitcode: code,
                    unitname: tu ? tu.unitname : ($row.data('unitname') || code),
                    unittype: type,
                    electivegroup: group,
                    nominalhours: tu ? (tu.nominalhours || 0) : 0,
                    courseid: courseid,
                    creditpoints: tu ? (tu.creditpoints || 0) : 0,
                });
            }
            renderComplianceDashboard();
            // CHECK: fast path — update the section pill without a full re-render.
            // The course cell stays as the visible dropdown (correct for a just-selected unit).
            updateSectionPill(type, group);
        } else {
            QB.currentUnits = QB.currentUnits.filter(function(u) { return u.unitcode !== code; });
            renderComplianceDashboard();
            // FIX-QB-DESELECT-RENDER (v5.9.273): UNCHECK needs a full re-render.
            // Without it the compact "✓ BSB226" badge stays visible on a deselected row,
            // making the unit look linked when it is no longer in QB.currentUnits.
            // renderUnitBuilder() also resets the section pills via section() so
            // a separate updateSectionPill() call is not needed here.
            renderUnitBuilder();
        }
    }

    function onCourseChange() {
        var $sel = $(this);
        var code     = $sel.data('unitcode');
        var courseid = parseInt($sel.val()) || 0;
        var unit = QB.currentUnits.find(function(u) { return u.unitcode === code; });
        if (unit) {
            unit.courseid = courseid;
            // If the new primary was previously a variant, remove it from variants.
            if (courseid && unit.variants) {
                unit.variants = unit.variants.filter(function(id) { return id !== courseid; });
            }
            // Re-render so the compact badge / dropdown switches correctly for this unit.
            renderUnitBuilder();
            renderComplianceDashboard();
        } else if (courseid > 0) {
            // FIX-QB-AUTOCHECK-ON-LINK (v5.9.269): selecting a course from the dropdown for
            // an UNCHECKED elective unit should implicitly select that unit.  Previously the
            // courseid was only stored in the DOM; if the user saved without checking the
            // checkbox the unit was missing from the save payload and disappeared on reload.
            // Now: choosing a real course (courseid > 0) auto-checks the checkbox, pushes
            // the unit into QB.currentUnits with the chosen courseid, and updates the
            // section status pill — identical to what onUnitToggle does on a manual check.
            var $row  = $sel.closest('.qb-unit-row');
            var $cb   = $row.find('.qb-unit-cb');
            if ($cb.length && !$cb.is(':checked')) {
                var type  = $cb.data('unittype')  || 'elective';
                var group = $cb.data('unitgroup') || '';
                var tu    = QB.tgaUnits.find(function(u) { return u.unitcode === code; });
                $cb.prop('checked', true);
                $row.addClass('selected');
                QB.currentUnits.push({
                    id:            0,
                    unitcode:      code,
                    unitname:      tu ? tu.unitname : ($row.data('unitname') || code),
                    unittype:      type,
                    electivegroup: group,
                    nominalhours:  tu ? (tu.nominalhours  || 0) : 0,
                    courseid:      courseid,
                    creditpoints:  tu ? (tu.creditpoints || 0) : 0,
                });
                // FIX-QB-AUTOCHECK-RENDER (v5.9.274): full re-render so the row
                // immediately switches from open-dropdown to compact-badge mode.
                // renderUnitBuilder() also recalculates section pills, so
                // the separate updateSectionPill() call is not needed here.
                // Order: renderUnitBuilder first (DOM), then renderComplianceDashboard
                // (cards) — consistent with the existing-unit branch above.
                renderUnitBuilder();
                renderComplianceDashboard();
            }
        }
        // If courseid resets to 0 for an already-ticked unit, the if(unit) branch above
        // handles it (courseid cleared in QB.currentUnits; unit stays selected).
    }

    // FIX-QB-MANUAL-GROUP: handle manual group assignment when TGA has no unit-level group codes.
    function onGroupChange() {
        var $sel  = $(this);
        var code  = $sel.data('unitcode');
        var newGroup = $sel.val() || '';
        var $row  = $sel.closest('.qb-unit-row');
        // Update the row's data-unitgroup so it is included in the save payload.
        $row.attr('data-unitgroup', newGroup);
        // FIX-QB-GROUP-CB-SYNC (v5.9.273/274): sync the CHECKBOX's data-unitgroup.
        // onUnitToggle and the onCourseChange auto-check path both read
        // $cb.data('unitgroup') from the checkbox element via jQuery's .data() accessor.
        // jQuery caches .data() values internally after the first read — subsequent
        // .attr() changes update the DOM attribute but NOT the cache, so .data() keeps
        // returning the old value.  We must call BOTH .attr() (DOM) AND .data() (cache).
        $row.find('.qb-unit-cb').attr('data-unitgroup', newGroup).data('unitgroup', newGroup);
        // Keep QB.currentUnits in sync when the unit is already selected.
        var unit = QB.currentUnits.find(function(u) { return u.unitcode === code; });
        if (unit) {
            unit.electivegroup = newGroup;
        }
        // Re-render the compliance dashboard so group-based requirements update.
        renderComplianceDashboard();
    }

    function deleteUnit() {
        var code    = $(this).data('unitcode');
        QB.currentUnits = QB.currentUnits.filter(function(u) { return u.unitcode !== code; });
        var tgaUnit = QB.tgaUnits.find(function(u) { return u.unitcode === code; });
        if (!tgaUnit) {
            // Imported unit: remove the row from the DOM immediately so it disappears
            // before renderUnitBuilder() fires (avoids a brief flicker of the old row).
            $('[data-unitcode="' + code + '"].qb-unit-row').remove();
        }
        // FIX-QB-DESELECT-RENDER (v5.9.273): full re-render after delete.
        // Without it, a TGA unit that was linked shows the compact "✓ BSB226" badge
        // even after deletion, because only the checkbox was unchecked in the DOM.
        // renderUnitBuilder() rebuilds all rows from QB.currentUnits + QB.tgaUnits,
        // so the deleted TGA unit reappears as unlinked/unchecked (correct), and the
        // deleted imported unit is gone (row was already removed above).
        // Section pills are recomputed inside renderUnitBuilder(); no separate
        // updateSectionPill() call is needed.
        renderComplianceDashboard();
        renderUnitBuilder();
    }

    // ===== ADD IMPORTED UNIT =====
    function showAddImportedForm() {
        $('#qb-add-imported-form').slideDown(150);
        $('#qb-add-imported-btn').hide();
        $('#qb-imp-code').focus();
    }
    function cancelImportedUnit() {
        $('#qb-add-imported-form').slideUp(150);
        $('#qb-add-imported-btn').show();
        $('#qb-imp-code,#qb-imp-name,#qb-imp-hours').val('');
    }
    function saveImportedUnit() {
        var code  = $('#qb-imp-code').val().trim().toUpperCase();
        var name  = $('#qb-imp-name').val().trim();
        var hours = parseInt($('#qb-imp-hours').val()) || 0;
        if (!code) { showError('Please enter a unit code.'); return; }
        if (!name) { showError('Please enter a unit name.'); return; }
        if (QB.currentUnits.find(function(u) { return u.unitcode === code; })) {
            showError(code + ' is already in this qualification.'); return;
        }
        QB.currentUnits.push({ id:0, unitcode:code, unitname:name, unittype:'imported', electivegroup:'', nominalhours:hours, courseid:0, creditpoints:0 });
        cancelImportedUnit();
        renderUnitBuilder();
        renderComplianceDashboard();
    }

    // ===== SAVE =====
    function saveQualification() {
        var code       = $('#qb-code-input').val().trim().toUpperCase();
        var name       = $('#qb-name-input').val().trim();
        var streamname = $('#qb-stream-input').val().trim();
        var type   = $('#qb-type-select').val();
        // Save the SEMESTER (QB.semesterid) as the categoryid so that on next page load
        // setup() detects it as a leaf and restores the exact semester correctly.
        // Saving the qual root instead caused setup() to auto-select the highest-ID
        // semester child (often S1 when S2 has fewer courses), silently loading the
        // wrong semester on every subsequent edit.
        var catid  = (QB.semesterid > 0 ? QB.semesterid : QB.categoryid)
                     || parseInt($('#qb-category-select').val()) || 0;
        var status = $('#qb-status-select').val();
        var aqf    = parseInt($('#qb-aqf-select').val()) || 0;
        var hours  = parseInt($('#qb-nominalhours-input').val()) || 0;

        if (!code) { showError('Qualification code is required.'); return; }
        if (!name) { showError('Qualification name is required.'); return; }

        // Compute electiverules JSON from groupRules + points
        var rulesObj = {};
        if (Object.keys(QB.groupRules).length > 0) rulesObj.requiredGroups = QB.groupRules;
        if (QB.pointsSystem) {
            rulesObj.pointsSystem  = true;
            rulesObj.pointsRequired = QB.pointsRequired;
            if (QB.corePointsRequired > 0)    rulesObj.corePointsRequired    = QB.corePointsRequired;
            if (QB.electivePointsRequired > 0) rulesObj.electivePointsRequired = QB.electivePointsRequired;
        }
        var electiverules = Object.keys(rulesObj).length > 0 ? JSON.stringify(rulesObj) : '';

        // Compute nominal hours from unit sum if not manually set
        if (!hours && QB.currentUnits.length > 0) {
            hours = QB.currentUnits.reduce(function(s,u){ return s+(u.nominalhours||0); }, 0);
        }

        var unitsToSave = QB.currentUnits.map(function(u) {
            return {
                unitcode:      u.unitcode,
                unitname:      u.unitname,
                unittype:      u.unittype,
                electivegroup: u.electivegroup || '',
                nominalhours:  u.nominalhours || 0,
                courseid:      u.courseid || 0,
                creditpoints:  u.creditpoints || 0,
                variants:      u.variants || [],
            };
        });

        $('#qb-save-btn').prop('disabled', true).text('Saving...');
        $('#qb-save-status').text('');

        ajax.call([{
            methodname: 'local_rtocompliance_qualbuilder_auto_build',
            args: {
                qualbuilderid:     QB.id,
                producttype:       type,
                qualificationcode: code,
                qualificationname: name,
                streamname:        streamname,
                aqflevel:          aqf,
                categoryid:        catid,
                nominalhours:      hours,
                status:            status,
                totalunits:        QB.pointsSystem ? (QB.totalRequired || 0) : (QB.totalRequired || unitsToSave.length || 1),
                coreunitcount:     QB.coreRequired || 0,
                electivecount:     QB.electiveReq || 0,
                electiverules:     electiverules,
                units:             JSON.stringify(unitsToSave),
            }
        }])[0].done(function(resp) {
            // BUG-MAY1-AUDIT #9 (v4.2.44): on success, briefly flash the button
            // green with "Saved OK" so the user sees positive confirmation, then
            // revert to the neutral primary state.  Previously the button was
            // permanently green and looked "already saved" before clicking.
            var $btn = $('#qb-save-btn');
            $btn.prop('disabled', false).text('Save Qualification');
            if (resp.success) {
                $btn.removeClass('btn-primary').addClass('btn-success').text('Saved \u2713');
                setTimeout(function () {
                    $btn.removeClass('btn-success').addClass('btn-primary').text('Save Qualification');
                }, 2000);
            }
            if (resp.success) {
                if (QB.id === 0 && resp.qualbuilderid) {
                    window.location.href = QB.wwwroot + '/local/rtocompliance/qualbuilder_edit.php?id=' + resp.qualbuilderid;
                } else {
                    QB.id = resp.qualbuilderid;
                    // FIX-RTO-TESTER-FEEDBACK-MAY1 #9: tester said the green
                    // "Saved successfully" tag appeared too quietly and the
                    // button still looked "uncommitted".  Flash a brighter
                    // toast-style banner that draws the eye, fades out after
                    // 2.5s, and reset the button to its default state.
                    $('#qb-save-btn').prop('disabled', false).text('Save Qualification');
                    var $st = $('#qb-save-status');
                    $st.html('<span class="badge badge-success p-2" style="font-size:0.95rem"><i class="fa fa-check"></i> Saved &mdash; ' + (resp.message || 'changes committed') + '</span>')
                       .css({opacity: 1, transition: 'opacity 0.6s'});
                    setTimeout(function() { $st.css('opacity', 0); }, 2500);
                    setTimeout(function() { $st.empty().css('opacity', 1); }, 3200);
                }
            } else {
                showError(resp.message || 'Save failed. Please try again.');
                $('#qb-save-btn').prop('disabled', false).text('Save Qualification');
            }
        }).fail(function(err) {
            $('#qb-save-btn').prop('disabled', false).text('Save Qualification');
            showError('Save failed: ' + (err.message || 'Unknown error'));
        });
    }

    // ===== QUALIFICATION VALIDATION ENGINES =====

    /**
     * Group-based qualification validation engine (ChatGPT doc 1).
     * Validates that the selected units satisfy group min/max constraints.
     *
     * @param {Array}  units  Array of {code, type, group} unit objects.
     * @param {Object} rules  { groups: { A: {min, max}, B: {min, max} }, coreRequired: N, electiveRequired: N }
     * @returns {Object} { coreCount, electiveCount, groupCounts, valid, errors }
     */
    function validateQualification(units, rules) {
        var result = {
            coreCount: 0,
            electiveCount: 0,
            groupCounts: {},
            errors: [],
            valid: true,
        };

        units.forEach(function(unit) {
            if (unit.type === 'CORE' || unit.unittype === 'core') {
                result.coreCount++;
            } else {
                result.electiveCount++;
                var g = unit.group || unit.electivegroup || 'OTHER';
                result.groupCounts[g] = (result.groupCounts[g] || 0) + 1;
            }
        });

        if (rules.coreRequired && result.coreCount < rules.coreRequired) {
            result.errors.push('Core units too low: ' + result.coreCount + ' / ' + rules.coreRequired + ' required.');
            result.valid = false;
        }

        if (rules.electiveRequired && result.electiveCount < rules.electiveRequired) {
            result.errors.push('Elective units too low: ' + result.electiveCount + ' / ' + rules.electiveRequired + ' required.');
            result.valid = false;
        }

        if (rules.groups) {
            Object.keys(rules.groups).sort().forEach(function(grp) {
                var r = rules.groups[grp];
                var cnt = result.groupCounts[grp] || 0;
                var min = r.min || 0;
                var max = r.max || 999;
                if (min > 0 && cnt < min) {
                    result.errors.push('Group ' + grp + ': need at least ' + min + ' unit(s), have ' + cnt + '.');
                    result.valid = false;
                }
                if (max < 999 && cnt > max) {
                    result.errors.push('Group ' + grp + ': maximum is ' + max + ' unit(s), have ' + cnt + '.');
                    result.valid = false;
                }
            });
        }

        return result;
    }

    /**
     * Points-based qualification engine (ChatGPT doc 2).
     * Used for MEM, UEE, and other qualifications that specify credit-point thresholds
     * rather than unit-count thresholds (e.g. MEM20105 requires 64 total pts, 18 core pts, 46 elective pts).
     *
     * @param {Array}  selectedUnits  Array of {code, points, category, prerequisites} objects.
     * @param {Object} rules          { totalRequired, coreRequired, electiveRequired }
     * @returns {Object} { totalPoints, corePoints, electivePoints, valid, errors }
     */
    function evaluateQualification(selectedUnits, rules) {
        var result = {
            totalPoints:    0,
            corePoints:     0,
            electivePoints: 0,
            valid:          true,
            errors:         [],
        };

        selectedUnits.forEach(function(u) {
            var pts = Number(u.creditpoints || 0);
            result.totalPoints += pts;
            if ((u.unittype || '').toUpperCase() === 'CORE') {
                result.corePoints += pts;
            } else {
                result.electivePoints += pts;
            }
        });

        if (rules.totalRequired && result.totalPoints < rules.totalRequired) {
            result.errors.push('Total points too low: ' + result.totalPoints + ' / ' + rules.totalRequired + ' required.');
            result.valid = false;
        }
        if (rules.coreRequired && result.corePoints < rules.coreRequired) {
            result.errors.push('Core points too low: ' + result.corePoints + ' / ' + rules.coreRequired + ' required.');
            result.valid = false;
        }
        if (rules.electiveRequired && result.electivePoints < rules.electiveRequired) {
            result.errors.push('Elective points too low: ' + result.electivePoints + ' / ' + rules.electiveRequired + ' required.');
            result.valid = false;
        }

        // Prerequisite check: warn if a required prerequisite code is missing from the selection
        selectedUnits.forEach(function(u) {
            if (u.prerequisites && Array.isArray(u.prerequisites)) {
                u.prerequisites.forEach(function(preCode) {
                    var found = selectedUnits.find(function(x) {
                        return (x.code || x.unitcode || '').toUpperCase() === preCode.toUpperCase();
                    });
                    if (!found) {
                        result.errors.push((u.unitcode || u.code || '?') + ' requires prerequisite ' + preCode + ' which is not in the selection.');
                        result.valid = false;
                    }
                });
            }
        });

        return result;
    }

    // ===== UTILITIES =====
    function showToast(msg) {
        var $t = $('#qb-toast');
        $t.text(msg).addClass('show');
        setTimeout(function() { $t.removeClass('show'); }, 3500);
    }

    function escH(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;');
    }
    function showLoading(msg) {
        $('#qb-loading-msg').text(msg || 'Loading...');
        $('#qb-loading-overlay').addClass('active');
        $('#qb-tga-spinner').show();
        $('#qb-load-tga-btn,#qb-reload-tga-btn').prop('disabled', true);
    }
    function hideLoading() {
        $('#qb-loading-overlay').removeClass('active');
        $('#qb-tga-spinner').hide();
        $('#qb-load-tga-btn,#qb-reload-tga-btn').prop('disabled', false);
    }
    function showError(msg) {
        notification.alert('Error', msg, 'OK');
    }
    function showTgaError(msg) {
        $('#qb-tga-banner').hide();
        $('#qb-tga-error').text(msg).show();
    }

    setup();
    }

    return { init: init };
});
