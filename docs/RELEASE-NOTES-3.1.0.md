# WP Dynamic Tags 3.1.0: release notes

3.1.0 makes the custom table the **only** place tags live, stops the plugin from writing to the
database while a page renders, replaces the admin with one screen, and closes the security and
reliability findings of an audit of 3.0.0. Tags written for 3.0.0 keep rendering; the deliberate
differences are listed under [Output differences](#output-differences-from-300).

Requirements: WordPress 7.0 or newer, PHP 8.2 or newer. Tested on WordPress 7.0 and 7.1.4 (as a network on
7.0), with the full suites on PHP 8.2, 8.3 and 8.5 against MySQL 8.4 and MariaDB 10.11. PHP 8.3 was run
without the curl extension (this machine's application control blocked that one library); PHP 8.4 has not
been run.

## What changes for a site owner

* **One tag store.** A tag is a row of the table `wp_dynamic_tags`. The `dynamic_tag` post type stays
  registered as an empty, hidden shim so other plugins that check for it keep working, and it is no longer
  a place to edit tags. The post-to-table Bridge, "dual mode" and the mirror posts are gone.
* **Nothing is written while a page renders.** No usage counter update, no transient, no schema check.
  Measured on a 5,000-tag site: one query to load the tags and none after that; 40 renders cost 0 writes.
* **Usage counting is off by default.** *Settings > Count Tag Usage* turns it on; the counts are then kept
  in memory and written once at the end of the request, never on admin, AJAX, REST, cron or preview
  requests. The "Uses" and "Last used" columns show a dash while it is off.
* **One screen to manage tags.** *Dynamic Tags* is a top-level menu: **All Tags** (search, status and group
  filters, sorting, paging, bulk activate / deactivate / duplicate / delete), **Add New** (name, shortcode,
  content with a live preview, group, priority, status), **Import / Export**, **Tools** (migration, table
  checks, diagnostics, debug log), plus **Groups** and **Settings**. The old addresses
  (`edit.php?post_type=dynamic_tag`, the old Table Manager and Conflict Manager pages) redirect to the
  new screens.
* **Safer writes.** A tag can no longer take a shortcode name that belongs to the plugin (`dt`, `dt_group`,
  `dt_loop`, anything starting with `dt_table_`, ...) or to WordPress or another plugin; it can no longer be
  saved if it would render itself (`[a]` inside `a`, or `a` and `b` referring to each other); and content
  written by a user without the `unfiltered_html` capability is filtered like post content. These rules
  apply to the screens, to import and to code that calls the facade.
* **Import / export you can trust.** JSON, CSV and XML can be imported, SQL is export only. A file is checked
  first (*Validate*), good rows are imported and each bad row is reported by its number with the reason.
  A CSV read back is identical to what was exported, and cells that a spreadsheet would run as a formula
  are neutralised on export. Limits: 5 MB and 5,000 rows by default (filters `dt_import_max_bytes`,
  `dt_import_max_rows`). 5,000 rows import in about 13 seconds.
* **Edits show on the whole site.** The plugin keeps nothing between requests, so an edit shows on the next page
  load; when the site has a page cache in front of it, the cache is purged for you: once per request, only when a tag
  or a group really changed, for WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround
  Optimizer, Hummingbird, Breeze, Cache Enabler, Nginx Helper and the Cloudflare plugin. The action `dt_tags_changed`
  lets any other cache layer listen, and the filter `dt_purge_page_caches` switches the built-in purge off. Within one
  request, code that edits a tag and then renders it sees the new content at once (it used to see the old one).
* **The tag form keeps WordPress's editor.** The Content field has the **Visual** and **Code** tabs of the post
  editor (the tab you last used is remembered), the placeholder buttons and **Preview** work in both, and a tag such as
  a phone number is saved without a wrapping `<p>`. Use the Code tab for conditions that contain `<` or `>`.
* **A Value column** on All Tags shows what each tag renders to, as plain text and cut short; it is blank when the tag
  cannot be rendered outside a page. It never makes the server fetch another site (`{api:}` answers that are already
  cached are used), and a tag with nothing to render is not sent through the renderer.
* **Groups work on the table.** The Groups screen counts and lists the tags of a group from the table, and
  deleting a group releases its tags. A tag belongs to **one** group (a post could have several).
* **Debug log.** Off unless the filter `dt_debug_mode` (or the constant `DT_DEBUG_MODE`) is true; records only
  the tag id, shortcode, kind of problem, context and time (never content, meta values, credentials or
  URLs), 50 entries, shown and cleared on the Tools screen.

## Upgrading from 3.0.0

1. Back up the database. The migration also writes its own backup of exactly what it changes.
2. Open **Dynamic Tags > Tools**. The migration panel shows how many legacy tag posts are not yet rows,
   runs a dry run, migrates in batches, and verifies the result. The same steps exist as WP-CLI commands:
   `wp dynamic-tags status | migrate [--dry-run] | verify | cleanup | conflicts | resolve | restore | rescan`.
3. Old tag posts are **never deleted automatically.** After the verification passes, *cleanup* offers to
   delete them (only posts that are verified and unchanged, with a backup), and only when you confirm.
4. Until you clean up, a compatibility window keeps older code working: a write to a legacy tag post (by
   another plugin, for example) is copied into the table, and a legacy post that has no row yet still
   renders, read only. This window is kept for the whole 3.x series and goes in 4.0.

Sibling plugins (Certificate Generator, certificate-generator-gema, chatbot-by-eshaan) use the facade
`WP_Dynamic_Tags_Table_Manager`, whose methods, input keys and return shapes are unchanged. The calls that
failed before now work (see B1 and B4 in `docs/API.md`).

## Output differences from 3.0.0

Every difference between what a tag rendered before and what it renders now was reviewed and is pinned by an
exact entry in `tests/golden-master/allowed-differences.json`; nothing else changed. In plain words:

| | Difference |
|---|---|
| C1 | The `format` attribute is now applied to tags that came from tag posts (it was ignored). |
| C2 | `processing="false"` is now honoured for such tags (it was ignored). |
| C3 | `{tag_name}`, `{tag_id}`, `{usage_count}` and `{user_id}` now expand in tags that came from posts. |
| C4 | Shortcodes nested in the content of such a tag now expand (they stayed as text). |
| C5 | `{meta:}` with an empty key renders nothing instead of the word `Array`. |
| C6 | The placeholder engine now also runs for tags created in the table: `{meta:}`, `{acf:}`, `{wc:}`, formatters, `??` fallbacks and role and field conditions resolve instead of staying as raw text. |
| C7 | For those tags `{user_name}` is empty for guests and the login name for users, `{user_role}` expands, `{current_date}` uses the site's date format, and post tokens are empty outside a post. |
| C8 | `format=` is applied to text the engine now resolves (a date is no longer the ISO form). |
| C9 | `[dt]`, `[dt_group]` and `[dt_template]` read the table, so a tag that exists only as a row is found. |
| C10 | After a `[dt_template]` loop WordPress restores the main query's post, so later `{post_title}` tokens resolve to it. |
| L1 | `{else}` blocks, `!negation` and `time_between()` across midnight work for tags created in the table; sequential `{if}` blocks no longer pair the wrong `{else}`. |

Other decisions:

* **Markup in tag output (S10).** The output of a per-tag shortcode is not run through `wp_kses_post`: the
  author's markup is preserved, as before. `[dt]`, `[dt_group]` and `[dt_template]` keep their own
  filtering. Filter `dt_kses_tag_output` turns filtering on for every tag.
* **`{api:}` is hardened.** Only public hosts (WordPress refuses loopback and private addresses; the plugin
  also checks them itself), a 1 MB response cap, a JSON (or plain text) content type, at most 5 fetches per
  request, an optional host allow-list (`dt_api_allowed_hosts`), and nothing larger than 256 KB is cached.
  It still stores one transient per cache miss, at most every five minutes per URL: it is the only database
  write a render can still cause.
* **Token safety is on by default.** `{query:}` and `[dt_template]` only see content the viewer may read
  (public post types, readable statuses, no password-protected posts, at most 50 rows); `{meta:}` refuses
  protected meta keys (`dt_allow_meta_key` filters this); `[dt_loop]` fills its template in one pass and
  escapes values for their context.
* **One group per tag.** A row has one `category_id`; a tag post could carry several groups, and the
  migration keeps the first.
* **`[dt_table_*]` helper shortcodes** (`group`, `random`, `list`, `search`, `stats`) keep their output
  (names and links); only their registration moved.

## Removed

* **Screens and features:** the post editor for tags (meta boxes, columns, quick edit, row actions),
  Quick Create, the Bulk Manager, the Conflict Manager / Conflict Resolver, the Table Manager page, the old
  import/export page, the Migration Manager screens, the post-to-table Bridge, dual mode, the post-path
  shortcode registrar and its caches and locks.
* **AJAX actions:** `dt_assign_groups`, `dt_auto_resolve_conflicts`, `dt_bulk_delete`, `dt_check_duplicate`,
  `dt_delete_duplicate_tags`, `dt_dismiss_menu_notice`, `dt_export_csv`, `dt_export_json`, `dt_inline_edit`,
  `dt_merge_duplicate_tags`, `dt_merge_tags`, `dt_resolve_conflict`, `dt_table_bulk_action`,
  `dt_table_create_tag`, `dt_table_delete_tag`, `dt_table_get_tags`, `dt_table_migrate`, `dt_table_reset`,
  `dt_table_sync`, `dt_table_update_tag`, `dt_test_shortcode`, and the `admin_action` handlers
  `dt_duplicate_tag` and `dt_export_single`. Added: `dt_preview_tag`.
* **Classes:** `WP_Dynamic_Tags_Post_Table_Bridge`, `_Bulk_Manager`, `_Conflict_Resolver`,
  `_Conflict_Manager_Admin`, `_Table_Manager_Admin`, `_Import_Export`, `_Migration_Manager`. Code that
  uses the facade `WP_Dynamic_Tags_Table_Manager` is not affected.
* **Options and transients:** `wp_dynamic_tags_dual_mode`, `dt_registration_lock`, `dt_table_registration_lock`,
  `dt_refresh_lock_*`, `wp_dynamic_tags_cache*`, the tag-list and fingerprint transients. Uninstall removes
  what the plugin leaves behind, including the debug log and the export and API-cache transients.

## Security and reliability

The full register, with a failing test for each item that could be tested, is `docs/BUG-REGISTER.md`.

* **Security:** loop and query tokens could read private content (S1, S2); protected meta readable through
  `{meta:}` (S3); custom post type capabilities (S4); missing capability checks on statistics and tag
  retrieval, and a nonce no code created (S5, S6); unguarded request keys (S7); uninstall deleted other
  plugins' user meta and transients (S8); object injection through stored meta (S9); `{api:}` response
  size, content type and fetch limits (S11); the export download id was guessable and reusable (I1);
  import upload checks and XML entity expansion (I2, I3); `{post_content}` printed the body of a
  password-protected post for every viewer when that post was the current one, for example from a tag in a
  widget or template part (A5; it now renders empty until WordPress would show the content).
* **Rendering and runtime:** sequential `{if}` blocks (L1) and negation (L2); hard-coded demo values in
  formatters (L3); shortcodes not registered on REST requests (L4); date formatters (L5); the Duplicate
  button (L6); `WP_Error` from term lookups (L7); **no recursion guard**, so a tag that contained itself
  caused a fatal error (L8, now a depth and size limit); debug logging of tag content (L11); a database
  write per render (L12); a tag replacing another plugin's shortcode (F1, L10).
* **Found while building 3.1.0:** the Groups screen showed 0 tags after the posts were gone (G1); an
  import took time with the square of its rows, five minutes for 5,000 (N10); the CSV export lost line
  breaks and the importer could not read a spreadsheet's file (N2); SQL could be imported through a
  parser that cut content at the first comma (N1); a copy of every tag stayed in `wp_options` after an
  uninstall that was asked to delete the data, and on a network only one site was cleaned.
* **Tests:** the root suite (141 tests, no WordPress), the WordPress suites (front 169, admin 237, on 7.0 and
  7.1.4 and as a network), an exposure matrix (six roles across query and meta tokens, `[dt_template]`, `[dt_loop]`, REST-rendered
  content and password-protected posts; every AJAX action for subscriber, contributor, author, editor and a
  visitor), a golden master of 396 renders compared with the recorded 3.0.0 output, 80 live
  migration checks, a performance probe, and hand-written negative controls (deliberate defects that
  the tests must catch; all are caught).

## Privacy

* The plugin has no telemetry and sends nothing to its author. It makes an outbound request only when a tag
  uses `{api:URL}` (the tag author chose the URL; see the limits above), and, if a licence key **and** a
  licence server are both configured (the server is empty by default), a periodic licence check that sends
  the key and the site address to that server.
* What it stores: the tags table, a few options (settings, migration state and log, the debug log when
  enabled), and a private backup folder in the uploads directory (`wp-dynamic-tags-private`, blocked from the
  web, unguessable names) for migration and uninstall backups. Tag content can hold personal data (the
  certificate plugins write student names into tags), so exports and backups should be handled accordingly.
* *Delete data on uninstall* removes the tags, groups, options, transients and the plugin's own backups from
  the migration; the uninstall backup it writes before deleting is deliberately kept in the private folder.

## Known limits

* A tag belongs to one group; group *names* are not part of an export, so groups do not travel between
  sites (a group id that does not exist on the target is dropped on import).
* Tags are per site on a network. The plugin never reads a tag from another site, and code that renders
  with `switch_to_blog()` uses the table of the first site.
* A very large export is held in one transient for ten minutes (one `wp_options` row): size it against
  `max_allowed_packet`.
* The licence and plan settings in the code are not used to limit anything in 3.1.0.
* **How many tags.** There is no cap, and the cost of a tag is paid on every request, because every active tag
  is registered as a shortcode. Measured on a development PC without a page cache, per uncached page: 1,000
  tags about +2 MB and +10 ms, 5,000 about +10 MB and +55 ms, 10,000 about +20 MB and +110 ms, 20,000 about
  +40 MB and +210 ms, 50,000 about +106 MB and +550 ms (2.05 KB and about 8 microseconds per tag, plus the
  time WordPress's own `do_shortcode()` spends comparing every registered name on each call). Advice: up to
  5,000 tags on shared hosting with one CPU core and 1 GB of RAM, 10,000 behind a page cache. A 50,000-tag
  table imported (about 170 s), listed (75 to 250 ms a page), searched and exported without an error.
