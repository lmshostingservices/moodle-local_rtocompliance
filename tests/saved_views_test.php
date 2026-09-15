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
 * Saved table-view security and lifecycle tests.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

use local_rtocompliance\local\saved_views;
use local_rtocompliance\local\saved_views_exception;
use local_rtocompliance\local\saved_view_pages;
use local_rtocompliance\privacy\provider;
use core_privacy\local\request\approved_contextlist;

/**
 * @coversDefaultClass \local_rtocompliance\local\saved_views
 */
final class saved_views_test extends \advanced_testcase {
    /** @var string */
    private $page;

    /** @var string */
    private $field;

    /**
     * Pick an actual page/registered field from the registry under test.
     *
     * This keeps the tests aligned with the page registry owned by the
     * navigation/UI layer instead of duplicating a production allow-list.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $pages = saved_view_pages::pages();
        foreach ($pages as $page => $fields) {
            // Accept the registry's canonical page=>fields contract and the
            // temporary key-only shape while the registry is being migrated.
            if (is_string($fields)) {
                $page = $fields;
                $fields = saved_view_pages::fields($page);
            }
            if (is_array($fields) && saved_view_pages::supported((string)$page)) {
                $this->page = (string)$page;
                $this->field = (string)reset($fields);
                break;
            }
        }
        if (empty($this->page)) {
            $this->markTestSkipped('The saved-view registry has no test page yet.');
        }
    }

    /**
     * @return array{query:array<string,string>,sort:array{column:string,direction:string}|null}
     */
    private function state(string $value = 'active'): array {
        return [
            'query' => [$this->field => $value],
            'sort' => ['column' => $this->field, 'direction' => 'asc'],
        ];
    }

    /**
     * Sanitisation must produce only a bounded namespace component.
     */
    public function test_table_key_is_sanitised_and_bounded(): void {
        $key = saved_views::sanitise_table_key('reports:main-table_1');

        $this->assertLessThanOrEqual(saved_views::MAX_TABLE_LENGTH, strlen($key));
        $this->assertSame('reports:main-table_1', $key);
        $this->assertMatchesRegularExpression('/^[a-z0-9:_-]+$/', $key);
        $this->assertSame('', saved_views::sanitise_table_key("reports/main"));
        $this->assertSame('', saved_views::sanitise_table_key(str_repeat('a', 81)));
    }

    /**
     * Table namespace keys over the contract bound must be rejected, not
     * truncated into a different table's namespace.
     */
    public function test_long_table_key_is_rejected(): void {
        $user = $this->getDataGenerator()->create_user();
        try {
            saved_views::save_view($user->id, $this->page, str_repeat('a', 81), 'Long', $this->state());
            $this->fail('A table key over 80 characters must not be truncated.');
        } catch (saved_views_exception $exception) {
            $this->assertSame('invalid_table', $exception->get_errorcode());
        }
    }

    /**
     * Form encoding must not create a second action vocabulary.
     */
    public function test_encoded_action_is_decoded_then_allowlisted(): void {
        $this->assertSame('save', saved_views::validate_action(urldecode('%73ave')));
        $this->expectException(saved_views_exception::class);
        saved_views::validate_action('save%00');
    }

    /**
     * Query keys are an allow-list, not a free-form URL/query-string store.
     */
    public function test_malicious_query_keys_and_nested_values_are_rejected(): void {
        $user = $this->getDataGenerator()->create_user();

        $state = $this->state();
        $state['query']['action'] = 'delete';
        $this->expectException(saved_views_exception::class);
        saved_views::save_view($user->id, $this->page, 'records', 'Bad', $state);
    }

    /**
     * State and names have explicit limits, while valid Unicode names remain
     * user-visible and are not byte-truncated into invalid UTF-8.
     */
    public function test_state_and_name_bounds_are_enforced(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->expectException(saved_views_exception::class);
        saved_views::save_view(
            $user->id,
            $this->page,
            'records',
            str_repeat('x', saved_views::MAX_NAME_LENGTH + 1),
            $this->state()
        );
    }

    /**
     * Create/update/delete is isolated by owner and page/table namespace.
     */
    public function test_lifecycle_and_ownership_isolation(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $id = saved_views::save_view($owner->id, $this->page, 'records', 'Active records', $this->state());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $id);
        $this->assertCount(1, saved_views::list_views($owner->id, $this->page, 'records'));
        $this->assertSame([], saved_views::list_views($other->id, $this->page, 'records'));
        $this->assertSame([], saved_views::list_views($owner->id, $this->page, 'other'));

