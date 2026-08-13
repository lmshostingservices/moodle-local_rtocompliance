define(['core/str'], function(Str) {
    'use strict';

    var debounceTimer = null;
    // FIX-XHR-RACE (v5.9.277): track the in-flight XHR so we can abort it
    // before starting a new one.  Without this, blur fires immediately AND
    // the debounce timer fires 800 ms later — two concurrent requests race,
    // and the slower (debounce) response silently overwrites whatever the
    // faster (blur) response already populated.
    var currentXhr = null;
    var apiUrl = '';

    // Loaded lang strings (populated in init via core/str).
    var S = {
        lookupBtn:      'Lookup NCVER Hours',
        lookupTitle:    'Automatically fetch nominal hours from NCVER for this code',
        lookingUpBtn:   'Looking up...',
        enterCodeFirst: 'Enter a code first (e.g. BSB50420 or BSBWHS411).',
        sourceNcver:    'NCVER',
        sourceRto:      'RTO Compliance',
        lookupFailed:   'Lookup failed. Enter hours manually.',
        lookupTimeout:  'Lookup timed out. Enter hours manually.',
    };

    var STRING_KEYS = [
        {key: 'nominalhours_lookup_btn',      component: 'local_rtocompliance'},
        {key: 'nominalhours_lookup_title',     component: 'local_rtocompliance'},
        {key: 'nominalhours_looking_up_btn',   component: 'local_rtocompliance'},
        {key: 'nominalhours_enter_code_first', component: 'local_rtocompliance'},
        {key: 'nominalhours_source_ncver',     component: 'local_rtocompliance'},
        {key: 'nominalhours_source_rto',       component: 'local_rtocompliance'},
        {key: 'nominalhours_lookup_failed',    component: 'local_rtocompliance'},
        {key: 'nominalhours_lookup_timeout',   component: 'local_rtocompliance'},
    ];

    /**
     * Initialise the nominal-hours autofill on a form.
     *
     * @param {string} codeFieldId  DOM id of the code input (e.g. 'id_qualificationcode' or 'id_unitcode').
     * @param {string} titleFieldId DOM id of the title/name input (may be null).
     * @param {string} hoursFieldId DOM id of the nominal hours input.
     * @param {string} apiurl       Base URL of the essaygraderai API.
     */
    function init(codeFieldId, titleFieldId, hoursFieldId, apiurl) {
        apiUrl = apiurl || 'https://lms-labs.com';

        Str.get_strings(STRING_KEYS).then(function(results) {
            S.lookupBtn      = results[0];
            S.lookupTitle    = results[1];
            S.lookingUpBtn   = results[2];
            S.enterCodeFirst = results[3];
            S.sourceNcver    = results[4];
            S.sourceRto      = results[5];
            S.lookupFailed   = results[6];
            S.lookupTimeout  = results[7];
            setupForm(codeFieldId, titleFieldId, hoursFieldId);
        }).catch(function() {
            // Defaults already set above; set up form with fallback strings.
            setupForm(codeFieldId, titleFieldId, hoursFieldId);
        });
    }

    function setupForm(codeFieldId, titleFieldId, hoursFieldId) {
        var codeField  = document.getElementById(codeFieldId);
        var titleField = titleFieldId ? document.getElementById(titleFieldId) : null;
        var hoursField = document.getElementById(hoursFieldId);

        if (!codeField || !hoursField) {
            return;
        }

        injectLookupButton(codeField, titleField, hoursField);

        codeField.addEventListener('blur', function() {
            var code = codeField.value.trim();
            if (code.length >= 4) {
                // Cancel any pending debounce — blur fires the lookup immediately,
                // so the 800 ms timer would otherwise send a redundant second request.
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                    debounceTimer = null;
                }
                lookupNominalHours(code, titleField, hoursField);
            }
        });

        codeField.addEventListener('input', function() {
            var code = codeField.value.trim();
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            if (code.length >= 4) {
                debounceTimer = setTimeout(function() {
                    lookupNominalHours(code, titleField, hoursField);
                }, 800);
            }
        });
    }

    function injectLookupButton(codeField, titleField, hoursField) {
        var existingBtn = document.getElementById('btn-rtoc-lookup-nominalhours');
        if (existingBtn) {
            return;
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'btn-rtoc-lookup-nominalhours';
        btn.textContent = S.lookupBtn;
        btn.title = S.lookupTitle;
        btn.style.cssText = [
            'margin-left:8px',
            'padding:3px 10px',
            'font-size:12px',
            'background:#1a73e8',
            'color:#fff',
            'border:none',
            'border-radius:4px',
            'cursor:pointer',
            'vertical-align:middle',
        ].join(';');

        btn.addEventListener('mouseenter', function() {
            btn.style.background = '#1557b0';
        });
        btn.addEventListener('mouseleave', function() {
            btn.style.background = '#1a73e8';
        });

        btn.addEventListener('click', function() {
            var code = codeField.value.trim();
            if (!code) {
                showLookupStatus(hoursField, S.enterCodeFirst, 'info');
                setTimeout(function() { hideLookupStatus(); }, 4000);
                return;
            }
            lookupNominalHours(code, titleField, hoursField);
        });

        if (hoursField.parentNode) {
            hoursField.parentNode.appendChild(btn);
        }
    }

    function lookupNominalHours(rawcode, titleField, hoursField) {
        var code = rawcode.toUpperCase().replace(/\s+/g, '');
        var url = apiUrl + '/api/moodle/course-info/nominal-hours/' + encodeURIComponent(code);

        showLookupStatus(hoursField, S.lookingUpBtn.replace('...', '') + ' ' + code + '...', 'info');

        var btn = document.getElementById('btn-rtoc-lookup-nominalhours');
        if (btn) {
            btn.disabled = true;
            btn.textContent = S.lookingUpBtn;
        }

        // Abort any in-flight request before starting a new one.
        if (currentXhr) {
            currentXhr.abort();
            currentXhr = null;
        }

        var xhr = new XMLHttpRequest();
        currentXhr = xhr;
        xhr.open('GET', url, true);
        xhr.timeout = 15000;

        xhr.onreadystatechange = function() {
            // Ignore callbacks from a superseded request.
            if (xhr !== currentXhr) { return; }
            if (xhr.readyState !== 4) {
                return;
            }

            if (btn) {
                btn.disabled = false;
                btn.textContent = S.lookupBtn;
            }

            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);

                    if (titleField && data.unitTitle && !titleField.value.trim()) {
                        titleField.value = data.unitTitle;
                    }

                    if (data.success && data.nominalHours) {
                        hoursField.value = data.nominalHours;
                        var src = data.source === 'ncver'    ? S.sourceNcver
                               : data.source === 'database'  ? S.sourceRto
                               : S.sourceNcver;
                        showLookupStatus(hoursField,
                            '\u2713 Found: ' + data.nominalHours + ' hours (' + src + ')', 'success');
                        setTimeout(function() { hideLookupStatus(); }, 5000);
                    } else {
                        var titleMsg = data.unitTitle ? ' (' + data.unitTitle + ')' : '';
                        showLookupStatus(hoursField,
                            'No NCVER hours found for ' + code + titleMsg + '. Enter manually.', 'warning');
                        setTimeout(function() { hideLookupStatus(); }, 6000);
                    }
                } catch (e) {
                    hideLookupStatus();
                }
            } else {
                showLookupStatus(hoursField, S.lookupFailed, 'warning');
                setTimeout(function() { hideLookupStatus(); }, 5000);
            }
        };

        xhr.ontimeout = function() {
            if (btn) {
                btn.disabled = false;
                btn.textContent = S.lookupBtn;
            }
            showLookupStatus(hoursField, S.lookupTimeout, 'warning');
            setTimeout(function() { hideLookupStatus(); }, 5000);
        };

        xhr.send();
    }

    function showLookupStatus(hoursField, msg, type) {
        var statusId = 'rtoc-nominalhours-lookup-status';
        var el = document.getElementById(statusId);
        if (!el) {
            el = document.createElement('div');
            el.id = statusId;
            el.style.cssText = 'font-size:12px;margin-top:4px;padding:4px 8px;border-radius:4px;display:inline-block;';
            if (hoursField && hoursField.parentNode) {
                var wrapper = document.createElement('div');
                hoursField.parentNode.appendChild(wrapper);
                wrapper.appendChild(el);
            }
        }

        if (type === 'success') {
            el.style.color = '#155724';
            el.style.backgroundColor = '#d4edda';
            el.style.border = '1px solid #c3e6cb';
        } else if (type === 'warning') {
            el.style.color = '#856404';
            el.style.backgroundColor = '#fff3cd';
            el.style.border = '1px solid #ffeeba';
        } else {
            el.style.color = '#0c5460';
            el.style.backgroundColor = '#d1ecf1';
            el.style.border = '1px solid #bee5eb';
        }

        el.textContent = msg;
        el.style.display = 'inline-block';
    }

    function hideLookupStatus() {
        var el = document.getElementById('rtoc-nominalhours-lookup-status');
        if (el) {
            el.style.display = 'none';
        }
    }

    return {
        init: init
    };
});
