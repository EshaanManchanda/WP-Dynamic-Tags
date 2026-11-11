# Bug register

Source of truth for every defect found in the 2026-11 audit. A bug is only closed when a test that **failed
before the fix** passes after it, and the evidence is recorded here (command and result).

Evidence codes: **P** reproduced by running code, **R** confirmed by reading the code path, **T** reported by a
tool (negative control passed), **S** suspected, needs reproduction before the fix.

Status: `open` -> `repro` (failing test written) -> `fixed` (test green, suites green) -> `verified` (golden master and
matrix green). Nothing is marked fixed without a test id.

## Audit coverage (what was actually examined)

| Area | How | Depth |
|---|---|---|
| Placeholder engine, formatters, ACF/WooCommerce integration, table shortcode processor, core shortcode callbacks, post-path registrar (`register_simple_shortcodes`, `register_clean_shortcode`), DB manager query builder, AJAX/nonce/capability wiring, import/export handlers (entry points) | Read in full or in the relevant ranges | High |
| `class-tag-groups.php`, `class-conflict-resolver.php`, `admin/conflict-manager.php`, `admin/settings-page.php`, rest of `class-import-export.php` and `class-table-import-export.php`, `assets/js/admin.js`, `class-license-manager.php` | Pattern scan (superglobals, SQL, files, XML, redirects, nonces) plus targeted reads | Medium; a full read is still open and will be done as each file is touched |
| Whole plugin | PHPStan level 8 (1,163 findings, about 60 substantive), PHPCS WordPress security sniffs (925, most are table-name interpolation false positives), PHPCompatibility 10 for PHP 8.2-8.5, wp-compat against WP 7.0, Psalm taint | Tools |

## Tool findings and limits

| ID | Finding | Notes |
|---|---|---|
| T1 | wp-compat against WP 7.0: **no findings** (only unrelated `WP_CLI` stub warnings) | Negative control `tools/static/negctl/compat-neg.php` is flagged at floor 6.0, so the tool works. The stubs stop at WP 6.9.4; a symbol introduced in 7.0 would show as unknown, none did |
| T2 | Psalm taint: **no findings** | Negative control flags `echo $_GET[...]`. It does **not** flag `$wpdb->query("..." . $_POST[...])`, so SQL flows are not covered by Psalm; covered by PHPCS `WordPress.DB.PreparedSQL` and manual review (DB manager builder reviewed: ORDER BY whitelisted, values prepared, no injection found) |
| T3 | PHPCompatibilityWP 2.1.x cannot load under PHP_CodeSniffer 4 (needed by PHPCompatibility 10, the first release that knows PHP 8.2-8.5 deprecations) | Use the `PHPCompatibility` standard directly. PHPCompatibility 9.3.5 gave a false "clean" (negative control for `utf8_encode` was not flagged), so it is not used |
| T4 | PHP 8.6 forward-compat: about 40 `trim()` calls without explicit characters (form feed becomes trimmed in 8.6) | Informational, not a bug today. Will not be changed except in lines touched anyway |

## Security

