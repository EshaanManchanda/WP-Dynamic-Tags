<?php
/**
 * Migration planner for WP Dynamic Tags.
 *
 * The WordPress-free decision logic of the posts -> table migration: what to do with each legacy
 * `dynamic_tag` post, how to name it, and when it is safe to remove. Everything here is a pure
 * function of its arguments (no database, no WordPress calls), so it can be unit tested and so the
 * engine's behaviour is explainable from a single file.
 *
 * Shapes
 *   post  : ['ID'=>int,'title'=>string,'content'=>string,'excerpt'=>string,'status'=>string,
 *            'date'=>'Y-m-d H:i:s','modified'=>'Y-m-d H:i:s','meta'=>array<string,string>,'group_ids'=>int[]]
 *   row   : ['id'=>int,'shortcode'=>string,'tag_name'=>string,'post_id'=>int,'status'=>string,
 *            'modified_date'=>'Y-m-d H:i:s','category_id'=>int,'content_md5'=>string]
 *   index : built by build_index(); case-insensitive, like the table's UNIQUE keys under its collation.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Dynamic_Tags_Migration_Planner
{
    public const ACTION_INSERT   = 'insert';
    public const ACTION_UPDATE   = 'update';
    public const ACTION_LINK     = 'link';
    public const ACTION_SKIP     = 'skip';
    public const ACTION_CONFLICT = 'conflict';

    /** Seconds after which an unrenewed migration lock is considered abandoned. */
    public const LOCK_TTL = 300;

    private const NAME_MAX = 255;

    /** Post meta that is either mapped to a table column or WordPress housekeeping. */
    private const INTERNAL_META = [
        '_dt_tag_name', '_dt_shortcode', '_dt_priority', '_dt_usage_count', '_dt_last_used',
        '_edit_lock', '_edit_last', '_encloseme', '_pingme',
    ];

    // ------------------------------------------------------------------ naming

    /**
     * The shortcode the legacy post path published for a tag with this title: lowercase, anything
     * outside [a-z0-9] becomes an underscore, runs collapsed, edges trimmed. Never empty.
     */
    public static function derive_shortcode(string $title): string
    {
        $key = strtolower(trim($title));
        $key = (string) preg_replace('/[^a-z0-9]/', '_', $key);
        $key = (string) preg_replace('/_{2,}/', '_', $key);
        $key = trim($key, '_');

        return $key === '' ? 'unnamed_tag' : $key;
    }

    /** Table status for a post status, or null when the post must not be migrated at all. */
    public static function status_for_post(string $post_status): ?string
    {
        if ($post_status === 'publish') {
            return 'active';
        }
        if (in_array($post_status, ['draft', 'pending', 'private', 'future'], true)) {
            return 'inactive';
        }
        return null;
    }

    /** Case-folded key for uniqueness comparisons (the table's collation is case-insensitive). */
    public static function norm(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * New [shortcode, tag_name] for resolving a conflict by renaming the incoming tag. The base is
     * shortened first when needed so the result always fits the 255-character columns.
     *
     * @return array{0:string,1:string}
     */
    public static function rename_for_conflict(string $shortcode, string $tag_name, int $post_id): array
    {
        $sc_suffix   = '_' . $post_id;
        $name_suffix = ' (' . $post_id . ')';

        $shortcode = substr($shortcode, 0, self::NAME_MAX - strlen($sc_suffix));
        if (function_exists('mb_substr')) {
            $tag_name = mb_substr($tag_name, 0, self::NAME_MAX - strlen($name_suffix), 'UTF-8');
        } else {
            $tag_name = substr($tag_name, 0, self::NAME_MAX - strlen($name_suffix));
        }

        return [$shortcode . $sc_suffix, $tag_name . $name_suffix];
    }

    /**
     * Provenance stored on a migrated row, so a migration is traceable and nothing the post carried
     * (all its groups, the shortcode its title used to produce) is lost.
     *
     * @param string[] $aliases
     * @return array{post_id:int,title:string,status:string,group_ids:int[],aliases:string[]}
     */
    public static function legacy_record(array $post, array $aliases): array
    {
        return [
            'post_id'   => (int) $post['ID'],
            'title'     => (string) $post['title'],
            'status'    => (string) $post['status'],
            'group_ids' => array_values(array_map('intval', (array) ($post['group_ids'] ?? []))),
            'aliases'   => array_values($aliases),
        ];
    }

    // ------------------------------------------------------------------ index

    /** @param array<int,array> $rows */
    public static function build_index(array $rows): array
    {
        $index = ['by_post_id' => [], 'by_shortcode' => [], 'by_tag_name' => []];
        foreach ($rows as $row) {
            self::remember($index, $row);
        }
        return $index;
    }

    /** Add (or replace) a row in the index so later posts in the same run see it. */
    public static function remember(array &$index, array $row): void
    {
        $row['id']          = (int) $row['id'];
        $row['post_id']     = (int) ($row['post_id'] ?? 0);
        $row['category_id'] = (int) ($row['category_id'] ?? 0);

        $index['by_shortcode'][self::norm((string) $row['shortcode'])] = $row;
        $index['by_tag_name'][self::norm((string) $row['tag_name'])]   = $row;
        if ($row['post_id'] > 0) {
            $index['by_post_id'][$row['post_id']] = $row;
        }
    }

    // ------------------------------------------------------------------ planning

    /**
     * Decide what to do with one legacy post.
     *
     * @return array{action:string,reason:string,row_id:?int,shortcode:string,tag_name:string,aliases:string[],data:array}
     */
    public static function plan_post(array $post, array $index): array
    {
        $id     = (int) $post['ID'];
        $status = self::status_for_post((string) $post['status']);
        if ($status === null) {
            return self::result(self::ACTION_SKIP, 'excluded_status');
        }

        $title    = trim((string) $post['title']);
        $tag_name = $title === '' ? 'Untitled tag ' . $id : $title;
        $derived  = self::derive_shortcode($title);
        $md5      = md5((string) $post['content']);
        $meta_sc  = trim((string) ($post['meta']['_dt_shortcode'] ?? ''));

        // 1. Already linked to a row: nothing to do unless the post was edited after the row.
        if (isset($index['by_post_id'][$id])) {
            return self::plan_for_matched_row($post, $index['by_post_id'][$id], $status, $md5, false);
        }

        // 2. Mirror of a table row (the table path created this post): link, never duplicate.
        if ($meta_sc !== '' && isset($index['by_shortcode'][self::norm($meta_sc)])) {
            $row = $index['by_shortcode'][self::norm($meta_sc)];
            if ($row['post_id'] !== 0 && $row['post_id'] !== $id) {
                return self::result(self::ACTION_CONFLICT, 'shortcode_linked_to_other_post', $row['id'], $row['shortcode'], $tag_name);
            }
            return self::plan_for_matched_row($post, $row, $status, $md5, true);
        }

        // 3. A tag that only ever existed as a post: it keeps the shortcode its title produced.
        if (self::len($tag_name) > self::NAME_MAX || strlen($derived) > self::NAME_MAX) {
            return self::result(self::ACTION_CONFLICT, 'name_too_long', null, $derived, $tag_name);
        }

        $by_shortcode = $index['by_shortcode'][self::norm($derived)] ?? null;
        if ($by_shortcode !== null) {
            $same_tag = in_array($by_shortcode['post_id'], [0, $id], true)
                && self::norm((string) $by_shortcode['tag_name']) === self::norm($tag_name)
                && (string) ($by_shortcode['content_md5'] ?? '') === $md5;
            if ($same_tag) {
                return self::result(self::ACTION_LINK, 'identical_row_exists', $by_shortcode['id'], $by_shortcode['shortcode'], $tag_name, [],
                    ['post_id' => $id, 'modified_date' => (string) $by_shortcode['modified_date']]);
            }
            return self::result(self::ACTION_CONFLICT, 'shortcode_taken', $by_shortcode['id'], $derived, $tag_name);
        }

        $by_name = $index['by_tag_name'][self::norm($tag_name)] ?? null;
        if ($by_name !== null) {
            return self::result(self::ACTION_CONFLICT, 'tag_name_taken', $by_name['id'], $derived, $tag_name);
        }

        return self::result(self::ACTION_INSERT, 'post_native', null, $derived, $tag_name, [], self::build_row($post, $derived, $tag_name));
    }

    /**
     * Build the full table row for a post.
     *
     * @return array<string,mixed>
     */
    public static function build_row(array $post, string $shortcode, string $tag_name, array $aliases = []): array
    {
        $meta     = is_array($post['meta'] ?? null) ? $post['meta'] : [];
        $groups   = array_values(array_map('intval', (array) ($post['group_ids'] ?? [])));
        $modified = self::real_date((string) ($post['modified'] ?? '')) ?? self::real_date((string) ($post['date'] ?? '')) ?? '1970-01-01 00:00:01';
        $created  = self::real_date((string) ($post['date'] ?? '')) ?? $modified;

        $priority = isset($meta['_dt_priority']) && is_numeric($meta['_dt_priority']) ? (int) $meta['_dt_priority'] : 0;
        $usage    = isset($meta['_dt_usage_count']) && is_numeric($meta['_dt_usage_count']) ? (int) $meta['_dt_usage_count'] : 0;

        return [
            'tag_name'       => $tag_name,
            'shortcode'      => $shortcode,
            'content'        => (string) $post['content'],
            'description'    => (string) ($post['excerpt'] ?? ''),
            'category_id'    => $groups[0] ?? 0,
            'priority'       => max(0, min(100, $priority)),
            'usage_count'    => max(0, $usage),
            'created_date'   => $created,
            'modified_date'  => $modified,
            'last_used_date' => self::real_date((string) ($meta['_dt_last_used'] ?? '')),
            'status'         => (string) self::status_for_post((string) $post['status']),
            'post_id'        => (int) $post['ID'],
            'meta_data'      => self::build_meta($post, $aliases),
        ];
    }

    /**
     * Fields that differ for a post that is newer than its row.
     *
     * @return array<string,mixed>
     */
    private static function update_data(array $post, string $status): array
    {
        $groups = array_values(array_map('intval', (array) ($post['group_ids'] ?? [])));
        $data   = [
            'content'       => (string) $post['content'],
            'description'   => (string) ($post['excerpt'] ?? ''),
            'status'        => $status,
            'modified_date' => self::real_date((string) ($post['modified'] ?? '')) ?? '1970-01-01 00:00:01',
        ];
        if ($groups) {
            $data['category_id'] = $groups[0];
        }
        return $data;
    }

    private static function plan_for_matched_row(array $post, array $row, string $status, string $md5, bool $needs_link): array
    {
        $id      = (int) $post['ID'];
        $aliases = self::aliases_for($post, (string) $row['shortcode']);
        $newer   = (string) ($post['modified'] ?? '') > (string) $row['modified_date'];
        $differs = $md5 !== (string) ($row['content_md5'] ?? '') || $status !== (string) $row['status'];

        if ($newer && $differs) {
            $data = self::update_data($post, $status);
            if ($needs_link) {
                $data['post_id'] = $id;
            }
            return self::result(
                $needs_link ? self::ACTION_LINK : self::ACTION_UPDATE,
                $needs_link ? 'mirror_of_row' : 'post_newer_than_row',
                $row['id'], (string) $row['shortcode'], (string) $row['tag_name'], $aliases, $data
            );
        }

        if ($needs_link) {
            return self::result(self::ACTION_LINK, 'mirror_of_row', $row['id'], (string) $row['shortcode'], (string) $row['tag_name'], $aliases,
                ['post_id' => $id, 'modified_date' => (string) $row['modified_date']]);
        }

        return self::result(self::ACTION_SKIP, 'already_migrated', $row['id'], (string) $row['shortcode'], (string) $row['tag_name'], $aliases);
    }

    /**
     * Public names a post carried that its row does not: the post path registered the shortcode
     * derived from the title, so content may still use it.
     *
     * @return string[]
     */
    public static function aliases_for(array $post, string $row_shortcode): array
    {
        $title = trim((string) $post['title']);
        if ($title === '') {
            return [];
        }
        $derived = self::derive_shortcode($title);
        return self::norm($derived) === self::norm($row_shortcode) ? [] : [$derived];
    }

    /** Extra post meta worth keeping, plus a record of where the row came from. */
    private static function build_meta(array $post, array $aliases): array
    {
        $out = [];
        foreach ((array) ($post['meta'] ?? []) as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::INTERNAL_META, true) || strncmp($key, '_wp_', 4) === 0) {
                continue;
            }
            $out[$key] = $value;
        }
        $out['legacy'] = self::legacy_record($post, $aliases);
        return $out;
    }

    // ------------------------------------------------------------------ verification and cleanup safety

    /**
     * How a migrated pair still differs. A row edited after the post is the newer truth and is never
     * reported, so cleanup cannot be blocked by (or lose) legitimate table edits.
     *
     * @param int[] $group_ids the post's tag_groups term IDs
     * @return string[] any of 'content', 'status', 'group'
     */
    public static function pair_mismatches(array $post, array $row, array $group_ids): array
    {
        if ((string) $row['modified_date'] >= (string) ($post['modified'] ?? '')) {
            return [];
        }

        $out = [];
        if (md5((string) $post['content']) !== (string) ($row['content_md5'] ?? '')) {
            $out[] = 'content';
        }
        if (self::status_for_post((string) $post['status']) !== (string) $row['status']) {
            $out[] = 'status';
        }
        if ($group_ids && !in_array((int) ($row['category_id'] ?? 0), array_map('intval', $group_ids), true)) {
            $out[] = 'group';
        }
        return $out;
    }

    /**
     * The five independent checks that must all pass before any legacy post may be deleted.
     *
     * @param array<string,mixed> $facts
     * @return array{passed:bool,checks:array<int,array{id:string,label:string,passed:bool,detail:string}>}
     */
    public static function evaluate_verification(array $facts): array
    {
        $processed = ($facts['status'] ?? '') === 'completed'
            && (int) ($facts['unprocessed'] ?? 0) === 0
            && (int) ($facts['unlinked'] ?? 0) === 0;

        $checks = [
            [
                'id'     => 'processed',
                'label'  => 'Every legacy post was processed',
                'passed' => $processed,
                'detail' => sprintf('status %s, %d after the cursor, %d without a row', (string) ($facts['status'] ?? '?'), (int) ($facts['unprocessed'] ?? 0), (int) ($facts['unlinked'] ?? 0)),
            ],
            [
                'id'     => 'content',
                'label'  => 'Each row matches its post (content, status, group)',
                'passed' => (int) ($facts['mismatches'] ?? 0) === 0,
                'detail' => sprintf('%d mismatches', (int) ($facts['mismatches'] ?? 0)),
            ],
            [
                'id'     => 'shortcodes',
                'label'  => 'Every migrated shortcode registers to this plugin',
                'passed' => (int) ($facts['unregistered'] ?? 0) === 0,
                'detail' => sprintf('%d not registered to this plugin', (int) ($facts['unregistered'] ?? 0)),
            ],
            [
                'id'     => 'conflicts',
                'label'  => 'No unresolved conflicts remain',
                'passed' => (int) ($facts['open_conflicts'] ?? 0) === 0,
                'detail' => sprintf('%d open', (int) ($facts['open_conflicts'] ?? 0)),
            ],
            [
                'id'     => 'backup',
                'label'  => 'The backup is intact',
                'passed' => !empty($facts['backup_exists']) && !empty($facts['backup_sha_ok']) && !empty($facts['backup_counts_ok']),
                'detail' => sprintf('file %s, checksum %s, record counts %s',
                    !empty($facts['backup_exists']) ? 'present' : 'missing',
                    !empty($facts['backup_sha_ok']) ? 'ok' : 'MISMATCH',
                    !empty($facts['backup_counts_ok']) ? 'ok' : 'MISMATCH'),
            ],
        ];

        $passed = true;
        foreach ($checks as $check) {
            $passed = $passed && $check['passed'];
        }

        return ['passed' => $passed, 'checks' => $checks];
    }

    /** True when a migration lock taken at $acquired_at has been abandoned. */
    public static function lock_is_stale(int $acquired_at, int $now, int $ttl = self::LOCK_TTL): bool
    {
        return $acquired_at <= 0 || ($now - $acquired_at) > $ttl;
    }

    // ------------------------------------------------------------------ internals

    private static function result(string $action, string $reason, ?int $row_id = null, string $shortcode = '', string $tag_name = '', array $aliases = [], array $data = []): array
    {
        return [
            'action'    => $action,
            'reason'    => $reason,
            'row_id'    => $row_id,
            'shortcode' => $shortcode,
            'tag_name'  => $tag_name,
            'aliases'   => $aliases,
            'data'      => $data,
        ];
    }

    private static function len(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** A usable 'Y-m-d H:i:s' date, or null for empty / zero dates. */
    private static function real_date(string $value): ?string
    {
        if ($value === '' || strncmp($value, '0000', 4) === 0) {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }
}
