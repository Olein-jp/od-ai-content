<?php
/**
 * Non-persistent Markdown preview integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Admin_Markdown;
use Olein\OdAiContent\Block_Converter;
use Olein\OdAiContent\Content_Resolver;
use Olein\OdAiContent\Html_To_Markdown;
use Olein\OdAiContent\Markdown_Document;
use Olein\OdAiContent\Markdown_Preview_REST_Controller;
use Olein\OdAiContent\Markdown_Url;
use Olein\OdAiContent\Settings;
use Olein\OdAiContent\Upgrade;

/**
 * Tests preview REST behavior, admin links, and legacy queue cleanup.
 */
class MarkdownPreviewTest extends WP_UnitTestCase {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Markdown document generator.
	 *
	 * @var Markdown_Document
	 */
	private $document;

	/**
	 * Preview REST controller.
	 *
	 * @var Markdown_Preview_REST_Controller
	 */
	private $controller;

	/**
	 * Set up preview services.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->settings   = new Settings();
		$this->document   = new Markdown_Document( new Block_Converter( new Html_To_Markdown() ) );
		$this->controller = new Markdown_Preview_REST_Controller(
			$this->document,
			$this->settings,
			new Content_Resolver( $this->settings ),
			new Markdown_Url()
		);
	}

	/**
	 * Clean plugin options and request state.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		delete_option( Upgrade::VERSION_OPTION );
		delete_option( Upgrade::LEGACY_QUEUE_OPTION );
		delete_option( Upgrade::LEGACY_QUEUE_LOCK_OPTION );
		wp_clear_scheduled_hook( Upgrade::LEGACY_QUEUE_CRON_HOOK );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The preview route replaces the legacy diagnosis route.
	 *
	 * @return void
	 */
	public function test_registers_preview_route_without_legacy_diagnosis_route() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/od-ai-content/v1/posts/(?P<id>[\d]+)/preview', $routes );
		$this->assertArrayNotHasKey( '/od-ai-content/v1/posts/(?P<id>[\d]+)/diagnosis', $routes );
	}

	/**
	 * Preview permission checks use the requested post and configured types.
	 *
	 * @return void
	 */
	public function test_preview_permissions_are_object_specific() {
		$missing_request       = new WP_REST_Request( 'POST' );
		$missing_request['id'] = 999999;
		$missing               = $this->controller->get_item_permissions_check( $missing_request );

		$this->assertWPError( $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );

		update_option(
			Settings::OPTION_NAME,
			array(
				'enabled'    => 1,
				'post_types' => array( 'post' ),
			)
		);

		$page_id                   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$unsupported_request       = new WP_REST_Request( 'POST' );
		$unsupported_request['id'] = $page_id;
		$unsupported               = $this->controller->get_item_permissions_check( $unsupported_request );

		$this->assertWPError( $unsupported );
		$this->assertSame( 400, $unsupported->get_error_data()['status'] );

		$post_id       = self::factory()->post->create();
		$request       = new WP_REST_Request( 'POST' );
		$request['id'] = $post_id;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$forbidden = $this->controller->get_item_permissions_check( $request );

		$this->assertWPError( $forbidden );
		$this->assertSame( 403, $forbidden->get_error_data()['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->controller->get_item_permissions_check( $request ) );
	}

	/**
	 * Preview reports conversion decisions without changing diagnostic meta.
	 *
	 * @return void
	 */
	public function test_preview_returns_conversion_report_without_persistence() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:spacer {"height":"20px"} /--><!-- wp:example/card --><div>Fallback content.</div><!-- /wp:example/card -->',
				'post_status'  => 'publish',
				'post_title'   => 'Preview report',
			)
		);
		$legacy  = array( 'status' => 'legacy' );

		update_post_meta( $post_id, '_od_ai_content_diagnostics', $legacy );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request       = new WP_REST_Request( 'POST' );
		$request['id'] = $post_id;
		$response      = $this->controller->generate_preview( $request );
		$data          = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '# Preview report', $data['markdown'] );
		$this->assertContains( 'core/spacer', $data['excluded_blocks'] );
		$this->assertContains( 'example/card', $data['fallback_blocks'] );
		$this->assertSame( ( new Markdown_Url() )->get( get_post( $post_id ) ), $data['markdown_url'] );
		$this->assertSame( $legacy, get_post_meta( $post_id, '_od_ai_content_diagnostics', true ) );
	}

	/**
	 * Generation failures return a REST error and do not write diagnostic meta.
	 *
	 * @return void
	 */
	public function test_preview_generation_failure_is_non_persistent() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Preview failure.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$filter  = static function () {
			throw new RuntimeException( 'Expected preview failure.' );
		};

		add_filter( 'od_ai_content_markdown_document', $filter );
		$request       = new WP_REST_Request( 'POST' );
		$request['id'] = $post_id;
		$result        = $this->controller->generate_preview( $request );
		remove_filter( 'od_ai_content_markdown_document', $filter );

		$this->assertWPError( $result );
		$this->assertSame( 'od_ai_content_markdown_preview_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( '', get_post_meta( $post_id, '_od_ai_content_diagnostics', true ) );
	}

	/**
	 * Saving a post does not generate Markdown or write diagnostic meta.
	 *
	 * @return void
	 */
	public function test_post_save_does_not_generate_preview_or_diagnostics() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Before save.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$calls   = 0;
		$filter  = static function ( $markdown ) use ( &$calls ) {
			++$calls;
			return $markdown;
		};

		add_filter( 'od_ai_content_markdown_document', $filter );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>After save.</p><!-- /wp:paragraph -->',
			)
		);
		do_action( 'rest_after_insert_post', get_post( $post_id ), new WP_REST_Request( 'POST' ), false );
		remove_filter( 'od_ai_content_markdown_document', $filter );

		$this->assertSame( 0, $calls );
		$this->assertSame( '', get_post_meta( $post_id, '_od_ai_content_diagnostics', true ) );
	}

	/**
	 * Post list columns retain only the public Markdown link.
	 *
	 * @return void
	 */
	public function test_admin_column_renders_public_markdown_link_only() {
		$admin    = new Admin_Markdown(
			$this->settings,
			new Content_Resolver( $this->settings ),
			new Markdown_Url()
		);
		$columns  = $admin->add_column( array( 'title' => 'Title' ) );
		$post_id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertArrayHasKey( Admin_Markdown::COLUMN_KEY, $columns );

		ob_start();
		$admin->render_column( Admin_Markdown::COLUMN_KEY, $post_id );
		$published_output = ob_get_clean();

		$this->assertStringContainsString( 'index.html.md', $published_output );
		$this->assertStringContainsString( 'View Markdown', $published_output );
		$this->assertStringNotContainsString( 'od-ai-content-status', $published_output );

		ob_start();
		$admin->render_column( Admin_Markdown::COLUMN_KEY, $draft_id );
		$draft_output = ob_get_clean();

		$this->assertSame( '&mdash;', $draft_output );
	}

	/**
	 * Legacy queue state is removed once while diagnostic post meta is retained.
	 *
	 * @return void
	 */
	public function test_upgrade_cleans_legacy_queue_once_and_keeps_post_meta() {
		$post_id = self::factory()->post->create();

		delete_option( Upgrade::VERSION_OPTION );
		update_option( Upgrade::LEGACY_QUEUE_OPTION, array( 'pending' => array( $post_id ) ) );
		update_option( Upgrade::LEGACY_QUEUE_LOCK_OPTION, time() );
		update_post_meta( $post_id, '_od_ai_content_diagnostics', array( 'status' => 'legacy' ) );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Upgrade::LEGACY_QUEUE_CRON_HOOK );

		$upgrade = new Upgrade();
		$upgrade->run();

		$this->assertSame( Upgrade::CURRENT_VERSION, (int) get_option( Upgrade::VERSION_OPTION ) );
		$this->assertFalse( get_option( Upgrade::LEGACY_QUEUE_OPTION ) );
		$this->assertFalse( get_option( Upgrade::LEGACY_QUEUE_LOCK_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Upgrade::LEGACY_QUEUE_CRON_HOOK ) );
		$this->assertSame(
			array( 'status' => 'legacy' ),
			get_post_meta( $post_id, '_od_ai_content_diagnostics', true )
		);

		update_option( Upgrade::LEGACY_QUEUE_OPTION, array( 'new' => 'value' ) );
		$upgrade->run();

		$this->assertSame( array( 'new' => 'value' ), get_option( Upgrade::LEGACY_QUEUE_OPTION ) );
	}
}
