# local_rtocompliance — release checklist

Follow every step in order. Do not skip steps on patch releases.  
Global rules that apply to all plugins live in `moodle-plugin/MOODLE_PLUGIN_CHECKLIST.md`.

---

## 1. Bump `version.php`

```php
$plugin->version  = YYYYMMDDNNN;   // date + 3-digit sequence (e.g. 2026073000325)
$plugin->release  = '5.9.NNN';     // prepend newest entry; keep prior entry as $plugin->release_prev
$plugin->release_prev = '5.9.NNN-1';  // the version you are replacing — NOT the one before that
```

**Trap:** there are dozens of `$plugin->release_prev = ...` lines in this file. PHP is
last-assignment-wins — the LOWEST `release_prev` line in the file is what Moodle reads.
Insert your new `release` line and update **only the immediately-preceding** `release_prev`
to the version you are replacing. Do NOT add a second `release_prev` line.

Version number format: `YYYYMMDDNNN`
- `YYYYMMDD` = today's date
- `NNN` = 3-digit sequence starting at `001`; increment within the same day (e.g. `001`, `002`)

---

## 2. Add a savepoint to `db/upgrade.php` (only for DB schema changes)

If your change only modifies PHP page output, lang strings, or CSS — **skip this step**.
Otherwise:

```php
    if ($oldversion < YYYYMMDDNNN) {
        // One-line description of what changed.
        upgrade_plugin_savepoint(true, YYYYMMDDNNN, 'local', 'rtocompliance');
    }

    return true;
```

Rules (see MOODLE_PLUGIN_CHECKLIST.md §2 for full detail):
- The new block must be **last** — placed immediately before `return true;`
- The version number must be **identical** to `$plugin->version`
- Use the Edit tool — never `cat >>` (appends outside the function → ParseError)

Verify ascending order after every edit:
```bash
grep "upgrade_plugin_savepoint" moodle-plugin/local_rtocompliance/db/upgrade.php \
  | grep -oP "\d{13}" | sort -nc
# Must exit 0 with no output
```

---

## 3. Update `BUILD_INFO.json`

```json
{
  "component": "local_rtocompliance",
  "version": "5.9.NNN",
  "numeric_version": "YYYYMMDDNNN"
}
```

---

## 4. Update `server/routes.ts`

Change the `zipFile:` value for the `local_rtocompliance` entry:
```ts
zipFile: 'local_rtocompliance_v5.9.NNN.zip',
```

The `folder:` value stays `'local_rtocompliance'` — never change it.

---

## 5. Update `client/src/lib/pluginConfig.ts`

- Bump `version: "5.9.NNN"`.
- Prepend a one-line entry to the `changelog` array:
  ```
  "v5.9.NNN - TYPE (DD Mon YYYY): one-line summary",
  ```

**Trap:** changelog strings must use single quotes for any inner quotation marks. A bare
double-quote inside the string causes a Vite build failure (`Expected "]" but found "Word"`).

---

## 6. Rebuild AMD JS (only if any `amd/src/*.js` changed)

This plugin does **not** have a rollup config — copy files manually:

```bash
# For each changed file in amd/src/:
cp moodle-plugin/local_rtocompliance/amd/src/qualbuilder_edit.js \
   moodle-plugin/local_rtocompliance/amd/build/qualbuilder_edit.js
cp moodle-plugin/local_rtocompliance/amd/src/qualbuilder_edit.js \
   moodle-plugin/local_rtocompliance/amd/build/qualbuilder_edit.min.js
# Repeat for cert_template_editor.js, nominalhours_autofill.js, etc.
```

AMD modules must use **named** `define()`:
```javascript
// CORRECT
define('local_rtocompliance/qualbuilder_edit', ['jquery', ...], function($, ...) { ... });
// WRONG — anonymous define corrupts Moodle's combo-loader
define(['jquery', ...], function($, ...) { ... });
```

---

## 7. PHP lint — every changed file

```bash
find moodle-plugin/local_rtocompliance -name "*.php" -newer moodle-plugin/local_rtocompliance/version.php \
  | xargs -P4 php -l 2>&1 | grep -v "^No syntax errors"
# Must produce no output.
```

