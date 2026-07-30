<?php
/**
 * Plugin Name:       OD AI Content
 * Description:       WordPressコンテンツをAIが取得・理解しやすいMarkdownとして配信します。
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.2.24
 * Author:            Olein Design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       od-ai-content
 *
 * @package OdAiContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OD_AI_CONTENT_VERSION', '0.1.0' );
define( 'OD_AI_CONTENT_FILE', __FILE__ );
define( 'OD_AI_CONTENT_DIR', plugin_dir_path( __FILE__ ) );

require_once OD_AI_CONTENT_DIR . 'includes/class-html-to-markdown.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-block-converter.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-markdown-document.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-settings.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-admin-settings.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-post-exclusion.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-content-resolver.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-markdown-url.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-response-controller.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-discovery.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-llms-selection.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-llms-txt.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-llms-txt-controller.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Olein\\OdAiContent\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Olein\\OdAiContent\\Plugin', 'deactivate' ) );

\Olein\OdAiContent\Plugin::load();
