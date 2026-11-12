# WP Dynamic Tags

> Create and manage unlimited dynamic tags as shortcodes for use in WordPress, Elementor, and other page builders.

[![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)](https://github.com/EshaanManchanda/WP-Dynamic-Tags)
[![WordPress](https://img.shields.io/badge/WordPress-7.0%2B-brightgreen.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL%20v2%2B-red.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 📋 Description

**WP Dynamic Tags** is a powerful WordPress plugin that allows you to create unlimited dynamic content tags and use them as shortcodes throughout your website. Whether you're using the default WordPress editor, Elementor, or any other page builder, this plugin seamlessly integrates to provide dynamic, reusable content snippets.

Perfect for:
- 🏢 **Agencies** managing multiple client sites with reusable content
- 📝 **Content creators** who need consistent messaging across pages
- 🛒 **E-commerce sites** with frequently changing promotional content
- 🎨 **Page builder users** (Elementor, Beaver Builder, etc.)
- 🔧 **Developers** needing a flexible content management solution

---

## ✨ Key Features

### 🎯 Core Functionality
- **Unlimited Dynamic Tags** - Create as many tags as you need with custom content
- **Universal Shortcode Support** - Works with the block editor, Elementor and any builder or theme that runs shortcodes
- **One tag store** - Tags live in a single optimized database table, and nothing is written to the database while a page renders
- **Safe migration** - Tags from older versions (stored as posts) are migrated in batches, verified, and only removed when you confirm

### 📁 Organization & Management
- **One screen** - Search, filter, sort and page through your tags, see what each one renders to, edit, duplicate, activate, deactivate or delete them, singly or in bulk
- **Live preview** - See a tag's output while you edit it, rendered by the same engine as your pages
- **Tag Groups** - Organize tags in a hierarchy of groups (a tag belongs to one group)
- **Safe by design** - No tag can take a shortcode name that belongs to the plugin, to WordPress or to another plugin, and no tag can render itself

### 🔄 Dynamic Placeholders
Automatically inject real-time data into your tags:

**User Data:**
- `{user_name}` - Current user's username
- `{user_email}` - User's email address
- `{user_display_name}` - User's display name
- `{user_first_name}` / `{user_last_name}` - User's first/last name
- `{user_role}` - User's role

**Date & Time:**
- `{current_year}` - Current year
- `{current_month}` - Current month
- `{current_day}` - Current day
- `{current_date}` - Full date
- `{current_time}` - Current time
- `{current_datetime}` - Date and time

**Site Information:**
- `{site_name}` - Your site's name
- `{site_url}` - Site URL
- `{site_description}` - Site tagline/description
- `{admin_email}` - Admin email
- `{site_language}` - Site language
- `{site_charset}` - Character set

**Post Context:**
- `{post_title}` - Current post title
- `{post_id}` - Post ID
- `{post_author}` - Post author name
- `{post_date}` - Post publish date
- And more...

### 🧩 The Placeholder Engine (Meta, ACF, WooCommerce, Formatters, Conditionals)

Beyond the flat placeholders above, every `{token}` supports:

- **Parameterized fields**: `{meta:key}`, `{user_meta:key}`, `{term_meta:key}`,
  `{acf:field}` (requires ACF; use `field.subfield` to pull a column out of a
  repeater/group), `{wc:price}` and other WooCommerce fields (requires
  WooCommerce), `{query:post_type=...&posts_per_page=...}` (query posts as
  an array), `{api:https://...::json.path}` (fetch external JSON), and
  `{func:name}` (call a function a developer explicitly registered in code
  via `register_dynamic_tag_function()` — never arbitrary code from tag
  content).
- **Formatters**: `{token|upper}`, `{meta:price|currency}`,
  `{post_title|upper|strip_html}`, plus array-aware formatters `|join:, `,
  `|count`, `|first`, `|last` for any token that resolves to a list.
- **Fallbacks**: `{token??Default text}` — shown when the token resolves empty.
- **Conditionals**: `{if:condition}...{else}...{/if}`, where `condition` can
  be a built-in check (`user_logged_in`, `is_mobile`, `user_role:admin`, …)
  or a comparison against any token's value, e.g. `{if:wc:price>50}`.
- **`[dt_loop source="..."]{item}[/dt_loop]`** — repeat a template once per
  item of an array-returning source (an ACF repeater/gallery, `{query:...}`,
  etc.).
- **`[dt_if condition="..."][dt_else]...[/dt_if]`** — the block-level version
  of `{if:}`: hides/shows a whole chunk of content (HTML, other shortcodes),
  not just a token's own text. Note: write `&lt;`/`&gt;` instead of a literal
  `<`/`>` inside the `condition="..."` attribute (a WordPress core shortcode-
  parsing limitation, not specific to this plugin).
- **`[dt_template tag="..." query="..."]`** — render an existing Dynamic Tag
  once per post in a query, like a repeating card/list template.

See `TESTING.md` for a full, runnable test case for every one of these, and
`ROADMAP.md` for the complete feature list and what's still planned.

### 🔧 Special Shortcodes

#### `[dt]` - Fallback Handling
Display alternative content if a tag doesn't exist (or resolves empty):
```
[dt key="promo_text" default="No promotion available"]
```

#### `[dt_group]` - Group Display
Display all tags from a specific group:
```
[dt_group name="footer_links" separator=", "]
```

#### `[dt_random]` - Random Tag
Display a random tag from a group or all tags:
```
[dt_random group="testimonials"]
[dt_random] <!-- Random from all tags -->
```

#### `[dt_count]` - Tag Counter
Display the count of tags (optionally by group):
```
[dt_count] <!-- Total tags -->
[dt_count group="products"] <!-- Tags in group -->
[dt_count group="products" format="text"] <!-- "five" instead of "5" -->
```

#### `[dt_list]` - Tag List
Display a formatted list of tags:
```
[dt_list group="features" type="ul"]
[dt_list group="team" type="ol"]
```

#### `[dt_loop]` - Repeat Per Item
Render a template once per item of an array-returning source:
```
[dt_loop source="acf:gallery"]<img src="{item}">[/dt_loop]
[dt_loop source="query:post_type=post&posts_per_page=3"]{item:title}[/dt_loop]
```

#### `[dt_if]` - Block-Level Visibility
Hide/show a whole block of content based on a condition:
```
[dt_if condition="wc:price&gt;50"]On sale![dt_else]Regular price[/dt_if]
```

#### `[dt_template]` - Per-Record Template
Render an existing Dynamic Tag once per post in a query:
```
[dt_template tag="product_card" query="post_type=product&posts_per_page=6"]
```

### 🛡️ Safe by Design
- **Reserved and foreign names** - A tag cannot be named like one of the plugin's own shortcodes (`dt`, `dt_group`, `dt_loop`, anything starting with `dt_table_`, ...) or like a shortcode WordPress or another plugin already owns
- **No loops** - A tag that would render itself, directly or through other tags, is refused when you save it (and cut off at render time as a second line of defence)
- **Filtered markup** - Content written by a user without the `unfiltered_html` capability is filtered like post content
- **Render limits** - Nesting depth and output size are capped (`dt_max_render_depth`, `dt_max_render_size`)
- **Token safety** - `{query:}` and `[dt_template]` only see what the visitor may read, `{meta:}` refuses protected keys, and `{api:}` only fetches public hosts, with size, type and count limits

### 📤 Import/Export Capabilities
- **Formats**: export to JSON, CSV, XML or SQL; import from JSON, CSV or XML (SQL is export only)
- **Selective Export** - Export all tags, only the active or inactive ones, or one group
- **Check first** - *Validate* lists every problem by row number and changes nothing
- **Good rows are imported, bad rows are reported** - each by its number in the file, with the reason
- **Duplicates** - skip tags whose shortcode already exists, or update them
- **Spreadsheet safe** - CSV cells a spreadsheet would run as a formula (they start with `=`, `+`, `-`, `@`, a tab or a carriage return) get a leading apostrophe on export; the import removes it again, so an export read back is identical
- **Sample files** - a minimal and a full CSV in the exact export layout

### ⚡ Performance
- **No writes while rendering** - Rendering a page does not update a counter, set a transient or check the schema
- **One query** - All active tags are loaded with a single query per request
- **Opt-in usage counting** - Off by default; when on, counts are kept in memory and written once at the end of the request, never on admin, AJAX, REST, cron or preview requests
- **Fast imports** - 5,000 rows import in about 15 to 20 seconds on a development PC
- **How many tags?** There is no built-in limit. Every request registers every active tag as a shortcode, so the cost grows with the number of tags. Measured on a development PC (PHP 8.2, MySQL 8.4, ten plugins active, no page cache), per uncached page: 1,000 tags about +2 MB and +10 ms; 5,000 about +10 MB and +55 ms; 10,000 about +20 MB and +110 ms; 20,000 about +40 MB and +210 ms; 50,000 about +106 MB and +550 ms. **On shared hosting (one CPU core, 1 GB RAM) keep a site to 5,000 tags, or 10,000 behind a page cache.** Larger sets work (a 50,000-tag site was imported, listed, searched and exported without errors) but every uncached request pays for them. Part of the cost is WordPress itself: `do_shortcode()` compares every registered shortcode name on each call. The same explanation, with this site's live numbers and a status, is on **Dynamic Tags > Settings**.

---

## 📦 Installation

### Method 1: WordPress Admin
1. Download the plugin ZIP file
2. Go to **Plugins > Add New** in your WordPress admin
3. Click **Upload Plugin** and select the ZIP file
4. Click **Install Now**
5. Activate the plugin

### Method 2: Manual Installation
1. Download and extract the plugin files
2. Upload the plugin folder to `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** menu in WordPress

### Requirements
- ✅ WordPress 7.0 or higher (single site or network)
- ✅ PHP 8.2 or higher
- ✅ A database version supported by WordPress 7.0 (tested on MySQL 8.4)

On a network the plugin works per site: each site has its own tags, table and settings, and the table is created the first time a site needs it.

---

## 🚀 Getting Started

### Creating Your First Dynamic Tag

1. **Open Dynamic Tags > Add New**

2. **Fill in the form**
   - **Name**: letters, numbers, spaces, hyphens and underscores (e.g. "Company Phone")
   - **Shortcode**: optional; made from the name when left empty (e.g. `company_phone`)
   - **Content**: e.g. `1-800-555-0100`. WordPress's editor, with its **Visual** and **Code** tabs; use **Code** for conditions that contain `<` or `>`. **Preview** shows the output.
   - **Group**, **Priority** and **Status** are optional

3. **Save, then use the shortcode**
   - Click **Copy** next to the shortcode in **All Tags**
   - Use `[company_phone]` anywhere on your site: posts, pages, widgets and page builders

### Using Dynamic Placeholders

Create a personalized greeting tag:
```
Content: Hello {user_display_name}, welcome back!
Shortcode: [greeting]
Output: Hello John Doe, welcome back!
```

Create a copyright tag:
```
Content: © {current_year} {site_name}. All rights reserved.
Shortcode: [copyright]
Output: © 2025 My Website. All rights reserved.
```

---

## 📖 Usage Guide

### Tag Groups / Organization

**Creating Tag Groups:**
1. Go to **Dynamic Tags > Groups**
2. Add a group, with an optional parent, colour and description
3. Put a tag in a group on its form, or filter **All Tags** by group

**What groups give you:**
- Logical organization of related tags, as a tree
- Group shortcodes (`[dt_group]`, `[dt_random group="..."]`, `[dt_count]`, `[dt_list]`)
- Filtering and exporting by group

A tag belongs to one group. Deleting a group does not delete its tags; they simply have no group afterwards.

### Managing Many Tags at Once

On **Dynamic Tags > All Tags**, select rows and choose a bulk action:
- ✅ Activate / ⏸ Deactivate
- 📋 Duplicate
- 🗑 Delete

Each row also has **Edit**, **Duplicate**, **Activate / Deactivate** and **Delete** links. The **Value** column shows what each tag renders to (blank when it cannot be rendered outside a page).

### Import/Export Workflows

#### Exporting Tags

1. Go to **Dynamic Tags > Import / Export**
2. Choose the format (JSON, CSV, XML, SQL)
3. Optionally filter by status and group
4. Click **Export**. The file downloads once, only for the person who exported, and the link works for ten minutes.

#### Importing Tags

1. Go to **Dynamic Tags > Import / Export**
2. Choose a `.json`, `.csv` or `.xml` file (SQL files are refused). Up to 5 MB and 5,000 rows by default.
3. Click **Validate** to see every problem by row number. Nothing is changed.
4. Tick the options you want:
   - **Skip duplicates** (on by default): a row whose shortcode already exists is left alone
   - **Update existing tags**: such a row replaces the content, description, priority and status of the existing tag. If both are ticked, update wins.
5. Click **Import**. Valid rows are imported; the rest are listed with their row numbers.

What to know about a file:

- **CSV** needs the header row the export writes (at least `Tag Name` and `Shortcode`); a spreadsheet's byte order mark, CRLF line endings, quoted values that span lines and unknown extra columns are fine. The sample files are in `assets/`.
- **XML** must be UTF-8 and must not contain a DOCTYPE or entity declaration.
- **Names and shortcodes** follow the same rules as the editor (letters, numbers, spaces, hyphens and underscores; the plugin's own shortcodes such as `dt_group` and names owned by WordPress or other plugins are refused; a tag may not refer back to itself).
- **Groups**: a group id that does not exist on this site is dropped (the tag is imported without a group), because ids differ between sites. An update never removes a tag from its group, and never replaces this site's usage counter or meta data with the file's.
- **Content** is filtered like everything saved in the editor: users without the `unfiltered_html` capability get post-style filtering.
- **Limits** can be changed with the filters `dt_import_max_bytes` (default 5 MB) and `dt_import_max_rows` (default 5,000).

### Moving from an Older Version

Tags created by earlier versions as `dynamic_tag` posts are moved into the table:

1. Back up your database
2. Open **Dynamic Tags > Tools**. The migration panel shows how many old tag posts are not yet migrated
3. Run the dry run, then migrate; it works in batches with a progress indicator
4. Verify. Old posts are **never deleted automatically**: after the verification passes you can choose to clean them up, and a backup of exactly what is deleted is written first

The same steps are available from WP-CLI: `wp dynamic-tags status | migrate [--dry-run] | verify | cleanup | conflicts | resolve | restore | rescan`.

Until you clean up, a write to an old tag post (by another plugin, for example) is copied into the table, and an old post that has no row yet still renders.

### Diagnostics and the Debug Log

**Dynamic Tags > Tools** shows the table's health, the shortcodes that were not registered because another plugin owns the name, the migration state, and the debug log. The log is off unless the filter `dt_debug_mode` (or the constant `DT_DEBUG_MODE`) is true; it records only tag ids, shortcodes, the kind of problem and the time, never content or credentials.

---

## 🎛️ Advanced Features

### Shortcodes Owned by Other Plugins

A tag whose shortcode name is already registered by WordPress or another plugin is never allowed to replace it: the form refuses the name, and a tag that is already in the table with such a name is skipped at render time and listed on the Tools screen (action `dt_shortcode_conflict`). The filter `dt_allow_shortcode_override` lets you allow a specific name.

### Delegating Tag Management

By default everything needs `manage_options`. The filter `dt_manage_capability` hands the **All Tags** and **Add New** screens (and the preview) to another capability, for example `edit_pages`. Import / Export, Tools, Settings, the licence settings and the migration always need `manage_options`.

### Edits Show on the Whole Site

The plugin keeps nothing between requests, so an edit to a tag shows on the next page load. If your site has a page
cache, it is purged for you when a tag or a group changes (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache,
WP Fastest Cache, SiteGround Optimizer, Hummingbird, Breeze, Cache Enabler, Nginx Helper and the Cloudflare plugin): once
per request, and only when something a page shows really changed. Other cache layers can listen to the action
`dt_tags_changed`; the filter `dt_purge_page_caches` turns the built-in purge off.

### Usage Counting

Off by default. Switch it on in **Dynamic Tags > Settings** (or with the filter `dt_track_usage`) to fill the **Used** column. Counts are kept in memory while the page renders and written once when the request ends.

### Developer Features

**Hooks:** the complete list of filters and actions, with arguments and defaults, is in [`docs/API.md`](docs/API.md). A few examples:

```php
// Change the content of every tag before it is displayed
add_filter('dt_process_value', function ($content) {
    return $content;
});

// Allow another plugin's shortcode name for a tag
add_filter('dt_allow_shortcode_override', function ($allow, $shortcode) {
    return $shortcode === 'my_known_name' ? true : $allow;
}, 10, 2);

// Refuse new tags beyond a limit
add_filter('dt_can_create_tag', function ($allowed, $data) {
    return count(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tags(array('status' => ''))) >= 100
        ? new WP_Error('my_limit', 'Tag limit reached.')
        : $allowed;
}, 10, 2);
```

**Creating tags from code** goes through the stable facade:

```php
$dt = WP_Dynamic_Tags_Table_Manager::get_instance();

$id = $dt->create_tag(array(
    'tag_name'  => 'Promo Text',
    'shortcode' => 'promo_text',
    'content'   => 'Special offer for {user_display_name}!',
    'status'    => 'active',
));
if (is_wp_error($id)) {
    // for example dt_reserved_shortcode, dt_shortcode_conflict, dt_circular_reference, validation_failed
}
```

---

## 📚 Shortcode Reference

### Basic Tag Shortcodes

```
[tag_name]                    - Display tag content
[tag_name-group-groupname]    - Display grouped tag
```

### Special Shortcodes

#### dt
```
[dt key="tagname" default="Default text"]
```
**Parameters:**
- `key` (required, `tag` also accepted as an alias) - Tag name to display
- `default` (optional) - Fallback text shown if the tag doesn't exist, or resolves empty

#### dt_group
```
[dt_group name="groupname" separator=", " before="<div>" after="</div>"]
```
**Parameters:**
- `name` (required) - Group name
- `separator` (optional) - Separator between tags (default: ", ")
- `before` (optional) - HTML before each tag
- `after` (optional) - HTML after each tag

#### dt_random
```
[dt_random group="groupname" cache="yes"]
```
**Parameters:**
- `group` (optional) - Limit to specific group
- `cache` (optional) - Cache random selection (default: "no")

#### dt_count
```
[dt_count group="groupname" format="text"]
```
**Parameters:**
- `group` (optional) - Count tags in specific group
- `format` (optional) - "number" or "text" (default: "number")

#### dt_list
```
[dt_list group="groupname" type="ul" class="custom-class"]
```
**Parameters:**
- `group` (optional) - List tags from group (default: all tags)
- `type` (optional) - "ul" or "ol" (default: "ul")
- `class` (optional) - Custom CSS class for list

---

## 🔍 Troubleshooting

### Common Issues

**Shortcodes Not Working**
- ✅ Ensure the plugin is activated and the tag is **Active**
- ✅ Check the shortcode in **All Tags** (use the **Copy** button)
- ✅ Open **Dynamic Tags > Tools**: it lists names another plugin owns
- ✅ Clear page and builder caches

**A Name Is Refused**
- ✅ Names and shortcodes must be unique
- ✅ `dt`, `dt_group`, `dt_loop` and the other plugin shortcodes are reserved, as is anything starting with `dt_table_`
- ✅ A name WordPress or another plugin uses cannot be taken

**A Tag Is Refused Because It Would Render Itself**
- ✅ Remove the `[shortcode]` of the tag (or of a tag that refers back to it) from its content

**Import Fails**
- ✅ Use a `.json`, `.csv` or `.xml` file (SQL cannot be imported)
- ✅ Ensure file encoding is UTF-8 (XML must be UTF-8)
- ✅ Click **Validate**: every problem is listed by row number
- ✅ Compare the file with the sample files in `assets/`
- ✅ Too many rows? Split the file or raise the `dt_import_max_rows` filter
- ✅ Check PHP upload limits (upload_max_filesize, post_max_size)

**Performance Issues**
- ✅ Keep usage counting off unless you need the counts
- ✅ Use a page cache for pages with many tags; rendering itself writes nothing
- ✅ Reduce the number of `{query:}` and `{api:}` placeholders on one page

**Migration Issues**
- ✅ Back up the database before you migrate
- ✅ Check PHP memory (256M or more is recommended for large sites)
- ✅ Use the dry run first, then **Verify**; every run keeps a log on the Tools screen
- ✅ A migration backup is a file in `uploads/wp-dynamic-tags-private/`; `wp dynamic-tags restore <file>` puts it back

---

## 🗂️ Database Structure

### Custom Table Schema

**Table Name:** `wp_dynamic_tags` (see `includes/class-database-manager.php` for the source of truth)

```sql
CREATE TABLE wp_dynamic_tags (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    tag_name varchar(255) NOT NULL,
    shortcode varchar(255) NOT NULL,
    content longtext,
    description text,
    category_id bigint(20) unsigned DEFAULT 0,
    priority int(11) DEFAULT 0,
    usage_count bigint(20) unsigned DEFAULT 0,
    created_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_used_date datetime NULL,
    status varchar(20) DEFAULT 'active',
    meta_data longtext,
    post_id bigint(20) unsigned DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY unique_tag_name (tag_name),
    UNIQUE KEY unique_shortcode (shortcode),
    KEY idx_category_id (category_id),
    KEY idx_status (status),
    KEY idx_priority (priority),
    KEY idx_usage_count (usage_count),
    KEY idx_created_date (created_date),
    KEY idx_post_id (post_id)
);
```

**Fields:**
- `id` - Unique identifier
- `tag_name` - Tag name (unique)
- `shortcode` - Registered shortcode name (unique)
- `content` - Tag content (supports HTML)
- `description` - Optional free-text description
- `category_id` - Group/category assignment
- `priority` - Sort/precedence weight
- `usage_count` - Number of times used
- `created_date` / `modified_date` - Timestamps, auto-managed by MySQL
- `last_used_date` - Last time the shortcode was rendered
- `status` - active/inactive
- `meta_data` - Extra data (JSON or serialized; never instantiated as objects)
- `post_id` - Link to a legacy tag post (kept for the migration only)

---

## 🔄 Changelog

### Version 3.1.0 (next release)
**One tag store, no writes while rendering, one admin screen**

- ✨ The custom table is the only place tags live; the Bridge, dual mode and the post editor for tags are gone
- ✨ Nothing is written while a page renders; usage counting is opt-in
- ✨ A new admin: one menu, a searchable and sortable list with a **Value** column, bulk actions, a form with live preview, Tools and diagnostics
- ✨ Reserved and foreign shortcode names, circular references and markup are checked when a tag is saved
- ✨ Import and export rebuilt: lossless CSV, per-row reports, limits, SQL is export only
- ✨ **Settings > Tags and your server** explains how tags work and why a lot of tags slow a site down, shows this site's live numbers (tags, PHP memory limit, page cache, load time) with a Fine / Watch / Too many status, and recommends tag counts by kind of hosting
- 🐛 The audit findings of 3.0.0 are fixed (see `docs/BUG-REGISTER.md`), including token safety, the missing recursion guard, export and import hardening and uninstall
- 📖 Every deliberate difference in output is listed in `docs/RELEASE-NOTES-3.1.0.md`; the public API is in `docs/API.md`

### Version 3.0.0
**The Placeholder Engine: array data, WooCommerce/ACF, conditionals, external data**

- ✨ 50+ new flat placeholders (post, taxonomy, author, site, URL, date)
- ✨ Parameterized fields: `{meta:}`, `{user_meta:}`, `{term_meta:}`, `{acf:}` (with repeater/group/gallery/relationship support via dot notation), `{wc:}` WooCommerce fields
- ✨ 15 formatters, including array-aware `|join`, `|count`, `|first`, `|last`
- ✨ Fallback syntax `{token??default}`
- ✨ Field-based conditionals (`{if:wc:price>50}`) alongside the existing built-in conditions
- ✨ `[dt_loop]`, `[dt_if]`/`[dt_else]`, and `[dt_template]` shortcodes
- ✨ `{query:...}` and `{api:...}` external/query data sources, and a whitelist-based `{func:...}` developer escape hatch (`register_dynamic_tag_function()` — never arbitrary code from tag content)
- 🐛 Fixed several cache-invalidation bugs (a Dynamic Tag save/delete could transiently un-register the plugin's own shortcodes; a newly created tag could be served stale/empty data)
- 📖 See `ROADMAP.md` for the full feature breakdown and `TESTING.md` for a complete test guide

### Version 2.0.0
**Major Update - Custom Table Support**

#### New Features:
- ✨ Custom database table with optimized schema
- 🔄 Bi-directional migration (Post Type ↔ Table)
- 🌉 Post-Table bridge for compatibility
- 📊 Table-based shortcode processor
- 📤 Enhanced import/export (XML, SQL support)
- ⚡ Significant performance improvements
- 🛡️ Enhanced conflict resolution system
- 📈 Performance monitoring dashboard

#### Improvements:
- 🚀 Multi-level caching system
- 🔍 Advanced filtering and search
- 📁 Better tag group management
- 🎨 Improved admin UI/UX
- 🐛 Bug fixes and stability improvements
- 📝 Enhanced documentation

#### Breaking Changes:
- Requires PHP 7.4+ (upgraded from 7.2)
- New database table (automatic creation on activation)

### Version 1.x
- Initial release with post type system
- Basic tag management
- Simple import/export
- Group support via taxonomy

---

## 🤝 Support & Contributing

### Getting Help

**Documentation:**
- 📚 Read this README
- 💡 Check the [Wiki](https://github.com/EshaanManchanda/WP-Dynamic-Tags/wiki)
- ❓ See [FAQ](https://github.com/EshaanManchanda/WP-Dynamic-Tags/wiki/FAQ)

**Support Channels:**
- 🐛 [Report Issues](https://github.com/EshaanManchanda/WP-Dynamic-Tags/issues)

### Contributing

We welcome contributions! Here's how:

1. 🍴 Fork the repository
2. 🌿 Create a feature branch (`git checkout -b feature/amazing-feature`)
3. 💻 Make your changes
4. ✅ Test thoroughly
5. 📝 Commit (`git commit -m 'Add amazing feature'`)
6. 📤 Push to branch (`git push origin feature/amazing-feature`)
7. 🔄 Open a Pull Request

**Development Setup:**
```bash
git clone https://github.com/EshaanManchanda/WP-Dynamic-Tags.git
cd wp-dynamic-tags
# Install in WordPress plugins directory
```

### Coding Standards
- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- Comment your code
- Write meaningful commit messages
- Include tests for new features

---

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
WP Dynamic Tags - Create and manage unlimited dynamic tags
Copyright (C) 2025 Eshaan Manchanda

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

Full license: [GPL v2 License](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 👨‍💻 Author

**Eshaan Manchanda**
- Website: [https://eshaanportfolio.vercel.app/](https://eshaanportfolio.vercel.app/)
- GitHub: [@EshaanManchanda](https://github.com/EshaanManchanda)
- Plugin URI: [https://github.com/EshaanManchanda/WP-Dynamic-Tags](https://github.com/EshaanManchanda/WP-Dynamic-Tags)

---

## 🙏 Acknowledgments

- Thanks to the WordPress community
- Built with ❤️ for content creators and developers
- Inspired by the need for flexible, reusable content management

---

## 🔗 Useful Links

- [WordPress Plugin Directory](https://wordpress.org/plugins/)
- [WordPress Developer Handbook](https://developer.wordpress.org/)
- [Elementor Documentation](https://elementor.com/help/)
- [Page Builder Documentation](https://wordpress.org/plugins/tags/page-builder/)

---

**⭐ If you find this plugin useful, please consider giving it a star on GitHub!**

---

<div align="center">

**Made with ❤️ for WordPress**

[Report Bug](https://github.com/EshaanManchanda/WP-Dynamic-Tags/issues) · [Request Feature](https://github.com/EshaanManchanda/WP-Dynamic-Tags/issues) · [Documentation](https://github.com/EshaanManchanda/WP-Dynamic-Tags/wiki)

</div>
