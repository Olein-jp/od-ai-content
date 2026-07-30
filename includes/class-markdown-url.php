<?php
/**
 * Markdown URL generation.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Builds the canonical Markdown alternative URL for a post.
 */
final class Markdown_Url {

	/**
	 * Get a post's Markdown URL.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public function get( WP_Post $post ) {
		return trailingslashit( get_permalink( $post ) ) . 'index.html.md';
	}
}
