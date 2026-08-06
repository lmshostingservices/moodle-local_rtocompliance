/**
 * RTO Compliance  -  AI Content Suggestion Engine
 * Attaches a sparkle AI button to every registered textarea in the plugin.
 * Calls /api/rto/ai-suggest on lms-labs.com (5 credits per call).
 * Version: 4.0.0
 */
(function () {
    'use strict';

    /* -- Field registry ---------------------------------------------------- */
    // FIX-AI-REGISTRY: Only textarea fields that are rendered as actual <textarea name="...">
    // elements in Moodle forms are included here. Hidden inputs (<input type="hidden">)
    // and non-textarea inputs (text, select) are excluded — attachButtons() uses the
    // selector 'form textarea[name]' so buttons will never attach to hidden/non-textarea
    // fields regardless of whether they appear in this registry.
    // Removed (were hidden inputs in TAS form, not real textareas):
    //   assessmentmethods (hidden JSON input), assessmentmapping (text/link field),
    //   validationschedule (hidden, managed in Validation Register),
    //   thirdparty, learnersupport, accessibility, marketinginfo, feesinformation,
    //   transitionplan, riskmanagement, complaintsprocess, continuousimprovement
    //   (all hidden — moved to dedicated dashboard registers).
    var FIELD_REGISTRY = {
        // TAS — visible textareas only
        scopedetails:           { label: 'RTO Scope Details',                    count: 3 },
        targetcohort:           { label: 'Target Learner Cohort',                count: 3 },
        entryrequirements:      { label: 'Entry Requirements',                   count: 3 },
        llnrequirements:        { label: 'LLN Requirements',                     count: 1 },
        prerequisites:          { label: 'Prerequisites',                        count: 1 },
        jobroles:               { label: 'Job Roles & Outcomes',                 count: 3 },
        deliveryschedule:       { label: 'Delivery Schedule',                    count: 1 },
        learningbreakdown:      { label: 'Volume of Learning Breakdown',         count: 1 },
        volumejustification:    { label: 'Volume of Learning Justification',     count: 1 },
        assessmentnotes:        { label: 'Assessment Plan Notes',                 count: 1 },
        trainerrequirements:    { label: 'Trainer/Assessor Requirements',        count: 1 },
        supervisionarrangements:{ label: 'Supervision Arrangements',             count: 1 },
        learningresources:      { label: 'Learning Resources & Materials',       count: 1 },
        facilities:             { label: 'Facilities & Equipment',               count: 1 },
        technology:             { label: 'Technology Requirements',              count: 1 },
        placementdetails:       { label: 'Placement Details & Supervision',      count: 1 },
        // Trainer edit — visible textareas
        vocationalqualifications:    { label: 'Vocational Qualifications',       count: 1 },
        vocationalcompetencynotes:   { label: 'Vocational Competency Notes',     count: 1 },
        scopenotes:                  { label: 'Approved Delivery Scope',         count: 1 },
        // Risk edit — visible textareas
        riskdescription:  { label: 'Risk Description',     count: 1 },
        mitigationplan:   { label: 'Mitigation Plan',      count: 1 },
        // RPL edit — visible textareas
        evidencedescription:  { label: 'Evidence Description',  count: 1 },
        decisionreason:       { label: 'RPL Decision Reason',   count: 1 },
        // Generic notes textareas (multiple pages)
        notes:          { label: 'Notes',          count: 1 },
        revisionnotes:  { label: 'Revision Notes', count: 1 },
    };

    var CREDIT_COST = 5;

    /* -- State -------------------------------------------------------------- */
    var apiKey   = '';
    var apiBase  = 'https://lms-labs.com';
    var activeOverlay   = null;
    var activeModal     = null;
    var activeTextarea  = null;
    var activeField     = null;

    /* -- Bootstrap --------------------------------------------------------- */
    function init() {
        var cfgEl = document.getElementById('rtoc-ai-config');
        if (!cfgEl) return;
        apiKey  = cfgEl.getAttribute('data-api-key')  || '';
        apiBase = (cfgEl.getAttribute('data-api-base') || 'https://lms-labs.com').replace(/\/$/, '');
        if (!apiKey) { return; }
        attachButtons();
    }

    /* -- Attach sparkle buttons -------------------------------------------- */
    function attachButtons() {
        var textareas = document.querySelectorAll('form textarea[name], textarea[name][data-rtoc-ai]');
        Array.prototype.forEach.call(textareas, function (ta) {
            var name = ta.getAttribute('name');
            if (!name || !FIELD_REGISTRY[name]) return;
            var parent = ta.parentElement;
            if (!parent) return;
            // Wrap
            var wrapper = document.createElement('div');
            wrapper.className = 'rtoc-ai-ta-wrapper';
            parent.insertBefore(wrapper, ta);
            wrapper.appendChild(ta);
            // Button
            var btn = createAIButton(name);
            wrapper.appendChild(btn);
            btn.addEventListener('click', function () { openModal(name, ta); });
        });
    }

    function createAIButton(name) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rtoc-ai-btn';
        btn.setAttribute('data-field', name);
        btn.setAttribute('title', 'Generate AI suggestion  -  ' + CREDIT_COST + ' credits');
        btn.setAttribute('aria-label', 'Generate AI suggestion for ' + (FIELD_REGISTRY[name] ? FIELD_REGISTRY[name].label : name));
        btn.innerHTML =
            '<svg class="rtoc-ai-star-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>' +
            '</svg>' +
            '<span class="rtoc-ai-btn-label">AI Suggest</span>';
        return btn;
    }

    /* -- Context collection ------------------------------------------------ */
    function collectContext(excludeField) {
        var ctx = {};
        var textareas = document.querySelectorAll('form textarea[name]');
        Array.prototype.forEach.call(textareas, function (ta) {
            var n = ta.getAttribute('name');
            if (n && n !== excludeField && ta.value.trim()) {
                ctx[n] = ta.value.trim().substring(0, 500);
            }
        });
        return ctx;
    }

    function getQualInfo() {
        var codeEl = document.getElementById('id_qualificationcode')
                  || document.getElementById('id_oldproductcode');
        var nameEl = document.getElementById('id_qualificationname')
                  || document.getElementById('id_oldproductname');
        var modeEl = document.getElementById('id_deliverymode')
                  || document.getElementById('id_transitiontype');

        var qualCode     = codeEl ? codeEl.value.trim() : '';
        var qualName     = nameEl ? nameEl.value.trim() : '';
        var deliveryMode = modeEl ? modeEl.value.trim() : '';

        // Transition form: enrich qualName with new product details when present
        var newCodeEl = document.getElementById('id_newproductcode');
        var newNameEl = document.getElementById('id_newproductname');
        if (newCodeEl && newCodeEl.value.trim()) {
            qualName += (qualName ? ' \u2192 ' : '') + newCodeEl.value.trim();
            if (newNameEl && newNameEl.value.trim()) {
                qualName += ' (' + newNameEl.value.trim() + ')';
            }
        }

        return {
            qualCode:     qualCode,
            qualName:     qualName,
            deliveryMode: deliveryMode,
        };
    }

    /* -- Modal ------------------------------------------------------------- */
    function openModal(field, ta) {
        activeField    = field;
        activeTextarea = ta;

        var reg   = FIELD_REGISTRY[field] || { label: field, count: 1 };
        var isMulti = reg.count > 1;
        var label   = reg.label;

        // Overlay
        activeOverlay = document.createElement('div');
        activeOverlay.className = 'rtoc-ai-overlay';
        activeOverlay.addEventListener('click', function (e) {
            if (e.target === activeOverlay) closeModal();
        });

        // Modal
        activeModal = document.createElement('div');
        activeModal.className = 'rtoc-ai-modal';
        activeModal.setAttribute('role', 'dialog');
        activeModal.setAttribute('aria-label', 'AI Suggestion for ' + label);
        activeModal.innerHTML = buildModalShell(label, isMulti);
        activeOverlay.appendChild(activeModal);
        document.body.appendChild(activeOverlay);

        // Animate in
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                activeOverlay.classList.add('rtoc-ai-overlay--in');
                activeModal.classList.add('rtoc-ai-modal--in');
            });
        });

        // Wire close
        activeModal.querySelector('.rtoc-ai-close').addEventListener('click', closeModal);

        // Wire regenerate
        var regenBtn = activeModal.querySelector('.rtoc-ai-regen-btn');
        if (regenBtn) {
            regenBtn.addEventListener('click', function () {
                var kw = activeModal.querySelector('.rtoc-ai-keyword-input').value.trim();
                generateSuggestions(kw);
            });
        }

        // Wire keyword enter key
        var kwInput = activeModal.querySelector('.rtoc-ai-keyword-input');
        if (kwInput) {
            kwInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    regenBtn && regenBtn.click();
                }
            });
        }

        // Auto-generate on open
        generateSuggestions('');
    }

    function closeModal() {
        if (!activeOverlay) return;
        activeOverlay.classList.remove('rtoc-ai-overlay--in');
        if (activeModal) activeModal.classList.remove('rtoc-ai-modal--in');
        var o = activeOverlay;
        setTimeout(function () {
            if (o && o.parentNode) o.parentNode.removeChild(o);
        }, 300);
        activeOverlay  = null;
        activeModal    = null;
        activeTextarea = null;
        activeField    = null;
    }

    function buildModalShell(label, isMulti) {
        var countDesc = isMulti ? '3 options will be generated' : '1 tailored response';
        return '' +
            '<div class="rtoc-ai-modal-header">' +
                '<div class="rtoc-ai-modal-title">' +
                    '<svg class="rtoc-ai-star-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>' +
                    '<span>' + escHtml(label) + '</span>' +
                '</div>' +
                '<button type="button" class="rtoc-ai-close" aria-label="Close">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
                '</button>' +
            '</div>' +
            '<div class="rtoc-ai-credit-notice">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
                '<span class="rtoc-ai-credit-text">' + CREDIT_COST + ' AI credits per generation &bull; ' + countDesc + '</span>' +
            '</div>' +
            '<div class="rtoc-ai-keyword-bar">' +
                '<label class="rtoc-ai-keyword-label">Refine with a keyword or context <em>(optional)</em></label>' +
                '<div class="rtoc-ai-keyword-row">' +
                    '<input type="text" class="rtoc-ai-keyword-input" placeholder="e.g. high school students, hospitality, remote delivery, regional RTO\u2026" autocomplete="off" />' +
                    '<button type="button" class="rtoc-ai-regen-btn">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>' +
                        'Regenerate' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="rtoc-ai-suggestions-area">' +
                '<div class="rtoc-ai-loading">' +
                    '<div class="rtoc-ai-spinner"></div>' +
                    '<span>Generating AI suggestions using ASQA practice guide context\u2026</span>' +
                '</div>' +
            '</div>';
    }

    /* -- API call ---------------------------------------------------------- */
    function generateSuggestions(keyword) {
        if (!activeModal || !activeField) return;
        var reg = FIELD_REGISTRY[activeField] || { count: 1 };
        var count = reg.count;

        var suggArea = activeModal.querySelector('.rtoc-ai-suggestions-area');
        suggArea.innerHTML =
            '<div class="rtoc-ai-loading">' +
            '<div class="rtoc-ai-spinner"></div>' +
            '<span>Generating ' + (count > 1 ? count + ' options' : 'suggestion') + ' using ASQA practice guide context\u2026</span>' +
            '</div>';

        var qual = getQualInfo();
        var ctx  = collectContext(activeField);

        fetch(apiBase + '/api/rto/ai-suggest', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': apiKey
            },
            body: JSON.stringify({
                apiKey:       apiKey,
                field:        activeField,
                qualCode:     qual.qualCode,
                qualName:     qual.qualName,
                deliveryMode: qual.deliveryMode,
                context:      ctx,
                keyword:      keyword,
                count:        count
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!activeModal) return;
            if (!data.success) {
                showError(data.error || 'Failed to generate suggestions. Please try again.');
                return;
            }
            // Update credit display
            var creditText = activeModal.querySelector('.rtoc-ai-credit-text');
            if (creditText && data.creditsRemaining !== undefined && data.creditsRemaining !== -1) {
                creditText.textContent = CREDIT_COST + ' credits used \u2022 ' + data.creditsRemaining + ' credits remaining';
            } else if (creditText && data.creditsRemaining === -1) {
                creditText.textContent = CREDIT_COST + ' credits used \u2022 Unlimited credits';
            }
            renderSuggestions(data.suggestions || [], count > 1);
        })
        .catch(function () {
            showError('Connection error. Please check your internet connection and try again.');
        });
    }

    /* -- Render suggestions ------------------------------------------------ */
    function renderSuggestions(suggestions, isMulti) {
        if (!activeModal) return;
        var suggArea = activeModal.querySelector('.rtoc-ai-suggestions-area');

        if (!suggestions || suggestions.length === 0) {
            showError('No suggestions returned. Please try regenerating.');
            return;
        }

        if (isMulti) {
            var html = '<p class="rtoc-ai-options-hint">Select the option that best fits your RTO \u2014 or refine above and regenerate:</p>';
            suggestions.forEach(function (text, idx) {
                html +=
                    '<div class="rtoc-ai-option-card">' +
                        '<div class="rtoc-ai-option-header">' +
                            '<span class="rtoc-ai-option-badge">Option ' + (idx + 1) + '</span>' +
                            '<button type="button" class="rtoc-ai-use-btn" data-idx="' + idx + '">' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" width="13" height="13"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                                'Use this' +
                            '</button>' +
                        '</div>' +
                        '<div class="rtoc-ai-option-text">' + markdownToHtml(text) + '</div>' +
                    '</div>';
            });
            suggArea.innerHTML = html;

            // Wire use buttons
            var useBtns = suggArea.querySelectorAll('.rtoc-ai-use-btn');
            Array.prototype.forEach.call(useBtns, function (btn) {
                btn.addEventListener('click', function () {
                    var idx = parseInt(btn.getAttribute('data-idx'), 10);
                    applyToTextarea(suggestions[idx]);
                });
            });

        } else {
            var text = suggestions[0] || '';
            suggArea.innerHTML =
                '<div class="rtoc-ai-single-card">' +
                    '<div class="rtoc-ai-option-text">' + markdownToHtml(text) + '</div>' +
                '</div>' +
                '<div class="rtoc-ai-single-actions">' +
                    '<button type="button" class="rtoc-ai-use-btn rtoc-ai-use-primary">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" width="14" height="14"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                        'Use this suggestion' +
                    '</button>' +
                    '<button type="button" class="rtoc-ai-discard-btn">Discard</button>' +
                '</div>';

            suggArea.querySelector('.rtoc-ai-use-primary').addEventListener('click', function () {
                applyToTextarea(text);
            });
            suggArea.querySelector('.rtoc-ai-discard-btn').addEventListener('click', closeModal);
        }
    }

    function showError(msg) {
        if (!activeModal) return;
        var suggArea = activeModal.querySelector('.rtoc-ai-suggestions-area');
        suggArea.innerHTML =
            '<div class="rtoc-ai-error">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>' +
            '<span>' + escHtml(msg) + '</span>' +
            '</div>';
    }

    /* -- Markdown helpers -------------------------------------------------- */
    /**
     * Strip markdown symbols so plain-text textareas show clean content.
     * **bold** → bold   *italic* → italic   ## Heading → Heading
     * - item  → item   > quote  → quote
     */
    function stripMarkdown(text) {
        return text
            .replace(/^#{1,6}\s+/gm, '')
            .replace(/\*\*\*([^*]+)\*\*\*/g, '$1')
            .replace(/\*\*([^*]+)\*\*/g, '$1')
            .replace(/\*([^*\n]+)\*/g, '$1')
            .replace(/__([^_]+)__/g, '$1')
            .replace(/_([^_\n]+)_/g, '$1')
            .replace(/^>\s+/gm, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    /**
     * Render markdown as HTML for the suggestion preview modal.
     * Escapes HTML first so injected content is safe, then applies formatting.
     */
    function markdownToHtml(text) {
        var s = escHtml(text);
        // Headings → bold line
        s = s.replace(/^#{1,6}\s+(.+)$/gm, '<strong>$1</strong>');
        // Bold before italic (order matters)
        s = s.replace(/\*\*\*([^*]+)\*\*\*/g, '<strong><em>$1</em></strong>');
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
        s = s.replace(/__([^_]+)__/g, '<strong>$1</strong>');
        s = s.replace(/_([^_\n]+)_/g, '<em>$1</em>');
        // Blockquotes
        s = s.replace(/^&gt;\s+(.*)$/gm, '<em>$1</em>');
        // Line breaks
        s = s.replace(/\n/g, '<br>');
        return s;
    }

    /* -- Apply to textarea ------------------------------------------------- */
    function applyToTextarea(text) {
        var ta = activeTextarea;
        closeModal();
        if (!ta) return;
        ta.value = stripMarkdown(text);
        ta.dispatchEvent(new Event('input',  { bubbles: true }));
        ta.dispatchEvent(new Event('change', { bubbles: true }));
        // Green flash
        var prev = ta.style.backgroundColor;
        ta.style.transition = 'background-color 0.4s ease';
        ta.style.backgroundColor = '#ecfdf5';
        setTimeout(function () {
            ta.style.backgroundColor = prev;
            setTimeout(function () { ta.style.transition = ''; }, 400);
        }, 900);
        ta.focus();
        ta.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /* -- Helpers ----------------------------------------------------------- */
    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    /* -- Init -------------------------------------------------------------- */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
