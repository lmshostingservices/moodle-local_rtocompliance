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
 * Private, bounded saved table views.
 *
 * Saved views deliberately use Moodle's user-preference store instead of a
 * plugin table.  A view is therefore naturally scoped to its Moodle user and
 * does not need an install/upgrade step.  The registry is the only source of
 * permitted page/query fields; this class never turns saved values into SQL.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Exception raised for a predictable saved-view request error.
 */
class saved_views_exception extends \Exception {
    /** @var string Stable, non-sensitive error code. */
    private $errorcode;

    /**
     * @param string $errorcode Stable API-facing error code.
     */
    public function __construct(string $errorcode) {
        $this->errorcode = $errorcode;
        parent::__construct($errorcode);
    }

    /**
     * @return string
     */
    public function get_errorcode(): string {
        return $this->errorcode;
    }
}

/**
 * Validation and CRUD for a user's RTO Compliance table views.
 */
class saved_views {
    /** Preference name prefix for individual view payloads. */
    public const VIEW_PREFERENCE_PREFIX = 'local_rtocompliance_saved_view_';

    /** Preference name prefix for the per-page/table manifest. */
    public const MANIFEST_PREFERENCE_PREFIX = 'local_rtocompliance_saved_views_manifest_';

    /** Maximum number of views for one page/table namespace. */
    public const MAX_VIEWS_PER_TABLE = 10;

    /** Maximum number of views for one user across all namespaces. */
    public const MAX_VIEWS_TOTAL = 50;

    /** Maximum name length in characters. */
    public const MAX_NAME_LENGTH = 60;

    /** Maximum stable client table key length in characters. */
    public const MAX_TABLE_LENGTH = 80;

    /**
     * Moodle installations commonly impose a small practical value limit on
     * user preferences. Keep a margin below a 1333-character limit.
     */
    public const MAX_PREFERENCE_BYTES = 1333;

    /** Maximum serialised state size, before wrapping it in a view payload. */
    public const MAX_STATE_BYTES = 1200;

    /** Maximum number of query parameters in one view. */
    public const MAX_QUERY_FIELDS = 40;

    /** Maximum query value size in characters. */
    public const MAX_QUERY_VALUE_LENGTH = 240;

    /**
     * Return views in a page/table namespace for a user.
     *
     * @param int $userid Owner.
     * @param string $page Registered page basename.
     * @param string $table Stable client table key.
     * @return array<int, array{id:string,name:string,state:array}>
     */
    public static function list_views(int $userid, string $page, string $table): array {
        self::validate_userid($userid);
        [$page, $table] = self::validate_namespace($page, $table);

        $manifest = self::read_manifest($userid, $page, $table);
        $views = [];
        $changed = false;

        foreach ($manifest as $entry) {
            $id = $entry['id'];
            $raw = get_user_preferences(self::view_preference_name($page, $table, $id), null, $userid);
            if ($raw === null) {
                // A manually purged or truncated preference must not become a
                // permanently visible manifest entry.
                $changed = true;
                continue;
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload) || !isset($payload['name'], $payload['state']) ||
                    !is_string($payload['name']) || !is_array($payload['state'])) {
                $changed = true;
                continue;
            }

            try {
                $name = self::normalise_name($payload['name']);
                $state = self::normalise_state($page, $payload['state']);
            } catch (saved_views_exception $exception) {
                $changed = true;
                continue;
            }

            $views[] = [
                'id' => $id,
                'name' => $name,
                'state' => $state,
            ];
        }

        if ($changed) {
            self::write_manifest($userid, $page, $table, array_map(
                static function (array $view) {
                    return ['id' => $view['id']];
                },
                $views
            ));
        }

        usort($views, static function (array $left, array $right): int {
            $byname = strcasecmp($left['name'], $right['name']);
            return $byname !== 0 ? $byname : strcmp($left['id'], $right['id']);
        });

