<?php
/**
 * Llms.txt post selection.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Saves llms.txt selections submitted by the classic editor.
 */
final class Llms_Selection {

	/**
	 * Selection meta key.
	 *
	 * @var string
	 */
	const META_KEY = '_od_ai_content_llms_selected';

	/**
	 * Description meta key.
	 *
	 * @var string
	 */
	const DESCRIPTION_META_KEY = '_od_ai_content_llms_description';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'od_ai_content_save_llms_selection';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'od_ai_content_llms_selection_nonce';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Save the selection fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, WP_Post $post ) {
		if (
			wp_is_post_revision( $post_id )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! in_array( $post->post_type, $this->settings->get_post_types(), true )
		) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$selected = isset( $_POST['od_ai_content_llms_selected'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['od_ai_content_llms_selected'] ) );

		if ( $selected ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
		} else {
			update_post_meta( $post_id, self::META_KEY, '0' );
		}

		$description = isset( $_POST['od_ai_content_llms_description'] )
			? sanitize_textarea_field( wp_unslash( $_POST['od_ai_content_llms_description'] ) )
			: '';
		$description = wp_html_excerpt( $description, 280, '' );

		if ( '' !== $description ) {
			update_post_meta( $post_id, self::DESCRIPTION_META_KEY, $description );
		} else {
			delete_post_meta( $post_id, self::DESCRIPTION_META_KEY );
		}
	}

	/**
	 * Determine whether a post is selected, falling back to the configured default.
	 *
	 * @param int  $post_id          Post ID.
	 * @param bool $default_selected Selection used when the post has no individual setting.
	 * @return bool
	 */
	public static function is_selected( $post_id, $default_selected = false ) {
		$selection = get_post_meta( $post_id, self::META_KEY, true );

		if ( '1' === $selection ) {
			return true;
		}

		if ( '0' === $selection ) {
			return false;
		}

		return (bool) $default_selected;
	}

	/**
	 * Get the administrator-provided description.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_custom_description( $post_id ) {
		return (string) get_post_meta( $post_id, self::DESCRIPTION_META_KEY, true );
	}

	/**
	 * Get a short description, with post content as a final fallback.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function get_description( WP_Post $post ) {
		$description = self::get_custom_description( $post->ID );

		if ( '' === $description ) {
			$description = $post->post_excerpt;
		}

		if ( '' === $description ) {
			$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30, '' );
		}

		return trim( preg_replace( '/\s+/u', ' ', $description ) );
	}
}
