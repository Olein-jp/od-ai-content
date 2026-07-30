<?php
/**
 * Markdown HTTP cache validation.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Builds validators and evaluates conditional Markdown requests.
 */
final class Markdown_Cache_Validator {

	/**
	 * Build ETag and Last-Modified response headers.
	 *
	 * @param WP_Post $post     Source post.
	 * @param string  $markdown Generated Markdown document.
	 * @return string[]
	 */
	public function get_headers( WP_Post $post, $markdown ) {
		$cache_key = array(
			'document_hash'     => hash( 'sha256', (string) $markdown ),
			'post_modified_gmt' => $post->post_modified_gmt,
			'schema_version'    => Markdown_Document::SCHEMA_VERSION,
		);

		/**
		 * Filter the values used to build a Markdown response ETag.
		 *
		 * @since 0.4.0
		 *
		 * @param mixed   $cache_key Cache key values.
		 * @param WP_Post $post      Source post.
		 * @param string  $markdown  Generated Markdown document.
		 */
		$cache_key = apply_filters( 'od_ai_content_markdown_cache_key', $cache_key, $post, $markdown );
		$encoded   = is_scalar( $cache_key ) ? (string) $cache_key : wp_json_encode( $cache_key );
		$timestamp = (int) get_post_modified_time( 'U', true, $post );
		$headers   = array(
			'ETag'          => '"' . hash( 'sha256', (string) $encoded ) . '"',
			'Last-Modified' => gmdate( 'D, d M Y H:i:s', $timestamp ) . ' GMT',
		);

		/**
		 * Filter Markdown HTTP cache validation headers.
		 *
		 * @since 0.4.0
		 *
		 * @param string[] $headers  ETag and Last-Modified headers.
		 * @param WP_Post  $post     Source post.
		 * @param string   $markdown Generated Markdown document.
		 */
		return (array) apply_filters( 'od_ai_content_markdown_cache_headers', $headers, $post, $markdown );
	}

	/**
	 * Determine whether request validators match the current representation.
	 *
	 * If-None-Match takes precedence over If-Modified-Since.
	 *
	 * @param string[] $headers Current response headers.
	 * @param array    $server  Request server values.
	 * @return bool
	 */
	public function is_not_modified( array $headers, array $server ) {
		if ( isset( $server['HTTP_IF_NONE_MATCH'] ) ) {
			if ( ! isset( $headers['ETag'] ) ) {
				return false;
			}

			return $this->etag_matches(
				wp_unslash( (string) $server['HTTP_IF_NONE_MATCH'] ),
				(string) $headers['ETag']
			);
		}

		if ( ! isset( $server['HTTP_IF_MODIFIED_SINCE'], $headers['Last-Modified'] ) ) {
			return false;
		}

		$request_timestamp  = strtotime( wp_unslash( (string) $server['HTTP_IF_MODIFIED_SINCE'] ) );
		$modified_timestamp = strtotime( (string) $headers['Last-Modified'] );

		return false !== $request_timestamp
			&& false !== $modified_timestamp
			&& $modified_timestamp <= $request_timestamp;
	}

	/**
	 * Compare an If-None-Match field with the current ETag.
	 *
	 * Weak comparison is valid for If-None-Match on GET requests.
	 *
	 * @param string $request_value If-None-Match field value.
	 * @param string $etag          Current response ETag.
	 * @return bool
	 */
	private function etag_matches( $request_value, $etag ) {
		$current = preg_replace( '/^W\//i', '', trim( $etag ) );

		foreach ( explode( ',', $request_value ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '*' === $candidate ) {
				return true;
			}

			if ( preg_replace( '/^W\//i', '', $candidate ) === $current ) {
				return true;
			}
		}

		return false;
	}
}
