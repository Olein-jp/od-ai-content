<?php
/**
 * Block editor document settings.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Registers post meta and the block editor document settings panel.
 */
final class Editor_Settings {

	/**
	 * Editor script handle.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'od-ai-content-editor-settings';

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
	 * Register editor hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_meta_fields' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register editable post meta for configured post types.
	 *
	 * @return void
	 */
	public function register_meta_fields() {
		foreach ( $this->settings->get_post_types() as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
				add_post_type_support( $post_type, 'custom-fields' );
			}

			register_post_meta(
				$post_type,
				Post_Exclusion::META_KEY,
				array(
					'auth_callback'     => array( $this, 'can_edit_meta' ),
					'description'       => __( 'Whether this content is excluded from Markdown output.', 'od-ai-content' ),
					'sanitize_callback' => array( $this, 'sanitize_binary_value' ),
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);

			register_post_meta(
				$post_type,
				Llms_Selection::META_KEY,
				array(
					'auth_callback'     => array( $this, 'can_edit_meta' ),
					'description'       => __( 'Whether this content is included in llms.txt.', 'od-ai-content' ),
					'sanitize_callback' => array( $this, 'sanitize_binary_value' ),
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);

			register_post_meta(
				$post_type,
				Llms_Selection::DESCRIPTION_META_KEY,
				array(
					'auth_callback'     => array( $this, 'can_edit_meta' ),
					'description'       => __( 'Short llms.txt description for this content.', 'od-ai-content' ),
					'sanitize_callback' => array( $this, 'sanitize_description' ),
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
				)
			);
		}
	}

	/**
	 * Determine whether the current user may edit the post meta.
	 *
	 * @param bool   $allowed Whether access is already allowed.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	public function can_edit_meta( $allowed, $meta_key, $post_id ) {
		unset( $allowed, $meta_key );

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Normalize checkbox meta to the existing string representation.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_binary_value( $value ) {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Sanitize and limit the llms.txt description.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_description( $value ) {
		$value = sanitize_textarea_field( (string) $value );

		return wp_html_excerpt( $value, 280, '' );
	}

	/**
	 * Enqueue the document settings panel for configured block editor screens.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();

		if (
			! $screen
			|| empty( $screen->post_type )
			|| ! in_array( $screen->post_type, $this->settings->get_post_types(), true )
			|| ! use_block_editor_for_post_type( $screen->post_type )
		) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/editor-settings.js', OD_AI_CONTENT_FILE ),
			array(
				'wp-components',
				'wp-data',
				'wp-editor',
				'wp-element',
				'wp-i18n',
				'wp-plugins',
			),
			OD_AI_CONTENT_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'odAiContentEditorSettings',
			array(
				'descriptionLabel'     => __( 'Short description', 'od-ai-content' ),
				'descriptionMetaKey'   => Llms_Selection::DESCRIPTION_META_KEY,
				'exclusionLabel'       => __( 'Exclude this content from Markdown output', 'od-ai-content' ),
				'exclusionMetaKey'     => Post_Exclusion::META_KEY,
				'llmsDefaultSelected'  => $this->settings->is_llms_default_selected(),
				'llmsSelectionLabel'   => __( 'Include this content in llms.txt', 'od-ai-content' ),
				'llmsSelectionMetaKey' => Llms_Selection::META_KEY,
				'panelTitle'           => __( 'OD AI Content', 'od-ai-content' ),
			)
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'od-ai-content' );
	}
}
