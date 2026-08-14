/**
 * CERT-TEMPLATE-BUILDER-PRO (v4.2.43) -- visual certificate template editor.
 *
 * Reads initial state from window.RTOC_TMPL_DATA (set inline by
 * cert_template_edit.php). All field coordinates are stored in mm; the
 * canvas is sized to the page dimensions multiplied by SCALE px/mm so
 * what you see matches what TCPDF renders.
 *
 * Premium UX features:
 *   - Toolbar: zoom 50-200%, grid toggle, sample-data toggle, undo/redo.
 *   - Undo/redo stack (50 step) with deep-clone snapshots on every mutation.
 *   - Keyboard: Arrow = nudge 1mm, Shift+Arrow = 5mm, Delete = remove,
 *     Ctrl+D = duplicate, Esc = deselect.
 *   - Snap-to-other-field alignment guides -- purple lines appear when a
 *     dragged field's edge or centre is within 2mm of another field's.
 *   - Z-order buttons (front / back) on the property panel.
 *   - One-click Fix buttons next to validator errors auto-add the missing
 *     mandatory field at its starter-template recommended coordinates.
 *   - Branding URLs (rto.logo + signatory.signature) painted from the
 *     system-wide FA_BRANDING filearea so users see exactly what TCPDF
 *     will render -- no guesswork.
 *
 * @module local_rtocompliance/cert_template_editor
 */
