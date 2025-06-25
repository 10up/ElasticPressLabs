<?php
/**
 * Search Algorithm Feature
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SearchAlgorithm class.
 */
class AIBot extends \ElasticPress\Feature {

	/**
	 * Group
	 *
	 * @var string $group.
	 */
	public $group = 'ai';

	/**
	 * Initialize feature settings.
	 */
	public function __construct() {
		$this->slug = 'ai_bot';

		parent::__construct();
	}

	/**
	 * Sets i18n strings.
	 *
	 * @return void
	 * @since 2.4.0
	 */
	public function set_i18n_strings(): void {
		$this->title = esc_html__( 'AI Bots', 'elasticpress-labs' );
	}

	/**
	 * Setup your feature functionality.
	 * Use this method to hook your feature functionality to ElasticPress or WordPress.
	 */
	public function setup() {}

	/**
	 * Generate the instructions text
	 *
	 * @since 2.2.0
	 */
	public function get_instructions() {
		ob_start();
		?>
		<p><?php esc_html_e( 'An AI Bot is a configuration of AI settings. You can create a bot, configure it, and assign it to a specific AI-enabled feature. That feature will then prioritize the Bot configuration over the global configuration.', 'elasticpress-labs' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Create a new "AI Bot" Post.', 'elasticpress-labs' ); ?></li>
			<li><?php esc_html_e( 'Provide prompts, if needed, and select the feature you want to override. Change the default settings as needed.', 'elasticpress-labs' ); ?></li>
			<li><?php esc_html_e( 'Publish the bot. Now, on a page or post, add the "AI Bot" Gutenberg block, and assign your bot to it.', 'elasticpress-labs' ); ?></li>
			<li><?php esc_html_e( 'View the page or post you added it to. The selected AI feature will display, and will use the AI Bot settings.', 'elasticpress-labs' ); ?></li>
		</ol>
		<?php
		return ob_get_clean();
	}

	/**
	 * Set the `settings_schema` attribute
	 *
	 * @since 2.2.0
	 */
	public function set_settings_schema() {
		$this->settings_schema = [
			[
				'key'   => 'epio',
				'label' => $this->get_instructions(),
				'type'  => 'markup',
			],
		];
	}
}
