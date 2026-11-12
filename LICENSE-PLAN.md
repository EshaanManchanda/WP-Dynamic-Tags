# WP Dynamic Tags — License Plan (Free / Paid)

Last updated: 2026-11-12 (3.1.0 ships the enforcement; see §8 for what was decided and built)

## 1. Philosophy

Two plans only — **Free** and **Paid**. Free stays a genuinely complete
merge-tag engine (the plugin's adoption driver and its main differentiator
vs. builder-locked competitors); Paid is everything that turns it into a
full *dynamic-data* engine — the array/query/conditional/external-data layer
built across V2–V4.

Rule of thumb used to sort every feature below: **if it works the same
regardless of what post/record you're on, it's Free. If it reaches into
structured data (WooCommerce, ACF arrays, other posts, external APIs) or
branches on that data, it's Paid.** Ops tooling (conflict detection, dual
storage, import/export) stays free forever — see §5.

## 2. The two plans

### Free
A complete, production-ready merge-tag plugin on its own — nothing here is
a crippled trial.
- **Up to 15 dynamic tags that you create yourself** (Paid: unlimited) —
  groups, the custom table (tags other plugins create do not count, §8)
- All V1 Core placeholders (post, taxonomy, author, site, URL, date)
- `{meta:key}` and scalar `{acf:field}`
- All formatters (`|upper`, `|currency`, `|date`, etc. — including the
  array-aware ones, which simply have nothing to operate on without a Paid
  data source)
- Fallback syntax `{token??default}`
- Built-in conditionals: `{if:user_logged_in}`, `{if:is_mobile}`,
  `{if:user_role:...}`, etc.
- `[dt_group]`, `[dt_random]`, `[dt_count]`, `[dt_list]`, `[dt]`
- Conflict detection, import/export (JSON/CSV/XML/SQL), migration tooling
- Works in Elementor/Gutenberg/Divi/anywhere — never gated

### Paid
Everything in Free, plus the entire V2–V4 dynamic-data layer:
- **Unlimited dynamic tags** (Free is capped at 15)
- WooCommerce field placeholders (`{wc:*}`)
- ACF repeater/gallery/relationship support (`{acf:field.subfield}`)
- `{user_meta:}` / `{term_meta:}`
- `[dt_loop]` (array/repeater iteration)
- Field-based conditionals (`{if:wc:price>50}`, `contains`, etc.) and
  `[dt_if]`/`[dt_else]` block-level visibility
- `[dt_template]` per-record rendering
- `{query:...}` and `{api:...}` data sources
- `{func:...}` developer escape hatch
- Priority email support

## 3. Feature comparison

| Feature | Free | Paid |
|---|:---:|:---:|
| Dynamic tags you create yourself | Up to 15 | Unlimited |
| Groups / taxonomy organization | ✅ | ✅ |
| Migration from 3.0.0 tag posts to the table | ✅ | ✅ |
| Shortcode-name safety (reserved/foreign names, self-reference) | ✅ | ✅ |
| Import/export (JSON, CSV, XML, SQL) | ✅ | ✅ |
| Works in any builder (Elementor, Gutenberg, Divi, widgets) | ✅ | ✅ |
| Post/taxonomy/author/site/URL/date placeholders | ✅ | ✅ |
| `{meta:key}` (post meta) | ✅ | ✅ |
| `{acf:field}` — scalar fields | ✅ | ✅ |
| Formatters (`\|upper`, `\|currency`, `\|date`, `\|round`, …) | ✅ | ✅ |
| Fallback syntax `{token??default}` | ✅ | ✅ |
| Built-in conditionals (`user_logged_in`, `is_mobile`, `user_role:`, …) | ✅ | ✅ |
| `[dt_group]` / `[dt_random]` / `[dt_count]` / `[dt_list]` / `[dt]` | ✅ | ✅ |
| **WooCommerce fields** `{wc:price\|sku\|stock_status\|categories\|…}` | ❌ | ✅ |
| **ACF repeater/gallery/relationship** (`{acf:field.subfield}`) | ❌ | ✅ |
| `{user_meta:key}` / `{term_meta:key}` | ❌ | ✅ |
| **`[dt_loop]`** — iterate any array-returning source | ❌ | ✅ |
| **Field-based conditionals** (`{if:wc:price>50}`, `=`, `contains`, …) | ❌ | ✅ |
| **`[dt_if]`/`[dt_else]`** — block-level visibility (built-in conditions free, field conditions Paid) | ◐ | ✅ |
| **`[dt_template]`** — per-record rendering (product cards, team grids) | ❌ | ✅ |
| **`{query:...}`** — query posts as a reusable data source | ❌ | ✅ |
| **`{api:...}`** — external JSON data source | ❌ | ✅ |
| **`{func:...}`** — developer function escape hatch | ❌ | ✅ |
| Support | Community (GitHub issues) | Priority email |

