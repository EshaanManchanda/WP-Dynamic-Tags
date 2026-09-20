=== WP Dynamic Tags ===
Contributors: eshaanmanchanda
Tags: dynamic content, shortcodes, merge tags, elementor, woocommerce
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create unlimited dynamic content tags and use them as builder-agnostic shortcodes in WordPress, Elementor, and any other page builder.

== Description ==

WP Dynamic Tags lets you create reusable, dynamic content snippets ("tags") and insert them anywhere as shortcodes — the WordPress block editor, Elementor, Divi, widgets, or any theme template that runs `do_shortcode()`. Unlike page-builder-specific dynamic tag features, these shortcodes work everywhere.

= Core features =

* Unlimited dynamic tags, organized into groups
* A rich placeholder engine: post, taxonomy, author, site, URL, and date placeholders, plus parameterized fields for post/user/term meta, ACF (including repeaters, galleries, and relationship fields), and WooCommerce product data
* Formatters (`|upper`, `|currency`, `|date`, array-aware `|join`/`|count`/`|first`/`|last`, and more) and a fallback syntax (`{token??Default text}`)
* Conditionals — both inline (`{if:condition}...{/if}`) and block-level (`[dt_if]`), able to test a field's value (`{if:wc:price>50}`), not just built-in site/user state
* `[dt_loop]` to repeat a template per item of an array (an ACF repeater, a query result, …)
* `[dt_template]` to render a Dynamic Tag once per post in a query — a repeating card/list template
* `{query:...}` and `{api:...}` data sources, and a whitelist-based `{func:...}` developer escape hatch
* Dual storage (WordPress post type or an optimized custom database table) with bi-directional migration
* Conflict detection between tag names/shortcodes
* Import/export in JSON, CSV, XML, and SQL

See the plugin's `ROADMAP.md` and `TESTING.md` (in the plugin directory) for the complete feature list and a full manual test guide.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-dynamic-tags`, or install the plugin through the WordPress admin's "Add New Plugin" screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Dynamic Tags → Add New** to create your first tag.
4. Use the generated shortcode anywhere `do_shortcode()` runs.

== Frequently Asked Questions ==

= Does this work with Elementor / Divi / other page builders? =

Yes — every tag is a standard WordPress shortcode, so it works in any builder or theme that renders shortcodes, not just one specific builder's own dynamic-tag system.

= Does it require ACF or WooCommerce? =

No. ACF and WooCommerce integration are entirely optional and inert if those plugins aren't installed — the core placeholder engine, formatters, conditionals, and shortcodes work with WordPress core alone.

= Can content editors run arbitrary PHP through a tag? =

No. The `{func:...}` placeholder only calls functions a developer has explicitly registered in code via `register_dynamic_tag_function()`. There is no `eval()` of tag content anywhere in the plugin.

= Is there a query builder or external API support? =

Yes — `{query:...}` runs a WP_Query and returns an array of post rows, and `{api:...}` fetches external JSON (via WordPress's SSRF-guarded `wp_safe_remote_get()`, with response caching).

== Screenshots ==

1. Creating a Dynamic Tag with the placeholder helper.
2. The tag list with shortcode, conflict status, and usage columns.

== Changelog ==

= 3.0.0 =
* Array/repeater primitives (`|join`, `|count`, `|first`, `|last`, `[dt_loop]`)
* WooCommerce field placeholders (`{wc:...}`)
* ACF repeater/gallery/relationship support via dot notation (`{acf:field.subfield}`)
* User meta / term meta placeholders
* Field-based conditionals and block-level `[dt_if]`/`[dt_else]` visibility
* `[dt_template]` per-record rendering
* `{query:...}` and `{api:...}` data sources
* Whitelist-based `{func:...}` developer escape hatch
* Several cache-invalidation bug fixes

= 2.0.0 =
* Custom database table storage option with bi-directional migration from the post-type storage
* Post-table bridge, table-based shortcode processor
* Enhanced import/export (added XML and SQL formats)
* Multi-level caching, conflict resolution, and admin UI improvements

= 1.x =
* Initial release: post-type-based dynamic tags, groups, and basic shortcodes

== Upgrade Notice ==

= 3.0.0 =
Adds a large set of new placeholder types and shortcodes; fully backward compatible with existing tags. If you use a persistent object cache (Redis/Memcached), this release also fixes a bug where editing a tag could serve stale data until the cache naturally expired.
