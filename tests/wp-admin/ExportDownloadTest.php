<?php
/**
 * I1: the export file (every tag, including student data of the certificate tags) is kept in a transient so the browser
 * can fetch it with a second request. It used a uniqid() id (13 characters, derived from the clock), no owner and a
 * one hour lifetime, so any administrator who guessed the id could download another one's export later.
 *
 * (I4, the old import page's unescaped notices, went away with that page; the new screens' notices are covered by
 * AdminScreensTest.)
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

}