## 4. Pricing

Monthly and yearly only — no lifetime tier, per current plan.

| Billing | Price | Notes |
|---|---|---|
| Monthly | **$2/mo** | Cancel anytime |
| Yearly | **$19/yr** | ≈ $1.58/mo — about 21% off the monthly rate, the usual incentive to commit annually |

This is deliberately aggressive — cheaper than nearly every competitor's
entry tier (Elementor Pro ~$59+/yr, JetEngine $22–247/yr, Dynamic.ooo
$29–199/yr) — positioning Paid as a near-default upgrade rather than a
premium decision. Adjust freely; these are starting numbers, not a
constraint from anything technical.

**Site count**: keep it simple — one license key activates one site.
If/when agency demand shows up, a straightforward multiplier (5-site,
unlimited-site) can be added later as a *quantity* option on the same
Paid plan, not a new feature tier — resist adding a 3rd feature tier for
this.

## 5. Deliberately never gated

Same reasoning as the original 3-tier roadmap: these are what make the
*Free* plan worth adopting over a locked competitor's free tier, so gating
them would defeat the point of having a generous free plan at all.
- Conflict detection
- Dual storage + migration tooling
- Import/export
- Builder-agnostic compatibility

## 6. Certificate Generator bundle

**Certificate Generator Pro/Business subscribers get Dynamic Tags Paid
automatically** — no separate purchase.

This is a **backend-side rule, not a plugin-side one**. Both plugins
already talk to the same external "portfolio backend" for license
validation (see `Certificate-Generator-V7.5/includes/Core/license-manager.php`
for the existing pattern). Dynamic Tags' license manager doesn't need any
special Certificate-Generator-awareness — it just calls the same
`activate-remote` endpoint, and the backend decides the returned `plan`
based on everything that account owns across the whole portfolio.

**Still open**: implementing the actual bundling rule requires access to
that backend's codebase, which isn't part of this WordPress installation.

## 7. Technical implementation (when ready to build)

Mirror `CG_License_Manager` almost exactly — same architecture, fewer plans:

- New `includes/class-license-manager.php` → `DT_License_Manager`
  - Options: `dt_plan` (`free`/`paid`), `dt_license_key`, `dt_license_expiry`
  - `is_paid()`, `get_plan()`, `activate_license()`, `deactivate_license()`,
    `heartbeat()` — same shapes as the CG equivalents, hitting the same
    `{backend}/api/payments/activate-remote` contract
  - WP-Cron heartbeat (twice daily) + `admin_init` fallback, exactly like CG
- **Tag limit (Free = 15)**: check the count on tag *creation*, not on
  read/render — a Free site that already has more than 15 (e.g. downgraded
  from Paid, or bulk-imported) should keep every existing tag working,
  just block creating new ones past the cap, with an admin notice and
  upgrade link. Never delete or disable a user's existing content — same
  non-destructive philosophy as CG's over-limit handling.
