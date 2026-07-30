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
		$html_converter  = new Html_To_Markdown();
		$block_converter = new Block_Converter( $html_converter );
		$document        = new Markdown_Document( $block_converter );
		$settings        = new Settings();
		$resolver        = new Content_Resolver( $settings );
		$url             = new Markdown_Url();

		$controller = new Response_Controller( $resolver, $document, $url );
		$discovery  = new Discovery( $resolver, $url );

		$controller->register_hooks();
		$discovery->register_hooks();

		if ( is_admin() ) {
			$admin_settings = new Admin_Settings( $settings );
			$post_exclusion = new Post_Exclusion( $settings );

			$admin_settings->register_hooks();
			$post_exclusion->register_hooks();
		}
	}

	/**
	 * Register rewrite rules and flush them on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		Response_Controller::register_rewrite_rule();
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
