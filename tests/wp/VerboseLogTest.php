<?php
/**
 * L11 / L12: the post-path shortcode callback (register_clean_shortcode, deleted with the post path later in the refactor)
 *
 *  - wrote the raw and the processed content of a tag to debug.log in verbose debug mode; tag content can hold personal data
 *    ({user_email}, certificate tags) and a log file is not a place for it (L11);
 *  - wrote two post meta rows (_dt_usage_count, _dt_last_used) on every render when WP_DEBUG was on, and never counted in
 *    production: a database write during rendering that behaved differently from the table path (L12).
 *
 * The file name sorts last in tests/wp on purpose: it defines DT_DEBUG_VERBOSE for the rest of the process.
 */
class DT_Post_Path_Side_Effects_Test extends WP_UnitTestCase
{
    /** @var string */
    private $log = '';
    /** @var string|false */
    private $previous_log;

    public function set_up(): void
    {
        parent::set_up();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'dtlog');
        $this->previous_log = ini_set('error_log', $this->log);
        if (!defined('DT_DEBUG_VERBOSE')) {
            define('DT_DEBUG_VERBOSE', true);
        }
    }

    public function tear_down(): void
    {
        ini_set('error_log', (string) $this->previous_log);
        @unlink($this->log);
        remove_shortcode('l11_probe');
        remove_shortcode('l12_probe');
        parent::tear_down();
    }

    /** Registers a tag through the post path's own callback factory and returns the tag post id. */
    private function register_post_path_tag(string $code, string $content): int
    {
        $post_id = (int) wp_insert_post(array('post_type' => 'dynamic_tag', 'post_status' => 'publish', 'post_title' => $code, 'post_content' => $content));
        $plugin = WP_Dynamic_Tags_Plugin::get_instance();
        $method = new ReflectionMethod($plugin, 'register_clean_shortcode');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($plugin, $code, $content, $post_id), 'the probe shortcode must be registered');
        return $post_id;
    }

    public function test_a_verbose_debug_log_never_contains_the_content_of_a_tag(): void
    {
        $this->register_post_path_tag('l11_probe', 'SECRET-L11 for {site_name}');

        $out = do_shortcode('[l11_probe]');

        $this->assertStringContainsString('SECRET-L11', $out, 'control: the shortcode still renders');
        $this->assertStringNotContainsString('SECRET-L11', (string) file_get_contents($this->log), 'tag content must not be written to debug.log');
    }

    public function test_rendering_a_post_path_tag_writes_no_usage_meta(): void
    {
        $post_id = $this->register_post_path_tag('l12_probe', 'plain content');

        do_shortcode('[l12_probe]');
        do_shortcode('[l12_probe]');

        $this->assertSame('', get_post_meta($post_id, '_dt_usage_count', true), 'a render must not write post meta');
        $this->assertSame('', get_post_meta($post_id, '_dt_last_used', true));
    }
}
