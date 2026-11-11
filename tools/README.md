# Dev tools (not shipped)

Each folder has its own `composer.json` so tool versions never conflict with the plugin's PHPUnit 10 suite.
`vendor/` is git-ignored. Install with Composer (this machine needs the Norton-inclusive CA bundle, see the
memory note `dev-environment-local-and-norton`; never disable TLS verification).

| Folder | Tools | Run |
|---|---|---|
| `static/` | PHPCompatibility 10 (PHP 8.2-8.5), wp-compat (WP floor 7.0), Psalm taint analysis, PHPStan 2 | `phpcs --standard=PHPCompatibility --runtime-set testVersion 8.2-`; `phpstan analyse -c phpstan-compat.neon`; `psalm --taint-analysis` |
| `integration/` | wp-phpunit 7.1.3 + PHPUnit 9.6 + Yoast polyfills (WordPress core test suite) | disposable database only |
| `mutation/` | Infection 0.29 | needs Xdebug (`php_xdebug.dll` ships with Local's PHP) |
| `fuzz/` | nikic/php-fuzzer | pure parsers only |

`static/negctl/` (with `psalm-negctl.xml`) holds **negative controls**: tiny files that must be flagged. A clean run of a tool is only
evidence if its control fails; keep them.

Known limits found while setting up (also in `docs/BUG-REGISTER.md`):
* PHPCompatibilityWP 2.1.x cannot load under PHP_CodeSniffer 4, which PHPCompatibility 10 (the first release that knows
  PHP 8.2-8.5 deprecations) needs. Use the `PHPCompatibility` standard directly.
* Psalm taint analysis (humanmade WordPress plugin) flags HTML sinks (`echo $_GET[...]`) but does **not** model
  `$wpdb->query()` as an SQL sink (negative control `negctl/taint-neg.php`). SQL risks are covered by PHPCS
  `WordPress.DB.PreparedSQL` and manual review instead.
