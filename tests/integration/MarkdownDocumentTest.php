<?php
/**
 * Markdown document integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Block_Converter;
use Olein\OdAiContent\Html_To_Markdown;
use Olein\OdAiContent\Markdown_Document;

/**
 * Tests semantic Markdown document generation.
 */
class MarkdownDocumentTest extends WP_UnitTestCase {

	/**
	 * Document generator.
	 *
	 * @var Markdown_Document
	 */
	private $document;

	/**
	 * Set up the converter under test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->document = new Markdown_Document(
			new Block_Converter( new Html_To_Markdown() )
		);
	}

	/**
	 * Document schema version is explicit for cache invalidation.
	 *
	 * @return void
	 */
	public function test_document_schema_version_is_defined() {
		$this->assertSame( '2', Markdown_Document::SCHEMA_VERSION );
	}

	/**
	 * Core content structures and metadata are retained.
	 *
	 * @return void
	 */
	public function test_generates_semantic_markdown_with_metadata() {
		$user_id = self::factory()->user->create(
			array(
				'display_name' => 'Test Author',
			)
		);
		$content = <<<'BLOCKS'
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Section title</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Hello <strong>world</strong>. Read <a href="/about/">about us</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>First item</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Second item</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Quoted fact.</p>
<!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Plan</th><th>Price</th></tr></thead><tbody><tr><td>Basic</td><td>100</td></tr></tbody></table></figure>
<!-- /wp:table -->

<!-- wp:code -->
<pre class="wp-block-code"><code class="language-php">&lt;?php echo 'ok';</code></pre>
<!-- /wp:code -->

<!-- wp:image -->
<figure class="wp-block-image"><img src="/image.jpg" alt="Meaningful image"/><figcaption class="wp-element-caption">Image caption</figcaption></figure>
<!-- /wp:image -->
BLOCKS;
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $user_id,
				'post_content' => $content,
				'post_excerpt' => 'A concise description.',
				'post_status'  => 'publish',
				'post_title'   => 'Document title',
			)
		);

		wp_set_post_categories(
			$post_id,
			array(
				self::factory()->category->create(
					array(
						'name' => 'WordPress',
					)
				),
			)
		);

		$markdown = $this->document->generate( get_post( $post_id ) );

		$this->assertStringContainsString( 'title: "Document title"', $markdown );
		$this->assertStringContainsString( 'author: "Test Author"', $markdown );
		$this->assertStringContainsString( 'description: "A concise description."', $markdown );
		$this->assertStringContainsString( 'category:', $markdown );
		$this->assertStringContainsString( '- "WordPress"', $markdown );
		$this->assertStringContainsString( '# Document title', $markdown );
		$this->assertStringContainsString( '## Section title', $markdown );
		$this->assertStringNotContainsString( "\n# Section title", $markdown );
		$this->assertStringContainsString( 'Hello **world**.', $markdown );
		$this->assertStringContainsString( '[about us](' . home_url( '/about/' ) . ')', $markdown );
		$this->assertStringContainsString( '- First item', $markdown );
		$this->assertStringContainsString( '> Quoted fact.', $markdown );
		$this->assertStringContainsString( '| Plan | Price |', $markdown );
		$this->assertStringContainsString( "```php\n<?php echo 'ok';\n```", $markdown );
		$this->assertStringContainsString( '![Meaningful image](' . home_url( '/image.jpg' ) . ')', $markdown );
	}

	/**
	 * The filtered post title is evaluated once and reused throughout a document.
	 *
	 * @return void
	 */
	public function test_reuses_one_filtered_title_for_metadata_and_h1() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>本文です。</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => '元のタイトル',
			)
		);
		$calls   = 0;
		$filter  = static function ( $title ) use ( &$calls ) {
			++$calls;

			return 1 === $calls ? '日本語の診断タイトル' : $title . '（再評価）';
		};

		add_filter( 'the_title', $filter );
		$markdown = $this->document->generate( get_post( $post_id ) );
		remove_filter( 'the_title', $filter );

		$this->assertSame( 1, $calls );
		$this->assertStringContainsString( 'title: "日本語の診断タイトル"', $markdown );
		$this->assertStringContainsString( '# 日本語の診断タイトル', $markdown );
		$this->assertStringNotContainsString( '（再評価）', $markdown );
	}

	/**
	 * Known navigation and decorative blocks are omitted.
	 *
	 * @return void
	 */
	public function test_omits_known_noise_blocks() {
		// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInText -- WordPress-generated CSS classes are lowercase.
		$content = <<<'BLOCKS'
<!-- wp:paragraph -->
<p>Primary content.</p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"100px"} -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:social-links -->
<ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://example.com","service":"wordpress"} /--></ul>
<!-- /wp:social-links -->
BLOCKS;
		// phpcs:enable WordPress.WP.CapitalPDangit.MisspelledInText
		$post_id = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_title'   => 'Noise test',
			)
		);

		$markdown = $this->document->generate( get_post( $post_id ) );

		$this->assertStringContainsString( 'Primary content.', $markdown );
		$this->assertStringNotContainsString( 'example.com', $markdown );
		$this->assertStringNotContainsString( 'height:100px', $markdown );
	}

	/**
	 * Custom block converters can override built-in behavior.
	 *
	 * @return void
	 */
	public function test_block_conversion_is_extensible() {
		$filter = static function ( $markdown, $block ) {
			if ( 'core/paragraph' === $block['blockName'] ) {
				return 'Custom paragraph.';
			}

			return $markdown;
		};

		add_filter( 'od_ai_content_block_markdown', $filter, 10, 2 );

		$post_id  = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Original paragraph.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'Filter test',
			)
		);
		$markdown = $this->document->generate( get_post( $post_id ) );

		remove_filter( 'od_ai_content_block_markdown', $filter );

		$this->assertStringContainsString( 'Custom paragraph.', $markdown );
		$this->assertStringNotContainsString( 'Original paragraph.', $markdown );
	}
}
