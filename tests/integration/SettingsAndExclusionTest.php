<?php
/**
 * Settings and post exclusion integration tests.
 *
 * @package OdAiContent
 */

use Olein\OdAiContent\Post_Exclusion;
use Olein\OdAiContent\Admin_Settings;
use Olein\OdAiContent\Editor_Settings;
use Olein\OdAiContent\Llms_Selection;
use Olein\OdAiContent\Settings;

/**
 * Tests option validation and secured post-meta saving.
 */
class SettingsAndExclusionTest extends WP_UnitTestCase {

	/**
	 * Clean global and persistent state after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_NAME );
		unset( $_POST[ Post_Exclusion::NONCE_NAME ], $_POST['od_ai_content_exclude'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Defaults preserve the original post and page behavior.
	 *
	 * @return void
	 */
	public function test_default_settings_enable_posts_and_pages() {
		$settings = new Settings();

		$this->assertTrue( $settings->is_enabled() );
		$this->assertSame( array( 'post', 'page' ), $settings->get_post_types() );
		$this->assertFalse( $settings->is_llms_default_selected() );
	}

	/**
	 * The option is registered through the Settings API.
	 *
	 * @return void
	 */
	public function test_option_is_registered_through_settings_api() {
		global $wp_registered_settings;

		$settings = new Settings();
		$admin    = new Admin_Settings( $settings );

		$admin->register_settings();

		$this->assertArrayHasKey( Settings::OPTION_NAME, $wp_registered_settings );
		$this->assertSame( 'array', $wp_registered_settings[ Settings::OPTION_NAME ]['type'] );
		$this->assertSame(
			array( $settings, 'sanitize' ),
			$wp_registered_settings[ Settings::OPTION_NAME ]['sanitize_callback']
		);
	}

	/**
	 * Editor fields are registered for REST-backed document settings.
	 *
	 * @return void
	 */
	public function test_editor_meta_fields_are_registered_for_rest() {
		$editor_settings = new Editor_Settings( new Settings() );
		$editor_settings->register_meta_fields();
		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertTrue( post_type_supports( 'post', 'custom-fields' ) );
		$this->assertTrue( $registered[ Post_Exclusion::META_KEY ]['show_in_rest'] );
		$this->assertTrue( $registered[ Llms_Selection::META_KEY ]['show_in_rest'] );
		$this->assertTrue( $registered[ Llms_Selection::DESCRIPTION_META_KEY ]['show_in_rest'] );
		$this->assertSame( 'string', $registered[ Post_Exclusion::META_KEY ]['type'] );
		$this->assertTrue( $registered[ Post_Exclusion::META_KEY ]['single'] );
	}

	/**
	 * Editor meta sanitizers retain the established storage format.
	 *
	 * @return void
	 */
	public function test_editor_meta_values_are_sanitized() {
		$editor_settings = new Editor_Settings( new Settings() );

		$this->assertSame( '1', $editor_settings->sanitize_binary_value( '1' ) );
		$this->assertSame( '0', $editor_settings->sanitize_binary_value( 'anything-else' ) );
		$this->assertSame(
			'Plain description',
			$editor_settings->sanitize_description( '<strong>Plain</strong> description' )
		);
		$this->assertSame(
			280,
			strlen( $editor_settings->sanitize_description( str_repeat( 'a', 300 ) ) )
		);
	}

	/**
	 * Administrators can sanitize valid settings while invalid post types are removed.
	 *
	 * @return void
	 */
	public function test_administrator_can_sanitize_settings() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$settings  = new Settings();
		$sanitized = $settings->sanitize(
			array(
				'enabled'               => '1',
				'post_types'            => array( 'post', 'page', 'invalid<script>' ),
				'llms_default_selected' => '1',
			)
		);

		$this->assertSame( 1, $sanitized['enabled'] );
		$this->assertSame( array( 'post', 'page' ), $sanitized['post_types'] );
		$this->assertSame( 1, $sanitized['llms_default_selected'] );
	}

	/**
	 * Users without manage_options cannot change settings through the sanitizer.
	 *
	 * @return void
	 */
	public function test_unauthorized_user_cannot_sanitize_new_settings() {
		update_option(
			Settings::OPTION_NAME,
			array(
				'enabled'               => 1,
				'post_types'            => array( 'post' ),
				'llms_default_selected' => 0,
			)
		);

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$settings = new Settings();
		$result   = $settings->sanitize(
			array(
				'enabled'    => 0,
				'post_types' => array(),
			)
		);

		$this->assertSame(
			array(
				'enabled'               => 1,
				'post_types'            => array( 'post' ),
				'llms_default_selected' => 0,
			),
			$result
		);
	}

	/**
	 * A valid nonce and edit capability save the exclusion.
	 *
	 * @return void
	 */
	public function test_authorized_user_can_save_exclusion() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create(
			array(
				'post_author' => $administrator,
			)
		);
		$post          = get_post( $post_id );

		wp_set_current_user( $administrator );

		$_POST[ Post_Exclusion::NONCE_NAME ] = wp_create_nonce( Post_Exclusion::NONCE_ACTION );
		$_POST['od_ai_content_exclude']      = '1';

		$exclusion = new Post_Exclusion( new Settings() );
		$exclusion->save( $post_id, $post );

		$this->assertTrue( Post_Exclusion::is_excluded( $post_id ) );
	}

	/**
	 * An invalid nonce cannot change the exclusion.
	 *
	 * @return void
	 */
	public function test_invalid_nonce_cannot_save_exclusion() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create();
		$post          = get_post( $post_id );

		wp_set_current_user( $administrator );

		$_POST[ Post_Exclusion::NONCE_NAME ] = 'invalid';
		$_POST['od_ai_content_exclude']      = '1';

		$exclusion = new Post_Exclusion( new Settings() );
		$exclusion->save( $post_id, $post );

		$this->assertFalse( Post_Exclusion::is_excluded( $post_id ) );
	}

	/**
	 * A user without edit capability cannot change the exclusion.
	 *
	 * @return void
	 */
	public function test_unauthorized_user_cannot_save_exclusion() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id    = self::factory()->post->create();
		$post       = get_post( $post_id );

		wp_set_current_user( $subscriber );

		$_POST[ Post_Exclusion::NONCE_NAME ] = wp_create_nonce( Post_Exclusion::NONCE_ACTION );
		$_POST['od_ai_content_exclude']      = '1';

		$exclusion = new Post_Exclusion( new Settings() );
		$exclusion->save( $post_id, $post );

		$this->assertFalse( Post_Exclusion::is_excluded( $post_id ) );
	}
}
