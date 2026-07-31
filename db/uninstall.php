<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_rtocompliance_uninstall() {
    global $DB;

    $avetmissshortnames = [
        'usi',
        'usiexemption',
        'dateofbirth',
        'sex',
        'countryofbirth',
        'languageathome',
        'atsi',
        'disability',
        'disabilitytype',
        'prioreducation',
    ];

    foreach ($avetmissshortnames as $shortname) {
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if ($field) {
            $DB->delete_records('user_info_data', ['fieldid' => $field->id]);
            $DB->delete_records('user_info_field', ['id' => $field->id]);
        }
    }

    $category = $DB->get_record('user_info_category', ['name' => 'AVETMISS Data']);
    if ($category) {
        $remainingfields = $DB->count_records('user_info_field', ['categoryid' => $category->id]);
        if ($remainingfields == 0) {
            $DB->delete_records('user_info_category', ['id' => $category->id]);
        }
    }

    return true;
}
