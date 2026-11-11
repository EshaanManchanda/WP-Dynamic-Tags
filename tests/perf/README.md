# Performance baseline

Measures what one front-end style request costs on a site with thousands of tags: boot (shortcode registration),
queries, writes, schema checks, peak memory, and rendering. Used for release gate 4 (no synchronous database
writes during rendering, no schema checks or transient churn on normal requests).

* `seed.php` fills `wp_dynamic_tags` with N rows (`perf_000001`...). **Destructive**; it refuses unless
  `DB_NAME` is the disposable clone `local_perf`.
* `measure.php` boots WordPress in a fresh process and records query counts (with `SAVEQUERIES`) for boot and
  for rendering 40 shortcodes (20 distinct tags, then the same tag 20 times to see cache behaviour).
* `run.ps1` runs several fresh-process samples and writes the medians to `<label>.json`.

```powershell
# create the clone (read-only dump of the live DB), see tests/live/README.md for the mysql flags used here
mysql -e "CREATE DATABASE local_perf CHARACTER SET utf8mb4"; mysqldump --single-transaction local | mysql local_perf
.\run.ps1 -Label after -Count 5000 -Seed
mysql -e "DROP DATABASE local_perf"
```

`baseline-pre-refactor.json` was captured on the code at commit `86be33b` (PHP 8.2.29, MySQL 8.4, 5,000 rows,
5 samples, medians). Compare later runs with it on the same machine only; absolute milliseconds depend on the
machine, the query counts do not. Numbers in that file: 68 queries per boot (8 of them transient writes, 26
schema checks), 78 MB peak, 5,000 shortcodes registered in about 1.6 s, and one database write for **every**
render, including repeated renders of the same tag.
