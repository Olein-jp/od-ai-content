<?php
/**
 * Post-level Markdown output exclusion.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Saves the Markdown exclusion submitted by the classic editor.
 */
final class Post_Exclusion {

	/**
	 * Exclusion meta key.
	 *
	 * @var string
	 */
	const META_KEY = '_od_ai_content_exclude';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'od_ai_content_save_exclusion';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'od_ai_content_exclusion_nonce';

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
	 * Save post-level exclusion.
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

		$excluded = isset( $_POST['od_ai_content_exclude'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['od_ai_content_exclude'] ) );

		if ( $excluded ) {
			update_post_meta( $post_id, self::META_KEY, '1' );
			return;
		}

		delete_post_meta( $post_id, self::META_KEY );
	}

	/**
	 * Determine whether a post is excluded.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded( $post_id ) {
		return '1' === get_post_meta( $post_id, self::META_KEY, true );
	}
}
