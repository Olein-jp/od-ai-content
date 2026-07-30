<?php
/**
 * Plugin settings storage and validation.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Provides sanitized Markdown output settings.
 */
final class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'od_ai_content_settings';

	/**
	 * Get all settings with defaults.
	 *
	 * @return array
	 */
	public function get_all() {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args(
			$settings,
			array(
				'enabled'    => 1,
				'post_types' => array( 'post', 'page' ),
			)
		);
	}

	/**
	 * Determine whether Markdown output is globally enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = $this->get_all();

		return ! empty( $settings['enabled'] );
	}

	/**
	 * Get configured post types after applying the existing public filter.
	 *
	 * @return string[]
	 */
	public function get_post_types() {
		$settings   = $this->get_all();
		$post_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? array_values( array_unique( array_map( 'sanitize_key', $settings['post_types'] ) ) )
			: array();

		/**
		 * Filter post types that may expose a Markdown alternative.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $post_types Allowed post type names.
		 */
		return array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						(array) apply_filters( 'od_ai_content_post_types', $post_types )
					)
				)
			)
		);
	}

	/**
	 * Get public, editable post types available in the settings UI.
	 *
	 * @return \WP_Post_Type[]
	 */
	public function get_available_post_types() {
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );

		foreach ( $post_types as $name => $post_type ) {
			if (
				'attachment' === $name
				|| ( ! $post_type->public && ! $post_type->publicly_queryable )
			) {
				unset( $post_types[ $name ] );
			}
		}

		/**
		 * Filter post types displayed in the Markdown output settings.
		 *
		 * @since 0.2.0
		 *
		 * @param \WP_Post_Type[] $post_types Available post type objects.
		 */
		return (array) apply_filters( 'od_ai_content_available_post_types', $post_types );
	}

	/**
	 * Sanitize values submitted through the Settings API.
	 *
	 * @param mixed $input Submitted option value.
	 * @return array
	 */
	public function sanitize( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error(
				self::OPTION_NAME,
				'od_ai_content_forbidden',
				__( 'You are not allowed to change OD AI Content settings.', 'od-ai-content' ),
				'error'
			);

			return $this->get_all();
		}

		$input = is_array( $input ) ? $input : array();
		$valid = array_keys( $this->get_available_post_types() );
		$types = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_map( 'sanitize_key', $input['post_types'] )
			: array();

		return array(
			'enabled'    => empty( $input['enabled'] ) ? 0 : 1,
			'post_types' => array_values( array_intersect( array_unique( $types ), $valid ) ),
		);
	}
}
