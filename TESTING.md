# WP Dynamic Tags — Manual Test Guide

This is a hands-on test script for every feature shipped across V1–V4
(see `ROADMAP.md`). Most of it has since been exercised against a real,
running WordPress request (once the dev site's backend services were
started) and passed — including a full regression pass after 3 real bugs
were found and fixed this way:

1. **`{token|join:sep}` with a trailing/leading space in the separator**
   (e.g. `{wc:categories|join:; }`) had that whitespace silently stripped —
   fixed by only trimming a formatter spec's *name*, never its argument.
2. **`[dt_if condition="key<value"]` with a literal `<`** (no later `>`)
   silently fails to render at all — this is a **WordPress core**
   limitation (its own shortcode-in-HTML-tag detection gets confused by an
   unbalanced `<`), not something this plugin can fix directly. Workaround:
   write `&lt;`/`&gt;` instead of `<`/`>` inside a `condition="..."`
   attribute — `parse_condition_expression()` decodes them. The `{if:...}`
   *token* form (not a shortcode) is unaffected either way.
3. **Creating or deleting a Dynamic Tag post could transiently un-register
   the plugin's own system shortcodes** (`[dt_loop]`, `[dt_if]`,
   `[dt_template]`, etc.) for the rest of that request, and separately,
   **a Dynamic Tag created/used in the same request as a `[dt_template]`
   call could come back empty** due to a stale object-cache entry never
   being invalidated. Both fixed at the root (see git history for
   `clear_dynamic_tag_shortcodes()` and `clear_all_plugin_caches()`).

**ACF and WooCommerce integrations still haven't been exercised against
real plugin data** — neither is installed on the dev site this was tested
against — so treat sections 2.2 and 2.3 as unverified until you've run
them yourself.

## 0. Setup

**How a Dynamic Tag becomes a shortcode:** create a post under **Dynamic
Tags → Add New**, put the test content (given in each case below) in the
post body, and publish it. The post *title* becomes the shortcode name:
lowercase, non-alphanumeric characters replaced with `_`. E.g. a tag titled
`Test Array Join` becomes `[test_array_join]`. Use a distinct, descriptive
title per test case so you can find/reuse it.

**Where to render a shortcode to check output:** paste `[your_shortcode]`
into any post/page content (classic editor, Gutenberg shortcode block, or a
Custom HTML block) and view the page on the front end. `do_shortcode()`
runs anywhere shortcodes normally work — this plugin is builder-agnostic.

