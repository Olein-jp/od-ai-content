<?php
/**
 * Llms.txt post selection.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use WP_Post;

/**
 * Registers and saves the llms.txt selection meta box.
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
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the selection meta box for configured post types.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		foreach ( $this->settings->get_post_types() as $post_type ) {
			add_meta_box(
				'od-ai-content-llms-selection',
				__( 'llms.txt', 'od-ai-content' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the selection fields.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( WP_Post $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label>
				<input
					type="checkbox"
					name="od_ai_content_llms_selected"
					value="1"
					<?php checked( self::is_selected( $post->ID ) ); ?>
				/>
				<?php esc_html_e( 'Include this content in llms.txt', 'od-ai-content' ); ?>
			</label>
		</p>
		<p>
			<label for="od-ai-content-llms-description">
				<?php esc_html_e( 'Short description', 'od-ai-content' ); ?>
			</label>
		</p>
		<textarea
			id="od-ai-content-llms-description"
			name="od_ai_content_llms_description"
			rows="4"
			class="widefat"
			maxlength="280"
		><?php echo esc_textarea( self::get_custom_description( $post->ID ) ); ?></textarea>
		<?php
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
			delete_post_meta( $post_id, self::META_KEY );
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
	 * Determine whether a post is explicitly selected.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_selected( $post_id ) {
		return '1' === get_post_meta( $post_id, self::META_KEY, true );
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
