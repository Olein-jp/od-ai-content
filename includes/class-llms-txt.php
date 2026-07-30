<?php
/**
 * Llms.txt generation.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Generates llms.txt from explicitly selected eligible content.
 */
final class Llms_Txt {

	/**
	 * Content resolver.
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
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Content_Resolver $resolver Content resolver.
	 * @param Markdown_Url     $url      Markdown URL generator.
	 * @param Settings         $settings Plugin settings.
	 */
	public function __construct( Content_Resolver $resolver, Markdown_Url $url, Settings $settings ) {
		$this->resolver = $resolver;
		$this->url      = $url;
		$this->settings = $settings;
	}

	/**
	 * Generate the current site's llms.txt document.
	 *
	 * @return string
	 */
	public function generate() {
		$default_selected = $this->settings->is_llms_default_selected();
		$query_args       = array(
			'no_found_rows'  => true,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
				'ID'         => 'ASC',
			),
			'post_status'    => 'publish',
			'post_type'      => $this->settings->get_post_types(),
			'posts_per_page' => -1,
		);

		if ( ! $default_selected ) {
			$query_args = array_merge(
				$query_args,
				array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit selection is intentionally stored in post meta.
					'meta_key'   => Llms_Selection::META_KEY,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only the explicit selected value is eligible.
					'meta_value' => '1',
				)
			);
		}

		$posts = get_posts( $query_args );

		/**
		 * Filter selected or default-included posts before llms.txt entries are generated.
		 *
		 * Posts still need to pass the public Markdown eligibility check.
		 *
		 * @since 0.3.0
		 *
		 * @param WP_Post[] $posts Candidate posts.
		 */
		$posts   = (array) apply_filters( 'od_ai_content_llms_txt_posts', $posts );
		$entries = array();

		foreach ( $posts as $post ) {
			if (
				! $post instanceof WP_Post
				|| ! Llms_Selection::is_selected( $post->ID, $default_selected )
				|| ! $this->resolver->is_eligible( $post )
			) {
				continue;
			}

			$entries[] = array(
				'title'       => get_the_title( $post ),
				'url'         => $this->url->get( $post ),
				'description' => Llms_Selection::get_description( $post ),
				'post_id'     => $post->ID,
			);
		}

		/**
		 * Filter llms.txt entries, including their content and order.
		 *
		 * Each entry accepts title, url, description, and post_id keys.
		 *
		 * @since 0.3.0
		 *
		 * @param array[]   $entries Generated entries.
		 * @param WP_Post[] $posts   Candidate posts from the query.
		 */
		$entries = (array) apply_filters( 'od_ai_content_llms_txt_entries', $entries, $posts );
		$lines   = array(
			'# ' . $this->escape_text( get_bloginfo( 'name' ) ),
			'',
			'> ' . $this->escape_text( get_bloginfo( 'description' ) ),
		);

		if ( ! empty( $entries ) ) {
			$lines[] = '';
			$lines[] = '## ' . __( 'Content', 'od-ai-content' );
			$lines[] = '';

			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$title       = isset( $entry['title'] ) ? $this->escape_link_text( $entry['title'] ) : '';
				$url         = isset( $entry['url'] ) ? esc_url_raw( $entry['url'] ) : '';
				$description = isset( $entry['description'] ) ? $this->escape_text( $entry['description'] ) : '';

				if ( '' === $title || '' === $url || '' === $description ) {
					continue;
				}

				$lines[] = sprintf( '- [%1$s](%2$s): %3$s', $title, $url, $description );
			}
		}

		$output = implode( "\n", $lines ) . "\n";

		/**
		 * Filter the complete llms.txt document.
		 *
		 * @since 0.3.0
		 *
		 * @param string  $output  Generated document.
		 * @param array[] $entries Filtered entries.
		 */
		return (string) apply_filters( 'od_ai_content_llms_txt_output', $output, $entries );
	}

	/**
	 * Escape plain Markdown text and normalize it to one line.
	 *
	 * @param mixed $text Text value.
	 * @return string
	 */
	private function escape_text( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );

		return str_replace( '\\', '\\\\', $text );
	}

	/**
	 * Escape a Markdown link label.
	 *
	 * @param mixed $text Link label.
	 * @return string
	 */
	private function escape_link_text( $text ) {
		return str_replace(
			array( '[', ']' ),
			array( '\[', '\]' ),
			$this->escape_text( $text )
		);
	}
}
