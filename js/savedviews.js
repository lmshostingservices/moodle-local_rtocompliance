/* RTO Compliance saved table views.
 *
 * This file is deliberately loaded as a same-origin external script.  The
 * footer only provides data attributes; there is no page-specific inline
 * JavaScript to be blocked by Moodle's CSP.
 */
(function (window, document) {
    'use strict';

    var TABLE_SELECTOR = [
        'table.data-table',
        'table.trainers-table',
        'table.deadline-table',
        'table.wfm-mapping-table',
        'table.rtoc-usi-table',
        'table.rtoc-unit-table',
        'table.natval-table',
        'table#rtoc-unit-table',
        'table.generaltable',
        'table.table'
    ].join(', ');

    var NAME_MAX_LENGTH = 60;
    var QUERY_VALUE_MAX_LENGTH = 240;
    var STATE_MAX_LENGTH = 1200;

    /* Marker proving this page load has already settled its saved view.
     *
     * It is added to every URL this file navigates to, whether or not the view
     * being applied contributes a single query parameter. That is what makes a
     * redirect loop impossible: the marker's presence is decided by the
     * navigation, never by the contents of the view. It is not a registered
     * field on any page, so safeTarget() drops it rather than carrying it into
     * a saved state or a later re-apply, and no PHP reads it. */
    var STICKY_PARAM = 'rtocsv';

    /* At most one automatic apply per page load, so two tables on the same
     * page cannot race each other into competing navigations. */
    var autoApplied = false;

    var OFFSET_FIELDS = {
        page: true,
        p: true,
        start: true,
        offset: true,
        pageoffset: true,
        pageindex: true,
        usipage: true,
        rpage: true,
        rsortpage: true
    };

    var CONTEXT_FIELDS = {
        id: true,
        courseid: true,
        cmid: true,
        contextid: true,
        userid: true,
        studentid: true,
        qualid: true,
        qualificationid: true,
        categoryid: true,
        section: true,
        tab: true,
        view: true,
        mode: true,
        lang: true,
        type: true
    };

    var SERVER_SORT_FIELDS = {
        sort: true,
        sortby: true,
        orderby: true,
        order: true,
        direction: true,
        sortdir: true,
        sortdirection: true,
        dir: true,
        usisort: true,
        usidir: true,
        rsort: true
    };

    function unsafeParameter(name) {
        var lower = String(name || '').toLowerCase();
        return /^(action|sesskey|export(?:_|$)|download(?:_|$)|delete(?:_|$)|confirm(?:_|$)|cancel(?:_|$)|save(?:_|$)|update(?:_|$)|bulk(?:_|$)|selected(?:_|$)|selection(?:_|$)|ids$|idlist|submit(?:_|$)|token(?:_|$)|nonce(?:_|$)|password(?:_|$)|file(?:_|$)|format(?:_|$))/.test(lower);
    }

    var instances = [];
    var observer = null;
    var scanQueued = false;

    function parseConfig() {
        var element = document.querySelector('[data-rtoc-savedviews-config]');
        if (!element) {
            return null;
        }

        var fields = [];
        try {
            fields = JSON.parse(element.getAttribute('data-allowed-fields') || '[]');
        } catch (ignore) {
            fields = [];
        }
        if (!Array.isArray(fields)) {
            fields = Object.keys(fields || {});
        }

        var allowed = {};
        fields.forEach(function (field) {
            if (typeof field === 'string' && /^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(field)) {
                allowed[field] = true;
            }
        });

        var page = element.getAttribute('data-page') || '';
        var endpoint = element.getAttribute('data-endpoint') || '';
        var sesskey = element.getAttribute('data-sesskey') || '';
        if (!page || !endpoint || !sesskey) {
            return null;
        }
        return {
            element: element,
            page: page,
            endpoint: endpoint,
            sesskey: sesskey,
            allowed: allowed
        };
    }

    function tableCandidates() {
        return Array.prototype.slice.call(document.querySelectorAll(TABLE_SELECTOR)).filter(function (table) {
            // Layout/settings tables and the cloned full-screen table are not
            // operational data tables.  A header is the important distinction:
            // an empty tbody is still a real, sortable data table.
            if (table.closest('.rtoc-savedviews-ignore, .rtoc-fullscreen-overlay')) {
                return false;
            }
            if (!table.querySelector('thead th')) {
                return false;
            }
            if (table.closest('.mform') && !table.classList.contains('generaltable')) {
                return false;
            }
            return true;
        });
    }

    function normaliseHeader(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    /* Characters a sortable header adds to show the current sort. They are part of
     * the header's TEXT, not a recognisable icon element, so stripping elements is
     * not enough - the glyphs have to come out of the string as well.
     *
     * This mattered: several pages render the arrow inside a plain styled span, so
     * the arrow was being hashed into the table's identity. The identity therefore
     * changed whenever the sort column or direction changed, which changed the
     * saved-view namespace with it - a view saved while sorted one way became
     * invisible when the page was next opened with a different sort. A refresh kept
     * working, because the URL kept the same sort, which is what made the fault look
     * like it only happened when navigating away and back. */
    var SORT_GLYPHS = /[\u25B2\u25BC\u25B4\u25BE\u2191\u2193\u2195\u21C5\u2B06\u2B07\uFE0F]/g;

    function stableHeaderValue(header, index) {
        var explicit = header.getAttribute('data-sort-key') ||
            header.getAttribute('data-field') ||
            header.getAttribute('aria-label');
        if (explicit) {
            return normaliseHeader(explicit);
        }
        var clone = header.cloneNode(true);
        Array.prototype.slice.call(clone.querySelectorAll(
            'svg, .rtoc-sort-icon, .sort-icon, [aria-hidden="true"]'
        )).forEach(function (decorative) {
            decorative.remove();
        });
        var text = (clone.textContent || ('column-' + index)).replace(SORT_GLYPHS, '');
        return normaliseHeader(text);
    }

    function stableHash(value) {
        var first = 2166136261;
        var second = 2246822519;
        String(value).split('').forEach(function (character) {
            var code = character.charCodeAt(0);
            first ^= code;
            first = Math.imul(first, 16777619);
            second ^= code;
            second = Math.imul(second, 3266489917);
        });
        return ('00000000' + (first >>> 0).toString(16)).slice(-8) +
            ('00000000' + (second >>> 0).toString(16)).slice(-8);
    }

    function tableIdentity(table) {
        var explicit = table.getAttribute('data-rtoc-table-key') ||
            table.getAttribute('data-table-key') ||
            table.getAttribute('data-view-key') ||
            table.id ||
            '';
        /* An explicit key or id is the table's identity ON ITS OWN.
         *
         * Previously the column headings were appended even when an explicit key
         * existed, which defeated the point of having one: a table with a column
         * that only appears under some conditions - for example a Qualifications
         * column shown only when a qualification file is present - changed identity
         * with that column, and took the saved-view namespace with it. Headings are
         * now the fallback for tables that carry no key of their own. */
        if (explicit) {
            return 'table:' + explicit;
        }
        var headers = Array.prototype.slice.call(table.querySelectorAll('thead th')).map(stableHeaderValue);
        return 'headers:' + headers.join('|');
    }

    function tableKey(table, allTables) {
        var explicit = table.getAttribute('data-rtoc-table-key') ||
            table.getAttribute('data-table-key') ||
            table.getAttribute('data-view-key') ||
            table.id ||
            '';
        var identity = tableIdentity(table);
        var slug = explicit.replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
        var hash = stableHash(identity);
        var stem = slug ? 'id-' + slug : 'headers';
        var base = stem.slice(0, 80 - hash.length - 1) + '-' + hash;

        var same = allTables.filter(function (candidate) {
            return tableIdentity(candidate) === identity;
        });
        var index = same.indexOf(table);
        if (same.length <= 1) {
            return base;
        }
        var suffix = '-' + (index + 1);
        return base.slice(0, 80 - suffix.length) + suffix;
    }

    function nearestLabel(table, key) {
        var card = table.closest('.generalbox, .rtoc-admin-card, .rtoc-page-card, .card');
        var heading = card && card.querySelector('h2, h3, h4, h5, h6');
        return (heading ? heading.textContent.trim() : '') || key;
    }

    function makeElement(tag, className, text) {
        var element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (typeof text === 'string') {
            element.textContent = text;
        }
        return element;
    }

    function setStatus(instance, message, isError) {
        instance.status.textContent = message || '';
        instance.status.classList.toggle('rtoc-savedviews-status-error', !!isError);
        instance.status.setAttribute('role', isError ? 'alert' : 'status');
    }

    function selectedId(instance) {
        return instance.select.value || '';
    }

    function selectedView(instance) {
        var id = selectedId(instance);
        return id && instance.views[id] ? instance.views[id] : null;
    }

    function updateActionState(instance) {
        var hasView = !!selectedView(instance);
        instance.apply.disabled = !hasView;
        instance.update.disabled = !hasView;
        instance.delete.disabled = !hasView;
        if (instance.reset) {
            instance.reset.disabled = !instance.lastViewId;
        }
    }

    /* True when the current URL already carries one of this page's own
     * registered filter fields. A deliberate link or a submitted filter form
     * always wins over the remembered view. */
    function urlCarriesPageFilters(instance) {
        var search = new URL(window.location.href).searchParams;
        return Object.keys(instance.config.allowed).some(function (name) {
            return search.has(name);
        });
    }

    function stickyMarkerPresent() {
        return new URL(window.location.href).searchParams.has(STICKY_PARAM);
    }

    function renderViews(instance, views, preserveId, lastViewId) {
        instance.views = {};
        instance.select.textContent = '';
        instance.select.appendChild(makeElement('option', '', 'Choose a saved view'));
        (Array.isArray(views) ? views : []).forEach(function (view) {
            if (!view || view.id === undefined || typeof view.name !== 'string') {
                return;
            }
            var id = String(view.id);
            instance.views[id] = {
                id: id,
                name: view.name,
                state: validState(view.state)
            };
            var option = makeElement('option', '', view.name);
            option.value = id;
            instance.select.appendChild(option);
        });
        if (lastViewId !== undefined) {
            instance.lastViewId = lastViewId && instance.views[lastViewId] ? String(lastViewId) : '';
        }
        if (preserveId && instance.views[preserveId]) {
            instance.select.value = preserveId;
        } else if (instance.lastViewId && instance.views[instance.lastViewId]) {
            // Show the remembered view as the current one, so the dropdown
            // agrees with the rows on screen after a refresh or a fresh login.
            instance.select.value = instance.lastViewId;
            instance.name.value = instance.views[instance.lastViewId].name;
        }
        updateActionState(instance);
    }

    function validState(state) {
        if (typeof state === 'string') {
            try {
                state = JSON.parse(state);
            } catch (ignore) {
                state = {};
            }
        }
        state = state && typeof state === 'object' ? state : {};
        var query = {};
        if (state.query && typeof state.query === 'object') {
            Object.keys(state.query).forEach(function (name) {
                if (typeof state.query[name] === 'string') {
                    query[name] = state.query[name];
                }
            });
        }
        var sort = null;
        if (state.sort && typeof state.sort === 'object' &&
                typeof state.sort.column === 'string' &&
                (state.sort.direction === 'asc' || state.sort.direction === 'desc')) {
            sort = {
                column: state.sort.column,
                direction: state.sort.direction
            };
        }
        return {query: query, sort: sort};
    }

    function post(instance, action, extra) {
        var params = new URLSearchParams();
        params.set('action', action);
        params.set('page', instance.config.page);
        params.set('table', instance.tableKey);
        params.set('sesskey', instance.config.sesskey);
        Object.keys(extra || {}).forEach(function (name) {
            if (extra[name] !== undefined && extra[name] !== null) {
                params.set(name, String(extra[name]));
            }
        });
        return window.fetch(instance.config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: params.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    throw new Error('The saved views response was not valid JSON.');
                }
                if (!response.ok || !data || data.ok !== true) {
                    throw new Error((data && (data.message || data.error)) || 'Saved views request failed.');
                }
                return data;
            });
        });
    }

    function sortedServerSide(table) {
        if (table.querySelector(
            'thead a[href*="sort="], thead a[href*="sortby="], ' +
            'thead a[href*="usisort="], thead a[href*="rsort="]'
        )) {
            return true;
        }
        var params = new URL(window.location.href).searchParams;
        var hasServerSort = Object.keys(SERVER_SORT_FIELDS).some(function (name) {
            return params.has(name);
        });
        if (!hasServerSort) {
            return false;
        }
        return !!table.querySelector('thead a[href], thead button');
    }

    function pageHasServerSortLinks() {
        return !!document.querySelector(
            'table thead a[href*="sort="], table thead a[href*="sortby="], ' +
            'table thead a[href*="usisort="], table thead a[href*="rsort="]'
        );
    }

    function sortState(instance) {
        if (sortedServerSide(instance.table)) {
            return null;
        }
        if (window.RtocTableSorter && typeof window.RtocTableSorter.getState === 'function') {
            return window.RtocTableSorter.getState(instance.table);
        }
        var header = instance.table.querySelector('thead th.rtoc-sort-asc, thead th.rtoc-sort-desc');
        if (!header) {
            return null;
        }
        var direction = header.classList.contains('rtoc-sort-desc') ? 'desc' : 'asc';
        return {
            column: (header.getAttribute('data-rtoc-sort-key') || normaliseHeader(header.textContent)).slice(0, 160),
            direction: direction
        };
    }

    function captureQuery(instance) {
        var query = {};
        var search = new URL(window.location.href).searchParams;
        Object.keys(instance.config.allowed).forEach(function (name) {
            var values = search.getAll(name);
            if (values.length) {
                query[name] = values.join(',');
            }
        });

        // GET filter forms are useful on first render, before their values have
        // necessarily been reflected in the URL.  Never read POST forms or
        // checkboxes: the latter are commonly student-selection controls.
        Array.prototype.slice.call(document.querySelectorAll('form')).forEach(function (form) {
            var method = (form.getAttribute('method') || 'get').toLowerCase();
            if (method !== 'get') {
                return;
            }
            Array.prototype.slice.call(form.querySelectorAll('input[name], select[name], textarea[name]')).forEach(function (control) {
                var name = control.name;
                if (!instance.config.allowed[name] || control.disabled ||
                        control.type === 'submit' || control.type === 'button' ||
                        control.type === 'reset' || control.type === 'file' ||
                        control.type === 'checkbox' || control.type === 'radio') {
                    return;
                }
                var value = '';
                if (control.tagName.toLowerCase() === 'select' && control.multiple) {
                    value = Array.prototype.slice.call(control.selectedOptions).map(function (option) {
                        return option.value;
                    }).join(',');
                } else {
                    value = String(control.value || '');
                }
                query[name] = value;
            });
        });
        return query;
    }

    function captureState(instance) {
        var state = {
            query: captureQuery(instance),
            sort: sortState(instance)
        };
        validateState(state);
        return state;
    }

    function validateState(state) {
        var query = state && state.query && typeof state.query === 'object' ? state.query : {};
        Object.keys(query).forEach(function (name) {
            if (typeof query[name] === 'string' && query[name].length > QUERY_VALUE_MAX_LENGTH) {
                throw new Error('Filter value for "' + name + '" is too long (maximum ' +
                    QUERY_VALUE_MAX_LENGTH + ' characters).');
            }
        });
        if (state && state.sort && typeof state.sort.column === 'string' &&
                state.sort.column.length > 160) {
            throw new Error('The selected sort column identity is too long.');
        }
        var encoded = JSON.stringify(state || {});
        if (encoded.length > STATE_MAX_LENGTH) {
            throw new Error('This saved view is too large (maximum ' + STATE_MAX_LENGTH + ' characters).');
        }
    }

    function saveState(instance, updateId) {
        var name = instance.name.value.trim();
        if (!name && !updateId) {
            setStatus(instance, 'Enter a name for the new saved view.', true);
            instance.name.focus();
            return;
        }
        if (name.length > NAME_MAX_LENGTH) {
            setStatus(instance, 'Saved view names can be at most ' + NAME_MAX_LENGTH + ' characters.', true);
            instance.name.focus();
            return;
        }
        var state;
        try {
            state = captureState(instance);
        } catch (error) {
            setStatus(instance, error.message, true);
            return;
        }
        var extra = {
            state: JSON.stringify(state)
        };
        if (name) {
            extra.name = name;
        }
        if (updateId) {
            extra.id = updateId;
        }
        var existingIds = Object.keys(instance.views);
        setStatus(instance, updateId ? 'Updating saved view…' : 'Saving saved view…', false);
        instance.save.disabled = true;
        instance.update.disabled = true;
        post(instance, updateId ? 'save' : 'save', extra).then(function (data) {
            var previous = updateId || '';
            renderViews(instance, data.views || [], previous, data.lastview || '');
            if (!previous && name) {
                var found = Object.keys(instance.views).filter(function (id) {
                    return existingIds.indexOf(id) === -1 && instance.views[id].name === name;
                }).pop() || Object.keys(instance.views).filter(function (id) {
                    return instance.views[id].name === name;
                }).pop();
                if (found) {
                    instance.select.value = found;
                }
            }
            updateActionState(instance);
            setStatus(instance, updateId ? 'Saved view updated.' : 'Saved view created.', false);
        }).catch(function (error) {
            setStatus(instance, error.message, true);
        }).then(function () {
            instance.save.disabled = false;
            updateActionState(instance);
        });
    }

    function deleteView(instance) {
        var view = selectedView(instance);
        if (!view) {
            return;
        }
        if (!window.confirm('Delete the saved view "' + view.name + '"?')) {
            return;
        }
        setStatus(instance, 'Deleting saved view…', false);
        instance.delete.disabled = true;
        post(instance, 'delete', {id: view.id}).then(function (data) {
            renderViews(instance, data.views || [], '', data.lastview || '');
            setStatus(instance, 'Saved view deleted.', false);
        }).catch(function (error) {
            setStatus(instance, error.message, true);
            updateActionState(instance);
        });
    }

    function safeTarget(instance, state) {
        validateState(state || {});
        var source = new URL(window.location.href);
        var target = new URL(window.location.href);
        var query = new URLSearchParams();
        // Sorting belongs to the page URL rather than a saved view. Preserve
        // canonical server-sort parameters even when this view is for another
        // table on a page containing more than one operational table.
        var serverSort = sortedServerSide(instance.table) || pageHasServerSortLinks();

        source.searchParams.forEach(function (value, name) {
            var lower = name.toLowerCase();
            if (instance.config.allowed[name] || OFFSET_FIELDS[lower] || unsafeParameter(lower)) {
                return;
            }
            if (CONTEXT_FIELDS[lower] || (serverSort && SERVER_SORT_FIELDS[lower])) {
                query.append(name, value);
            }
        });

        var values = state && state.query && typeof state.query === 'object' ? state.query : {};
        Object.keys(instance.config.allowed).forEach(function (name) {
            if (typeof values[name] === 'string' && values[name] !== '') {
                query.set(name, values[name]);
            }
        });
        target.search = query.toString();
        target.hash = '';
        return target;
    }

    function storageKey(instance) {
        return 'rtoc:saved-view:' + encodeURIComponent(instance.config.page) + ':' +
            encodeURIComponent(instance.tableKey);
    }

    function restoreSort(instance) {
        var key = storageKey(instance);
        var stored;
        try {
            stored = window.sessionStorage.getItem(key);
            window.sessionStorage.removeItem(key);
        } catch (ignore) {
            return;
        }
        if (!stored) {
            return;
        }
        var record;
        try {
            record = JSON.parse(stored);
        } catch (ignoreParse) {
            return;
        }
        if (!record || !record.target || record.target !== window.location.pathname + window.location.search ||
                !record.sort || sortedServerSide(instance.table)) {
            return;
        }
        if (window.RtocTableSorter && typeof window.RtocTableSorter.apply === 'function') {
            window.RtocTableSorter.apply(instance.table, record.sort.column, record.sort.direction);
        }
    }

    /* Navigate to a view's URL, handing the client-side sort across the one
     * navigation and stamping the sticky marker so this page load is not
     * re-settled on arrival. */
    function navigateToView(instance, target, sort) {
        target.searchParams.set(STICKY_PARAM, '1');
        try {
            window.sessionStorage.setItem(storageKey(instance), JSON.stringify({
                target: target.pathname + target.search,
                sort: sort
            }));
        } catch (ignore) {
            // A storage restriction should not prevent a safe URL apply.
        }
        window.location.assign(target.toString());
    }

    function applyView(instance) {
        var view = selectedView(instance);
        if (!view) {
            return;
        }
        var state = validState(view.state);
        var target;
        try {
            target = safeTarget(instance, state);
        } catch (error) {
            setStatus(instance, error.message, true);
            return;
        }

        // Record the choice before leaving, so it is reapplied on the next
        // visit. A failure to record must not block the apply itself - the
        // view still opens, it just will not be remembered.
        instance.apply.disabled = true;
        setStatus(instance, 'Applying saved view…', false);
        post(instance, 'remember', {id: view.id}).then(function () {
            navigateToView(instance, target, state.sort);
        }).catch(function () {
            navigateToView(instance, target, state.sort);
        });
    }

    /* Reapply the remembered view on a page opened without filters of its own.
     *
     * This is what carries a chosen view across a refresh, a new tab and a
     * logout/login cycle: the choice lives in a Moodle user preference rather
     * than in the URL or in browser storage. */
    function autoApplyLastView(instance) {
        if (autoApplied || !instance.lastViewId || stickyMarkerPresent() || urlCarriesPageFilters(instance)) {
            return;
        }
        var view = instance.views[instance.lastViewId];
        if (!view) {
            return;
        }
        var state = validState(view.state);
        var target;
        try {
            target = safeTarget(instance, state);
        } catch (error) {
            return;
        }
        autoApplied = true;
        setStatus(instance, 'Restoring “' + view.name + '”…', false);
        navigateToView(instance, target, state.sort);
    }

    /* Stop reapplying the remembered view and return the table to the page's
     * own default. Without this a sticky view could never be escaped. */
    function resetToDefault(instance) {
        var target;
        try {
            target = safeTarget(instance, {query: {}, sort: null});
        } catch (error) {
            setStatus(instance, error.message, true);
            return;
        }
        instance.reset.disabled = true;
        setStatus(instance, 'Clearing saved view…', false);
        post(instance, 'forget', {}).then(function () {
            navigateToView(instance, target, null);
        }).catch(function (error) {
            setStatus(instance, error.message, true);
            updateActionState(instance);
        });
    }

    function createControls(instance) {
        var title = nearestLabel(instance.table, instance.tableKey);
        var section = makeElement('section', 'rtoc-savedviews');
        section.setAttribute('aria-label', 'Saved views for ' + title);

        var label = makeElement('label', 'rtoc-savedviews-label', 'Saved view');
        var select = document.createElement('select');
        select.className = 'rtoc-savedviews-select';
        select.setAttribute('aria-label', 'Choose a saved view for ' + title);
        label.appendChild(select);
        section.appendChild(label);

        var name = document.createElement('input');
        name.type = 'text';
        name.maxLength = NAME_MAX_LENGTH;
        name.className = 'rtoc-savedviews-name';
        name.placeholder = 'New view name';
        name.setAttribute('aria-label', 'New saved view name for ' + title);
        section.appendChild(name);

        var save = makeElement('button', 'rtoc-savedviews-button', 'Save new');
        save.type = 'button';
        save.title = 'Save the current filters and sort as a new view';
        section.appendChild(save);

        var update = makeElement('button', 'rtoc-savedviews-button', 'Update');
        update.type = 'button';
        update.title = 'Update the selected saved view with current filters and sort';
        section.appendChild(update);

        var apply = makeElement('button', 'rtoc-savedviews-button rtoc-savedviews-apply', 'Apply');
        apply.type = 'button';
        apply.title = 'Apply the selected saved view';
        section.appendChild(apply);

        var reset = makeElement('button', 'rtoc-savedviews-button rtoc-savedviews-reset', 'Page default');
        reset.type = 'button';
        reset.title = 'Stop reopening this table with the remembered saved view';
        section.appendChild(reset);

        var remove = makeElement('button', 'rtoc-savedviews-button rtoc-savedviews-delete', 'Delete');
        remove.type = 'button';
        remove.title = 'Delete the selected saved view';
        section.appendChild(remove);

        var status = makeElement('span', 'rtoc-savedviews-status', '');
        status.setAttribute('aria-live', 'polite');
        section.appendChild(status);

        instance.section = section;
        instance.select = select;
        instance.name = name;
        instance.save = save;
        instance.update = update;
        instance.apply = apply;
        instance.reset = reset;
        instance.delete = remove;
        instance.status = status;

        select.addEventListener('change', function () {
            updateActionState(instance);
            var view = selectedView(instance);
            if (view) {
                name.value = view.name;
            }
        });
        save.addEventListener('click', function () {
            saveState(instance, '');
        });
        update.addEventListener('click', function () {
            saveState(instance, selectedId(instance));
        });
        apply.addEventListener('click', function () {
            applyView(instance);
        });
        reset.addEventListener('click', function () {
            resetToDefault(instance);
        });
        remove.addEventListener('click', function () {
            deleteView(instance);
        });

        var wrapper = instance.table.closest('.rtoc-table-wrap, .rtoc-table-scroll, .rtoc-table-wrapper');
        var anchor = wrapper || instance.table;
        anchor.parentNode.insertBefore(section, anchor);
        updateActionState(instance);
    }

    function loadViews(instance) {
        setStatus(instance, 'Loading saved views…', false);
        // Consume the one-shot client-sort handoff before the optional list
        // request. A transient list/API failure must not lose an already
        // applied, safe local sort.
        restoreSort(instance);
        post(instance, 'list', {}).then(function (data) {
            renderViews(instance, data.views || [], '', data.lastview || '');
            setStatus(instance, '', false);
            autoApplyLastView(instance);
        }).catch(function (error) {
            // The table remains useful if the optional saved-view endpoint is
            // unavailable; controls stay visible and communicate the failure.
            setStatus(instance, error.message, true);
            instance.save.disabled = true;
            instance.update.disabled = true;
            instance.delete.disabled = true;
            instance.apply.disabled = true;
            instance.reset.disabled = true;
        });
    }

    function mount(table, config, allTables) {
        if (table.getAttribute('data-rtoc-savedviews-mounted') === '1') {
            return;
        }
        table.setAttribute('data-rtoc-savedviews-mounted', '1');
        if (window.initRtocTableSorting) {
            window.initRtocTableSorting(table);
        }
        var instance = {
            table: table,
            config: config,
            tableKey: tableKey(table, allTables),
            views: {}
        };
        var originalKey = instance.tableKey;
        var suffix = 2;
        while (instances.some(function (existing) {
            return existing.config.page === config.page && existing.tableKey === instance.tableKey;
        })) {
            var suffixText = '-' + suffix++;
            instance.tableKey = originalKey.slice(0, 80 - suffixText.length) + suffixText;
        }
        table.setAttribute('data-rtoc-savedviews-table', instance.tableKey);
        createControls(instance);
        instances.push(instance);
        loadViews(instance);
    }

    function scan(config) {
        var tables = tableCandidates();
        tables.forEach(function (table) {
            mount(table, config, tables);
        });
    }

    function scheduleScan(config) {
        if (scanQueued) {
            return;
        }
        scanQueued = true;
        window.setTimeout(function () {
            scanQueued = false;
            scan(config);
        }, 0);
    }

    function init() {
        var config = parseConfig();
        if (!config) {
            return;
        }
        scan(config);
        if (window.MutationObserver && document.body) {
            observer = new window.MutationObserver(function () {
                scheduleScan(config);
            });
            observer.observe(document.body, {childList: true, subtree: true});
        }
    }

    window.RtocSavedViews = {
        init: init,
        captureState: captureState,
        safeTarget: safeTarget
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}(window, document));