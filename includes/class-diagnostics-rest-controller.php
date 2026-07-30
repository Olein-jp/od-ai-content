<?php
/**
 * Authenticated Markdown diagnostics REST endpoint.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Regenerates diagnostics and returns an editor preview.
 */
final class Diagnostics_REST_Controller extends WP_REST_Controller {

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
	 * @param Diagnostics      $diagnostics Diagnostics service.
	 * @param Settings         $settings    Plugin settings.
	 * @param Content_Resolver $resolver    Public content resolver.
	 * @param Markdown_Url     $url         Markdown URL generator.
	 */
	public function __construct(
		Diagnostics $diagnostics,
		Settings $settings,
		Content_Resolver $resolver,
		Markdown_Url $url
	) {
		$this->namespace   = 'od-ai-content/v1';
		$this->rest_base   = 'posts';
		$this->diagnostics = $diagnostics;
		$this->settings    = $settings;
		$this->resolver    = $resolver;
		$this->url         = $url;
	}

	/**
	 * Register REST API hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		foreach ( $this->settings->get_post_types() as $post_type ) {
			add_action( "rest_after_insert_{$post_type}", array( $this, 'refresh_after_post_save' ), 10, 3 );
		}
	}

	/**
	 * Store a fresh diagnosis after the block editor finishes saving a post.
	 *
	 * The core REST controller fires this action after post fields, terms, and
	 * registered meta have been saved, so the stored result represents the
	 * completed editor save.
	 *
	 * @param WP_Post         $post     Saved post.
	 * @param WP_REST_Request $request Save request.
	 * @param bool            $creating Whether the post was created.
	 * @return void
	 */
	public function refresh_after_post_save( WP_Post $post, WP_REST_Request $request, $creating ) {
		unset( $request, $creating );

		if (
			wp_is_post_revision( $post->ID )
			|| wp_is_post_autosave( $post->ID )
			|| ! in_array( $post->post_type, $this->settings->get_post_types(), true )
		) {
			return;
		}

		$this->diagnostics->diagnose( $post );
	}

	/**
	 * Register the authenticated diagnosis endpoint.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/diagnosis',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_diagnosis' ),
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
	 * Check whether the current user may diagnose the requested post.
	 *
	 * @param WP_REST_Request $request REST request.
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
				__( 'You are not allowed to diagnose this post.', 'od-ai-content' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Regenerate diagnostics and return the Markdown preview.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function run_diagnosis( $request ) {
		$post   = get_post( (int) $request['id'] );
		$result = $this->diagnostics->diagnose( $post );
		$result = $this->diagnostics->prepare_result_for_display( $result );

		$result['markdown_url'] = $this->resolver->is_eligible( $post )
			? $this->url->get( $post )
			: '';

		return rest_ensure_response( $result );
	}
}
