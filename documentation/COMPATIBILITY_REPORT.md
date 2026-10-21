# COMPATIBILITY_REPORT.md — mamabe module (PHP 5.6 → PHP 8.2.12)

## Scope

All `.php` files directly under `/odb/mamabe/` (21 files). The two legacy backup
files `mamabe_mama_detail.php-lama` and `mamabe_mama_list.php-lama` were left
untouched (not referenced by any include chain — dead backups, out of scope).
Shared includes (`../common/index_adv.php`, `../lock_adv.php`) are outside this
module's boundary and were not modified, only read for context.

## Method

1. Ran `php -l` (PHP 8.2.12 CLI, found at
   `C:\laragon\bin\php\php-8.2.12-nts-Win32-vs16-x64\php.exe`) against every file
   — zero fatal parse errors, before and after changes.
2. Grepped for hard-removed/deprecated PHP 5.6-era functions
   (`mysql_*`, `each()`, `create_function()`, `ereg()`/`split()`,
   `get_magic_quotes_gpc()`, `$str{0}` offsets, `(unset)`/`(real)` casts,
   `session_register()`, `$HTTP_*_VARS`) — **none found**. The module already
   uses `mysqli` exclusively.
3. Line-by-line review of every file for PHP 8.1/8.2 deprecations and PHP 8
   fatal-error changes (see checklist below).

## Findings by category

### 1. Passing `null` to non-nullable internal function parameters (deprecated, PHP 8.1+)
The dominant issue class in this codebase. Pattern: `trim($_POST['x'])`,
`(int)$_POST['x']`, `htmlspecialchars($row['col'])`, `explode(..., $row['col'])`
etc. called without an `isset()`/`?? ''` guard. When the key is missing (malformed
POST, legacy NULL DB column, `LEFT JOIN` producing NULL, third-party API response
missing a field), PHP 8 raises `Warning: Undefined array key` followed by
`Deprecated: <func>(): Passing null to parameter #N ... is deprecated`. Non-fatal
by default, but noisy, and risky wherever it precedes a `header('Location: ...')`
redirect (see category 4) or feeds an AJAX/JSON response.

**Fixed** — see `FINAL_UPGRADE_SUMMARY.md` for the full per-file line list.

### 2. `rand()` / `mt_rand()` `ValueError` on `min > max` (PHP 8 — fatal, new behavior)
`mamabe_blast_export.php` computed `send_min`/`send_max` and `pic_min`/`pic_max`
independently clamped but never compared against each other, then called
`rand($min, $max)`. On PHP 5.6–7.x, `min > max` silently returned a nonsensical
value; **on PHP 8, this throws an uncaught `ValueError`** and crashes the export.
**Fixed**: bounds are now swapped if `min > max` before use.

### 3. `header()`/redirect corruption risk from inline deprecation notices
Where an unguarded `$_POST` read (category 1) precedes a `header('Location: ...')`
call, a printed PHP 8 deprecation notice (if `display_errors=On` in the target
environment) would occur before the header call and break the redirect
("headers already sent"), leaving the user on a warning-littered page instead
of being routed forward. Concrete instances: `mamabe_add_campaign.php` (before
line 55's redirect), `mamabe_mama_add.php` Tab A/B (before their redirects),
`mamabe_mama_detail.php`'s `update_mama`/`add_baby` handlers.
**Fixed** by removing the underlying unguarded reads (category 1 fix also
resolves this).

### 4. AJAX/plain-text/JSON endpoint corruption risk
`mamabe_ajax.php` builds a pipe-delimited plain-text response from
`stripslashes($row[...])` on nullable `customer` columns (`phone`, `c_addr`,
`language`); a deprecation notice printed mid-script would inject stray text
into the `|`-delimited payload the frontend JS parses by splitting on `|`.
`mamabe_verify_receipt.php` builds a JSON response from unguarded third-party
Xilnex API fields (`itemCode`, `unitPrice`, `itemName`) — a future API response
shape change would previously fail silently (undefined-index notice, PHP 5.6)
but now emits a `Warning` that could corrupt the JSON body.
**Fixed**: all fields cast/`?? ''`-guarded at the read site.

### 5. `htmlspecialchars()` default-flag change (PHP 8.1)
Default flags changed from `ENT_COMPAT` to `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401`
— single quotes in rendered text (names, addresses, remarks) are now also
HTML-escaped where they weren't before. This is a **behavior difference, not an
error**, and is generally a safety improvement (better XSS coverage in
single-quoted attributes). **Not changed** — flagged as informational only, per
the "minimal changes" mandate; safe to leave on the new (safer) default.

### 6. Categories checked with no findings
`each()`, `create_function()`, `ereg()`/`split()`, `get_magic_quotes_gpc()`,
`money_format()`, `(real)`/`(unset)` casts, `${...}`/`$str{0}` offset syntax,
`assert()` with string arg, dynamic property assignment (PHP 8.2 deprecation —
no custom classes in this module), `switch`/`continue` ambiguity,
optional-before-required function parameters, `@`-suppression on now-fatal
code, `count()`/`sizeof()` on null (all counted arrays are `array()`-initialized
before use), old-style `usort()` comparators (module uses modern int-returning
closures where sorting is used at all), `session_register()`/`$HTTP_*_VARS`,
`mysql_*` functions.

## Security notes (documented as pre-existing technical debt, not upgraded)
- Several files build SQL with direct string interpolation of `(int)`-cast or
  otherwise-typed values (e.g. `$campaign_id`, `$blast_date`, `$duration` in
  `mamabe_blast_export.php`'s dynamic `$sql` blocks). These are cast to `int`
  or validated against a strict date regex before interpolation, so injection
  risk is low, but they are not parameterized. **Left unchanged** — rewriting
  to prepared statements would be a larger structural change than this
  compatibility pass calls for; flagged for future hardening.
- `mamabe_sku_master.php`'s `add_sku` INSERT and `mamabe_mama_add.php`'s
  `customer` INSERT use `mysqli_real_escape_string()` string-building rather
  than `bind_param()`. Functionally safe on PHP 8.2 (no behavior change) but
  same technical-debt note applies.
- API credentials (Xilnex `appid`/`token`) are hardcoded in
  `mamabe_vip_lookup.php`. Pre-existing; out of scope for this upgrade, flagged
  for future secrets-management work.

## Risk level after upgrade
**Low.** No fatal errors remain in `php -l` output. The one genuine
PHP-8-fatal-risk item (`rand()` `ValueError` in `mamabe_blast_export.php`) is
fixed. All other findings were non-fatal deprecations/warnings that are now
resolved at their source. Manual QA of the flows listed in
`MODULE_DOCUMENTATION.md` §I is still recommended before production cutover.
