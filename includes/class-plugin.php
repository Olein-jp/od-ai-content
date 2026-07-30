<?php
/**
 * Main plugin coordinator.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Registers plugin services and lifecycle behavior.
 */
final class Plugin {

	/**
	 * Register the plugin hooks.
	 *
	 * @return void
	 */
	public static function load() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		$html_converter      = new Html_To_Markdown();
			$block_converter = new Block_Converter( $html_converter );
			$document        = new Markdown_Document( $block_converter );
			$settings        = new Settings();
			$diagnostics     = new Diagnostics( $document, $settings );
			$editor_settings = new Editor_Settings( $settings );
			$resolver        = new Content_Resolver( $settings );
		$url                 = new Markdown_Url();
		$llms_txt            = new Llms_Txt( $resolver, $url, $settings );
		$cache_validator     = new Markdown_Cache_Validator();
		$github_updater      = new GitHub_Updater();

			$controller          = new Response_Controller( $resolver, $document, $url, $cache_validator );
			$diagnostics_api     = new Diagnostics_REST_Controller( $diagnostics, $settings, $resolver, $url );
			$llms_txt_controller = new Llms_Txt_Controller( $llms_txt );
		$discovery               = new Discovery( $resolver, $url );

			$controller->register_hooks();
			$diagnostics->register_hooks();
			$diagnostics_api->register_hooks();
			$llms_txt_controller->register_hooks();
		$discovery->register_hooks();
		$github_updater->register();
		$editor_settings->register_hooks();

		if ( is_admin() ) {
				$admin_settings    = new Admin_Settings( $settings );
				$admin_diagnostics = new Admin_Diagnostics( $settings, $diagnostics );
				$post_exclusion    = new Post_Exclusion( $settings );
			$llms_selection        = new Llms_Selection( $settings );

				$admin_settings->register_hooks();
				$admin_diagnostics->register_hooks();
				$post_exclusion->register_hooks();
			$llms_selection->register_hooks();
		}
	}

	/**
	 * Load bundled translations.
	 *
	 * @return bool True when the text domain path is registered.
	 */
	public static function load_textdomain() {
		return load_plugin_textdomain(
			'od-ai-content',
			false,
			dirname( plugin_basename( OD_AI_CONTENT_FILE ) ) . '/languages'
		);
	}

	/**
	 * Register rewrite rules and flush them on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		Response_Controller::register_rewrite_rule();
		Llms_Txt_Controller::register_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * Remove cached rewrite rules on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
