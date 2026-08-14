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
 * RTO Compliance plugin — avetmiss_fields.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

class avetmiss_fields {
    private static $fields = null;
    
    public static function get_all() {
        if (self::$fields !== null) {
            return self::$fields;
        }
        
        $cache = \cache::make('local_rtocompliance', 'avetmiss_codes');
        $cached = $cache->get('fields');
        
        if ($cached !== false) {
            self::$fields = $cached;
            return self::$fields;
        }
        
        self::$fields = self::build_fields();
        $cache->set('fields', self::$fields);
        return self::$fields;
    }
    
    private static function build_fields() {
        return [
            'usi' => [
                'name' => 'USI',
                'shortname' => 'usi',
                'datatype' => 'text',
                'required' => true,
                'maxlength' => 10,
                'description' => 'Unique Student Identifier (10 characters)',
            ],
            'usiexemption' => [
                'name' => 'USI Exemption',
                'shortname' => 'usiexemption',
                'datatype' => 'menu',
                'required' => false,
                'options' => 'N|No exemption
INTOFF|International offshore delivery
INDIV|Individual exemption granted',
                'description' => 'USI exemption reason if applicable',
            ],
            'dateofbirth' => [
                'name' => 'Date of Birth',
                'shortname' => 'dateofbirth',
                'datatype' => 'text',
                'required' => true,
                'maxlength' => 10,
                'description' => 'Date of birth (DD/MM/YYYY format)',
            ],
            'sex' => [
                'name' => 'Sex',
                'shortname' => 'sex',
                'datatype' => 'menu',
                'required' => true,
                'options' => 'M|Male
F|Female
X|Indeterminate/Intersex/Unspecified',
                'description' => 'AVETMISS Sex Identifier',
            ],
            'countryofbirth' => [
                'name' => 'Country of Birth',
                'shortname' => 'countryofbirth',
                'datatype' => 'menu',
                'required' => true,
                'options' => '1101|Australia
1201|New Zealand
2100|United Kingdom
6101|China
7103|India
9999|Other',
                'description' => 'AVETMISS Country Identifier',
            ],
            'languageathome' => [
                'name' => 'Language Spoken at Home',
                'shortname' => 'languageathome',
                'datatype' => 'menu',
                'required' => true,
                'options' => '1201|English
4202|Mandarin
7101|Hindi
1301|Italian
2401|Arabic
9999|Other',
                'description' => 'AVETMISS Language Identifier',
            ],
            'atsi' => [
                'name' => 'Aboriginal/Torres Strait Islander',
                'shortname' => 'atsi',
                'datatype' => 'menu',
                'required' => true,
                'options' => '@|Not stated
N|No
Y|Yes, Aboriginal
T|Yes, Torres Strait Islander
B|Yes, Both',
                'description' => 'Indigenous Status',
            ],
            'disability' => [
                'name' => 'Disability Status',
                'shortname' => 'disability',
                'datatype' => 'menu',
                'required' => true,
                'options' => '@|Not stated
N|No disability
Y|Yes, has disability',
                'description' => 'Disability Flag',
            ],
            'disabilitytype' => [
                'name' => 'Disability Type',
                'shortname' => 'disabilitytype',
                'datatype' => 'menu',
                'required' => false,
                'options' => '11|Hearing/Deaf
12|Physical
13|Intellectual
14|Learning
15|Mental illness
16|Acquired brain impairment
17|Vision
18|Medical condition
19|Other',
                'description' => 'AVETMISS Disability Type Identifier',
            ],
            'prioreducation' => [
                'name' => 'Prior Educational Achievement',
                'shortname' => 'prioreducation',
                'datatype' => 'menu',
                'required' => true,
                'options' => '@@|Not stated
02|Bachelor degree or higher
03|Advanced diploma or associate degree
04|Diploma
05|Certificate IV
06|Certificate III
07|Certificate II
08|Certificate I
09|Miscellaneous education
10|Year 12
11|Year 11
12|Year 10 or below',
                'description' => 'Highest prior educational achievement',
            ],
            'employmentstatus' => [
                'name' => 'Employment Category',
                'shortname' => 'employmentstatus',
                'datatype' => 'menu',
                'required' => true,
                'options' => '@@|Not stated
01|Full-time employee
02|Part-time employee
03|Self-employed
04|Employer
05|Unemployed - seeking full-time work
06|Unemployed - seeking part-time work
07|Not employed - not seeking employment',
                'description' => 'Labour Force Status Identifier',
            ],
            'studyreason' => [
                'name' => 'Study Reason',
                'shortname' => 'studyreason',
                'datatype' => 'menu',
                'required' => true,
                'options' => '@@|Not specified
01|To get a job
02|To develop my existing business
03|To start my own business
04|To try for a different career
05|To get a better job or promotion
06|It was a requirement of my job
07|I wanted extra skills for my job
08|To get into another course of study
11|For personal interest or self-development
12|Other reasons',
                'description' => 'Study Reason Identifier',
            ],
            'streetaddress' => [
                'name' => 'Street Address',
                'shortname' => 'streetaddress',
                'datatype' => 'text',
                'required' => true,
                'maxlength' => 100,
                'description' => 'Building/property name, number and street (NAT00085)',
            ],
            'postcode' => [
                'name' => 'Residential Postcode',
                'shortname' => 'residentialpostcode',
                'datatype' => 'text',
                'required' => true,
                'maxlength' => 4,
                'description' => 'Australian residential postcode',
            ],
            'state' => [
                'name' => 'Residential State',
                'shortname' => 'residentialstate',
                'datatype' => 'menu',
                'required' => true,
                'options' => '01|NSW
02|VIC
03|QLD
04|SA
05|WA
06|TAS
07|NT
08|ACT
09|Other Australian Territory
99|Other/Overseas',
                'description' => 'State/Territory Identifier',
            ],
            'suburb' => [
                'name' => 'Residential Suburb',
                'shortname' => 'residentialsuburb',
                'datatype' => 'text',
                'required' => true,
                'maxlength' => 50,
                'description' => 'Suburb/Locality name',
            ],
            'highestschoollevel' => [
                'name' => 'Highest School Level Completed',
                'shortname' => 'highestschoollevel',
                'datatype' => 'menu',
                'required' => true,
                'options' => '@@|Not stated
02|Did not go to school
08|Year 8 or below
09|Year 9 or equivalent
10|Year 10 or equivalent
11|Year 11 or equivalent
12|Year 12 or equivalent',
                'description' => 'AVETMISS Highest School Level Completed Identifier',
            ],
            'yearschoolleft' => [
                'name' => 'Year Left School',
                'shortname' => 'yearschoolleft',
                'datatype' => 'text',
                'required' => false,
                'maxlength' => 4,
                'description' => 'Year highest school level was completed (e.g. 2020)',
            ],
            'surveycontact' => [
                'name' => 'Survey Contact Consent',
                'shortname' => 'surveycontact',
                'datatype' => 'menu',
                'required' => true,
                'options' => '@|Not stated
Y|Yes, willing to be contacted
N|No, do not contact',
                'description' => 'Permission to contact for surveys and research',
            ],
            'lui' => [
                'name' => 'LUI (Queensland Only)',
                'shortname' => 'lui',
                'datatype' => 'text',
                'required' => false,
                'maxlength' => 10,
                'description' => 'Learner Unique Identifier - 10-digit QCAA number for Queensland students',
            ],
        ];
    }
}
