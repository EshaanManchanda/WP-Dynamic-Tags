# Live scenarios

`migration-scenarios.php` drives the real migration engine through its whole lifecycle against a real
WordPress database: dry run, locking, resumable batches, idempotency, verification, conflict resolution,
guarded cleanup, backup tamper detection, restore, transactional rollback, and the compatibility facade.
It prints one PASS/FAIL line per check and exits 1 if anything fails.

## These scenarios are destructive. Never run them against a site in use.

They delete tag posts, replace table rows and restore backups. On the day this was written the dev site's
owner created real tags while the tests were being prepared; the script therefore **refuses to run unless
the database is the disposable clone `local_mgtest`**.

## Running them

```powershell
# 1. clone the site's database (read-only dump of the real one)
mysql -e "CREATE DATABASE local_mgtest CHARACTER SET utf8mb4"
mysqldump --single-transaction local | mysql local_mgtest

# 2. remove legacy tag posts from the clone only (the script needs a start with none)
mysql -e "DELETE FROM local_mgtest.wp_posts WHERE post_type='dynamic_tag'"

# 3. run, pointing WordPress at the clone
$env:WP_DB_NAME = 'local_mgtest'
php migration-scenarios.php          # see tests/golden-master/run.ps1 for the PHP flags used on this machine

# 4. drop the clone
mysql -e "DROP DATABASE local_mgtest"
```

`WP_DB_NAME` is honoured by `tests/golden-master/wp-boot.php`: it defines `DB_NAME` before `wp-config.php`
does, and swallows the single "already defined" warning that causes.

At the end the script puts the clone back exactly as it found it (rows, posts, options, backup files and the
private backup directory) and proves it against a snapshot, so it can be re-run.

## What a passing run proves, and what it does not

* Proves: the decisions in `WP_Dynamic_Tags_Migration_Planner` are applied correctly to a database; writes are
  atomic per batch; a batch that fails halfway leaves nothing behind; cleanup deletes only verified, unchanged
  posts and suspends the legacy delete hooks (checked with the bridge's hard-delete option on, so a failure
  would delete rows); a tampered or path-traversal backup is rejected; a genuine one restores byte-for-byte.
* A mutation check was run when this was written: with `suspend_legacy_hooks()` disabled, four checks fail.
* Does not prove: behaviour under concurrent requests (the lock is exercised, not raced), multisite, or a
  persistent object cache.

## Browser testing of the admin UI

The migration notice, panel and AJAX endpoints were exercised in a real browser against the clone, served by a
throwaway `php -S 127.0.0.1:8089` with an `auto_prepend_file` that defines `DB_NAME`, `WP_HOME` and `WP_SITEURL`.
Note: the Participation Portal plugin replaces `wp-login.php` with a custom slug (`/gema-login`) and returns 404
for the default login URL, so log in there.

## WP-CLI commands (executed 2026-11-11)

`wp dynamic-tags status|migrate [--dry-run]|verify|cleanup|conflicts|resolve|restore|rescan` were run for real with
`wp-cli.phar` (sha512 verified against the wp-cli/builds repository) against a **throwaway WordPress** (an extracted WP 7.1.4 in the
scratch folder, the plugin copied in, `wp-config.php` pointing at the disposable database `local_cli`, a copy of the site's data):

* three raw legacy posts inserted by SQL: `migrate --dry-run` planned 3 inserts; `migrate` inserted 3; a second `migrate` processed 0 (idempotent);
  `verify` passed all 5 checks; a legacy post written after the verification was **kept** by `cleanup` (3 deleted, 1 kept);
* `restore <file> --yes` brought 8 rows back and reverted a tampered row, writing a safety backup first; a path-traversal argument
  (`../../wp-config.php`) was refused ("That backup file was not found"); `rescan` queued the late post again.

Recipe: create `local_cli` from a dump of the site, copy the plugin (no `vendor`/`tools`/`tests`) into the throwaway site's
`wp-content/plugins`, write a `wp-config.php` for it, `wp plugin activate Dynamic-tags-v4/wp-dynamic-tags.php`. Never point WP-CLI at the live site for this.
