@local @local_rtocompliance
Feature: Course-level AVETMISS filtering
  In order to comply with AVETMISS requirements efficiently
  As an RTO administrator
  I need AVETMISS data collection to apply only to students in nationally recognised courses

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | manager1 | Manager   | One      | manager1@example.com |
    And the following "courses" exist:
      | fullname                                    | shortname | category |
      | BSB50420 Diploma of Leadership              | BSB50420  | 0        |
      | Internal Staff Training                     | INTERNAL  | 0        |
    And the following "course enrolments" exist:
      | user     | course   | role           |
      | student1 | BSB50420 | student        |
      | student2 | INTERNAL | student        |
      | teacher1 | BSB50420 | editingteacher |
      | teacher1 | INTERNAL | editingteacher |
    And the following "system role assigns" exist:
      | user     | role    |
      | manager1 | manager |

  @javascript
  Scenario: Manager can mark course as nationally recognised
    Given I log in as "manager1"
    And I am on "BSB50420" course homepage
    When I navigate to "RTO Compliance Settings" in current page administration
    Then I should see "Nationally Recognised Training"
    When I set the field "nationallyrecognised" to "1"
    And I set the field "qualificationcode" to "BSB50420"
    And I set the field "qualificationname" to "Diploma of Leadership and Management"
    And I set the field "nominalhours" to "600"
    And I press "Save changes"
    Then I should see "Settings saved"

  @javascript
  Scenario: Teacher can mark course as nationally recognised
    Given I log in as "teacher1"
    And I am on "BSB50420" course homepage
    When I navigate to "RTO Compliance Settings" in current page administration
    Then I should see "Nationally Recognised Training"
    When I set the field "nationallyrecognised" to "1"
    And I press "Save changes"
    Then I should see "Settings saved"

  @javascript
  Scenario: Student in nationally recognised course sees AVETMISS profile prompt
    Given the following "local_rtocompliance_courses" exist:
      | course   | nationallyrecognised | qualificationcode | qualificationname                    |
      | BSB50420 | 1                    | BSB50420          | Diploma of Leadership and Management |
    When I log in as "student1"
    And I am on "BSB50420" course homepage
    Then I should see "Complete your AVETMISS profile"

  @javascript
  Scenario: Student in non-recognised course does not see AVETMISS profile prompt
    When I log in as "student2"
    And I am on "INTERNAL" course homepage
    Then I should not see "Complete your AVETMISS profile"

  @javascript
  Scenario: Teacher is never prompted for AVETMISS profile
    Given the following "local_rtocompliance_courses" exist:
      | course   | nationallyrecognised | qualificationcode | qualificationname                    |
      | BSB50420 | 1                    | BSB50420          | Diploma of Leadership and Management |
    When I log in as "teacher1"
    And I am on "BSB50420" course homepage
    Then I should not see "Complete your AVETMISS profile"

  @javascript
  Scenario: Course settings persist after save
    Given I log in as "manager1"
    And I am on "BSB50420" course homepage
    And I navigate to "RTO Compliance Settings" in current page administration
    And I set the field "nationallyrecognised" to "1"
    And I set the field "qualificationcode" to "BSB50420"
    And I set the field "qualificationname" to "Diploma of Leadership and Management"
    And I set the field "nominalhours" to "600"
    And I press "Save changes"
    When I am on "BSB50420" course homepage
    And I navigate to "RTO Compliance Settings" in current page administration
    Then the field "nationallyrecognised" matches value "1"
    And the field "qualificationcode" matches value "BSB50420"
    And the field "qualificationname" matches value "Diploma of Leadership and Management"
    And the field "nominalhours" matches value "600"

  @javascript
  Scenario: Unmarking course removes AVETMISS requirement for students
    Given the following "local_rtocompliance_courses" exist:
      | course   | nationallyrecognised | qualificationcode |
      | BSB50420 | 1                    | BSB50420          |
    And I log in as "manager1"
    And I am on "BSB50420" course homepage
    And I navigate to "RTO Compliance Settings" in current page administration
    And I set the field "nationallyrecognised" to "0"
    And I press "Save changes"
    And I log out
    When I log in as "student1"
    And I am on "BSB50420" course homepage
    Then I should not see "Complete your AVETMISS profile"
