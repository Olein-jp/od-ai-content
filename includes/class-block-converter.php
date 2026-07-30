<?php
/**
 * Semantic block-to-Markdown conversion.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Converts parsed WordPress blocks while discarding known decorative content.
 */
final class Block_Converter {

	/**
	 * HTML fallback converter.
	 *
	 * @var Html_To_Markdown
	 */
	private $html_converter;

	/**
	 * Constructor.
	 *
	 * @param Html_To_Markdown $html_converter HTML fallback converter.
	 */
	public function __construct( Html_To_Markdown $html_converter ) {
		$this->html_converter = $html_converter;
	}

	/**
	 * Convert a block list.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return string
	 */
	public function convert_blocks( array $blocks ) {
		$fragments = array();

		foreach ( $blocks as $block ) {
			$fragment = trim( $this->convert_block( $block ) );

			if ( '' !== $fragment ) {
				$fragments[] = $fragment;
			}
		}

		return implode( "\n\n", $fragments );
	}

	/**
	 * Convert one parsed block.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	public function convert_block( array $block ) {
		/**
		 * Filter a block's Markdown before the built-in converters run.
		 *
		 * Return a string to handle the block, or null to use the built-in
		 * converter.
		 *
		 * @since 0.1.0
		 *
		 * @param string|null    $markdown Converted Markdown or null.
		 * @param array          $block    Parsed block.
		 * @param Block_Converter $converter Converter instance.
		 */
		$filtered = apply_filters( 'od_ai_content_block_markdown', null, $block, $this );

		if ( is_string( $filtered ) ) {
			return $filtered;
		}

		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		if ( in_array( $name, array( 'core/spacer', 'core/navigation', 'core/social-links', 'core/query' ), true ) ) {
			return '';
		}

		if ( 'core/separator' === $name ) {
			return '---';
		}

		if (
			in_array(
				$name,
				array(
					'core/group',
					'core/columns',
					'core/column',
					'core/cover',
					'core/media-text',
				),
				true
			)
			&& ! empty( $block['innerBlocks'] )
		) {
			return $this->convert_blocks( $block['innerBlocks'] );
		}

		$html     = render_block( $block );
		$markdown = $this->html_converter->convert( $html );

		if ( 'core/heading' === $name ) {
			$markdown = preg_replace( '/^#\s+/m', '## ', $markdown );
		}

		/**
		 * Filter the Markdown produced for a block.
		 *
		 * @since 0.1.0
		 *
		 * @param string $markdown Converted Markdown.
		 * @param array  $block    Parsed block.
		 */
		return (string) apply_filters( 'od_ai_content_converted_block_markdown', $markdown, $block );
	}
}
