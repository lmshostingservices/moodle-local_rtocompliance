define(['core/str'], function (Str) {
    'use strict';

    // AMD-STRINGS (v6.3.21): every user-facing string on this module comes from the language
    // pack through core/str — none is written in this file — so an RTO that rewords or
    // translates the plugin sees the change here too. The keys are declared empty and filled
    // by loadStrings() before anything is rendered.
    var S = {
        nominalhours_lookup_btn: '',
        nominalhours_lookup_btn_title: '',
        nominalhours_lookup_busy: '',
        nominalhours_lookup_searching: '',
        nominalhours_lookup_found: '',
        nominalhours_lookup_none: '',
        nominalhours_lookup_failed: '',
        nominalhours_lookup_timeout: '',
        nominalhours_source_ncver: '',
        nominalhours_source_local: ''
    };

    /**
     * Load every string above from the language pack into S.
     *
     * @return {Promise} resolved once the strings are in place
     */
    function loadStrings() {
        var keys = Object.keys(S).map(function (k) {
            return {key: k, component: 'local_rtocompliance'};
        });
        return Str.get_strings(keys).then(function (values) {
            Object.keys(S).forEach(function (k, i) {
                if (values[i]) {
                    S[k] = values[i];
                }
            });
            return S;
        });
    }

    /**
     * Substitute a {$a} placeholder (or {$a->name} placeholders) in a resolved string.
     *
     * @param {String} template the string as returned by core/str
     * @param {Object|String} a the replacement value, or an object of named values
     * @return {String} the string with its placeholders filled in
     */
    function fill(template, a) {
        if (a === null || a === undefined) {
            return template;
        }
        if (typeof a !== 'object') {
            return String(template).replace(/\{\$a\}/g, a);
        }
        return String(template).replace(/\{\$a->(\w+)\}/g, function (m, name) {
            return a[name] !== undefined ? a[name] : m;
        });
    }

    var debounceTimer = null;
    // FIX-XHR-RACE (v5.9.277): track the in-flight XHR so we can abort it
    // before starting a new one.  Without this, blur fires immediately AND
    // the debounce timer fires 800 ms later — two concurrent requests race,
    // and the slower (debounce) response silently overwrites whatever the
    // faster (blur) response already populated.
    var currentXhr = null;
    var apiUrl = '';

    /**
     * Initialise the nominal-hours autofill on a form.
     *
     * @param {string} codeFieldId  DOM id of the code input (e.g. 'id_qualificationcode' or 'id_unitcode').
     * @param {string} titleFieldId DOM id of the title/name input (may be null).
     * @param {string} hoursFieldId DOM id of the nominal hours input.
     * @param {string} apiurl       Base URL of the essaygraderai API.
     */
    function init(codeFieldId, titleFieldId, hoursFieldId, apiurl) {
        // NOMINAL-HOURS-INTERNAL (v5.9.418): apiurl is now the plugin's OWN internal
        // lookup endpoint (nominalhours_lookup.php), not lms-labs.com — the lookup
        // resolves the authoritative local reference table (NCVER + state overrides).
        apiUrl = apiurl || (window.M && M.cfg ? M.cfg.wwwroot + '/local/rtocompliance/nominalhours_lookup.php' : '');

        var codeField  = document.getElementById(codeFieldId);
        var titleField = titleFieldId ? document.getElementById(titleFieldId) : null;
        var hoursField = document.getElementById(hoursFieldId);

        if (!codeField || !hoursField) {
            return;
        }

        // The lookup button is only injected once its label has resolved, so no English
        // placeholder can ever be painted into the page.
        loadStrings().then(function () {
            injectLookupButton(codeField, titleField, hoursField);
            return S;
        });

        codeField.addEventListener('blur', function () {
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

        codeField.addEventListener('input', function () {
            var code = codeField.value.trim();
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            if (code.length >= 4) {
                debounceTimer = setTimeout(function () {
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
        btn.textContent = S.nominalhours_lookup_btn;
        btn.title = S.nominalhours_lookup_btn_title;
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

        btn.addEventListener('mouseenter', function () {
            btn.style.background = '#1557b0';
        });
        btn.addEventListener('mouseleave', function () {
            btn.style.background = '#1a73e8';
        });

        btn.addEventListener('click', function () {
            var code = codeField.value.trim();
            if (!code) {
                showLookupStatus(hoursField, 'Enter a code first (e.g. BSB50420 or BSBWHS411).', 'info');
                setTimeout(function () { hideLookupStatus(); }, 4000);
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
        // NOMINAL-HOURS-INTERNAL (v5.9.418): query the internal endpoint by ?code=.
        var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'code=' + encodeURIComponent(code);

        showLookupStatus(hoursField, fill(S.nominalhours_lookup_searching, code), 'info');

        var btn = document.getElementById('btn-rtoc-lookup-nominalhours');
        if (btn) {
            btn.disabled = true;
            btn.textContent = S.nominalhours_lookup_busy;
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

        xhr.onreadystatechange = function () {
            // Ignore callbacks from a superseded request.
            if (xhr !== currentXhr) { return; }
            if (xhr.readyState !== 4) {
                return;
            }

            if (btn) {
                btn.disabled = false;
                btn.textContent = S.nominalhours_lookup_btn;
            }

            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);

                    if (titleField && data.unitTitle && !titleField.value.trim()) {
                        titleField.value = data.unitTitle;
                    }

                    if (data.success && data.nominalHours) {
                        hoursField.value = data.nominalHours;
                        var src = (data.source === 'database')
                            ? S.nominalhours_source_local
                            : S.nominalhours_source_ncver;
                        showLookupStatus(hoursField,
                            '\u2713 ' + fill(S.nominalhours_lookup_found,
                                {hours: data.nominalHours, source: src}), 'success');
                        setTimeout(function () { hideLookupStatus(); }, 5000);
                    } else {
                        var titleMsg = data.unitTitle ? code + ' (' + data.unitTitle + ')' : code;
                        showLookupStatus(hoursField,
                            fill(S.nominalhours_lookup_none, titleMsg), 'warning');
                        setTimeout(function () { hideLookupStatus(); }, 6000);
                    }
                } catch (e) {
                    hideLookupStatus();
                }
            } else {
                showLookupStatus(hoursField, S.nominalhours_lookup_failed, 'warning');
                setTimeout(function () { hideLookupStatus(); }, 5000);
            }
        };

        xhr.ontimeout = function () {
            if (btn) {
                btn.disabled = false;
                btn.textContent = S.nominalhours_lookup_btn;
            }
            showLookupStatus(hoursField, S.nominalhours_lookup_timeout, 'warning');
            setTimeout(function () { hideLookupStatus(); }, 5000);
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
