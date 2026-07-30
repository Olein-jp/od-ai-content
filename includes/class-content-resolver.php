<?php
/**
 * Public content resolution.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Resolves a Markdown request to an eligible public post.
 */
final class Content_Resolver {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Plugin settings.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ? $settings : new Settings();
	}

	/**
	 * Resolve an original permalink path to a post.
	 *
	 * @param string $path URL path without the Markdown suffix.
	 * @return WP_Post|null
	 */
	public function resolve_path( $path ) {
		$path = trim( rawurldecode( (string) $path ), '/' );

		if ( '' === $path ) {
			return null;
		}

		$post_id = url_to_postid( home_url( user_trailingslashit( '/' . $path ) ) );

		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );

		return $this->is_eligible( $post ) ? $post : null;
	}

	/**
	 * Determine whether a post may have a public Markdown representation.
	 *
	 * @param WP_Post|null $post Post object.
	 * @return bool
	 */
	public function is_eligible( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( ! $this->settings->is_enabled() ) {
			return false;
		}

		return 'publish' === $post->post_status
			&& in_array( $post->post_type, $this->settings->get_post_types(), true )
			&& '' === $post->post_password
			&& ! Post_Exclusion::is_excluded( $post->ID );
	}
}