Or lint specific files:
```bash
php -l moodle-plugin/local_rtocompliance/db/upgrade.php
php -l moodle-plugin/local_rtocompliance/version.php
```

**Trap:** `XMLDB_NOTNULL_FALSE` is not a valid constant — `php -l` won't catch it but Moodle
will crash at runtime. Use `null` for the notnull param of xmldb_field.

---

## 8. Build the ZIP

```bash
PREV_VERSION="5.9.NNN-1"
NEW_VERSION="5.9.NNN"

# Delete stale ZIPs first (BOTH locations)
rm -f "moodle-plugin/local_rtocompliance_v${PREV_VERSION}.zip"
rm -f "public/downloads/local_rtocompliance_v${PREV_VERSION}.zip"

# Build from /tmp — never from workspace root
BUILD_TMP=/tmp/rtocompliance_build_$$
rm -rf "$BUILD_TMP" && mkdir -p "$BUILD_TMP"
cp -r moodle-plugin/local_rtocompliance "$BUILD_TMP/local_rtocompliance"
cd "$BUILD_TMP"
zip -q -r "/tmp/local_rtocompliance_v${NEW_VERSION}.zip" local_rtocompliance \
    -x "*.DS_Store" -x "__MACOSX/*"
mv "/tmp/local_rtocompliance_v${NEW_VERSION}.zip" \
   "/home/runner/workspace/moodle-plugin/local_rtocompliance_v${NEW_VERSION}.zip"
cp "/home/runner/workspace/moodle-plugin/local_rtocompliance_v${NEW_VERSION}.zip" \
   "/home/runner/workspace/public/downloads/local_rtocompliance_v${NEW_VERSION}.zip"
rm -rf "$BUILD_TMP"
cd /home/runner/workspace
```

---

## 9. Verify the ZIP

```python
python3 -c "
import zipfile
z = zipfile.ZipFile('public/downloads/local_rtocompliance_v5.9.NNN.zip')
tops = sorted(set(n.split('/')[0] for n in z.namelist()))
bad  = [n for n in z.namelist() if not n.startswith('local_rtocompliance')]
print('Top-level:', tops)
print('Bad entries:', bad if bad else 'NONE')
"
```

`Top-level: ['local_rtocompliance']` and `Bad entries: NONE` are the only acceptable results.

---

## 10. Restart the server

`server/routes.ts` changed (new ZIP filename). Restart the **Start application** workflow,
then confirm:

```bash
curl -s "http://localhost:5000/api/downloads/rtocompliance" -o /dev/null -w "%{http_code}"
# Must print 200
```

---

## 11. Stale-reference check

```bash
# Old version must not appear in routes or config (changelog entries are OK)
grep -n "v5.9.${PREV_VERSION}" server/routes.ts client/src/lib/pluginConfig.ts \
  | grep -v changelog
# Must produce no output.
```

---

## Plugin-specific traps

| Trap | Symptom | Fix |
|---|---|---|
| Multiple `$plugin->release_prev` lines at bottom | PHP last-assignment-wins → Moodle shows wrong version | Only the first (topmost) `release_prev` line is the one Moodle reads; keep older ones as PHP comments embedded in the string |
| `cat >>` to upgrade.php | `ParseError: Unmatched '}'` | Always use the Edit tool, insert before `return true;` |
| Savepoint block out of ascending order | "Cannot downgrade" site-wide upgrade abort | Always append as the last block |
| `XMLDB_NOTNULL_FALSE` used in xmldb_field | Runtime crash — NOT caught by `php -l` | Use `null` for the notnull param |
| Hook listener doesn't `require_once lib.php` | "Call to undefined function" at hook fire time | `require_once __DIR__ . '/../../lib.php';` as first line of every hook method |
| Anonymous `define()` in amd/build/ | Nav collapses site-wide | Named `define('local_rtocompliance/module', ...)` |
| ZIP built from workspace root | Output file self-embeds (partial copy inside itself) | Always build from `/tmp` |
