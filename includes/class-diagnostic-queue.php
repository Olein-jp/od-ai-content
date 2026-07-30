<?php
/**
 * Persistent background queue for Markdown diagnostics.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use Throwable;
use WP_Post;

/**
 * Processes selected posts in small, idempotent WordPress Cron batches.
 */
final class Diagnostic_Queue {

	/**
	 * Queue state option.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'od_ai_content_diagnostic_queue';

	/**
	 * Worker lock option.
	 *
	 * @var string
	 */
	const LOCK_OPTION_NAME = 'od_ai_content_diagnostic_queue_lock';

	/**
	 * Cron hook.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'od_ai_content_process_diagnostic_queue';

	/**
	 * Queue state schema version.
	 *
	 * @var int
	 */
	const STATE_VERSION = 1;

	/**
	 * Number of posts processed by one Cron request.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 5;

	/**
	 * Seconds after which an abandoned worker lock may be reclaimed.
	 *
	 * @var int
	 */
	const LOCK_TTL = 300;

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
	 * Optional diagnostic processor used by tests and integrations.
	 *
	 * @var callable|null
	 */
	private $processor;

	/**
	 * Constructor.
	 *
	 * @param Diagnostics   $diagnostics Diagnostics service.
	 * @param Settings      $settings    Plugin settings.
	 * @param callable|null $processor   Optional post processor.
	 */
	public function __construct( Diagnostics $diagnostics, Settings $settings, $processor = null ) {
		$this->diagnostics = $diagnostics;
		$this->settings    = $settings;
		$this->processor   = is_callable( $processor ) ? $processor : null;
	}

	/**
	 * Register queue hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::CRON_HOOK, array( $this, 'process' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Add editable posts to the active queue.
	 *
	 * @param int[] $post_ids Selected post IDs.
	 * @return array{queued:int,skipped:int}
	 */
	public function enqueue( array $post_ids ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$state    = $this->get_state();

		if ( 'completed' === $state['status'] && empty( $state['pending'] ) && empty( $state['processing'] ) ) {
			$state = $this->get_default_state();
		}

		$queued  = 0;
		$skipped = 0;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if (
				! $post instanceof WP_Post
				|| ! in_array( $post->post_type, $this->settings->get_post_types(), true )
				|| ! current_user_can( 'edit_post', $post_id )
				|| in_array( $post_id, $state['queued_ids'], true )
			) {
				++$skipped;
				continue;
			}

			$state['pending'][]    = $post_id;
			$state['queued_ids'][] = $post_id;
			++$queued;
		}

		if ( 0 < $queued ) {
			$state['status']     = 'pending';
			$state['total']      = count( $state['queued_ids'] );
			$state['updated_at'] = time();
			$this->save_state( $state );
			$this->schedule();
		}

