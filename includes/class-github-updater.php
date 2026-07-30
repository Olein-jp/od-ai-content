<?php
/**
 * GitHub updater integration.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use Inc2734\WP_GitHub_Plugin_Updater\Bootstrap;
use Throwable;

/**
 * Initializes the production updater dependency without coupling plugin startup
 * to remote GitHub availability.
 */
final class GitHub_Updater {

	/**
	 * GitHub repository owner.
	 *
	 * @var string
	 */
	const REPOSITORY_OWNER = 'Olein-jp';

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	const REPOSITORY_NAME = 'od-ai-content';

	/**
	 * Updater factory.
	 *
	 * @var callable
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $factory Optional updater factory for testing.
	 */
	public function __construct( $factory = null ) {
		$this->factory = $factory ? $factory : static function ( $plugin_file, $owner, $repository ) {
			return new Bootstrap( $plugin_file, $owner, $repository );
		};
	}

	/**
	 * Register the GitHub updater.
	 *
	 * Missing dependencies or initialization failures must not prevent the
	 * Markdown delivery services from loading.
	 *
	 * @return bool Whether the updater was initialized.
	 */
	public function register() {
		if ( ! class_exists( Bootstrap::class ) ) {
			return false;
		}

		try {
			call_user_func(
				$this->factory,
				plugin_basename( OD_AI_CONTENT_FILE ),
				self::REPOSITORY_OWNER,
				self::REPOSITORY_NAME
			);
		} catch ( Throwable $error ) {
			/**
			 * Fires when the GitHub updater cannot be initialized.
			 *
			 * @param Throwable $error Initialization error.
			 */
			do_action( 'od_ai_content_github_updater_error', $error );

			return false;
		}

		return true;
	}
}
