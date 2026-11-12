<?php
/**
 * Groups (the "Groups" page and its endpoints) on table-only storage (plan Phase 8). A tag is a row of the table and its
 * group is the row's category_id; the page used to count, list and release tag *posts* through term relationships, so once
 * the legacy posts were gone every group showed zero tags, "ungrouped" showed zero, and the tag list of a group was empty.
 */
class DT_Groups_Test extends WP_Ajax_UnitTestCase
{
    /** @var WP_Dynamic_Tags_Groups */
    private $groups;

    /** @var WP_Dynamic_Tags_Table_Manager */
    private $tags;

    public function set_up(): void
    {
        parent::set_up();
        $this->groups = new WP_Dynamic_Tags_Groups();
        $this->tags = WP_Dynamic_Tags_Table_Manager::get_instance();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down(): void
    {
        $_POST = $_GET = $_REQUEST = [];
        parent::tear_down();
    }

    private function group(string $name): int
    {
        $term = wp_insert_term($name, 'tag_groups');
        $this->assertIsArray($term, is_wp_error($term) ? $term->get_error_message() : '');
        return (int) $term['term_id'];
    }

    private function tag(string $code, int $group = 0): int
    {
        $id = $this->tags->create_tag(['tag_name' => 'Tag ' . $code, 'shortcode' => $code, 'content' => 'x', 'category_id' => $group]);
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        return $id;
    }

    /** @return array{json: mixed, raw: string, warnings: string[]} */
    private function ajax(string $action, array $post, string $nonce_action): array
    {
        $_POST = array_merge(['nonce' => wp_create_nonce($nonce_action)], $post);
        $this->_last_response = '';
        $warnings = [];
        set_error_handler(static function ($no, $message) use (&$warnings) {
            if (strpos($message, 'headers already sent') === false) {
                $warnings[] = $message;
            }
            return true;
        });
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
            // expected
        } finally {
            restore_error_handler();
        }
        $raw = (string) $this->_last_response;
        return ['json' => json_decode($raw, true), 'raw' => $raw, 'warnings' => $warnings];
    }