- Gate points — wrap each Paid feature's registration/resolution with
  `DT_License_Manager::is_paid()`:
  - `includes/class-woocommerce-integration.php` — whole file inert unless paid
  - `class-acf-integration.php` — keep scalar fields always on; gate the
    repeater/gallery/relationship branches only
  - `get_user_meta_value()` / `get_term_meta_value()` registrations
  - `loop_shortcode()`, `if_shortcode()` (field-based branch only — keep
    built-in conditions free), `template_shortcode()`
  - `get_query_value()`, `get_api_value()`, `get_func_value()` registrations
- Admin: a License tab (mirror `Certificate-Generator-V7.5/includes/Admin/license-tab.php`)
  plus upgrade prompts wherever a Free user hits a gated placeholder (return
  a friendly "Upgrade to Paid" string instead of silently resolving empty,
  same pattern CG uses for over-limit states)

## 8. What 3.1.0 ships (decisions of 2026-11-12)

Enforcement is live in 3.1.0. The decisions that §1-§7 left open, and how they were built:

- **License server.** `DT_License_Manager::DEFAULT_LICENSE_SERVER` is `https://tlvapi.techlovev.in` (the backend Certificate
  Generator Pro uses). Order: constant `DT_LICENSE_SERVER`, option `dt_license_server_url`, `cg_license_server_url`, the default.
  Only an `https://` address is accepted. A site sends the key and the site address, only when a key is activated and on the
  twice-daily check while a key is stored; without a key it sends nothing.
- **The `PAID-` offline shortcut is gone.** Keys were accepted when they began with `PAID-` and no server was configured;
  with a default server that path is dead code and would have been a free upgrade. A key is valid only if the server says so.
  An unreachable server never downgrades a Paid site (only an explicit invalid/expired/revoked answer does).
- **The cap (15) counts tags that people create**: Add New, Duplicate, Import. Code that calls
  `create_tag($data)` (Certificate Generator's per-student tags, the chatbot's event tags) is never blocked and never counted: such
  a tag carries `dt_origin = api` in its `meta_data` (no schema change). A caller cannot choose that mark, an import file
  cannot carry it, and an update cannot add or remove it. Tags that existed before 3.1.0 have no mark and count.
  The cap stops new tags only: nothing existing is disabled, hidden or deleted, and rendering never looks at it.
- **Seam.** The limit is one callback on `dt_can_create_tag` (`DT_License_Manager::enforce_tag_limit`, priority 10); the filter
  receives `$allowed, $data, $origin`.
- **Gates (one place each).** Parameterized placeholders `wc`, `user_meta`, `term_meta`, `query`, `api`, `func`: in
  `WP_Dynamic_Tags_Placeholders::resolve_token()` (so `{token}`, `[dt_loop source=]` and conditions are covered together) and, before
  formatters, in `replace_generic_token()`; ACF repeater/flexible/group/gallery/relationship values and `{acf:rows.name}` sub-fields:
  `DT_License_Manager::acf_allowed()`; `[dt_loop]` and `[dt_template]`: top of the shortcode.
- **`[dt_if]` scope.** The shortcode stays free with the built-in conditions (`user_logged_in`, `user_not_logged_in`, `is_admin`,
  `is_mobile`, `is_front_page`, `is_home`, `is_single`, `is_page`, `user_role:`, `time_between()`) and with a condition a site adds
  through `dt_evaluate_condition`. A condition that reads a token (`meta:key=value`, `wc:price>50`, `acf:featured`) is Paid: on Free it
  is simply not met (a leading `!` still negates it).
- **What a Free visitor sees for a Paid feature:** nothing. The token renders empty (an author-written `??fallback`, `empty=""`
  or template text still shows), with no query, request or function call made. No upgrade text is ever put in a page. The admin sees
  a "Paid feature" badge on the tag in All Tags, a notice on its edit form and a note under its preview.
- **Upgrade link.** `dt_upgrade_url` (filter, empty by default); a refusal names it only when it is set.
