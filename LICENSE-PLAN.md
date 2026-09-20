# WP Dynamic Tags — License Plan (Free / Paid)

Last updated: 2026-09-20

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
- Unlimited tags, groups, both storage backends (post type + custom table)
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
| Unlimited dynamic tags | ✅ | ✅ |
| Groups / taxonomy organization | ✅ | ✅ |
| Dual storage (post type + custom table) + migration | ✅ | ✅ |
| Conflict detection between tag names/shortcodes | ✅ | ✅ |
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
| **`[dt_if]`/`[dt_else]`** — block-level visibility | ❌ | ✅ |
| **`[dt_template]`** — per-record rendering (product cards, team grids) | ❌ | ✅ |
| **`{query:...}`** — query posts as a reusable data source | ❌ | ✅ |
| **`{api:...}`** — external JSON data source | ❌ | ✅ |
| **`{func:...}`** — developer function escape hatch | ❌ | ✅ |
| Support | Community (GitHub issues) | Priority email |

## 4. Pricing

Monthly and yearly only — no lifetime tier, per current plan.

| Billing | Price | Notes |
|---|---|---|
| Monthly | **$2.99/mo** | Cancel anytime |
| Yearly | **$19/yr** | ≈ $1.58/mo — about 47% off the monthly rate, the usual incentive to commit annually |

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
