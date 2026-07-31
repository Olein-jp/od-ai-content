<?php
/**
 * Plugin Name:       OD AI Content
 * Description:       Delivers WordPress content as Markdown that is easier for AI systems to retrieve and understand.
 * Version:           0.5.3
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Olein Design
 * Update URI:        https://github.com/Olein-jp/od-ai-content
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       od-ai-content
 * Domain Path:       /languages
 *
 * @package OdAiContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OD_AI_CONTENT_VERSION', '0.5.3' );
define( 'OD_AI_CONTENT_FILE', __FILE__ );
define( 'OD_AI_CONTENT_DIR', plugin_dir_path( __FILE__ ) );

$autoload_file = OD_AI_CONTENT_DIR . 'vendor/autoload.php';
if ( is_readable( $autoload_file ) ) {
	require_once $autoload_file;
}

require_once OD_AI_CONTENT_DIR . 'includes/class-html-to-markdown.php';
require_once OD_AI_CONTENT_DIR . 'includes/interface-block-markdown-converter.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-block-converter.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-markdown-document.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-diagnostics.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-settings.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-diagnostic-queue.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-admin-settings.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-admin-diagnostics.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-post-exclusion.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-editor-settings.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-content-resolver.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-markdown-url.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-diagnostics-rest-controller.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-markdown-cache-validator.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-response-controller.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-discovery.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-llms-selection.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-llms-txt.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-llms-txt-controller.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-github-updater.php';
require_once OD_AI_CONTENT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Olein\\OdAiContent\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Olein\\OdAiContent\\Plugin', 'deactivate' ) );

\Olein\OdAiContent\Plugin::load();
