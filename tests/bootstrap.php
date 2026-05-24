<?php
/**
 * PHPUnit bootstrap file for the Minutes plugin tests.
 *
 * Uses wp-phpunit/wp-phpunit (installed via Composer) as the test library.
 * WordPress core must be downloaded to WP_CORE_DIR (default: /tmp/wordpress).
 */

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$bmlt_minutes_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( "{$bmlt_minutes_tests_dir}/includes/functions.php" ) ) {
	echo 'Could not find wp-phpunit. Run: composer install' . PHP_EOL;
	exit( 1 );
}

require_once "{$bmlt_minutes_tests_dir}/includes/functions.php";

function bmlt_minutes_tests_manually_load_plugin() {
	require dirname( __DIR__ ) . '/minutes.php';
	BMLT_Minutes::activate();
}
tests_add_filter( 'muplugins_loaded', 'bmlt_minutes_tests_manually_load_plugin' );

require "{$bmlt_minutes_tests_dir}/includes/bootstrap.php";
