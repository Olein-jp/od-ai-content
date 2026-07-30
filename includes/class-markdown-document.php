<?php
/**
 * Complete Markdown document generation.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Builds a self-describing Markdown document from a WordPress post.
 */
final class Markdown_Document {

	/**
	 * Block converter.
	 *
	 * @var Block_Converter
	 */
	private $block_converter;

	/**
	 * Constructor.
	 *
	 * @param Block_Converter $block_converter Block converter.
	 */
	public function __construct( Block_Converter $block_converter ) {
		$this->block_converter = $block_converter;
	}

	/**
	 * Generate a complete Markdown document.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public function generate( WP_Post $post ) {
		$metadata  = $this->get_metadata( $post );
		$content   = $this->generate_content( $post );
		$title     = get_the_title( $post );
		$document  = $this->serialize_front_matter( $metadata );
		$document .= "\n# " . $title . "\n";

		if ( '' !== $content ) {
			$document .= "\n" . $content . "\n";
		}

		/**
		 * Filter the final Markdown document.
		 *
		 * @since 0.1.0
		 *
		 * @param string  $document Generated document.
		 * @param WP_Post $post     Source post.
		 * @param array   $metadata Document metadata.
		 */
		return (string) apply_filters( 'od_ai_content_markdown_document', $document, $post, $metadata );
	}

	/**
	 * Generate body Markdown with the post set as rendering context.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function generate_content( WP_Post $post ) {
		$previous_post = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post
			? $GLOBALS['post']
			: null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- render_block() requires the source post as global context.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$content = $this->block_converter->convert_blocks( parse_blocks( $post->post_content ) );

		if ( $previous_post ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the previous rendering context.
			$GLOBALS['post'] = $previous_post;
			setup_postdata( $previous_post );
		} else {
			unset( $GLOBALS['post'] );
		}

		return trim( $content );
	}

	/**
	 * Build document metadata.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function get_metadata( WP_Post $post ) {
		$author = get_userdata( (int) $post->post_author );

		$metadata = array(
			'title'          => get_the_title( $post ),
			'canonical_url'  => get_permalink( $post ),
			'language'       => get_bloginfo( 'language' ),
			'date_published' => get_post_time( DATE_W3C, false, $post ),
			'date_modified'  => get_post_modified_time( DATE_W3C, false, $post ),
			'content_type'   => $post->post_type,
			'author'         => $author ? $author->display_name : '',
		);

		if ( has_excerpt( $post ) ) {
			$metadata['description'] = wp_strip_all_tags( get_the_excerpt( $post ) );
		}

		$taxonomies = $this->get_taxonomies( $post );

		if ( ! empty( $taxonomies ) ) {
			$metadata['taxonomies'] = $taxonomies;
		}

		/**
		 * Filter Markdown front-matter metadata.
		 *
		 * @since 0.1.0
		 *
		 * @param array   $metadata Metadata values.
		 * @param WP_Post $post     Source post.
		 */
		return (array) apply_filters( 'od_ai_content_markdown_metadata', $metadata, $post );
	}

	/**
	 * Get public taxonomy term names.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function get_taxonomies( WP_Post $post ) {
		$values     = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$values[ $taxonomy->name ] = wp_list_pluck( $terms, 'name' );
		}

		return $values;
	}

	/**
	 * Serialize metadata as conservative YAML front matter.
	 *
	 * @param array $metadata Metadata values.
	 * @return string
	 */
	private function serialize_front_matter( array $metadata ) {
		$lines = array( '---' );

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$lines[] = $key . ':';

				foreach ( $value as $child_key => $child_value ) {
					$lines[] = '  ' . sanitize_key( $child_key ) . ':';

					foreach ( (array) $child_value as $item ) {
						$lines[] = '    - ' . $this->yaml_scalar( $item );
					}
				}

				continue;
			}

			if ( '' !== (string) $value ) {
				$lines[] = $key . ': ' . $this->yaml_scalar( $value );
			}
		}

		$lines[] = '---';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Quote a scalar as a JSON-compatible YAML string.
	 *
	 * @param mixed $value Scalar value.
	 * @return string
	 */
	private function yaml_scalar( $value ) {
		return (string) wp_json_encode(
			(string) $value,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
}
