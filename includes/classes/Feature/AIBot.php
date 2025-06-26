<?php
/**
 * AI Bot Feature
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * AIBot class.
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
	public function setup() {
		add_action( 'init', [ $this, 'register_block' ] );
	}

	/**
	 * Register block
	 */
	public function register_block() {
		/**
		 * Registering it here so translation works
		 *
		 * @see https://core.trac.wordpress.org/ticket/54797#comment:20
		 */
		wp_register_script(
			'ep-ai-bot-block-script',
			ELASTICPRESS_LABS_URL . 'dist/blocks/ai-bot-block-script.js',
			[],
			[],
			true
		);

		wp_set_script_translations( 'ep-ai-bot-block-script', 'elasticpress' );

		$block_folder = ELASTICPRESS_LABS_PATH . 'assets/js/blocks/ai-bot';

		$block_options = [];

		$markup_file_path = $block_folder . '/markup.php';
		if ( file_exists( $markup_file_path ) ) {

			// only add the render callback if the block has a file called markdown.php in it's directory
			$block_options['render_callback'] = function ( $attributes, $content, $block ) use ( $block_folder ) {

				// create helpful variables that will be accessible in markup.php file
				$context = $block->context;

				// get the actual markup from the markup.php file
				ob_start();
				include $block_folder . '/markup.php';
				return ob_get_clean();
			};
		}

		register_block_type_from_metadata( $block_folder, $block_options );
	}

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
