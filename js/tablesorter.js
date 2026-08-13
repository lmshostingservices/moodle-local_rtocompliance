/* RTOC Table Sorter — loaded as an external file to comply with Moodle 4.3+ CSP.
   Previously this was an inline <script> block injected by the
   before_footer_html_generation hook. Moving it here allows it to be served as
   a same-origin script, which is permitted by Moodle's CSP 'self' directive. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initRtocTableSorting();
    });

    function initRtocTableSorting() {
        // NOTE: scroll-wrapping was removed here in v4.4.40.
        // tables.js now handles scroll wrapping and the full-screen expand button
        // for all plugin tables. tablesorter.js only manages column sort behaviour.

        var tables = document.querySelectorAll('.data-table, .trainers-table, table.table, table.generaltable');

        tables.forEach(function (table) {
            var headers = table.querySelectorAll('thead th');
            if (headers.length === 0) return;

            headers.forEach(function (th, colIndex) {
                var headerText = th.textContent.trim().toLowerCase();
                if (headerText === 'actions' || headerText === 'action' || headerText === '') return;

                th.classList.add('rtoc-sortable');
                th.setAttribute('data-col-index', colIndex);

                th.addEventListener('click', function () {
                    sortTable(table, colIndex, th);
                });
            });
        });
    }

    function sortTable(table, colIndex, clickedTh) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length === 0) return;

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
})();
