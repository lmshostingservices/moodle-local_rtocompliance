<?php
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
 * RTO Compliance plugin — shared guard for AVETMISS coded <select> fields.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

use local_rtocompliance\avetmiss_codes;

/**
 * Builds AVETMISS coded menus that cannot silently rewrite the value they are showing.
 *
 * THE DEFECT THIS EXISTS TO PREVENT (v6.3.33, generalised in v6.3.34):
 * A <select> whose selected value has no matching <option> is submitted by every browser
 * as its FIRST option. Build the menu straight from a code array with no placeholder and
 * no check, and opening a record and pressing Save - changing nothing - rewrites the field
 * to whatever happens to sit first. It is silent by construction: the page looks right, the
 * save succeeds, and the audit log records an ordinary-looking update by a real person.
 *
 * It cost this plugin dearly once. The country and language arrays disagreed with NCVER's
 * own system files, and on one production site 798 of 948 non-Australian students held a
 * country that either does not exist in SACC or names a different country than the label
 * shown.
 *
 * The same shape of bug is one code-list edit away on every coded menu in the plugin, which
 * is why the guard lives here rather than in one form. Changing a code list is now safe:
 * the worst outcome is that a stored value shows as unrecognised, not that it is quietly
 * replaced. That property is what let v6.3.34 move delivery mode from the Release 7.0
 * numeric codes to the Release 8.0 Y/N triplets without touching a single stored row.
 *
 * A using class must define CODE_FIELDS as:
 *     'fieldname' => [getter, notstatedcode|null, legacygetter|null]
 * where legacygetter names a second avetmiss_codes method holding superseded-but-recognised
 * values, so a stored legacy code is labelled for what it is instead of reported as unknown.
 */
trait code_select_trait {
    /**
     * The record this form is editing, or null on a create.
     *
     * A using class overrides this if its custom data is not keyed 'student'.
     *
     * @return \stdClass|null
     */
    protected function get_edited_record() {
        return $this->_customdata['student'] ?? null;
    }

    /**
     * The value currently stored for a coded field, as a string, or null on a create.
     *
     * @param string $name Field name.
     * @return string|null
     */
    protected function stored_code_value($name) {
        $record = $this->get_edited_record();
        if ($record === null || !isset($record->$name)) {
            return null;
        }
        return (string)$record->$name;
    }

    /**
     * Add a guarded <select> for an AVETMISS coded field.
     *
     * Three things close the hole, and all three are needed:
     *
     * 1. A value the list does not contain is ADDED to the list, labelled as superseded or
     *    unrecognised. The browser then always has a matching option, so the first-option
     *    fallback can never fire, and the operator can see what the record really holds.
     * 2. The FIRST option is the field's own not-stated code where AVETMISS defines one, and
     *    a blank placeholder where it does not. If a fallback ever does happen - a
     *    hand-built POST, a future bug - it lands on 'Not stated', which is valid and
     *    visibly wrong in a report, rather than on a real country or a real delivery mode.
     * 3. Every label carries its code in brackets. A mislabelled list is then visible to the
     *    person typing, not only to an auditor a year later.
     *
     * @param string $name Field name, which must be a key of static::CODE_FIELDS.
     * @param string $label Visible label.
     * @param string|null $helpname Help string name, defaults to $name.
     * @return void
     */
    protected function add_code_select($name, $label, $helpname = null) {
        $mform = $this->_form;
        [$getter, $notstated, $legacygetter] = array_pad(static::CODE_FIELDS[$name], 3, null);
        $codes = avetmiss_codes::$getter();

        $options = [];
        if ($notstated !== null && array_key_exists($notstated, $codes)) {
            $options[$notstated] = $codes[$notstated] . ' (' . $notstated . ')';
        } else {
            $options[''] = get_string('choosedots');
        }
        foreach ($codes as $code => $text) {
            $code = (string)$code;
            if ($notstated !== null && $code === (string)$notstated) {
                continue;
            }
            $options[$code] = $text . ' (' . $code . ')';
        }

        $current = $this->stored_code_value($name);
        if ($current !== null && $current !== '' && !array_key_exists($current, $options)) {
            // A superseded code the plugin still recognises is named for what it is. That
            // matters most where a whole code set has moved on: 12,911 enrolments on one
            // site hold Release 7.0 delivery modes, and calling those "unrecognised" would
            // be both wrong and alarming.
            $legacy = $legacygetter !== null ? avetmiss_codes::$legacygetter() : [];
            $options[$current] = array_key_exists($current, $legacy)
                ? $legacy[$current] . ' (' . $current . ')'
                : get_string('code_unrecognised', 'local_rtocompliance', $current);
        }

        $mform->addElement('select', $name, $label, $options);
        if ($helpname !== false) {
            $mform->addHelpButton($name, $helpname ?? $name, 'local_rtocompliance');
        }
    }

    /**
     * Refuse any NEW coded value that is not in the standard.
     *
     * A value unchanged from what is already stored is allowed through, deliberately. On a
     * site with thousands of legacy codes, refusing them would block every unrelated edit -
     * a phone number, a USI, an end date - until someone had researched the right answer.
     * The rule that matters is that no NEW bad value can be written.
     *
     * A recognised superseded code is likewise allowed to persist unchanged, because the
     * NAT generator converts it on output and nothing is gained by blocking the record.
     *
     * @param array $data Submitted data.
     * @return array field => error string
     */
    protected function validate_code_fields(array $data): array {
        $errors = [];
        foreach (static::CODE_FIELDS as $field => $spec) {
            [$getter, $notstated, $legacygetter] = array_pad($spec, 3, null);
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = (string)$data[$field];
            if ($value === '') {
                continue;
            }
            if (array_key_exists($value, avetmiss_codes::$getter())) {
                continue;
            }
            if ($value === (string)$this->stored_code_value($field)) {
                continue;
            }
            $errors[$field] = get_string('error_code_not_in_standard', 'local_rtocompliance', $value);
        }
        return $errors;
    }
}
