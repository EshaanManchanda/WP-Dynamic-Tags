<?php
/**
 * A green run only means something if it ran on what its name says. This test is the proof the CI matrix needs: it fails when a job
 * called "network" ran as a single site (setting WP_MULTISITE alone changes nothing unless the bootstrap acts on it), or when a
 * job called "PHP 8.5, WordPress 7.0.8" ran on something else.
 *
 * The expectations come from the environment, so a local run without them checks only the network setting:
 *   WP_MULTISITE=1      the run must be a network;  anything else, a single site
 *   DT_EXPECT_PHP=8.5   PHP_MAJOR_VERSION.PHP_MINOR_VERSION
 *   DT_EXPECT_WP=7.0.8  the WordPress version under test (only an exact x.y.z is compared: "latest" has no fixed number)
 */
class DT_Rig_Test extends WP_UnitTestCase
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
        $this->assertGreaterThanOrEqual(80200, PHP_VERSION_ID, 'the plugin\'s declared minimum PHP');
        $this->assertTrue(version_compare(get_bloginfo('version'), '7.0', '>='), 'the plugin\'s declared minimum WordPress');
    }
}
