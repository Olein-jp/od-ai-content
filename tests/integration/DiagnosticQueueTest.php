<?php
/**
 * Background diagnostic queue integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Block_Converter;
use Olein\OdAiContent\Diagnostic_Queue;
use Olein\OdAiContent\Diagnostics;
use Olein\OdAiContent\Html_To_Markdown;
use Olein\OdAiContent\Markdown_Document;
use Olein\OdAiContent\Settings;

/**
 * Tests queue validation, batching, deduplication, and failure isolation.
 */
class DiagnosticQueueTest extends WP_UnitTestCase {

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
	 * Set up production diagnostic services and a clean queue.
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

		delete_option( Diagnostic_Queue::OPTION_NAME );
		delete_option( Diagnostic_Queue::LOCK_OPTION_NAME );
		wp_clear_scheduled_hook( Diagnostic_Queue::CRON_HOOK );
	}

	/**
	 * Clean queue, settings, scheduled events, and request state.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Diagnostic_Queue::OPTION_NAME );
		delete_option( Diagnostic_Queue::LOCK_OPTION_NAME );
		delete_option( Settings::OPTION_NAME );
		wp_clear_scheduled_hook( Diagnostic_Queue::CRON_HOOK );
		wp_set_current_user( 0 );
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Selected posts are deduplicated and processed in bounded batches.
	 *
	 * @return void
	 */
	public function test_queue_deduplicates_and_processes_bounded_batches() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_ids = array();

		for ( $index = 0; $index < 6; ++$index ) {
			$post_ids[] = self::factory()->post->create(
				array(
					'post_content' => '<!-- wp:paragraph --><p>Queued content.</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
					'post_title'   => 'Queued post ' . $index,
				)
			);
		}

		$queue  = new Diagnostic_Queue( $this->diagnostics, $this->settings );
		$result = $queue->enqueue( array_merge( $post_ids, array( $post_ids[0] ) ) );

		$this->assertSame( 6, $result['queued'] );
		$this->assertNotFalse( wp_next_scheduled( Diagnostic_Queue::CRON_HOOK ) );

		$duplicate = $queue->enqueue( array( $post_ids[0] ) );
		$this->assertSame( 0, $duplicate['queued'] );
		$this->assertSame( 1, $duplicate['skipped'] );

		$queue->process();
		$progress = $queue->get_progress();

		$this->assertSame( 'pending', $progress['status'] );
		$this->assertSame( 5, $progress['completed'] );
		$this->assertSame( 1, $progress['waiting'] );
		$this->assertSame( 0, $progress['failed'] );

		$queue->process();
		$progress = $queue->get_progress();

		$this->assertSame( 'completed', $progress['status'] );
		$this->assertSame( 6, $progress['completed'] );
		$this->assertSame( 0, $progress['waiting'] );
		$this->assertIsArray( $this->diagnostics->get_stored_result( $post_ids[5] ) );
	}

	/**
	 * Unsupported or unauthorized posts are not queued.
	 *
	 * @return void
	 */
	public function test_queue_rejects_unsupported_and_unauthorized_posts() {
		update_option(
			Settings::OPTION_NAME,
			array(
				'enabled'    => 1,
				'post_types' => array( 'post' ),
			)
		);

		$post_id = self::factory()->post->create();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$queue   = new Diagnostic_Queue( $this->diagnostics, $this->settings );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$unauthorized = $queue->enqueue( array( $post_id ) );

		$this->assertSame( 0, $unauthorized['queued'] );
		$this->assertSame( 1, $unauthorized['skipped'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$unsupported = $queue->enqueue( array( $page_id ) );

		$this->assertSame( 0, $unsupported['queued'] );
		$this->assertSame( 1, $unsupported['skipped'] );
		$this->assertSame( 0, $queue->get_progress()['total'] );
	}

	/**
	 * A failed item is recorded without preventing later posts from running.
	 *
	 * @return void
	 */
	public function test_queue_continues_after_individual_failure() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$failed_id   = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Fails.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$success_id  = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Succeeds.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$diagnostics = $this->diagnostics;
		$queue       = new Diagnostic_Queue(
			$this->diagnostics,
			$this->settings,
			static function ( $post ) use ( $failed_id, $diagnostics ) {
				if ( $failed_id === $post->ID ) {
					throw new RuntimeException( 'Expected queue failure.' );
				}

				$diagnostics->diagnose( $post );
			}
		);

		$queue->enqueue( array( $failed_id, $success_id ) );
		$queue->process();
		$progress = $queue->get_progress();

		$this->assertSame( 'completed', $progress['status'] );
		$this->assertSame( 1, $progress['completed'] );
		$this->assertSame( 1, $progress['failed'] );
		$this->assertSame( array( $failed_id ), $progress['failed_ids'] );
		$this->assertIsArray( $this->diagnostics->get_stored_result( $success_id ) );
	}

	/**
	 * Completed progress is available once and then resets to idle.
	 *
	 * @return void
	 */
	public function test_completed_progress_is_consumed_once() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Completed.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$queue   = new Diagnostic_Queue( $this->diagnostics, $this->settings );

		$queue->enqueue( array( $post_id ) );
		$queue->process();

		$completed = $queue->consume_completed_progress();

		$this->assertIsArray( $completed );
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 1, $completed['completed'] );
		$this->assertNull( $queue->consume_completed_progress() );
		$this->assertSame( 'idle', $queue->get_progress()['status'] );
		$this->assertSame( 0, $queue->get_progress()['total'] );
	}
}
