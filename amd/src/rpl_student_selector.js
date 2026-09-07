// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Data source for the RPL / Credit Transfer student selector.
 *
 * Implements the transport/processResults contract that core/form-autocomplete
 * expects, so the browser only ever receives the matches for what was typed —
 * never the whole student register and never the whole USI list.
 *
 * The value carried by each option is local_rtocompliance_students.id, NOT the
 * Moodle user id: the RPL save path and the results-posting path both key on the
 * local student record.
 *
 * @module     local_rtocompliance/rpl_student_selector
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    'use strict';

    return {

        /**
         * Fetch matching students for the typed query.
         *
         * @param {String} selector Element selector the autocomplete is attached to.
         * @param {String} query Text the user typed.
         * @param {Function} success Called with the raw response.
         * @param {Function} failure Called on error.
         * @return {Promise}
         */
        transport: function(selector, query, success, failure) {
            var request = Ajax.call([{
                methodname: 'local_rtocompliance_search_students',
                args: {query: query}
            }]);

            return request[0].then(success).catch(failure);
        },

        /**
         * Convert the service response into the {value, label} pairs the field wants.
         *
         * @param {String} selector Element selector the autocomplete is attached to.
         * @param {Object} results Response from local_rtocompliance_search_students.
         * @return {Array}
         */
        processResults: function(selector, results) {
            var out = [];

            if (!results || !results.students) {
                return out;
            }

            results.students.forEach(function(student) {
                var label = student.name;
                if (student.usi) {
                    label += ' (USI ' + student.usi + ')';
                }
                out.push({
                    value: student.id,
                    label: label
                });
            });

            return out;
        }
    };
});
