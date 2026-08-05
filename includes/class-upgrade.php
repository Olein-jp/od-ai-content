<?php
/**
 * Versioned plugin data upgrades.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Runs small idempotent data migrations after plugin updates.
 */
final class Upgrade {

	/**
	 * Stored data version option.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'od_ai_content_data_version';

	/**
	 * Current data migration version.
	 *
	 * @var int
	 */
	const CURRENT_VERSION = 1;

	/**
	 * Legacy diagnostic queue option.
	 *
	 * @var string
	 */
	const LEGACY_QUEUE_OPTION = 'od_ai_content_diagnostic_queue';

	/**
	 * Legacy diagnostic queue lock option.
	 *
	 * @var string
	 */
	const LEGACY_QUEUE_LOCK_OPTION = 'od_ai_content_diagnostic_queue_lock';

	/**
	 * Legacy diagnostic queue Cron hook.
	 *
	 * @var string
	 */
	const LEGACY_QUEUE_CRON_HOOK = 'od_ai_content_process_diagnostic_queue';

	/**
	 * Register upgrade hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'run' ), 1 );
	}

	/**
	 * Run all migrations not yet applied to this site.
	 *
	 * @return void
	 */
	public function run() {
		$installed_version = (int) get_option( self::VERSION_OPTION, 0 );

		if ( self::CURRENT_VERSION <= $installed_version ) {
			return;
		}

		self::remove_legacy_diagnostic_queue();
		update_option( self::VERSION_OPTION, self::CURRENT_VERSION, false );
	}

	/**
	 * Remove queue state left by releases before 0.6.0.
	 *
	 * @return void
	 */
	public static function remove_legacy_diagnostic_queue() {
		wp_clear_scheduled_hook( self::LEGACY_QUEUE_CRON_HOOK );
		delete_option( self::LEGACY_QUEUE_OPTION );
		delete_option( self::LEGACY_QUEUE_LOCK_OPTION );
	}
}
