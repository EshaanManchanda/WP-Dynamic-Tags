# Dev tools (not shipped)

Each folder has its own `composer.json` so tool versions never conflict with the plugin's PHPUnit 10 suite.
`vendor/` is git-ignored. Install with Composer (this machine needs the Norton-inclusive CA bundle, see the
memory note `dev-environment-local-and-norton`; never disable TLS verification).

| Folder | Tools | Run |
|---|---|---|
| `static/` | PHPCompatibility 10 (PHP 8.2-8.5), wp-compat (WP floor 7.0), Psalm taint analysis, PHPStan 2 | `phpcs --standard=PHPCompatibility --runtime-set testVersion 8.2-`; `phpstan analyse -c phpstan-compat.neon`; `psalm --taint-analysis` |
| `integration/` | wp-phpunit 7.1.3 + PHPUnit 9.6 + Yoast polyfills (WordPress core test suite) | disposable database only |
| `mutation/` | Infection 0.29 (installed, **does not complete here**: it stops silently while processing the first source file on Windows + Xdebug) and `mutants.php`, a hand-written mutation runner (26 deliberate mutants of the conditional parser, the time window and `decode_meta`; all 26 killed) | `php tools/mutation/mutants.php` |
| `fuzz/` | nikic/php-fuzzer | pure parsers only |

`static/negctl/` (with `psalm-negctl.xml`) holds **negative controls**: tiny files that must be flagged. A clean run of a tool is only
evidence if its control fails; keep them.

Known limits found while setting up (also in `docs/BUG-REGISTER.md`):
* PHPCompatibilityWP 2.1.x cannot load under PHP_CodeSniffer 4, which PHPCompatibility 10 (the first release that knows
  PHP 8.2-8.5 deprecations) needs. Use the `PHPCompatibility` standard directly.
* Psalm taint analysis (humanmade WordPress plugin) flags HTML sinks (`echo $_GET[...]`) but does **not** model
  `$wpdb->query()` as an SQL sink (negative control `negctl/taint-neg.php`). SQL risks are covered by PHPCS
  `WordPress.DB.PreparedSQL` and manual review instead.

Fuzzing (`fuzz/`): `targets/conditional-parser.php` and `targets/token-parsers.php` (token body, condition expression, comparison, path walk,
formatter spec, `decode_meta`) each ran 1.5 million inputs with php-fuzzer (about 2 and 0.6 minutes) without a crash, warning or timeout.
Seeds live in `seeds-*`; start from a corpus directory outside the repository: `cp -r tools/fuzz/seeds-parser <scratch>/corpus` then
`php tools/fuzz/vendor/bin/php-fuzzer fuzz tools/fuzz/targets/conditional-parser.php <scratch>/corpus --max-runs 1500000`.
Limit: a negative control (a planted crash for inputs starting with `{if:`) was **not** found in two attempts of 60 seconds, so this setup is not proven to find deep bugs; it did grow its corpus (363 and 120 coverage features), which shows it explores the code, and it turns warnings into failures. Treat "no crash in 1.5 million inputs" as moderate evidence, backed by the unit tests and the mutation check.
