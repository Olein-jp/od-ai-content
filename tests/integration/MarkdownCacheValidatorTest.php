<?php
/**
 * Markdown HTTP cache validation integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Block_Converter;
use Olein\OdAiContent\Html_To_Markdown;
use Olein\OdAiContent\Markdown_Cache_Validator;
use Olein\OdAiContent\Markdown_Document;

/**
 * Tests stable validators and conditional request evaluation.
 */
class MarkdownCacheValidatorTest extends WP_UnitTestCase {

	/**
	 * Cache validator.
	 *
	 * @var Markdown_Cache_Validator
	 */
	private $validator;

	/**
	 * Document generator.
	 *
	 * @var Markdown_Document
	 */
	private $document;

	/**
	 * Set up production cache and document services.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->validator = new Markdown_Cache_Validator();
		$this->document  = new Markdown_Document(
			new Block_Converter( new Html_To_Markdown() )
		);
	}

	/**
	 * Identical representations receive stable ETag and Last-Modified values.
	 *
	 * @return void
	 */
	public function test_builds_stable_validation_headers() {
		$post     = $this->create_post();
		$markdown = $this->document->generate( $post );
		$first    = $this->validator->get_headers( $post, $markdown );
		$second   = $this->validator->get_headers( $post, $markdown );

		$this->assertSame( $first, $second );
		$this->assertMatchesRegularExpression( '/^"[a-f0-9]{64}"$/', $first['ETag'] );
		$this->assertSame(
			gmdate( 'D, d M Y H:i:s', (int) get_post_modified_time( 'U', true, $post ) ) . ' GMT',
			$first['Last-Modified']
		);
	}

	/**
	 * Updating a post changes the representation ETag.
	 *
	 * @return void
	 */
	public function test_post_update_changes_etag() {
		$post     = $this->create_post();
		$markdown = $this->document->generate( $post );
		$before   = $this->validator->get_headers( $post, $markdown );

		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => '<!-- wp:paragraph --><p>Updated body.</p><!-- /wp:paragraph -->',
			)
		);

		$updated_post     = get_post( $post->ID );
		$updated_markdown = $this->document->generate( $updated_post );
		$after            = $this->validator->get_headers( $updated_post, $updated_markdown );

		$this->assertNotSame( $before['ETag'], $after['ETag'] );
		$this->assertStringContainsString( 'Updated body.', $updated_markdown );
	}

	/**
	 * If-None-Match supports lists, weak validators, and precedence.
	 *
	 * @return void
	 */
	public function test_if_none_match_is_evaluated_safely_and_takes_precedence() {
		$headers = array(
			'ETag'          => '"current"',
			'Last-Modified' => 'Wed, 29 Jul 2026 00:00:00 GMT',
		);

		$this->assertTrue(
			$this->validator->is_not_modified(
				$headers,
				array( 'HTTP_IF_NONE_MATCH' => '"old", W/"current"' )
			)
		);
		$this->assertTrue(
			$this->validator->is_not_modified(
				$headers,
				array( 'HTTP_IF_NONE_MATCH' => '*' )
			)
		);
		$this->assertFalse(
			$this->validator->is_not_modified(
				$headers,
				array(
					'HTTP_IF_MODIFIED_SINCE' => 'Thu, 30 Jul 2026 00:00:00 GMT',
					'HTTP_IF_NONE_MATCH'     => '"different"',
				)
			)
		);
	}

	/**
	 * If-Modified-Since is used only when an ETag condition is absent.
	 *
	 * @return void
	 */
	public function test_if_modified_since_evaluation() {
		$headers = array(
			'ETag'          => '"current"',
			'Last-Modified' => 'Wed, 29 Jul 2026 00:00:00 GMT',
		);

		$this->assertTrue(
			$this->validator->is_not_modified(
				$headers,
				array( 'HTTP_IF_MODIFIED_SINCE' => 'Wed, 29 Jul 2026 00:00:00 GMT' )
			)
		);
		$this->assertFalse(
			$this->validator->is_not_modified(
				$headers,
				array( 'HTTP_IF_MODIFIED_SINCE' => 'Tue, 28 Jul 2026 00:00:00 GMT' )
			)
		);
		$this->assertFalse(
			$this->validator->is_not_modified(
				$headers,
				array( 'HTTP_IF_MODIFIED_SINCE' => 'not-a-date' )
			)
		);
	}

	/**
	 * Cache-key and response-header filters receive the documented context.
	 *
	 * @return void
	 */
	public function test_cache_key_and_headers_are_filterable() {
		$post            = $this->create_post();
		$markdown        = $this->document->generate( $post );
		$captured_schema = null;
		$key_filter      = static function ( $cache_key ) use ( &$captured_schema ) {
			$captured_schema = $cache_key['schema_version'];
			return 'custom-key';
		};
		$headers_filter  = static function ( $headers ) {
			$headers['ETag'] = '"custom-etag"';
			return $headers;
		};

		add_filter( 'od_ai_content_markdown_cache_key', $key_filter );
		add_filter( 'od_ai_content_markdown_cache_headers', $headers_filter );

		$headers = $this->validator->get_headers( $post, $markdown );

		remove_filter( 'od_ai_content_markdown_cache_key', $key_filter );
		remove_filter( 'od_ai_content_markdown_cache_headers', $headers_filter );

		$this->assertSame( Markdown_Document::SCHEMA_VERSION, $captured_schema );
		$this->assertSame( '"custom-etag"', $headers['ETag'] );
	}

	/**
	 * Create a published post for validator tests.
	 *
	 * @return WP_Post
	 */
	private function create_post() {
		return get_post(
			self::factory()->post->create(
				array(
					'post_content' => '<!-- wp:paragraph --><p>Original body.</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
					'post_title'   => 'Cache test',
				)
			)
		);
	}
}
