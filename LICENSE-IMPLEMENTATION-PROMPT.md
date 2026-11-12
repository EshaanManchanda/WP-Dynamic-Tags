# Implementation Prompt: Free/Paid Licensing for WP Dynamic Tags

Paste this whole file as the task prompt when you're ready to build the
licensing system — it's written to be self-contained for a fresh session
with no prior context on this conversation.

---

You are implementing the Free/Paid licensing system for the "WP Dynamic
Tags" WordPress plugin. The plan is already finalized in `LICENSE-PLAN.md`
in the plugin root — **read that file first**, it's the source of truth
for which features are Free vs. Paid and the pricing. This prompt gives
you the technical specification to build it.

## Context

- Plugin root: `wp-content/plugins/Dynamic-tags-v4/`. Main file
  `wp-dynamic-tags.php`, class `WP_Dynamic_Tags_Plugin`. Token engine in
  `includes/class-dynamic-placeholders.php`, class
  `WP_Dynamic_Tags_Placeholders`. `TESTING.md` and `ROADMAP.md` in the same
  directory document the plugin's full feature set and how it was verified
  — read them if anything below is unclear.
- A sibling plugin, `Certificate-Generator-V7.5` (same `wp-content/plugins/`
  directory), already has a **working, production** licensing system
  talking to an external "portfolio backend" server. **Mirror its
  architecture exactly** — same option-key style, same remote-validation
  contract, same heartbeat pattern — so both plugins stay consistent and
  the shared backend doesn't need two different client protocols. Read
  `Certificate-Generator-V7.5/includes/Core/license-manager.php` and
  `Certificate-Generator-V7.5/includes/Payment/backend-api.php` in full
  before writing any code — don't design this from scratch.

## Open questions to resolve before or during implementation

1. **Is the license server the same one Certificate Generator uses** (same
   `cg_license_server_url`-equivalent, single shared portfolio backend), or
   a separate URL for Dynamic Tags? The Certificate Generator bundling
   requirement in `LICENSE-PLAN.md` §6 only works if it's the same backend
   account system. Ask the user if not already answered.
2. **Does the Dynamic Tags team have access to that backend's codebase?**
   If yes, the actual "CertGen Pro/Business → also grant DT Paid" rule
   needs to be implemented there, not in this plugin. If no, treat §6 as
   fully out of scope for this pass and only build the DT-side client that
   trusts whatever `plan` the backend returns.
3. **`[dt_if]` gating scope** — `LICENSE-PLAN.md` gates *field-based*
   conditionals (`{if:wc:price>50}`) as Paid, but doesn't explicitly say
   whether the `[dt_if]` *shortcode itself* should be blocked when used
   with only a built-in condition (e.g. `condition="is_mobile"`). Decide
   one of:
   - (a) `[dt_if]` always requires Paid, regardless of condition type, or
   - (b) `[dt_if]` works on Free with built-in conditions, and only the
     field-based comparison path is gated (consistent with how `{if:}`
     tokens themselves are handled).
   Recommendation: (b), for consistency with how the inline `{if:}` token
   is gated (see the gate point below) — but confirm before building.

## What to build

### 1. `includes/class-license-manager.php` — `DT_License_Manager`

A singleton mirroring `CG_License_Manager`, adapted to two plans instead
of three:
- Constants: `PLAN_FREE = 'free'`, `PLAN_PAID = 'paid'`
- Options: `dt_plan`, `dt_license_key`, `dt_license_expiry` — skip a
  `dt_license_limits`/monthly-usage system entirely; Dynamic Tags has no
  usage quota, only the flat 15-tag cap (see §2), which doesn't need CG's
  monthly-reset cron machinery.
- `get_plan(): string`, `is_paid(): bool`, `get_plan_label(): string`
- `get_license_key()`, `get_expiry()`
- `activate_license(string $key): array` — POST to
  `{backend}/api/payments/activate-remote` with `{license_key, site_url}`,
  same response contract CG expects: `{success, valid, plan, expiry}`.
  Keep a local offline-dev fallback like CG's prefix trick, adapted: a key
  starting with `PAID-` (optionally ending `-LIFETIME`/`-LOCAL`) activates
  Paid locally without a network call.
- `deactivate_license(): void` — notify the backend
  (`/api/payments/deactivate-remote`), then clear local options.
