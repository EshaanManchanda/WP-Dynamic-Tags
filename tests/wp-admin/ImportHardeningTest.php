<?php
/**
 * I2 / I3: the table import endpoints (dt_table_import, dt_table_validate_import) must check the upload before reading it,
 * sanitize their options, and refuse XML that declares a DOCTYPE (entity expansion).
 */
class DT_Import_Hardening_Test extends WP_Ajax_UnitTestCase
{
    /** @var string[] temp files to delete */
    private array $files = [];

    public function tear_down(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        unset($_FILES['import_file']);
        remove_all_filters('dt_import_max_bytes');
        parent::tear_down();
    }

    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dtimp');
        file_put_contents($tmp, $content);
        $this->files[] = $tmp;
        $_FILES['import_file'] = ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($content)];
    }

    /** @return array{die: ?string, json: mixed, raw: string, crash: ?string} */
    private function call(string $action, array $post): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_POST = array_merge(['nonce' => wp_create_nonce('dt_table_manager')], $post);
        $this->_last_response = '';
        $die = $crash = null;
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
            $die = $e->getMessage();
        } catch (Throwable $e) {
            $crash = get_class($e) . ': ' . $e->getMessage();
        }
        $raw = (string) $this->_last_response;
        return ['die' => $die, 'raw' => $raw, 'json' => json_decode($raw, true), 'crash' => $crash];
    }

    private function failed(array $r): bool
    {
        return $r['crash'] === null && is_array($r['json']) && ($r['json']['success'] ?? null) === false;
    }

    public function test_a_valid_json_import_still_works(): void
    {
        $this->upload('tags.json', json_encode(['tags' => [['tag_name' => 'Hardening ok', 'shortcode' => 'hard_ok', 'content' => 'hello']]]));
        $r = $this->call('dt_table_import', ['format' => 'json']);
        $this->assertNull($r['crash'], (string) $r['crash']);
        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertNotEmpty(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('hard_ok'));
    }

    public function test_a_failed_upload_is_reported_not_read(): void
    {
        $this->upload('tags.json', '', UPLOAD_ERR_PARTIAL);
        $r = $this->call('dt_table_import', ['format' => 'json']);
        $this->assertTrue($this->failed($r), 'a partial upload must produce an error response, got: ' . ($r['crash'] ?? $r['raw']));
    }

    public function test_a_missing_file_is_reported(): void
    {
        $r = $this->call('dt_table_import', ['format' => 'json']);
        $this->assertTrue($this->failed($r), $r['crash'] ?? $r['raw']);
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        add_filter('dt_import_max_bytes', static fn() => 50);
        $this->upload('big.json', json_encode(['tags' => [['tag_name' => 'Too big', 'shortcode' => 'hard_big', 'content' => str_repeat('x', 200)]]]));
        $r = $this->call('dt_table_import', ['format' => 'json']);
        $this->assertTrue($this->failed($r), $r['crash'] ?? $r['raw']);
        $this->assertEmpty(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('hard_big'));
    }

    public function test_an_unsupported_extension_is_refused(): void
    {
        $this->upload('shell.php', json_encode(['tags' => [['tag_name' => 'Sneaky', 'shortcode' => 'hard_sneaky', 'content' => 'x']]]));
        $r = $this->call('dt_table_import', ['format' => 'json']);
        $this->assertTrue($this->failed($r), $r['crash'] ?? $r['raw']);
        $this->assertEmpty(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('hard_sneaky'));
    }

    public function test_validate_only_does_not_import_even_if_the_options_ask_for_it(): void
    {
        $this->upload('tags.json', json_encode(['tags' => [['tag_name' => 'Dry run', 'shortcode' => 'hard_dry', 'content' => 'x']]]));
        $r = $this->call('dt_table_validate_import', ['format' => 'json', 'options' => ['validate_only' => '0', 'update_existing' => '1']]);
        $this->assertNull($r['crash'], (string) $r['crash']);
        $this->assertEmpty(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('hard_dry'));
    }

    public function test_xml_with_a_doctype_is_refused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE tags [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;">]>'
            . '<tags><tag><tag_name>Entity</tag_name><shortcode>hard_entity</shortcode><content>&b;</content></tag></tags>';
        $this->upload('tags.xml', $xml);
        $r = $this->call('dt_table_import', ['format' => 'xml']);
        $this->assertTrue($this->failed($r), $r['crash'] ?? $r['raw']);
        $this->assertEmpty(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('hard_entity'));
    }

    public function test_xml_without_a_doctype_imports(): void
    {
        $this->upload('tags.xml', '<?xml version="1.0"?><tags><tag><tag_name>Plain xml</tag_name><shortcode>hard_xml</shortcode><content>ok</content></tag></tags>');
        $r = $this->call('dt_table_import', ['format' => 'xml']);
        $this->assertNull($r['crash'], (string) $r['crash']);
        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertNotEmpty(WP_Dynamic_Tags_Table_Manager::get_instance()->get_tag_by_shortcode('hard_xml'));
    }
}
