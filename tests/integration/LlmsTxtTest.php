<?php
/**
 * Llms.txt integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Content_Resolver;
use Olein\OdAiContent\Llms_Selection;
use Olein\OdAiContent\Llms_Txt;
use Olein\OdAiContent\Markdown_Url;
use Olein\OdAiContent\Post_Exclusion;
use Olein\OdAiContent\Settings;

/**
 * Tests selected-content storage and llms.txt generation.
 */
class LlmsTxtTest extends WP_UnitTestCase {

	/**
	 * Original site name.
	 *
	 * @var string
	 */
	private $site_name;

	/**
	 * Original site description.
	 *
	 * @var string
	 */
	private $site_description;

	/**
	 * Preserve site information used in generated documents.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->site_name        = get_option( 'blogname' );
		$this->site_description = get_option( 'blogdescription' );
	}

	/**
	 * Clean global and persistent state after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		update_option( 'blogname', $this->site_name );
		update_option( 'blogdescription', $this->site_description );
		delete_option( Settings::OPTION_NAME );
		unset(
			$_POST[ Llms_Selection::NONCE_NAME ],
			$_POST['od_ai_content_llms_selected'],
			$_POST['od_ai_content_llms_description']
		);
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Only explicitly selected, eligible content appears in llms.txt.
	 *
	 * @return void
	 */
	public function test_generate_includes_only_selected_eligible_content() {
		update_option( 'blogname', 'Example Site' );
		update_option( 'blogdescription', 'Useful WordPress guidance.' );

		$included_id = $this->create_post(
			array(
				'post_title'   => 'Included post',
				'post_content' => 'Included body.',
			),
			'An intentionally short description.'
		);
		$this->create_post(
			array(
				'post_title' => 'Unselected post',
			),
			'This should not be listed.',
			false
		);
		$this->create_post(
			array(
				'post_password' => 'secret',
				'post_title'    => 'Password protected',
			),
			'This should not be listed.'
		);
		$this->create_post(
			array(
				'post_status' => 'private',
				'post_title'  => 'Private post',
			),
			'This should not be listed.'
		);
		$excluded_id = $this->create_post(
			array(
				'post_title' => 'Markdown excluded',
			),
			'This should not be listed.'
		);
		update_post_meta( $excluded_id, Post_Exclusion::META_KEY, '1' );

		$output = $this->generator()->generate();

		$this->assertStringContainsString( "# Example Site\n\n> Useful WordPress guidance.", $output );
		$this->assertStringContainsString( '[Included post]', $output );
		$this->assertStringContainsString( 'An intentionally short description.', $output );
		$this->assertStringContainsString(
			( new Markdown_Url() )->get( get_post( $included_id ) ),
			$output
		);
		$this->assertStringNotContainsString( 'Unselected post', $output );
		$this->assertStringNotContainsString( 'Password protected', $output );
		$this->assertStringNotContainsString( 'Private post', $output );
		$this->assertStringNotContainsString( 'Markdown excluded', $output );
	}

	/**
	 * Published updates are reflected without a generated-file cache.
	 *
	 * @return void
	 */
	public function test_post_updates_are_reflected_in_output() {
		$post_id   = $this->create_post(
			array(
				'post_title' => 'Original title',
			),
			'Original description.'
		);
		$generator = $this->generator();

		$this->assertStringContainsString( 'Original title', $generator->generate() );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated title',
			)
		);
		update_post_meta( $post_id, Llms_Selection::DESCRIPTION_META_KEY, 'Updated description.' );

		$output = $generator->generate();

		$this->assertStringContainsString( 'Updated title', $output );
		$this->assertStringContainsString( 'Updated description.', $output );
		$this->assertStringNotContainsString( 'Original title', $output );
	}

	/**
	 * Entry and output filters can alter content and ordering.
	 *
	 * @return void
	 */
	public function test_content_and_order_are_filterable() {
		$this->create_post(
			array(
				'post_title' => 'Alpha',
			),
			'Alpha description.'
		);
		$this->create_post(
			array(
				'post_title' => 'Beta',
			),
			'Beta description.'
		);

		$entries_filter = static function ( $entries ) {
			$entries                   = array_reverse( $entries );
			$entries[0]['description'] = 'Filtered description.';
			return $entries;
		};
		$output_filter  = static function ( $output ) {
			return $output . "\n<!-- filtered -->\n";
		};

		add_filter( 'od_ai_content_llms_txt_entries', $entries_filter );
		add_filter( 'od_ai_content_llms_txt_output', $output_filter );

		$output = $this->generator()->generate();

		remove_filter( 'od_ai_content_llms_txt_entries', $entries_filter );
		remove_filter( 'od_ai_content_llms_txt_output', $output_filter );

		$this->assertLessThan( strpos( $output, '[Alpha]' ), strpos( $output, '[Beta]' ) );
		$this->assertStringContainsString( 'Filtered description.', $output );
		$this->assertStringContainsString( '<!-- filtered -->', $output );
	}

	/**
	 * Authorized editors can save selection and description metadata.
	 *
	 * @return void
	 */
	public function test_authorized_user_can_save_selection() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create(
			array(
				'post_author' => $administrator,
			)
		);
		$post          = get_post( $post_id );

		wp_set_current_user( $administrator );

		$_POST[ Llms_Selection::NONCE_NAME ]     = wp_create_nonce( Llms_Selection::NONCE_ACTION );
		$_POST['od_ai_content_llms_selected']    = '1';
		$_POST['od_ai_content_llms_description'] = '<strong>Useful</strong> description.';

		$selection = new Llms_Selection( new Settings() );
		$selection->save( $post_id, $post );

		$this->assertTrue( Llms_Selection::is_selected( $post_id ) );
		$this->assertSame( 'Useful description.', Llms_Selection::get_custom_description( $post_id ) );
	}

	/**
	 * Create a post and optionally select it for llms.txt.
	 *
	 * @param array  $args        Post fields.
	 * @param string $description Short description.
	 * @param bool   $selected    Whether to select the post.
	 * @return int
	 */
	private function create_post( array $args, $description, $selected = true ) {
		$post_id = self::factory()->post->create(
			wp_parse_args(
				$args,
				array(
					'post_status' => 'publish',
				)
			)
		);

		if ( $selected ) {
			update_post_meta( $post_id, Llms_Selection::META_KEY, '1' );
		}

		update_post_meta( $post_id, Llms_Selection::DESCRIPTION_META_KEY, $description );

		return $post_id;
	}

	/**
	 * Create a generator using production dependencies.
	 *
	 * @return Llms_Txt
	 */
	private function generator() {
		$settings = new Settings();

		return new Llms_Txt(
			new Content_Resolver( $settings ),
			new Markdown_Url(),
			$settings
		);
	}
}