**Recommended: turn on debug logging** in `wp-config.php` while testing:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('DT_DEBUG_VERBOSE', true); // this plugin's own verbose logging
```
Check `wp-content/debug.log` if something doesn't resolve as expected.

**Plugin dependencies for some test cases:**
- ACF tests (section 1.9, 2.2) require Advanced Custom Fields active, with
  a repeater, a gallery, and a relationship field created on the post type
  you're testing against.
- WooCommerce tests (section 2.3) require WooCommerce active with at least
  one product that has a regular price, a sale price, a SKU, stock tracking
  enabled, a category, and a tag.
- Everything else needs only WordPress core + this plugin.

**Cache note:** this plugin caches shortcode registration. If a newly
created/edited Dynamic Tag's shortcode doesn't show updated output, save
the post again, or use **Dynamic Tags → Tools → Force Refresh** if present,
or just wait — saves auto-clear the relevant cache.

---

## 1. V1 Core — placeholders, formatters, fallback, conditionals

### 1.1 Post/Page placeholders
Tag content:
```
{post_title} | {post_id} | {post_slug} | {post_excerpt} | {post_status} | {post_type}
{post_date} | {post_modified_date}
{post_comment_count} comments, status: {post_comment_status}
{post_shortlink}
{post_featured_image}
{post_featured_image_alt} / {post_featured_image_caption}
Parent: {post_parent_title} ({post_parent_url})
```
- [ ] Every token resolves to real data for the post it's embedded in.
- [ ] Test on a post **with no featured image** — `{post_featured_image}` etc. should resolve empty, not error.
- [ ] Test on a **top-level post** (no parent) — `{post_parent_title}`/`{post_parent_url}` should resolve empty.

### 1.2 Taxonomy/Terms
On a post assigned to at least 2 categories and 1 tag:
```
Primary: {term_name} ({term_slug}) - {term_description} - {term_url}
All categories: {term_list}
All tags: {tag_list}
```
- [ ] `{term_name}` etc. show the post's *first* assigned category (WordPress's own "primary category" logic via `get_the_category()`).
- [ ] `{term_list}`/`{tag_list}` are comma-separated and include every assigned term.
- [ ] On a post with **no categories/tags**, all six resolve empty (no PHP notices).

### 1.3 Author
```
{author_name} ({author_id}) - {author_email}
{author_bio}
{author_url} / {author_website}
{author_avatar}
```
- [ ] Matches the post's actual author, not the currently logged-in viewer.
- [ ] Test with an author who has **no bio/website set** — should resolve empty, not error.

### 1.4 Site
```
{site_name} | {site_url} | {site_description} | {admin_email}
{site_language} | {wp_version} | {theme_name} | {theme_version}
{site_logo} | {site_icon} | {php_version} | {site_timezone}
```
- [ ] All resolve to real values regardless of which post they're embedded in.

### 1.5 URL/Request
```
{url_current} | {url_path} | {url_query_string} | {url_host}
{url_referrer} | {url_user_agent}
```
- [ ] Load the page with a query string, e.g. `?ref=test` — `{url_query_string}` should show it.
- [ ] **XSS check**: `{url_query_string}` and `{url_user_agent}` are attacker-influenced (a visitor controls their own query string/UA). Confirm the rendered HTML has these values properly escaped (view source — no raw `<script>` execution if you visit `?x=<script>alert(1)</script>`).

### 1.6 Date/Time
```
{current_year} / {current_month} / {current_day}
{current_date} / {current_time} / {current_datetime}
Custom: {date:Y-m-d} and {date:l, F j, Y}
```
- [ ] `{date:...}` respects the format string exactly (test at least two different formats).

### 1.7 Native post meta
Add a custom field `test_meta_key` = `Hello World` to the post (via Custom Fields panel or ACF's own meta), then:
```
{meta:test_meta_key}
{meta:does_not_exist??fallback text}
```
- [ ] First line prints `Hello World`.
- [ ] Second line prints `fallback text` (key doesn't exist → fallback fires).

### 1.8 Formatters (scalar)
```
{post_title|upper}
{post_title|lower}
{post_title|capitalize}
{meta:test_meta_key|trim}
{meta:test_meta_key|strip_html}
{meta:some_price|currency}       (set some_price = 19.5)
{meta:some_price|decimal:3}
{meta:some_price|thousands}      (set some_price = 1234567)
{meta:some_price|round:1}
{post_date|date:F j, Y}
{post_date|relative}
```
- [ ] Each formatter transforms the value as expected.
- [ ] Chain two: `{meta:some_price|round:2|currency}` — chained left-to-right, final output is a formatted currency string.

### 1.9 ACF (scalar fields only — no repeater/gallery/relationship here, that's section 2.2)
With a plain ACF text field `subtitle` on the post:
```
{acf:subtitle}
{acf:subtitle??No subtitle set}
```
- [ ] Resolves to the field's value; fallback fires if the field is empty.
- [ ] With ACF **deactivated**, `{acf:subtitle}` should render as literal unresolved text `{acf:subtitle}` (inert, not an error) — confirms the plugin doesn't fatal when ACF is missing.

### 1.10 Fallback syntax
```
{meta:missing_key??Default shown}
{meta:missing_key|upper??Default shown}
```
- [ ] **Critical regression check**: the second line must show `Default shown` **unformatted** — not `DEFAULT SHOWN`. Fallback text must never be passed through formatters (this was a real bug fixed pre-V1; confirms it hasn't regressed).

### 1.11 Conditionals
```
{if:user_logged_in}You're logged in{else}Please log in{/if}
{if:user_role:administrator}Admin only content{/if}
{if:is_mobile}Mobile{else}Desktop{/if}
{if:is_front_page}Home{else}Not home{/if}
```
- [ ] View logged out vs. logged in — first line switches correctly.
- [ ] View as a non-admin — second line shows nothing.
- [ ] View on the site's front page vs. any other page — fourth line switches.

### 1.12 Special shortcodes
Create 3+ Dynamic Tags assigned to a shared group (Taxonomy: Tag Groups), e.g. group "testgroup":
```
[dt_group group="testgroup" format="list" separator=", "]
[dt_random group="testgroup" count="1"]
[dt_count group="testgroup"]
[dt_list group="testgroup" template="{key}: {value}"]
[dt key="one_of_the_tag_keys"]
```
- [ ] `[dt_group]` lists all tags in the group in the requested format.
- [ ] `[dt_random]` returns one random tag from the group (refresh a few times to confirm randomness).
- [ ] `[dt_count]` matches the actual number of tags in the group.
- [ ] `[dt_list]` renders the template once per tag.
- [ ] `[dt key="..."]` renders that one tag's content directly.

---

## 2. V2 Core — array/repeater primitives, WooCommerce, ACF arrays, user/term meta

### 2.1 Array formatters (no ACF/WooCommerce needed — uses a flat placeholder)
This needs a temporary array-returning placeholder since no built-in flat placeholder returns an array by default. Easiest way to test: add this to a must-use plugin or `functions.php` temporarily —
```php
add_action('init', function () {
    WP_Dynamic_Tags_Placeholders::register_external_placeholder('test_colors', function () {
        return ['Red', 'Green', 'Blue'];
    });
}, 26);
```
Then a Dynamic Tag:
```
Joined: {test_colors|join:, }
Joined custom sep: {test_colors|join: | }
Count: {test_colors|count}
First: {test_colors|first}
Last: {test_colors|last}
First then upper: {test_colors|first|upper}
Bare (no formatter): {test_colors}
```
- [ ] `|join` output: `Red, Green, Blue` (default) and `Red | Green | Blue` (custom separator).
- [ ] `|count` → `3`.
- [ ] `|first` → `Red`, `|last` → `Blue`.
- [ ] `|first|upper` → `RED` (proves a formatter chain can collapse an array *then* apply a scalar formatter).
- [ ] **Critical safety-net check**: the bare `{test_colors}` line (no formatter at all) must render as `Red, Green, Blue`, **never** the literal word `Array`.

### 2.2 ACF repeater / gallery / relationship (requires ACF)
Set up on the test post:
- A **repeater** field `team_members` with sub-fields `name` and `role`, 2+ rows filled in.
- A **gallery** field `photos` with 2+ images.
- A **relationship** field `related_posts` pointing to 2+ other posts.

```
Repeater column: {acf:team_members.name|join:, }
Repeater single sub-field via loop:
[dt_loop source="acf:team_members" separator=" | "]{item:name} ({item:role})[/dt_loop]
Gallery URLs: {acf:photos|join:, }
Gallery loop:
[dt_loop source="acf:photos"]<img src="{item}">[/dt_loop]
Relationship titles: {acf:related_posts|join:, }
Count of gallery images: {acf:photos|count}
```
- [ ] `{acf:team_members.name}` returns just the `name` column across all rows (not full row data).
- [ ] `[dt_loop source="acf:team_members"]` renders once per row, `{item:name}`/`{item:role}` pull the right sub-fields.
- [ ] `{acf:photos}` flattens to a comma-joined list of image URLs (test with the gallery's "Return Format" set to both "Image Array" and "Image ID" in ACF's field settings — both should still work).
- [ ] `[dt_loop source="acf:photos"]` renders one `<img>` per photo.
- [ ] `{acf:related_posts}` shows the *titles* of the related posts (test with "Post Object" return format set to both "Post Object" and "Post ID").
- [ ] A **plain scalar ACF field** (e.g. `{acf:subtitle}` from 1.9) still works exactly as before — no regression from the field-type branching.
- [ ] An ACF field that genuinely doesn't exist, `{acf:not_a_real_field}`, resolves empty — not an error.

### 2.3 WooCommerce fields (requires WooCommerce)
On a product page/post with a sale price, SKU, stock tracking on, 1+ category, 1+ tag:
```
Price: {wc:price|currency}
Regular: {wc:regular_price|currency} / Sale: {wc:sale_price|currency}
Sale %: {wc:sale_percent}
SKU: {wc:sku}
Stock: {wc:stock_status} ({wc:stock_quantity} units)
Categories: {wc:categories|join:, }
Tags: {wc:tags|join:, }
```
- [ ] All fields match the actual product data in wp-admin.
- [ ] `{wc:sale_percent}` is empty on a product that is **not** on sale (no regular/sale price gap).
- [ ] On a **non-product post** (regular blog post), every `{wc:...}` token resolves empty, not an error.
- [ ] With WooCommerce **deactivated**, every `{wc:...}` token renders as inert literal text — confirms the guard works.

### 2.4 User meta / term meta
Add user meta `phone` = `555-1234` to the post's author (Users → edit user → a plugin/ACF user field, or via a quick DB/CLI edit), and term meta `icon` = `star.png` to the post's primary category (needs a plugin that supports term meta UI, or set via code) —
```
Author phone: {user_meta:phone??not set}
Term icon: {term_meta:icon??not set}
```
- [ ] Resolves to the values set on the **author** and **primary category**, not on the currently logged-in viewer or a different term.
- [ ] With no value set, the `??not set` fallback fires correctly.

---

## 3. V3 — field-based conditions, block visibility, dynamic templates

### 3.1 Field-based conditions
On a WooCommerce product with price 75 and stock status "instock":
```
{if:wc:price>50}Over $50{else}$50 or under{/if}
{if:wc:stock_status=instock}In stock{else}Sold out{/if}
{if:wc:stock_status!=outofstock}Available{else}Gone{/if}
{if:acf:subtitle}Has a subtitle{else}No subtitle{/if}
{if:post_title contains Test}Title has "Test"{else}No match{/if}
```
- [ ] Comparison operators (`>`, `=`, `!=`, `contains`) all evaluate correctly against real resolved token values.
- [ ] Truthy check (`{if:acf:subtitle}`) matches whether the field is actually empty or filled.
- [ ] Existing non-field conditions (`{if:user_logged_in}`, `{if:is_mobile}`, etc. from 1.11) still work unchanged — no regression from the new comparison logic.
- [ ] A **nonsense condition** you didn't define, e.g. `{if:totally_made_up_thing}`, should render as the *false* branch (or nothing, if no `{else}`) — never an error.

### 3.2 `[dt_if]` block visibility
Paste this directly into a page (not inside a Dynamic Tag — this is the point: it works anywhere):
```
[dt_if condition="wc:price>50"]
<div style="background:gold;padding:10px;">On sale banner / big block of HTML</div>
[dt_else]
<p>Regular content here.</p>
[/dt_if]
```
- [ ] The whole HTML block switches based on the condition, not just inline text.
- [ ] Works with **no** `[dt_else]` present at all (should just show nothing when false).
- [ ] Works with an `{acf:...}`/`{meta:...}` token *inside* the true/false branch — confirms nested token resolution still happens.
- [ ] **`<`/`>` gotcha**: `condition="wc:price>50"` works, but `condition="wc:price<50"` (a literal `<` with no later `>` anywhere in the shortcode) does **not** — WordPress core's own shortcode parser gets confused by it and the whole `[dt_if]` silently fails to render (found via live testing; this is a WP core limitation, not this plugin). Use `condition="wc:price&lt;50"` instead — confirm this renders correctly.

### 3.3 `[dt_template]` — per-record rendering
Create a Dynamic Tag titled `product_card` with content like:
```
<div class="card"><h3>{post_title}</h3><p>{wc:price|currency}</p></div>
```
Then on any page:
```
[dt_template tag="product_card" query="post_type=product&posts_per_page=3&orderby=date&order=DESC"]
```
- [ ] Renders the `product_card` template **once per matching product**, each with that product's own title/price (not all showing the same post).
- [ ] Change `posts_per_page` and confirm the count of rendered cards matches.
- [ ] Set `tag="a_tag_that_does_not_exist"` — should render the `empty=""` fallback (or nothing), not an error.
- [ ] Set a `query` that matches **zero posts** (e.g. an unused `meta_key`/`meta_value` pair) — same empty-fallback behavior.

---

## 4. V4 — query builder, external API, function whitelist

### 4.1 `{query:...}` data source
```
[dt_loop source="query:post_type=post&posts_per_page=3&orderby=date&order=DESC" separator=" | "]{item:title}[/dt_loop]
```
- [ ] Lists the 3 most recent posts' titles, separated by ` | `.
- [ ] Change to `{item:permalink}` and confirm real URLs are returned.
- [ ] Try `{item:thumbnail}` on a post **without** a featured image — should resolve empty for that row, not break the whole loop.
- [ ] A query matching zero posts renders the loop's `empty=""` attribute (or nothing).

### 4.2 `{api:...}` external data source
Pick a real, free, no-auth JSON API for testing (e.g. a public exchange-rate or JSONPlaceholder-style endpoint) —
```
Whole payload count: {api:https://jsonplaceholder.typicode.com/todos/1::title}
```
(Adjust the URL/path to whatever public JSON endpoint you use — the `::` separates the URL from the dot-notation path into the decoded JSON.)
- [ ] First load: value resolves correctly from the live API response.
- [ ] **Cache check**: temporarily break the URL (typo it) after a successful first load within 5 minutes — it should still show the *previously cached* value, not immediately fail (confirms the 5-minute success cache).
- [ ] Then wait for the cache to expire (or use `wp transient delete` / a fresh unique test URL) and confirm a genuinely broken URL resolves empty, not an error or a fatal.
- [ ] **SSRF check**: try `{api:http://169.254.169.254/latest/meta-data/::x}` (cloud metadata endpoint) or `{api:http://127.0.0.1/::x}` — must resolve empty. `wp_safe_remote_get()` should refuse the request outright; if you have access to server logs, confirm no outbound connection was actually attempted.
- [ ] Disable via code — add `add_filter('dt_enable_api_placeholder', '__return_false');` — and confirm `{api:...}` tokens then render as inert literal text.

### 4.3 `{func:...}` whitelist
Add to a must-use plugin or `functions.php`:
```php
add_action('init', function () {
    WP_Dynamic_Tags_Placeholders::register_dynamic_tag_function('shipping_estimate', function ($arg) {
        return $arg === 'express' ? '1 business day' : '5-7 business days';
    });
}, 26);
```
Then:
```
Standard: {func:shipping_estimate}
Express: {func:shipping_estimate:express}
Unregistered: {func:not_a_real_function}
```
- [ ] First line → `5-7 business days` (no arg passed to the callback).
- [ ] Second line → `1 business day`.
- [ ] Third line resolves **empty**, proving this is a whitelist: only names you explicitly registered are callable, nothing else — confirm by also trying `{func:system}`, `{func:exec}`, `{func:eval}` and seeing all resolve empty.

---

## 5. Cross-cutting regression checks

Run these once at the end, they exercise the shared engine all the features above sit on top of:

- [ ] **No literal "Array" ever appears** anywhere on a page using any of the array-returning tokens (`{query:...}`, `{acf:repeater.field}`, `{acf:gallery}`, `{wc:categories}`, `{func:...}` returning an array) without an explicit array formatter.
- [ ] **Fallback still fires on an empty array**, not just empty string: e.g. `{acf:empty_repeater.name??no rows}` on a repeater with zero rows should show `no rows`.
- [ ] **Formatter chains that mix array and scalar formatters** don't error: `{acf:team_members.name|first|upper|strip_html}` should work end to end.
- [ ] All V1 tests in section 1 still pass unchanged after V2–V4 were added — the array-engine and condition-engine changes should be additive, not disruptive to existing content already using this plugin.
- [ ] **A separator with meaningful whitespace survives**: `{wc:categories|join:, }` (comma-space) must actually join with `", "`, not `","` — a real bug (formatter args getting over-trimmed) was found and fixed here.
- [ ] **Creating, then deleting, a Dynamic Tag post doesn't break the plugin's own system shortcodes for the rest of that page load**: create a throwaway Dynamic Tag, delete it again, then immediately test `[dt_loop]`, `[dt_if]`, `[dt_group]`, etc. on the same page load — all should still work. (A real bug here caused these to silently stop working after any Dynamic Tag save/delete, in a narrow timing window.)
- [ ] **A Dynamic Tag used as a `[dt_template]` immediately after being created/edited reflects the new content**, not stale/empty data (another real caching bug, now fixed).
