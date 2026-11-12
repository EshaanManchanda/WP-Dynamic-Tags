# Golden master

Release-blocking regression harness for the table-only storage refactor
(`docs/API.md`, plan: Release gate 1). It renders the same fixtures through the real plugin on a
live WordPress site and compares the output before and after the refactor.

* `render.php` — one PHP process per **render path** × **request context**. Creates ~24 fixture tags
  (plain text, engine tokens, formatters, `??` fallbacks, built-in and field conditionals, nested
  shortcodes, `[dt_if]`/`[dt_loop]`/`[dt_template]`, attribute variants, user/post/site tokens),
  renders each with `do_shortcode()`, normalizes volatile values (IDs, today's date) and writes JSON.
  Everything it creates is removed on exit.
* `run.ps1` — runs all contexts (`out_singular`, `in_singular`, `out_archive`) for the given paths.
* `diff.php` — compares two result sets; exit code 1 when anything differs.
* `siblings.php` — characterizes the public API with the real payloads of the sibling plugins.
* `baseline/` — recorded on the pre-refactor commit (`95e1d46`). **Do not regenerate it** except to add
  fixtures, and then only on a pre-refactor checkout.

## Paths

| Path | Meaning |
|---|---|
| `post` | tags exist only as `dynamic_tag` posts, rendered by the post-based registrar (bridge disabled) |
| `table` | tags exist only as `wp_dynamic_tags` rows, rendered by the table registrar |
| `unified` | after the refactor: rows only, rendered by the single registrar (same setup as `table`) |

**Since the table-only refactor** (plan Phase 2c) the post registrar is disconnected, so `post` can no longer be produced; the
live path is `unified`, compared with `baseline post` and `baseline table`. `allowed-differences.json` holds 374 entries with
`path: unified` and `from: post|table`, each tagged with a class (`classes` at the top of the file explains C1-C10 and L1); the
older `post`/`table` entries pin the Phase B fixes against the files of that time. After a change, regenerate with
`.\run.ps1 -Label current -Paths unified` and run `php diff.php baseline post current unified <ctx> --all --allow=allowed-differences.json`
for the three contexts, then the same with `baseline table`: 0 unlisted and 0 stale is the bar.

`post` and `table` can only be produced on the pre-refactor code. After the refactor run
`.\run.ps1 -Label current -Paths unified` and compare:

```
php diff.php baseline post  current unified in_singular   # post-origin tags: must be identical except listed deliberate differences
php diff.php baseline table current unified in_singular   # table-native tags: same
```

## Requirements

A running Local site (`http://gema-ecosystem.local`), PHP CLI with `mysqli`, and the MySQL port of the
site (`-MysqlPort`, default 10006). Override the WordPress location with `WP_LOAD` and the host with `WP_HOST`.
The harness acts as the first user with the `administrator` role.

## Gotchas this harness had to learn the hard way

* **Clean up the plugin's caches, not just its data.** The post path caches its tag list in a one-hour
  transient. If a run deletes its fixture posts without rebuilding that cache, every later web request
  re-registers the deleted tags and writes usage meta against post IDs that no longer exist (see B8 in
  `docs/API.md`). `render.php` and `siblings.php` call `force_refresh_shortcodes()` on exit, then sweep their own
  post IDs' meta again in a second shutdown handler.
* **Registration lock.** `force_refresh_shortcodes()` re-arms the plugin's 3-second `dt_registration_lock`, which
  makes the *next* process skip registering the core shortcodes. Each run clears both locks before it starts and
  after it finishes (B9).
* **Determinism.** Run the whole set twice back-to-back and compare the `out` sections; they must be identical.
  Volatile values (IDs, today's date in both formats, e-mail addresses) are normalized before writing.
* **Antivirus.** Scripts that call `wp_set_current_user()` can be quarantined by some antivirus products. If
  `render.php` or `siblings.php` disappears, restore it and exclude this folder.

## Approving deliberate differences (allow-list)

`allowed-differences.json` lists every output that is *meant* to differ from the baseline: path, context, the exact invocation, the
**exact new output**, a bug id and the reason. Run the comparison with the allow-list:

```
php diff.php baseline table current table in_singular --all --allow=allowed-differences.json
```

It fails on any unlisted difference, on a listed entry whose output is different, and on a **stale** entry (the difference no
longer occurs), so a security or bug fix can never be approved in bulk and an old approval cannot hide a later regression.
