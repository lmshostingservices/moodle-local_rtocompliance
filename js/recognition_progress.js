/**
 * Batched National Register classification with visible progress.
 *
 * Replaces a single POST that ran every lookup in one request - on a site with 65
 * unclassified codes that meant minutes on a blank page and then, often, a timeout
 * with no way to tell whether anything had happened.
 *
 * The form still works with JavaScript off: this script only takes over the submit
 * event, and the server path it replaces is unchanged.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function () {
    'use strict';

    var form = document.getElementById('rtoc-recognition-lookup-form');
    if (!form) {
        return;
    }

    var cfg = window.rtocRecognition || {};
    var endpoint = cfg.endpoint;
    var sesskey = cfg.sesskey;
    var strings = cfg.strings || {};
    if (!endpoint || !sesskey) {
        return; // Leave the plain form submit in place.
    }

    var panel = document.getElementById('rtoc-recognition-progress');
    var bar = document.getElementById('rtoc-recognition-bar');
    var label = document.getElementById('rtoc-recognition-label');
    var log = document.getElementById('rtoc-recognition-log');
    var button = form.querySelector('button[type="submit"]');

    var total = 0;
    var done = 0;
    var tally = {found: 0, notfound: 0, error: 0};
    var stopped = false;

    /**
     * Substitute {$a->name} placeholders in a Moodle string.
     *
     * @param {string} tpl
     * @param {object} vals
     * @returns {string}
     */
    function fill(tpl, vals) {
        return String(tpl || '').replace(/\{\$a->(\w+)\}/g, function (m, k) {
            return typeof vals[k] === 'undefined' ? m : vals[k];
        });
    }

    /**
     * POST to the endpoint and parse the JSON envelope.
     *
     * @param {object} params
     * @returns {Promise<object>}
     */
    function post(params) {
        var body = new URLSearchParams();
        body.append('sesskey', sesskey);
        Object.keys(params).forEach(function (k) {
            body.append(k, params[k]);
        });
        return fetch(endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) {
            return r.text().then(function (text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    // An HTML error page, a login redirect, or a proxy notice.
                    throw new Error(strings.badresponse || 'Unexpected response from the server.');
                }
                if (!data.ok) {
                    throw new Error(data.error || 'error');
                }
                return data;
            });
        });
    }

    /**
     * Repaint the bar and the counter.
     *
     * @returns {void}
     */
    function paint() {
        var pct = total > 0 ? Math.round((done / total) * 100) : 100;
        bar.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));
        label.textContent = fill(strings.progress, {done: done, total: total});
    }

    /**
     * Add one finished code to the running list.
     *
     * @param {object} row
     * @returns {void}
     */
    function logrow(row) {
        var line = document.createElement('div');
        line.className = 'small';

        var code = document.createElement('code');
        code.textContent = row.code;
        line.appendChild(code);

        var badge = document.createElement('span');
        badge.className = 'badge ml-2 ' + (
            row.result === 'found' ? 'badge-success'
                : (row.result === 'notfound' ? 'badge-secondary' : 'badge-warning'));
        badge.textContent = strings['result_' + row.result] || row.result;
        line.appendChild(badge);

        if (row.title) {
            var t = document.createElement('span');
            t.className = 'text-muted ml-2';
            t.textContent = row.title;
            line.appendChild(t);
        }

        log.insertBefore(line, log.firstChild);
    }

    /**
     * Update the three count cards in place.
     *
     * @param {object} counts
     * @returns {void}
     */
    function paintcounts(counts) {
        [['recognised', 'rtoc-count-recognised'],
            ['notrecognised', 'rtoc-count-notrecognised'],
            ['unknown', 'rtoc-count-unknown']].forEach(function (pair) {
            var el = document.getElementById(pair[1]);
            if (el && typeof counts[pair[0]] !== 'undefined') {
                el.textContent = counts[pair[0]];
            }
        });
    }

    /**
     * Finish: say what happened and offer the reload that redraws the table.
     *
     * @returns {void}
     */
    function finish() {
        var msg = fill(strings.done, {
            checked: done,
            recognised: tally.found,
            notfound: tally.notfound,
            errors: tally.error
        });
        label.textContent = msg;
        bar.style.width = '100%';
        bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
        bar.classList.add(tally.error > 0 ? 'bg-warning' : 'bg-success');

        var reload = document.createElement('button');
        reload.type = 'button';
        reload.className = 'btn btn-sm btn-primary mt-2';
        reload.textContent = strings.reload || 'Refresh the list';
        reload.addEventListener('click', function () {
            window.location.reload();
        });
        panel.appendChild(reload);
    }

    /**
     * Show a failure without losing the work already done.
     *
     * @param {Error} err
     * @returns {void}
     */
    function fail(err) {
        stopped = true;
        var box = document.createElement('div');
        box.className = 'alert alert-danger mt-2 mb-0';
        box.textContent = fill(strings.failed, {error: err.message || String(err)})
            + ' ' + (strings.failed_resume || '');
        panel.appendChild(box);
        if (button) {
            button.disabled = false;
        }
    }

    /**
     * Run batches until the server says nothing is pending.
     *
     * @param {number} runstart
     * @returns {void}
     */
    function loop(runstart) {
        if (stopped) {
            return;
        }
        post({step: 'batch', runstart: runstart, batch: 5}).then(function (data) {
            done += data.processed;
            tally.found += data.tally.found;
            tally.notfound += data.tally.notfound;
            tally.error += data.tally.error;
            (data.results || []).forEach(logrow);
            paintcounts(data.counts);
            paint();

            // processed === 0 with work still pending would spin forever, so treat it
            // as the end of the run rather than trusting remaining alone.
            if (data.remaining > 0 && data.processed > 0) {
                loop(runstart);
            } else {
                finish();
            }
        }).catch(fail);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (button) {
            button.disabled = true;
        }
        panel.hidden = false;
        log.textContent = '';
        label.textContent = strings.starting || 'Starting...';

        post({step: 'start'}).then(function (data) {
            total = data.total;
            done = 0;
            if (total === 0) {
                label.textContent = strings.nothingtodo
                    || 'Every code already has a state.';
                bar.style.width = '100%';
                bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                bar.classList.add('bg-success');
                if (button) {
                    button.disabled = false;
                }
                return;
            }
            paint();
            loop(data.runstart);
        }).catch(fail);
    });
})();
