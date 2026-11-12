# WP Dynamic Tags: public PHP API (3.1)

This is the contract other plugins (Certificate Generator, certificate-generator-gema,
chatbot-by-eshaan) rely on. **`WP_Dynamic_Tags_Table_Manager` is the stable facade**: its
method signatures, accepted input keys and return shapes do not change in a minor release.
Internals (repository, service, renderer classes) may be refactored freely behind it.

Behaviour of 3.0.0 was recorded by `tests/golden-master/siblings.php` (result:
`tests/golden-master/baseline/siblings.json`). Where recorded behaviour was a bug the callers evidently
did not intend, it is listed under [Deviations fixed in 3.1](#deviations-found-in-300-and-fixed-in-31).

## Obtaining the facade

```php
if ( class_exists( 'WP_Dynamic_Tags_Table_Manager' ) ) {
    $dt = WP_Dynamic_Tags_Table_Manager::get_instance();
}
```

Detection that callers use today and that keeps working: `class_exists('WP_Dynamic_Tags_Plugin')`,
`class_exists('WP_Dynamic_Tags_Table_Manager')`, `post_type_exists('dynamic_tag')` (the post type stays
registered as an empty, hidden shim; it is not a place to store tags any more).

## Tag object

Returned by `get_tag*()` and as items of `get_tags()` (`stdClass`). Database columns arrive as strings.

| Property | Type | Notes |
|---|---|---|
| `id` | numeric string | primary key |
| `tag_name` | string | unique, human label; letters, digits, space, `_`, `-` |
| `shortcode` | string | unique; the `[shortcode]` that renders the tag; letters, digits, `_`, `-` |
| `content` | string | template: placeholders, formatters, conditionals |
| `description` | string | |
| `category_id` | numeric string | `tag_groups` term ID, `0` = none. **One group per tag.** |
| `priority` | numeric string | 0 to 100 |
| `usage_count` | numeric string | only maintained when usage counting is on (see `track_usage`) |
| `status` | `active` \| `inactive` | only `active` tags register a shortcode |
| `meta_data` | array | unserialized; `[]` when empty |
| `post_id` | numeric string | legacy link to a `dynamic_tag` post, `0` = none (kept for migration only) |
| `created_date`, `modified_date` | `Y-m-d H:i:s` | |
| `last_used_date` | string \| null | |

## Methods

### `create_tag( array $data ): int|WP_Error`

Returns the new tag ID. Input keys: `tag_name` (required), `shortcode` (optional: generated from
`tag_name` and made unique when omitted), `content`, `description`, `category_id`, `priority`, `status`,
`meta_data` (array). The legacy keys `title` (= `tag_name`) and `group_id` (= `category_id`) are accepted as
aliases; the real field wins when both are given; keys that are not columns (such as `id`) are dropped.
Fires `wp_dynamic_tags_tag_created( $id, $data )` and registers the shortcode for the rest of the request.

Rules applied by the **Tag Service** (also to the screens and to import):

* the shortcode may not be one of the plugin's own (`dt`, `dt_group`, `dt_random`, `dt_count`, `dt_list`,
  `dt_loop`, `dt_if`, `dt_else`, `dt_template`, or anything starting with `dt_table_`) and may not be a name
  that WordPress or another plugin owns (filter `dt_allow_shortcode_override` can allow it);
* the tag may not render itself, directly or through other tags (`[a]` in `a`, or `a` and `b` referring to
  each other, also by `tag="..."` / `key="..."`);
