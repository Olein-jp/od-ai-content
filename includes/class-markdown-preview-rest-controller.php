<?php
/**
 * Authenticated Markdown preview REST endpoint.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use Throwable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

/**
 * Generates a non-persistent Markdown preview and conversion report.
 */
final class Markdown_Preview_REST_Controller extends WP_REST_Controller {

	/**
	 * Markdown document generator.
	 *
	 * @var Markdown_Document
	 */
	private $document;

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
	 * @param Markdown_Document $document Markdown document generator.
	 * @param Settings          $settings Plugin settings.
	 * @param Content_Resolver  $resolver Public content resolver.
	 * @param Markdown_Url      $url      Markdown URL generator.
	 */
	public function __construct(
		Markdown_Document $document,
		Settings $settings,
		Content_Resolver $resolver,
		Markdown_Url $url
	) {
		$this->namespace = 'od-ai-content/v1';
		$this->rest_base = 'posts';
		$this->document  = $document;
		$this->settings  = $settings;
		$this->resolver  = $resolver;
		$this->url       = $url;
	}

	/**
	 * Register REST API hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the authenticated preview endpoint.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_preview' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Unique identifier for the post.', 'od-ai-content' ),
						'required'    => true,
						'type'        => 'integer',
						'minimum'     => 1,
					),
				),
			)
		);
	}

	/**
	 * Check whether the current user may preview the requested post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post ) {
			return new WP_Error(
				'od_ai_content_post_not_found',
				__( 'The requested post was not found.', 'od-ai-content' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $post->post_type, $this->settings->get_post_types(), true ) ) {
			return new WP_Error(
				'od_ai_content_post_type_not_supported',
				__( 'This post type is not enabled for Markdown output.', 'od-ai-content' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'You are not allowed to preview this post.', 'od-ai-content' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Generate a Markdown preview without changing the post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function generate_preview( $request ) {
		$post = get_post( (int) $request['id'] );

		try {
			$generated = $this->document->generate_with_report( $post );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'od_ai_content_markdown_preview_failed',
				__( 'The Markdown preview could not be generated.', 'od-ai-content' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'markdown'        => (string) $generated['markdown'],
				'excluded_blocks' => array_values( array_unique( (array) $generated['excluded_blocks'] ) ),
				'fallback_blocks' => array_values( array_unique( (array) $generated['fallback_blocks'] ) ),
				'markdown_url'    => $this->resolver->is_eligible( $post ) ? $this->url->get( $post ) : '',
			)
		);
	}
}
