<?php
/**
 * Block converter integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Block_Converter;
use Olein\OdAiContent\Block_Markdown_Converter;
use Olein\OdAiContent\Html_To_Markdown;

/**
 * Tests semantic core block conversion and the public converter API.
 */
class BlockConverterTest extends WP_UnitTestCase {

	/**
	 * Block converter.
	 *
	 * @var Block_Converter
	 */
	private $converter;

	/**
	 * Set up the production converter.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->converter = new Block_Converter( new Html_To_Markdown() );
	}

	/**
	 * Details retain summary, hidden body, links, and nested headings.
	 *
	 * @return void
	 */
	public function test_details_retains_all_content() {
		$content = <<<'BLOCKS'
<!-- wp:details -->
<details class="wp-block-details"><summary>More <strong>information</strong></summary><!-- wp:paragraph -->
<p>Hidden <a href="/details/">details link</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Hidden heading</h1>
<!-- /wp:heading --></details>
<!-- /wp:details -->
BLOCKS;

		$markdown = $this->converter->convert_blocks( parse_blocks( $content ) );

		$this->assertStringContainsString( 'More **information**', $markdown );
		$this->assertStringContainsString( '[details link](' . home_url( '/details/' ) . ')', $markdown );
		$this->assertStringContainsString( '## Hidden heading', $markdown );
		$this->assertStringNotContainsString( "\n# Hidden heading", $markdown );
	}

	/**
	 * Embed retains its canonical URL and caption.
	 *
	 * @return void
	 */
	public function test_embed_becomes_an_explicit_link() {
		$content = <<<'BLOCKS'
<!-- wp:embed {"url":"https://example.com/video","type":"video","providerNameSlug":"example"} -->
<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">
https://example.com/video
</div><figcaption class="wp-element-caption">Video caption</figcaption></figure>
<!-- /wp:embed -->
BLOCKS;

		$markdown = $this->converter->convert_blocks( parse_blocks( $content ) );

		$this->assertStringContainsString( '[Embedded content](https://example.com/video)', $markdown );
		$this->assertStringContainsString( '*Video caption*', $markdown );
	}

	/**
	 * Buttons retain each label and destination.
	 *
	 * @return void
	 */
	public function test_buttons_retain_links() {
		$content = <<<'BLOCKS'
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/first/">First action</a></div>
<!-- /wp:button -->

<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/second">Second action</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
BLOCKS;

		$markdown = $this->converter->convert_blocks( parse_blocks( $content ) );

		$this->assertStringContainsString( '[First action](' . home_url( '/first/' ) . ')', $markdown );
		$this->assertStringContainsString( '[Second action](https://example.com/second)', $markdown );
	}

	/**
	 * Media-text retains semantic order, image, text, and heading hierarchy.
	 *
	 * @return void
	 */
	public function test_media_text_retains_media_and_nested_content() {
		$content = <<<'BLOCKS'
<!-- wp:media-text {"mediaPosition":"right","mediaUrl":"/media.jpg","mediaType":"image","mediaAlt":"Meaningful media"} -->
<div class="wp-block-media-text has-media-on-the-right"><div class="wp-block-media-text__content"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Media heading</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Media <a href="/context/">context</a>.</p>
<!-- /wp:paragraph --></div><figure class="wp-block-media-text__media"><img src="/media.jpg" alt="Meaningful media"/></figure></div>
<!-- /wp:media-text -->
BLOCKS;

		$markdown = $this->converter->convert_blocks( parse_blocks( $content ) );

		$this->assertStringContainsString( '## Media heading', $markdown );
		$this->assertStringContainsString( '[context](' . home_url( '/context/' ) . ')', $markdown );
		$this->assertStringContainsString( '![Meaningful media](' . home_url( '/media.jpg' ) . ')', $markdown );
		$this->assertLessThan(
			strpos( $markdown, '![Meaningful media]' ),
			strpos( $markdown, '## Media heading' )
		);
	}

	/**
	 * Nested lists preserve item order and nesting depth.
	 *
	 * @return void
	 */
	public function test_nested_lists_preserve_order_and_indentation() {
		$content = <<<'BLOCKS'
<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Parent<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Child one</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Child two<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>Grandchild</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list --></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
BLOCKS;

		$markdown = $this->converter->convert_blocks( parse_blocks( $content ) );

		$this->assertStringContainsString(
			"- Parent\n  - Child one\n  - Child two\n    1. Grandchild",
			$markdown
		);
	}

