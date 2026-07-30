<?php
/**
 * Content resolver integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Content_Resolver;

/**
 * Tests public content eligibility.
 */
class ContentResolverTest extends WP_UnitTestCase {

	/**
	 * Public posts and pages are eligible.
	 *
	 * @return void
	 */
	public function test_published_posts_and_pages_are_eligible() {
		$resolver = new Content_Resolver();
		$post     = get_post(
			self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => 'post',
				)
			)
		);
		$page     = get_post(
			self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => 'page',
				)
			)
		);

		$this->assertTrue( $resolver->is_eligible( $post ) );
		$this->assertTrue( $resolver->is_eligible( $page ) );
	}

	/**
	 * Non-public and password-protected content is not eligible.
	 *
	 * @return void
	 */
	public function test_restricted_content_is_not_eligible() {
		$resolver = new Content_Resolver();
		$draft    = get_post(
			self::factory()->post->create(
				array(
					'post_status' => 'draft',
				)
			)
		);
		$private  = get_post(
			self::factory()->post->create(
				array(
					'post_status' => 'private',
				)
			)
		);
		$password = get_post(
			self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_password' => 'secret',
				)
			)
		);

		$this->assertFalse( $resolver->is_eligible( $draft ) );
		$this->assertFalse( $resolver->is_eligible( $private ) );
		$this->assertFalse( $resolver->is_eligible( $password ) );
	}

	/**
	 * Post types can be extended through the public filter.
	 *
	 * @return void
	 */
	public function test_post_types_are_filterable() {
		register_post_type(
			'book',
			array(
				'public' => true,
			)
		);

		$post = get_post(
			self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => 'book',
				)
			)
		);

		$filter = static function ( $post_types ) {
			$post_types[] = 'book';
			return $post_types;
		};

		add_filter( 'od_ai_content_post_types', $filter );

		$resolver = new Content_Resolver();
		$this->assertTrue( $resolver->is_eligible( $post ) );

		remove_filter( 'od_ai_content_post_types', $filter );
		unregister_post_type( 'book' );
	}
}
