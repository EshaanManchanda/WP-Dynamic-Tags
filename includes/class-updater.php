<?php
/**
 * Updater: offers new versions of the plugin from its GitHub Releases, through WordPress's own update system.
 *
 * How it works
 *  - The plugin header carries `Update URI: https://github.com/EshaanManchanda/WP-Dynamic-Tags`. WordPress then does not ask
 *    wordpress.org about this plugin and asks the filter update_plugins_github.com instead, during its own update check (twice a
 *    day, or when someone presses "Check again" on the Updates screen). Updates are installed by WordPress's normal updater
 *    (Plugins screen, WP-CLI, auto-updates); nothing here installs anything.
 *  - The newest published release is read from the GitHub API. It is used only if every part of it validates: a plain x.y.z tag,
 *    not a draft or pre-release, the ZIP and the manifest asset named exactly as the release workflow names them, at the exact
 *    URLs GitHub serves them from. The manifest (a small JSON file in the same release) carries the ZIP's SHA-256 and the
 *    WordPress and PHP requirements.
 *  - Before WordPress installs the ZIP it is compared with that SHA-256 (upgrader_pre_download). A mismatch, or a package this
 *    site has not checked, is refused before the installed copy is touched. The checksum detects a damaged or substituted
 *    download; it is not a signature, so it does not protect against someone who can edit the release itself.
 *  - Anything that fails (GitHub down, rate limited, nothing published, a release that does not validate) offers nothing new and
 *    raises nothing. WordPress rebuilds its update list from scratch on every check, so a failure must not look like "no
 *    update": the last release that did validate keeps being offered, and the next try waits an hour.
 *
 * What is sent: one GET to api.github.com and one to github.com (the manifest), with a generic user agent and no cookies,
 * site address, license key or user data. GitHub sees the server's IP address like any web server it talks to.
 * Free and Paid sites are treated the same; no license is involved.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Updater
{
    /** Network option: array('release' => validated release|null, 'next_check' => unix time). A cache, deleted on uninstall. */
    const OPTION = 'dt_update_state';

    const SLUG = 'wp-dynamic-tags';

    const HOME_URL = 'https://github.com/EshaanManchanda/WP-Dynamic-Tags';

    const API_URL = 'https://api.github.com/repos/EshaanManchanda/WP-Dynamic-Tags/releases/latest';

    const DOWNLOAD_PREFIX = 'https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/download/';

    const RELEASE_PREFIX = 'https://github.com/EshaanManchanda/WP-Dynamic-Tags/releases/tag/';

    /**
     * How long a good answer is kept: less than WordPress's own twelve-hour cycle, so the cache never makes an update appear a
     * cycle late, yet long enough that many sites behind one IP address do not use up GitHub's 60 requests an hour.
     */
    const OK_TTL = 21600;

    /** After a failure: try again in an hour. */
    const FAIL_TTL = 3600;

    /** The manifest is a few hundred bytes; refuse anything that claims to be more than this. */
    const MANIFEST_MAX_BYTES = 65536;

    /** The release notes kept for the "View details" popup. */
    const NOTES_MAX_CHARS = 20000;

    public static function register(): void
    {
        add_filter('update_plugins_github.com', array(__CLASS__, 'filter_update'), 10, 3);
        add_filter('plugins_api', array(__CLASS__, 'filter_plugins_api'), 10, 3);
        add_filter('upgrader_pre_download', array(__CLASS__, 'filter_pre_download'), 10, 2);
        add_filter('upgrader_source_selection', array(__CLASS__, 'filter_source_selection'), 10, 4);
    }

    /** The plugin file as WordPress names it ("wp-dynamic-tags/wp-dynamic-tags.php"). */
    public static function plugin_file(): string
    {
        return plugin_basename(dirname(__DIR__) . '/wp-dynamic-tags.php');
    }

    // ================================================================== WordPress hooks

    /**
     * update_plugins_github.com: every plugin whose Update URI is on github.com comes through here, so anything that is not this
     * plugin is returned as it came.
     *
     * @param array|false $update      What an earlier filter answered.
     * @param array       $plugin_data The plugin's headers (Version is the installed version).
     * @param string      $plugin_file The plugin's file, relative to the plugins folder.
     * @return array|false
     */
    public static function filter_update($update, $plugin_data = array(), $plugin_file = '')
    {
        if ($plugin_file !== self::plugin_file() || !is_array($plugin_data)) {
            return $update;
        }
        $release = self::get_release(self::is_check_again());
        if ($release === null) {
            return $update;
        }

        $answer = array(
            'slug'         => self::SLUG,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'requires'     => $release['requires'],
            'tested'       => $release['tested'],
            'requires_php' => $release['requires_php'],
        );
        // Only a release newer than the installed one carries a package. WordPress files an answer that is not newer under
        // "no update" (which is what lets the Plugins screen offer the auto-update switch); it can never become a downgrade.
        if (version_compare($release['version'], (string) ($plugin_data['Version'] ?? '0'), '>')) {
            $answer['package'] = $release['package'];
        }
        return $answer;
    }

    /**
     * plugins_api: the "View version details" popup of the Plugins screen.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object|null        $args
     * @return false|object|array
     */
    public static function filter_plugins_api($result, $action = '', $args = null)
    {
        if ($action !== 'plugin_information' || !is_object($args) || !isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }
        $release = self::get_release(false);
        if ($release === null) {
            return $result;
        }

        return (object) array(
            'name'          => 'WP Dynamic Tags',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="' . esc_url('https://eshaanportfolio.vercel.app/') . '">Eshaan Manchanda</a>',
            'homepage'      => self::HOME_URL,
            'download_link' => $release['package'],
            'requires'      => $release['requires'],
            'tested'        => $release['tested'],
            'requires_php'  => $release['requires_php'],
            'last_updated'  => $release['published'],
            'sections'      => array(
                'description' => esc_html__('Create dynamic tags and use them as shortcodes in WordPress, Elementor and other page builders.', 'wp-dynamic-tags'),
                // The notes are the release's text, written on GitHub: shown as text, never as markup.
                'changelog'   => wpautop(esc_html($release['notes'])),
            ),
        );
    }

    /**
     * upgrader_pre_download: the ZIP is downloaded here, checked against the release's SHA-256, and only then handed to
     * WordPress. A package of this plugin that this site has not checked is refused too (it has no checksum to compare).
     *
     * @param false|string|WP_Error $reply   What an earlier filter answered (false: nobody handled it).
     * @param string                $package The package URL WordPress is about to download.
     * @return false|string|WP_Error The path of the verified file, an error, or $reply untouched for any other package.
     */
    public static function filter_pre_download($reply, $package = '')
    {
        if ($reply !== false || !is_string($package) || !self::is_own_package_url($package)) {
            return $reply;
        }

        // The stored answer, whatever its age: it is what WordPress offered, and the user clicked on that. Asking GitHub again
        // here could swap the release under the click.
        $state   = self::read_state();
        $release = $state === null ? null : $state['release'];
        if ($release === null || $release['package'] !== $package) {
            return new WP_Error('dt_update_unverified', __('This update package does not match the release this site last checked, so it was not installed. Open Dashboard > Updates, press "Check again" and try once more.', 'wp-dynamic-tags'));
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package);
        if (is_wp_error($file)) {
            return $file;
        }
        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($release['sha256'], $actual)) {
            wp_delete_file($file);
            return new WP_Error('dt_update_checksum', __('The downloaded update does not match the checksum published with the release, so it was not installed. Nothing was changed; try again later.', 'wp-dynamic-tags'));
        }
        return $file;
    }

    /**
     * upgrader_source_selection: WordPress installs an update into the folder named by the package's root, not into the folder
     * the plugin currently lives in. The release ZIP's root is wp-dynamic-tags/, so a copy installed anywhere else (GitHub's
     * "Source code" ZIP, a renamed or development folder) would get a second copy next to the old one and stay on the old
     * version. The unpacked folder is renamed to the installed one, so the update replaces the plugin where it is.
     *
     * @param string|WP_Error $source        The unpacked folder (with a trailing slash).
     * @param string          $remote_source The folder WordPress unpacked into.
     * @param mixed           $upgrader      Unused.
     * @param array|mixed     $hook_extra    What is being updated ('plugin' => the plugin file for a plugin update).
     * @return string|WP_Error The folder to install from.
     */
    public static function filter_source_selection($source, $remote_source = '', $upgrader = null, $hook_extra = array())
    {
        if (!is_array($hook_extra) || ($hook_extra['plugin'] ?? null) !== self::plugin_file() || !is_string($source) || !is_string($remote_source)) {
            return $source;
        }
        $installed = basename(dirname(__DIR__));
        $parent    = untrailingslashit(wp_normalize_path($remote_source));
        $unpacked  = untrailingslashit(wp_normalize_path($source));
        if (dirname($unpacked) !== $parent || basename($unpacked) === $installed) {
            return $source; // not what this method is for, or already named right
        }

        global $wp_filesystem;
        $target = $parent . '/' . $installed;
        if (!is_object($wp_filesystem) || !$wp_filesystem->move($unpacked, $target, true)) {
            return new WP_Error('dt_update_rename', __('The update could not be unpacked into the folder the plugin is installed in, so it was not installed. Nothing was changed.', 'wp-dynamic-tags'));
        }
        return trailingslashit($target);
    }

    // ================================================================== the release

    /**
     * The newest valid release, from the stored answer while it is fresh, from GitHub otherwise.
     *
     * @return array|null See sanitize_release().
     */
    private static function get_release(bool $ignore_cache): ?array
    {
        $state = self::read_state();
        if ($state !== null && !$ignore_cache && $state['next_check'] > time()) {
            return $state['release'];
        }

        $fresh = self::fetch_release();
        if ($fresh !== null) {
            $release = $fresh;
            $wait    = self::OK_TTL;
        } else {
            // WordPress rebuilds its update list from nothing on each check: forgetting the last good release here would
            // withdraw an update that is already on offer, for as long as GitHub is out of reach.
            $release = $state === null ? null : $state['release'];
            $wait    = self::FAIL_TTL;
        }
        update_site_option(self::OPTION, array('release' => $release, 'next_check' => time() + $wait));
        return $release;
    }

    /**
     * "Check again" on the Updates screen (update-core.php?force-check=1) skips the stored answer, for someone who may update
     * plugins. The link carries no nonce and the request changes nothing but this cache.
     */
    private static function is_check_again(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_GET['force-check']) && current_user_can('update_plugins');
    }

    /** @return array{release: array|null, next_check: int}|null Null when nothing usable is stored (nothing, or a cache that does not look like ours). */
    private static function read_state(): ?array
    {
        $raw = get_site_option(self::OPTION, null);
        if (!is_array($raw) || !array_key_exists('release', $raw) || !isset($raw['next_check']) || !is_int($raw['next_check'])) {
            return null;
        }
        $release = $raw['release'];
        if ($release !== null) {
            $release = self::sanitize_release($release);
            if ($release === null) {
                return null; // tampered or from another version of this class: forget it and ask again
            }
        }
        return array('release' => $release, 'next_check' => $raw['next_check']);
    }

    /** @return array|null A validated release, or null when GitHub did not answer or the answer does not validate. */
    private static function fetch_release(): ?array
    {
        $body = self::get(self::API_URL, 'application/vnd.github+json', 0);
        if ($body === null) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        $tag = isset($json['tag_name']) && is_string($json['tag_name']) ? $json['tag_name'] : '';
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $m)) {
            return null;
        }
        $version = $m[1];
        if (($json['draft'] ?? null) !== false || ($json['prerelease'] ?? null) !== false) {
            return null;
        }
        $page = self::RELEASE_PREFIX . rawurlencode($tag);
        if (($json['html_url'] ?? null) !== $page) {
            return null;
        }

        $zip_name      = "wp-dynamic-tags-$version.zip";
        $manifest_name = "wp-dynamic-tags-$version.manifest.json";
        $base          = self::DOWNLOAD_PREFIX . rawurlencode($tag) . '/';
        if (!self::has_asset($json, $zip_name, $base . $zip_name, PHP_INT_MAX)
            || !self::has_asset($json, $manifest_name, $base . $manifest_name, self::MANIFEST_MAX_BYTES)) {
            return null;
        }

        $manifest = self::get($base . $manifest_name, 'application/json', self::MANIFEST_MAX_BYTES);
        $manifest = $manifest === null ? null : json_decode($manifest, true);
        if (!is_array($manifest) || ($manifest['plugin'] ?? null) !== self::SLUG || ($manifest['version'] ?? null) !== $version
            || ($manifest['tag'] ?? null) !== $tag || ($manifest['zip'] ?? null) !== $zip_name) {
            return null;
        }

        $notes     = isset($json['body']) && is_string($json['body']) ? mb_substr($json['body'], 0, self::NOTES_MAX_CHARS) : '';
        $published = isset($json['published_at']) && is_string($json['published_at']) && strtotime($json['published_at']) !== false ? $json['published_at'] : '';

        return self::sanitize_release(array(
            'version'      => $version,
            'tag'          => $tag,
            'package'      => $base . $zip_name,
            'url'          => $page,
            'sha256'       => $manifest['sha256'] ?? null,
            'requires'     => $manifest['requires'] ?? null,
            'tested'       => $manifest['tested'] ?? null,
            'requires_php' => $manifest['requires_php'] ?? null,
            'notes'        => $notes,
            'published'    => $published,
        ));
    }

    /**
     * Validates a release, whether it was just built from GitHub's answer or read back from the stored cache: the stored copy is
     * never trusted merely because it is in the database.
     *
     * @param mixed $r
     * @return array{version: string, tag: string, package: string, url: string, sha256: string, requires: string, tested: string, requires_php: string, notes: string, published: string}|null
     */
    private static function sanitize_release($r): ?array
    {
        if (!is_array($r)) {
            return null;
        }
        foreach (array('version', 'tag', 'package', 'url', 'sha256', 'requires', 'tested', 'requires_php', 'notes', 'published') as $key) {
            if (!isset($r[$key]) || !is_string($r[$key])) {
                return null;
            }
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $r['version']) || !in_array($r['tag'], array($r['version'], 'v' . $r['version']), true)) {
            return null;
        }
        $base = self::DOWNLOAD_PREFIX . rawurlencode($r['tag']) . '/';
        if ($r['package'] !== $base . 'wp-dynamic-tags-' . $r['version'] . '.zip' || $r['url'] !== self::RELEASE_PREFIX . rawurlencode($r['tag'])) {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $r['sha256'])) {
            return null;
        }
        foreach (array('requires', 'tested', 'requires_php') as $requirement) {
            if (!preg_match('/^\d+(\.\d+){0,2}$/D', $r[$requirement])) {
                return null;
            }
        }
        return array(
            'version'      => $r['version'],
            'tag'          => $r['tag'],
            'package'      => $r['package'],
            'url'          => $r['url'],
            'sha256'       => $r['sha256'],
            'requires'     => $r['requires'],
            'tested'       => $r['tested'],
            'requires_php' => $r['requires_php'],
            'notes'        => mb_substr($r['notes'], 0, self::NOTES_MAX_CHARS),
            'published'    => $r['published'],
        );
    }

    /** True when the release lists an asset with exactly this name, served at exactly this URL, fully uploaded and not empty. */
    private static function has_asset(array $release, string $name, string $url, int $max_bytes): bool
    {
        foreach ((array) ($release['assets'] ?? array()) as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== $name) {
                continue;
            }
            return ($asset['browser_download_url'] ?? null) === $url
                && ($asset['state'] ?? null) === 'uploaded'
                && is_int($asset['size'] ?? null) && $asset['size'] > 0 && $asset['size'] <= $max_bytes;
        }
        return false;
    }

    /** True for a URL that looks like this plugin's release ZIP; such a package is only installed after its checksum is verified. */
    private static function is_own_package_url(string $url): bool
    {
        return strpos($url, self::DOWNLOAD_PREFIX) === 0 && preg_match('#/wp-dynamic-tags-\d+\.\d+\.\d+\.zip$#D', $url) === 1;
    }

    /**
     * One GET, with nothing about this site in it: no cookies, no body, a generic agent (WordPress's default agent carries the
     * site address).
     *
     * @return string|null The body of a 200 answer, or null.
     */
    private static function get(string $url, string $accept, int $limit): ?string
    {
        $args = array(
            'timeout'    => 5,
            'user-agent' => 'WP-Dynamic-Tags/' . WP_DYNAMIC_TAGS_VERSION,
            'headers'    => array('Accept' => $accept),
        );
        if ($limit > 0) {
            $args['limit_response_size'] = $limit;
        }
        $response = wp_safe_remote_get($url, $args);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        return is_string($body) && $body !== '' ? $body : null;
    }
}
