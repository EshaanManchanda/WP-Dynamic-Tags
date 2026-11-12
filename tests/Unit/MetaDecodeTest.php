<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-database-manager.php';

/**
 * S9: stored meta_data must never be able to instantiate objects. The "gadget" cases use a class that records whether PHP
 * created or destroyed an instance, which is what an object-injection chain needs.
 */
class MetaDecodeTest extends TestCase
{
    public static bool $gadget_ran = false;

    protected function setUp(): void
    {
        self::$gadget_ran = false;
    }

    public function test_it_reads_a_serialized_array(): void
    {
        $this->assertSame(array('a' => 1, 'legacy' => array('post_id' => 5)), WP_Dynamic_Tags_Database_Manager::decode_meta(serialize(array('a' => 1, 'legacy' => array('post_id' => 5)))));
    }

    public function test_it_reads_json_objects_and_lists(): void
    {
        $this->assertSame(array('k' => 'v'), WP_Dynamic_Tags_Database_Manager::decode_meta('{"k":"v"}'));
        $this->assertSame(array(1, 2), WP_Dynamic_Tags_Database_Manager::decode_meta('[1,2]'));
    }

    public function test_an_array_passes_through_and_empty_stays_empty(): void
    {
        $this->assertSame(array('x'), WP_Dynamic_Tags_Database_Manager::decode_meta(array('x')));
        $this->assertSame('', WP_Dynamic_Tags_Database_Manager::decode_meta(''));
        $this->assertNull(WP_Dynamic_Tags_Database_Manager::decode_meta(null));
    }

    public function test_a_plain_string_is_returned_unchanged_like_maybe_unserialize_did(): void
    {
        $this->assertSame('just text', WP_Dynamic_Tags_Database_Manager::decode_meta('just text'));
    }

    public function test_a_serialized_object_is_not_instantiated(): void
    {
        $payload = serialize(new MetaDecodeGadget());
        self::$gadget_ran = false;
        $out = WP_Dynamic_Tags_Database_Manager::decode_meta($payload);
        $this->assertSame(array(), $out, 'objects decode to nothing');
        $this->assertFalse(self::$gadget_ran, 'neither __wakeup nor __destruct of the named class ran');
    }

    public function test_an_object_nested_in_an_array_is_not_instantiated(): void
    {
        $payload = serialize(array('ok' => 1, 'evil' => new MetaDecodeGadget()));
        self::$gadget_ran = false;
        $out = WP_Dynamic_Tags_Database_Manager::decode_meta($payload);
        $this->assertIsArray($out);
        $this->assertSame(1, $out['ok']);
        $this->assertFalse(is_object($out['evil'] ?? null) && !($out['evil'] instanceof __PHP_Incomplete_Class), 'nested objects become inert incomplete-class placeholders');
        $this->assertFalse(self::$gadget_ran);
    }

    public function test_corrupt_serialized_data_decodes_to_an_empty_array(): void
    {
        $this->assertSame(array(), WP_Dynamic_Tags_Database_Manager::decode_meta('a:2:{s:1:"a";i:1;'));
    }

    public function test_a_serialized_scalar_decodes_to_an_empty_array(): void
    {
        $this->assertSame(array(), WP_Dynamic_Tags_Database_Manager::decode_meta(serialize('text')));
        $this->assertSame(array(), WP_Dynamic_Tags_Database_Manager::decode_meta('b:0;'));
    }
}

/** An object-injection gadget: anything that runs when an instance is created or destroyed. */
class MetaDecodeGadget
{
    public function __wakeup(): void
    {
        MetaDecodeTest::$gadget_ran = true;
    }
}
