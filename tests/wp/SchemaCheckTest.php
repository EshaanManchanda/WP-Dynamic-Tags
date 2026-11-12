<?php
/**
 * Release gate 4 (no schema checks on a normal request). The data layer asked the database whether its table exists
 * (SHOW TABLES) on every facade call: 26 times per request in the pre-refactor baseline. The schema version stored in
 * the option wp_dynamic_tags_table_version, written after create_table() succeeded, is enough to know the table is there;
 * only a missing or different version, or an explicit verify, goes to the database (once per request).
 */
class DT_Schema_Check_Test extends WP_UnitTestCase
{
    /** @var WP_Dynamic_Tags_Database_Manager */
    private $db;

    public function set_up(): void
    {
        parent::set_up();
        $this->db = WP_Dynamic_Tags_Database_Manager::get_instance();
        $this->forget_the_per_request_answer();
    }

    public function tear_down(): void
    {
        update_option('wp_dynamic_tags_table_version', $this->db->get_table_version());
        $this->forget_the_per_request_answer();
        parent::tear_down();
    }

    private function forget_the_per_request_answer(): void
    {
        if (!property_exists($this->db, 'table_found')) {
            return; // the code under test does not remember an answer (yet)
        }
        $prop = new ReflectionProperty($this->db, 'table_found');
        $prop->setValue($this->db, null);
    }

    /** Number of SHOW TABLES queries issued while $work runs. */
    private function show_tables_during(callable $work): int
    {
        $count = 0;
        $counter = static function ($sql) use (&$count) {
            if (stripos((string) $sql, 'SHOW TABLES') !== false) {
                $count++;
            }
            return $sql;
        };
        add_filter('query', $counter);
        $work();
        remove_filter('query', $counter);
        return $count;
    }

    public function test_a_known_schema_version_means_no_schema_query_at_all(): void
    {
        update_option('wp_dynamic_tags_table_version', $this->db->get_table_version());
        $manager = WP_Dynamic_Tags_Table_Manager::get_instance();

        $queries = $this->show_tables_during(function () use ($manager) {
            for ($i = 0; $i < 3; $i++) {
                $this->assertTrue($this->db->table_exists());
                $this->db->ensure_table_exists();
                $manager->get_tags(array('status' => 'active'));
                $manager->get_tag(1);
                $manager->get_tag_by_shortcode('does_not_exist');
            }
        });

        $this->assertSame(0, $queries, 'the stored schema version replaces the per-call SHOW TABLES');
    }

    public function test_without_a_stored_version_the_table_is_checked_once_per_request(): void
    {
        delete_option('wp_dynamic_tags_table_version');

        $queries = $this->show_tables_during(function () {
            for ($i = 0; $i < 4; $i++) {
                $this->assertTrue($this->db->table_exists());
            }
        });

        $this->assertSame(1, $queries, 'one real check, remembered for the rest of the request');
    }

    public function test_verify_always_asks_the_database(): void
    {
        update_option('wp_dynamic_tags_table_version', $this->db->get_table_version());

        $queries = $this->show_tables_during(function () {
            $this->assertTrue($this->db->table_exists(true));
            $this->assertTrue($this->db->table_exists(true));
        });

        $this->assertSame(2, $queries, 'the admin health check must see the real table, not the stored version');
    }
}
