<?php
/**
 * Small HTML-to-Markdown fallback converter.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Converts rendered block HTML without carrying layout-only markup into Markdown.
 */
final class Html_To_Markdown {

	/**
	 * Convert HTML to normalized Markdown.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public function convert( $html ) {
		$html = trim( (string) $html );

		if ( '' === $html ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->normalize( wp_strip_all_tags( $html ) );
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="utf-8" ?><body>' . $html . '</body>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $this->normalize( wp_strip_all_tags( $html ) );
		}

		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body ) {
			return $this->normalize( wp_strip_all_tags( $html ) );
		}

		return $this->normalize( $this->convert_children( $body ) );
	}

	/**
	 * Convert all child nodes.
	 *
	 * @param DOMNode $node    Parent node.
	 * @param string  $context Conversion context.
	 * @return string
	 */
	private function convert_children( DOMNode $node, $context = '' ) {
		$markdown = '';

		foreach ( $node->childNodes as $child ) {
			$markdown .= $this->convert_node( $child, $context );
		}

		return $markdown;
	}

	/**
	 * Convert one DOM node.
	 *
	 * @param DOMNode $node    Node to convert.
	 * @param string  $context Conversion context.
	 * @return string
	 */
	private function convert_node( DOMNode $node, $context = '' ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = (string) $node->nodeValue;

			return 'pre' === $context ? $text : preg_replace( '/\s+/u', ' ', $text );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType || ! $node instanceof DOMElement ) {
			return '';
		}

		$tag     = strtolower( $node->tagName );
		$content = $this->convert_children( $node, $context );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return str_repeat( '#', (int) substr( $tag, 1 ) ) . ' ' . trim( $content ) . "\n\n";

			case 'p':
				return trim( $content ) . "\n\n";

			case 'br':
				return "  \n";

			case 'strong':
			case 'b':
				return '**' . trim( $content ) . '**';

			case 'em':
			case 'i':
				return '*' . trim( $content ) . '*';

			case 'del':
			case 's':
				return '~~' . trim( $content ) . '~~';

			case 'a':
				return $this->convert_link( $node, $content );

			case 'img':
				return $this->convert_image( $node );

			case 'ul':
			case 'ol':
				return $this->convert_list( $node, 'ol' === $tag );

			case 'blockquote':
				return $this->prefix_lines( trim( $content ), '> ' ) . "\n\n";

			case 'pre':
				return $this->convert_preformatted( $node );

			case 'code':
				if ( 'pre' === $context || ( $node->parentNode && 'pre' === strtolower( $node->parentNode->nodeName ) ) ) {
					return (string) $node->textContent;
				}

				return '`' . str_replace( '`', '\`', trim( (string) $node->textContent ) ) . '`';

			case 'table':
				return $this->convert_table( $node );

			case 'hr':
				return "---\n\n";

			case 'details':
				return $this->convert_details( $node );

			case 'iframe':
				$source = $node->getAttribute( 'src' );
				return '' === $source ? '' : '[' . esc_html__( 'Embedded content', 'od-ai-content' ) . '](' . $this->normalize_url( $source ) . ")\n\n";

			case 'figcaption':
				return '*' . trim( $content ) . "*\n\n";

			case 'script':
			case 'style':
			case 'noscript':
			case 'svg':
				return '';

