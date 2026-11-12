<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-table-import-export.php';

/**
 * The CSV a tag export is written as and read back from (plan Phase 7): an export read back must give the same text, and a
 * cell that starts with a character a spreadsheet runs as a formula must never leave the export unguarded.
 *
 * The WordPress-free pieces (guard_csv_cell, unguard_csv_cell, write_csv, read_csv) are checked against thousands of random
 * texts built from the characters that matter (quotes, commas, newlines, tabs, apostrophes, the formula starters). The input
 * is random but seeded, so a failure repeats; this is a property test, not coverage-guided fuzzing.
 */
class CsvTransportTest extends TestCase
{
    private const ALPHABET = array("'", '=', '+', '-', '@', "\t", "\r", "\n", '"', ',', ' ', 'a', 'Z', '0', '\\', 'é', '日', ';', '<', '{', '}');

    private function random_text(int $max_length): string
    {
        $text = '';
        for ($i = mt_rand(0, $max_length); $i > 0; $i--) {
            $text .= self::ALPHABET[mt_rand(0, count(self::ALPHABET) - 1)];
        }
        return $text;
    }

    public function test_guarding_a_cell_and_unguarding_it_gives_the_cell_back(): void
    {
        mt_srand(20261112);
        for ($i = 0; $i < 20000; $i++) {
            $cell = $this->random_text(12);
            $this->assertSame($cell, WP_Dynamic_Tags_Table_Import_Export::unguard_csv_cell(WP_Dynamic_Tags_Table_Import_Export::guard_csv_cell($cell)), 'cell ' . json_encode($cell));
        }
    }

    public function test_a_guarded_cell_never_starts_with_a_character_a_spreadsheet_runs(): void
    {
        mt_srand(20261113);
        for ($i = 0; $i < 20000; $i++) {
            $guarded = WP_Dynamic_Tags_Table_Import_Export::guard_csv_cell($this->random_text(12));
            $this->assertSame(0, preg_match('/^[=+\-@\t\r]/', $guarded), 'guarded ' . json_encode($guarded));
        }
    }

    public function test_the_guard_leaves_ordinary_text_alone(): void
    {
        foreach (array('', 'plain', 'hello = world', "'quoted", 'a-b', 'x@y.z', ' =leading space') as $cell) {
            $this->assertSame($cell, WP_Dynamic_Tags_Table_Import_Export::guard_csv_cell($cell), json_encode($cell));
        }
    }

    public function test_rows_written_as_csv_are_read_back_unchanged(): void
    {
        mt_srand(20261114);
        for ($i = 0; $i < 3000; $i++) {
            $rows = array();
            for ($r = mt_rand(1, 4); $r > 0; $r--) {
                $fields = array();
                for ($f = mt_rand(2, 6); $f > 0; $f--) { // at least two fields: a row of one empty field is a blank line
                    $fields[] = $this->random_text(10);
                }
                $rows[] = $fields;
            }
            $this->assertSame($rows, WP_Dynamic_Tags_Table_Import_Export::read_csv(WP_Dynamic_Tags_Table_Import_Export::write_csv($rows)), json_encode($rows));
        }
    }

    public function test_the_whole_path_of_an_export_read_back_gives_the_same_cells(): void
    {
        mt_srand(20261115);
        $guard = array('WP_Dynamic_Tags_Table_Import_Export', 'guard_csv_cell');
        $unguard = array('WP_Dynamic_Tags_Table_Import_Export', 'unguard_csv_cell');
        for ($i = 0; $i < 3000; $i++) {
            $cells = array();
            for ($f = mt_rand(2, 6); $f > 0; $f--) {
                $cells[] = $this->random_text(10);
            }
            $text = WP_Dynamic_Tags_Table_Import_Export::write_csv(array(array_map($guard, $cells)));
            $back = array_map($unguard, WP_Dynamic_Tags_Table_Import_Export::read_csv($text)[0]);
            $this->assertSame($cells, $back, json_encode($cells));
        }
    }

    public function test_known_inputs(): void
    {
        $this->assertSame("\xEF\xBB\xBFa,b\n", WP_Dynamic_Tags_Table_Import_Export::write_csv(array(array('a', 'b'))));
        $this->assertSame(
            array(array('Tag Name', 'Content'), array('c', "d\r\ne")),
            WP_Dynamic_Tags_Table_Import_Export::read_csv("\xEF\xBB\xBFTag Name,Content\r\n\r\nc,\"d\r\ne\"\r\n"),
            'a spreadsheet file: byte order mark, CRLF, a blank line, a quoted field that spans lines'
        );
        $this->assertSame(array(array('a\\"b', 'c')), WP_Dynamic_Tags_Table_Import_Export::read_csv('"a\\""b",c' . "\n"), 'a backslash is not an escape character');
        $this->assertSame(array(), WP_Dynamic_Tags_Table_Import_Export::read_csv(''));
    }
}