    private function page(): string
    {
        $_GET = ['page' => 'dt-group-manager'];
        ob_start();
        try {
            $this->groups->group_manager_page();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    // ------------------------------------------------------------------ the page

    public function test_the_statistics_count_table_rows_not_tag_posts(): void
    {
        $a = $this->group('Alpha');
        $b = $this->group('Beta');
        $this->tag('grp_a1', $a);
        $this->tag('grp_a2', $a);
        $this->tag('grp_b1', $b);
        $this->tag('grp_none1');
        $this->tag('grp_none2');
        $this->tag('grp_none3');

        $html = $this->page();
        $this->assertMatchesRegularExpression('#>6</div>\s*<div>Total Tags</div>#', $html, 'six tags in the table');
        $this->assertMatchesRegularExpression('#>3</div>\s*<div>Ungrouped Tags</div>#', $html);
        $this->assertMatchesRegularExpression('#>Alpha</div>\s*<div>Most Used Group \(2 tags\)</div>#', $html);
        $this->assertMatchesRegularExpression('#data-group-id="' . $a . '".*?dt-group-count">2<#s', $html, 'the tree shows the count of each group');
        $this->assertMatchesRegularExpression('#data-group-id="' . $b . '".*?dt-group-count">1<#s', $html);
    }

    /** WCAG 2 relative luminance of #rrggbb or #rgb. */
    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public function test_text_on_a_coloured_background_on_the_page_meets_wcag_aa_contrast(): void
    {
        // Lighthouse (axe color-contrast) flagged the group count: #666 on #ddd is 4.22:1, AA needs 4.5:1 for small text.
        $html = $this->page();
        $this->assertSame(1, preg_match('#<style[^>]*>(.*?)</style>#s', $html, $style), 'the page carries its own styles');

        $checked = 0;
        preg_match_all('#([^{}]+)\{([^}]*)\}#', $style[1], $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            if (preg_match('#(?<![-\w])background(?:-color)?:\s*(\#[0-9a-fA-F]{3,6})\b#', $rule[2], $bg) && preg_match('#(?<![-\w])color:\s*(\#[0-9a-fA-F]{3,6})\b#', $rule[2], $fg)) {
                $a = self::luminance($bg[1]);
                $b = self::luminance($fg[1]);
                $ratio = (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
                $this->assertGreaterThanOrEqual(4.5, $ratio, trim($rule[1]) . ': ' . $fg[1] . ' on ' . $bg[1] . ' is ' . round($ratio, 2) . ':1');
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked, 'the check looked at least one rule');
    }

    public function test_a_tag_whose_group_no_longer_exists_counts_as_ungrouped(): void
    {
        $a = $this->group('Real');
        $this->tag('dang_real', $a);
        $dangling = WP_Dynamic_Tags_Database_Manager::get_instance()->insert_tag(['tag_name' => 'Dangling', 'shortcode' => 'dang_gone', 'content' => 'x', 'category_id' => 987654]);
        $this->assertIsInt($dangling, is_wp_error($dangling) ? $dangling->get_error_message() : '');
        $this->tags->flush_cache();

        $html = $this->page();
        $this->assertMatchesRegularExpression('#>2</div>\s*<div>Total Tags</div>#', $html);
        $this->assertMatchesRegularExpression('#>1</div>\s*<div>Ungrouped Tags</div>#', $html, 'older data can hold the id of a group that was deleted without releasing its tags');
    }

    // ------------------------------------------------------------------ escaping

    public function test_names_are_escaped_on_the_page_and_in_the_details(): void
    {
        $group = $this->group('Hostile');
        // WordPress encodes a term name when it stores it, so escaping only matters for a name written some other way
        global $wpdb;
        $wpdb->update($wpdb->terms, ['name' => 'Quo"te <img src=x onerror=alert(31)>'], ['term_id' => $group]);
        clean_term_cache($group, 'tag_groups');
        // the facade refuses markup in a name, but an import or an older row can hold it
        $id = WP_Dynamic_Tags_Database_Manager::get_instance()->insert_tag(['tag_name' => 'Evil <b onmouseover=alert(32)>', 'shortcode' => 'evil_code', 'content' => 'x', 'category_id' => $group]);
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        $this->tags->flush_cache();

        $html = $this->page();
        $this->assertStringContainsString('Quo&quot;te &lt;img src=x', $html, 'a group name is escaped in the tree and the parent list');
        $this->assertStringNotContainsString('<img src=x', $html);

        $r = $this->ajax('dt_get_group_details', ['group_id' => (string) $group], 'dt_get_group_details');
        $details = $r['json']['data']['tags_html'];
        $this->assertStringNotContainsString('<b onmouseover', $details, 'a tag name is escaped in the list');
        $this->assertStringContainsString('&lt;b onmouseover', $details);
    }

    public function test_a_translation_cannot_break_out_of_the_inline_script(): void
    {
        add_filter('gettext', static function ($translated, $text) {
            return $text === 'Unknown error occurred.' ? "It's </script><b id=\"x\">hijack</b>" : $translated;
        }, 10, 2);

        $html = $this->page();
        $this->assertStringNotContainsString('</script><b id="x">', $html, 'a translated string is escaped for JavaScript, not echoed');
        $this->assertStringContainsString("It\\'s", $html, 'the apostrophe is escaped for the string it sits in');
    }

    // ------------------------------------------------------------------ the details endpoint

    public function test_the_details_of_a_group_list_its_table_tags_with_the_real_shortcode_and_the_new_edit_link(): void
    {
        $a = $this->group('Details');
        $id = $this->tag('det_one', $a);
        $this->tag('det_two', $a);
        $this->tag('det_other');

        $r = $this->ajax('dt_get_group_details', ['group_id' => (string) $a], 'dt_get_group_details');
        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $data = $r['json']['data'];
        $this->assertSame(2, $data['count']);
        $this->assertStringContainsString('[det_one]', $data['tags_html'], 'the shortcode of the row, not one made up from the title');
        $this->assertStringContainsString('[det_two]', $data['tags_html']);
        $this->assertStringNotContainsString('det_other', $data['tags_html']);
        $this->assertStringContainsString('page=dt-tag-edit', $data['tags_html']);
        $this->assertStringContainsString('tag=' . $id, $data['tags_html']);
        $this->assertStringNotContainsString('post.php', $data['tags_html'], 'no link to the hidden post editor');
    }

    public function test_a_group_without_tags_says_so(): void
    {
        $a = $this->group('Empty one');
        $r = $this->ajax('dt_get_group_details', ['group_id' => (string) $a], 'dt_get_group_details');
        $this->assertSame(0, $r['json']['data']['count']);
        $this->assertStringContainsString('No tags in this group', $r['json']['data']['tags_html']);
    }

    public function test_the_details_endpoints_do_not_warn_when_the_group_is_missing_from_the_request(): void
    {
        foreach (['dt_get_group_details' => 'dt_get_group_details', 'dt_get_group_edit_data' => 'dt_get_group_edit_data', 'dt_delete_group' => 'dt_group_operation'] as $action => $nonce) {
            $r = $this->ajax($action, [], $nonce);
            $this->assertSame([], $r['warnings'], $action . ': ' . implode(' | ', $r['warnings']));
            $this->assertFalse($r['json']['success'] ?? true, $action);
        }
    }

    // ------------------------------------------------------------------ deleting a group

    public function test_deleting_a_group_releases_its_tags_instead_of_leaving_a_dead_id(): void
    {
        $a = $this->group('To delete');
        $keep = $this->group('To keep');
        $one = $this->tag('del_one', $a);
        $two = $this->tag('del_two', $a);
        $other = $this->tag('del_other', $keep);

        $r = $this->ajax('dt_delete_group', ['group_id' => (string) $a], 'dt_group_operation');
        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);

        $this->assertFalse(term_exists($a, 'tag_groups') ? true : false);
        $this->assertSame(0, (int) $this->tags->get_tag($one)->category_id, 'the tag keeps working, without a group');
        $this->assertSame(0, (int) $this->tags->get_tag($two)->category_id);
        $this->assertSame('active', $this->tags->get_tag($one)->status, 'nothing else about the tag changes');
        $this->assertSame($keep, (int) $this->tags->get_tag($other)->category_id, 'tags of other groups are untouched');
    }

    public function test_a_group_removed_any_other_way_releases_its_tags_too(): void
    {
        $a = $this->group('Removed elsewhere');
        $id = $this->tag('rel_one', $a);

        wp_delete_term($a, 'tag_groups'); // WP-CLI, another plugin, the core term screen

        $this->assertSame(0, (int) $this->tags->get_tag($id)->category_id);
    }
}