			default:
				return $content;
		}
	}

	/**
	 * Convert an anchor element.
	 *
	 * @param DOMElement $node    Anchor element.
	 * @param string     $content Converted label.
	 * @return string
	 */
	private function convert_link( DOMElement $node, $content ) {
		$href  = $node->getAttribute( 'href' );
		$label = trim( $content );

		if ( '' === $href ) {
			return $label;
		}

		if ( '' === $label ) {
			$label = $href;
		}

		return '[' . str_replace( array( '[', ']' ), array( '\[', '\]' ), $label ) . '](' . $this->normalize_url( $href ) . ')';
	}

	/**
	 * Convert an image element.
	 *
	 * @param DOMElement $node Image element.
	 * @return string
	 */
	private function convert_image( DOMElement $node ) {
		$source = $node->getAttribute( 'src' );

		if ( '' === $source ) {
			return '';
		}

		$alt = str_replace( array( '[', ']' ), array( '\[', '\]' ), $node->getAttribute( 'alt' ) );

		return '![' . $alt . '](' . $this->normalize_url( $source ) . ')';
	}

	/**
	 * Convert an ordered or unordered list.
	 *
	 * @param DOMElement $list_element List element.
	 * @param bool       $ordered Whether the list is ordered.
	 * @return string
	 */
	private function convert_list( DOMElement $list_element, $ordered ) {
		$lines = array();
		$index = 1;

		foreach ( $list_element->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$item = trim( $this->convert_children( $child ) );
			$item = preg_replace( "/\n{2,}/", "\n", $item );

			if ( '' === $item ) {
				continue;
			}

			$prefix  = $ordered ? $index . '. ' : '- ';
			$lines[] = $prefix . str_replace( "\n", "\n  ", $item );
			++$index;
		}

		return empty( $lines ) ? '' : implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Convert a preformatted code block.
	 *
	 * @param DOMElement $node Pre element.
	 * @return string
	 */
	private function convert_preformatted( DOMElement $node ) {
		$code     = rtrim( (string) $node->textContent );
		$language = '';
		$codes    = $node->getElementsByTagName( 'code' );

		if ( $codes->length > 0 ) {
			$class = $codes->item( 0 )->getAttribute( 'class' );

			if ( preg_match( '/(?:^|\s)language-([a-z0-9_-]+)/i', $class, $matches ) ) {
				$language = $matches[1];
			}
		}

		$fence = false !== strpos( $code, '```' ) ? '````' : '```';

		return $fence . $language . "\n" . $code . "\n" . $fence . "\n\n";
	}

	/**
	 * Convert a table to pipe-table Markdown.
	 *
	 * @param DOMElement $table Table element.
	 * @return string
	 */
	private function convert_table( DOMElement $table ) {
		$rows = array();

		foreach ( $table->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();

			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement || ! in_array( strtolower( $cell->tagName ), array( 'th', 'td' ), true ) ) {
					continue;
				}

				$value   = $this->normalize( $this->convert_children( $cell ) );
				$cells[] = str_replace( '|', '\|', str_replace( "\n", '<br>', $value ) );
			}

			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$columns  = max( array_map( 'count', $rows ) );
		$markdown = array();

		foreach ( $rows as $row ) {
			$row        = array_pad( $row, $columns, '' );
			$markdown[] = '| ' . implode( ' | ', $row ) . ' |';
		}

		array_splice( $markdown, 1, 0, '| ' . implode( ' | ', array_fill( 0, $columns, '---' ) ) . ' |' );

		return implode( "\n", $markdown ) . "\n\n";
	}

	/**
	 * Convert a details element while retaining hidden content.
	 *
	 * @param DOMElement $details Details element.
	 * @return string
	 */
	private function convert_details( DOMElement $details ) {
		$summary = '';
		$body    = '';

		foreach ( $details->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'summary' === strtolower( $child->tagName ) ) {
				$summary = trim( $this->convert_children( $child ) );
				continue;
			}

			$body .= $this->convert_node( $child );
		}

		$markdown = '' === $summary ? '' : '**' . $summary . "**\n\n";

		return $markdown . trim( $body ) . "\n\n";
	}

	/**
	 * Prefix every non-empty line.
	 *
	 * @param string $text   Text to prefix.
	 * @param string $prefix Prefix.
	 * @return string
	 */
	private function prefix_lines( $text, $prefix ) {
		$lines = explode( "\n", $text );

		foreach ( $lines as &$line ) {
			$line = '' === trim( $line ) ? '>' : $prefix . $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Convert root-relative URLs to absolute URLs.
	 *
	 * @param string $url URL from HTML.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return home_url( $url );
		}

		return $url;
	}

	/**
	 * Normalize blank lines and surrounding whitespace.
	 *
	 * @param string $markdown Markdown fragment.
	 * @return string
	 */
	private function normalize( $markdown ) {
		$markdown = preg_replace( "/[ \t]+\n/", "\n", (string) $markdown );
		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );

		return trim( $markdown );
	}
}
