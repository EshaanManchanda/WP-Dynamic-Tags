# WP Dynamic Tags — public PHP API

This is the contract other plugins (Certificate Generator, certificate-generator-gema,
chatbot-by-eshaan) rely on. **`WP_Dynamic_Tags_Table_Manager` is the stable facade**: its
method signatures, accepted input keys and return shapes must not change in a minor release.
Internals (repository, service, renderer classes) may be refactored freely behind it.

Behaviour below was recorded from the pre-refactor code by `tests/golden-master/siblings.php`
(result: `tests/golden-master/baseline/siblings.json`). Where recorded behaviour is a bug the
callers evidently do not intend, it is listed under **Known deviations** with the 3.1 fix.

## Obtaining the facade

```php
if ( class_exists( 'WP_Dynamic_Tags_Table_Manager' ) ) {
    $dt = WP_Dynamic_Tags_Table_Manager::get_instance();
}
```

Detection that callers use today and that must keep working:
`class_exists('WP_Dynamic_Tags_Plugin')`, `class_exists('WP_Dynamic_Tags_Table_Manager')`,
`post_type_exists('dynamic_tag')` (the post type stays registered as an empty, hidden shim).

## Tag object

Returned by `get_tag*()` and as items of `get_tags()` (`stdClass`). Database columns arrive as strings.

| Property | Type | Notes |
|---|---|---|
| `id` | numeric string | primary key |
| `tag_name` | string | unique, human label; letters, digits, space, `_`, `-` |
| `shortcode` | string | unique; the `[shortcode]` that renders the tag; letters, digits, `_`, `-` |
| `content` | string | template: placeholders, formatters, conditionals |
| `description` | string | |
| `category_id` | numeric string | `tag_groups` term ID, `0` = none |
| `priority` | numeric string | 0–100 |
| `usage_count` | numeric string | |
| `status` | `active` \| `inactive` | only `active` tags register a shortcode |
| `meta_data` | array | unserialized; `[]` when empty |
| `post_id` | numeric string | legacy link to a `dynamic_tag` post, `0` = none (kept for migration only) |
| `created_date`, `modified_date` | `Y-m-d H:i:s` | |
| `last_used_date` | string \| null | |

## Methods

### `create_tag( array $data ): int|WP_Error`

Returns the new tag ID. Input keys: `tag_name` (required), `shortcode` (optional — generated
from `tag_name` and made unique when omitted), `content`, `description`, `category_id`,
`priority`, `status`, `meta_data` (array). Fires `wp_dynamic_tags_tag_created( $id, $data )`
and registers the shortcode immediately for the rest of the request.

Errors: `table_not_available`, `validation_failed` (message lists every problem; covers missing
name, bad characters, duplicate name, duplicate shortcode, priority range, status value),
`duplicate_tag_name` / `duplicate_shortcode` / `insert_failed` (repository level).

### `update_tag( int $id, array $data ): true|WP_Error`

Partial update: only the keys supplied are changed. Fires
`wp_dynamic_tags_tag_updated( $id, $data, $old_tag )`.
Errors: `table_not_available`, `invalid_id`, `tag_not_found`, `validation_failed`,
`duplicate_tag_name`, `duplicate_shortcode`, `update_failed`.

### `delete_tag( int $id ): true|WP_Error`

Fires `wp_dynamic_tags_tag_deleted( $id, $old_tag )`. Errors: `invalid_id`, `tag_not_found`, `delete_failed`.

### `get_tag( int $id )`, `get_tag_by_name( string )`, `get_tag_by_shortcode( string )`: `object|false|null`

Return the tag object, or a falsy value when not found (`null` from the name/shortcode lookups,
`false` for an invalid ID). Callers must test truthiness, not type.

### `get_tags( array $args = [] ): array`

Returns a list of tag objects; `[]` when the table is unavailable. Args:

