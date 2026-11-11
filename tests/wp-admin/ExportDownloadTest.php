<?php
/**
 * I1: the export file (every tag, including student data of the certificate tags) is kept in a transient so the browser
 * can fetch it with a second request. It used a uniqid() id (13 characters, derived from the clock), no owner and a
 * one hour lifetime, so any administrator who guessed the id could download another one's export later.
 *
 * I4: the old import page (class-import-export.php) read $_POST['conflict_resolution'] without isset() and echoed
 * translated strings unescaped.
 */
class DT_Export_Download_Test extends WP_Ajax_UnitTestCase
{
    private function export_as_new_admin(): array
    {
        WP_Dynamic_Tags_Table_Manager::get_instance()->create_tag(array('tag_name' => 'Export probe', 'shortcode' => 'export_probe', 'content' => 'x'));
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin);
        $_POST = array('nonce' => wp_create_nonce('dt_table_manager'), 'format' => 'json');
        $this->_last_response = '';
        try {
            $this->_handleAjax('dt_table_export');
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
            // expected: wp_send_json_* ends the request
        }
        $json = json_decode((string) $this->_last_response, true);
        $this->assertTrue($json['success'] ?? false, 'the export must succeed: ' . $this->_last_response);
        return array('id' => (string) $json['data']['export_id'], 'user' => $admin);
    }

    // ------------------------------------------------------------------ I1

    public function test_the_export_id_is_unguessable_and_the_export_belongs_to_its_creator_for_ten_minutes(): void
    {
        $e = $this->export_as_new_admin();

        $this->assertSame(32, strlen($e['id']), 'uniqid() gave 13 clock-derived characters');
        $stored = get_transient('dt_export_' . $e['id']);
        $this->assertIsArray($stored);
        $this->assertSame($e['user'], (int) ($stored['user_id'] ?? 0));
        $expires = (int) get_option('_transient_timeout_dt_export_' . $e['id']);
        $this->assertLessThanOrEqual(time() + 10 * MINUTE_IN_SECONDS + 5, $expires, 'an export is kept for ten minutes, not an hour');
    }

    public function test_another_user_cannot_take_the_export(): void
    {
        $e = $this->export_as_new_admin();
        $other = self::factory()->user->create(array('role' => 'administrator'));

        $this->assertNull(WP_Dynamic_Tags_Table_Import_Export::get_instance()->take_export($e['id'], $other));
        $this->assertIsArray(get_transient('dt_export_' . $e['id']), 'a refused request must not consume the creator\'s export');
    }

    public function test_the_export_can_be_taken_once(): void
    {
        $e = $this->export_as_new_admin();
        $io = WP_Dynamic_Tags_Table_Import_Export::get_instance();

        $first = $io->take_export($e['id'], $e['user']);
        $this->assertIsArray($first);
        $this->assertNotSame('', (string) $first['data']);
        $this->assertNotSame('', (string) $first['filename']);
        $this->assertNull($io->take_export($e['id'], $e['user']), 'the export is deleted after the first download');
        $this->assertFalse(get_transient('dt_export_' . $e['id']));
    }

    // ------------------------------------------------------------------ I4

    private function legacy_import(array $post, string $file_name, string $content): string
    {
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        $tmp = tempnam(sys_get_temp_dir(), 'dtleg');
        file_put_contents($tmp, $content);
        $_FILES['import_file'] = array('name' => $file_name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content));
        $_POST = array_merge(array('import_tags' => '1', 'dt_import_nonce' => wp_create_nonce('dt_import_tags')), $post);
        $_REQUEST = $_POST; // check_admin_referer() reads $_REQUEST

        $prop = new ReflectionProperty(WP_Dynamic_Tags_Plugin::class, 'import_export');
        $prop->setAccessible(true);
        $prop->getValue(WP_Dynamic_Tags_Plugin::get_instance())->handle_import();

        ob_start();
        do_action('admin_notices');
        $html = (string) ob_get_clean();
        @unlink($tmp);
        unset($_FILES['import_file']);
        return $html;
    }

    public function test_the_old_import_page_does_not_need_conflict_resolution_in_the_request(): void
    {
        // A missing key used to raise an Undefined array key warning. This suite does not turn warnings into exceptions,
        // so the test collects them itself.
        $warnings = array();
        set_error_handler(static function ($no, $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            $this->legacy_import(array(), 'tags.json', wp_json_encode(array('tags' => array())));
        } finally {
            restore_error_handler();
        }
        $this->assertSame(array(), $warnings);
    }

    public function test_translated_notice_strings_of_the_old_import_page_are_escaped(): void
    {
        $unsupported = 'Unsupported file format. Please upload JSON or CSV files only.';
        add_filter('gettext', static fn($translated, $text) => $text === $unsupported ? '<img src=x onerror=alert(1)>' : $translated, 10, 2);

        $html = $this->legacy_import(array('conflict_resolution' => 'skip'), 'tags.txt', 'not a tag file');

        $this->assertStringContainsString('&lt;img', $html, 'the notice must be shown');
        $this->assertStringNotContainsString('<img', $html, 'a translation can contain markup; it must be escaped, not echoed');
    }
}
