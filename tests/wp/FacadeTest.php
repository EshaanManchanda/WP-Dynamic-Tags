<?php
/**
 * The public PHP API sibling plugins use (docs/API.md): WP_Dynamic_Tags_Table_Manager.
 * B1 partial update, B2 limit 0, B3 search by shortcode, B4 chatbot payload (title / group_id aliases).
 */
class DT_Facade_Test extends WP_UnitTestCase
{
    private function tm(): WP_Dynamic_Tags_Table_Manager
    {
        return WP_Dynamic_Tags_Table_Manager::get_instance();
    }

    private function make(string $name, string $shortcode, string $content = 'c'): int
    {
        $id = $this->tm()->create_tag(['tag_name' => $name, 'shortcode' => $shortcode, 'content' => $content]);
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        return $id;
    }

    // ------------------------------------------------------------------ B1

    public function test_update_tag_accepts_a_partial_update(): void
    {
        $id = $this->make('Fees', 'fac_fees', 'old');
        $result = $this->tm()->update_tag($id, ['content' => 'new']);
        $this->assertTrue($result, is_wp_error($result) ? $result->get_error_message() : '');
        $tag = $this->tm()->get_tag($id);
        $this->assertSame('new', $tag->content);
        $this->assertSame('Fees', $tag->tag_name, 'fields that were not sent are untouched');
    }

    public function test_update_tag_still_validates_the_fields_it_receives(): void
    {
        $id = $this->make('Fees B', 'fac_fees_b');
        $this->make('Other', 'fac_other');
        $this->assertWPError($this->tm()->update_tag($id, ['shortcode' => 'fac_other']), 'duplicate shortcode');
        $this->assertWPError($this->tm()->update_tag($id, ['status' => 'bogus']), 'invalid status');
        $this->assertWPError($this->tm()->update_tag($id, ['tag_name' => 'bad name!']), 'invalid name');
    }

    public function test_create_tag_still_requires_a_name(): void
    {
        $this->assertWPError($this->tm()->create_tag(['shortcode' => 'fac_noname', 'content' => 'x']));
    }

    // ------------------------------------------------------------------ B4 (chatbot-by-eshaan payload)

    public function test_create_tag_accepts_the_title_and_group_id_aliases(): void
    {
        $group = self::factory()->term->create(['taxonomy' => 'tag_groups', 'name' => 'Chatbot Events']);
        $id = $this->tm()->create_tag(['title' => 'Event fees', 'shortcode' => 'fac_event_fees', 'content' => 'Waiting for event sync...', 'group_id' => $group]);
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        $tag = $this->tm()->get_tag($id);
        $this->assertSame('Event fees', $tag->tag_name);
        $this->assertSame($group, (int) $tag->category_id);
    }

    public function test_update_tag_accepts_the_aliases_too(): void
    {
        $id = $this->make('Alias target', 'fac_alias');
        $this->assertTrue($this->tm()->update_tag($id, ['title' => 'Alias renamed']));
        $this->assertSame('Alias renamed', $this->tm()->get_tag($id)->tag_name);
    }

    public function test_the_real_field_wins_over_its_alias(): void
    {
        $id = $this->tm()->create_tag(['tag_name' => 'Real', 'title' => 'Alias', 'shortcode' => 'fac_real', 'content' => 'x']);
        $this->assertSame('Real', $this->tm()->get_tag($id)->tag_name);
    }

    public function test_the_row_id_cannot_be_chosen_by_the_caller(): void
    {
        $id = $this->tm()->create_tag(['id' => 987654, 'tag_name' => 'Fixed id', 'shortcode' => 'fac_fixedid', 'content' => 'x']);
        $this->assertNotSame(987654, $id);
        $keep = $this->make('Keeper', 'fac_keeper');
        $this->tm()->update_tag($keep, ['id' => 555555, 'content' => 'y']);
        $this->assertSame('y', $this->tm()->get_tag($keep)->content);
        $this->assertEmpty($this->tm()->get_tag(555555), "no row with the id the caller asked for");
    }

    // ------------------------------------------------------------------ B2

    public function test_a_limit_of_zero_means_no_limit(): void
    {
        $this->make('Limit one', 'fac_lim1');
        $this->make('Limit two', 'fac_lim2');
        $this->make('Limit three', 'fac_lim3');
        $all = $this->tm()->get_tags(['limit' => 0, 'search' => 'fac_lim']);
        $this->assertCount(3, $all);
        $this->assertCount(2, $this->tm()->get_tags(['limit' => 2, 'search' => 'fac_lim']));
    }

    // ------------------------------------------------------------------ B3

    public function test_search_matches_the_shortcode(): void
    {
        $this->make('Zebra', 'fac_findme_xyz', 'stripes');
        $found = $this->tm()->search_tags('findme_xyz');
        $this->assertCount(1, $found);
        $this->assertSame('Zebra', $found[0]->tag_name);
        $this->assertSame(1, (int) WP_Dynamic_Tags_Database_Manager::get_instance()->get_tags_count(['search' => 'findme_xyz']), 'the count query must agree with the list');
    }
}
