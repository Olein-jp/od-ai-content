<?php
/**
 * Markdown diagnostics integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Admin_Diagnostics;
use Olein\OdAiContent\Block_Converter;
use Olein\OdAiContent\Content_Resolver;
use Olein\OdAiContent\Diagnostic_Queue;
use Olein\OdAiContent\Diagnostics;
use Olein\OdAiContent\Diagnostics_REST_Controller;
use Olein\OdAiContent\Html_To_Markdown;
use Olein\OdAiContent\Markdown_Document;
use Olein\OdAiContent\Markdown_Url;
use Olein\OdAiContent\Post_Exclusion;
use Olein\OdAiContent\Settings;

/**
 * Tests deterministic diagnostics, stored statuses, and secured previews.
 */
class DiagnosticsTest extends WP_UnitTestCase {

	/**
	 * Diagnostics service.
	 *
	 * @var Diagnostics
	 */
	private $diagnostics;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Set up diagnostics with production converters.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->settings    = new Settings();
		$this->diagnostics = new Diagnostics(
			new Markdown_Document( new Block_Converter( new Html_To_Markdown() ) ),
			$this->settings
		);
	}

	/**
	 * Clean persistent and global state after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		delete_option( Diagnostic_Queue::OPTION_NAME );
		delete_option( Diagnostic_Queue::LOCK_OPTION_NAME );
		wp_clear_scheduled_hook( Diagnostic_Queue::CRON_HOOK );
		wp_set_current_user( 0 );
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * A standard document is normal and its compact result is stored.
	 *
	 * @return void
	 */
	public function test_normal_document_is_diagnosed_and_stored() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Section</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Useful content.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'Diagnostic title',
			)
		);

		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		$stored = $this->diagnostics->get_stored_result( $post_id );

		$this->assertSame( 'normal', $result['status'] );
		$this->assertStringContainsString( '# Diagnostic title', $result['markdown'] );
		$this->assertIsArray( $stored );
		$this->assertSame( 'normal', $stored['status'] );
		$this->assertArrayNotHasKey( 'markdown', $stored );
		$this->assertSame( 'normal', $this->diagnostics->get_status( $post_id ) );
	}

	/**
	 * Excluded blocks are informational and unknown blocks produce a warning.
	 *
	 * @return void
	 */
	public function test_conversion_report_identifies_exclusions_and_fallbacks() {
		$content = <<<'BLOCKS'
<!-- wp:paragraph -->
<p>Primary content.</p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"20px"} -->
<div style="height:20px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:example/card -->
<section><p>Custom card.</p></section>
<!-- /wp:example/card -->
BLOCKS;
		$post_id = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_title'   => 'Conversion report',
			)
		);

		$result = $this->diagnostics->diagnose( get_post( $post_id ) );

		$this->assertSame( 'warning', $result['status'] );
		$this->assertSame( array( 'core/spacer' ), $result['excluded_blocks'] );
		$this->assertSame( array( 'example/card' ), $result['fallback_blocks'] );
	}

	/**
	 * Missing body content is a diagnostic error.
	 *
	 * @return void
	 */
	public function test_empty_body_is_an_error() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Empty body',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '',
			)
		);

		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		$codes  = wp_list_pluck( $result['checks'], 'code' );

		$this->assertSame( 'error', $result['status'] );
		$this->assertContains( 'body_empty', $codes );
	}

	/**
	 * H1 headings inside supported code fences do not count as document H1s.
	 *
	 * @return void
	 */
	public function test_h1_ignores_headings_inside_fenced_code_blocks() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'Fenced H1 test',
			)
		);
		$filter  = static function ( $markdown ) {
			return $markdown
				. "\n```\n# Three-backtick H1\n###### Three-backtick H6\n```\n"
				. "\n````\n```\n# Four-backtick H1\n###### Four-backtick H6\n````\n"
				. "\n~~~markdown\n# Tilde H1\n###### Tilde H6\n~~~\n";
		};

		add_filter( 'od_ai_content_markdown_document', $filter );
		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		remove_filter( 'od_ai_content_markdown_document', $filter );

		$codes = wp_list_pluck( $result['checks'], 'code' );

		$this->assertSame( 'normal', $result['status'] );
		$this->assertContains( 'h1_valid', $codes );
		$this->assertNotContains( 'h1_multiple', $codes );
		$this->assertNotContains( 'heading_hierarchy_jump', $codes );
	}

	/**
	 * H1 headings outside code fences still produce existing errors.
	 *
	 * @return void
	 */
	public function test_h1_outside_fenced_code_retains_existing_validation() {
		$post_id            = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'Document title',
			)
		);
		$multiple_h1_filter = static function ( $markdown ) {
			return $markdown . "\n# Second H1\n";
		};

		add_filter( 'od_ai_content_markdown_document', $multiple_h1_filter );
		$multiple_h1_result = $this->diagnostics->diagnose( get_post( $post_id ) );
		remove_filter( 'od_ai_content_markdown_document', $multiple_h1_filter );

		$mismatched_h1_filter = static function ( $markdown ) {
			return str_replace( '# Document title', '# Different title', $markdown );
		};

		add_filter( 'od_ai_content_markdown_document', $mismatched_h1_filter );
		$mismatched_h1_result = $this->diagnostics->diagnose( get_post( $post_id ) );
		remove_filter( 'od_ai_content_markdown_document', $mismatched_h1_filter );

		$this->assertContains( 'h1_multiple', wp_list_pluck( $multiple_h1_result['checks'], 'code' ) );
		$this->assertContains( 'h1_title_mismatch', wp_list_pluck( $mismatched_h1_result['checks'], 'code' ) );
	}

	/**
	 * H1 diagnosis reuses the title fixed during Markdown generation.
	 *
	 * @return void
	 */
	public function test_h1_diagnosis_does_not_reevaluate_filtered_post_title() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>本文です。</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => '元のタイトル',
			)
		);
		$calls   = 0;
		$filter  = static function ( $title ) use ( &$calls ) {
			++$calls;

			return 2 >= $calls ? 'llms.txtとは？日本語の診断タイトル' : $title . '（診断時に再評価）';
		};

		add_filter( 'the_title', $filter );
		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		remove_filter( 'the_title', $filter );

		$codes = wp_list_pluck( $result['checks'], 'code' );

		$this->assertSame( 1, $calls );
		$this->assertSame( 'normal', $result['status'] );
		$this->assertStringContainsString( '# llms.txtとは？日本語の診断タイトル', $result['markdown'] );
		$this->assertContains( 'h1_valid', $codes );
		$this->assertNotContains( 'h1_title_mismatch', $codes );
	}

	/**
	 * Semantically equivalent title markup and entities compare equally.
	 *
	 * @return void
	 */
	public function test_h1_title_comparison_normalizes_markup_and_entities() {
		$post_id         = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>本文です。</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => '元のタイトル',
			)
		);
		$title_filter    = static function () {
			return '日本語 &amp; <em>WordPress</em>';
		};
		$document_filter = static function ( $markdown ) {
			return str_replace(
				'# 日本語 &amp; <em>WordPress</em>',
				'# 日本語 & WordPress',
				$markdown
			);
		};

		add_filter( 'the_title', $title_filter );
		add_filter( 'od_ai_content_markdown_document', $document_filter );
		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		remove_filter( 'od_ai_content_markdown_document', $document_filter );
		remove_filter( 'the_title', $title_filter );

		$codes = wp_list_pluck( $result['checks'], 'code' );

		$this->assertSame( 'normal', $result['status'] );
		$this->assertStringContainsString( '# 日本語 & WordPress', $result['markdown'] );
		$this->assertContains( 'h1_valid', $codes );
		$this->assertNotContains( 'h1_title_mismatch', $codes );
	}

	/**
	 * Stored results from the previous diagnostic version are stale.
	 *
	 * @return void
	 */
	public function test_previous_diagnostic_result_version_is_stale() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$post    = get_post( $post_id );

		update_post_meta(
			$post_id,
			Diagnostics::META_KEY,
			array(
				'version'           => 1,
				'document_schema'   => Markdown_Document::SCHEMA_VERSION,
				'post_modified_gmt' => $post->post_modified_gmt,
				'status'            => 'normal',
				'checks'            => array(),
			)
		);

		$this->assertSame( 2, Diagnostics::RESULT_VERSION );
		$this->assertNull( $this->diagnostics->get_stored_result( $post ) );
		$this->assertSame( 'not_diagnosed', $this->diagnostics->get_status( $post ) );
	}

	/**
	 * A fenced code block remains valid body content.
	 *
	 * @return void
	 */
	public function test_fenced_code_block_counts_as_body_content() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:code --><pre class="wp-block-code"><code>echo &quot;Body&quot;;</code></pre><!-- /wp:code -->',
				'post_status'  => 'publish',
				'post_title'   => 'Code body test',
			)
		);

		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		$codes  = wp_list_pluck( $result['checks'], 'code' );

		$this->assertSame( 'normal', $result['status'] );
		$this->assertContains( 'body_present', $codes );
		$this->assertNotContains( 'body_empty', $codes );
	}

	/**
	 * Heading level jumps produce a warning.
	 *
	 * @return void
	 */
	public function test_heading_level_jump_is_a_warning() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Skipped level</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'Heading test',
			)
		);

		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		$codes  = wp_list_pluck( $result['checks'], 'code' );

		$this->assertSame( 'warning', $result['status'] );
		$this->assertContains( 'heading_hierarchy_jump', $codes );
	}

	/**
	 * Headings inside three- and four-backtick code fences are ignored.
	 *
	 * @return void
	 */
	public function test_heading_level_jump_ignores_fenced_code_blocks() {
		$content = <<<'BLOCKS'
<!-- wp:heading -->
<h2 class="wp-block-heading">Section</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Subsection</h3>
<!-- /wp:heading -->

<!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">First child</h4>
<!-- /wp:heading -->

<!-- wp:code -->
<pre class="wp-block-code"><code>## Example in a three-backtick fence</code></pre>
<!-- /wp:code -->

<!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Second child</h4>
<!-- /wp:heading -->

<!-- wp:code -->
<pre class="wp-block-code"><code>```
## Example in a four-backtick fence</code></pre>
<!-- /wp:code -->

<!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Third child</h4>
<!-- /wp:heading -->
BLOCKS;
		$post_id = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_title'   => 'Fenced code heading test',
			)
		);

		$result = $this->diagnostics->diagnose( get_post( $post_id ) );
		$codes  = wp_list_pluck( $result['checks'], 'code' );

		$this->assertStringContainsString( "```\n## Example in a three-backtick fence\n```", $result['markdown'] );
		$this->assertStringContainsString( "````\n```\n## Example in a four-backtick fence\n````", $result['markdown'] );
		$this->assertSame( 'normal', $result['status'] );
		$this->assertNotContains( 'heading_hierarchy_jump', $codes );
	}

	/**
	 * Excluded posts display the excluded status.
	 *
	 * @return void
	 */
	public function test_excluded_post_has_excluded_status() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $post_id, Post_Exclusion::META_KEY, '1' );
		$result = $this->diagnostics->diagnose( get_post( $post_id ) );

		$this->assertSame( 'excluded', $result['status'] );
		$this->assertSame( 'excluded', $this->diagnostics->get_status( $post_id ) );
	}

	/**
	 * Updating a post invalidates its stored diagnostic result.
	 *
	 * @return void
	 */
	public function test_post_update_invalidates_stored_result() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Original.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);

		$this->diagnostics->diagnose( get_post( $post_id ) );
		$this->assertIsArray( $this->diagnostics->get_stored_result( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>Updated.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNull( $this->diagnostics->get_stored_result( $post_id ) );
		$this->assertSame( 'not_diagnosed', $this->diagnostics->get_status( $post_id ) );
	}

	/**
	 * A completed block editor REST save stores a fresh diagnostic result.
	 */
	public function test_rest_post_save_refreshes_stored_result() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Original.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$controller    = new Diagnostics_REST_Controller(
			$this->diagnostics,
			$this->settings,
			new Content_Resolver( $this->settings ),
			new Markdown_Url()
		);
		$request       = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );

		$controller->register_hooks();
		wp_set_current_user( $administrator );

		$request->set_body_params(
			array(
				'content' => '<!-- wp:paragraph --><p>Updated through REST.</p><!-- /wp:paragraph -->',
			)
		);

		$response = rest_do_request( $request );
		$stored   = $this->diagnostics->get_stored_result( $post_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $stored );
		$this->assertSame( 'normal', $stored['status'] );
		$this->assertSame( 'normal', $this->diagnostics->get_status( $post_id ) );
	}

	/**
	 * Editor bookkeeping meta does not discard a fresh diagnostic result.
	 *
	 * @return void
	 */
	public function test_editor_bookkeeping_meta_does_not_invalidate_result() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);

		$this->diagnostics->diagnose( get_post( $post_id ) );
		update_post_meta( $post_id, '_edit_lock', time() . ':1' );

		$this->assertIsArray( $this->diagnostics->get_stored_result( $post_id ) );
		$this->assertSame( 'normal', $this->diagnostics->get_status( $post_id ) );
	}

	/**
	 * The list table column reads stored status without regenerating content.
	 *
	 * @return void
	 */
	public function test_admin_column_displays_stored_status() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$admin   = new Admin_Diagnostics( $this->settings, $this->diagnostics );
		$columns = $admin->add_column( array( 'title' => 'Title' ) );

		$this->assertArrayHasKey( Admin_Diagnostics::COLUMN_KEY, $columns );

		ob_start();
		$admin->render_column( Admin_Diagnostics::COLUMN_KEY, $post_id );
		$before = ob_get_clean();

		$this->diagnostics->diagnose( get_post( $post_id ) );

		ob_start();
		$admin->render_column( Admin_Diagnostics::COLUMN_KEY, $post_id );
		$after = ob_get_clean();

		$this->assertStringContainsString( 'not_diagnosed', $before );
		$this->assertStringContainsString( 'index.html.md', $before );
		$this->assertStringContainsString( 'normal', $after );
		$this->assertStringContainsString( 'View Markdown', $after );
	}

	/**
	 * The list table omits Markdown links for ineligible content.
	 *
	 * @return void
	 */
	public function test_admin_column_hides_markdown_link_for_ineligible_content() {
		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
			)
		);
		$admin    = new Admin_Diagnostics( $this->settings, $this->diagnostics );

		ob_start();
		$admin->render_column( Admin_Diagnostics::COLUMN_KEY, $draft_id );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'index.html.md', $output );
		$this->assertStringNotContainsString( 'View Markdown', $output );
	}

	/**
	 * The bulk action validates its nonce and enqueues selected posts.
	 *
	 * @return void
	 */
	public function test_admin_bulk_action_validates_nonce_and_enqueues_posts() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Bulk content.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$queue         = new Diagnostic_Queue( $this->diagnostics, $this->settings );
		$admin         = new Admin_Diagnostics(
			$this->settings,
			$this->diagnostics,
			new Content_Resolver( $this->settings ),
			new Markdown_Url(),
			$queue
		);

		wp_set_current_user( $administrator );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );

		$redirect = $admin->handle_bulk_action(
			'https://example.org/wp-admin/edit.php',
			'od_ai_content_diagnose',
			array( $post_id )
		);

		$this->assertStringContainsString( 'od_ai_content_diagnostics_queued=1', $redirect );
		$this->assertSame( 1, $queue->get_progress()['total'] );

		$_REQUEST['_wpnonce'] = 'invalid';
		$invalid_redirect     = $admin->handle_bulk_action(
			'https://example.org/wp-admin/edit.php',
			'od_ai_content_diagnose',
			array( $post_id )
		);

		$this->assertStringContainsString( 'od_ai_content_diagnostics_error=invalid_nonce', $invalid_redirect );
	}

	/**
	 * Only users who can edit a supported post may request a preview.
	 *
	 * @return void
	 */
	public function test_rest_controller_checks_edit_capability_and_returns_preview() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id       = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>REST preview.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'REST diagnosis',
			)
		);
		$controller    = new Diagnostics_REST_Controller(
			$this->diagnostics,
			$this->settings,
			new Content_Resolver( $this->settings ),
			new Markdown_Url()
		);
		$request       = new WP_REST_Request(
			'POST',
			'/od-ai-content/v1/posts/' . $post_id . '/diagnosis'
		);
		$request['id'] = $post_id;

		wp_set_current_user( $subscriber );
		$this->assertWPError( $controller->get_item_permissions_check( $request ) );

		wp_set_current_user( $administrator );
		$this->assertTrue( $controller->get_item_permissions_check( $request ) );

		$response = $controller->run_diagnosis( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'normal', $data['status'] );
		$this->assertStringContainsString( 'REST preview.', $data['markdown'] );
		$this->assertSame( ( new Markdown_Url() )->get( get_post( $post_id ) ), $data['markdown_url'] );
	}

	/**
	 * REST diagnosis exposes actionable messages for error checks.
	 *
	 * @return void
	 */
	public function test_rest_diagnosis_exposes_actionable_error_messages() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create(
			array(
				'post_content' => '',
				'post_status'  => 'publish',
				'post_title'   => 'Empty REST diagnosis',
			)
		);
		$controller    = new Diagnostics_REST_Controller(
			$this->diagnostics,
			$this->settings,
			new Content_Resolver( $this->settings ),
			new Markdown_Url()
		);
		$request       = new WP_REST_Request(
			'POST',
			'/od-ai-content/v1/posts/' . $post_id . '/diagnosis'
		);
		$request['id'] = $post_id;

		wp_set_current_user( $administrator );

		$data         = $controller->run_diagnosis( $request )->get_data();
		$error_checks = array_values(
			array_filter(
				$data['checks'],
				static function ( $check ) {
					return 'error' === $check['severity'];
				}
			)
		);

		$this->assertSame( 'error', $data['status'] );
		$this->assertNotEmpty( $error_checks );
		$this->assertContains( 'body_empty', wp_list_pluck( $error_checks, 'code' ) );
		$this->assertStringContainsString( 'empty', implode( ' ', wp_list_pluck( $error_checks, 'message' ) ) );
	}
}
