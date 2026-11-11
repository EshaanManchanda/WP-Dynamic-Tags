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
| S1 | high | fixed | `wp-dynamic-tags.php:792-800` | P: red run, 5 of 5 loop tests failed on the old code | `DT_Token_Safety_Test::test_loop_*` (5); golden master `loop` fixture identical |
| S2 | high | fixed | `class-dynamic-placeholders.php:473-475`, `wp-dynamic-tags.php:874-877` | P: red run, 5 of 8 query tests failed | `DT_Token_Safety_Test` S2 cases (7) incl. filter cannot widen visibility |
| S3 | medium | fixed | `class-dynamic-placeholders.php:430-459` | P: red run (2 errors, protected meta and author meta readable) | `DT_Token_Safety_Test` S3 cases (4) |
| S4 | medium | fixed | `wp-dynamic-tags.php:235` | P: red run on the old capability_type; the first fix attempt (mapping to manage_options with map_meta_cap) took manage_options away from every administrator and was caught by the test | `DT_Token_Safety_Test::test_only_administrators_can_create_publish_or_edit_tag_posts` |
| S5 | medium | fixed | `admin/table-manager-admin.php:1178`, `class-table-manager.php:756`, `wp-dynamic-tags.php:2498, 2982, 3037` | No capability check on `ajax_statistics` and `ajax_get_tags` (returns all tag content); `edit_posts` is too weak for check-duplicate, duplicate and single export | R, P: red run, 7 cases failed (2026-11-11) | `DT_Action_Security_Test::test_denies_a_user_without_the_capability...`, `..._contributor_...` (tests/wp-admin) |
| S6 | medium | fixed | `class-table-manager.php:673-788` | Five AJAX handlers verify nonce `wp_dynamic_tags_nonce`, which no code in any plugin creates: unreachable | R, P: red run | `DT_Action_Security_Test::test_every_verified_nonce_is_created_somewhere_in_the_plugin` |
| S7 | low | fixed (see note) | `wp-dynamic-tags.php:2308, 2456, 4243, 4365, 4461`, `admin/table-manager-admin.php:85, 96`, `class-bulk-manager.php:737`, `class-tag-groups.php:1070-1125, 1358-1360`, `class-conflict-resolver.php:406-420` | Unguarded `$_POST`/`$_GET` keys (PHP 8 warnings; `array_map('intval', $_POST['tag_ids'])` is a fatal TypeError when missing); `wp_redirect` instead of `wp_safe_redirect` (5 sites); bulk delete / inline edit do not verify post type | R, P: red run, 3 handlers crashed on missing keys | `DT_Action_Security_Test::test_an_authorized_request_without_parameters_does_not_crash` |
| S8 | medium | fixed | `uninstall.php:346-347, 279, 368, 431`, `wp-dynamic-tags.php:4612, 4934`, `class-database-manager.php:152` | P: red run (another plugin's dt_ user meta was deleted) | `DT_Uninstall_Test` (runs the real uninstall.php on the disposable DB); also all SHOW TABLES LIKE queries are prepared |
| S9 | low | fixed | `class-database-manager.php:310, 327, 344, 425`, `class-table-import-export.php:208`, `class-migration-engine.php:261, 695, 711` | P: negative control (plain unserialize runs __wakeup of the named class) | `MetaDecodeTest` (8, pure); `decode_meta()` replaces 7 maybe_unserialize call sites |
| S10 | low | open | `class-table-shortcode-processor.php:203`, `wp-dynamic-tags.php:1112` | Per-tag callbacks (table and post path) return output without `wp_kses_post`; `[dt]`, `[dt_group]`, `[dt_template]` apply it: inconsistent | R | |
| S11 | medium | open | `class-dynamic-placeholders.php:507-534` | `{api:}` already uses `wp_safe_remote_get`; missing: response size cap, content-type check, host allowlist, payload size limit before caching in a transient, per-request fetch cap | R | |
| I1 | low | open | `class-table-import-export.php:724-728` | Export (all tags, including `cg_*` student data) is kept in a transient (`wp_options`) for an hour under a `uniqid()` id | R | |
| I2 | medium | fixed | `class-table-import-export.php:714-754, 779-784` | P: red run (size, extension cases failed) | `DT_Import_Hardening_Test` (8): size cap `dt_import_max_bytes` (5 MB), extension check, upload error check, options whitelist, validate-only forced |
| I3 | low | fixed | `class-table-import-export.php:424-425` | P: red run (DOCTYPE accepted) | `DT_Import_Hardening_Test::test_xml_with_a_doctype_is_refused`; also LIBXML_NONET |
| I4 | low | open | `class-import-export.php:808, 794, 802, 820` | Undefined `conflict_resolution` key; 32 MB limit; translated strings echoed without `esc_html` | R | |

## Rendering and runtime

| ID | Sev | Status | Where | Defect | Evidence | Test |
|---|---|---|---|---|---|---|
| L1 | high | fixed | `class-dynamic-placeholders.php:620-630`, `class-table-shortcode-processor.php:266-280` ; red run on the old engines: 6 of 7 end-to-end tests failed | Sequential `{if}` blocks mis-parse: `A{if:x}shown{/if} middle {if:y}yes{else}no{/if} end` pairs the first `{if:x}` with the second block's `{else}`, leaking raw `{/if}`/`{if:y}`; nested `{if}` unsupported | **P** (regex probe 2026-11-11, output `A[x|T=shown{/if} middle {if:y}yes|F=no] end`)  `ConditionalParserTest` (17, pure), `DT_Conditional_Render_Test` (7, WP suite); golden-master allow-list entries |
| L2 | medium | fixed (partly) | `class-table-shortcode-processor.php:285-314` | Condition matching by `strpos`: `{if:!user_logged_in}` is read as `user_logged_in`; `time_between` never true across midnight; post engine has no `time_between` | R  `DT_Conditional_Render_Test`, `TimeWindowTest` (13). Post engine still lacks `time_between`, table renderer still lacks `user_role:`/field conditions: resolved by the Phase 3 unification |
| L3 | medium | fixed | `class-dynamic-placeholders.php:585-596` | P: red run | `DT_Formatter_Test::test_square_bracket_text_is_not_replaced_with_hardcoded_demo_values`; the per-call content logging is gone as well (L11 partly) |
| L4 | medium | fixed | `wp-dynamic-tags.php:253, 285, 2108` | R; behavior of the opt-out covered by unit-style tests | `DT_Render_Guard_Test::test_shortcodes_are_registered_on_rest_requests_by_default`, `..._can_opt_out...` (the REST early return is replaced by `WP_Dynamic_Tags_Plugin::should_register_shortcodes()`; filter `dt_register_shortcodes_on_rest`) |
| L5 | medium | fixed | `class-dynamic-formatters.php:92, 104, 113` | P: red run (5 of 6 date/relative/currency cases failed) | `DT_Formatter_Test` (8) |
| L6 | medium | open | `assets/js/admin.js:195` | Posts `dt_duplicate_tag` to admin-ajax; only a GET `admin_action_` handler exists | R | |
| L7 | medium | open | `class-bulk-manager.php:181`, `class-conflict-resolver.php:275-288, 636, 733`, `wp-dynamic-tags.php:1975`, `class-dynamic-placeholders.php:482` | Unchecked `WP_Error` from `get_terms`/`wp_get_object_terms` (fatal on PHP 8 for a missing taxonomy); `$ID` on `int|WP_Post`; plus unused private methods, write-only properties, unreachable code after `wp_send_json` (`admin/conflict-manager.php:425, 432`) | T (PHPStan level 8) | |
| L8 | high | fixed | `class-table-shortcode-processor.php:258`, whole plugin | No recursion guard: tag `a` containing `[a]`, or A<->B, recurses; no depth or output-size limit | P: `tests/live/recursion-probe.php` exit 255 (PHP fatal, WordPress critical-error page) for self, pair and chain before; exit 0 with `A`, `AB`, `ABC` after | `DT_Render_Guard_Test` (9 tests; mutation: disabling the depth and size guards fails 3 of them) |
| L9 | low | fixed | `class-table-shortcode-processor.php:197` | `performance_stats['shortcode_executions'][]` grows on every render | R | execution log capped at 50 entries; exercised by `DT_Render_Guard_Test` |
| L10 | medium | fixed | `wp-dynamic-tags.php:1050-1060, 1186-1204, 1246-1248, 1589, 1618` | P: red run (hardcoded names owned by others were removed) | `DT_Foreign_Shortcode_Test::test_the_post_path_cleanup_does_not_remove_hardcoded_names_owned_by_others`; `is_our_shortcode()` now asks who owns the callback (file under includes/, admin/ or the main file) |
| L11 | low | open | `wp-dynamic-tags.php:1086, 1108` | Verbose debug logging writes raw and processed tag content (may contain `{user_email}` etc.) to `debug.log` | R | |
| L12 | medium | open | `wp-dynamic-tags.php:1090-1092` | Post-path usage is written (2 `update_post_meta`) per render when `WP_DEBUG` is on, and never counted in production: inconsistent with the table path | R | |
| F1 | medium | fixed | `wp-dynamic-tags.php:993-995`, `class-table-shortcode-processor.php:139-143` | P: red run (table tag replaced another plugin's shortcode) | `DT_Foreign_Shortcode_Test` (4): both registrars skip and record a foreign name (`get_shortcode_conflicts()`, action `dt_shortcode_conflict`), filter `dt_allow_shortcode_override` restores last-one-wins |
| L13 | medium | fixed | `admin/conflict-manager.php:24-25`, `class-conflict-resolver.php:26`, `class-tag-groups.php:45` | Four `wp_ajax_*` hooks (`dt_merge_duplicate_tags`, `dt_delete_duplicate_tags`, `dt_merge_tags`, `dt_quick_edit_groups`) were registered with callbacks that do not exist: requesting the action is a fatal error. No UI used them; registrations removed | P: red run `test_every_registered_ajax_callback_is_callable`; PHPStan also flagged all four | `DT_Action_Security_Test::test_every_registered_ajax_callback_is_callable` |
| C1 | medium | fixed | `wp-dynamic-tags.php:2944, 2957`, `class-import-export.php:670, 727, 926, 942, 1358, 1394`, `class-table-import-export.php:402` | P: PHP 8.5.12 E_DEPRECATED; PHPCompatibility 10 now reports 0 escape findings (was 9) | explicit escape argument on all 9 calls; guard is the PHPCompatibility run in `tools/static` |

## Facade and earlier findings (see `docs/API.md`)

| ID | Sev | Status | Where | Defect | Evidence | Test |
|---|---|---|---|---|---|---|
| B1 | medium | fixed | `class-table-manager.php` `update_tag`/`validate_tag_data` | A partial update (`['content' => ...]`) was rejected with "Tag name is required" | P: red run; `siblings.php`: `cg.update_content` was a `WP_Error`, now `true` | `DT_Facade_Test::test_update_tag_accepts_a_partial_update` (+ validation still applied to sent fields) |
| B2 | low | fixed | `class-database-manager.php` `get_tags` | `limit => 0` produced `LIMIT 0,0` (nothing) | P: red run | `DT_Facade_Test::test_a_limit_of_zero_means_no_limit` |
| B3 | low | fixed | `class-database-manager.php` search | Search ignored the shortcode (list and count) | P: red run | `DT_Facade_Test::test_search_matches_the_shortcode` |
| B4 | medium | fixed | `class-table-manager.php` `create_tag`/`update_tag` | chatbot-by-eshaan sends `title`/`group_id`: "Tag name is required". Aliases added (real field wins); non-column keys such as `id` are dropped | P: red run; `siblings.php` `chatbot.create_legacy_keys` was a `WP_Error` | `DT_Facade_Test` alias and id cases (3). Side effect to watch: the chatbot plugin will now create about 12 `event_*` tags and a "Chatbot Events" group on its next admin load, and under a later Free 15-tag cap they would use the quota |
| B5 | low | open | dual mode | Mirror post `post_id` link never saved | R | removed with the Bridge in Phase 3 |
| B8 | medium | open | `wp-dynamic-tags.php` `get_dynamic_tags` | Stale 1-hour tag-list transient (ghost shortcodes) | P (earlier session) | removed with the post path in Phase 3 |
| B9 | low | open | `wp-dynamic-tags.php` `register_shortcodes` | 3-second registration lock can skip registration | R | removed with the locks in Phase 3 |

Usage `UPDATE` on every render (measured: one write per render, `tests/perf/baseline-pre-refactor.json`): open, Phase 3.

## Measured baseline (gate 4)

`tests/perf/baseline-pre-refactor.json`, commit `86be33b`, 5,000 tags, PHP 8.2.29, medians of 5: boot 1.56 s, 78 MB peak, 68
queries (8 transient writes, 26 schema checks), 5,000 shortcodes registered; rendering 40 shortcodes = 40 database writes.

## Test evidence log

* 2026-11-11 L1/L2: new pure `WP_Dynamic_Tags_Conditional_Parser` (stack based) used by both renderers; the table renderer gets `{else}`, `!negation`, exact condition names and midnight-crossing `time_between`. Red: 6 of 7 `DT_Conditional_Render_Test` cases failed on the old engines. Green: root PHPUnit 127, WP 7.1.4 and 7.0 front 18 + admin 131, PHPStan clean. Golden master: post path identical 132/132 in 3 contexts; table path 12 approved differences per context pinned in `tests/golden-master/allowed-differences.json` (negative control: a wrong expected output makes `diff.php --allow` fail).

* 2026-11-11 L8/L9: guard in `WP_Dynamic_Tags_Table_Shortcode_Processor` (render stack, `dt_max_render_depth` default 10, `dt_max_render_size` default 1 MB; a result produced while a guard tripped is not cached). `tests/wp/RenderGuardTest.php`: 9 tests green on WP 7.1.4.

* 2026-11-11 B3 batch (S5, S6, S7, L13): `tests/wp-admin/ActionSecurityTest.php` generated from `tests/wp-admin/support/actions.php`.
  Before the fixes: 143 tests, 22 failures (7 S5/L13 authorization cases, 4 L13 callbacks, 1 S6 nonce, 3 S7 crashes, plus the registry integrity case). After: **131 tests, 0 failures** on WP 7.1.4 (the 4 dead actions left the registry). Root PHPUnit 97 tests OK, PHPStan baseline clean, golden master identical 6/6 on a disposable clone.
  Run: `DT_WP_CORE_ROOT=<scratch> DT_PHP_INI_DIR=<scratch>\phpini82 tools\integration
un.ps1 -Core 7.1.4 -Suite admin`.
* B7 (empty `{meta:}` key rendering "Array") is fixed by the S3 key check: `DT_Token_Safety_Test::test_an_empty_meta_key_renders_nothing_instead_of_array`; golden master allow-list pins the 10 resulting differences (post path, 2 singular contexts).
* 2026-11-11 B1 token safety (S1, S2, S3, B7): `WP_Dynamic_Tags_Placeholders::build_query_args()` (whitelisted args, public post types, statuses limited to what the viewer may read, filters cannot widen, password-protected excluded, row cap 50) used by `{query:}` and `[dt_template]`; `is_meta_key_allowed()`; `[dt_loop]` processes the template once and fills rows in a single pass with context escaping (`WP_Dynamic_Tags_Plugin::fill_loop_template()`). Red: 7 of 11 safety tests and 5 of 5 loop tests failed on the old code. Green: WP 7.1.4 and 7.0 front 34 + admin 131, root PHPUnit 127, PHPStan clean, golden master within the allow-list (46 pinned differences). New filters: `dt_query_allowed_args`, `dt_query_post_statuses`, `dt_query_max_posts`, `dt_allow_meta_key`. Deliberate output differences: row values in `[dt_loop]` are escaped (e.g. `&` becomes `&amp;`) and never expanded as tokens.
* 2026-11-11 B1/B2 continued (S4, S8, S9, L3, L5): CPT gets its own capabilities (`edit_dynamic_tags`, ...), granted to whoever holds `manage_options` (filter `dt_manage_capability`); mapping the CPT's meta capabilities onto `manage_options` itself is a trap (WordPress then treats `manage_options` as the meta capability `edit_post` and denies it to everybody) and is documented in the code. Uninstall removes only the exact user-meta key and escapes transient prefixes. `WP_Dynamic_Tags_Database_Manager::decode_meta()` never instantiates objects. Formatters: unparsable dates are returned unchanged (they used to show the current date), `relative` uses real UTC timestamps, `dt_currency_symbol` filter, locale separators. Hardcoded `[date1]`/`[date2]`/`[tagname]` replacements removed. Red/green: 6 of 8 formatter cases, S4, S8 failed before. Green: root PHPUnit 135, WP 7.1.4 and 7.0 front 44 + admin 131, PHPStan clean, golden master within the allow-list.
* 2026-11-11 Facade (B1-B4): `normalize_input()` (aliases `title`/`group_id`, whitelist of writable columns), name required only on create, `limit <= 0` = no limit, search includes the shortcode. 7 of 9 `DT_Facade_Test` cases failed on the old code; green: root PHPUnit 135, WP 7.1.4 and 7.0 front 53 + admin 131, PHPStan clean, golden master within the allow-list, `siblings.php` shows the documented failures now succeed.
* 2026-11-11 L4, C1, I2, I3: REST registration is on by default (opt-out filter), CSV functions pass `$escape` explicitly, table import endpoints check the upload (error code, 5 MB cap, extension, option whitelist), XML with a DOCTYPE/ENTITY is refused. Red: 3 of 8 import cases failed before. Green: root PHPUnit 135, WP 7.1.4 and 7.0 front 55 + admin 139, PHPStan clean.
* 2026-11-11 F1/L10: `WP_Dynamic_Tags_Plugin::shortcode_is_ours()` decides ownership by the file the registered callback lives in; every `remove_shortcode()` in the post path and the table processor is guarded by it, and a name owned by someone else is skipped and reported. Red: 2 of 4 cases failed before. Green: root PHPUnit 135, WP 7.1.4 and 7.0 front 59 + admin 139, PHPStan clean, golden master within the allow-list.
