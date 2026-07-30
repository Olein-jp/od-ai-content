<?php
/**
 * Custom block Markdown converter contract.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Defines support detection and conversion for custom block handlers.
 */
interface Block_Markdown_Converter {

	/**
	 * Determine whether this converter handles a parsed block.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	public function supports( array $block );

	/**
	 * Convert a supported block to Markdown.
	 *
	 * The parent converter may be used to convert nested inner blocks.
	 *
	 * @param array           $block     Parsed block.
	 * @param Block_Converter $converter Parent block converter.
	 * @return string
	 */
	public function convert( array $block, Block_Converter $converter );
}
