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
	 * Block names intentionally omitted from Markdown by default.
	 *
	 * @var string[]
	 */
	const EXCLUDED_BLOCKS = array(
		'core/spacer',
		'core/navigation',
		'core/social-links',
		'core/query',
	);

	/**
	 * Block names whose rendered HTML conversion is an established path.
	 *
	 * @var string[]
	 */
	const VERIFIED_HTML_BLOCKS = array(
		'core/button',
		'core/code',
		'core/heading',
		'core/image',
		'core/list',
		'core/list-item',
		'core/paragraph',
		'core/quote',
		'core/table',
	);

	/**
	 * HTML fallback converter.
	 *
	 * @var Html_To_Markdown
	 */
	private $html_converter;

	/**
	 * Active conversion report, or null when reporting is disabled.
	 *
	 * @var array|null
	 */
	private $active_report;

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
	 * Convert blocks and report exclusions and unverified HTML fallbacks.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return array{markdown:string,excluded_blocks:string[],fallback_blocks:string[]}
	 */
	public function convert_blocks_with_report( array $blocks ) {
		$previous_report     = $this->active_report;
		$this->active_report = array(
			'excluded_blocks' => array(),
			'fallback_blocks' => array(),
		);

		$markdown = $this->convert_blocks( $blocks );
		$report   = $this->active_report;

		$this->active_report = $previous_report;

		foreach ( $report as $key => $names ) {
			$names          = array_values( array_unique( $names ) );
			$report[ $key ] = $names;
		}

		return array(
			'markdown'        => $markdown,
			'excluded_blocks' => $report['excluded_blocks'],
			'fallback_blocks' => $report['fallback_blocks'],
		);
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

		if ( in_array( $name, $this->get_excluded_block_names(), true ) ) {
			$this->record_block( 'excluded_blocks', $name );
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

		if ( '' !== trim( $markdown ) && ! in_array( $name, $this->get_verified_html_block_names(), true ) ) {
			$this->record_block( 'fallback_blocks', '' === $name ? 'unregistered' : $name );
		}

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
	 * Add a block name to the active conversion report.
	 *
	 * @param string $key  Report collection key.
	 * @param string $name Block name.
	 * @return void
	 */
	private function record_block( $key, $name ) {
		if ( null === $this->active_report || ! isset( $this->active_report[ $key ] ) ) {
			return;
		}

		$this->active_report[ $key ][] = (string) $name;
	}

	/**
	 * Return normalized block names that should be intentionally omitted.
	 *
	 * Custom converters run before this registry, so an explicitly registered
	 * converter can still retain Markdown for an excluded block.
	 *
	 * @since 0.5.0
	 *
	 * @return string[]
	 */
	private function get_excluded_block_names() {
		/**
		 * Filter block names that are intentionally omitted from Markdown.
		 *
		 * Registered names produce no Markdown and are reported as informational
		 * exclusions instead of unverified HTML fallback warnings.
		 *
		 * @since 0.5.0
		 *
		 * @param string[]       $block_names Default excluded block names.
		 * @param Block_Converter $converter  Converter instance.
		 */
		$block_names = apply_filters( 'od_ai_content_excluded_block_names', self::EXCLUDED_BLOCKS, $this );

		return $this->normalize_block_names( $block_names );
	}

	/**
	 * Return normalized block names whose HTML fallback has been verified.
	 *
	 * @since 0.5.0
	 *
	 * @return string[]
	 */
	private function get_verified_html_block_names() {
		/**
		 * Filter block names whose rendered HTML fallback has been verified.
		 *
		 * Registered names retain their converted Markdown without producing an
		 * unverified HTML fallback warning.
		 *
		 * @since 0.5.0
		 *
		 * @param string[]        $block_names Verified block names.
		 * @param Block_Converter $converter   Converter instance.
		 */
		$block_names = apply_filters( 'od_ai_content_verified_html_blocks', self::VERIFIED_HTML_BLOCKS, $this );

		return $this->normalize_block_names( $block_names );
	}

	/**
	 * Normalize a public block name registry.
	 *
	 * @param mixed $block_names Candidate block names.
	 * @return string[]
	 */
	private function normalize_block_names( $block_names ) {
		$normalized = array();

		foreach ( (array) $block_names as $block_name ) {
			if ( ! is_string( $block_name ) ) {
				continue;
			}

			$block_name = trim( $block_name );

			if ( ! preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $block_name ) ) {
				continue;
			}

			$normalized[] = $block_name;
		}

		return array_values( array_unique( $normalized ) );
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