	/**
	 * Query is excluded by default but may be explicitly handled.
	 *
	 * @return void
	 */
	public function test_query_default_can_be_overridden_by_custom_converter() {
		$content = '<!-- wp:query {"queryId":1} --><div class="wp-block-query">Query content</div><!-- /wp:query -->';
		$blocks  = parse_blocks( $content );

		$this->assertSame( '', $this->converter->convert_blocks( $blocks ) );

			$custom = new class() implements Block_Markdown_Converter {
				/**
				 * Handle query blocks.
				 *
				 * @param array $block Parsed block.
				 * @return bool
				 */
				public function supports( array $block ) {
					return 'core/query' === $block['blockName'];
				}

				/**
				 * Convert the query block.
				 *
				 * @param array           $block     Parsed block.
				 * @param Block_Converter $converter Parent converter.
				 * @return string
				 */
				public function convert( array $block, Block_Converter $converter ) {
					unset( $block, $converter );
					return 'Selected query results.';
				}
			};
		$filter     = static function ( $converters ) use ( $custom ) {
			$converters[] = $custom;
			return $converters;
		};

		add_filter( 'od_ai_content_block_converters', $filter );
		$markdown = $this->converter->convert_blocks( $blocks );
		remove_filter( 'od_ai_content_block_converters', $filter );

		$this->assertSame( 'Selected query results.', $markdown );
	}

	/**
	 * Custom converters can replace a block and recursively convert children.
	 *
	 * @return void
	 */
	public function test_custom_converter_can_convert_nested_content() {
		$content    = <<<'BLOCKS'
<!-- wp:example/card -->
<section class="example-card"><!-- wp:paragraph -->
<p>Card body.</p>
<!-- /wp:paragraph --></section>
<!-- /wp:example/card -->
BLOCKS;
			$custom = new class() implements Block_Markdown_Converter {
				/**
				 * Handle example card blocks.
				 *
				 * @param array $block Parsed block.
				 * @return bool
				 */
				public function supports( array $block ) {
					return 'example/card' === $block['blockName'];
				}

				/**
				 * Convert the card and its children.
				 *
				 * @param array           $block     Parsed block.
				 * @param Block_Converter $converter Parent converter.
				 * @return string
				 */
				public function convert( array $block, Block_Converter $converter ) {
					return "### Custom card\n\n" . $converter->convert_blocks( $block['innerBlocks'] );
				}
			};
		$filter     = static function ( $converters ) use ( $custom ) {
			$converters[] = $custom;
			return $converters;
		};

		add_filter( 'od_ai_content_block_converters', $filter );
		$markdown = $this->converter->convert_blocks( parse_blocks( $content ) );
		remove_filter( 'od_ai_content_block_converters', $filter );

		$this->assertStringContainsString( '### Custom card', $markdown );
		$this->assertStringContainsString( 'Card body.', $markdown );
	}

	/**
	 * Unsupported and failed custom conversion retains the HTML fallback.
	 *
	 * @return void
	 */
	public function test_failed_custom_converter_falls_back_to_rendered_html() {
		$content    = '<!-- wp:example/fallback --><p>Fallback <a href="/kept/">content</a>.</p><!-- /wp:example/fallback -->';
			$custom = new class() implements Block_Markdown_Converter {
				/**
				 * Handle the fallback test block.
				 *
				 * @param array $block Parsed block.
				 * @return bool
				 */
				public function supports( array $block ) {
					return 'example/fallback' === $block['blockName'];
				}

				/**
				 * Simulate a failed conversion.
				 *
				 * @param array           $block     Parsed block.
				 * @param Block_Converter $converter Parent converter.
				 * @return void
				 * @throws RuntimeException Always thrown for this test.
				 */
				public function convert( array $block, Block_Converter $converter ) {
					unset( $block, $converter );
					throw new RuntimeException( 'Expected test failure.' );
				}
			};
		$filter     = static function ( $converters ) use ( $custom ) {
			$converters[] = $custom;
			return $converters;
		};

		add_filter( 'od_ai_content_block_converters', $filter );
		$markdown = $this->converter->convert_blocks( parse_blocks( $content ) );
		remove_filter( 'od_ai_content_block_converters', $filter );

		$this->assertStringContainsString( 'Fallback', $markdown );
		$this->assertStringContainsString( '[content](' . home_url( '/kept/' ) . ')', $markdown );
	}

