# Dev tools (not shipped)

Each folder has its own `composer.json` so tool versions never conflict with the plugin's PHPUnit 10 suite.
`vendor/` is git-ignored. Install with Composer (this machine needs the Norton-inclusive CA bundle, see the
memory note `dev-environment-local-and-norton`; never disable TLS verification).

| Folder | Tools | Run |
|---|---|---|
| `static/` | PHPCompatibility 10 (PHP 8.2-8.5), wp-compat (WP floor 7.0), Psalm taint analysis, PHPStan 2 | `phpcs --standard=PHPCompatibility --runtime-set testVersion 8.2-`; `phpstan analyse -c phpstan-compat.neon`; `psalm --taint-analysis` |
| `integration/` | wp-phpunit 7.1.3 + PHPUnit 9.6 + Yoast polyfills (WordPress core test suite) | disposable database only |
| `mutation/` | Infection 0.29 (installed, **does not complete here**: it stops silently while processing the first source file on Windows + Xdebug) and `mutants.php`, a hand-written mutation runner: 68 deliberate mutants (the conditional parser, the time window, `decode_meta`, the origin mark and the Paid-feature helpers of the Free/Paid plans, the array formatters); every mutant is checked to be valid PHP, and all 68 are killed by a failing test. **It works on a temporary copy of the plugin and never touches the plugin folder** (the site serves that folder live: an older version of this runner wrote its mutants into it and restored them afterwards, which could reach a real page or stall it with an infinite loop) | `php tools/mutation/mutants.php [name filter]` |
| `fuzz/` | nikic/php-fuzzer | pure parsers only |

`static/negctl/` (with `psalm-negctl.xml`) holds **negative controls**: tiny files that must be flagged. A clean run of a tool is only
evidence if its control fails; keep them.

Known limits found while setting up (also in `docs/BUG-REGISTER.md`):
* PHPCompatibilityWP 2.1.x cannot load under PHP_CodeSniffer 4, which PHPCompatibility 10 (the first release that knows
  PHP 8.2-8.5 deprecations) needs. Use the `PHPCompatibility` standard directly.
* Psalm taint analysis (humanmade WordPress plugin) flags HTML sinks (`echo $_GET[...]`) but does **not** model
  `$wpdb->query()` as an SQL sink (negative control `negctl/taint-neg.php`). SQL risks are covered by PHPCS
  `WordPress.DB.PreparedSQL` and manual review instead.

Fuzzing (`fuzz/`): `targets/conditional-parser.php`, `targets/token-parsers.php` (token body, condition expression, comparison, path walk,
formatter spec, `decode_meta`) and `targets/plan-helpers.php` (the origin mark, the Paid-feature detection, the key mask).
Seeds live in `seeds-*`; start from a corpus directory outside the repository (php-fuzzer renames the files and writes `crash-*.txt` into the
current directory, so run it from a scratch folder): `cp -r tools/fuzz/seeds-parser <scratch>/corpus` then
`php tools/fuzz/vendor/bin/php-fuzzer fuzz tools/fuzz/targets/conditional-parser.php <scratch>/corpus --max-runs 500000`.

**What php-fuzzer reports (found 2026-11-12 with a planted failure): a PHP error, warning or notice, and a thrown `Error`. It silently
ignores a thrown `Exception`, even with `run-single`.** The two older targets signalled a failed check with `throw new Exception(...)`, so those
checks could never fire: their "1.5 million inputs without a crash" showed only that no PHP warning, notice or fatal error occurred, not that the
output invariants held. All targets now throw `Error`. Each target has a negative control that works:
a planted `Error` is found from a seed that does not trigger it, and the plan-helpers target fails at once (a crash on the first seed) against the
version of `normalize_meta` from before it also defused the mark hidden in a nested key or a value. Remaining limit: a deep-bug negative control
for the parser targets has not been built; treat a clean run as moderate evidence, backed by the unit tests and the mutation runner.
