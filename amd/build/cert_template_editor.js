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
define('local_rtocompliance/cert_template_editor', [], function() { // FIX-AMD-NAMED-DEFINE (v5.9.279): anonymous define() allowed Moodle combo-loader to overwrite the AMD slot
    'use strict';

    var BASE_SCALE = 3.0;   // px per mm at 100% zoom (A4 landscape = 891x630).
    var SNAP_MM = 0.5;      // sub-millimetre snap.
    var GUIDE_MM = 2.0;     // alignment-guide threshold.
    var UNDO_LIMIT = 50;

    var canvas, captionEl, propsEmpty, propsForm;
    var design = null;
    var catalogue = null;
    var certtype = null;
    var starterIndex = {};
    var samplePayload = {};
    var brandingLogoUrl = '';
    var brandingSigUrl = '';
    var brandingOrgSealUrl = '';

    var pageW = 297, pageH = 210;
    var zoom = 1.0;
    var showGrid = true;
    var showSample = false;

    var selectedId = null;
    var nextId = 1;

    var undoStack = [];
    var redoStack = [];

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

        // Compute next field id.
        (design.fields || []).forEach(function(f) {
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

        captureUndo(); // baseline
    }

    // -- undo/redo -----------------------------------------------------------
    function captureUndo() {
        undoStack.push(snapshotDesign());
        if (undoStack.length > UNDO_LIMIT + 1) { undoStack.shift(); }
        redoStack = [];
        refreshUndoButtons();
    }

    function snapshotDesign() {
        return JSON.stringify(design, function(k, v) { return k === '_pendingfile' ? undefined : v; });
    }

    function restoreDesign(snap) {
        var pending = {};
        (design.fields || []).forEach(function(f) { if (f._pendingfile) { pending[f.id] = f._pendingfile; } });
        design = JSON.parse(snap);
        (design.fields || []).forEach(function(f) { if (pending[f.id]) { f._pendingfile = pending[f.id]; } });
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

    function renderAllFields() {
        // Sort fields by z (preserve order; lower index renders first).
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-field, .rtoc-tmpl-guide')).forEach(function(n) {
            n.remove();
        });
        (design.fields || []).forEach(renderField);
    }

    function renderField(field) {
        var el = document.createElement('div');
        el.className = 'rtoc-tmpl-field';
        el.dataset.id = field.id;
        applyFieldGeometry(el, field);
        applyFieldVisual(el, field);
        var handle = document.createElement('div');
        handle.className = 'rtoc-tmpl-field-handle';
        el.appendChild(handle);

        wireDrag(el, field);
        wireResize(handle, el, field);
        wireSelect(el, field);

        canvas.appendChild(el);
    }

    function applyFieldGeometry(el, field) {
        var s = getScale();
        el.style.left = (field.x_mm * s) + 'px';
        el.style.top = (field.y_mm * s) + 'px';
        el.style.width = (field.w_mm * s) + 'px';
        el.style.height = (field.h_mm * s) + 'px';
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
            // Sample-data toggle: pick from the realistic sample payload.
            if (showSample && samplePayload[dk] !== undefined && samplePayload[dk] !== null && samplePayload[dk] !== '') {
                return String(samplePayload[dk]);
            }
            var meta = catalogue[dk] || {};
            return meta.sample || meta.label || dk;
        }
        return '';
    }

    function applyFieldVisual(el, field) {
        el.classList.toggle('rtoc-tmpl-field-line', field.kind === 'line');
        el.classList.toggle('rtoc-tmpl-field-box', field.kind === 'box');
        el.classList.toggle('rtoc-tmpl-field-image', field.kind === 'image');
        var fontStyle = '';
        if ((field.fontstyle || '').indexOf('B') >= 0) { fontStyle += 'font-weight:bold;'; }
        if ((field.fontstyle || '').indexOf('I') >= 0) { fontStyle += 'font-style:italic;'; }
        var fontFamily = field.font === 'times' ? 'Times, serif' :
                         field.font === 'courier' ? '"Courier New", monospace' :
                         '"Helvetica Neue", Helvetica, Arial, sans-serif';
        var alignMap = { L: 'left', C: 'center', R: 'right' };
        var preview;
        var html = '';
        if (field.kind === 'line' || field.kind === 'box') {
            html = '';
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
        var sizePx = (parseFloat(field.fontsize || 12) * 1.33 * zoom).toFixed(1);
        el.style.cssText += ';color:' + color + ';' + fontStyle
            + 'font-family:' + fontFamily + ';'
            + 'font-size:' + sizePx + 'px;'
            + 'text-align:' + (alignMap[field.align] || 'left') + ';';
        Array.prototype.slice.call(el.querySelectorAll('.rtoc-tmpl-field-inner')).forEach(function(n) { n.remove(); });
        var inner = document.createElement('div');
        inner.className = 'rtoc-tmpl-field-inner';
        inner.innerHTML = html;
        el.insertBefore(inner, el.firstChild);
    }

    // -- snap guides ---------------------------------------------------------
    function clearGuides() {
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-guide')).forEach(function(n) { n.remove(); });
    }

    function paintGuide(orient, posMm) {
        var g = document.createElement('div');
        g.className = 'rtoc-tmpl-guide rtoc-tmpl-guide-' + orient;
        var s = getScale();
        if (orient === 'v') {
            g.style.cssText = 'position:absolute;left:' + (posMm * s) + 'px;top:0;width:1px;height:100%;background:#9333ea;pointer-events:none;z-index:9000;';
        } else {
            g.style.cssText = 'position:absolute;top:' + (posMm * s) + 'px;left:0;height:1px;width:100%;background:#9333ea;pointer-events:none;z-index:9000;';
        }
        canvas.appendChild(g);
    }

    function snapWithGuides(field, newXMm, newYMm) {
        clearGuides();
        var snappedX = newXMm, snappedY = newYMm;
        // Candidate edges of THIS field at the proposed position.
        var thisEdgesX = [newXMm, newXMm + field.w_mm / 2, newXMm + field.w_mm];
        var thisEdgesY = [newYMm, newYMm + field.h_mm / 2, newYMm + field.h_mm];
        var bestDX = GUIDE_MM + 1, bestDY = GUIDE_MM + 1;
        var bestGuideX = null, bestGuideY = null;
        (design.fields || []).forEach(function(o) {
            if (o.id === field.id) { return; }
            var oEdgesX = [o.x_mm, o.x_mm + o.w_mm / 2, o.x_mm + o.w_mm];
            var oEdgesY = [o.y_mm, o.y_mm + o.h_mm / 2, o.y_mm + o.h_mm];
            for (var i = 0; i < 3; i++) {
                for (var j = 0; j < 3; j++) {
                    var dx = Math.abs(thisEdgesX[i] - oEdgesX[j]);
                    if (dx < bestDX) { bestDX = dx; bestGuideX = oEdgesX[j]; snappedX = newXMm + (oEdgesX[j] - thisEdgesX[i]); }
                    var dy = Math.abs(thisEdgesY[i] - oEdgesY[j]);
                    if (dy < bestDY) { bestDY = dy; bestGuideY = oEdgesY[j]; snappedY = newYMm + (oEdgesY[j] - thisEdgesY[i]); }
                }
            }
        });
        // Page centre lines too.
        var centreX = pageW / 2;
        var centreY = pageH / 2;
        for (var k = 0; k < 3; k++) {
            var dxc = Math.abs(thisEdgesX[k] - centreX);
            if (dxc < bestDX) { bestDX = dxc; bestGuideX = centreX; snappedX = newXMm + (centreX - thisEdgesX[k]); }
            var dyc = Math.abs(thisEdgesY[k] - centreY);
            if (dyc < bestDY) { bestDY = dyc; bestGuideY = centreY; snappedY = newYMm + (centreY - thisEdgesY[k]); }
        }
        if (bestDX <= GUIDE_MM && bestGuideX !== null) { paintGuide('v', bestGuideX); } else { snappedX = newXMm; }
        if (bestDY <= GUIDE_MM && bestGuideY !== null) { paintGuide('h', bestGuideY); } else { snappedY = newYMm; }
        return { x: snap(snappedX), y: snap(snappedY) };
    }

    // -- drag / resize / select ----------------------------------------------
    function wireDrag(el, field) {
        var startX, startY, startLeft, startTop, dragging = false, moved = false;
        el.addEventListener('mousedown', function(ev) {
            if (ev.target.classList.contains('rtoc-tmpl-field-handle')) { return; }
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

    function wireResize(handle, el, field) {
        var startX, startY, startW, startH, resizing = false, moved = false;
        handle.addEventListener('mousedown', function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
            resizing = true; moved = false;
            startX = ev.clientX; startY = ev.clientY;
            startW = parseFloat(el.style.width); startH = parseFloat(el.style.height);
            select(field.id);
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
        function onMove(ev) {
            if (!resizing) { return; }
            moved = true;
            var s = getScale();
            var dx = ev.clientX - startX, dy = ev.clientY - startY;
            var newW = clampPx(startW + dx, 5, pageW * s - parseFloat(el.style.left));
            var newH = clampPx(startH + dy, 5, pageH * s - parseFloat(el.style.top));
            el.style.width = newW + 'px';
            el.style.height = newH + 'px';
            field.w_mm = snap(newW / s);
            field.h_mm = snap(newH / s);
            updatePropsIfSelected(field);
        }
        function onUp() {
            resizing = false;
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            if (moved) { captureUndo(); }
        }
    }

    function wireSelect(el, field) {
        el.addEventListener('click', function(ev) {
            ev.stopPropagation();
            select(field.id);
        });
    }

    function wireCanvasClickAway() {
        canvas.addEventListener('click', function(ev) {
            if (ev.target === canvas) { deselect(); }
        });
    }

    function select(id) {
        selectedId = id;
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-field')).forEach(function(n) {
            n.classList.toggle('rtoc-tmpl-field-selected', n.dataset.id === id);
        });
        var field = findField(id);
        if (!field) { return; }
        propsEmpty.style.display = 'none';
        propsForm.style.display = '';
        loadFieldIntoProps(field);
        toggleKindSpecificControls(field);
    }

    function deselect() {
        selectedId = null;
        Array.prototype.slice.call(canvas.querySelectorAll('.rtoc-tmpl-field')).forEach(function(n) {
            n.classList.remove('rtoc-tmpl-field-selected');
        });
        propsEmpty.style.display = '';
        propsForm.style.display = 'none';
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
    }

    function toggleKindSpecificControls(field) {
        var kind = field.kind;
        document.getElementById('p-text-wrap').style.display = (kind === 'text') ? '' : 'none';
        document.getElementById('p-dateformat-wrap').style.display = (kind === 'date') ? '' : 'none';
        document.getElementById('p-typo-wrap').style.display = (kind === 'line' || kind === 'box' || kind === 'image') ? 'none' : '';
        document.getElementById('p-image-wrap').style.display = (kind === 'image') ? '' : 'none';
        document.getElementById('p-linewidth-wrap').style.display = (kind === 'line' || kind === 'box') ? '' : 'none';
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
        ];
        bindings.forEach(function(b) {
            var el = document.getElementById(b[0]);
            if (!el) { return; }
            el.addEventListener('input', function() {
                if (!selectedId) { return; }
                var field = findField(selectedId);
                if (!field) { return; }
                field[b[1]] = b[2](el.value);
                redrawField(field);
            });
            el.addEventListener('change', function() { captureUndo(); });
        });
        var imgInput = document.getElementById('p-image');
        if (imgInput) {
            imgInput.addEventListener('change', function(ev) {
                if (!selectedId) { return; }
                var field = findField(selectedId);
                if (!field || !ev.target.files || !ev.target.files[0]) { return; }
                var reader = new FileReader();
                reader.onload = function(e) {
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
            delBtn.addEventListener('click', function() {
                if (!selectedId) { return; }
                deleteSelected();
            });
        }
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
        wrap.querySelector('#p-zfront').addEventListener('click', function() { reorderSelected('front'); });
        wrap.querySelector('#p-zforward').addEventListener('click', function() { reorderSelected('forward'); });
        wrap.querySelector('#p-zbackward').addEventListener('click', function() { reorderSelected('backward'); });
        wrap.querySelector('#p-zback').addEventListener('click', function() { reorderSelected('back'); });
        wrap.querySelector('#p-duplicate').addEventListener('click', function() { duplicateSelected(); });
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
        design.fields = design.fields.filter(function(f) { return f.id !== selectedId; });
        renderAllFields();
        deselect();
        captureUndo();
    }

    function duplicateSelected() {
        if (!selectedId) { return; }
        var src = findField(selectedId);
        if (!src) { return; }
        var clone = JSON.parse(JSON.stringify(src, function(k, v) { return k === '_pendingfile' ? undefined : v; }));
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
            var family = fontKey === 'times'   ? 'Times, serif' :
                         fontKey === 'courier' ? '"Courier New", monospace' :
                         '"Helvetica Neue", Helvetica, Arial, sans-serif';
            ctx.font = (isBold ? 'bold ' : '') + fontPx + 'px ' + family;
            return ctx.measureText(text || '').width / (BASE_SCALE * zoom);
        } catch (e) { return 60; }
    }

    // Y for click-to-add: just below the lowest existing field.
    function nextAutoYMm() {
        var maxBottom = 20;
        (design.fields || []).forEach(function(f) {
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
            if (design.fields.some(function(f) { return f.kind === 'dynamic' && f.dynamickey === dk; })) {
                // Already on canvas -- select it instead.
                var existing = design.fields.filter(function(f) { return f.kind === 'dynamic' && f.dynamickey === dk; })[0];
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
        chips.forEach(function(btn) {
            btn.addEventListener('click', function() {
                spawnFieldFromChip(btn.dataset, null, null);
            });
        });

        // Drag from field row onto canvas.
        chips.forEach(function(btn) {
            btn.addEventListener('dragstart', function(ev) {
                ev.dataTransfer.effectAllowed = 'copy';
                ev.dataTransfer.setData('text/plain', JSON.stringify({
                    add:        btn.dataset.add,
                    dynamickey: btn.dataset.dynamickey || null,
                    label:      btn.dataset.label || null,
                }));
            });
        });

        // Canvas: accept drops from field rows.
        canvas.addEventListener('dragover', function(ev) {
            ev.preventDefault();
            ev.dataTransfer.dropEffect = 'copy';
            canvas.classList.add('rtoc-tmpl-canvas-dragover');
        });
        canvas.addEventListener('dragleave', function(ev) {
            if (!canvas.contains(ev.relatedTarget)) {
                canvas.classList.remove('rtoc-tmpl-canvas-dragover');
            }
        });
        canvas.addEventListener('drop', function(ev) {
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
            searchInput.addEventListener('input', function() {
                var q = searchInput.value.toLowerCase().trim();
                var rows    = Array.prototype.slice.call(document.querySelectorAll('#rtoc-field-list .rtoc-field-row'));
                var headers = Array.prototype.slice.call(document.querySelectorAll('#rtoc-field-list .rtoc-field-group-header'));

                rows.forEach(function(row) {
                    var txt = (row.dataset.searchtext || '').toLowerCase();
                    row.style.display = (!q || txt.indexOf(q) !== -1) ? '' : 'none';
                });

                // Hide a section header when every row beneath it is hidden.
                headers.forEach(function(hdr) {
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
        Object.keys(overrides || {}).forEach(function(k) { f[k] = overrides[k]; });
        return f;
    }

    // -- page controls + bg upload -------------------------------------------
    function wirePageControls() {
        var orientEl = document.getElementById('rtoc-tmpl-orient');
        if (orientEl) {
            orientEl.addEventListener('change', function(ev) {
                design.page.orientation = ev.target.value === 'P' ? 'P' : 'L';
                sizeCanvasToOrientation();
                design.fields.forEach(function(f) {
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
            colorEl.addEventListener('input', function(ev) {
                design.page.bg_color = ev.target.value;
                canvas.style.backgroundColor = ev.target.value;
            });
            colorEl.addEventListener('change', captureUndo);
        }
    }

    function wireBgUpload() {
        var input = document.getElementById('rtoc-tmpl-bgupload');
        if (input) {
            input.addEventListener('change', function(ev) {
                if (!ev.target.files || !ev.target.files[0]) { return; }
                var reader = new FileReader();
                reader.onload = function(e) {
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
            clear.addEventListener('click', function() {
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
        form.addEventListener('submit', function() {
            var clean = JSON.parse(JSON.stringify(design, function(k, v) {
                return k === '_pendingfile' ? undefined : v;
            }));
            document.getElementById('rtoc-tmpl-designjson').value = JSON.stringify(clean);
        });
    }

    function updatePropsIfSelected(field) {
        if (selectedId !== field.id) { return; }
        document.getElementById('p-x').value = field.x_mm;
        document.getElementById('p-y').value = field.y_mm;
        document.getElementById('p-w').value = field.w_mm;
        document.getElementById('p-h').value = field.h_mm;
    }

    // -- toolbar -------------------------------------------------------------
    function wireToolbar() {
        var z = document.getElementById('rtoc-tmpl-zoom');
        if (z) {
            z.addEventListener('change', function() {
                zoom = parseFloat(z.value) / 100;
                sizeCanvasToOrientation();
                applyGridStyle();
                renderAllFields();
                if (selectedId) { select(selectedId); }
            });
        }
        var g = document.getElementById('rtoc-tmpl-grid');
        if (g) {
            g.addEventListener('change', function() {
                showGrid = !!g.checked;
                applyGridStyle();
            });
        }
        var s = document.getElementById('rtoc-tmpl-sample');
        if (s) {
            s.addEventListener('change', function() {
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
        document.addEventListener('keydown', function(ev) {
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
        panel.addEventListener('click', function(ev) {
            var btn = ev.target.closest('[data-fix-key]');
            if (!btn) { return; }
            ev.preventDefault();
            var dk = btn.dataset.fixKey;
            if (design.fields.some(function(f) { return f.kind === 'dynamic' && f.dynamickey === dk; })) {
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
        return String(s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s).replace(/"/g, '&quot;'); }
    function cssEsc(s) {
        return String(s).replace(/[^a-zA-Z0-9_-]/g, function(c) { return '\\' + c; });
    }
    function formatPreviewDate(fmt) {
        var now = new Date();
        var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
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