| Arg | Default | Meaning |
|---|---|---|
| `status` | `'active'` | `'active'`, `'inactive'`; empty string = any |
| `category_id` | `null` | filter by group term ID |
| `search` | `''` | substring match |
| `orderby` | `'priority'` | `id`, `tag_name`, `priority`, `usage_count`, `created_date`, `modified_date` |
| `order` | `'DESC'` | `ASC` / `DESC` |
| `limit`, `offset` | `null`, `0` | page size / start |

Convenience wrappers: `search_tags()`, `get_tags_by_category()`, `get_popular_tags()`, `get_recent_tags()`.

### `bulk_action( string $action, array $ids, array $data = [] ): array`

Actions: `delete`, `activate`, `deactivate`, `update_category` (`$data['category_id']`),
`update_priority` (`$data['priority']`). Returns `[ id => true|WP_Error ]`; an unknown action
yields `invalid_action` per ID.

### `track_usage( int $tag_id ): bool`

Increments the usage counter. Fires `wp_dynamic_tags_tag_used( $tag_id )`.

## Actions

| Action | Arguments |
|---|---|
| `wp_dynamic_tags_tag_created` | `$id`, `$data` |
| `wp_dynamic_tags_tag_updated` | `$id`, `$data`, `$old_tag` |
| `wp_dynamic_tags_tag_deleted` | `$id`, `$old_tag` |
| `wp_dynamic_tags_tag_used` | `$tag_id` |

Filter `dt_process_value` ( `$content` ) renders template content (used by chatbot-by-eshaan).

## Known deviations (recorded 2026-11-10 — fixed in 3.1)

Evidence: `tests/golden-master/baseline/siblings.json`.

| # | Current behaviour | Who is hit | 3.1 behaviour |
|---|---|---|---|
| B1 | `update_tag( $id, ['content' => …] )` always returns `validation_failed: "Tag name is required."` — `tag_name` is demanded even on partial updates. `bulk_action('activate'/'deactivate')` fails the same way. | Certificate Generator (`post-types.php:88`) and chatbot (`class-chatbot-integrations.php:688`): re-syncing an existing tag never changes it. | Partial updates validate only supplied keys. |
| B2 | `get_tags( ['limit' => 0] )` builds `LIMIT 0, 0` and returns no rows. | certificate-generator-gema dashboard counters (`integration-dashboard.php:133-144`) are always 0. | `limit <= 0` or `null` means no limit. |
| B3 | `search` matches name, content, description — not the shortcode. | Same dashboard: `search => 'cg_'` can never match a `cg_*` shortcode. | `search` also matches `shortcode`. |
| B4 | `create_tag( ['title' => …, 'group_id' => …] )` fails with `Tag name is required.` | chatbot-by-eshaan auto-setup (`class-chatbot-integrations.php:459`). | Not changed unless the compatibility alias is approved (`title`→`tag_name`, `group_id`→`category_id`). |
| B5 | Creating a tag mirrors it into a `dynamic_tag` post; the write-back of `post_id` goes through B1 and is rejected, so the link is never saved and the mirror is never deleted with the tag (4 leaked posts in the recorded run). | any caller of `create_tag` | No mirror posts; the post type is an empty shim. |
| B6 | `Table_Manager::init` calls `migrate_posts_to_table()` on **frontend** requests (`class-table-manager.php:63-65`) despite its comment. | every site with unmigrated posts | Migration runs only from admin, WP-CLI or cron. |
| B7 | A `{meta:}` token with an empty key renders the literal text `Array`. | any tag using it | Renders empty. |

## Compatibility and deprecation policy

* Signatures, accepted input keys, return types and `WP_Error` codes in this document are stable
  for the 3.x series. Adding optional keys or new error codes is allowed; removing or renaming is not.
* A method slated for removal is marked `@deprecated X.Y` with `_deprecated_function()` for at
  least one minor release before it is removed, and its replacement is named here.
* Hooks follow the same rule: arguments may be appended, never reordered or removed.
