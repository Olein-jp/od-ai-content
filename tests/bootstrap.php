<?php
/**
 * PHPUnit bootstrap for WordPress integration tests.
 *
 * @package OdAiContent
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tests_dir ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR is required before WordPress loads.
	fwrite( STDERR, "WP_TESTS_DIR is not set.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/od-ai-content.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
