<?php
/**
 * Moving tags between sites (plan Phase 7): what a file must look like to be accepted, what a spreadsheet may do with an
 * export, and that an export read back gives the same tags. Complements ImportHardeningTest (upload checks, DOCTYPE) and
 * ExportDownloadTest (the download id).
 *
 *  - CSV export neutralises cells a spreadsheet would run as a formula (OWASP CSV injection); the import undoes exactly that
 *  - a round trip through JSON, CSV and XML keeps every field, including newlines, quotes, backslashes and "<" in conditions
 *  - SQL is export only; a file with too many rows is refused (filter dt_import_max_rows, default 5000)
 *  - a file with some bad rows imports the good ones and reports each bad one by row number
 *  - malformed files of every format are refused without a PHP warning
 */
class DT_Import_Export_Test extends WP_Ajax_UnitTestCase
{
    /** @var string[] temp files to delete */
    private array $files = [];

    /** @var WP_Dynamic_Tags_Table_Import_Export */
    private $io;

    /** @var WP_Dynamic_Tags_Table_Manager */
    private $tags;

    public function set_up(): void
    {
        parent::set_up();
        $this->io = WP_Dynamic_Tags_Table_Import_Export::get_instance();
        $this->tags = WP_Dynamic_Tags_Table_Manager::get_instance();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        unset($_FILES['import_file']);
        remove_all_filters('dt_import_max_rows');
        parent::tear_down();
    }

    // ------------------------------------------------------------------ helpers