        $updated = $this->state('completed');
        saved_views::save_view($owner->id, $this->page, 'records', 'Completed records', $updated, $id);
        $views = saved_views::list_views($owner->id, $this->page, 'records');
        $this->assertSame('Completed records', $views[0]['name']);
        $this->assertSame('completed', $views[0]['state']['query'][$this->field]);

        try {
            saved_views::delete_view($other->id, $this->page, 'records', $id);
            $this->fail('A user must not be able to delete another user\'s opaque id.');
        } catch (saved_views_exception $exception) {
            $this->assertSame('not_found', $exception->get_errorcode());
        }

        saved_views::delete_view($owner->id, $this->page, 'records', $id);
        $this->assertSame([], saved_views::list_views($owner->id, $this->page, 'records'));
    }

    /**
     * Names are unique within a user's exact page/table namespace, while
     * another table or another user may use the same display name.
     */
    public function test_duplicate_names_and_update_exception(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $id = saved_views::save_view($owner->id, $this->page, 'records', 'Active', $this->state());
        try {
            saved_views::save_view($owner->id, $this->page, 'records', 'active', $this->state('other'));
            $this->fail('Names must be unique case-insensitively within a namespace.');
        } catch (saved_views_exception $exception) {
            $this->assertSame('duplicate_name', $exception->get_errorcode());
        }
        saved_views::save_view($owner->id, $this->page, 'records', 'Active', $this->state('updated'), $id);
        saved_views::save_view($owner->id, $this->page, 'other-table', 'Active', $this->state());
        saved_views::save_view($other->id, $this->page, 'records', 'Active', $this->state());
    }

    /**
     * LIKE metacharacters in preference prefixes must not erase lookalikes,
     * and an unapproved user context must not authorize erasure.
     */
    public function test_preference_prefixes_and_context_approval(): void {
        $user = $this->getDataGenerator()->create_user();
        saved_views::save_view($user->id, $this->page, 'records', 'Prefix', $this->state());
        $lookalike = 'localXrtocompliance_saved_view_lookalike';
        set_user_preference($lookalike, 'keep', $user->id);

        $names = saved_views::user_preference_names($user->id);
        $this->assertNotContains($lookalike, $names);

        // A user-context approval is not sufficient for system-scoped data.
        $usercontext = \context_user::instance($user->id);
        $unapproved = new approved_contextlist($user, 'local_rtocompliance', [$usercontext->id]);
        provider::delete_data_for_user($unapproved);
        $this->assertCount(1, saved_views::list_views($user->id, $this->page, 'records'));
        $this->assertSame('keep', get_user_preferences($lookalike, null, $user->id));

        $approved = new approved_contextlist(
            $user,
            'local_rtocompliance',
            [\context_system::instance()->id]
        );
        provider::delete_data_for_user($approved);
        $this->assertSame([], saved_views::list_views($user->id, $this->page, 'records'));
        $this->assertSame('keep', get_user_preferences($lookalike, null, $user->id));
        $this->assertNotContains(
            $lookalike,
            saved_views::user_preference_names($user->id)
        );
    }

    /**
     * Both per-table and per-user limits must be enforced.
     */
    public function test_per_table_and_total_bounds(): void {
        $user = $this->getDataGenerator()->create_user();

        for ($index = 0; $index < saved_views::MAX_VIEWS_PER_TABLE; $index++) {
            saved_views::save_view(
                $user->id,
                $this->page,
                'table',
                'View ' . $index,
                $this->state((string)$index)
            );
        }
        try {
            saved_views::save_view($user->id, $this->page, 'table', 'Too many', $this->state());
            $this->fail('The per-table limit must be enforced.');
        } catch (saved_views_exception $exception) {
            $this->assertSame('table_limit', $exception->get_errorcode());
        }

        // The first table already contains ten views; eight more tables with
        // five views each reach exactly the global limit.
        for ($table = 0; $table < 8; $table++) {
            for ($index = 0; $index < 5; $index++) {
                saved_views::save_view(
                    $user->id,
                    $this->page,
                    'table-' . $table,
                    'View ' . $index,
                    $this->state((string)$index)
                );
            }
        }
        try {
            saved_views::save_view($user->id, $this->page, 'last', 'Too many overall', $this->state());
            $this->fail('The global limit must be enforced.');
        } catch (saved_views_exception $exception) {
            $this->assertSame('total_limit', $exception->get_errorcode());
        }
    }
}