define('local_rtocompliance/cert_template_editor', [], function () { // FIX-AMD-NAMED-DEFINE (v5.9.279): anonymous define() allowed Moodle combo-loader to overwrite the AMD slot
    'use strict';

    var BASE_SCALE = 3.0;   // px per mm at 100% zoom (A4 landscape = 891x630).
    var SNAP_MM = 0.5;      // sub-millimetre snap.
    var GUIDE_MM = 2.0;     // alignment-guide threshold.
    var MARGIN_MM = 10;     // SMART-SNAP (v6.2.29): editor-only page safety margin (snap + overlay).
    var EQ_TOL_MM = 0.8;    // SMART-SNAP: tolerance for equal-gap / distribution detection.
    var UNDO_LIMIT = 50;

    var canvas, captionEl, propsEmpty, propsForm;
    var design = null;
    var catalogue = null;
    var certtype = null;
    var starterIndex = {};
    var samplePayload = {};
    var fontCss = {}; // FONTS (v6.2.63): font-key -> CSS font-family (incl. Google fonts).
    var brandingLogoUrl = '';
    var brandingSigUrl = '';
    var brandingOrgSealUrl = '';
    var brandingNrtUrl = '';
    var brandingAqfUrl = '';
    var brandingStaUrl = '';
    var rtoIdentity = {};

    // ROR-CAPACITY-HINT (v5.9.340) — dynamic keys that render a multi-row
    // units table (RoR columns + flat units list). When one of these fields
    // is selected the properties panel shows a live overflow estimate.
    var ROR_KEYS = {
        'qualification.units':             true,
        'qualification.units_col_semester': true,
        'qualification.units_col_names':    true,
        'qualification.units_col_results':  true,
    };
    var rorQualData = [];
    var headerColour = '#0f6cbf';   // STYLE-A-TABLE (v5.9.447) — units table header bar fill.

    var pageW = 297, pageH = 210;
    var zoom = 1.0;
    var showGrid = true;
    var showSample = false;

    var selectedId = null;
    var nextId = 1;

    var undoStack = [];
    var redoStack = [];

    // LIVE-VALIDATION (v6.2.16) — config + debounce state for live ASQA re-validation.
    var validateUrl = '';
    var validateSesskey = '';
    var revalidateTimer = null;
    var revalidateSeq = 0;

    function init() {
        var data = window.RTOC_TMPL_DATA || {};
        design = data.design || { page: { orientation: 'L', width_mm: 297, height_mm: 210, bg_color: '#ffffff' }, fields: [] };
        catalogue = data.catalogue || {};
        certtype = data.certtype || '';
        pageW = data.pageW || 297;
        pageH = data.pageH || 210;
        starterIndex = data.starterindex || {};
        samplePayload = data.samplepayload || {};
        brandingLogoUrl = data.brandinglogourl || '';
        brandingSigUrl = data.brandingsigurl || '';
        brandingOrgSealUrl = data.brandingorgsealurl || '';
        brandingNrtUrl = data.brandingnrturl || '';
        brandingAqfUrl = data.brandingaqfurl || '';
        brandingStaUrl = data.brandingstaurl || '';
        rtoIdentity = data.rtoidentity || {};
        rorQualData = data.rorqualdata || [];
        headerColour = data.headercolour || '#0f6cbf';
        // FONTS (v6.2.63): load the Google fonts as webfonts so the canvas previews the real
        // typeface, and keep the key -> CSS family map for rendering field text.
        fontCss = data.fontcss || {};
        loadGoogleFonts(data.googlefonts || []);
        // LIVE-VALIDATION (v6.2.16) — endpoint + sesskey for live ASQA re-validation.
        validateUrl = data.validateurl || '';
        validateSesskey = data.sesskey || '';

        // Compute next field id.
        (design.fields || []).forEach(function (f) {
            var n = parseInt(String(f.id || '').replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n) && n >= nextId) {
                nextId = n + 1;
            }
        });

        canvas = document.getElementById('rtoc-tmpl-canvas');
        captionEl = document.getElementById('rtoc-tmpl-canvas-caption');
        propsEmpty = document.getElementById('rtoc-tmpl-props-empty');
        propsForm = document.getElementById('rtoc-tmpl-props');

        sizeCanvasToOrientation();
        applyGridStyle();
        renderAllFields();
        wirePalette();
        wirePageControls();
        wireBgUpload();
        wirePropsForm();
        wireFormSubmit();
        wireCanvasClickAway();
        wireToolbar();
        wireKeyboard();
        wireValidatorFixButtons();
        wireLivePreview();
        wireWideCanvas();
        wireAlignToolbar();
        // SLIDE-OVER PROPERTIES (v6.2.63): start with the canvas full-width; the settings panel
        // slides in when the author selects a field.
        setPropertiesPanel(false);

        captureUndo(); // baseline
        scheduleLivePreview(400); // initial render of the loaded design
    }

    // ALIGN-ON-PAGE (v6.2.58): wire the "Align on page" buttons in the properties panel.
    function wireAlignToolbar() {
        Array.prototype.slice.call(document.querySelectorAll('.rtoc-align-btn')).forEach(function (b) {
            b.addEventListener('click', function () {
                if (!selectedId) { return; }
                alignSelectedOnPage(b.getAttribute('data-align'));
            });
        });
    }

    // WIDE-CANVAS (v6.2.31): toolbar toggle that collapses the right properties inspector so
    // the design canvas gets the full width; click again to restore the panel.
    function wireWideCanvas() {
        var btn = document.getElementById('rtoc-tmpl-wide');
        var grid = document.querySelector('.rtoc-tmpl-grid');
        if (!btn || !grid) { return; }
        btn.addEventListener('click', function () {
            // Manual toggle pins the panel state and disables the auto slide-over for the session.
            grid.setAttribute('data-manual-wide', '1');
            var collapsed = grid.classList.toggle('rtoc-inspector-collapsed');
            btn.classList.toggle('active', collapsed);
            btn.innerHTML = collapsed ? '&#8596; Show panel' : '&#8596; Wide canvas';
        });
    }

    // LIVE-PREVIEW (v5.9.366) — post the current (unsaved) design to
    // cert_template_preview.php and stream the resulting PDF into the iframe.
    // Debounced so rapid edits (drag, nudge, typing) collapse into one render.
    var livePreviewTimer = null;
    function wireLivePreview() {
        var btn = document.getElementById('rtoc-tmpl-refresh-preview');
        if (btn) {
            btn.addEventListener('click', function () { refreshLivePreview(); });
        }
        // FULLSCREEN-PREVIEW (v6.2.25): blow the live preview up to fill the viewport so the
        // whole issued PDF is readable at once (paired with the single-page PDF view mode).
        var fsBtn = document.getElementById('rtoc-tmpl-preview-fullscreen');
        var lp = document.getElementById('rtoc-tmpl-livepreview');
        if (fsBtn && lp) {
            var setFs = function (on) {
                lp.classList.toggle('rtoc-fullscreen', on);
                fsBtn.textContent = on ? 'Exit full screen' : 'Full screen';
                document.body.style.overflow = on ? 'hidden' : '';
            };
            fsBtn.addEventListener('click', function () {
                setFs(!lp.classList.contains('rtoc-fullscreen'));
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && lp.classList.contains('rtoc-fullscreen')) { setFs(false); }
            });
        }
    }

    function scheduleLivePreview(delay) {
        var form = document.getElementById('rtoc-tmpl-preview-form');
        if (!form) { return; }
        if (livePreviewTimer) { window.clearTimeout(livePreviewTimer); }
        livePreviewTimer = window.setTimeout(refreshLivePreview, delay || 900);
    }

    var lastPreviewBlobUrl = null;
    function refreshLivePreview() {
        var form = document.getElementById('rtoc-tmpl-preview-form');
        var input = document.getElementById('rtoc-tmpl-preview-designjson');
        var frame = document.getElementById('rtoc-tmpl-preview-frame');
        if (!form || !input) { return; }
        input.value = serializeDesignForSave();
        // CLEAN-PDF-PREVIEW (v6.2.57): fetch the PDF ourselves and display it via a blob URL with
        // viewer parameters that hide the browser PDF chrome — the thumbnail side panel ("that
        // left side"), the toolbar and the status bar — and fit it to width, so the preview shows
        // the whole certificate cleanly instead of a two-pane document viewer. Falls back to the
        // classic POST-to-iframe if fetch/blob is unavailable or fails, so the preview can never
        // end up worse than before.
        if (!frame || !window.fetch || !window.URL || !URL.createObjectURL) {
            form.submit();
            return;
        }
        try {
            var fd = new FormData(form);
            fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) { throw new Error('preview http ' + r.status); }
                    var ct = r.headers.get('content-type') || '';
                    if (ct.indexOf('pdf') === -1) { throw new Error('preview not pdf'); }
                    return r.blob();
                })
                .then(function (blob) {
                    if (lastPreviewBlobUrl) { try { URL.revokeObjectURL(lastPreviewBlobUrl); } catch (e) { /* ignore */ } }
                    lastPreviewBlobUrl = URL.createObjectURL(blob);
                    frame.setAttribute('src', lastPreviewBlobUrl
                        + '#toolbar=0&navpanes=0&scrollbar=0&statusbar=0&pagemode=none&view=FitH');
                })
                .catch(function () {
                    // Fallback: the classic POST-to-iframe (shows chrome but always works).
                    try { form.submit(); } catch (e) { /* ignore */ }
                });
        } catch (e) {
            form.submit();
        }
    }

    // -- undo/redo -----------------------------------------------------------
    function captureUndo() {
        undoStack.push(snapshotDesign());
        if (undoStack.length > UNDO_LIMIT + 1) { undoStack.shift(); }
        redoStack = [];
        refreshUndoButtons();
        // Keep the hidden save field current after every mutation (v5.9.450).
        syncMainDesignJson();
        // LIVE-PREVIEW (v5.9.366) — every mutation captures an undo snapshot, so
        // this is the single choke point to debounce a preview refresh from.
        scheduleLivePreview();
        // LIVE-VALIDATION (v6.2.16) — same choke point re-runs the ASQA validator so
        // the panel's errors/recommendations clear the instant their field is added
        // (or reappear when removed), instead of staying stale until save + reload.
        scheduleRevalidate();
    }

    // LIVE-VALIDATION (v6.2.16) — debounce a POST of the current (unsaved) design to
    // cert_template_validate.php and swap the ASQA validator panel with the freshly
    // rendered result. The "Fix" click listener is delegated on the panel element
    // itself (see wireValidatorFixButtons), so replacing only its innerHTML keeps the
    // buttons live. A monotonic sequence guards against out-of-order responses.
    function scheduleRevalidate() {
        if (!validateUrl) { return; }
        if (revalidateTimer) { window.clearTimeout(revalidateTimer); }
        revalidateTimer = window.setTimeout(runRevalidate, 500);
    }

    function runRevalidate() {
        if (!validateUrl) { return; }
        var panel = document.getElementById('rtoc-tmpl-validation');
        if (!panel) { return; }
        var seq = ++revalidateSeq;
        var body = new FormData();
        body.append('sesskey', validateSesskey);
        body.append('certtype', certtype);
        var json;
        try { json = serializeDesignForSave(); } catch (e) { return; }
        body.append('design', json);
        window.fetch(validateUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                // Ignore a stale response if a newer revalidation has since fired.
                if (seq !== revalidateSeq) { return; }
                if (res && res.ok && typeof res.html === 'string') {
                    panel.innerHTML = res.html;
                }
            })
            .catch(function () { /* leave the last-rendered panel in place on error */ });
    }

    function snapshotDesign() {
        return serializeDesignForSave();
    }

    // MALFORMED-PAYLOAD-FIX (v5.9.449): the save handler on the server rejects the
    // design as "malformed design payload" whenever the posted designjson is empty
    // or not a JSON object. That happened silently when JSON.stringify(design) threw
    // (e.g. a non-serialisable value slipped onto a field) inside the submit handler,
    // leaving the hidden field at its initial empty value. This serialiser never
    // throws: it first tries a normal stringify, then falls back to a circular- and
    // DOM-node-safe pass, so the editor always posts a valid object (or the submit is
    // cancelled with a clear message rather than corrupting the template).
    function serializeDesignForSave() {
        try {
            return JSON.stringify(design, function (k, v) {
                return k === '_pendingfile' ? undefined : v;
            });
        } catch (e) {
            var seen = (typeof WeakSet !== 'undefined') ? new WeakSet() : null;
            return JSON.stringify(design, function (k, v) {
                if (k === '_pendingfile' || typeof v === 'function') { return undefined; }
                if (v && typeof v === 'object') {
                    if (v.nodeType) { return undefined; }        // DOM node
                    if (seen) {
                        if (seen.has(v)) { return undefined; }   // circular reference
                        seen.add(v);
                    }
                }
                return v;
            });
        }
    }

    // MALFORMED-PAYLOAD-FIX (v5.9.450): keep the hidden save field continuously in
    // sync with the current design (called on every mutation), so the payload is
    // NEVER empty at submit time — even if the submit-event handler is bypassed
    // (e.g. a programmatic form.submit()) or a stale build is momentarily active.
    function syncMainDesignJson() {
        var el = document.getElementById('rtoc-tmpl-designjson');
        if (!el) { return; }
        try {
            var json = serializeDesignForSave();
            if (json && json.charAt(0) === '{') { el.value = json; }
        } catch (e) { /* leave the previous value in place */ }
    }

    function restoreDesign(snap) {
        var pending = {};
        (design.fields || []).forEach(function (f) { if (f._pendingfile) { pending[f.id] = f._pendingfile; } });
        design = JSON.parse(snap);
        (design.fields || []).forEach(function (f) { if (pending[f.id]) { f._pendingfile = pending[f.id]; } });
        sizeCanvasToOrientation();
        renderAllFields();
        if (selectedId && findField(selectedId)) {
            select(selectedId);
        } else {
            deselect();
        }
        // Repaint background-color/image (page changes restored too).
        canvas.style.backgroundColor = design.page.bg_color || '#ffffff';
        if (design.page.bg_image_url) {
            canvas.style.backgroundImage = 'url(' + JSON.stringify(design.page.bg_image_url) + ')';
            canvas.style.backgroundSize = '100% 100%';
        } else {
            canvas.style.backgroundImage = '';
        }
    }

    function refreshUndoButtons() {
        var u = document.getElementById('rtoc-tmpl-undo');
        var r = document.getElementById('rtoc-tmpl-redo');
        if (u) { u.disabled = undoStack.length <= 1; }
        if (r) { r.disabled = redoStack.length === 0; }
    }

    function undo() {
        if (undoStack.length <= 1) { return; }
        redoStack.push(undoStack.pop());
        restoreDesign(undoStack[undoStack.length - 1]);
        refreshUndoButtons();
    }

    function redo() {
        if (redoStack.length === 0) { return; }
        var snap = redoStack.pop();
        undoStack.push(snap);
        restoreDesign(snap);
        refreshUndoButtons();
    }

    // -- canvas --------------------------------------------------------------
    function getScale() { return BASE_SCALE * zoom; }

    function sizeCanvasToOrientation() {
        var orient = (design.page && design.page.orientation === 'P') ? 'P' : 'L';
        var w = (orient === 'L') ? 297 : 210;
        var h = (orient === 'L') ? 210 : 297;
        design.page.orientation = orient;
        design.page.width_mm = w;
        design.page.height_mm = h;
        pageW = w; pageH = h;
        var s = getScale();
        canvas.style.width = (w * s) + 'px';
        canvas.style.height = (h * s) + 'px';
        if (captionEl) {
            captionEl.textContent = 'A4 ' + (orient === 'L' ? '297 x 210 mm landscape' : '210 x 297 mm portrait')
                + ' -- drag to move, corner handle to resize, arrow keys to nudge';
        }
    }

    function applyGridStyle() {
        if (showGrid) {
            var s = getScale() * 10; // 10mm grid.
            canvas.style.backgroundImage =
                (design.page.bg_image_url ? 'url(' + JSON.stringify(design.page.bg_image_url) + '),' : '') +
                'linear-gradient(to right, rgba(0,0,0,0.06) 1px, transparent 1px),' +
                'linear-gradient(to bottom, rgba(0,0,0,0.06) 1px, transparent 1px)';
            canvas.style.backgroundSize =
                (design.page.bg_image_url ? '100% 100%,' : '') + s + 'px ' + s + 'px,' + s + 'px ' + s + 'px';
            // FIX-GRID-REPEAT (v5.0.3): CSS had background-repeat:no-repeat which
            // prevented the gradient grid lines from tiling. Now we set per-layer repeat:
            // background image (if any) must not repeat; gradient layers must repeat.
            canvas.style.backgroundRepeat =
                (design.page.bg_image_url ? 'no-repeat,' : '') + 'repeat,repeat';
        } else {
            if (design.page.bg_image_url) {
                canvas.style.backgroundImage = 'url(' + JSON.stringify(design.page.bg_image_url) + ')';
                canvas.style.backgroundSize = '100% 100%';
                canvas.style.backgroundRepeat = 'no-repeat';
            } else {
                canvas.style.backgroundImage = '';
                canvas.style.backgroundRepeat = '';
            }
        }
    }

    // SMART-SNAP (v6.2.29): a persistent dashed rectangle showing the page safe margin,
    // so authors always see the zone that elements snap to. Purely decorative; never saved
    // into the design and never read by the renderer.
    function ensureMarginOverlay() {
        if (!canvas) { return; }
        var m = canvas.querySelector('.rtoc-tmpl-margins');
        if (!m) {
            m = document.createElement('div');
            m.className = 'rtoc-tmpl-margins';
            canvas.insertBefore(m, canvas.firstChild);
        }
        var s = getScale();
        m.style.cssText = 'position:absolute;pointer-events:none;z-index:1;'
            + 'left:' + (MARGIN_MM * s) + 'px;top:' + (MARGIN_MM * s) + 'px;'
            + 'width:' + ((pageW - 2 * MARGIN_MM) * s) + 'px;height:' + ((pageH - 2 * MARGIN_MM) * s) + 'px;'
            + 'border:1px dashed rgba(148,163,184,.65);border-radius:2px;';
    }

    function renderAllFields() {
        // Sort fields by z (preserve order; lower index renders first).
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-field, .rtoc-tmpl-guide')).forEach(function (n) {
            n.remove();
        });
        ensureMarginOverlay();
        (design.fields || []).forEach(renderField);
    }

    function renderField(field) {
        var el = document.createElement('div');
        el.className = 'rtoc-tmpl-field';
        el.dataset.id = field.id;
        applyFieldGeometry(el, field);
        applyFieldVisual(el, field);
        // RESIZE-HANDLES-8 (v6.2.30): corners + edges. Lines (zero-height rules) expose only
        // the horizontal handles; every other element gets all eight.
        var dirs = (field.kind === 'line') ? ['w', 'e'] : ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
        dirs.forEach(function (d) {
            var h = document.createElement('div');
            h.className = 'rtoc-tmpl-handle rtoc-tmpl-handle-' + d;
            el.appendChild(h);
            wireResize(h, el, field, d);
        });

        wireDrag(el, field);
        wireSelect(el, field);

        canvas.appendChild(el);
    }

    // NO-MIN-HEIGHT (v6.2.52): the forced minimum text size was removed, so the old 10mm
    // text-field height floor is gone too — authors may size fields freely. A small 3mm
    // floor remains only so a field never collapses to an unselectable sliver on the canvas.
    function minFieldHeightMm(field) {
        return (field && (field.kind === 'text' || field.kind === 'dynamic')) ? 3 : 3;
    }

    function applyFieldGeometry(el, field) {
        var s = getScale();
        // Display the box at its effective (floored) height so the canvas matches the
        // issued PDF, which also floors text-field height at 10mm. Does not mutate the
        // stored h_mm — the stored value is clamped on resize / Height-box edit instead.
        var hmm = field.h_mm;
        var minh = minFieldHeightMm(field);
        if (hmm < minh) { hmm = minh; }
        el.style.left = (field.x_mm * s) + 'px';
        el.style.top = (field.y_mm * s) + 'px';
        el.style.width = (field.w_mm * s) + 'px';
        el.style.height = (hmm * s) + 'px';
    }

    function previewTextFor(field) {
        if (field.kind === 'text') {
            return field.text || '(text)';
        }
        if (field.kind === 'date') {
            return formatPreviewDate(field.dateformat || 'd M Y');
        }
        if (field.kind === 'dynamic') {
            var dk = field.dynamickey;
            // Branding image keys -- paint the actual uploaded image.
            if (dk === 'rto.logo' && brandingLogoUrl) {
                return { __img: brandingLogoUrl };
            }
            if (dk === 'signatory.signature' && brandingSigUrl) {
                return { __img: brandingSigUrl };
            }
            // FIX-ORG-SEAL-PREVIEW (v5.0.3): organisation_seal was not in this
            // block, so dragging it onto the canvas showed a text placeholder even
            // when an image had been uploaded via the Branding panel.
            if (dk === 'organisation_seal' && brandingOrgSealUrl) {
                return { __img: brandingOrgSealUrl };
            }
            // FIX-EDITOR-BRANDING-CHAIN (v5.9.406): paint the NRT / AQF / State
            // Training Authority logos in the canvas from the same asset chain
            // the issued PDF uses, so uploading them via Certificate Settings is
            // reflected in the editor instead of showing a "[NRT logo]" text box.
            if (dk === 'nrt_logo' && brandingNrtUrl) {
                return { __img: brandingNrtUrl };
            }
            if (dk === 'aqf_logo' && brandingAqfUrl) {
                return { __img: brandingAqfUrl };
            }
            if (dk === 'state_training_authority_logo' && brandingStaUrl) {
                return { __img: brandingStaUrl };
            }
            // EDITOR-REAL-RTO-IDENTITY (v5.9.409): the RTO's own identity fields
            // (name, code, authorised signatory, AQF statement) are fixed values,
            // not per-student sample data — always paint the REAL configured value
            // (independent of the sample-data toggle) so the canvas reflects RTO
            // Settings. Falls through to the sample/catalogue placeholder only when
            // the setting is not configured yet.
            if (rtoIdentity[dk] !== undefined && rtoIdentity[dk] !== null && rtoIdentity[dk] !== '') {
                return String(rtoIdentity[dk]);
            }
            // QR-CODE-PREVIEW (v5.0.8): render a real QR code image in the canvas
            // editor for the qrcode field. Uses the free qrserver.com API with the
            // realistic sample verify URL so the designer sees exactly what will print.
            if (dk === 'qrcode') {
                var qVerifyUrl = (showSample && samplePayload['verify.url'])
                    ? String(samplePayload['verify.url'])
                    : 'https://example.com/verify/CERT-2026-PREVIEW';
                return { __img: 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='
                    + encodeURIComponent(qVerifyUrl) };
            }
            // v5.9.442 CANVAS-DATA-UNIFY (root cause of the recurring "wrong preview"
            // bugs): the canvas now renders the SAME coherent record the issued PDF uses
            // (one Jane Citizen / BSB30120 sample), not each field's own catalogue
            // placeholder. A key present in samplePayload wins even when it is
            // intentionally BLANK — e.g. the skill-set statement on a qualification SoA,
            // or an unconfigured optional statement — so unrelated placeholder lines
            // (a First-Aid skill set, a Business qual, an "[insert language]" token)
            // can no longer stack on the canvas. The "Show sample data" toggle therefore
            // no longer changes data fields; the canvas always mirrors the issued preview.
            if (Object.prototype.hasOwnProperty.call(samplePayload, dk)) {
                return String(samplePayload[dk]);
            }
            var meta = catalogue[dk] || {};
            return meta.sample || meta.label || dk;
        }
        return '';
    }

    // STYLE-A-TABLE (v5.9.447) — build the shaded 3-column units table mock shown
    // in the editor canvas: Unit Code | Unit Title | Completion Date, with a
    // coloured header bar (headerColour), white bold headings and zebra rows —
    // matching what cert_template_renderer::render_units_table() draws in the PDF.
    // For ror_table fields the columns are proportioned by col1_w/col2_w/col3_w
    // (reinterpreted as code | title | date). For a dynamic qualification.units
    // field, the columns are derived from the field width (code 26mm, date 30mm).
    function unitsTableMockHtml(field) {
        var hc = escapeAttr(headerColour || '#0f6cbf');
        var thStyle = 'background:' + hc + ';color:#fff;font-weight:bold;';
        // ROR-5COL (v6.2.51): a Record of Results (ror_table with col3mode='result') draws the
        // full ASQA layout — ENROLMENT DATE | UNIT CODE | UNIT TITLE | RESULT | COMPLETION DATE —
        // with result codes (C / NYC / CT / RPL) and a code legend beneath the table. This
        // mirrors cert_template_renderer::render_ror_full_table(). SoA / date tables stay 3-col.
        if (field.kind === 'ror_table' && field.col3mode === 'result') {
            var rows5 = [
                ['12 Feb 2024', 'BSBCMM311', 'Apply critical thinking skills in a team environment', 'C', '15 Mar 2024'],
                ['12 Feb 2024', 'BSBCRT311', 'Apply critical thinking skills', 'C', '02 May 2024'],
                ['12 Feb 2024', 'BSBPEF301', 'Organise personal work priorities', 'RPL', '18 Mar 2024'],
                ['05 Jan 2024', 'BSBTEC301', 'Design and produce business documents', 'CT', '05 Jan 2024'],
                ['12 Feb 2024', 'BSBSUS211', 'Participate in sustainable work practices', 'NYC', '—']
            ];
            var aligns = ['center', 'left', 'left', 'center', 'center'];
            var heads5 = ['ENROLMENT DATE', 'UNIT CODE', 'UNIT TITLE', 'RESULT', 'COMPLETION DATE'];
            var body5 = '';
            rows5.forEach(function (r, i) {
                var zeb = (i % 2 === 1) ? ' style="background:#f6f8fb;"' : '';
                var tds = '';
                r.forEach(function (cell, ci) {
                    var bold = (ci === 3) ? 'font-weight:bold;' : '';
                    tds += '<td style="text-align:' + aligns[ci] + ';' + bold + '">' + escapeHtml(cell) + '</td>';
                });
                body5 += '<tr' + zeb + '>' + tds + '</tr>';
            });
            var head5 = '';
            heads5.forEach(function (h, ci) {
                head5 += '<th style="' + thStyle + 'text-align:' + aligns[ci] + ';font-size:0.82em;">' + escapeHtml(h) + '</th>';
            });
            var key = '<div class="rtoc-tmpl-ror-key" style="font-style:italic;color:#475569;'
                + 'font-size:0.82em;margin-top:3px;background:#f4f7fb;border:1px solid #cbd5e1;'
                + 'padding:2px 4px;">Result key:&nbsp;&nbsp;C = Competent&nbsp;&nbsp;&nbsp;'
                + 'NYC = Not Yet Competent&nbsp;&nbsp;&nbsp;CT = Credit Transfer&nbsp;&nbsp;&nbsp;'
                + 'RPL = Recognition of Prior Learning</div>';
            return '<table class="rtoc-tmpl-ror-mock"><thead><tr>' + head5
                + '</tr></thead><tbody>' + body5 + '</tbody></table>' + key;
        }

        // WIDER-CODE-COL (v5.9.449): code column held at >=34mm so a 12pt unit code
        // stays on one line; the title column takes the rest and wraps long names.
        var fw = parseFloat(field.w_mm) || 180;
        var c1, c2, c3;
        if (field.kind === 'ror_table') {
            c1 = Math.max(parseFloat(field.col1_w) || 34, 34);
            c3 = parseFloat(field.col3_w) || 30;
            c2 = Math.max(30, fw - c1 - c3);
        } else {
            c1 = 34;
            c3 = 30;
            c2 = Math.max(40, fw - c1 - c3);
        }
        var tot = c1 + c2 + c3;
        if (tot <= 0) { tot = 1; }
        var p1 = (c1 / tot * 100).toFixed(2);
        var p2 = (c2 / tot * 100).toFixed(2);
        var p3 = (c3 / tot * 100).toFixed(2);
        var c3head = 'DATE';
        var sampleRows = [
            ['BSBCMM311', 'Apply critical thinking skills in a team environment', '15 Mar 2024'],
            ['BSBCRT311', 'Apply critical thinking skills', '02 May 2024'],
            ['BSBPEF301', 'Organise personal work priorities', '19 Aug 2024'],
            ['BSBSUS211', 'Participate in sustainable work practices', '30 Sep 2024']
        ];
        var body = '';
        sampleRows.forEach(function (r, i) {
            var zeb = (i % 2 === 1) ? ' style="background:#f6f8fb;"' : '';
            body += '<tr' + zeb + '><td>' + escapeHtml(r[0])
                 + '</td><td style="text-align:left;">' + escapeHtml(r[1])
                 + '</td><td style="text-align:center;">' + escapeHtml(r[2]) + '</td></tr>';
        });
        return '<table class="rtoc-tmpl-ror-mock"><colgroup>'
            + '<col style="width:' + p1 + '%"><col style="width:' + p2 + '%"><col style="width:' + p3 + '%">'
            + '</colgroup><thead><tr>'
            + '<th style="' + thStyle + 'font-size:0.82em;">UNIT CODE</th>'
            + '<th style="' + thStyle + 'text-align:left;font-size:0.82em;">UNIT TITLE</th>'
            + '<th style="' + thStyle + 'text-align:center;font-size:0.82em;">' + escapeHtml(c3head) + '</th>'
            + '</tr></thead><tbody>' + body + '</tbody></table>';
    }

    // STUDENT-DETAILS-TABLE (v6.2.51) — canvas mock for the student.detailstable field:
    // one shaded three-column table (STUDENT NAME | USI | QUALIFICATION) matching what
    // cert_template_renderer::render_student_details_table() draws in the PDF. Values come
    // from the coherent sample payload so the canvas mirrors the issued document.
    function studentDetailsTableMockHtml() {
        var hc = escapeAttr(headerColour || '#0f6cbf');
        var thStyle = 'background:' + hc + ';color:#fff;font-weight:bold;font-size:0.82em;';
        var name = (samplePayload['student.fullname'] != null) ? String(samplePayload['student.fullname']) : 'Jane Citizen';
        var usi = (samplePayload['student.usi'] != null && samplePayload['student.usi'] !== '')
            ? String(samplePayload['student.usi']) : 'AB12CD34EF';
        var qcode = (samplePayload['qualification.code'] != null) ? String(samplePayload['qualification.code']) : 'BSB30120';
        var qname = (samplePayload['qualification.name'] != null) ? String(samplePayload['qualification.name']) : 'Certificate III in Business';
        var qual = (qcode + ' ' + qname).replace(/\s+/g, ' ').trim();
        return '<table class="rtoc-tmpl-ror-mock"><colgroup>'
            + '<col style="width:34%"><col style="width:26%"><col style="width:40%">'
            + '</colgroup><thead><tr>'
            + '<th style="' + thStyle + '">STUDENT NAME</th>'
            + '<th style="' + thStyle + '">USI</th>'
            + '<th style="' + thStyle + '">QUALIFICATION</th>'
            + '</tr></thead><tbody><tr>'
            + '<td style="text-align:center;font-weight:bold;">' + escapeHtml(name) + '</td>'
            + '<td style="text-align:center;font-weight:bold;">' + escapeHtml(usi) + '</td>'
            + '<td style="text-align:center;">' + escapeHtml(qual) + '</td>'
            + '</tr></tbody></table>';
    }

    // FONTS (v6.2.63): resolve a stored font key to a CSS font-family for the canvas. Google
    // font keys map to their real family (loaded as a webfont); unknown keys fall back safely.
    function cssFontFamily(key) {
        if (fontCss && fontCss[key]) { return fontCss[key]; }
        return key === 'times' ? 'Times, serif'
            : key === 'courier' ? '"Courier New", monospace'
            : '"Helvetica Neue", Helvetica, Arial, sans-serif';
    }

    // FONTS (v6.2.63): inject a single Google Fonts stylesheet for every catalogue family so the
    // canvas can preview any chosen typeface. No-op if there are none or it's already loaded.
    function loadGoogleFonts(list) {
        if (!list || !list.length) { return; }
        if (document.querySelector('link[data-rtoc-fonts]')) { return; }
        try {
            var fams = list.map(function (f) {
                return 'family=' + encodeURIComponent(f).replace(/%20/g, '+');
            }).join('&');
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://fonts.googleapis.com/css2?' + fams + '&display=swap';
            link.setAttribute('data-rtoc-fonts', '1');
            document.head.appendChild(link);
        } catch (e) { /* preview only — ignore */ }
    }

    function applyFieldVisual(el, field) {
        el.classList.toggle('rtoc-tmpl-field-line', field.kind === 'line');
        el.classList.toggle('rtoc-tmpl-field-box', field.kind === 'box');
        el.classList.toggle('rtoc-tmpl-field-image', field.kind === 'image');
        el.classList.toggle('rtoc-tmpl-field-rortable', field.kind === 'ror_table'
            || (field.kind === 'dynamic' && (field.dynamickey === 'qualification.units'
                || field.dynamickey === 'student.detailstable')));
        var fontStyle = '';
        if ((field.fontstyle || '').indexOf('B') >= 0) { fontStyle += 'font-weight:bold;'; }
        if ((field.fontstyle || '').indexOf('I') >= 0) { fontStyle += 'font-style:italic;'; }
        var fontFamily = cssFontFamily(field.font);
        var alignMap = { L: 'left', C: 'center', R: 'right' };
        var preview;
        var html = '';
        if (field.kind === 'line' || field.kind === 'box') {
            html = '';
        } else if (field.kind === 'dynamic' && field.dynamickey === 'student.detailstable') {
            // STUDENT-DETAILS-TABLE (v6.2.51): the identity table (Name | USI | Qualification).
            html = studentDetailsTableMockHtml();
        } else if (field.kind === 'ror_table'
                   || (field.kind === 'dynamic' && field.dynamickey === 'qualification.units')) {
            // STYLE-A-TABLE (v5.9.447): both the RoR ror_table field and the SoA
            // dynamic qualification.units field render the shaded units table.
            html = unitsTableMockHtml(field);
        } else if (field.kind === 'image') {
            html = field.imageurl
                ? '<img src="' + escapeAttr(field.imageurl) + '" style="max-width:100%;max-height:100%;">'
                : '<div class="rtoc-tmpl-field-imgplaceholder">[image]</div>';
        } else {
            preview = previewTextFor(field);
            if (preview && typeof preview === 'object' && preview.__img) {
                html = '<img src="' + escapeAttr(preview.__img) + '" style="max-width:100%;max-height:100%;object-fit:contain;">';
            } else {
                html = escapeHtml(String(preview || '')).replace(/\n/g, '<br>');
            }
        }
        var color = field.color || '#000000';
        // CERT-EDITOR-FONT-DPI (v5.9.406): convert points to canvas pixels with the
        // SAME factor the width-overflow measurement uses (line ~793): 1pt = 0.3528mm,
        // and the canvas is BASE_SCALE px/mm, so px = pt * 0.3528 * BASE_SCALE * zoom.
        // The old hardcoded 1.33 (96/72 CSS-DPI) ignored BASE_SCALE and rendered every
        // field ~26% larger than the issued PDF, throwing off drag-to-fit alignment.
        // NO-MIN-FONT (v6.2.52): the 12pt canvas clamp was removed — the canvas now shows the
        // author's exact chosen size, matching the issued PDF (which no longer forces a minimum).
        var sizePx = ((parseFloat(field.fontsize) || 12) * 0.3528 * BASE_SCALE * zoom).toFixed(1);
        el.style.cssText += ';color:' + color + ';' + fontStyle
            + 'font-family:' + fontFamily + ';'
            + 'font-size:' + sizePx + 'px;'
            + 'text-align:' + (alignMap[field.align] || 'left') + ';';
        Array.prototype.slice.call(el.querySelectorAll('.rtoc-tmpl-field-inner')).forEach(function (n) { n.remove(); });
        var inner = document.createElement('div');
        inner.className = 'rtoc-tmpl-field-inner';
        inner.innerHTML = html;
        el.insertBefore(inner, el.firstChild);
    }

    // -- snap guides ---------------------------------------------------------
    function clearGuides() {
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-guide, .rtoc-tmpl-badge'))
            .forEach(function (n) { n.remove(); });
    }

    // SMART-SNAP (v6.2.29): a snap/alignment line. colour distinguishes align (purple),
    // margin (grey) and distribution (teal) guides.
    function paintGuide(orient, posMm, colour) {
        var g = document.createElement('div');
        g.className = 'rtoc-tmpl-guide rtoc-tmpl-guide-' + orient;
        var s = getScale();
        var c = colour || '#9333ea';
        if (orient === 'v') {
            g.style.cssText = 'position:absolute;left:' + (posMm * s) + 'px;top:0;width:1px;height:100%;background:' + c + ';pointer-events:none;z-index:9000;';
        } else {
            g.style.cssText = 'position:absolute;top:' + (posMm * s) + 'px;left:0;height:1px;width:100%;background:' + c + ';pointer-events:none;z-index:9000;';
        }
        canvas.appendChild(g);
    }

    // SMART-SNAP (v6.2.29): a small pill showing a distance in mm, centred at (xMm,yMm).
    function paintBadge(xMm, yMm, valueMm, colour) {
        var b = document.createElement('div');
        b.className = 'rtoc-tmpl-badge';
        var s = getScale();
        b.style.cssText = 'position:absolute;left:' + (xMm * s) + 'px;top:' + (yMm * s) + 'px;'
            + 'transform:translate(-50%,-50%);background:' + (colour || '#0fb69f') + ';color:#fff;'
            + 'font:600 10px/1 sans-serif;padding:2px 5px;border-radius:4px;pointer-events:none;'
            + 'z-index:9001;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.25);';
        b.textContent = (Math.round(valueMm * 10) / 10) + ' mm';
        canvas.appendChild(b);
    }

    // SMART-SNAP (v6.2.29): after a position is chosen, surface Canva-style equal-distance
    // hints — equal gap to the two page edges (element centred), and equal gap to the
    // nearest neighbour on each side (evenly distributed). Purely visual (teal badges).
    function showEqualDistanceHints(field, fx, fy) {
        var w = field.w_mm, h = field.h_mm;
        var midY = fy + h / 2, midX = fx + w / 2;
        // Equal distance from the left & right page edges.
        var leftGap = fx, rightGap = pageW - (fx + w);
        if (leftGap > 1 && rightGap > 1 && Math.abs(leftGap - rightGap) <= EQ_TOL_MM) {
            paintBadge(leftGap / 2, midY, leftGap, '#0fb69f');
            paintBadge(fx + w + rightGap / 2, midY, rightGap, '#0fb69f');
        }
        // Equal distance from the top & bottom page edges.
        var topGap = fy, botGap = pageH - (fy + h);
        if (topGap > 1 && botGap > 1 && Math.abs(topGap - botGap) <= EQ_TOL_MM) {
            paintBadge(midX, topGap / 2, topGap, '#0fb69f');
            paintBadge(midX, fy + h + botGap / 2, botGap, '#0fb69f');
        }
        // Even horizontal distribution: equal gap to the nearest neighbour left & right
        // (only counting elements that share vertical extent, i.e. sit in the same row).
        var L = null, R = null;
        (design.fields || []).forEach(function (o) {
            if (o.id === field.id) { return; }
            if (o.y_mm + o.h_mm < fy || o.y_mm > fy + h) { return; }
            if (o.x_mm + o.w_mm <= fx) {
                if (!L || (o.x_mm + o.w_mm) > (L.x_mm + L.w_mm)) { L = o; }
            } else if (o.x_mm >= fx + w) {
                if (!R || o.x_mm < R.x_mm) { R = o; }
            }
        });
        if (L && R) {
            var gL = fx - (L.x_mm + L.w_mm);
            var gR = R.x_mm - (fx + w);
            if (gL > 1 && gR > 1 && Math.abs(gL - gR) <= EQ_TOL_MM) {
                paintBadge((L.x_mm + L.w_mm) + gL / 2, midY, gL, '#0fb69f');
                paintBadge((fx + w) + gR / 2, midY, gR, '#0fb69f');
            }
        }
        // VERTICAL-DISTRIBUTION (v6.2.60): mirror of the above for stacked rows — equal gap to
        // the nearest neighbour above and below (only elements sharing horizontal extent).
        var T = null, B = null;
        (design.fields || []).forEach(function (o) {
            if (o.id === field.id) { return; }
            if (o.x_mm + o.w_mm < fx || o.x_mm > fx + w) { return; }
            if (o.y_mm + o.h_mm <= fy) {
                if (!T || (o.y_mm + o.h_mm) > (T.y_mm + T.h_mm)) { T = o; }
            } else if (o.y_mm >= fy + h) {
                if (!B || o.y_mm < B.y_mm) { B = o; }
            }
        });
        if (T && B) {
            var gT = fy - (T.y_mm + T.h_mm);
            var gB = B.y_mm - (fy + h);
            if (gT > 1 && gB > 1 && Math.abs(gT - gB) <= EQ_TOL_MM) {
                paintBadge(midX, (T.y_mm + T.h_mm) + gT / 2, gT, '#0fb69f');
                paintBadge(midX, (fy + h) + gB / 2, gB, '#0fb69f');
            }
        }
    }

    function snapWithGuides(field, newXMm, newYMm) {
        clearGuides();
        var snappedX = newXMm, snappedY = newYMm;
        var thisEdgesX = [newXMm, newXMm + field.w_mm / 2, newXMm + field.w_mm];
        var thisEdgesY = [newYMm, newYMm + field.h_mm / 2, newYMm + field.h_mm];
        var bestDX = GUIDE_MM + 1, bestDY = GUIDE_MM + 1;
        var bestGuideX = null, bestGuideY = null, bestCX = '#9333ea', bestCY = '#9333ea';

        function considerX(pos, colour) {
            for (var i = 0; i < 3; i++) {
                var dx = Math.abs(thisEdgesX[i] - pos);
                if (dx < bestDX) { bestDX = dx; bestGuideX = pos; bestCX = colour; snappedX = newXMm + (pos - thisEdgesX[i]); }
            }
        }
        function considerY(pos, colour) {
            for (var i = 0; i < 3; i++) {
                var dy = Math.abs(thisEdgesY[i] - pos);
                if (dy < bestDY) { bestDY = dy; bestGuideY = pos; bestCY = colour; snappedY = newYMm + (pos - thisEdgesY[i]); }
            }
        }

        // Other fields' left/centre/right (and top/mid/bottom) edges — alignment (purple).
        (design.fields || []).forEach(function (o) {
            if (o.id === field.id) { return; }
            considerX(o.x_mm, '#9333ea'); considerX(o.x_mm + o.w_mm / 2, '#9333ea'); considerX(o.x_mm + o.w_mm, '#9333ea');
            considerY(o.y_mm, '#9333ea'); considerY(o.y_mm + o.h_mm / 2, '#9333ea'); considerY(o.y_mm + o.h_mm, '#9333ea');
        });
        // Page outside border (purple).
        considerX(0, '#9333ea'); considerX(pageW, '#9333ea');
        considerY(0, '#9333ea'); considerY(pageH, '#9333ea');
        // Page margins / safe zone (grey).
        considerX(MARGIN_MM, '#94a3b8'); considerX(pageW - MARGIN_MM, '#94a3b8');
        considerY(MARGIN_MM, '#94a3b8'); considerY(pageH - MARGIN_MM, '#94a3b8');
        // Page centre (purple).
        considerX(pageW / 2, '#9333ea'); considerY(pageH / 2, '#9333ea');

        // SNAP-EQUAL-SPACING (v6.2.59): snap the element's CENTRE to the midpoint between the
        // nearest neighbours that share its row (X) or column (Y) — dropping it into an evenly
        // spaced slot yields equal gaps on both sides. Teal, matching the distribution badges.
        (function () {
            var w = field.w_mm, h = field.h_mm, fx = newXMm, fy = newYMm;
            var L = null, R = null, T = null, B = null;
            (design.fields || []).forEach(function (o) {
                if (o.id === field.id) { return; }
                var sharesRow = !(o.y_mm + o.h_mm < fy || o.y_mm > fy + h);
                var sharesCol = !(o.x_mm + o.w_mm < fx || o.x_mm > fx + w);
                if (sharesRow) {
                    if (o.x_mm + o.w_mm <= fx) { if (!L || (o.x_mm + o.w_mm) > (L.x_mm + L.w_mm)) { L = o; } }
                    else if (o.x_mm >= fx + w) { if (!R || o.x_mm < R.x_mm) { R = o; } }
                }
                if (sharesCol) {
                    if (o.y_mm + o.h_mm <= fy) { if (!T || (o.y_mm + o.h_mm) > (T.y_mm + T.h_mm)) { T = o; } }
                    else if (o.y_mm >= fy + h) { if (!B || o.y_mm < B.y_mm) { B = o; } }
                }
            });
            if (L && R) { considerX(((L.x_mm + L.w_mm) + R.x_mm) / 2, '#0fb69f'); }
            if (T && B) { considerY(((T.y_mm + T.h_mm) + B.y_mm) / 2, '#0fb69f'); }
        })();

        if (bestDX <= GUIDE_MM && bestGuideX !== null) { paintGuide('v', bestGuideX, bestCX); } else { snappedX = newXMm; }
        if (bestDY <= GUIDE_MM && bestGuideY !== null) { paintGuide('h', bestGuideY, bestCY); } else { snappedY = newYMm; }

        var fx = snap(snappedX), fy = snap(snappedY);
        showEqualDistanceHints(field, fx, fy);
        return { x: fx, y: fy };
    }

    // ALIGN-TARGETS (v6.2.58): snap positions for the moving edges of a field being RESIZED —
    // every other field's left/centre/right (X) or top/middle/bottom (Y), plus the page border,
    // the safe margins and the page centre. Colour: purple = element/page align, grey = margin.
    function alignTargetsX(excludeId) {
        var t = [];
        (design.fields || []).forEach(function (o) {
            if (o.id === excludeId) { return; }
            t.push({ pos: o.x_mm, c: '#9333ea' }, { pos: o.x_mm + o.w_mm / 2, c: '#9333ea' }, { pos: o.x_mm + o.w_mm, c: '#9333ea' });
        });
        t.push({ pos: 0, c: '#9333ea' }, { pos: pageW, c: '#9333ea' },
               { pos: MARGIN_MM, c: '#94a3b8' }, { pos: pageW - MARGIN_MM, c: '#94a3b8' },
               { pos: pageW / 2, c: '#9333ea' });
        return t;
    }
    function alignTargetsY(excludeId) {
        var t = [];
        (design.fields || []).forEach(function (o) {
            if (o.id === excludeId) { return; }
            t.push({ pos: o.y_mm, c: '#9333ea' }, { pos: o.y_mm + o.h_mm / 2, c: '#9333ea' }, { pos: o.y_mm + o.h_mm, c: '#9333ea' });
        });
        t.push({ pos: 0, c: '#9333ea' }, { pos: pageH, c: '#9333ea' },
               { pos: MARGIN_MM, c: '#94a3b8' }, { pos: pageH - MARGIN_MM, c: '#94a3b8' },
               { pos: pageH / 2, c: '#9333ea' });
        return t;
    }
    function nearestTarget(val, targets, tol) {
        var best = null, bd = tol + 1;
        targets.forEach(function (t) { var d = Math.abs(val - t.pos); if (d < bd) { bd = d; best = t; } });
        return best;
    }
    // MATCH-SIZE (v6.2.58): nearest other-field width/height to snap to while resizing.
    function nearestSize(val, dim, tol, excludeId) {
        var best = null, bd = tol + 1;
        (design.fields || []).forEach(function (o) {
            if (o.id === excludeId) { return; }
            var d = Math.abs(val - o[dim]); if (d < bd && o[dim] > 0) { bd = d; best = o[dim]; }
        });
        return best;
    }

    // ALIGN-ON-PAGE (v6.2.58): one-click alignment of the selected field to the page — the
    // fastest way to get "equal distance from the edges" (centre) and clean edges. `how` is one
    // of left|centerh|right|top|centerv|bottom. Uses the safe margin for the edge alignments.
    function alignSelectedOnPage(how) {
        var f = findField(selectedId);
        if (!f) { return; }
        switch (how) {
            case 'left':    f.x_mm = MARGIN_MM; break;
            case 'centerh': f.x_mm = Math.max(0, (pageW - f.w_mm) / 2); break;
            case 'right':   f.x_mm = Math.max(0, pageW - MARGIN_MM - f.w_mm); break;
            case 'top':     f.y_mm = MARGIN_MM; break;
            case 'centerv': f.y_mm = Math.max(0, (pageH - f.h_mm) / 2); break;
            case 'bottom':  f.y_mm = Math.max(0, pageH - MARGIN_MM - f.h_mm); break;
        }
        f.x_mm = Math.round(f.x_mm * 2) / 2;
        f.y_mm = Math.round(f.y_mm * 2) / 2;
        redrawField(f);
        updatePropsIfSelected(f);
        captureUndo();
    }

    // -- drag / resize / select ----------------------------------------------
    function wireDrag(el, field) {
        var startX, startY, startLeft, startTop, dragging = false, moved = false;
        el.addEventListener('mousedown', function (ev) {
            if (ev.target.classList.contains('rtoc-tmpl-handle')) { return; }
            ev.preventDefault();
            dragging = true; moved = false;
            startX = ev.clientX; startY = ev.clientY;
            startLeft = parseFloat(el.style.left); startTop = parseFloat(el.style.top);
            select(field.id);
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
        function onMove(ev) {
            if (!dragging) { return; }
            moved = true;
            var s = getScale();
            var dx = ev.clientX - startX, dy = ev.clientY - startY;
            var newLeftPx = clampPx(startLeft + dx, 0, pageW * s - parseFloat(el.style.width));
            var newTopPx = clampPx(startTop + dy, 0, pageH * s - parseFloat(el.style.height));
            var snapped = snapWithGuides(field, newLeftPx / s, newTopPx / s);
            el.style.left = (snapped.x * s) + 'px';
            el.style.top = (snapped.y * s) + 'px';
            field.x_mm = snapped.x;
            field.y_mm = snapped.y;
            updatePropsIfSelected(field);
        }
        function onUp() {
            dragging = false;
            clearGuides();
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            if (moved) { captureUndo(); }
        }
    }

    // RESIZE-HANDLES-8 (v6.2.30): direction-aware resize. dir is one of nw,n,ne,e,se,s,sw,w.
    // Handles containing 'w'/'n' move the LEFT/TOP edge (and reposition x/y so the opposite
    // edge stays put); 'e'/'s' grow the right/bottom edge. The 12pt / 10mm text floor and the
    // page-bounds clamps are preserved.
    function wireResize(handle, el, field, dir) {
        var startX, startY, startW, startH, startLeft, startTop, resizing = false, moved = false;
        var west = dir.indexOf('w') !== -1, east = dir.indexOf('e') !== -1;
        var north = dir.indexOf('n') !== -1, south = dir.indexOf('s') !== -1;
        handle.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            resizing = true; moved = false;
            startX = ev.clientX; startY = ev.clientY;
            startW = parseFloat(el.style.width); startH = parseFloat(el.style.height);
            startLeft = parseFloat(el.style.left); startTop = parseFloat(el.style.top);
            select(field.id);
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
        function onMove(ev) {
            if (!resizing) { return; }
            moved = true;
            var s = getScale();
            var dx = ev.clientX - startX, dy = ev.clientY - startY;
            // MIN-FIELD-HEIGHT-10 (v6.2.11): don't let a text-bearing field be resized
            // shorter than 10mm — the 12pt minimum font would clip. Non-text exempt.
            var minWpx = 5 * s, minHpx = minFieldHeightMm(field) * s;
            var newLeft = startLeft, newTop = startTop, newW = startW, newH = startH;
            if (east) {
                newW = clampPx(startW + dx, minWpx, pageW * s - startLeft);
            } else if (west) {
                var right = startLeft + startW;                 // keep the right edge fixed
                newW = clampPx(startW - dx, minWpx, right);
                newLeft = right - newW;
            }
            if (south) {
                newH = clampPx(startH + dy, minHpx, pageH * s - startTop);
            } else if (north) {
                var bottom = startTop + startH;                 // keep the bottom edge fixed
                newH = clampPx(startH - dy, minHpx, bottom);
                newTop = bottom - newH;
            }
            // ALIGN-ON-RESIZE (v6.2.58): snap the MOVING edge(s) to alignment targets (other
            // fields' edges, the page border, the safe margins and the page centre) and to a
            // matching size, drawing the same Canva-style guide lines the drag interaction shows.
            clearGuides();
            var xMm = newLeft / s, yMm = newTop / s, wMm = newW / s, hMm = newH / s;
            var TOL = GUIDE_MM;
            if (east) {
                var rt = nearestTarget(xMm + wMm, alignTargetsX(field.id), TOL);
                if (rt) { wMm = rt.pos - xMm; paintGuide('v', rt.pos, rt.c); }
                else { var mw = nearestSize(wMm, 'w_mm', TOL, field.id); if (mw !== null) { wMm = mw; } }
            } else if (west) {
                var rightMm = xMm + wMm;
                var lt = nearestTarget(xMm, alignTargetsX(field.id), TOL);
                if (lt) { xMm = lt.pos; wMm = rightMm - xMm; paintGuide('v', lt.pos, lt.c); }
            }
            if (south) {
                var bt = nearestTarget(yMm + hMm, alignTargetsY(field.id), TOL);
                if (bt) { hMm = bt.pos - yMm; paintGuide('h', bt.pos, bt.c); }
                else { var mh = nearestSize(hMm, 'h_mm', TOL, field.id); if (mh !== null) { hMm = mh; } }
            } else if (north) {
                var bottomMm = yMm + hMm;
                var tt = nearestTarget(yMm, alignTargetsY(field.id), TOL);
                if (tt) { yMm = tt.pos; hMm = bottomMm - yMm; paintGuide('h', tt.pos, tt.c); }
            }
            wMm = Math.max(5, wMm);
            hMm = Math.max(minFieldHeightMm(field), hMm);
            el.style.left = (xMm * s) + 'px';
            el.style.top = (yMm * s) + 'px';
            el.style.width = (wMm * s) + 'px';
            el.style.height = (hMm * s) + 'px';
            field.x_mm = snap(xMm);
            field.y_mm = snap(yMm);
            field.w_mm = snap(wMm);
            field.h_mm = Math.max(minFieldHeightMm(field), snap(hMm));
            updatePropsIfSelected(field);
        }
        function onUp() {
            resizing = false;
            clearGuides();
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            if (moved) { captureUndo(); }
        }
    }

    function wireSelect(el, field) {
        el.addEventListener('click', function (ev) {
            ev.stopPropagation();
            select(field.id);
        });
    }

    function wireCanvasClickAway() {
        canvas.addEventListener('click', function (ev) {
            if (ev.target === canvas) { deselect(); }
        });
    }

    function select(id) {
        selectedId = id;
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-field')).forEach(function (n) {
            n.classList.toggle('rtoc-tmpl-field-selected', n.dataset.id === id);
        });
        var field = findField(id);
        if (!field) { return; }
        // SLIDE-OVER PROPERTIES (v6.2.63): the settings panel is a slide-over — it appears when a
        // field is selected and the canvas takes the full width when nothing is. Reuses the tested
        // "Wide canvas" collapse mechanism, so it's the same reliable CSS.
        setPropertiesPanel(true);
        propsEmpty.style.display = 'none';
        propsForm.style.display = '';
        loadFieldIntoProps(field);
        toggleKindSpecificControls(field);
    }

    // SLIDE-OVER PROPERTIES (v6.2.63): show/hide the right settings pane by toggling the existing
    // inspector-collapsed grid state. Respects a manual "Wide canvas" pin (once the author clicks
    // Wide canvas we stop auto-showing until they toggle it back).
    function setPropertiesPanel(show) {
        var grid = document.querySelector('.rtoc-tmpl-grid');
        if (!grid) { return; }
        if (grid.getAttribute('data-manual-wide') === '1') { return; }
        grid.classList.toggle('rtoc-inspector-collapsed', !show);
    }

    function deselect() {
        selectedId = null;
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-field')).forEach(function (n) {
            n.classList.remove('rtoc-tmpl-field-selected');
        });
        propsEmpty.style.display = '';
        propsForm.style.display = 'none';
        // SLIDE-OVER PROPERTIES (v6.2.63): nothing selected → give the canvas the full width.
        setPropertiesPanel(false);
        // ROR-CAPACITY-HINT: hide when nothing selected.
        var hint = document.getElementById('p-ror-capacity-hint');
        if (hint) { hint.style.display = 'none'; }
    }

    function findField(id) {
        for (var i = 0; i < design.fields.length; i++) {
            if (design.fields[i].id === id) { return design.fields[i]; }
        }
        return null;
    }

    function findFieldIndex(id) {
        for (var i = 0; i < design.fields.length; i++) {
            if (design.fields[i].id === id) { return i; }
        }
        return -1;
    }

    // ROR-CAPACITY-HINT (v5.9.340) — compute and display how many rows the
    // selected field can hold vs how many units the largest qualification has.
    // Formula: rows = h_mm / (fontsize_pt × 0.352 + 1.5mm line gap).
    // Only shown for the four ror_table-type dynamic keys.
    function updateRorCapacityHint(field) {
        var hint = document.getElementById('p-ror-capacity-hint');
        if (!hint) { return; }
        // ROR-TABLE-AUTHOR (v5.9.366) — also show the capacity hint for the
        // first-class ror_table field, not just the legacy per-column dynamic keys.
        var isRor = field && ((field.kind === 'dynamic' && ROR_KEYS[field.dynamickey]) || field.kind === 'ror_table');
        if (!isRor) {
            hint.style.display = 'none';
            return;
        }
        var hMm = parseFloat(field.h_mm) || 0;
        var fs  = parseFloat(field.fontsize) || 12;
        var lineHeightMm = fs * 0.352 + 1.5;
        var capacity = lineHeightMm > 0 ? Math.floor(hMm / lineHeightMm) : 0;

        var qualLine = '';
        var isWarning = false;
        if (rorQualData.length > 0) {
            var top = rorQualData[0]; // highest unit count first
            var display = top.code
                ? escapeHtml(top.code)
                : escapeHtml(top.name || 'Unknown');
            qualLine = ' <strong>' + display + '</strong> has ' + top.unit_count + ' unit' + (top.unit_count !== 1 ? 's' : '') + '.';
            if (capacity < top.unit_count) { isWarning = true; }
        }

        hint.className = isWarning
            ? 'rtoc-ror-capacity-hint alert alert-warning small py-1 px-2 mb-1'
            : 'rtoc-ror-capacity-hint alert alert-info small py-1 px-2 mb-1';
        hint.innerHTML = 'At <strong>' + fs + 'pt</strong>, this field fits approximately '
            + '<strong>' + capacity + '</strong> row' + (capacity !== 1 ? 's' : '') + '.'
            + qualLine;
        hint.style.display = '';
    }

    function loadFieldIntoProps(field) {
        document.getElementById('p-x').value = field.x_mm;
        document.getElementById('p-y').value = field.y_mm;
        document.getElementById('p-w').value = field.w_mm;
        document.getElementById('p-h').value = field.h_mm;
        document.getElementById('p-font').value = field.font || 'helvetica';
        document.getElementById('p-fontsize').value = field.fontsize || 12;
        document.getElementById('p-fontstyle').value = field.fontstyle || '';
        document.getElementById('p-color').value = field.color || '#000000';
        document.getElementById('p-align').value = field.align || 'L';
        document.getElementById('p-text').value = field.text || '';
        document.getElementById('p-dateformat').value = field.dateformat || 'd M Y';
        document.getElementById('p-linewidth').value = field.linewidth || 0.5;
        // ROR-TABLE-AUTHOR (v5.9.366) — column widths (mm) for the ror_table field.
        var c1 = document.getElementById('p-col1w');
        var c2 = document.getElementById('p-col2w');
        var c3 = document.getElementById('p-col3w');
        if (c1) { c1.value = field.col1_w != null ? field.col1_w : 30; }
        if (c2) { c2.value = field.col2_w != null ? field.col2_w : 110; }
        if (c3) { c3.value = field.col3_w != null ? field.col3_w : 36; }
        var c3m = document.getElementById('p-col3mode');
        if (c3m) { c3m.value = (field.col3mode === 'result') ? 'result' : 'date'; }
        // ROR-CAPACITY-HINT: refresh on field selection.
        updateRorCapacityHint(field);
    }

    function toggleKindSpecificControls(field) {
        var kind = field.kind;
        document.getElementById('p-text-wrap').style.display = (kind === 'text') ? '' : 'none';
        document.getElementById('p-dateformat-wrap').style.display = (kind === 'date') ? '' : 'none';
        document.getElementById('p-typo-wrap').style.display = (kind === 'line' || kind === 'box' || kind === 'image') ? 'none' : '';
        document.getElementById('p-image-wrap').style.display = (kind === 'image') ? '' : 'none';
        document.getElementById('p-linewidth-wrap').style.display = (kind === 'line' || kind === 'box') ? '' : 'none';
        // ROR-TABLE-AUTHOR (v5.9.366) — column-width inputs only for ror_table.
        var rorcols = document.getElementById('p-rorcols-wrap');
        if (rorcols) { rorcols.style.display = (kind === 'ror_table') ? '' : 'none'; }
    }

    function wirePropsForm() {
        var bindings = [
            ['p-x', 'x_mm', parseFloat],
            ['p-y', 'y_mm', parseFloat],
            ['p-w', 'w_mm', parseFloat],
            ['p-h', 'h_mm', parseFloat],
            ['p-font', 'font', String],
            ['p-fontsize', 'fontsize', parseFloat],
            ['p-fontstyle', 'fontstyle', String],
            ['p-color', 'color', String],
            ['p-align', 'align', String],
            ['p-text', 'text', String],
            ['p-dateformat', 'dateformat', String],
            ['p-linewidth', 'linewidth', parseFloat],
            // ROR-TABLE-AUTHOR (v5.9.366) — column widths (mm).
            ['p-col3mode', 'col3mode', String],
            ['p-col1w', 'col1_w', parseFloat],
            ['p-col2w', 'col2_w', parseFloat],
            ['p-col3w', 'col3_w', parseFloat],
        ];
        bindings.forEach(function (b) {
            var el = document.getElementById(b[0]);
            if (!el) { return; }
            el.addEventListener('input', function () {
                if (!selectedId) { return; }
                var field = findField(selectedId);
                if (!field) { return; }
                field[b[1]] = b[2](el.value);
                redrawField(field);
                // ROR-CAPACITY-HINT: refresh when h_mm or fontsize changes.
                updateRorCapacityHint(field);
            });
            el.addEventListener('change', function () {
                // NO-MIN-FONT (v6.2.52): the forced 12pt minimum was removed. Only guard against
                // a zero/negative size; any positive author-chosen size is kept as-is.
                if (b[1] === 'fontsize' && selectedId) {
                    var f = findField(selectedId);
                    if (f) {
                        var clamped = Math.max(1, parseFloat(el.value) || 12);
                        if (clamped !== parseFloat(el.value)) { el.value = clamped; }
                        f.fontsize = clamped;
                        redrawField(f);
                    }
                }
                // MIN-FIELD-HEIGHT-10 (v6.2.11): text-bearing fields use a 12pt minimum
                // font, which needs at least ~10mm of box height or the descenders clip.
                // When the author leaves the Height box, snap any value below 10mm up to
                // 10mm for text/dynamic fields (lines, boxes, images are exempt).
                if (b[1] === 'h_mm' && selectedId) {
                    var fh = findField(selectedId);
                    if (fh) {
                        var minh = minFieldHeightMm(fh);
                        var chH = Math.max(minh, parseFloat(el.value) || minh);
                        if (chH !== parseFloat(el.value)) { el.value = chH; }
                        fh.h_mm = chH;
                        redrawField(fh);
                    }
                }
                captureUndo();
            });
        });
        var imgInput = document.getElementById('p-image');
        if (imgInput) {
            imgInput.addEventListener('change', function (ev) {
                if (!selectedId) { return; }
                var field = findField(selectedId);
                if (!field || !ev.target.files || !ev.target.files[0]) { return; }
                var reader = new FileReader();
                reader.onload = function (e) {
                    field.imageurl = e.target.result;
                    field._pendingfile = ev.target.files[0];
                    redrawField(field);
                    captureUndo();
                };
                reader.readAsDataURL(ev.target.files[0]);
            });
        }
        var delBtn = document.getElementById('p-delete');
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                if (!selectedId) { return; }
                deleteSelected();
            });
        }
        // SNAP-TO-CENTRE (v6.2.25): centre the selected element on the page in one click.
        // Exact centre (no grid snap) so it lands dead-centre; H and V are independent.
        function centreSelected(axis) {
            if (!selectedId) { return; }
            var f = findField(selectedId);
            if (!f) { return; }
            if (axis === 'h') {
                f.x_mm = Math.round(Math.max(0, (pageW - (f.w_mm || 0)) / 2) * 10) / 10;
            } else {
                f.y_mm = Math.round(Math.max(0, (pageH - (f.h_mm || 0)) / 2) * 10) / 10;
            }
            redrawField(f);
            loadFieldIntoProps(f);
            captureUndo();
        }
        var centreHBtn = document.getElementById('p-centre-h');
        if (centreHBtn) { centreHBtn.addEventListener('click', function () { centreSelected('h'); }); }
        var centreVBtn = document.getElementById('p-centre-v');
        if (centreVBtn) { centreVBtn.addEventListener('click', function () { centreSelected('v'); }); }
        // Z-order buttons (front / back / forward / backward).
        addZOrderButtons();
    }

    function addZOrderButtons() {
        var host = document.getElementById('p-delete');
        if (!host || host.dataset.zwired) { return; }
        host.dataset.zwired = '1';
        var wrap = document.createElement('div');
        wrap.className = 'btn-group btn-group-sm mt-2';
        wrap.style.cssText = 'display:flex;gap:4px;flex-wrap:wrap;';
        wrap.innerHTML =
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="p-zfront" title="Bring to front">&#x2B06;</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="p-zforward" title="Forward one">&#x2191;</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="p-zbackward" title="Back one">&#x2193;</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="p-zback" title="Send to back">&#x2B07;</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" id="p-duplicate" title="Duplicate (Ctrl+D)">Duplicate</button>';
        host.parentNode.insertBefore(wrap, host);
        wrap.querySelector('#p-zfront').addEventListener('click', function () { reorderSelected('front'); });
        wrap.querySelector('#p-zforward').addEventListener('click', function () { reorderSelected('forward'); });
        wrap.querySelector('#p-zbackward').addEventListener('click', function () { reorderSelected('backward'); });
        wrap.querySelector('#p-zback').addEventListener('click', function () { reorderSelected('back'); });
        wrap.querySelector('#p-duplicate').addEventListener('click', function () { duplicateSelected(); });
    }

    function reorderSelected(dir) {
        if (!selectedId) { return; }
        var i = findFieldIndex(selectedId);
        if (i < 0) { return; }
        var f = design.fields.splice(i, 1)[0];
        if (dir === 'front') { design.fields.push(f); }
        else if (dir === 'back') { design.fields.unshift(f); }
        else if (dir === 'forward') { design.fields.splice(Math.min(i + 1, design.fields.length), 0, f); }
        else { design.fields.splice(Math.max(i - 1, 0), 0, f); }
        renderAllFields();
        select(f.id);
        captureUndo();
    }

    function deleteSelected() {
        if (!selectedId) { return; }
        design.fields = design.fields.filter(function (f) { return f.id !== selectedId; });
        renderAllFields();
        deselect();
        captureUndo();
    }

    function duplicateSelected() {
        if (!selectedId) { return; }
        var src = findField(selectedId);
        if (!src) { return; }
        var clone = JSON.parse(JSON.stringify(src, function (k, v) { return k === '_pendingfile' ? undefined : v; }));
        clone.id = 'f' + (nextId++);
        clone.x_mm = Math.min(pageW - clone.w_mm, clone.x_mm + 5);
        clone.y_mm = Math.min(pageH - clone.h_mm, clone.y_mm + 5);
        // For dynamic fields, picking an in-use dynamickey would render twice
        // but TCPDF handles that -- keep it for now; users typically duplicate
        // text/lines/boxes.
        design.fields.push(clone);
        renderField(clone);
        select(clone.id);
        captureUndo();
    }

    function redrawField(field) {
        var el = canvas.querySelector('.rtoc-tmpl-field[data-id="' + cssEsc(field.id) + '"]');
        if (!el) { return; }
        applyFieldGeometry(el, field);
        el.style.cssText = 'left:' + el.style.left + ';top:' + el.style.top
            + ';width:' + el.style.width + ';height:' + el.style.height + ';';
        applyFieldVisual(el, field);
    }

    // -- palette -------------------------------------------------------------

    // Measure preview text width in mm using the Canvas 2D API.
    function measureTextMm(text, fontPt, isBold, fontKey) {
        try {
            var tmp = document.createElement('canvas');
            var ctx = tmp.getContext('2d');
            var fontPx = fontPt * 0.3528 * BASE_SCALE * zoom;
            var family = cssFontFamily(fontKey);
            ctx.font = (isBold ? 'bold ' : '') + fontPx + 'px ' + family;
            return ctx.measureText(text || '').width / (BASE_SCALE * zoom);
        } catch (e) { return 60; }
    }

    // Y for click-to-add: just below the lowest existing field.
    function nextAutoYMm() {
        var maxBottom = 20;
        (design.fields || []).forEach(function (f) {
            var b = (f.y_mm || 0) + (f.h_mm || 10);
            if (b > maxBottom) { maxBottom = b; }
        });
        return Math.min(maxBottom + 4, pageH - 15);
    }

    // Build smart size + centred overrides for a dynamic field with no starter.
    function smartDynamicOverrides(dk, meta, dropXMm, dropYMm) {
        // QR code must be square; default to bottom-right corner of page.
        if (dk === 'qrcode') {
            var qSz  = 25;
            var qxMm = (dropXMm != null) ? Math.max(0, Math.min(dropXMm - qSz / 2, pageW - qSz)) : pageW - qSz - 5;
            var qyMm = (dropYMm != null) ? Math.max(0, Math.min(dropYMm - qSz / 2, pageH - qSz)) : pageH - qSz - 5;
            return { dynamickey: dk, x_mm: qxMm, y_mm: qyMm, w_mm: qSz, h_mm: qSz, fontsize: 14, fontstyle: '', align: 'C' };
        }
        // STUDENT-DETAILS-TABLE (v6.2.51): a full-width three-column table needs a wide box,
        // not a text-sized one — default to a page-wide, ~22mm-tall band near the top.
        if (dk === 'student.detailstable') {
            var sdw = Math.min(180, pageW - 10);
            var sdx = (dropXMm != null) ? Math.max(0, Math.min(dropXMm - sdw / 2, pageW - sdw))
                    : Math.round((pageW - sdw) / 2 * 2) / 2;
            var sdy = (dropYMm != null) ? Math.max(0, dropYMm - 11) : nextAutoYMm();
            return { dynamickey: dk, x_mm: sdx, y_mm: sdy, w_mm: sdw, h_mm: 22, fontsize: 12, fontstyle: '', align: 'L' };
        }
        var label      = meta.label || dk;
        var isBold     = (dk === 'student.fullname');
        var fontPt     = (dk === 'student.fullname') ? 22 : 14;
        var sampleText = (showSample && samplePayload[dk]) ? String(samplePayload[dk]) : label;
        var wMm = Math.min(Math.max(measureTextMm(sampleText, fontPt, isBold, 'helvetica') + 8, 20), pageW - 4);
        var hMm = Math.round(fontPt * 0.3528 * 1.8 * 2) / 2;
        var xMm = (dropXMm != null)
            ? Math.max(0, Math.min(dropXMm - wMm / 2, pageW - wMm))
            : Math.round((pageW - wMm) / 2 * 2) / 2;
        var yMm = (dropYMm != null)
            ? Math.max(0, Math.min(dropYMm - hMm / 2, pageH - hMm))
            : nextAutoYMm();
        return { dynamickey: dk, x_mm: xMm, y_mm: yMm, w_mm: wMm, h_mm: hMm,
                 fontsize: fontPt, fontstyle: isBold ? 'B' : '', align: 'C' };
    }

    // Core: create and add a field from palette chip data at optional drop mm coords.
    function spawnFieldFromChip(ds, dropXMm, dropYMm) {
        var add = ds.add;
        var field;
        if (add === 'dynamic') {
            var dk = ds.dynamickey;
            if (design.fields.some(function (f) { return f.kind === 'dynamic' && f.dynamickey === dk; })) {
                // Already on canvas -- select it instead.
                var existing = design.fields.filter(function (f) { return f.kind === 'dynamic' && f.dynamickey === dk; })[0];
                if (existing) { select(existing.id); }
                return;
            }
            field = makeFieldFromStarter(dk, dropXMm, dropYMm);
        } else if (add === 'text') {
            var tw   = measureTextMm('New text', 14, false, 'helvetica') + 8;
            var th   = Math.round(14 * 0.3528 * 1.8 * 2) / 2;
            var txMm = (dropXMm != null) ? Math.max(0, Math.min(dropXMm - tw / 2, pageW - tw)) : Math.round((pageW - tw) / 2 * 2) / 2;
            var tyMm = (dropYMm != null) ? Math.max(0, dropYMm - th / 2) : nextAutoYMm();
            field = makeField('text', { text: 'New text', w_mm: tw, h_mm: th, x_mm: txMm, y_mm: tyMm, align: 'C' });
        } else if (add === 'date') {
            var dw   = measureTextMm('01 January 2026', 14, false, 'helvetica') + 8;
            var dh   = Math.round(14 * 0.3528 * 1.8 * 2) / 2;
            var dxMm = (dropXMm != null) ? Math.max(0, Math.min(dropXMm - dw / 2, pageW - dw)) : Math.round((pageW - dw) / 2 * 2) / 2;
            var dyMm = (dropYMm != null) ? Math.max(0, dropYMm - dh / 2) : nextAutoYMm();
            field = makeField('date', { w_mm: dw, h_mm: dh, x_mm: dxMm, y_mm: dyMm, align: 'C' });
        } else if (add === 'image') {
            var ixMm = (dropXMm != null) ? Math.max(0, Math.min(dropXMm - 20, pageW - 40)) : Math.round((pageW - 40) / 2 * 2) / 2;
            var iyMm = (dropYMm != null) ? Math.max(0, dropYMm - 20) : nextAutoYMm();
            field = makeField('image', { w_mm: 40, h_mm: 40, x_mm: ixMm, y_mm: iyMm });
        } else if (add === 'line') {
            var lyMm = (dropYMm != null) ? dropYMm : nextAutoYMm();
            field = makeField('line', { w_mm: pageW - 10, h_mm: 0, x_mm: 5, y_mm: lyMm, fontsize: 1 });
        } else if (add === 'box') {
            var bxMm = (dropXMm != null) ? Math.max(0, Math.min(dropXMm - 30, pageW - 60)) : Math.round((pageW - 60) / 2 * 2) / 2;
            var byMm = (dropYMm != null) ? Math.max(0, dropYMm - 15) : nextAutoYMm();
            field = makeField('box', { w_mm: 60, h_mm: 30, x_mm: bxMm, y_mm: byMm, fontsize: 1 });
        } else if (add === 'ror_table') {
            // ROR-TABLE-AUTHOR (v5.9.366) — the Record-of-Results units table.
            var rw = Math.min(180, pageW - 15);
            var rxMm = (dropXMm != null) ? Math.max(0, Math.min(dropXMm - rw / 2, pageW - rw)) : Math.round((pageW - rw) / 2 * 2) / 2;
            var ryMm = (dropYMm != null) ? Math.max(0, dropYMm - 57) : nextAutoYMm();
            field = makeField('ror_table', {
                w_mm: rw, h_mm: 115, x_mm: rxMm, y_mm: ryMm,
                col1_w: 30, col2_w: 110, col3_w: 36,
                fontsize: 10, align: 'L'
            });
        }
        if (field) {
            design.fields.push(field);
            renderField(field);
            select(field.id);
            captureUndo();
        }
    }

    function wirePalette() {
        // Selector covers both the new form-builder rows AND any legacy chips.
        var chips = Array.prototype.slice.call(
            document.querySelectorAll('.rtoc-field-row, .rtoc-palette-chip')
        );

        // Click-to-add.
        chips.forEach(function (btn) {
            btn.addEventListener('click', function () {
                spawnFieldFromChip(btn.dataset, null, null);
            });
        });

        // Drag from field row onto canvas.
        chips.forEach(function (btn) {
            btn.addEventListener('dragstart', function (ev) {
                ev.dataTransfer.effectAllowed = 'copy';
                ev.dataTransfer.setData('text/plain', JSON.stringify({
                    add:        btn.dataset.add,
                    dynamickey: btn.dataset.dynamickey || null,
                    label:      btn.dataset.label || null,
                }));
            });
        });

        // Canvas: accept drops from field rows.
        canvas.addEventListener('dragover', function (ev) {
            ev.preventDefault();
            ev.dataTransfer.dropEffect = 'copy';
            canvas.classList.add('rtoc-tmpl-canvas-dragover');
        });
        canvas.addEventListener('dragleave', function (ev) {
            if (!canvas.contains(ev.relatedTarget)) {
                canvas.classList.remove('rtoc-tmpl-canvas-dragover');
            }
        });
        canvas.addEventListener('drop', function (ev) {
            ev.preventDefault();
            canvas.classList.remove('rtoc-tmpl-canvas-dragover');
            var raw;
            try { raw = JSON.parse(ev.dataTransfer.getData('text/plain')); } catch(e) { return; }
            if (!raw || !raw.add) { return; }
            var rect = canvas.getBoundingClientRect();
            var s    = getScale();
            var xMm  = (ev.clientX - rect.left) / s;
            var yMm  = (ev.clientY - rect.top)  / s;
            spawnFieldFromChip(raw, xMm, yMm);
        });

        // Search filter -- instant client-side filtering of field rows.
        var searchInput = document.getElementById('rtoc-palette-search');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var q = searchInput.value.toLowerCase().trim();
                var rows    = Array.prototype.slice.call(document.querySelectorAll('#rtoc-field-list .rtoc-field-row'));
                var headers = Array.prototype.slice.call(document.querySelectorAll('#rtoc-field-list .rtoc-field-group-header'));

                rows.forEach(function (row) {
                    var txt = (row.dataset.searchtext || '').toLowerCase();
                    row.style.display = (!q || txt.indexOf(q) !== -1) ? '' : 'none';
                });

                // Hide a section header when every row beneath it is hidden.
                headers.forEach(function (hdr) {
                    var sib = hdr.nextElementSibling;
                    var anyVisible = false;
                    while (sib && !sib.classList.contains('rtoc-field-group-header')) {
                        if (sib.style.display !== 'none') { anyVisible = true; break; }
                        sib = sib.nextElementSibling;
                    }
                    hdr.style.display = anyVisible ? '' : 'none';
                });
            });
        }
    }

    function makeFieldFromStarter(dk, dropXMm, dropYMm) {
        var meta    = catalogue[dk] || {};
        var starter = starterIndex[dk];
        var overrides;
        if (starter) {
            overrides = {
                dynamickey: dk,
                x_mm: starter.x_mm, y_mm: starter.y_mm,
                w_mm: starter.w_mm, h_mm: starter.h_mm,
                font: starter.font || 'helvetica',
                fontsize: starter.fontsize || 14,
                fontstyle: starter.fontstyle || '',
                color: starter.color || '#000000',
                align: starter.align || 'L',
            };
        } else {
            overrides = smartDynamicOverrides(dk, meta, dropXMm, dropYMm);
        }
        var f = makeField('dynamic', overrides);
        f.label = meta.label || dk;
        return f;
    }

    function makeField(kind, overrides) {
        var f = {
            id: 'f' + (nextId++),
            kind: kind,
            dynamickey: null,
            x_mm: 30, y_mm: 30,
            w_mm: 100, h_mm: 12,
            font: 'helvetica', fontsize: 14, fontstyle: '',
            color: '#000000', align: 'L',
            text: '', dateformat: 'd M Y',
            imageurl: '', imageitemid: 0,
            linewidth: 0.5,
        };
        Object.keys(overrides || {}).forEach(function (k) { f[k] = overrides[k]; });
        return f;
    }

    // -- page controls + bg upload -------------------------------------------
    function wirePageControls() {
        var orientEl = document.getElementById('rtoc-tmpl-orient');
        if (orientEl) {
            orientEl.addEventListener('change', function (ev) {
                design.page.orientation = ev.target.value === 'P' ? 'P' : 'L';
                sizeCanvasToOrientation();
                design.fields.forEach(function (f) {
                    if (f.x_mm + f.w_mm > pageW) { f.x_mm = Math.max(0, pageW - f.w_mm); }
                    if (f.y_mm + f.h_mm > pageH) { f.y_mm = Math.max(0, pageH - f.h_mm); }
                });
                renderAllFields();
                applyGridStyle();
                captureUndo();
            });
        }
        var colorEl = document.getElementById('rtoc-tmpl-bgcolor');
        if (colorEl) {
            colorEl.addEventListener('input', function (ev) {
                design.page.bg_color = ev.target.value;
                canvas.style.backgroundColor = ev.target.value;
            });
            colorEl.addEventListener('change', captureUndo);
        }
    }

    function wireBgUpload() {
        var input = document.getElementById('rtoc-tmpl-bgupload');
        if (input) {
            input.addEventListener('change', function (ev) {
                if (!ev.target.files || !ev.target.files[0]) { return; }
                var reader = new FileReader();
                reader.onload = function (e) {
                    design.page.bg_image_url = e.target.result;
                    applyGridStyle();
                    attachFileToFormForUpload(ev.target.files[0]);
                    captureUndo();
                };
                reader.readAsDataURL(ev.target.files[0]);
            });
        }
        var clear = document.getElementById('rtoc-tmpl-bgclear');
        if (clear) {
            clear.addEventListener('click', function () {
                design.page.bg_image_url = '';
                design.page.bg_itemid = 0;
                applyGridStyle();
                captureUndo();
            });
        }
    }

    function attachFileToFormForUpload(file) {
        var form = document.getElementById('rtoc-tmpl-form');
        var prior = form.querySelector('input[type=file][name=bgfile][data-injected]');
        if (prior) { prior.remove(); }
        var dt = new DataTransfer();
        dt.items.add(file);
        var input = document.createElement('input');
        input.type = 'file';
        input.name = 'bgfile';
        input.style.display = 'none';
        input.dataset.injected = '1';
        input.files = dt.files;
        form.appendChild(input);
        form.enctype = 'multipart/form-data';
    }

    function wireFormSubmit() {
        var form = document.getElementById('rtoc-tmpl-form');
        form.addEventListener('submit', function (ev) {
            var el = document.getElementById('rtoc-tmpl-designjson');
            var json = '';
            try { json = serializeDesignForSave(); } catch (e) { json = ''; }
            // Never post an empty/invalid payload — that is what the server reports as
            // "malformed design payload". Cancel instead, so the saved copy is untouched.
            if (!json || json === 'null' || json.charAt(0) !== '{') {
                ev.preventDefault();
                window.alert('The certificate layout could not be prepared for saving. '
                    + 'Please reload the page and try again — your last saved version is intact.');
                return;
            }
            el.value = json;
        });
    }

    function updatePropsIfSelected(field) {
        if (selectedId !== field.id) { return; }
        document.getElementById('p-x').value = field.x_mm;
        document.getElementById('p-y').value = field.y_mm;
        document.getElementById('p-w').value = field.w_mm;
        document.getElementById('p-h').value = field.h_mm;
        // ROR-CAPACITY-HINT: refresh when field is resized/moved via drag.
        updateRorCapacityHint(field);
    }

    // -- toolbar -------------------------------------------------------------
    function wireToolbar() {
        var z = document.getElementById('rtoc-tmpl-zoom');
        if (z) {
            z.addEventListener('change', function () {
                zoom = parseFloat(z.value) / 100;
                sizeCanvasToOrientation();
                applyGridStyle();
                renderAllFields();
                if (selectedId) { select(selectedId); }
            });
        }
        var g = document.getElementById('rtoc-tmpl-grid');
        if (g) {
            g.addEventListener('change', function () {
                showGrid = !!g.checked;
                applyGridStyle();
            });
        }
        var s = document.getElementById('rtoc-tmpl-sample');
        if (s) {
            s.addEventListener('change', function () {
                showSample = !!s.checked;
                renderAllFields();
                if (selectedId) { select(selectedId); }
            });
        }
        var u = document.getElementById('rtoc-tmpl-undo');
        if (u) { u.addEventListener('click', undo); }
        var r = document.getElementById('rtoc-tmpl-redo');
        if (r) { r.addEventListener('click', redo); }
    }

    // -- keyboard ------------------------------------------------------------
    function wireKeyboard() {
        document.addEventListener('keydown', function (ev) {
            // Ignore if focused in form input/textarea/select.
            var t = ev.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) {
                // Allow Esc to deselect even from inputs.
                if (ev.key === 'Escape') { deselect(); }
                return;
            }
            if (ev.key === 'Escape') { ev.preventDefault(); deselect(); return; }
            // Undo/redo.
            if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'z' || ev.key === 'Z')) {
                ev.preventDefault();
                if (ev.shiftKey) { redo(); } else { undo(); }
                return;
            }
            if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'y' || ev.key === 'Y')) {
                ev.preventDefault(); redo(); return;
            }
            if (!selectedId) { return; }
            var field = findField(selectedId);
            if (!field) { return; }
            // Ctrl+D duplicate.
            if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'd' || ev.key === 'D')) {
                ev.preventDefault(); duplicateSelected(); return;
            }
            if (ev.key === 'Delete' || ev.key === 'Backspace') {
                ev.preventDefault(); deleteSelected(); return;
            }
            var step = ev.shiftKey ? 5 : 1;
            var moved = false;
            if (ev.key === 'ArrowLeft')  { field.x_mm = Math.max(0, field.x_mm - step); moved = true; }
            if (ev.key === 'ArrowRight') { field.x_mm = Math.min(pageW - field.w_mm, field.x_mm + step); moved = true; }
            if (ev.key === 'ArrowUp')    { field.y_mm = Math.max(0, field.y_mm - step); moved = true; }
            if (ev.key === 'ArrowDown')  { field.y_mm = Math.min(pageH - field.h_mm, field.y_mm + step); moved = true; }
            if (moved) {
                ev.preventDefault();
                redrawField(field);
                updatePropsIfSelected(field);
                captureUndo();
            }
        });
    }

    // -- validator Fix buttons -----------------------------------------------
    function wireValidatorFixButtons() {
        var panel = document.getElementById('rtoc-tmpl-validation');
        if (!panel) { return; }
        panel.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-fix-key]');
            if (!btn) { return; }
            ev.preventDefault();
            var dk = btn.dataset.fixKey;
            if (design.fields.some(function (f) { return f.kind === 'dynamic' && f.dynamickey === dk; })) {
                return;
            }
            var field = makeFieldFromStarter(dk);
            design.fields.push(field);
            renderField(field);
            select(field.id);
            captureUndo();
        });
    }

    // -- helpers -------------------------------------------------------------
    function clampPx(v, min, max) { return Math.max(min, Math.min(max, v)); }
    function snap(v) { return Math.round(v / SNAP_MM) * SNAP_MM; }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }
    function cssEsc(s) {
        return String(s).replace(/[^a-zA-Z0-9_-]/g, function (c) { return '\\' + c; });
    }
    function formatPreviewDate(fmt) {
        var now = new Date();
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        return fmt
            .replace(/d/g, pad(now.getDate()))
            .replace(/j/g, '' + now.getDate())
            .replace(/m/g, pad(now.getMonth() + 1))
            .replace(/F/g, months[now.getMonth()])
            .replace(/M/g, months[now.getMonth()].substring(0, 3))
            .replace(/Y/g, '' + now.getFullYear())
            .replace(/l/g, days[now.getDay()])
            .replace(/D/g, days[now.getDay()].substring(0, 3));
    }

    return { init: init };
});
