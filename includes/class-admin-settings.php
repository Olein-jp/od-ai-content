<?php
/**
 * Settings screen for Markdown output.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Registers and renders the plugin settings page.
 */
final class Admin_Settings {

	/**
	 * Settings group.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'od_ai_content';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'od-ai-content';

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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Register the option, section, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => array(
					'enabled'               => 1,
					'post_types'            => array( 'post', 'page' ),
					'llms_default_selected' => 0,
				),
			)
		);

		add_settings_section(
			'od_ai_content_output',
			__( 'Markdown output', 'od-ai-content' ),
			array( $this, 'render_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'od_ai_content_enabled',
			__( 'Output status', 'od-ai-content' ),
			array( $this, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'od_ai_content_output'
		);

		add_settings_field(
			'od_ai_content_post_types',
			__( 'Post types', 'od-ai-content' ),
			array( $this, 'render_post_types_field' ),
			self::PAGE_SLUG,
			'od_ai_content_output'
		);

		add_settings_field(
			'od_ai_content_llms_default_selected',
			__( 'llms.txt default', 'od-ai-content' ),
			array( $this, 'render_llms_default_selected_field' ),
			self::PAGE_SLUG,
			'od_ai_content_output'
		);
	}

	/**
	 * Register the settings page under Settings.
	 *
	 * @return void
	 */
	public function register_page() {
		add_options_page(
			__( 'OD AI Content', 'od-ai-content' ),
			__( 'OD AI Content', 'od-ai-content' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'od-ai-content' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the settings section description.
	 *
	 * @return void
	 */
	public function render_section() {
		echo '<p>' . esc_html__( 'Control which public content receives a Markdown alternative.', 'od-ai-content' ) . '</p>';
	}

	/**
	 * Render the global enable field.
	 *
	 * @return void
	 */
	public function render_enabled_field() {
		$settings = $this->settings->get_all();
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[enabled]"
				value="1"
				<?php checked( ! empty( $settings['enabled'] ) ); ?>
			/>
			<?php esc_html_e( 'Enable Markdown alternatives', 'od-ai-content' ); ?>
		</label>
		<?php
	}

	/**
	 * Render post type checkboxes.
	 *
	 * @return void
	 */
	public function render_post_types_field() {
		$selected = $this->settings->get_post_types();

		foreach ( $this->settings->get_available_post_types() as $post_type ) {
			?>
			<label style="display:block">
				<input
					type="checkbox"
					name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[post_types][]"
					value="<?php echo esc_attr( $post_type->name ); ?>"
					<?php checked( in_array( $post_type->name, $selected, true ) ); ?>
				/>
				<?php echo esc_html( $post_type->labels->name ); ?>
			</label>
			<?php
		}
	}

	/**
	 * Render the default llms.txt selection field.
	 *
	 * @return void
	 */
	public function render_llms_default_selected_field() {
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[llms_default_selected]"
				value="1"
				<?php checked( $this->settings->is_llms_default_selected() ); ?>
			/>
			<?php esc_html_e( 'Include content without an individual setting in llms.txt', 'od-ai-content' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, existing and newly published content in the selected post types is included by default. Individual content settings override this default.', 'od-ai-content' ); ?>
		</p>
		<?php
	}
}