    private function upload(string $name, string $content): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dtimp');
        file_put_contents($tmp, $content);
        $this->files[] = $tmp;
        $_FILES['import_file'] = ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    /** @return array{raw: string, json: mixed, crash: ?string, warnings: string[]} */
    private function call(string $action, array $post): array
    {
        $_POST = array_merge(['nonce' => wp_create_nonce('dt_table_manager')], $post);
        $this->_last_response = '';
        $crash = null;
        $warnings = [];
        set_error_handler(static function ($no, $message) use (&$warnings) {
            // wp_send_json_*() tries to send headers after PHPUnit has already printed its banner: not the plugin's doing
            if (strpos($message, 'headers already sent') === false) {
                $warnings[] = $message;
            }
            return true;
        });
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieStopException | WPAjaxDieContinueException $e) {
            // expected: wp_send_json_* ends the request
        } catch (Throwable $e) {
            $crash = get_class($e) . ': ' . $e->getMessage();
        } finally {
            restore_error_handler();
        }
        $raw = (string) $this->_last_response;
        return ['raw' => $raw, 'json' => json_decode($raw, true), 'crash' => $crash, 'warnings' => $warnings];
    }

    private function failed(array $r): bool
    {
        return $r['crash'] === null && is_array($r['json']) && ($r['json']['success'] ?? null) === false;
    }

    private function insert_raw(array $fields): int
    {
        $id = WP_Dynamic_Tags_Database_Manager::get_instance()->insert_tag(array_merge(['content' => 'x'], $fields));
        $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        $this->tags->flush_cache();
        return $id;
    }

    /** The data rows of a CSV, keyed by the header, read the way RFC 4180 says (real newlines inside quotes). */
    private function csv_rows(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        $rows = [];
        while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $fields);
        }
        fclose($stream);
        return $rows;
    }

    private function delete_all_tags(): void
    {
        foreach ($this->tags->get_tags(['status' => '']) as $row) {
            $this->tags->delete_tag((int) $row->id);
        }
    }

    // ------------------------------------------------------------------ CSV formulas

    public function test_csv_export_neutralises_cells_a_spreadsheet_would_run_as_formulas(): void
    {
        $cases = [
            'f_eq'     => ['=HYPERLINK("http://example.com","x")', '\'=HYPERLINK("http://example.com","x")'],
            'f_plus'   => ['+cmd|x', "'+cmd|x"],
            'f_minus'  => ['-2+3', "'-2+3"],
            'f_at'     => ['@SUM(1)', "'@SUM(1)"],
            'f_tab'    => ["\t=x", "'\t=x"],
            'f_cr'     => ["\r=x", "'\r=x"],
            'f_quoted' => ["'=already", "''=already"],
            'f_plain'  => ['hello = world', 'hello = world'],
            'f_apos'   => ["'plain", "'plain"],
        ];
        foreach ($cases as $code => [$content]) {
            $this->insert_raw(['tag_name' => 'Cell ' . $code, 'shortcode' => $code, 'content' => $content, 'description' => $content]);
        }
        $this->insert_raw(['tag_name' => '=evil name', 'shortcode' => 'f_name', 'content' => 'x']);

        $export = $this->io->export_data('csv');
        $this->assertIsArray($export);
        $rows = [];
        foreach ($this->csv_rows($export['data']) as $row) {
            $rows[$row['Shortcode']] = $row;
        }

        foreach ($cases as $code => [, $expected]) {
            $this->assertSame($expected, $rows[$code]['Content'], $code . ': content cell');
            $this->assertSame($expected, $rows[$code]['Description'], $code . ': description cell');
        }
        $this->assertSame("'=evil name", $rows['f_name']['Tag Name'], 'the name column is guarded too');
        $this->assertSame('x', $rows['f_name']['Content']);
    }

    // ------------------------------------------------------------------ round trip

    /** @return array<string, array{string}> */
    public static function formats(): array
    {
        return ['json' => ['json'], 'csv' => ['csv'], 'xml' => ['xml']];
    }

    /** @dataProvider formats */
    public function test_an_export_read_back_gives_the_same_tags(string $format): void
    {
        $originals = [
            'rt_multi'   => "first line\nsecond line, with, commas\n\"quoted\" text",
            'rt_slash'   => 'path C:\\"x" and \\n stays two characters',
            'rt_cond'    => '{if post_id > 0 or a < b}yes{/if} <b>bold</b> & more',
            'rt_formula' => '=1+1',
            'rt_apos'    => "'=kept",
            'rt_unicode' => 'Ünï — 日本語 ✓',
            'rt_space'   => '  padded  ',
        ];
        $i = 0;
        foreach ($originals as $code => $content) {
            $id = $this->tags->create_tag([
                'tag_name' => 'Round ' . $code, 'shortcode' => $code, 'content' => $content, 'description' => 'd of ' . $code,
                'priority' => 7, 'status' => (++$i % 2) ? 'active' : 'inactive',
            ]);
            $this->assertIsInt($id, is_wp_error($id) ? $id->get_error_message() : '');
        }

        $export = $this->io->export_data($format);
        $this->assertIsArray($export, is_wp_error($export) ? $export->get_error_message() : '');
        $this->delete_all_tags();

        $result = $this->io->import_data($export['data'], $format, ['skip_duplicates' => false]);
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame(count($originals), $result['stats']['created'], wp_json_encode($result['stats']['error_details']));

        $i = 0;
        foreach ($originals as $code => $content) {
            $row = $this->tags->get_tag_by_shortcode($code);
            $this->assertNotEmpty($row, $code . ' must exist');
            $this->assertSame($content, $row->content, $code . ': content');
            $this->assertSame('d of ' . $code, $row->description, $code . ': description');
            $this->assertSame(7, (int) $row->priority);
            $this->assertSame((++$i % 2) ? 'active' : 'inactive', $row->status, $code . ': status');
        }
    }

    // ------------------------------------------------------------------ SQL, row limit

    public function test_sql_is_export_only(): void
    {
        $this->tags->create_tag(['tag_name' => 'Sql probe', 'shortcode' => 'sql_probe', 'content' => 'x']);
        $sql = $this->io->export_data('sql');
        $this->assertIsArray($sql);
        $this->assertStringContainsString('INSERT INTO', $sql['data']);

        $direct = $this->io->import_data($sql['data'], 'sql');
        $this->assertWPError($direct);
        $this->assertSame('unsupported_format', $direct->get_error_code());

        $this->delete_all_tags();
        $this->upload('tags.sql', $sql['data']);
        $r = $this->call('dt_table_import', ['format' => 'sql']);
        $this->assertTrue($this->failed($r), $r['crash'] ?? $r['raw']);
        $this->assertEmpty($this->tags->get_tag_by_shortcode('sql_probe'), 'an SQL file is never imported');
        $this->assertSame(['csv', 'json', 'xml'], $this->io->get_import_formats());
        $this->assertSame(['csv', 'json', 'sql', 'xml'], (function () {
            $f = $this->io->get_export_formats();
            sort($f);
            return $f;
        })());
    }

    public function test_a_file_with_more_rows_than_the_limit_is_refused_by_default(): void
    {
        $rows = array_fill(0, 5001, ['tag_name' => 'Row', 'shortcode' => 'row_x', 'content' => 'x']);
        $result = $this->io->import_data(wp_json_encode(['tags' => $rows]), 'json');
        $this->assertWPError($result);
        $this->assertSame('too_many_rows', $result->get_error_code());
        $this->assertStringContainsString('dt_import_max_rows', $result->get_error_message(), 'the message names the filter');
    }

    public function test_the_row_limit_can_be_changed_with_a_filter(): void
    {
        add_filter('dt_import_max_rows', static fn() => 2);
        $three = wp_json_encode(['tags' => [
            ['tag_name' => 'Cap one', 'shortcode' => 'cap_one'], ['tag_name' => 'Cap two', 'shortcode' => 'cap_two'], ['tag_name' => 'Cap three', 'shortcode' => 'cap_three'],
        ]]);
        $this->assertWPError($this->io->import_data($three, 'json'));
        $this->assertEmpty($this->tags->get_tag_by_shortcode('cap_one'), 'a refused file imports nothing');

        $two = wp_json_encode(['tags' => [['tag_name' => 'Cap one', 'shortcode' => 'cap_one'], ['tag_name' => 'Cap two', 'shortcode' => 'cap_two']]]);
        $result = $this->io->import_data($two, 'json');
        $this->assertIsArray($result);
        $this->assertSame(2, $result['stats']['created']);
    }

    // ------------------------------------------------------------------ per-row report

    public function test_a_partly_valid_file_imports_its_good_rows_and_reports_each_bad_one(): void
    {
        $file = wp_json_encode(['tags' => [
            ['tag_name' => 'Part good', 'shortcode' => 'part_good', 'content' => 'ok'],
            ['tag_name' => 'Part reserved', 'shortcode' => 'dt_group', 'content' => 'x'],
            ['tag_name' => 'Part status', 'shortcode' => 'part_status', 'content' => 'x', 'status' => 'weird'],
            'oops',
            ['tag_name' => 'Part nocode', 'content' => 'x'],
        ]]);

        $this->upload('tags.json', $file);
        $dry = $this->call('dt_table_validate_import', ['format' => 'json']);
        $this->assertTrue($dry['json']['success'] ?? false, $dry['raw']);
        $validation = $dry['json']['data']['validation'];
        $this->assertFalse($validation['valid']);
        $this->assertSame(5, $validation['total_rows']);
        $this->assertSame(1, $validation['valid_rows']);
        $this->assertSame(4, $validation['invalid_rows']);
        $this->assertSame(['Row 2', 'Row 3', 'Row 4', 'Row 5'], array_keys($validation['errors']));
        $this->assertStringContainsString('reserved', implode(' ', $validation['errors']['Row 2']));
        $this->assertEmpty($this->tags->get_tag_by_shortcode('part_good'), 'validating imports nothing');

        $this->upload('tags.json', $file);
        $run = $this->call('dt_table_import', ['format' => 'json']);
        $this->assertTrue($run['json']['success'] ?? false, $run['raw']);
        $stats = $run['json']['data']['stats'];
        $this->assertSame(1, $stats['created']);
        $this->assertSame(4, $stats['errors']);
        $this->assertSame([2, 3, 4, 5], array_column($stats['error_details'], 'row'));
        foreach ($stats['error_details'] as $detail) {
            $this->assertArrayNotHasKey('data', $detail, 'the report names the row and the problem, it does not echo the row back');
            $this->assertNotSame('', (string) $detail['error']);
        }
        $this->assertNotEmpty($this->tags->get_tag_by_shortcode('part_good'));
        $this->assertEmpty($this->tags->get_tag_by_shortcode('part_status'));
    }

    public function test_a_name_already_used_by_another_tag_is_a_row_error_in_the_dry_run(): void
    {
        $this->tags->create_tag(['tag_name' => 'Dup name', 'shortcode' => 'dup_a', 'content' => 'x']);
        $result = $this->io->import_data(wp_json_encode(['tags' => [['tag_name' => 'Dup name', 'shortcode' => 'dup_b', 'content' => 'y']]]), 'json', ['validate_only' => true]);
        $this->assertIsArray($result);
        $this->assertFalse($result['validation']['valid']);
        $this->assertArrayHasKey('Row 1', $result['validation']['errors']);
    }

    public function test_a_field_that_is_a_list_or_an_object_is_a_row_error(): void
    {
        $file = wp_json_encode(['tags' => [
            ['tag_name' => ['x'], 'shortcode' => 'arr_name', 'content' => 'x'],
            ['tag_name' => 'Arr code', 'shortcode' => ['y'], 'content' => 'x'],
            ['tag_name' => 'Arr content', 'shortcode' => 'arr_content', 'content' => ['z' => 1]],
        ]]);
        $result = $this->io->import_data($file, 'json');
        $this->assertSame(0, $result['stats']['created']);
        $this->assertSame(3, $result['stats']['errors']);
        foreach ($result['stats']['error_details'] as $detail) {
            $this->assertStringContainsString('must be text or a number', $detail['error']);
        }
        $this->assertEmpty($this->tags->get_tag_by_shortcode('arr_name'), 'a list is not turned into the name "Array"');
    }

    public function test_the_export_order_is_fixed_whatever_the_request_asks_for(): void
    {
        foreach (['c' => 'Zulu', 'b' => 'Mike', 'a' => 'Alpha'] as $code => $name) { // ids rise while the names fall
            $this->tags->create_tag(['tag_name' => $name, 'shortcode' => 'ord_' . $code, 'content' => 'x']);
        }
        $export = $this->io->export_data('json', ['orderby' => 'tag_name', 'order' => 'DESC', 'status' => "active'; DROP TABLE x; --"]);
        $this->assertIsArray($export, is_wp_error($export) ? $export->get_error_message() : '');
        $this->assertSame(['ord_c', 'ord_b', 'ord_a'], array_column(json_decode($export['data'], true)['tags'], 'shortcode'));
    }

    public function test_the_sample_files_that_ship_with_the_plugin_import_cleanly(): void
    {
        foreach (['sample.csv', 'sample-full.csv'] as $file) {
            $this->delete_all_tags();
            $csv = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/' . $file);
            $this->assertNotSame('', $csv, $file);
            $result = $this->io->import_data($csv, 'csv');
            $this->assertIsArray($result, $file . ': ' . (is_wp_error($result) ? $result->get_error_message() : ''));
            $this->assertSame(0, $result['stats']['errors'], $file . ': ' . wp_json_encode($result['stats']['error_details']));
            $this->assertGreaterThanOrEqual(5, $result['stats']['created'], $file);
        }
        $multi = $this->tags->get_tag_by_shortcode('multi_line_notice');
        $this->assertSame("Line one\nLine two\nLine three", str_replace("\r\n", "\n", $multi->content), 'a value that spans lines survives');
        $this->assertSame('inactive', $this->tags->get_tag_by_shortcode('holiday_banner')->status);
    }

    // ------------------------------------------------------------------ cost of a big file

    /**
     * Every created tag used to make the Tag Service load and scan every stored tag for circular references, so an import took
     * time with the square of its rows (measured: 500 rows 2.6 s, 1,000 rows 10 s, 2,000 rows 49 s). A batch keeps the
     * reference graph and updates it as tags are created.
     */
    public function test_a_big_import_does_not_reload_every_tag_for_every_row(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = ['tag_name' => 'Bulk ' . $i, 'shortcode' => 'bulk_' . $i, 'content' => 'row ' . $i . ($i ? ' [bulk_' . ($i - 1) . ']' : '')];
        }
        $full_loads = 0;
        $count = static function ($sql) use (&$full_loads) {
            if (preg_match('/^\s*SELECT \* FROM \S+\s+ORDER BY/i', (string) $sql)) {
                $full_loads++;
            }
            return $sql;
        };
        add_filter('query', $count);
        $result = $this->io->import_data(wp_json_encode(['tags' => $rows]), 'json');
        remove_filter('query', $count);

        $this->assertSame(60, $result['stats']['created'], wp_json_encode($result['stats']['error_details']));
        $this->assertLessThanOrEqual(3, $full_loads, 'the whole table was loaded ' . $full_loads . ' times for 60 rows');
    }

    public function test_the_circular_reference_check_still_sees_tags_created_earlier_in_the_same_import(): void
    {
        $file = wp_json_encode(['tags' => [
            ['tag_name' => 'Cycle a', 'shortcode' => 'cyc_a', 'content' => 'see [cyc_b]'],
            ['tag_name' => 'Cycle b', 'shortcode' => 'cyc_b', 'content' => 'see [cyc_a]'],
        ]]);
        $result = $this->io->import_data($file, 'json');
        $this->assertSame(1, $result['stats']['created']);
        $this->assertSame(1, $result['stats']['errors']);
        $this->assertSame(2, $result['stats']['error_details'][0]['row']);
        $this->assertStringContainsString('render itself', $result['stats']['error_details'][0]['error']);
        $this->assertEmpty($this->tags->get_tag_by_shortcode('cyc_b'));

        // and outside an import the check is as strict as before
        $direct = $this->tags->create_tag(['tag_name' => 'Cycle c', 'shortcode' => 'cyc_c', 'content' => 'see [cyc_a] and [cyc_c]']);
        $this->assertWPError($direct);
    }

    // ------------------------------------------------------------------ malformed files

    /** @return array<string, array{string, string, string}> file name, content, a fragment of the reason given */
    public static function malformed_files(): array
    {
        $doctype = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE t [<!ENTITY a "aaaa">]><tags><tag><tag_name>U</tag_name><shortcode>mal_u16</shortcode><content>&a;</content></tag></tags>';
        return [
            'json truncated'       => ['a.json', '{"tags": [', 'Invalid JSON'],
            'json scalar'          => ['a.json', '123', 'must hold a list'],
            'json tags not a list' => ['a.json', '{"tags":"x"}', 'must be a list'],
            'json empty file'      => ['a.json', '', 'Invalid JSON'],
            'json no tags'         => ['a.json', '{"tags":[]}', 'no tags'],
            'xml truncated'        => ['a.xml', '<tags><tag>', 'XML parsing failed'],
            'xml no tags'          => ['a.xml', '<?xml version="1.0"?><tags/>', 'no tags'],
            'xml utf-16 with bom'  => ['a.xml', "\xFF\xFE" . mb_convert_encoding($doctype, 'UTF-16LE', 'UTF-8'), 'UTF-8'],
            'xml utf-16 no bom'    => ['a.xml', mb_convert_encoding($doctype, 'UTF-16LE', 'UTF-8'), 'UTF-8'],
            'xml other encoding'   => ['a.xml', '<?xml version="1.0" encoding="ISO-8859-1"?><tags><tag><tag_name>L</tag_name><shortcode>mal_l1</shortcode></tag></tags>', 'UTF-8'],
            'csv empty'            => ['a.csv', '', 'empty'],
            'csv header only'      => ['a.csv', "Tag Name,Shortcode,Content\n", 'no tags'],
            'csv wrong header'     => ['a.csv', "foo,bar\n1,2\n", 'header'],
        ];
    }

    /** @dataProvider malformed_files */
    public function test_a_malformed_file_is_refused_without_a_crash_or_a_warning(string $name, string $content, string $reason): void
    {
        $this->upload($name, $content);
        $r = $this->call('dt_table_import', ['format' => pathinfo($name, PATHINFO_EXTENSION)]);
        $this->assertNull($r['crash'], (string) $r['crash']);
        $this->assertSame([], $r['warnings'], 'no PHP warning: ' . implode(' | ', $r['warnings']));
        $this->assertTrue($this->failed($r), 'must be refused, got: ' . $r['raw']);
        $this->assertStringContainsString($reason, (string) ($r['json']['data'] ?? ''), 'and say why');
        $this->assertEmpty($this->tags->get_tag_by_shortcode('mal_u16'));
        $this->assertEmpty($this->tags->get_tag_by_shortcode('mal_l1'));
    }

    public function test_importing_xml_leaves_libxml_error_handling_as_it_was(): void
    {
        $before = libxml_use_internal_errors(false);
        $this->io->import_data('<?xml version="1.0"?><tags><tag><tag_name>Lib</tag_name><shortcode>lib_xml</shortcode></tag></tags>', 'xml');
        $after = libxml_use_internal_errors($before);
        $this->assertFalse($after, 'another plugin that expects libxml to throw warnings must not be switched to internal errors');
    }

    // ------------------------------------------------------------------ spreadsheet files, groups, options

    public function test_a_csv_saved_by_a_spreadsheet_imports(): void
    {
        $csv = "\xEF\xBB\xBF" . "Tag Name,Shortcode,Content,Notes,Status\r\n"
            . "Sheet one,sheet_one,\"line one\r\nline two\",ignored,active\r\n"
            . "\r\n"
            . "Sheet two,sheet_two,plain,ignored,inactive\r\n"
            . "Sheet bad,sheet_bad\r\n";
        $result = $this->io->import_data($csv, 'csv');
        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame(2, $result['stats']['created']);
        $this->assertSame(1, $result['stats']['errors'], 'the row with the wrong number of columns is reported, not dropped silently');
        $this->assertSame(3, $result['stats']['error_details'][0]['row']);
        $this->assertSame("line one\r\nline two", $this->tags->get_tag_by_shortcode('sheet_one')->content);
        $this->assertSame('inactive', $this->tags->get_tag_by_shortcode('sheet_two')->status);
    }

    public function test_a_group_that_does_not_exist_here_is_dropped_with_a_warning(): void
    {
        $term = wp_insert_term('Import group', 'tag_groups');
        $this->assertIsArray($term, is_wp_error($term) ? $term->get_error_message() : '');
        $file = wp_json_encode(['tags' => [
            ['tag_name' => 'Group here', 'shortcode' => 'grp_here', 'content' => 'x', 'category_id' => $term['term_id']],
            ['tag_name' => 'Group gone', 'shortcode' => 'grp_gone', 'content' => 'x', 'category_id' => 999999],
        ]]);

        $dry = $this->io->import_data($file, 'json', ['validate_only' => true]);
        $this->assertStringContainsString('Row 2', implode(' ', $dry['validation']['warnings']));

        $this->io->import_data($file, 'json');
        $this->assertSame((int) $term['term_id'], (int) $this->tags->get_tag_by_shortcode('grp_here')->category_id);
        $this->assertSame(0, (int) $this->tags->get_tag_by_shortcode('grp_gone')->category_id, 'an id from another site must not point at an unrelated term');
    }

    public function test_an_update_keeps_what_the_file_does_not_say(): void
    {
        $term = wp_insert_term('Kept group', 'tag_groups');
        $this->assertIsArray($term, is_wp_error($term) ? $term->get_error_message() : '');
        $this->insert_raw([
            'tag_name' => 'Keep me', 'shortcode' => 'keep_me', 'content' => 'old', 'usage_count' => 9,
            'category_id' => (int) $term['term_id'], 'meta_data' => ['legacy' => ['aliases' => ['old_alias']]],
        ]);

        $file = wp_json_encode(['tags' => [['tag_name' => 'Keep me', 'shortcode' => 'keep_me', 'content' => 'new', 'usage_count' => 1]]]);
        $result = $this->io->import_data($file, 'json', ['update_existing' => true]);
        $this->assertSame(1, $result['stats']['updated'], wp_json_encode($result['stats']['error_details']));

        $row = $this->tags->get_tag_by_shortcode('keep_me');
        $this->assertSame('new', $row->content);
        $this->assertSame(9, (int) $row->usage_count, 'the usage counter of this site is not replaced by the file\'s');
        $this->assertSame((int) $term['term_id'], (int) $row->category_id, 'a file without a group does not remove the tag from its group');
        $this->assertSame(['old_alias'], WP_Dynamic_Tags_Database_Manager::decode_meta($row->meta_data)['legacy']['aliases'], 'nor does it wipe the meta data (legacy aliases)');
    }

    public function test_update_existing_is_honoured_even_when_skip_duplicates_is_ticked_too(): void
    {
        $this->tags->create_tag(['tag_name' => 'Upd me', 'shortcode' => 'upd_me', 'content' => 'old']);
        $file = wp_json_encode(['tags' => [['tag_name' => 'Upd me', 'shortcode' => 'upd_me', 'content' => 'new']]]);

        $skipped = $this->io->import_data($file, 'json', ['skip_duplicates' => true, 'update_existing' => false]);
        $this->assertSame(1, $skipped['stats']['skipped']);
        $this->assertSame('old', $this->tags->get_tag_by_shortcode('upd_me')->content);

        // the page's two checkboxes: "skip duplicates" is ticked by default, and the user also ticks "update existing"
        $updated = $this->io->import_data($file, 'json', ['skip_duplicates' => true, 'update_existing' => true]);
        $this->assertSame(1, $updated['stats']['updated']);
        $this->assertSame('new', $this->tags->get_tag_by_shortcode('upd_me')->content);
    }
}
