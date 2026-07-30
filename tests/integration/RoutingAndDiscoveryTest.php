<?php
/**
 * Routing and discovery integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Content_Resolver;
use Olein\OdAiContent\Discovery;
use Olein\OdAiContent\Llms_Txt;
use Olein\OdAiContent\Llms_Txt_Controller;
use Olein\OdAiContent\Markdown_Url;
use Olein\OdAiContent\Post_Exclusion;
use Olein\OdAiContent\Response_Controller;
use Olein\OdAiContent\Settings;

/**
 * Tests endpoint registration and HTML discovery.
 */
class RoutingAndDiscoveryTest extends WP_UnitTestCase {

	/**
	 * Remove plugin settings after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

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
	 * Rewrite rule routes /llms.txt requests into the plugin query variable.
	 *
	 * @return void
	 */
	public function test_llms_txt_rewrite_rule_is_registered() {
		global $wp_rewrite;

		Llms_Txt_Controller::register_rewrite_rule();

		$this->assertArrayHasKey( '^llms\.txt$', $wp_rewrite->extra_rules_top );
		$this->assertSame(
			'index.php?od_ai_content_llms_txt=1',
			$wp_rewrite->extra_rules_top['^llms\.txt$']
		);
	}

	/**
	 * Llms.txt registers its public query variable and required Content-Type.
	 *
	 * @return void
	 */
	public function test_llms_txt_query_variable_and_content_type() {
		$settings   = new Settings();
		$resolver   = new Content_Resolver( $settings );
		$url        = new Markdown_Url();
		$document   = new Llms_Txt( $resolver, $url, $settings );
		$controller = new Llms_Txt_Controller( $document );

		$this->assertContains(
			'od_ai_content_llms_txt',
			$controller->register_query_vars( array() )
		);
		$this->assertSame(
			'text/plain; charset=UTF-8',
			$controller->get_response_headers()['Content-Type']
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

	/**
	 * Excluded content does not advertise a Markdown alternative.
	 *
	 * @return void
	 */
	public function test_excluded_content_is_not_advertised() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, Post_Exclusion::META_KEY, '1' );
		$this->go_to( get_permalink( $post_id ) );

		$discovery = new Discovery( new Content_Resolver(), new Markdown_Url() );

		ob_start();
		$discovery->render_alternate_link();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertArrayNotHasKey( 'Link', $discovery->add_alternate_header( array() ) );
	}
}