* content written by a logged-in user without the `unfiltered_html` capability goes through `wp_kses_post`;
  code that runs without a user (cron, WP-CLI, another plugin's setup) is trusted;
* filter `dt_can_create_tag` is the single place a limit on the number of tags is decided.

Errors: `table_not_available`, `validation_failed` (the message lists every problem: missing name, bad
characters, duplicate name, duplicate shortcode, priority range, status value), `duplicate_tag_name` /
`duplicate_shortcode` / `insert_failed` (repository level), `dt_reserved_shortcode`,
`dt_shortcode_conflict`, `dt_circular_reference`, `dt_tag_limit`.

### `update_tag( int $id, array $data ): true|WP_Error`

Partial update: only the keys supplied are validated and changed. The Tag Service checks only what changes
(a new shortcode name, new content), so saving a description never fails because of a name or a reference
that was already there. Fires `wp_dynamic_tags_tag_updated( $id, $data, $old_tag )`.
Errors: `table_not_available`, `invalid_id`, `tag_not_found`, `validation_failed`, `duplicate_tag_name`,
`duplicate_shortcode`, `update_failed`, and the Tag Service codes above.

### `delete_tag( int $id ): true|WP_Error`

Fires `wp_dynamic_tags_tag_deleted( $id, $old_tag )`. Errors: `invalid_id`, `tag_not_found`, `delete_failed`.

### `get_tag( int $id )`, `get_tag_by_name( string )`, `get_tag_by_shortcode( string )`: `object|false|null`

Return the tag object, or a falsy value when not found (`null` from the name and shortcode lookups,
`false` for an invalid ID). Callers must test truthiness, not type.

### `get_tags( array $args = [] ): array`

Returns a list of tag objects; `[]` when the table is unavailable. Args:

| Arg | Default | Meaning |
|---|---|---|
| `status` | `'active'` | `'active'`, `'inactive'`; empty string = any |
| `category_id` | `null` | filter by group term ID |
| `search` | `''` | substring match on name, shortcode, content and description |
| `orderby` | `'priority'` | `id`, `tag_name`, `priority`, `usage_count`, `created_date`, `modified_date` |
| `order` | `'DESC'` | `ASC` / `DESC` |
| `limit`, `offset` | `null`, `0` | page size / start; a `limit` of 0 or less means no limit |

Convenience wrappers: `search_tags()`, `get_tags_by_category()`, `get_popular_tags()`, `get_recent_tags()`.

### `bulk_action( string $action, array $ids, array $data = [] ): array`

Actions: `delete`, `activate`, `deactivate`, `update_category` (`$data['category_id']`),
`update_priority` (`$data['priority']`). Returns `[ id => true|WP_Error ]`; an unknown action yields
`invalid_action` per ID.

### `track_usage( int $tag_id ): bool`

Increments the usage counter at once. **Rendering does not call it any more:** counting during renders is off
by default (option `wp_dynamic_tags_track_usage`, filter `dt_track_usage`) and, when on, is kept in memory
and written once at `shutdown`, one `UPDATE` per distinct tag, never on admin, AJAX, REST, cron or preview
requests. Fires `wp_dynamic_tags_tag_used( $tag_id )` (in memory on every render).

## Filters

| Filter | Arguments | Default | Meaning |
|---|---|---|---|
| `dt_manage_capability` | `$cap` | `manage_options` | capability for every admin screen and endpoint of the plugin |
| `dt_can_create_tag` | `$allowed`, `$data` | `true` | return `false`, or a `WP_Error`, to refuse a new tag (`dt_tag_limit`) |
| `dt_allow_shortcode_override` | `$allow`, `$shortcode` | `false` | let a tag use a name another plugin owns |
| `dt_process_value` | `$content` | | renders placeholders, formatters and conditionals; used by chatbot-by-eshaan |
| `dt_kses_tag_output` | `$filter`, `$tag` | `false` | run the output of every per-tag shortcode through `wp_kses_post` |
| `dt_max_render_depth` | `$depth` | `10` | nesting limit of tags rendering tags |
| `dt_max_render_size` | `$bytes` | `1048576` | output size limit of one render |
| `dt_track_usage` | `$on` | the option | count renders (see `track_usage`) |
| `dt_register_shortcodes_on_rest` | `$on` | `true` | register the shortcodes on REST requests |
| `dt_evaluate_condition` | `$result`, `$condition` | `false` | add a condition name to `{if}` / `[dt_if]` |
| `dt_available_placeholders` | `$placeholders` | | the placeholder list shown in the editor |
| `dt_allow_meta_key` | `$allowed`, `$key`, `$type` | | allow or refuse a meta key for `{meta:}`, `{user_meta:}`, `{term_meta:}` |
| `dt_query_allowed_args` | `$args` | whitelist | query arguments `{query:}` and `[dt_template]` accept |
| `dt_query_post_statuses` | `$statuses` | what the viewer may read | cannot widen visibility |
| `dt_query_max_posts` | `$n` | `50` | row cap of a query |
| `dt_enable_api_placeholder` | `$on` | `true` | switch `{api:}` off |
| `dt_api_allowed_hosts` | `$hosts` | `[]` | host allow-list for `{api:}` (empty = any public host) |
| `dt_api_max_fetches` | `$n` | `5` | `{api:}` fetches per request |
| `dt_currency_symbol` | `$symbol` | `$` | symbol of the `currency` formatter |
| `dt_import_max_bytes` | `$bytes` | 5 MB | upload size cap of an import |
| `dt_import_max_rows` | `$rows` | `5000` | rows per import file (0 or less: no limit) |
| `dt_list_per_page` | `$n` | `20` | rows per page of the All Tags screen |
| `dt_purge_page_caches` | `$on` | `true` | purge the supported page-cache plugins when a tag or group changes (see Actions) |
| `dt_debug_mode` | `$on` | the constant `DT_DEBUG_MODE` | record the debug log |

## Actions

| Action | Arguments |
|---|---|
| `wp_dynamic_tags_tag_created` | `$id`, `$data` |
| `wp_dynamic_tags_tag_updated` | `$id`, `$data`, `$old_tag` |
| `wp_dynamic_tags_tag_deleted` | `$id`, `$old_tag` |
| `wp_dynamic_tags_tag_used` | `$tag_id` |
| `dt_shortcode_conflict` | `$name`, `$owner`: a tag was not registered because another plugin owns its name |
| `dt_tags_changed` | none. Fires once, at the end of a request, after a tag or a group changed in a way a page can show (not for a save that changes nothing, nor for a usage count). Listen to it to purge a cache layer of your own. |

When a tag or a group changes, the page caches of WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache,
WP Fastest Cache, SiteGround Optimizer, Hummingbird, Breeze, Cache Enabler, Nginx Helper and the Cloudflare plugin are
purged once per request, so an edit shows on the whole site at once. The plugin itself keeps nothing between requests.

## Shortcodes

Every active tag registers its own `[shortcode]`. The core shortcodes:

| Shortcode | Attributes | Purpose |
|---|---|---|
| `[dt key="name"]` (`tag="name"` is an alias) | `default` | render a tag by shortcode, normalised name or legacy alias |
| `[dt_group group="..."]` | `format` (`inline`, `list`, `grid`), `separator`, `before`, `after`, `limit`, `order`, `orderby` | render the tags of a group |
| `[dt_random group="..."]` | `count` | one or more random tags of a group |
| `[dt_count group="..."]` | `format` (`number`, `text`) | how many tags a group holds |
| `[dt_list group="..."]` | `template`, `separator`, `limit`, `order`, `orderby` | a templated list |
| `[dt_loop source="..."]...[/dt_loop]` | `separator`, `limit`, `empty` | repeat the enclosed template per item of an array |
| `[dt_if condition="..."]...[dt_else]...[/dt_if]` | | block-level visibility |
| `[dt_template tag="..." query="..."]` | `separator`, `before`, `after`, `empty` | render a tag once per post of a query |
| `[dt_table_group]`, `_random`, `_list`, `_search`, `_stats` | | helpers that return names, links and shortcodes (not content) |

## Groups

Groups are terms of the taxonomy `tag_groups`; a tag's group is its `category_id`. Deleting a group sets
`category_id` to 0 on its tags, whoever deletes it (`delete_tag_groups`). The Groups screen and its AJAX
endpoints (`dt_create_group`, `dt_edit_group`, `dt_delete_group`, `dt_get_group_details`,
`dt_get_group_edit_data`) require `dt_manage_capability` and their own nonces.

## Import and export

`WP_Dynamic_Tags_Table_Import_Export` (`get_instance()`):

* `export_data( $format = 'json', $filters = [] )`: `json`, `csv`, `xml` or `sql`; filters `status`
  (`active`, `inactive`) and `category_id`. Returns `['success', 'data', 'filename', 'stats']` or `WP_Error`.
* `import_data( $text, $format, $options = [] )`: `json`, `csv` or `xml` (SQL is export only); options
  `update_existing`, `skip_duplicates`, `validate_only`. A dry run returns `['success', 'validation',
  'total_records']` with `validation` = `valid`, `errors` (keyed `Row N`, N from 1), `warnings`,
  `total_rows`, `valid_rows`, `invalid_rows`, `invalid_row_numbers`; an import returns `['success',
  'stats']` with `created`, `updated`, `skipped`, `errors` and `error_details` (`row`, `error`).
  Errors: `unsupported_format`, `invalid_json`, `invalid_xml`, `xml_doctype_not_allowed`, `xml_encoding`,
  `csv_header`, `no_rows`, `too_many_rows`, `import_failed`.
* File format: see the README ("Import and export"). A CSV text cell that starts with `=`, `+`, `-`, `@`, a
  tab or a carriage return carries a leading apostrophe in the file; the import removes exactly that one.

## Migration (WP-CLI)

`wp dynamic-tags status | migrate [--dry-run] | verify | cleanup | conflicts | resolve | restore | rescan`.
The same engine runs the **Tools** screen. Legacy tag posts are never deleted without a confirmation, and a
backup of exactly what is deleted is written first.

## Removed in 3.1

The post-to-table Bridge, dual mode, the post editor for tags, the Bulk Manager, the Conflict Resolver and
Conflict Manager, the old Table Manager and import/export pages, the Migration Manager screens, and their
AJAX actions. The complete list is in `docs/RELEASE-NOTES-3.1.0.md`. The facade, the hooks above and the
shortcodes are unchanged.

## Deviations found in 3.0.0 and fixed in 3.1

Evidence: `tests/golden-master/baseline/siblings.json` (3.0.0) against `tests/golden-master/siblings.php`.

| # | 3.0.0 behaviour | Who was hit | 3.1 behaviour |
|---|---|---|---|
| B1 | `update_tag( $id, ['content' => ...] )` always returned `validation_failed: "Tag name is required."`; `bulk_action('activate'/'deactivate')` failed the same way. | Certificate Generator (`post-types.php:88`) and chatbot (`class-chatbot-integrations.php:688`): re-syncing an existing tag never changed it. | Partial updates validate only the keys supplied. |
| B2 | `get_tags( ['limit' => 0] )` built `LIMIT 0, 0` and returned no rows. | certificate-generator-gema dashboard counters (`integration-dashboard.php:133-144`) were always 0. | `limit <= 0` or `null` means no limit. |
| B3 | `search` matched name, content and description, not the shortcode. | Same dashboard: `search => 'cg_'` could never match a `cg_*` shortcode. | `search` also matches `shortcode`. |
| B4 | `create_tag( ['title' => ..., 'group_id' => ...] )` failed with `Tag name is required.` | chatbot-by-eshaan auto-setup (`class-chatbot-integrations.php:459`). | The aliases `title` and `group_id` are accepted. |
| B5 | Creating a tag mirrored it into a `dynamic_tag` post whose `post_id` link was never saved, so the mirror was never deleted with the tag. | any caller of `create_tag` | No mirror posts exist. |
| B6 | `Table_Manager::init` started a migration on **frontend** requests. | every site with unmigrated posts | Migration runs only from the admin, WP-CLI or cron. |
| B7 | A `{meta:}` token with an empty key rendered the text `Array`. | any tag using it | Renders empty. |
| B8 | The post-based registrar cached its tag list in a one-hour transient; after tag posts were deleted every request kept registering and test-rendering the deleted tags, writing meta against posts that no longer existed. | any site where tag posts are deleted | No tag-list transient; one registry read per request from the table. |
| B9 | `register_shortcodes()` returned early while a 3-second transient lock was set, which could leave `[dt]` and every tag as raw text. | post-only sites with an empty table | No cross-request locks. |

## Compatibility and deprecation policy

* Signatures, accepted input keys, return types and `WP_Error` codes in this document are stable for the
  3.x series. Adding optional keys or new error codes is allowed; removing or renaming is not.
* A method slated for removal is marked `@deprecated X.Y` with `_deprecated_function()` for at least one
  minor release before it is removed, and its replacement is named here.
* Hooks follow the same rule: arguments may be appended, never reordered or removed.
* The compatibility window for legacy tag posts (capture of writes, read-only rendering of unmigrated posts)
  is kept for all of 3.x and removed in 4.0.
