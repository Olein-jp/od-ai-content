<?php
/**
 * GitHub updater integration tests.
 *
 * @package OdAiContent
 */

use Inc2734\WP_GitHub_Plugin_Updater\Bootstrap;
use Olein\OdAiContent\GitHub_Updater;

/**
 * Tests production autoloading and updater initialization.
 */
class GitHubUpdaterTest extends WP_UnitTestCase {

	/**
	 * The production updater is available through Composer autoloading.
	 *
	 * @return void
	 */
	public function test_updater_dependency_is_autoloaded() {
		$this->assertTrue( class_exists( Bootstrap::class ) );
	}

	/**
	 * The updater receives the expected plugin and repository identifiers.
	 *
	 * @return void
	 */
	public function test_updater_uses_expected_repository_identifiers() {
		$received = array();
		$updater  = new GitHub_Updater(
			static function ( $plugin_file, $owner, $repository ) use ( &$received ) {
				$received = array( $plugin_file, $owner, $repository );
			}
		);

		$this->assertTrue( $updater->register() );
		$this->assertSame(
			array(
				plugin_basename( OD_AI_CONTENT_FILE ),
				'Olein-jp',
				'od-ai-content',
			),
			$received
		);
	}

	/**
	 * Release tags are compared with the installed plugin version.
	 *
	 * @return void
	 */
	public function test_release_tag_is_compared_with_plugin_version() {
		$environment = array(
			'wp_version'  => '6.9',
			'php_version' => '7.4',
		);
		$current     = array(
			'version'      => OD_AI_CONTENT_VERSION,
			'requires_wp'  => '6.9',
			'requires_php' => '7.4',
		);
		$release     = array(
			'version'      => '99.0.0',
			'requires_wp'  => '6.9',
			'requires_php' => '7.4',
		);

		$this->assertTrue( Bootstrap::should_update( $environment, $current, $release ) );

		$release['version'] = OD_AI_CONTENT_VERSION;

		$this->assertFalse( Bootstrap::should_update( $environment, $current, $release ) );
	}

	/**
	 * Plugin bootstrap registers the upstream update-check filter.
	 *
	 * @return void
	 */
	public function test_plugin_bootstrap_registers_upstream_update_filter() {
		global $wp_filter;

		$registered = false;
		$callbacks  = $wp_filter['pre_set_site_transient_update_plugins']->callbacks;

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				if (
					is_array( $callback['function'] )
					&& $callback['function'][0] instanceof Bootstrap
				) {
					$registered = true;
					break 2;
				}
			}
		}

		$this->assertTrue( $registered );
	}

	/**
	 * Updater initialization failures do not remove existing Markdown hooks.
	 *
	 * @return void
	 */
	public function test_updater_failure_does_not_break_markdown_delivery_hooks() {
		$updater = new GitHub_Updater(
			static function () {
				throw new RuntimeException( 'Updater unavailable.' );
			}
		);

		$this->assertFalse( $updater->register() );
		$this->assertNotFalse( has_action( 'template_redirect' ) );
	}
}
