<?php
/**
 * Routing and discovery integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Content_Resolver;
use Olein\OdAiContent\Discovery;
use Olein\OdAiContent\Markdown_Url;
use Olein\OdAiContent\Response_Controller;

/**
 * Tests endpoint registration and HTML discovery.
 */
class RoutingAndDiscoveryTest extends WP_UnitTestCase {

	/**
	 * Rewrite rule routes index.html.md requests into plugin query variables.
	 *
	 * @return void
	 */
	public function test_rewrite_rule_is_registered() {
		global $wp_rewrite;

		Response_Controller::register_rewrite_rule();

		$this->assertArrayHasKey( '^(.+?)/index\.html\.md/?$', $wp_rewrite->extra_rules_top );
		$this->assertSame(
			'index.php?od_ai_content_markdown=1&od_ai_content_path=$matches[1]',
			$wp_rewrite->extra_rules_top['^(.+?)/index\.html\.md/?$']
		);
	}

	/**
	 * Eligible singular pages advertise their Markdown alternative.
	 *
	 * @return void
	 */
	public function test_singular_html_advertises_markdown_url() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Discoverable content',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$url       = new Markdown_Url();
		$discovery = new Discovery( new Content_Resolver(), $url );

		ob_start();
		$discovery->render_alternate_link();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'rel="alternate"', $output );
		$this->assertStringContainsString( 'type="text/markdown"', $output );
		$this->assertStringContainsString( esc_url( $url->get( get_post( $post_id ) ) ), $output );

		$headers = $discovery->add_alternate_header( array() );
		$this->assertArrayHasKey( 'Link', $headers );
		$this->assertStringContainsString( 'rel="alternate"', $headers['Link'] );
	}

	/**
	 * Password-protected pages do not advertise a Markdown alternative.
	 *
	 * @return void
	 */
	public function test_restricted_content_is_not_advertised() {
		$post_id = self::factory()->post->create(
			array(
				'post_password' => 'secret',
				'post_status'   => 'publish',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$discovery = new Discovery( new Content_Resolver(), new Markdown_Url() );

		ob_start();
		$discovery->render_alternate_link();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertArrayNotHasKey( 'Link', $discovery->add_alternate_header( array() ) );
	}
}