		return array(
			'queued'  => $queued,
			'skipped' => $skipped,
		);
	}

	/**
	 * Process one small queue batch.
	 *
	 * @return void
	 * @throws \RuntimeException When a queued post is unavailable or unsupported.
	 */
	public function process() {
		if ( ! $this->acquire_lock() ) {
			$this->schedule();
			return;
		}

		try {
			$batch_size = (int) apply_filters( 'od_ai_content_diagnostic_queue_batch_size', self::BATCH_SIZE );
			$batch_size = max( 1, $batch_size );

			for ( $processed = 0; $processed < $batch_size; ++$processed ) {
				$state = $this->get_state();

				if ( empty( $state['pending'] ) ) {
					$this->complete( $state );
					break;
				}

				$post_id             = (int) reset( $state['pending'] );
				$state['status']     = 'running';
				$state['processing'] = $post_id;
				$state['updated_at'] = time();
				$this->save_state( $state );
				$diagnostic_succeeded = true;

				try {
					$post = get_post( $post_id );

					if (
						! $post instanceof WP_Post
						|| ! in_array( $post->post_type, $this->settings->get_post_types(), true )
					) {
						throw new \RuntimeException( 'The queued post is unavailable or unsupported.' );
					}

					$this->run_diagnosis( $post );
				} catch ( Throwable $error ) {
					$diagnostic_succeeded = false;

					/**
					 * Fires when a background diagnostic job fails.
					 *
					 * @since 0.4.0
					 *
					 * @param Throwable $error   Processing error.
					 * @param int       $post_id Queued post ID.
					 */
					do_action( 'od_ai_content_diagnostic_queue_error', $error, $post_id );
				}

				$state               = $this->get_state();
				$state['pending']    = array_values( array_diff( $state['pending'], array( $post_id ) ) );
				$state['processing'] = 0;
				$state['updated_at'] = time();

				if ( $diagnostic_succeeded ) {
					++$state['completed'];
				} else {
					++$state['failed'];
					$state['failed_ids'][] = $post_id;
					$state['failed_ids']   = array_values( array_unique( $state['failed_ids'] ) );
				}

				$this->save_state( $state );
			}

			$state = $this->get_state();

			if ( empty( $state['pending'] ) ) {
				$this->complete( $state );
			} else {
				$state['status']     = 'pending';
				$state['updated_at'] = time();
				$this->save_state( $state );
				$this->schedule();
			}
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Ensure an interrupted pending queue has a scheduled worker.
	 *
	 * @return void
	 */
	public function ensure_scheduled() {
		$state = $this->get_state();

		if ( ! empty( $state['pending'] ) ) {
			$this->schedule();
		}
	}

	/**
	 * Return normalized progress for the admin UI.
	 *
	 * @return array
	 */
	public function get_progress() {
		$state      = $this->get_state();
		$processing = empty( $state['processing'] ) ? 0 : 1;
		$waiting    = max( 0, count( $state['pending'] ) - $processing );

		return array(
			'status'     => $state['status'],
			'total'      => (int) $state['total'],
			'waiting'    => $waiting,
			'processing' => $processing,
			'completed'  => (int) $state['completed'],
			'failed'     => (int) $state['failed'],
			'failed_ids' => $state['failed_ids'],
			'updated_at' => (int) $state['updated_at'],
		);
	}

	/**
	 * Return a completed queue once, then reset its display state.
	 *
	 * @since 0.5.0
	 *
	 * @return array|null Completed progress, or null when already consumed.
	 */
	public function consume_completed_progress() {
		$state = $this->get_state();

		if ( 'completed' !== $state['status'] || empty( $state['completion_notice_pending'] ) ) {
			return null;
		}

		$progress = $this->get_progress();
		$this->save_state( $this->get_default_state() );

		return $progress;
	}

	/**
	 * Remove scheduled workers and an abandoned lock on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::LOCK_OPTION_NAME );
	}

	/**
	 * Run the configured diagnostic processor.
	 *
	 * @param WP_Post $post Queued post.
	 * @return void
	 */
	private function run_diagnosis( WP_Post $post ) {
		if ( is_callable( $this->processor ) ) {
			call_user_func( $this->processor, $post );
			return;
		}

		$this->diagnostics->diagnose( $post );
	}

	/**
	 * Mark an empty queue as completed.
	 *
	 * @param array $state Queue state.
	 * @return void
	 */
	private function complete( array $state ) {
		if ( 0 === (int) $state['total'] ) {
			return;
		}

		$should_notify                      = 'completed' !== $state['status'];
		$state['status']                    = 'completed';
		$state['processing']                = 0;
		$state['updated_at']                = time();
		$state['completion_notice_pending'] = $should_notify || ! empty( $state['completion_notice_pending'] );
		$this->save_state( $state );
	}

	/**
	 * Schedule the next worker when one is not already pending.
	 *
	 * @return void
	 */
	private function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
	}

	/**
	 * Acquire an atomic option-based worker lock.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$now = time();

		if ( add_option( self::LOCK_OPTION_NAME, $now, '', 'no' ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION_NAME, 0 );

		if ( 0 < $locked_at && ( $now - $locked_at ) <= self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION_NAME );

		return add_option( self::LOCK_OPTION_NAME, $now, '', 'no' );
	}

	/**
	 * Release the worker lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_option( self::LOCK_OPTION_NAME );
	}

	/**
	 * Read and normalize queue state.
	 *
	 * @return array
	 */
	private function get_state() {
		$state = get_option( self::OPTION_NAME, array() );

		if (
			! is_array( $state )
			|| self::STATE_VERSION !== ( isset( $state['version'] ) ? (int) $state['version'] : 0 )
		) {
			return $this->get_default_state();
		}

		$state                              = wp_parse_args( $state, $this->get_default_state() );
		$state['pending']                   = array_values( array_unique( array_filter( array_map( 'absint', (array) $state['pending'] ) ) ) );
		$state['queued_ids']                = array_values( array_unique( array_filter( array_map( 'absint', (array) $state['queued_ids'] ) ) ) );
		$state['failed_ids']                = array_values( array_unique( array_filter( array_map( 'absint', (array) $state['failed_ids'] ) ) ) );
		$state['processing']                = absint( $state['processing'] );
		$state['total']                     = absint( $state['total'] );
		$state['completed']                 = absint( $state['completed'] );
		$state['failed']                    = absint( $state['failed'] );
		$state['updated_at']                = absint( $state['updated_at'] );
		$state['completion_notice_pending'] = (bool) $state['completion_notice_pending'];
		$state['status']                    = in_array( $state['status'], array( 'idle', 'pending', 'running', 'completed' ), true )
			? $state['status']
			: 'idle';

		return $state;
	}

	/**
	 * Save normalized queue state without autoloading it.
	 *
	 * @param array $state Queue state.
	 * @return void
	 */
	private function save_state( array $state ) {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $state, '', 'no' );
			return;
		}

		update_option( self::OPTION_NAME, $state, false );
	}

	/**
	 * Get a fresh queue state.
	 *
	 * @return array
	 */
	private function get_default_state() {
		return array(
			'version'                   => self::STATE_VERSION,
			'status'                    => 'idle',
			'pending'                   => array(),
			'processing'                => 0,
			'queued_ids'                => array(),
			'total'                     => 0,
			'completed'                 => 0,
			'failed'                    => 0,
			'failed_ids'                => array(),
			'updated_at'                => 0,
			'completion_notice_pending' => false,
		);
	}
}
