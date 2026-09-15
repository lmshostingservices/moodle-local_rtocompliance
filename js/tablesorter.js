/* RTOC Table Sorter — loaded as an external file to comply with Moodle 4.3+ CSP.
   Previously this was an inline <script> block injected by the
   before_footer_html_generation hook. Moving it here allows it to be served as
   a same-origin script, which is permitted by Moodle's CSP 'self' directive. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initRtocTableSorting();
    });

    function initRtocTableSorting(root) {
        // NOTE: scroll-wrapping was removed here in v4.4.40.
        // tables.js now handles scroll wrapping and the full-screen expand button
        // for all plugin tables. tablesorter.js only manages column sort behaviour.

        var tables;
        if (root && root.tagName && root.tagName.toLowerCase() === 'table') {
            tables = [root];
        } else {
            tables = document.querySelectorAll('.data-table, .trainers-table, table.table, table.generaltable, ' +
                'table.deadline-table, table.wfm-mapping-table');
        }

        Array.prototype.forEach.call(tables, function (table) {
            if (table.getAttribute('data-rtoc-sort-initialized') === '1') return;
            var headers = table.querySelectorAll('thead th');
            if (headers.length === 0) return;

            Array.prototype.forEach.call(headers, function (th, colIndex) {
                var headerText = th.textContent.trim().toLowerCase();
                if (headerText === 'actions' || headerText === 'action' || headerText === '') return;

                th.classList.add('rtoc-sortable');
                th.setAttribute('data-col-index', colIndex);
                th.setAttribute('data-rtoc-sort-key', sortKey(th, colIndex));

                th.addEventListener('click', function () {
                    sortTable(table, colIndex, th);
                });
            });
            table.setAttribute('data-rtoc-sort-initialized', '1');
        });
    }

    function sortKey(th, colIndex) {
        var source = th.getAttribute('data-sort-key') ||
            th.getAttribute('data-field') ||
            th.textContent.trim().replace(/\s+/g, ' ').toLowerCase();
        return (source || ('column-' + colIndex)).slice(0, 160);
    }

    function sortTable(table, colIndex, clickedTh) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr'));

        var isAsc = clickedTh.classList.contains('rtoc-sort-asc');
        var isDesc = clickedTh.classList.contains('rtoc-sort-desc');

        table.querySelectorAll('th.rtoc-sortable').forEach(function (th) {
            th.classList.remove('rtoc-sort-asc', 'rtoc-sort-desc');
        });

        var newDir = 'asc';
        if (isAsc) {
            newDir = 'desc';
        } else if (isDesc) {
            newDir = 'asc';
        }

        clickedTh.classList.add('rtoc-sort-' + newDir);

        // Keep a sort choice on an empty result page.  Saved views can then
        // carry the user's supported client-sort preference to the next filter.
        if (rows.length === 0) {
            table.dispatchEvent(new CustomEvent('rtoc:tablesort', {
                bubbles: true,
                detail: {column: clickedTh.getAttribute('data-rtoc-sort-key') || '', direction: newDir}
            }));
            return;
        }

        rows.sort(function (a, b) {
            var aCell = a.cells[colIndex];
            var bCell = b.cells[colIndex];
            if (!aCell || !bCell) return 0;

            var aVal = getCellSortValue(aCell);
            var bVal = getCellSortValue(bCell);

            var aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
            var bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));

            if (!isNaN(aNum) && !isNaN(bNum)) {
                return newDir === 'asc' ? aNum - bNum : bNum - aNum;
            }

            var aDate = parseDate(aVal);
            var bDate = parseDate(bVal);
            if (aDate && bDate) {
                return newDir === 'asc' ? aDate - bDate : bDate - aDate;
            }

            var cmp = aVal.localeCompare(bVal, undefined, {sensitivity: 'base'});
            return newDir === 'asc' ? cmp : -cmp;
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });

        // Saved views listens to the same state through the public helper below.
        table.dispatchEvent(new CustomEvent('rtoc:tablesort', {
            bubbles: true,
            detail: {column: clickedTh.getAttribute('data-rtoc-sort-key') || '', direction: newDir}
        }));
    }

    function getCellSortValue(cell) {
        if (cell.hasAttribute('data-sort')) {
            return cell.getAttribute('data-sort');
        }
        var clone = cell.cloneNode(true);
        var buttons = clone.querySelectorAll('a, button, .btn');
        buttons.forEach(function (btn) { btn.remove(); });
        return clone.textContent.trim();
    }

    function parseDate(str) {
        var parts = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (parts) {
            return new Date(parts[3], parts[2] - 1, parts[1]).getTime();
        }
        parts = str.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (parts) {
            return new Date(parts[1], parts[2] - 1, parts[3]).getTime();
        }
        return null;
    }

    function getState(table) {
        var header = table.querySelector('thead th.rtoc-sort-asc, thead th.rtoc-sort-desc');
        if (!header) return null;
        return {
            column: (header.getAttribute('data-rtoc-sort-key') ||
                header.textContent.trim().replace(/\s+/g, ' ').toLowerCase()).slice(0, 160),
            direction: header.classList.contains('rtoc-sort-desc') ? 'desc' : 'asc'
        };
    }

    function applySort(table, column, direction) {
        var headers = Array.prototype.slice.call(table.querySelectorAll('thead th.rtoc-sortable'));
        var header = headers.filter(function (candidate) {
            return candidate.getAttribute('data-rtoc-sort-key') === column ||
                candidate.textContent.trim().replace(/\s+/g, ' ').toLowerCase() === column;
        })[0];
        if (!header || (direction !== 'asc' && direction !== 'desc')) return false;

        var index = parseInt(header.getAttribute('data-col-index'), 10);
        if (isNaN(index)) return false;
        table.querySelectorAll('th.rtoc-sortable').forEach(function (th) {
            th.classList.remove('rtoc-sort-asc', 'rtoc-sort-desc');
        });
        header.classList.add('rtoc-sort-' + direction);

        var tbody = table.querySelector('tbody');
        if (!tbody) return false;
        var rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort(function (a, b) {
            var aCell = a.cells[index];
            var bCell = b.cells[index];
            if (!aCell || !bCell) return 0;
            var aVal = getCellSortValue(aCell);
            var bVal = getCellSortValue(bCell);
            var aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
            var bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return direction === 'asc' ? aNum - bNum : bNum - aNum;
            }
            var aDate = parseDate(aVal);
            var bDate = parseDate(bVal);
            if (aDate && bDate) {
                return direction === 'asc' ? aDate - bDate : bDate - aDate;
            }
            var cmp = aVal.localeCompare(bVal, undefined, {sensitivity: 'base'});
            return direction === 'asc' ? cmp : -cmp;
        });
        rows.forEach(function (row) {
            tbody.appendChild(row);
        });
        return true;
    }

    // The saved-view UI uses this narrow public surface rather than reaching
    // into the sorter's implementation.  It also lets dynamically-added
    // tables be initialised without loading another copy of this script.
    window.RtocTableSorter = {
        getState: getState,
        apply: applySort
    };
    window.initRtocTableSorting = initRtocTableSorting;
})();
