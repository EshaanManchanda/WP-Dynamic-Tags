# WP Dynamic Tags 3.1.1: release notes

3.1.1 is the **first public release**. It is 3.1.0 (which was never published on its own: see
[RELEASE-NOTES-3.1.0.md](RELEASE-NOTES-3.1.0.md) for everything it changed) plus **automatic updates from GitHub Releases**.
Nothing about tags, rendering, storage or the admin differs from 3.1.0.

Requirements: WordPress 7.0 or newer, PHP 8.2 or newer.

## What is new: updates

The plugin is distributed from GitHub, not WordPress.org, so until now a site that installed the ZIP never heard about a newer
version. The plugin header now carries `Update URI: https://github.com/EshaanManchanda/WP-Dynamic-Tags`, and WordPress's own update
check asks the plugin whether a newer release exists. From then on it is an ordinary update: **Dashboard > Updates**, the
Plugins screen ("View details" shows the release notes), WP-CLI, and auto-updates if you switch them on. It is the same on the
Free and the Paid plan; no license is involved. There is no new setting and no new screen.

**What a release has to be before it is offered.** The newest published release on GitHub is used only if every part of it
validates: a plain `x.y.z` tag; not a draft and not a pre-release; the release page and the two files it carries at exactly the
addresses the release workflow publishes them at; the ZIP named `wp-dynamic-tags-<version>.zip`, not empty and fully uploaded; and
a small `wp-dynamic-tags-<version>.manifest.json` (the ZIP's SHA-256 and the WordPress and PHP requirements) that agrees with the
tag. Anything else offers nothing. A release that is not newer than the installed version is never offered, so an update cannot be
a downgrade.

**The download is checked.** Before WordPress installs the ZIP it is compared with the SHA-256 in the manifest. A file that does
not match, or a package of this plugin that the site has not checked, is refused before the installed copy is touched.

**A failure changes nothing.** GitHub down, rate limited, nothing published yet, a release that does not validate: no update is
offered, nothing is shown and nothing is logged as an error. WordPress builds its list of updates from nothing on every check, so
a failure must not look like "no update"; the last release that did validate keeps being offered, and the next try waits an hour.
A good answer is kept for six hours (shorter than WordPress's own twelve-hour cycle, so the cache never makes an update late, yet
long enough that many sites behind one IP address do not use up GitHub's 60 requests an hour). *Dashboard > Updates > Check again*
asks GitHub at once, for someone who may update plugins. The cache is one network option, `dt_update_state`, never autoloaded, and
removed on uninstall whether or not the tags are kept.

**An update goes into the folder the plugin lives in.** WordPress installs an update into the folder named by the package's root
(`wp-dynamic-tags/`), not the folder the plugin is in. For a copy anywhere else (GitHub's "Source code" ZIP, a renamed or
development folder) the old folder would be removed and the plugin would disappear from the path it was activated under. The
updater renames the unpacked folder to the installed one, so the update replaces the plugin where it is. This was reproduced on a
real WordPress: with the rename removed, updating a copy in `dt-renamed/` left no plugin in `dt-renamed/` and a new one in
`wp-dynamic-tags/`.

**Installing.** Use the release ZIP (`wp-dynamic-tags-<version>.zip`), **not** GitHub's "Source code (zip)": only the release ZIP has
the right folder name. A copy installed from an earlier build has no updater, so it needs one manual upload of 3.1.1 (Plugins > Add
New > Upload; WordPress offers to replace the current version); after that it updates itself.

## Privacy

This replaces the *Privacy* section of the 3.1.0 notes. The plugin has no telemetry and sends nothing to its author. It makes an
outbound request in three situations, all listed under *External services* in `readme.txt`:

* **GitHub, to look for updates** (new in 3.1.1): one request to `api.github.com` and one to `github.com` during WordPress's own
  update check, about twice a day. The requests carry a generic user agent (`WP-Dynamic-Tags/<version>`), no cookies and no body, and
  do **not** deliberately contain the site address, any user information or the license key. GitHub necessarily sees the server's IP
  address, as any web server it talks to.
* **The license server**, only while a key is stored: the key and the site address, when a key is activated or deactivated and twice
  a day. With no key stored, nothing is sent.
* **The address a tag author chose for `{api:URL}`** (Paid), when such a tag renders.

## How a release is made

Everything is in the repository, so a release does not depend on one person's computer.

1. Bump the version (plugin header, `WP_DYNAMIC_TAGS_VERSION`, `readme.txt` Stable tag, Changelog and Upgrade Notice,
   `plugin-info.json`, `README.md`, `uninstall.php`, the `.pot`) and add `docs/RELEASE-NOTES-<version>.md`. `php tools/release/verify.php <version>`
   fails if any of them disagrees.
2. Merge to `main` through a pull request. `.github/workflows/ci.yml` runs on it.
3. Tag the commit on `main` (`git tag vX.Y.Z`, `git push origin vX.Y.Z`). `release.yml` runs every CI gate again, builds the ZIP, its
   `.sha256` and the manifest with `tools/release/build.php`, verifies them with `tools/release/verify.php`, and creates a **draft** GitHub
   release holding exactly those files. It refuses when a gate fails, when the commit is not on `main`, when a release for the tag
   exists, or when the version is not newer than the last published one. It never publishes and never replaces anything.
4. Read the draft and press **Publish**. Only then can a site's update check see it.

A published release is never edited or its tag moved: to fix a bad release, publish a new version.

The ZIP is `git archive` of the tagged commit (what `.gitattributes` does not export-ignore). Two builds of the same commit are
byte-identical: file times come from the commit, and line endings are not converted (a Windows checkout used to give CRLF files and
the Linux runner LF files, so the same commit made two different ZIPs). The release workflow's ZIP can therefore be compared with a
local build of the tag by its SHA-256.

## What was verified for this release

Everything below was run on 2026-11-16 on a development PC (Windows, MySQL 8.4) unless it says GitHub Actions.

* **Static:** PHPStan level 5, 0 errors; the WordPress security and i18n sniffs (output escaping, nonces, input validation, prepared SQL,
  text domain), 0 findings (the same command finds 14 errors in a file with deliberate violations); `composer audit --locked`, no advisories;
  a syntax check of all 100 tracked PHP files on PHP 8.2.29 and 8.5.12.
* **Root unit suite:** 191 tests on PHP 8.2.29 and 8.5.12.
* **WordPress suites** (front, then admin), all green, on WordPress 7.0.8 and 7.1.4, PHP 8.2.29 and 8.5.12, as a single site and as a
  network: the front suite 303 tests and the admin suite 280 (218 and 278 in 3.1.0). `tests/wp*/RigTest.php` fails a run that is not
  the install it claims (a network job that ran as a single site, or the wrong PHP or WordPress); each is shown to fail when the
  claim is false.
* **The updater:** 83 tests with the network replaced (a newer, equal and older release; every way a release can fail to validate; every
  failure of GitHub and that none of them removes an update that is on offer; a poisoned or damaged cache; another plugin's update left
  alone; the details popup and that its notes are escaped; the checksum; the folder rename; the Free and Paid plans alike). 29
  deliberate defects put into a copy of the class are all caught by a failing test (two escaped on the first run, and a missing
  assertion and a missing test were added for them).
* **The built ZIP, installed on a blank WordPress 7.1.4** (`tools/release/lifecycle.php`, 91 checks, on PHP 8.2.29 and again on 8.5.12): install and
  activation; the Update URI and hooks; a group and tags; the Free limit refusing a person's 16th tag but never a plugin's; the tags
  rendering; a Paid token rendering nothing on Free and its value on Paid; the updater accepting the manifest and ZIP the build really
  wrote and refusing a ZIP changed after the build; an uninstall that keeps the data and one that deletes it, both leaving nothing
  they should not; and **WordPress's own upgrader updating an install of the previous version** in place (new files, activates
  cleanly, every tag still there, renders what it rendered, no second copy), refusing a tampered ZIP with nothing changed, and
  updating a copy installed in a differently named folder where it lives. The "previous version" is this very build with its
  version lowered one step, because no earlier release with an updater exists. The lifecycle is shown to fail on the old 3.1.0 ZIP
  (no updater) and, in the last scenario, when the folder rename is removed.
* **WordPress Plugin Check 2.2.0** on the ZIP, installed on WordPress 7.1.4: **1 error and 307 warnings.** The error is
  `plugin_updater_detected` ("Use of the Update URI header is not allowed in plugins hosted on WordPress.org"): it is the updater itself,
  by design (see the limits below). The 307 warnings are the 3.1.0 ones, unchanged and explained in `RELEASE-NOTES-3.1.0.md` (direct
  queries on the plugin's own table, table names in queries, `error_log()` calls behind `WP_DEBUG`, the documented `dt_` hook names and a
  few single items). The first run showed one new warning, an Upgrade Notice over the 300 characters WordPress allows; it was shortened.
* **The release files:** `verify.php` passes the repository and the ZIP and rejects the old ZIP and ZIPs with development files, a
  second root folder, a syntax error or a path that climbs out of the folder. `actionlint` reports nothing on both workflows and
  reports the five defects of a deliberately broken one.

* **GitHub Actions** (CI on pull request #1, 2026-11-16, run 43861325831 on `a0ae029` merged with `main`): all 15 jobs green in under a
  minute. Static analysis (lint, PHPStan, the security and i18n sniffs, `composer audit`); the unit suite on **PHP 8.2, 8.3, 8.4 and 8.5**;
  the WordPress front and admin suites on 7.0.8, 7.1.4 and the newest WordPress, on PHP 8.2 and 8.5, and as a network (303 and 280
  tests, the same counts and assertions as on the development PC); the built ZIP built, verified, installed on a blank WordPress and walked
  through the whole lifecycle on **PHP 8.2, 8.4 and 8.5**; and Gitleaks over all 109 commits. This is the evidence for PHP 8.3 and 8.4,
  which could not be run on the development PC (8.4: the portable `php.exe` is blocked by Windows Application Control and was not
  worked around).

### Not verified, and how that is handled

* **The release workflow has not run yet.** CI has; `release.yml` only runs when a version tag is pushed, and all it does is create a
  draft. Its scripts (`build.php`, `verify.php`) ran in CI's package job, and the workflow itself is validated by `actionlint`. Its first
  run is the first real one; nothing is published unless the owner presses Publish.
* **A real GitHub round trip** (a published release found by a real site's update check) needs a published release. It is the first
  thing to check after publishing: a site on the previous build, *Dashboard > Updates > Check again*.
* **Activating the plugin for a whole network** is not exercised by a lifecycle run: the suites run as a network and the uninstall
  cleans every site, but no run network-activates the ZIP on a multisite and visits a second site.

## Known limits

* **The checksum is not a signature.** It detects a damaged or substituted download, and a ZIP that does not belong to the release it
  claims. It does not protect against someone who can edit the GitHub release itself (the manifest sits beside the ZIP). Signed releases
  (an offline key signing the manifest) are the next step; see `ROADMAP.md`.
* **GitHub's unauthenticated limit** is 60 requests an hour per IP address. The plugin makes at most two requests per update check and
  caches the answer (six hours, one hour after a failure), so this is not reached in practice.
* **WordPress.org.** The `Update URI` header and the updater exist because the plugin is distributed from GitHub; WordPress.org does not
  allow either (guideline 8), and its Plugin Check tool reports them. They would have to go before any submission there, as would the
  "WP" at the start of the name and slug. The other known limits of 3.1.0 (the Plugin Check warnings on direct queries, the number of
  tags a server can carry, groups not travelling in an export) are unchanged: see `RELEASE-NOTES-3.1.0.md`.
