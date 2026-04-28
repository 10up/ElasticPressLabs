<?php
/**
 * Core plugin functionality.
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Core;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use WP_Error;

/**
 * Default setup routine
 *
 * @return void
 */
function setup() {
	$n = function ( $function_name ) {
		return __NAMESPACE__ . "\\$function_name";
	};

	add_action( 'init', $n( 'i18n' ) );
	add_action( 'init', $n( 'init' ) );
	add_action( 'admin_enqueue_scripts', $n( 'admin_scripts' ) );

	add_action( 'plugins_loaded', $n( 'maybe_load_features' ), 11 );

	add_filter( 'ep_user_register_feature', '__return_false' );

	add_filter( 'ep_feature_groups', $n( 'add_feature_groups' ) );

	do_action( 'elasticpress_labs_loaded' );

	setup_updater();
}

/**
 * Registers the default textdomain.
 *
 * @return void
 */
function i18n() {
	$locale = apply_filters( 'plugin_locale', get_locale(), 'elasticpress-labs' );
	load_textdomain( 'elasticpress-labs', WP_LANG_DIR . '/elasticpress-labs/elasticpress-labs-' . $locale . '.mo' );
	load_plugin_textdomain( 'elasticpress-labs', false, plugin_basename( ELASTICPRESS_LABS_PATH ) . '/languages/' );
}

/**
 * Initializes the plugin and fires an action other plugins can hook into.
 *
 * @return void
 */
function init() {
	do_action( 'ep_labs_init' );
}

/**
 * Activate the plugin
 *
 * @return void
 */
function activate() {
	// First load the init scripts in case any rewrite functionality is being loaded
	init();
	flush_rewrite_rules();
}

/**
 * Deactivate the plugin
 *
 * Uninstall routines should be in uninstall.php
 *
 * @return void
 */
function deactivate() {
}


/**
 * The list of knows contexts for enqueuing scripts/styles.
 *
 * @return array
 */
function get_enqueue_contexts() {
	return [ 'admin' ];
}

/**
 * Generate an URL to a script, taking into account whether SCRIPT_DEBUG is enabled.
 *
 * @param string $script Script file name (no .js extension)
 * @param string $context Context for the script ('admin', 'frontend', or 'shared')
 *
 * @return string|WP_Error URL
 */
function script_url( $script, $context ) {
	if ( ! in_array( $context, get_enqueue_contexts(), true ) ) {
		return new WP_Error( 'invalid_enqueue_context', 'Invalid $context specified in ElasticPressLabs script loader.' );
	}

	return ELASTICPRESS_LABS_URL . "dist/js/{$script}.js";
}

/**
 * Generate an URL to a stylesheet, taking into account whether SCRIPT_DEBUG is enabled.
 *
 * @param string $stylesheet Stylesheet file name (no .css extension)
 * @param string $context Context for the script ('admin', 'frontend', or 'shared')
 *
 * @return string URL
 */
function style_url( $stylesheet, $context ) {
	if ( ! in_array( $context, get_enqueue_contexts(), true ) ) {
		return new WP_Error( 'invalid_enqueue_context', 'Invalid $context specified in ElasticPressLabs stylesheet loader.' );
	}

	return ELASTICPRESS_LABS_URL . "dist/css/{$stylesheet}.css";
}

/**
 * Enqueue scripts for admin.
 *
 * @return void
 */
function admin_scripts() {
	wp_enqueue_script(
		'elasticpress_labs_admin',
		script_url( 'admin', 'admin' ),
		array( 'wp-i18n' ),
		ELASTICPRESS_LABS_VERSION,
		true
	);
}

/**
 * Check if minimum ElasticPress requirements are met before loading the plugin feature.
 */
function maybe_load_features() {
	if ( ! class_exists( '\ElasticPress\Features' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\admin_notice_missing_ep' );
		return;
	}

	if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, ELASTICPRESS_LABS_MIN_EP_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\admin_notice_min_ep_version' );
		return;
	}

	if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.0.0', '<' ) ) {
		// Include your class file.
		require_once ELASTICPRESS_LABS_INC . 'classes/Feature/ElasticPressLabs.php';
		\ElasticPress\Features::factory()->register_feature(
			new \ElasticPressLabs()
		);
		return;
	}

	// Remove the old `elasticpress_labs` feature from EP settings
	$feature_settings = \ElasticPress\Features::factory()->get_feature_settings();
	if ( isset( $feature_settings['elasticpress_labs'] ) ) {
		unset( $feature_settings['elasticpress_labs'] );
		\ElasticPress\Utils\update_option( 'ep_feature_settings', $feature_settings );
	}

	$sep          = DIRECTORY_SEPARATOR;
	$features_dir = ELASTICPRESS_LABS_PATH . "includes{$sep}classes{$sep}Feature{$sep}";

	$manually_added_features = [
		'AISearchSummary',
		'ElasticPressLabs',
	];

	foreach ( glob( "{$features_dir}*.php" ) as $filename ) {
		$basename = basename( $filename, '.php' );
		if ( realpath( $filename ) === __FILE__ || in_array( $basename, $manually_added_features, true ) ) {
			continue;
		}

		if ( 'ElasticPressLabs' === $basename ) {
			continue;
		}

		$class_name = 'ElasticPressLabs\Feature\\' . $basename;

		if ( class_exists( $class_name ) ) {
			$subfeature = new $class_name();

			\ElasticPress\Features::factory()->register_feature( $subfeature );
		}
	}

	$vector_embeddings = new \ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings();
	\ElasticPress\Features::factory()->register_feature( $vector_embeddings );

	$semantic_search = new \ElasticPressLabs\Feature\SemanticSearch\SemanticSearch();
	\ElasticPress\Features::factory()->register_feature( $semantic_search );

	$ai_search_summary = new \ElasticPressLabs\Feature\AISearchSummary();
	\ElasticPress\Features::factory()->register_feature( $ai_search_summary );
}

/**
 * Add feature groups
 *
 * @since 2.5.0
 * @param array $groups Current groups
 * @return array
 */
function add_feature_groups( $groups ) {
	$groups['ai'] = [
		'label' => esc_html__( 'AI', 'elasticpress-labs' ),
	];
	return $groups;
}

/**
 * Render an admin notice about the absence of the ElasticPress plugin.
 */
function admin_notice_missing_ep() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'ElasticPress Labs needs ElasticPress to work.', 'elasticpress-labs' ); ?></p>
	</div>
	<?php
}

/**
 * Render an admin notice about the absence of the minimum ElasticPress plugin version.
 */
function admin_notice_min_ep_version() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: Min. EP version */
				esc_html__( 'ElasticPress Labs needs at least ElasticPress %s to work properly.', 'elasticpress-labs' ),
				esc_html( ELASTICPRESS_LABS_MIN_EP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Setup the updater
 *
 * @since 2.1.1
 * @return void
 */
function setup_updater() {
	$tenup_plugin_updater = PucFactory::buildUpdateChecker(
		'https://github.com/10up/ElasticPressLabs/',
		ELASTICPRESS_LABS_MAIN_FILE,
		'ElasticPressLabs'
	);

	$tenup_plugin_updater->addResultFilter(
		function ( $plugin_info ) {
			$plugin_info->icons = array(
				'svg' => ELASTICPRESS_LABS_URL . 'assets/img/logo-icon.svg',
			);
			return $plugin_info;
		}
	);
}
