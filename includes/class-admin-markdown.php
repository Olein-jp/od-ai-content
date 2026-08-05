<?php
/**
 * Public Markdown links in post list tables.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Adds a public Markdown link column to configured post types.
 */
final class Admin_Markdown {

	/**
	 * Markdown column key.
	 *
	 * @var string
	 */
	const COLUMN_KEY = 'od_ai_content_markdown';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
	 * Constructor.
	 *
	 * @param Settings         $settings Plugin settings.
	 * @param Content_Resolver $resolver Public content resolver.
	 * @param Markdown_Url     $url      Markdown URL generator.
	 */
	public function __construct( Settings $settings, Content_Resolver $resolver, Markdown_Url $url ) {
		$this->settings = $settings;
		$this->resolver = $resolver;
		$this->url      = $url;
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
	 * Add the Markdown link column.
	 *
	 * @param string[] $columns Existing columns.
	 * @return string[]
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN_KEY ] = __( 'OD AI Content', 'od-ai-content' );

		return $columns;
	}

	/**
	 * Render a post's public Markdown link when available.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public function render_column( $column_name, $post_id ) {
		if ( self::COLUMN_KEY !== $column_name ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $this->resolver->is_eligible( $post ) ) {
			echo '&mdash;';
			return;
		}

		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $this->url->get( $post ) ),
			esc_html__( 'View Markdown', 'od-ai-content' )
		);
	}
}