	/**
	 * Integrations can intentionally omit a block without a fallback warning.
	 *
	 * @return void
	 */
	public function test_registered_exclusion_is_informational() {
		$content = '<!-- wp:example/card --><section><p>Omitted card.</p></section><!-- /wp:example/card -->';
		$filter  = static function ( $block_names ) {
			$block_names[] = '';
			$block_names[] = 'invalid';
			$block_names[] = 'example/card';
			$block_names[] = 'example/card';

			return $block_names;
		};

		add_filter( 'od_ai_content_excluded_block_names', $filter );
		$report = $this->converter->convert_blocks_with_report( parse_blocks( $content ) );
		remove_filter( 'od_ai_content_excluded_block_names', $filter );

		$this->assertSame( '', $report['markdown'] );
		$this->assertSame( array( 'example/card' ), $report['excluded_blocks'] );
		$this->assertSame( array(), $report['fallback_blocks'] );
	}

	/**
	 * Integrations can acknowledge a verified HTML fallback.
	 *
	 * @return void
	 */
	public function test_verified_html_fallback_retains_content_without_warning() {
		$content = '<!-- wp:example/card --><section><p>Verified card.</p></section><!-- /wp:example/card -->';
		$filter  = static function ( $block_names ) {
			$block_names[] = 'example/card';

			return $block_names;
		};

		add_filter( 'od_ai_content_verified_html_blocks', $filter );
		$report = $this->converter->convert_blocks_with_report( parse_blocks( $content ) );
		remove_filter( 'od_ai_content_verified_html_blocks', $filter );

		$this->assertStringContainsString( 'Verified card.', $report['markdown'] );
		$this->assertSame( array(), $report['excluded_blocks'] );
		$this->assertSame( array(), $report['fallback_blocks'] );
	}

	/**
	 * Unknown HTML fallbacks continue to produce a warning report.
	 *
	 * @return void
	 */
	public function test_unknown_html_fallback_remains_reported() {
		$content = '<!-- wp:example/card --><section><p>Unknown card.</p></section><!-- /wp:example/card -->';
		$report  = $this->converter->convert_blocks_with_report( parse_blocks( $content ) );

		$this->assertStringContainsString( 'Unknown card.', $report['markdown'] );
		$this->assertSame( array( 'example/card' ), $report['fallback_blocks'] );
	}

	/**
	 * A custom converter takes precedence over an exclusion registry entry.
	 *
	 * @return void
	 */
	public function test_custom_converter_takes_precedence_over_registered_exclusion() {
		$content           = '<!-- wp:example/card --><section><p>Card.</p></section><!-- /wp:example/card -->';
		$custom            = new class() implements Block_Markdown_Converter {
			/**
			 * Support the example block.
			 *
			 * @param array $block Parsed block.
			 * @return bool
			 */
			public function supports( array $block ) {
				return 'example/card' === $block['blockName'];
			}

			/**
			 * Return an explicit conversion.
			 *
			 * @param array           $block     Parsed block.
			 * @param Block_Converter $converter Parent converter.
			 * @return string
			 */
			public function convert( array $block, Block_Converter $converter ) {
				unset( $block, $converter );

				return 'Explicit card.';
			}
		};
		$converters_filter = static function ( $converters ) use ( $custom ) {
			$converters[] = $custom;

			return $converters;
		};
		$excluded_filter   = static function ( $block_names ) {
			$block_names[] = 'example/card';

			return $block_names;
		};

		add_filter( 'od_ai_content_block_converters', $converters_filter );
		add_filter( 'od_ai_content_excluded_block_names', $excluded_filter );
		$report = $this->converter->convert_blocks_with_report( parse_blocks( $content ) );
		remove_filter( 'od_ai_content_block_converters', $converters_filter );
		remove_filter( 'od_ai_content_excluded_block_names', $excluded_filter );

		$this->assertSame( 'Explicit card.', $report['markdown'] );
		$this->assertSame( array(), $report['excluded_blocks'] );
		$this->assertSame( array(), $report['fallback_blocks'] );
	}
}