- `heartbeat(): void` — re-validate the stored key twice daily via
  WP-Cron, plus an `admin_init` fallback for sites where cron is dormant
  (copy CG's exact dual-trigger pattern). Downgrade to free on
  invalid/expired/revoked.
- `get_license_server(): string` — reads a `DT_LICENSE_SERVER` constant or
  `dt_license_server_url` option. **Resolve open question #1 before
  hardcoding this.**
- `get_plan_features(): array` — the feature matrix from
  `LICENSE-PLAN.md` §3, for the admin comparison table.

### 2. Tag limit (Free = 15)

Enforce on tag **creation only**, never on read/render (see
`LICENSE-PLAN.md` §7 for the exact non-destructive philosophy — a Free
site with 40 existing tags after a downgrade keeps all 40 working; only
*new* tag creation past 15 is blocked).
- Hook into wherever a Dynamic Tag post transitions to `publish` status
  (find the actual save hook in `wp-dynamic-tags.php` — search for
  `save_post`/`transition_post_status` handling for the `dynamic_tag` post
  type). If `!DT_License_Manager::is_paid()` and the current published-tag
  count is already ≥ 15, block the new one from publishing and show an
  admin notice with an upgrade link.
- Do not touch `get_dynamic_tags()`, shortcode registration, or rendering
  — an already-existing, already-registered tag must keep working
  regardless of plan or count.

### 3. Gate points — wrap with `DT_License_Manager::is_paid()`

- **`includes/class-woocommerce-integration.php`** — the whole
  `add_action('init', ...)` closure should return early if not paid, same
  guard style as its existing ACF/WC-absent checks.
- **`includes/class-acf-integration.php`** — do NOT gate the whole file.
  Scalar ACF fields stay Free. Only the
  `repeater`/`flexible_content`/`gallery`/`relationship`/`post_object`/
  `group` branches in the field-type `switch` should check `is_paid()` and
  fall back to the pre-V2 behavior (return `''` for arrays) when not paid.
- **`includes/class-dynamic-placeholders.php`**:
  - `get_user_meta_value()` / `get_term_meta_value()` — gate their
    *registration* in `register_default_placeholders()`, not the methods
    themselves, so an unpaid site never resolves the `{user_meta:}`/
    `{term_meta:}` prefix at all (same "unknown token, inert" behavior as
    any unregistered placeholder — friendlier than resolving to empty).
  - `get_query_value()`, `get_api_value()`, `get_func_value()` — same
    registration-time gating.
  - The comparison/`contains` branch of `evaluate_field_condition()` —
    keep the existing named conditions (`user_logged_in`, `is_mobile`,
    `user_role:`, etc.) working unconditionally on Free. Gate only the
    *field-based* comparison/truthy-against-a-token path behind
    `is_paid()`. When not paid, that branch should return `null` (not
    `false`), so it falls through to the `dt_evaluate_condition` filter
    exactly like an unresolvable condition does today — don't change that
    fallback contract for third-party integrators.
- **`wp-dynamic-tags.php`**:
  - `loop_shortcode()` (`[dt_loop]`) — return the `empty` attribute (or
    `''`) immediately if not paid.
  - `if_shortcode()` (`[dt_if]`) — gate per the decision on open question
    #3 above.
  - `template_shortcode()` (`[dt_template]`) — return the `empty`
    attribute immediately if not paid.

### 4. Admin UI

- A License tab, mirroring
  `Certificate-Generator-V7.5/includes/Admin/license-tab.php`'s
  layout/UX (key input, activate/deactivate buttons, plan badge, feature
  comparison table sourced from `get_plan_features()`).
- Everywhere a Free user hits a gated placeholder/shortcode, prefer a
  friendly inline "Upgrade to Paid to use {feature}" string over silently
  resolving empty. In the placeholder helper metabox
  (`render_placeholder_helper_box()` in `wp-dynamic-tags.php`, which
  already lists `{wc:...}`/`{acf:...}` input rows), annotate the Paid-only
  rows for Free users instead of hiding them outright — drives upgrade
  awareness rather than hiding the feature exists.

### 5. Explicitly out of scope for this prompt

- The Certificate Generator bundling rule itself (`LICENSE-PLAN.md` §6) —
  see open question #2. Do not fake or hardcode bundling logic client-side.
- Any payment/checkout flow (Stripe/Razorpay, etc.) — this prompt only
  covers what happens *after* a license key exists and gets activated.
- Multi-site/quantity licensing (`LICENSE-PLAN.md` §4's "one key per site,
  keep it simple" note) — build single-site activation only.

## Verification expectations

Match the rigor already established in this plugin's history (see
`TESTING.md` — several real bugs in V2–V4 were only ever found by testing
against a live WordPress request, not by code review alone). Specifically:
- Pure, WP-call-free logic (the tag-count check's counting logic, the
  plan-to-feature-matrix lookup) should get real PHPUnit tests under
  `tests/Unit/`, following this repo's existing convention of extracting
  side-effect-free logic into static methods — see
  `WP_Dynamic_Tags_Placeholders::is_empty_value()` and
  `parse_condition_expression()` for the pattern to match.
- Everything WP-dependent (actual gating behavior per plan, admin notices,
  shortcode output) needs to be verified against a **live WordPress
  request**, not just read through. If a live site is available: toggle
  `dt_plan` between `free`/`paid` via `update_option('dt_plan', ...)` and
  confirm every gate point in §3 actually flips behavior; then confirm a
  Free site with tags already over the 15 cap doesn't lose any existing
  tag (create 16+ tags as Paid, downgrade to Free, confirm all 16+ still
  render, then confirm a 17th can't be published).
- Update `TESTING.md` with new test cases for the licensing behavior, and
  update `LICENSE-PLAN.md`/`ROADMAP.md` to reflect what shipped, following
  this repo's existing documentation conventions (see how V1–V4 were each
  marked done in `ROADMAP.md` as they landed).
