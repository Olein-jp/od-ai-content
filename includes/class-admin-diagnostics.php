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
	 * Constructor.
	 *
	 * @param Settings    $settings    Plugin settings.
	 * @param Diagnostics $diagnostics Diagnostics service.
	 */
	public function __construct( Settings $settings, Diagnostics $diagnostics ) {
		$this->settings    = $settings;
		$this->diagnostics = $diagnostics;
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
		}
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
	}
}
