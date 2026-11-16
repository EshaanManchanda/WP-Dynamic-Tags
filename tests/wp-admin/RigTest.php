<?php
/**
 * The admin suite's counterpart of tests/wp/RigTest.php: a green run only means something if it ran on what its name says.
 * WP_MULTISITE=1 must make this run a network, DT_EXPECT_PHP / DT_EXPECT_WP (set by the CI matrix) must be what is really running.
 */
class DT_Admin_Rig_Test extends WP_UnitTestCase
{
    public function test_the_run_is_the_install_it_was_asked_to_be(): void
    {
        $this->assertSame(getenv('WP_MULTISITE') === '1', is_multisite(), 'WP_MULTISITE decides whether this run is a network');
    }

    public function test_the_run_is_on_the_php_and_wordpress_it_claims(): void
    {
        $php = (string) getenv('DT_EXPECT_PHP');
        if ($php !== '') {
            $this->assertSame($php, PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'the PHP this run was asked for');
        }
        $wp = (string) getenv('DT_EXPECT_WP');
        if (preg_match('/^\d+\.\d+\.\d+$/D', $wp)) {
            $this->assertSame($wp, get_bloginfo('version'), 'the WordPress this run was asked for');
        }
        $this->assertTrue(defined('WP_ADMIN') && WP_ADMIN, 'the admin suite is bootstrapped as an admin request');
    }
}
