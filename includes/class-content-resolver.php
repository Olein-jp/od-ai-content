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

		/**
		 * Filter post types that may expose a Markdown alternative.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $post_types Allowed post type names.
		 */
		$post_types = (array) apply_filters( 'od_ai_content_post_types', array( 'post', 'page' ) );

		return 'publish' === $post->post_status
			&& in_array( $post->post_type, $post_types, true )
			&& '' === $post->post_password;
	}
}
