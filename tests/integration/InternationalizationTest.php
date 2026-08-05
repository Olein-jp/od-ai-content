<?php
/**
 * Internationalization integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Plugin;

/**
 * Tests bundled translation metadata and catalogs.
 */
class InternationalizationTest extends WP_UnitTestCase {

	/**
	 * Plugin metadata declares the bundled language directory.
	 *
	 * @return void
	 */
	public function test_plugin_declares_language_directory() {
		$headers = get_file_data(
			OD_AI_CONTENT_FILE,
			array(
				'description' => 'Description',
				'domain'      => 'Text Domain',
				'domain_path' => 'Domain Path',
			)
		);

		$this->assertSame( 'od-ai-content', $headers['domain'] );
		$this->assertSame( '/languages', $headers['domain_path'] );
		$this->assertSame(
			'Delivers public WordPress content as structured Markdown for retrieval and reuse by external tools.',
			$headers['description']
		);
	}

	/**
	 * Bundled Japanese MO files translate plugin strings.
	 *
	 * @return void
	 */
	public function test_bundled_japanese_catalog_translates_strings() {
		unload_textdomain( 'od-ai-content' );

		$loaded = load_textdomain(
			'od-ai-content',
			OD_AI_CONTENT_DIR . 'languages/od-ai-content-ja.mo',
			'ja'
		);

		$this->assertTrue( $loaded );
		$this->assertSame(
			'Markdown出力',
			__( 'Markdown output', 'od-ai-content' )
		);

		unload_textdomain( 'od-ai-content' );
	}

	/**
	 * The plugin registers bundled translations on init.
	 *
	 * @return void
	 */
	public function test_plugin_registers_textdomain_loader_on_init() {
		$this->assertNotFalse(
			has_action( 'init', array( Plugin::class, 'load_textdomain' ) )
		);
	}
}
