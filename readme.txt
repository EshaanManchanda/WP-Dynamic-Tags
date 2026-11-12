=== WP Dynamic Tags ===
Tags: dynamic content, shortcodes, merge tags, elementor, woocommerce
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create reusable dynamic content tags and use them as builder-agnostic shortcodes in WordPress, Elementor, and any other page builder.

== Description ==

WP Dynamic Tags lets you create reusable, dynamic content snippets ("tags") and insert them anywhere as shortcodes — the WordPress block editor, Elementor, Divi, widgets, or any theme template that runs `do_shortcode()`. Unlike page-builder-specific dynamic tag features, these shortcodes work everywhere.

= Free and Paid =

The plugin is complete and usable on the Free plan, with no trial and no time limit. A Paid license unlocks the dynamic-data layer.

* **Free:** up to 15 tags that you create yourself (Add New, Duplicate, Import), groups, the post, taxonomy, author, site, URL and date placeholders, `{meta:key}` and scalar `{acf:field}`, all formatters, the `{token??fallback}` syntax, `[dt]`, `[dt_group]`, `[dt_random]`, `[dt_count]`, `[dt_list]`, `{if:...}` and `[dt_if]` with the built-in conditions (logged in, mobile, user role, page type, time of day), import and export, migration, and the full admin.
* **Paid:** unlimited tags, WooCommerce fields `{wc:...}`, ACF repeater, group, gallery and relationship fields, `{user_meta:...}` and `{term_meta:...}`, `[dt_loop]`, `[dt_template]`, field-based conditions (`{if:meta:status=active}`, `[dt_if condition="wc:price>50"]`), and the `{query:...}`, `{api:...}` and `{func:...}` data sources.
* Tags that other plugins create through the plugin's API (for example one tag per student from a certificate plugin) are never blocked and do not count toward the 15. The limit stops new tags only: every tag you already have keeps working, nothing is disabled, hidden or deleted. A Paid feature on a Free site renders nothing; visitors never see an upgrade message.
* The admin tells you what applies: "N of 15" above the tag list, a "Paid feature" label on a tag that uses one, and a note under the preview.

= Core features =

* Dynamic tags organized into groups
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

The complete documentation, the roadmap and a manual test guide are in the plugin's repository: https://github.com/EshaanManchanda/WP-Dynamic-Tags. The public PHP API is described in `docs/API.md` inside the plugin.

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

On the Free plan, 15 that you create yourself; on Paid, no limit. Tags that other plugins create do not count. Separately from the plan, every page request registers every active tag as a shortcode, so the cost grows with the number of tags. Measured without a page cache: about 2 MB and 10 ms extra per request at 1,000 tags, 10 MB and 55 ms at 5,000, 20 MB and 110 ms at 10,000, and 106 MB and 550 ms at 50,000. On shared hosting with one CPU core and 1 GB of RAM, keep a site to 5,000 tags, or 10,000 behind a page cache. The plugin shows a notice above the tag list when a site passes the recommended maximum for its server.

= What happens to my tags if I am over the Free limit? =

Nothing. The limit only stops you from creating more tags by hand. Every existing tag keeps rendering and can be edited, deactivated or deleted. Deleting tags makes room for new ones, and a Paid key removes the limit.

= What does a Paid feature do on a Free site? =

It renders nothing (a `{token??fallback}` you wrote still shows its fallback, and `[dt_loop]` and `[dt_template]` show their `empty="..."` text). Visitors never see an upgrade message. In the admin the tag carries a "Paid feature" label, and its edit form says which features it uses.

= Does the plugin contact any server? =

Only the ones listed under External services below: the license server when you activate a key (and for the twice-daily check while a key is stored), and the address a tag author chose for `{api:...}`. With no key and no `{api:...}` tag, the plugin makes no outbound request. It has no telemetry and sends nothing to its author.

= Is there a query builder or external API support? =

Yes (Paid) — `{query:...}` runs a WP_Query and returns an array of post rows, and `{api:...}` fetches external JSON (via WordPress's SSRF-guarded `wp_safe_remote_get()`, with response caching).

== External services ==

This plugin connects to two kinds of outside service, and only when you use them.

= The license server =

What it is: the license server of the plugin's author (`https://tlvapi.techlovev.in` by default; you can change it, https only, under Dynamic Tags > Settings). It checks Paid license keys.

What is sent, and when: when you activate a key, and about twice a day while a key is stored, the plugin sends the license key and this site's address (the home URL) to `/api/payments/activate-remote`; when you deactivate a license it sends the same two values to `/api/payments/deactivate-remote`. Nothing else about the site, its content or its users is sent. If no key is stored, nothing is sent at all.

= A data source that a tag author chooses =

If a tag uses the Paid `{api:URL}` placeholder, the plugin fetches that URL (public addresses only, JSON, at most 1 MB, at most 5 per request, cached for five minutes) and shows the data you select. The address is chosen by whoever writes the tag; the plugin does not send any site or user data with the request.

== Changelog ==

= 3.1.0 =
* The custom table is the only tag store; the post-to-table bridge, dual mode and the post editor for tags are gone
* Nothing is written to the database while a page renders; usage counting is opt-in
* New admin: one menu, a searchable and sortable list with a Value column, bulk actions, a form with a live preview, Tools and diagnostics
* Reserved and foreign shortcode names, circular references and markup are checked when a tag is saved
* Import and export rebuilt (lossless CSV, per-row reports, limits; SQL is export only)
* Settings now explains how tags work and why a lot of tags slow a site down, shows the site's live numbers (tags, PHP memory limit, page cache, load time) with a Fine / Watch / Too many status, and recommends tag counts by kind of hosting
* Free and Paid plans: Free allows 15 tags that you create yourself (tags other plugins create are never blocked and do not count; existing tags always keep working); Paid is unlimited and adds the WooCommerce, ACF structure, `{user_meta:}`, `{term_meta:}`, `{query:}`, `{api:}`, `{func:}`, `[dt_loop]`, `[dt_template]` and field-condition features. Keys are checked only by the license server, over https
* The admin shows "N of 15" on the Free plan, labels tags that use a Paid feature, and warns above the recommended number of tags for the server
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
Back up first, then run Dynamic Tags > Tools to move tags stored as posts into the table (dry run, migrate, verify; nothing is deleted without your confirmation). New Free and Paid plans: Paid features render nothing on Free, and Free allows 15 tags you create. See docs/RELEASE-NOTES-3.1.0.md.

= 3.0.0 =
Adds a large set of new placeholder types and shortcodes; fully backward compatible with existing tags. If you use a persistent object cache (Redis/Memcached), this release also fixes a bug where editing a tag could serve stale data until the cache naturally expired.