| ID | Sev | Status | Where | Defect | Evidence | Test |
|---|---|---|---|---|---|---|
| S1 | high | open | `wp-dynamic-tags.php:792-800` | `[dt_loop]` inserts `{item}`/`{item:sub}` raw, then re-runs `dt_process_value` over the result: remote `{api:}` data injects HTML and executable `{...}` tokens | R | |
| S2 | high | open | `class-dynamic-placeholders.php:473-475`, `wp-dynamic-tags.php:874-877` | `{query:}` / `[dt_template query=]` merge user args over defaults: `post_status`, `post_type`, `posts_per_page=-1` overridable, private/draft titles, excerpts and permalinks reach visitors | R | |
| S3 | medium | open | `class-dynamic-placeholders.php:430-459` | `{meta:}`, `{user_meta:}`, `{term_meta:}` read any key (`_edit_lock`, author's `session_tokens`, `wp_capabilities`) | R | |
| S4 | medium | open | `wp-dynamic-tags.php:235` | CPT `capability_type => 'post'`: Authors can publish tags that register site-wide shortcodes and use S2/S3 | R | |
| S5 | medium | fixed | `admin/table-manager-admin.php:1178`, `class-table-manager.php:756`, `wp-dynamic-tags.php:2498, 2982, 3037` | No capability check on `ajax_statistics` and `ajax_get_tags` (returns all tag content); `edit_posts` is too weak for check-duplicate, duplicate and single export | R, P: red run, 7 cases failed (2026-11-11) | `DT_Action_Security_Test::test_denies_a_user_without_the_capability...`, `..._contributor_...` (tests/wp-admin) |
| S6 | medium | fixed | `class-table-manager.php:673-788` | Five AJAX handlers verify nonce `wp_dynamic_tags_nonce`, which no code in any plugin creates: unreachable | R, P: red run | `DT_Action_Security_Test::test_every_verified_nonce_is_created_somewhere_in_the_plugin` |
| S7 | low | fixed (see note) | `wp-dynamic-tags.php:2308, 2456, 4243, 4365, 4461`, `admin/table-manager-admin.php:85, 96`, `class-bulk-manager.php:737`, `class-tag-groups.php:1070-1125, 1358-1360`, `class-conflict-resolver.php:406-420` | Unguarded `$_POST`/`$_GET` keys (PHP 8 warnings; `array_map('intval', $_POST['tag_ids'])` is a fatal TypeError when missing); `wp_redirect` instead of `wp_safe_redirect` (5 sites); bulk delete / inline edit do not verify post type | R, P: red run, 3 handlers crashed on missing keys | `DT_Action_Security_Test::test_an_authorized_request_without_parameters_does_not_crash` |
| S8 | medium | open | `uninstall.php:346-347, 279, 368, 431`, `wp-dynamic-tags.php:4612, 4934`, `class-database-manager.php:152` | Uninstall deletes postmeta `LIKE '_dt_%' OR 'dt_%'`: `_` is a wildcard and there is no owner check, other plugins' keys can match; `SHOW TABLES LIKE '$table'` unprepared | R | |
| S9 | low | open | `class-database-manager.php:310, 327, 344, 425`, `class-table-import-export.php:208`, `class-migration-engine.php:261, 695, 711` | `maybe_unserialize()` on stored data allows object instantiation | R | |
| S10 | low | open | `class-table-shortcode-processor.php:203`, `wp-dynamic-tags.php:1112` | Per-tag callbacks (table and post path) return output without `wp_kses_post`; `[dt]`, `[dt_group]`, `[dt_template]` apply it: inconsistent | R | |
| S11 | medium | open | `class-dynamic-placeholders.php:507-534` | `{api:}` already uses `wp_safe_remote_get`; missing: response size cap, content-type check, host allowlist, payload size limit before caching in a transient, per-request fetch cap | R | |
| I1 | low | open | `class-table-import-export.php:724-728` | Export (all tags, including `cg_*` student data) is kept in a transient (`wp_options`) for an hour under a `uniqid()` id | R | |
| I2 | medium | open | `class-table-import-export.php:714-754, 779-784` | Import/validate AJAX: no size cap, no extension/mime check, upload error code not checked, `$_POST['options']`/`['filters']` unsanitized, undefined `format` key | R | |
| I3 | low | open | `class-table-import-export.php:424-425` | XML parsed without `LIBXML_NONET` (default libxml on PHP 8 does not expand external entities; hardening) | R | |
| I4 | low | open | `class-import-export.php:808, 794, 802, 820` | Undefined `conflict_resolution` key; 32 MB limit; translated strings echoed without `esc_html` | R | |

## Rendering and runtime

| ID | Sev | Status | Where | Defect | Evidence | Test |
|---|---|---|---|---|---|---|
| L1 | high | open | `class-dynamic-placeholders.php:620-630`, `class-table-shortcode-processor.php:266-280` | Sequential `{if}` blocks mis-parse: `A{if:x}shown{/if} middle {if:y}yes{else}no{/if} end` pairs the first `{if:x}` with the second block's `{else}`, leaking raw `{/if}`/`{if:y}`; nested `{if}` unsupported | **P** (regex probe 2026-11-11, output `A[x|T=shown{/if} middle {if:y}yes|F=no] end`) | |
| L2 | medium | open | `class-table-shortcode-processor.php:285-314` | Condition matching by `strpos`: `{if:!user_logged_in}` is read as `user_logged_in`; `time_between` never true across midnight; post engine has no `time_between` | R | |
| L3 | medium | open | `class-dynamic-placeholders.php:585-596` | Hardcoded test data in production: `[date1]` -> `25th Aug 2056`, `[date2]`, `[tagname]` -> `dynamic_tag_name`; `error_log` per token under `WP_DEBUG` | R | |
| L4 | medium | open | `wp-dynamic-tags.php:253, 285, 2108` | `register_shortcodes()` returns early on REST requests (raw shortcodes in REST-rendered content); called twice per request | R | |
| L5 | medium | open | `class-dynamic-formatters.php:92, 104, 113` | `date`/`relative` give 1970 for unparsable input; `human_time_diff(strtotime(), current_time('timestamp'))` mixes UTC and site-offset time; `currency` hardcodes `$`, `number_format` ignores locale | R | |
| L6 | medium | open | `assets/js/admin.js:195` | Posts `dt_duplicate_tag` to admin-ajax; only a GET `admin_action_` handler exists | R | |
| L7 | medium | open | `class-bulk-manager.php:181`, `class-conflict-resolver.php:275-288, 636, 733`, `wp-dynamic-tags.php:1975`, `class-dynamic-placeholders.php:482` | Unchecked `WP_Error` from `get_terms`/`wp_get_object_terms` (fatal on PHP 8 for a missing taxonomy); `$ID` on `int|WP_Post`; plus unused private methods, write-only properties, unreachable code after `wp_send_json` (`admin/conflict-manager.php:425, 432`) | T (PHPStan level 8) | |
| L8 | high | open | `class-table-shortcode-processor.php:258`, whole plugin | No recursion guard: tag `a` containing `[a]`, or A<->B, recurses; no depth or output-size limit | R (grep for depth/stack/recursion found nothing); **needs P first** | |
| L9 | low | open | `class-table-shortcode-processor.php:197` | `performance_stats['shortcode_executions'][]` grows on every render | R | |
| L10 | medium | open | `wp-dynamic-tags.php:1050-1060, 1186-1204, 1246-1248, 1589, 1618` | Site-specific hardcoded names: `remove_conflicting_shortcodes()` removes `math_olympiad_*`, `science_olympiad_*`, `general_*` of any owner; `is_our_shortcode()` pattern `^[a-z0-9_]+$` matches every shortcode, so conflict handling treats foreign shortcodes as ours | R | |
| L11 | low | open | `wp-dynamic-tags.php:1086, 1108` | Verbose debug logging writes raw and processed tag content (may contain `{user_email}` etc.) to `debug.log` | R | |
| L12 | medium | open | `wp-dynamic-tags.php:1090-1092` | Post-path usage is written (2 `update_post_meta`) per render when `WP_DEBUG` is on, and never counted in production: inconsistent with the table path | R | |
| F1 | medium | open | `wp-dynamic-tags.php:993-995`, `class-table-shortcode-processor.php:139-143` | Both registrars `remove_shortcode()` a name already registered by any plugin | R | |
| L13 | medium | fixed | `admin/conflict-manager.php:24-25`, `class-conflict-resolver.php:26`, `class-tag-groups.php:45` | Four `wp_ajax_*` hooks (`dt_merge_duplicate_tags`, `dt_delete_duplicate_tags`, `dt_merge_tags`, `dt_quick_edit_groups`) were registered with callbacks that do not exist: requesting the action is a fatal error. No UI used them; registrations removed | P: red run `test_every_registered_ajax_callback_is_callable`; PHPStan also flagged all four | `DT_Action_Security_Test::test_every_registered_ajax_callback_is_callable` |
| C1 | medium | open | `wp-dynamic-tags.php:2944, 2957`, `class-import-export.php:670, 727, 926, 942, 1358, 1394`, `class-table-import-export.php:402` | PHP 8.4+: `fputcsv`/`fgetcsv`/`str_getcsv` without explicit `$escape` raise E_DEPRECATED (printed into the CSV stream when display_errors is on) | **P** (PHP 8.5.12: `E8192: fputcsv(): the $escape parameter must be provided...`) | |

## Known from earlier sessions (see `docs/API.md`)

B1 partial `update_tag` rejected; B2 `get_tags(limit=0)` -> `LIMIT 0,0`; B3 search ignores shortcode; B4 chatbot
`title`/`group_id` keys fail (alias approved); B5 mirror post `post_id` link never saved; B7 `{meta:}` with an empty
key renders "Array"; B8 stale 1-hour tag-list transient (ghost shortcodes); B9 3-second registration lock can skip
registration; usage `UPDATE` on every render (measured: one write per render, see `tests/perf/baseline-pre-refactor.json`).

## Measured baseline (gate 4)

`tests/perf/baseline-pre-refactor.json`, commit `86be33b`, 5,000 tags, PHP 8.2.29, medians of 5: boot 1.56 s, 78 MB peak, 68
queries (8 transient writes, 26 schema checks), 5,000 shortcodes registered; rendering 40 shortcodes = 40 database writes.

## Test evidence log

* 2026-11-11 B3 batch (S5, S6, S7, L13): `tests/wp-admin/ActionSecurityTest.php` generated from `tests/wp-admin/support/actions.php`.
  Before the fixes: 143 tests, 22 failures (7 S5/L13 authorization cases, 4 L13 callbacks, 1 S6 nonce, 3 S7 crashes, plus the registry integrity case). After: **131 tests, 0 failures** on WP 7.1.4 (the 4 dead actions left the registry). Root PHPUnit 97 tests OK, PHPStan baseline clean, golden master identical 6/6 on a disposable clone.
  Run: `DT_WP_CORE_ROOT=<scratch> DT_PHP_INI_DIR=<scratch>\phpini82 tools\integrationun.ps1 -Core 7.1.4 -Suite admin`.
