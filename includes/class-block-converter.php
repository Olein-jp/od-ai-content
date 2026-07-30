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

		$custom = $this->convert_with_custom_converters( $block );

		if ( is_string( $custom ) ) {
			return $custom;
		}

		if ( in_array( $name, array( 'core/spacer', 'core/navigation', 'core/social-links', 'core/query' ), true ) ) {
			return '';
		}

		if ( 'core/separator' === $name ) {
			return '---';
		}

		if ( 'core/details' === $name ) {
			return $this->convert_details( $block );
		}

		if ( 'core/embed' === $name ) {
			return $this->convert_embed( $block );
		}

		if ( 'core/buttons' === $name && ! empty( $block['innerBlocks'] ) ) {
			return $this->convert_blocks( $block['innerBlocks'] );
		}

		if ( 'core/media-text' === $name ) {
			return $this->convert_media_text( $block );
		}

		if (
			in_array(
				$name,
				array(
					'core/group',
					'core/columns',
					'core/column',
					'core/cover',
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

	/**
	 * Convert a block with the first registered converter that supports it.
	 *
	 * Invalid converters, exceptions, and non-string results fall through to
	 * the built-in behavior so content is not silently discarded.
	 *
	 * @param array $block Parsed block.
	 * @return string|null
	 */
	private function convert_with_custom_converters( array $block ) {
		/**
		 * Filter registered custom block Markdown converters.
		 *
		 * Converters are evaluated in array order. The first converter whose
		 * supports() method returns true and convert() returns a string wins.
		 *
		 * @since 0.5.0
		 *
		 * @param Block_Markdown_Converter[] $converters Registered converters.
		 * @param Block_Converter            $converter  Parent converter.
		 */
		$converters = (array) apply_filters( 'od_ai_content_block_converters', array(), $this );

		foreach ( $converters as $converter ) {
			if ( ! $converter instanceof Block_Markdown_Converter ) {
				continue;
			}

			try {
				if ( ! $converter->supports( $block ) ) {
					continue;
				}

				$markdown = $converter->convert( $block, $this );

				if ( is_string( $markdown ) ) {
					return $markdown;
				}
			} catch ( \Throwable $error ) {
				/**
				 * Fires when a registered block converter cannot convert a block.
				 *
				 * Built-in conversion or HTML fallback continues after this action.
				 *
				 * @since 0.5.0
				 *
				 * @param \Throwable               $error     Conversion error.
				 * @param array                    $block     Parsed block.
				 * @param Block_Markdown_Converter $converter Custom converter.
				 */
				do_action( 'od_ai_content_block_converter_error', $error, $block, $converter );
			}
		}

		return null;
	}

	/**
	 * Convert a details block while retaining its summary and hidden body.
	 *
	 * @param array $block Parsed details block.
	 * @return string
	 */
	private function convert_details( array $block ) {
		$summary = '';
		$html    = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

		if ( preg_match( '/<summary\b[^>]*>(.*?)<\/summary>/is', $html, $matches ) ) {
			$summary = trim( $this->html_converter->convert( $matches[1] ) );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$body = $this->convert_blocks( $block['innerBlocks'] );

			return trim( ( '' === $summary ? '' : $summary . "\n\n" ) . $body );
		}

		return $this->normalize_heading_levels(
			$this->html_converter->convert( render_block( $block ) )
		);
	}

	/**
	 * Convert an embed block to an explicit link and optional caption.
	 *
	 * @param array $block Parsed embed block.
	 * @return string
	 */
	private function convert_embed( array $block ) {
		$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$url        = isset( $attributes['url'] ) ? $this->normalize_url( $attributes['url'] ) : '';

		if ( '' === $url ) {
			return $this->html_converter->convert( render_block( $block ) );
		}

		$markdown = '[' . __( 'Embedded content', 'od-ai-content' ) . '](' . $url . ')';
		$html     = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

		if ( preg_match( '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $html, $matches ) ) {
			$caption = trim( $this->html_converter->convert( $matches[1] ) );

			if ( '' !== $caption ) {
				$markdown .= "\n\n*" . $caption . '*';
			}
		}

		return $markdown;
	}

	/**
	 * Convert a media-text block while retaining media and nested content.
	 *
	 * @param array $block Parsed media-text block.
	 * @return string
	 */
	private function convert_media_text( array $block ) {
		$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$media_url  = isset( $attributes['mediaUrl'] ) ? (string) $attributes['mediaUrl'] : '';
		$media_alt  = isset( $attributes['mediaAlt'] ) ? (string) $attributes['mediaAlt'] : '';
		$media_type = isset( $attributes['mediaType'] ) ? (string) $attributes['mediaType'] : 'image';

		if ( '' === $media_url && ! empty( $attributes['mediaId'] ) ) {
			$media_url = (string) wp_get_attachment_url( (int) $attributes['mediaId'] );
			$media_alt = (string) get_post_meta( (int) $attributes['mediaId'], '_wp_attachment_image_alt', true );
		}

		$media   = '';
		$content = empty( $block['innerBlocks'] ) ? '' : $this->convert_blocks( $block['innerBlocks'] );

		if ( '' !== $media_url ) {
			$media_url = $this->normalize_url( $media_url );

			if ( 'image' === $media_type ) {
				$media = '![' . $this->escape_link_text( $media_alt ) . '](' . $media_url . ')';
			} else {
				$media = '[' . __( 'Media', 'od-ai-content' ) . '](' . $media_url . ')';
			}
		}

		if ( '' === $media ) {
			return '' === $content
				? $this->normalize_heading_levels( $this->html_converter->convert( render_block( $block ) ) )
				: $content;
		}

		$fragments = 'right' === ( isset( $attributes['mediaPosition'] ) ? $attributes['mediaPosition'] : '' )
			? array( $content, $media )
			: array( $media, $content );

		return implode( "\n\n", array_filter( $fragments, 'strlen' ) );
	}

	/**
	 * Prevent nested rendered content from creating a second document H1.
	 *
	 * @param string $markdown Markdown fragment.
	 * @return string
	 */
	private function normalize_heading_levels( $markdown ) {
		return (string) preg_replace( '/^#\s+/m', '## ', (string) $markdown );
	}

	/**
	 * Normalize a rendered block URL.
	 *
	 * @param string $url URL value.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return home_url( $url );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Escape a Markdown link label.
	 *
	 * @param string $text Label value.
	 * @return string
	 */
	private function escape_link_text( $text ) {
		return str_replace(
			array( '\\', '[', ']' ),
			array( '\\\\', '\[', '\]' ),
			wp_strip_all_tags( (string) $text )
		);
	}
}
