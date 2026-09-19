# WP Dynamic Tags — Product Roadmap

Last updated: 2026-09-19

## 1. Where the plugin stands today

Two storage systems (legacy CPT + custom table with a bridge), groups/taxonomy,
bulk manager, conflict resolver, import/export (JSON/CSV/XML/SQL), migration
tooling, and a dynamic-placeholder engine that (as of the V1 Core update)
covers:

- **Post/Page**: title, ID, slug, content, excerpt, status, type, dates,
  featured image (+ alt/caption), parent post, comment count/status, shortlink
- **Taxonomy/Terms**: primary term name/ID/slug/description/URL, full
  category/tag lists
- **Author**: name, ID, email, bio, URL, website, avatar
- **Site**: name, URL, description, logo, icon, language, charset, timezone,
  PHP/WP/theme versions
- **URL/Request**: current URL, path, query string, host, referrer, user agent
  (all HTML-escaped against reflected XSS)
- **Date/Time**: current + relative, custom `{date:FORMAT}`
- **Native meta**: `{meta:key}` (post meta)
- **ACF**: `{acf:field}` (scalar fields only, inert when ACF isn't installed)
- **Formatting**: `|upper`, `|lower`, `|capitalize`, `|trim`, `|strip_html`,
  `|currency`, `|decimal`, `|thousands`, `|round`, `|date`, `|relative`
- **Fallback**: `{token??Default text}`
- Special shortcodes: `[dt_group]`, `[dt_random]`, `[dt_count]`, `[dt_list]`,
  tag-level `[dt key=".." ]` (note: no `default=` support despite README)

This is a solid **content-snippet / merge-tag engine**. It is not yet a
**dynamic-data engine** in the sense Elementor Pro, Dynamic.ooo, or JetEngine
are — those let a *field on the current record* drive a widget's output
(image, link, repeater loop, visibility) anywhere in a page builder, not just
inside a manually-authored shortcode string.

---

## 2. Competitive landscape

| Capability | **WP Dynamic Tags** (this plugin) | Elementor Pro (native Dynamic Tags) | Dynamic.ooo (DCE + Dynamic Visibility + Dynamic Shortcodes) | JetEngine (Crocoblock) | Toolset |
|---|---|---|---|---|---|
| Reusable named snippets / shortcodes | ✅ core feature | ❌ (not its model) | ⚠️ via Dynamic Shortcodes add-on | ❌ | ❌ |
| Post/site/user/date merge fields | ✅ broad (V1 Core) | ✅ | ✅ | ✅ | ✅ |
| Taxonomy/term fields | ✅ (primary term) | ✅ | ✅ | ✅ | ✅ |
| ACF fields | ⚠️ scalar only | ✅ full (incl. repeater/gallery) | ✅ full | N/A (has own meta system) | N/A |
| WooCommerce fields | ❌ | ✅ (Pro) | ✅ extensive | ✅ extensive | ⚠️ limited |
| Repeater/relationship/array fields | ❌ | ✅ (loop items) | ✅ | ✅ (core strength) | ✅ |
| Conditional visibility (if/else on elements) | ⚠️ only inside tag content via `{if:}` | ⚠️ limited | ✅ dedicated product | ✅ | ✅ |
| Query builder (custom post queries as a data source) | ❌ | ⚠️ limited | ✅ | ✅ (core strength) | ✅ |
| Dynamic templates (render a template per record) | ❌ | ✅ (Theme Builder) | ✅ | ✅ | ✅ |
| REST/external API data | ❌ | ❌ | ⚠️ limited | ⚠️ limited | ❌ |
| Formatting/transforms on values | ✅ (V1 Core) | ✅ | ✅ | ✅ | ✅ |
| Fallback/default values | ✅ token-level; tag-level `[dt]` incomplete | ✅ | ✅ | ✅ | ✅ |
| Works outside Elementor (core editor, any theme) | ✅ (its main differentiator) | ❌ Elementor-only | ❌ Elementor-only | ⚠️ mixed | ⚠️ mixed |
| Conflict detection between tags | ✅ (nobody else does this) | N/A | N/A | N/A | N/A |
| Custom DB table + migration tooling | ✅ (nobody else does this) | N/A | N/A | N/A | N/A |
| Import/export (JSON/CSV/XML/SQL) | ✅ | ❌ | ⚠️ limited | ⚠️ limited | ⚠️ limited |
| Developer extension API | ⚠️ informal (`register_external_placeholder`) | ✅ documented `register_tag` API | ✅ | ✅ | ✅ |
| Price | Free (currently) | Bundled in Elementor Pro (~$59+/yr) | $29–$199/yr | $22–$247/yr | $69–$199/yr |

**Read:** this plugin's actual edge is (1) being **builder-agnostic** — its
shortcodes work in Gutenberg, Elementor, Divi, widgets, anywhere `do_shortcode`
runs — and (2) **ops tooling** (conflict detection, dual storage + migration,
multi-format import/export) that none of the competitors bother with because
they're all single-storage, single-builder products. The gap is entirely in
**structured/relational data** (WooCommerce, repeaters, queries) and
**builder-native placement** (dynamic templates, per-widget visibility).

---

## 3. Missing features (things competitors have that this plugin doesn't)

Every item below is a **capability gap** — a competitor has it, this plugin
has nothing equivalent yet. Ordered so later items depend on earlier ones.

1. **Array/repeater primitives** — `{count}`, `{join}`, `{first}`, `{last}`,
   and a loop construct for repeater-shaped data. Nothing today can express
   "for each item in a list." *Blocks almost everything below.*
   Competitors: JetEngine (core strength), Elementor Pro, Dynamic.ooo, Toolset.
2. **WooCommerce field set** — price, SKU, stock, sale %, product categories,
   cart/customer data. Zero WooCommerce awareness today.
   Competitors: Elementor Pro, Dynamic.ooo, JetEngine (all extensive).
3. **ACF repeater/gallery/relationship fields** — today's ACF support is
   scalar-field-only; repeaters and galleries return nothing. Needs #1 first.
   Competitors: Elementor Pro, Dynamic.ooo (both full support).
4. **User meta / term meta placeholders** — `{meta:key}` only reads post
   meta today; no equivalent for user or term meta.
5. **Element-level conditional visibility** — today's `{if:}` only branches
   text *inside* a tag's output; there's no way to hide/show a whole
   builder element based on a condition.
   Competitors: Dynamic.ooo (dedicated product), JetEngine, Toolset.
6. **Dynamic templates** — rendering a template once per record (product
   card, team-member card, archive loop). This is the feature competitors
   lead marketing with, and the plugin has no equivalent at all.
   Competitors: Elementor Pro (Theme Builder), Dynamic.ooo, JetEngine, Toolset.
7. **Query-builder data source** — "get N posts of type X where meta Y = Z"
   as a reusable data source, vs. today's single-record-only fields.
   Competitors: JetEngine (core strength), Toolset, Elementor Pro (limited).
8. **REST/external API tag** — fetch external JSON and expose a path as a
   token. No competitor does this well either, but it's fully absent here.
9. **PHP/function escape-hatch tag** — a developer-only tag type for custom
   logic, mirroring what every page-builder ecosystem eventually offers.
10. **Formal source/formatter registration API** — `register_external_placeholder()`
    exists but is undocumented, placeholder-only, and has no equivalent for
    registering a new formatter or a new data source. Elementor Pro's
    `register_tag` API is the bar to match.
11. **Resolution debugger** — no screen shows which tag resolved to what,
    from which source, or how many queries it cost. Agencies troubleshooting
    a client site have nothing to inspect.
12. **Third-party integrations** — Meta Box, Pods, JetEngine, Toolset,
    WPML/Polylang have no adapters. Don't build these before #10 exists, or
    each becomes a one-off special case instead of a generalized pattern.

## 4. Existing features that need improvement

These already exist and work, but have a known limitation, bug, or rough
edge worth fixing before adding new surface area:

- **ACF integration is scalar-only** (`includes/class-acf-integration.php`) —
  silently returns empty/nothing for repeater, gallery, and relationship
  field types instead of erroring or partially rendering, which can look
  like a bug to a user who doesn't know the limitation exists.
- **`[dt]` tag-level fallback is incomplete** — the README documents
  `[dt_fallback tag=".." default=".."]`, but the shortcode doesn't actually
  support a `default=` attribute. Docs and behavior have drifted apart.
- **`{if:}` conditionals are content-only** — they can branch text inside a
  tag's output, but can't control whether a whole page-builder element
  renders at all (see gap #5 above); the in-content version is a weaker
  subset of what's needed.
- **`register_external_placeholder()` is informal** — works, but is
  undocumented, placeholder-registration-only, and doesn't cover formatters
  or data sources, so it can't yet serve as the "real" developer API (see
  gap #10).
- **Version constant mismatch** — `WP_DYNAMIC_TAGS_VERSION` is `2.0.0` while
  the plugin header says `3.0.0`. Low-risk but confusing for support/debugging.
- **`README.md`'s documented DB schema is stale** — it doesn't list several
  real columns from `class-database-manager.php` (`shortcode`, `priority`,
  `meta_data`, `post_id`, etc.), so anyone reading the README to understand
  the storage layer gets an incomplete picture.
- **Formatter set is text/number/date only** — covers the common cases
  (`upper`, `currency`, `round`, `relative`, etc.) but has no array-aware
  formatters (e.g. `|join`, `|count`), which will be needed the moment
  gap #1 (array primitives) ships.

---

## 5. Pricing plan

Current state: **100% free**, no paid tier exists. Recommended split below —
free stays genuinely useful (adoption driver), Pro is where a single
freelancer/small-agency site pays for itself, Business is the
multi-client/agency tier.

### Free
Everything that exists **today**, unchanged — this is not a downgrade, it's
"ship what's already built":
- Unlimited tags, both storage backends + migration
- Groups/taxonomy, bulk manager, conflict resolver
- Import/export (JSON/CSV/XML/SQL)
- Full placeholder set from section 1 (Post/Taxonomy/Author/Site/URL/Date,
  native post meta, ACF scalar fields, formatters, fallback)
- Elementor/Gutenberg/any-page-builder compatibility (shortcodes always work
  everywhere — never paywall this, it's the plugin's whole identity)

### Pro — target: freelancers / single-site builders
Everything in Free, plus gaps #1–5:
- Array/repeater primitives (count/join/first/last/loop)
- WooCommerce field set
- ACF repeater/gallery/relationship support
- User meta / term meta placeholders
- Conditional visibility system (element-level, not just in-content `{if:}`)
- Priority support

*Suggested price: $39–49/year, single site.*

### Business — target: agencies / multi-client shops
Everything in Pro, plus gaps #6–11 and agency ops:
- Dynamic templates (per-record rendering)
- Query builder tag
- REST/external API tag
- PHP/function tag (developer escape hatch)
- Formal source/formatter registration API
- Debugger/profiler screen
- Multisite license, unlimited sites
- Agency deployment tools (bulk-apply a tag set across client sites)
- White-label option (remove plugin branding from client-facing admin)

*Suggested price: $149–199/year, unlimited sites.*

### Deliberately NOT tiered
- Conflict detection, dual storage, migration, import/export — these are
  differentiators nobody else has; gating them behind a paywall would erase
  the reason to choose this plugin over a competitor's free tier in the first
  place. Keep them free forever.

---

## 6. Roadmap sequencing

| Phase | Scope (gap #s) | Depends on |
|---|---|---|
| **V1 — done** | Broad placeholder set, formatters, fallback (this session) | — |
| **V2 — Data depth** | Array primitives (#1), then WooCommerce (#2) + ACF repeater/gallery (#3) + user/term meta (#4) on top of it | V1's `{prefix:arg}` + formatter pattern |
| **V3 — Placement** | Element visibility (#5), dynamic templates (#6) | V2 (visibility conditions often test array/relation fields) |
| **V4 — Query & external data** | Query builder (#7), REST/API tag (#8), PHP tag (#9) | V2/V3 (query results feed the same array primitives) |
| **V5 — Ecosystem** | Formal developer API (#10), debugger (#11), third-party integrations (#12) | V2–V4 (nothing to register a source *for* until sources vary) |

Build order matters here specifically because gap #1 (array handling) is a
prerequisite for almost everything through gap #9 — sequencing it first
avoids building WooCommerce variations, ACF repeaters, and query results as
three separate one-off array implementations.