        return array_values($views);
    }

    /**
     * Save a new view or update one in this user's namespace.
     *
     * @param int $userid Owner.
     * @param string $page Registered page basename.
     * @param string $table Stable client table key.
     * @param string $name Display name.
     * @param array $state Normalised state object.
     * @param string|null $id Existing opaque id, or null to create.
     * @return string Opaque view id.
     */
    public static function save_view(
        int $userid,
        string $page,
        string $table,
        string $name,
        array $state,
        ?string $id = null
    ): string {
        global $DB;

        self::validate_userid($userid);
        [$page, $table] = self::validate_namespace($page, $table);
        $name = self::normalise_name($name);
        $state = self::normalise_state($page, $state);

        $manifest = self::read_manifest($userid, $page, $table);
        $existingindex = null;
        if ($id !== null) {
            self::validate_id($id);
            foreach ($manifest as $index => $entry) {
                if ($entry['id'] === $id) {
                    $existingindex = $index;
                    break;
                }
            }
            if ($existingindex === null) {
                throw new saved_views_exception('not_found');
            }
        } else {
            if (count($manifest) >= self::MAX_VIEWS_PER_TABLE) {
                throw new saved_views_exception('table_limit');
            }
            if (self::count_user_views($userid) >= self::MAX_VIEWS_TOTAL) {
                throw new saved_views_exception('total_limit');
            }
            $id = self::new_id($userid, $page, $table, $manifest);
        }
        if (self::name_exists($userid, $page, $table, $manifest, $name, $id)) {
            throw new saved_views_exception('duplicate_name');
        }

        $payload = json_encode(
            ['name' => $name, 'state' => $state],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (strlen($payload) > self::MAX_PREFERENCE_BYTES) {
            throw new saved_views_exception('state_too_large');
        }

        if ($existingindex === null) {
            $manifest[] = ['id' => $id];
        }

        // User preference writes are regular database writes. Keep both halves
        // of a save together so an interrupted request cannot leave a newly
        // advertised view without its state.
        $transaction = $DB->start_delegated_transaction();
        try {
            set_user_preference(self::view_preference_name($page, $table, $id), $payload, $userid);
            self::write_manifest($userid, $page, $table, $manifest);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
            throw new saved_views_exception('storage');
        }

        return $id;
    }

    /**
     * Delete a view belonging to this user and exact page/table namespace.
     *
     * @param int $userid Owner.
     * @param string $page Registered page basename.
     * @param string $table Stable client table key.
     * @param string $id Opaque id.
     * @return void
     */
    public static function delete_view(int $userid, string $page, string $table, string $id): void {
        global $DB;

        self::validate_userid($userid);
        [$page, $table] = self::validate_namespace($page, $table);
        self::validate_id($id);
        $manifest = self::read_manifest($userid, $page, $table);
        $kept = [];
        $found = false;
        foreach ($manifest as $entry) {
            if ($entry['id'] === $id) {
                $found = true;
                continue;
            }
            $kept[] = $entry;
        }
        if (!$found) {
            throw new saved_views_exception('not_found');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            unset_user_preference(self::view_preference_name($page, $table, $id), $userid);
            if ($kept) {
                self::write_manifest($userid, $page, $table, $kept);
            } else {
                unset_user_preference(self::manifest_preference_name($page, $table), $userid);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
            throw new saved_views_exception('storage');
        }
    }

    /**
     * Validate and canonicalise the state object accepted by the endpoint.
     *
     * Query keys must be explicitly registered for the page. Sort column is a
     * bounded display/header identity, not a SQL fragment.
     *
     * @param string $page Registered page basename.
     * @param array $state Decoded state.
     * @return array{query:array<string,string>,sort:array{column:string,direction:string}|null}
     */
    public static function normalise_state(string $page, array $state): array {
        $page = self::validate_page($page);
        $statekeys = array_keys($state);
        if (count($statekeys) !== 2 || count(array_diff(['query', 'sort'], $statekeys)) !== 0) {
            throw new saved_views_exception('invalid_state');
        }
        foreach ($statekeys as $key) {
            if (!is_string($key) || !in_array($key, ['query', 'sort'], true)) {
                throw new saved_views_exception('invalid_state');
            }
        }
        if (!array_key_exists('query', $state) || !is_array($state['query'])) {
            throw new saved_views_exception('invalid_query');
        }
        if (!array_key_exists('sort', $state) || ($state['sort'] !== null && !is_array($state['sort']))) {
            throw new saved_views_exception('invalid_sort');
        }

        $allowed = array_fill_keys(self::registered_fields($page), true);
        $query = [];
        if (count($state['query']) > self::MAX_QUERY_FIELDS) {
            throw new saved_views_exception('query_too_large');
        }
        foreach ($state['query'] as $key => $value) {
            if (!is_string($key) || self::is_sensitive_name($key) || !isset($allowed[$key])) {
                throw new saved_views_exception('invalid_query');
            }
            if (!is_string($value) || self::string_length($value) > self::MAX_QUERY_VALUE_LENGTH) {
                throw new saved_views_exception('invalid_query');
            }
            if (!self::is_safe_text($value)) {
                throw new saved_views_exception('invalid_query');
            }
            $query[$key] = $value;
        }
        ksort($query);

        $sort = null;
        if ($state['sort'] !== null) {
            $sortkeys = array_keys($state['sort']);
            if (count($sortkeys) !== 2 ||
                    count(array_diff(['column', 'direction'], $sortkeys)) !== 0 ||
                    !isset($state['sort']['column'], $state['sort']['direction']) ||
                    !is_string($state['sort']['column']) ||
                    !is_string($state['sort']['direction'])) {
                throw new saved_views_exception('invalid_sort');
            }
            $column = $state['sort']['column'];
            $direction = $state['sort']['direction'];
            $column = trim(preg_replace('/\s+/u', ' ', $column) ?? '');
            $columncomparison = preg_replace('/[^a-z0-9_-]/i', '', $column);
            if ($column === '' || self::string_length($column) > 160 ||
                    !self::is_safe_text($column) ||
                    self::is_sensitive_name((string)$columncomparison)) {
                throw new saved_views_exception('invalid_sort');
            }
            if ($direction !== 'asc' && $direction !== 'desc') {
                throw new saved_views_exception('invalid_sort');
            }
            $sort = ['column' => $column, 'direction' => $direction];
        }

        $normalised = ['query' => $query, 'sort' => $sort];
        self::encode_state($normalised);
        return $normalised;
    }

    /**
     * Sanitise a client table key into a stable namespace component.
     *
     * @param string $table Client-provided key.
     * @return string Sanitised key, or an empty string if no key remains.
     */
    public static function sanitise_table_key(string $table): string {
        $table = strtolower(trim($table));
        if (strlen($table) > self::MAX_TABLE_LENGTH ||
                !preg_match('/^[a-z0-9:_-]{1,80}$/D', $table)) {
            return '';
        }
        return $table;
    }

    /**
     * US-English alias for callers that use the API contract wording.
     *
     * @param string $table Client-provided key.
     * @return string Sanitised key, or an empty string if no key remains.
     */
    public static function sanitize_table_key(string $table): string {
        return self::sanitise_table_key($table);
    }

    /**
     * Accept only the three documented actions after normal form decoding.
     *
     * @param string $action Request action.
     * @return string
     */
    public static function validate_action(string $action): string {
        if (!in_array($action, ['list', 'save', 'delete'], true)) {
            throw new saved_views_exception('invalid_request');
        }
        return $action;
    }

    /**
     * Return all preference names belonging to this plugin's saved views.
     *
     * Used by the Privacy API because per-view/per-table preference names
     * contain hashes and cannot be declared as one fixed preference name.
     *
     * @param int $userid User id.
     * @return string[]
     */
    public static function user_preference_names(int $userid): array {
        global $DB;
        self::validate_userid($userid);
        // v6.3.32: both prefixes are full of underscores, which are LIKE
        // wildcards. sql_like_escape() alone is not enough - a hand-written
        // "name LIKE :x" carries no ESCAPE clause, so the escape character is
        // whatever the engine happens to default to. $DB->sql_like() emits the
        // clause the current driver needs, so use it rather than raw LIKE.
        $viewprefix = $DB->sql_like_escape(self::VIEW_PREFERENCE_PREFIX) . '%';
        $manifestprefix = $DB->sql_like_escape(self::MANIFEST_PREFERENCE_PREFIX) . '%';
        $likeview = $DB->sql_like('name', ':viewprefix');
        $likemanifest = $DB->sql_like('name', ':manifestprefix');
        $records = $DB->get_records_select(
            'user_preferences',
            "userid = :userid AND ($likeview OR $likemanifest)",
            [
                'userid' => $userid,
                'viewprefix' => $viewprefix,
                'manifestprefix' => $manifestprefix,
            ],
            '',
            'name'
        );
        $names = [];
        foreach ($records as $record) {
            $names[] = (string)$record->name;
        }
        return $names;
    }

    /**
     * Remove all saved-view preferences for one user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function delete_user_preferences(int $userid): void {
        foreach (self::user_preference_names($userid) as $name) {
            unset_user_preference($name, $userid);
        }
    }

    /**
     * Check whether a registered page is accessible to the current user.
     *
     * A null capability is intentional: the registry has explicitly declared
     * the page as login-only. There is no generic staff fallback here.
     *
     * @param string $page Registered page basename.
     * @return bool
     */
    public static function can_access_page(string $page): bool {
        try {
            $page = self::validate_page($page);
            $registry = saved_view_pages::class;
            if (method_exists($registry, 'can_access')) {
                return (bool)$registry::can_access($page);
            }
            if (!method_exists($registry, 'capability')) {
                return false;
            }
            $capability = $registry::capability($page);
            if ($capability === null || $capability === '') {
                return true;
            }
            return has_capability($capability, \context_system::instance());
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Whether a page basename is explicitly registered for saved views.
     *
     * @param string $page Candidate page basename.
     * @return bool
     */
    public static function is_supported_page(string $page): bool {
        try {
            self::validate_page($page);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * @param string $page Page basename.
     * @param string $table Table key.
     * @return array{0:string,1:string}
     */
    private static function validate_namespace(string $page, string $table): array {
        return [self::validate_page($page), self::validate_table($table)];
    }

    /**
     * @param string $page Page basename.
     * @return string
     */
    private static function validate_page(string $page): string {
        $page = trim($page);
        if ($page === '' || strlen($page) > self::MAX_TABLE_LENGTH ||
                !preg_match('/^[A-Za-z0-9_-]+(?:\.php)?$/iD', $page)) {
            throw new saved_views_exception('invalid_page');
        }
        $page = strtolower($page);
        if (substr($page, -4) !== '.php') {
            $page .= '.php';
        }
        if (!class_exists(saved_view_pages::class) || !saved_view_pages::supported($page)) {
            throw new saved_views_exception('unsupported_page');
        }
        return $page;
    }

    /**
     * @param string $table Table key.
     * @return string
     */
    private static function validate_table(string $table): string {
        $table = self::sanitise_table_key($table);
        if ($table === '') {
            throw new saved_views_exception('invalid_table');
        }
        return $table;
    }

    /**
     * @param int $userid User id.
     * @return void
     */
    private static function validate_userid(int $userid): void {
        if ($userid < 1) {
            throw new saved_views_exception('invalid_user');
        }
    }

    /**
     * @param string $page Page.
     * @return string[]
     */
    private static function registered_fields(string $page): array {
        if (!class_exists(saved_view_pages::class)) {
            throw new saved_views_exception('unsupported_page');
        }
        $fields = saved_view_pages::fields($page);
        if (!is_array($fields)) {
            throw new saved_views_exception('unsupported_page');
        }
        $safe = [];
        foreach ($fields as $fieldkey => $fieldvalue) {
            // The registry contract uses a list, but tolerate the equivalent
            // field => label form so the backend stays aligned with the
            // footer's defensive handling of registry data.
            $field = is_int($fieldkey) ? $fieldvalue : $fieldkey;
            if (is_string($field) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $field) &&
                    !self::is_sensitive_name($field)) {
                $safe[] = $field;
            }
        }
        return array_values(array_unique($safe));
    }

    /**
     * @param string $name Candidate sensitive name.
     * @return bool
     */
    private static function is_sensitive_name(string $name): bool {
        $name = strtolower($name);
        $blocked = [
            'action', 'sesskey', 'id', 'export', 'context', 'contextid',
            'targetuserid', 'userid', 'userids', 'token', 'wstoken',
            'password', 'passwd', 'secret', 'apikey', 'api_key',
        ];
        if (in_array($name, $blocked, true)) {
            return true;
        }
        if (preg_match('/(?:^|[_-])(?:target)?userids?(?:$|[_-])/', $name)) {
            return true;
        }
        return (bool)preg_match('/(?:^|[_-])(token|secret|password|passwd|apikey|sesskey)(?:$|[_-])/', $name);
    }

    /**
     * @param string $name Display name.
     * @return string
     */
    private static function normalise_name(string $name): string {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || self::string_length($name) > self::MAX_NAME_LENGTH || !self::is_safe_text($name)) {
            throw new saved_views_exception('invalid_name');
        }
        return $name;
    }

    /**
     * @param string $id Opaque id.
     * @return void
     */
    private static function validate_id(string $id): void {
        if (!preg_match('/^[a-f0-9]{12}$/D', $id)) {
            throw new saved_views_exception('invalid_id');
        }
    }

    /**
     * @param int $userid User id.
     * @return int
     */
    private static function count_user_views(int $userid): int {
        global $DB;
        $prefix = $DB->sql_like_escape(self::VIEW_PREFERENCE_PREFIX) . '%';
        $like = $DB->sql_like('name', ':prefix');
        return (int)$DB->count_records_select(
            'user_preferences',
            "userid = :userid AND $like",
            ['userid' => $userid, 'prefix' => $prefix]
        );
    }

    /**
     * Check for an existing view name in one page/table namespace.
     *
     * @param int $userid Owner.
     * @param string $page Canonical page.
     * @param string $table Canonical table.
     * @param array $manifest View metadata.
     * @param string $name Candidate name.
     * @param string $excludeid Current id on update.
     * @return bool
     */
    private static function name_exists(
        int $userid,
        string $page,
        string $table,
        array $manifest,
        string $name,
        string $excludeid
    ): bool {
        $wanted = self::name_comparison_key($name);
        foreach ($manifest as $entry) {
            if ($entry['id'] === $excludeid) {
                continue;
            }
            $raw = get_user_preferences(self::view_preference_name($page, $table, $entry['id']), null, $userid);
            if (!is_string($raw)) {
                continue;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload) || !isset($payload['name']) || !is_string($payload['name'])) {
                continue;
            }
            try {
                $stored = self::normalise_name($payload['name']);
            } catch (saved_views_exception $exception) {
                continue;
            }
            if (self::name_comparison_key($stored) === $wanted) {
                return true;
            }
        }
        return false;
    }

    /**
     * Case-insensitive, Unicode-aware comparison key for unique names.
     *
     * @param string $name Normalised display name.
     * @return string
     */
    private static function name_comparison_key(string $name): string {
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    /**
     * Generate an opaque id and guard against a random collision.
     *
     * @param int $userid User id.
     * @param string $page Page.
     * @param string $table Table.
     * @param array $manifest Existing metadata.
     * @return string
     */
    private static function new_id(int $userid, string $page, string $table, array $manifest): string {
        $existing = array_column($manifest, 'id');
        do {
            $id = bin2hex(random_bytes(6));
        } while (in_array($id, $existing, true) ||
                get_user_preferences(self::view_preference_name($page, $table, $id), null, $userid) !== null);
        return $id;
    }

    /**
     * @param int $userid User.
     * @param string $page Page.
     * @param string $table Table.
     * @return array<int,array{id:string}>
     */
    private static function read_manifest(int $userid, string $page, string $table): array {
        $raw = get_user_preferences(self::manifest_preference_name($page, $table), '', $userid);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['views']) || !is_array($decoded['views'])) {
            return [];
        }
        $manifest = [];
        foreach ($decoded['views'] as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id'])) {
                continue;
            }
            try {
                self::validate_id($entry['id']);
                $manifest[] = ['id' => $entry['id']];
            } catch (saved_views_exception $exception) {
                continue;
            }
        }
        return array_slice($manifest, 0, self::MAX_VIEWS_PER_TABLE);
    }

    /**
     * @param int $userid User.
     * @param string $page Page.
     * @param string $table Table.
     * @param array $manifest Metadata.
     * @return void
     */
    private static function write_manifest(int $userid, string $page, string $table, array $manifest): void {
        $payload = json_encode(
            ['v' => 1, 'views' => array_values($manifest)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (strlen($payload) > self::MAX_PREFERENCE_BYTES) {
            throw new saved_views_exception('manifest_too_large');
        }
        set_user_preference(self::manifest_preference_name($page, $table), $payload, $userid);
    }

    /**
     * @param string $page Page.
     * @param string $table Table.
     * @return string
     */
    private static function manifest_preference_name(string $page, string $table): string {
        return self::MANIFEST_PREFERENCE_PREFIX . hash('sha256', $page . "\0" . $table);
    }

    /**
     * @param string $page Page.
     * @param string $table Table.
     * @param string $id View id.
     * @return string
     */
    private static function view_preference_name(string $page, string $table, string $id): string {
        return self::VIEW_PREFERENCE_PREFIX . hash('sha256', $page . "\0" . $table . "\0" . $id);
    }

    /**
     * @param array $state State.
     * @return string
     */
    private static function encode_state(array $state): string {
        try {
            $encoded = json_encode(
                $state,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new saved_views_exception('invalid_state');
        }
        if (strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new saved_views_exception('state_too_large');
        }
        return $encoded;
    }

    /**
     * @param string $value Text.
     * @return bool
     */
    private static function is_safe_text(string $value): bool {
        return preg_match('//u', $value) === 1 &&
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * @param string $value Text.
     * @return int
     */
    private static function string_length(string $value): int {
        return class_exists('core_text') ? \core_text::strlen($value) : strlen($value);
    }
}