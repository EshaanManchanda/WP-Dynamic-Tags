=== WP Dynamic Tags ===
Tags: dynamic content, shortcodes, merge tags, elementor, woocommerce
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
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
* One tag store: an optimized custom database table; nothing is written to the database while a page renders
* One admin screen with search, filters, sorting, bulk actions, a live preview and a "Value" column that shows what each tag renders to
* Safe by design: a tag cannot take a shortcode name that belongs to the plugin, WordPress or another plugin, and cannot render itself
* Migration of tags stored by older versions as posts, in batches, with a dry run, verification, backups and WP-CLI commands
* Import from JSON, CSV and XML (with a per-row report) and export to JSON, CSV, XML and SQL

See the plugin's `ROADMAP.md` and `TESTING.md` (in the plugin directory) for the complete feature list and a full manual test guide.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the plugin through the WordPress admin's "Add New Plugin" screen.
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

= How many tags can I create? =

There is no built-in limit, but every page request registers every active tag as a shortcode, so the cost grows with the number of tags. Measured without a page cache: about 2 MB and 10 ms extra per request at 1,000 tags, 10 MB and 55 ms at 5,000, 20 MB and 110 ms at 10,000, and 106 MB and 550 ms at 50,000. On shared hosting with one CPU core and 1 GB of RAM, keep a site to 5,000 tags, or 10,000 behind a page cache.

= Is there a query builder or external API support? =

Yes — `{query:...}` runs a WP_Query and returns an array of post rows, and `{api:...}` fetches external JSON (via WordPress's SSRF-guarded `wp_safe_remote_get()`, with response caching).

== Changelog ==

= 3.1.0 =
* The custom table is the only tag store; the post-to-table bridge, dual mode and the post editor for tags are gone
* Nothing is written to the database while a page renders; usage counting is opt-in
* New admin: one menu, a searchable and sortable list with a Value column, bulk actions, a form with a live preview, Tools and diagnostics
* Reserved and foreign shortcode names, circular references and markup are checked when a tag is saved
* Import and export rebuilt (lossless CSV, per-row reports, limits; SQL is export only)
* The findings of a security and reliability audit of 3.0.0 are fixed; see docs/BUG-REGISTER.md and docs/RELEASE-NOTES-3.1.0.md

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

= 3.1.0 =
Back up your database first, then open Dynamic Tags > Tools to move tags that older versions stored as posts into the table (dry run, migrate, verify). Old posts are never deleted without your confirmation. Output of some tags changes in documented ways: see docs/RELEASE-NOTES-3.1.0.md.

= 3.0.0 =
Adds a large set of new placeholder types and shortcodes; fully backward compatible with existing tags. If you use a persistent object cache (Redis/Memcached), this release also fixes a bug where editing a tag could serve stale data until the cache naturally expired.
