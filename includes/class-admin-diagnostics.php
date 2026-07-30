<?php
/**
 * Markdown diagnostic status in post list tables.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Adds a stored diagnostic status column to configured post types.
 */
final class Admin_Diagnostics {

	/**
	 * Diagnostic column key.
	 *
	 * @var string
	 */
	const COLUMN_KEY = 'od_ai_content_diagnostics';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Diagnostics service.
	 *
	 * @var Diagnostics
	 */
	private $diagnostics;

	/**
	 * Public content resolver.
	 *
	 * @var Content_Resolver
	 */
	private $resolver;

	/**
	 * Markdown URL generator.
	 *
	 * @var Markdown_Url
	 */
	private $url;

	/**
	 * Background diagnostic queue.
	 *
	 * @var Diagnostic_Queue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings    Plugin settings.
	 * @param Diagnostics           $diagnostics Diagnostics service.
	 * @param Content_Resolver|null $resolver    Public content resolver.
	 * @param Markdown_Url|null     $url         Markdown URL generator.
	 * @param Diagnostic_Queue|null $queue       Background diagnostic queue.
	 */
	public function __construct(
		Settings $settings,
		Diagnostics $diagnostics,
		?Content_Resolver $resolver = null,
		?Markdown_Url $url = null,
		?Diagnostic_Queue $queue = null
	) {
		$this->settings    = $settings;
		$this->diagnostics = $diagnostics;
		$this->resolver    = $resolver ? $resolver : new Content_Resolver( $settings );
		$this->url         = $url ? $url : new Markdown_Url();
		$this->queue       = $queue ? $queue : new Diagnostic_Queue( $diagnostics, $settings );
	}

	/**
	 * Register list table hooks for configured post types.
	 *
	 * @return void
	 */
	public function register_hooks() {
		foreach ( $this->settings->get_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}

		add_action( 'admin_notices', array( $this, 'render_progress_notice' ) );
	}

	/**
	 * Add the diagnostic status column.
	 *
	 * @param string[] $columns Existing columns.
	 * @return string[]
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN_KEY ] = __( 'OD AI Content', 'od-ai-content' );

		return $columns;
	}

	/**
	 * Render a post's stored diagnostic status without regenerating Markdown.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public function render_column( $column_name, $post_id ) {
		if ( self::COLUMN_KEY !== $column_name ) {
			return;
		}

		$status = $this->diagnostics->get_status( $post_id );
		$label  = Diagnostics::get_status_label( $status );
		$icons  = array(
			'normal'        => 'yes-alt',
			'warning'       => 'warning',
			'error'         => 'dismiss',
			'excluded'      => 'hidden',
			'not_diagnosed' => 'marker',
		);
		$icon   = isset( $icons[ $status ] ) ? $icons[ $status ] : $icons['not_diagnosed'];

		printf(
			'<span class="od-ai-content-status od-ai-content-status--%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span> <span>%3$s</span></span>',
			esc_attr( $status ),
			esc_attr( $icon ),
			esc_html( $label )
		);

		$post = get_post( $post_id );

		if ( $this->resolver->is_eligible( $post ) ) {
			printf(
				'<br><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $this->url->get( $post ) ),
				esc_html__( 'View Markdown', 'od-ai-content' )
			);
		}
	}

	/**
	 * Add the background diagnosis bulk action.
	 *
	 * @param string[] $actions Existing bulk actions.
	 * @return string[]
	 */
	public function add_bulk_action( $actions ) {
		$actions['od_ai_content_diagnose'] = __( 'Run OD AI Content diagnosis', 'od-ai-content' );

		return $actions;
	}

	/**
	 * Validate and enqueue selected posts.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Selected bulk action.
	 * @param int[]  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( 'od_ai_content_diagnose' !== $action ) {
			return $redirect_to;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
			return add_query_arg( 'od_ai_content_diagnostics_error', 'invalid_nonce', $redirect_to );
		}

		$result = $this->queue->enqueue( (array) $post_ids );

		return add_query_arg(
			array(
				'od_ai_content_diagnostics_queued'  => (int) $result['queued'],
				'od_ai_content_diagnostics_skipped' => (int) $result['skipped'],
			),
			$redirect_to
		);
	}

	/**
	 * Display persistent queue progress on configured post list screens.
	 *
	 * @return void
	 */
	public function render_progress_notice() {
		$screen = get_current_screen();

		if (
			! $screen
			|| 'edit' !== $screen->base
			|| ! in_array( $screen->post_type, $this->settings->get_post_types(), true )
		) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only value only selects an admin notice.
		if ( isset( $_GET['od_ai_content_diagnostics_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only value only selects an admin notice.
			$error = sanitize_key( wp_unslash( $_GET['od_ai_content_diagnostics_error'] ) );

			if ( 'invalid_nonce' === $error ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'The bulk diagnosis request could not be verified.', 'od-ai-content' )
				);
			}

			return;
		}

		$progress = $this->queue->get_progress();

		if ( 0 === $progress['total'] ) {
			return;
		}

		$notice_class = 0 < $progress['failed'] ? 'notice-warning' : 'notice-info';

		if ( 'completed' === $progress['status'] && 0 === $progress['failed'] ) {
			$notice_class = 'notice-success';
		}

		$message = sprintf(
			/* translators: 1: waiting count, 2: processing count, 3: completed count, 4: failed count. */
			__( 'OD AI Content bulk diagnosis — Waiting: %1$d, Processing: %2$d, Completed: %3$d, Failed: %4$d.', 'od-ai-content' ),
			$progress['waiting'],
			$progress['processing'],
			$progress['completed'],
			$progress['failed']
		);

		printf(
			'<div class="notice %1$s"><p>%2$s</p></div>',
			esc_attr( $notice_class ),
			esc_html( $message )
		);
	}
}
