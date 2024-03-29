<?php
/**
 * Boolean Search Operators Feature
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;
use ElasticPress\FeatureRequirementsStatus;
use ElasticPress\Features;
use ElasticPress\Elasticsearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Boolean Search Feature
 *
 * @package ElasticPressLabs\Feature\BooleanSearchOperators
 */
class BooleanSearchOperators extends Feature {

	/**
	 * Order of the feature in ElasticPress's Dashboard.
	 *
	 * @var integer
	 */
	public $order = 10;

	/**
	 * Initialize feature setting it's config
	 */
	public function __construct() {
		$this->slug = 'boolean_search_operators';

		$this->title = esc_html__( 'Boolean Search Operators', 'elasticpress-labs' );

		$this->requires_install_reindex = false;
		$this->default_settings         = [
			'active' => false,
		];

		parent::__construct();
	}

	/**
	 * Setup Feature Functionality
	 */
	public function setup() {
		$settings = $this->get_settings();

		if ( empty( $settings['active'] ) ) {
			return false;
		}

		/** Features Class @var Features $features */
		$features = Features::factory();

		/** Search Feature @var Feature\Search\Search $search */
		$search = $features->get_registered_feature( 'search' );

		if ( ! $search->is_active() && $this->is_active() ) {
			$features->deactivate_feature( $this->slug );

			return false;
		}

		add_filter( 'ep_elasticpress_enabled', [ $this, 'integrate_boolean_search_operators' ], 15, 2 );
	}

	/**
	 * Hook up the boolean operators query if it is enabled
	 *
	 * @hook   ep_elasticpress_enabled
	 *
	 * @param  {bool}     $enabled Whether to integrate with Elasticsearch or not
	 * @param  {WP_Query} $query WP_Query to evaluate
	 *
	 * @return bool
	 */
	public function integrate_boolean_search_operators( $enabled, $query ) {
		if ( ! $enabled ) {
			return false;
		}

		if ( $this->is_active() || true === $query->query_vars['ep_boolean_operators'] ) {

			\add_filter( 'ep_post_formatted_args_query', [ $this, 'replace_query_if_boolean' ], 999, 4 );
		}

		return true;
	}

	/**
	 * Check if a search query uses boolean operators and switch queries accordingly
	 *
	 * @hook  ep_post_formatted_args_query
	 *
	 * @param {array}  $query         Current query
	 * @param {array}  $query_vars    Query variables
	 * @param {string} $search_text   Search text
	 * @param {array}  $search_fields Search fields
	 *
	 * @return array
	 */
	public function replace_query_if_boolean( $query, $query_vars, $search_text, $search_fields ) {

		if ( ! $this->query_uses_boolean_operators( $search_text ) ) {
			return $query;
		}

		$simple_query = array(
			'simple_query_string' => array(
				/**
				 * Filter the fields to use in boolean operator searches
				 *
				 * @hook   ep_labs_boolean_operators_fields
				 * @since  1.2.0
				 *
				 * @param  {array} $search_fields
				 * @param  {array}  $query_vars    Query variables
				 * @param  {string} $search_text   Search text modified to replace tokens
				 * @param  {array}  $search_fields Search fields
				 * @param  {array}  $query         The original query
				 *
				 * @return {array} New fields
				 */
				'fields'           => \apply_filters( 'ep_labs_boolean_operators_fields', $search_fields, $query_vars, $search_text, $query ),

				/**
				 * Filter the default boolean operator
				 * Valid values: OR, AND
				 *
				 * @hook   ep_labs_boolean_operators_default
				 * @since  1.2.0
				 *
				 * @param  {string} $default
				 * @param  {array}  $query_vars    Query variables
				 * @param  {string} $search_text   Search text modified to replace tokens
				 * @param  {array}  $search_fields Search fields
				 * @param  {array}  $query         The original query
				 *
				 * @return {string} New operator
				 */
				'default_operator' => \apply_filters( 'ep_labs_boolean_operators_default', 'AND', $query_vars, $search_text, $search_fields, $query ),

				/**
				 * Filter allowed boolean operators.
				 * Valid flags: ALL, AND, ESCAPE, FUZZY, NEAR, NONE, NOT, OR, PHRASE, PRECEDENCE, PREFIX, SLOP, WHITESPACE
				 * Must return a string with a single flag or use pipe separators, e.g.: 'OR|AND|PREFIX'
				 *
				 * @hook    ep_labs_boolean_operators_flags
				 * @since   1.2.0
				 *
				 * @param  {string} $flags
				 * @param  {array}  $query_vars    Query variables
				 * @param  {string} $search_text   Search text modified to replace tokens
				 * @param  {array}  $search_fields Search fields
				 * @param  {array}  $query         The original query
				 *
				 * @return {string} New flags
				 */
				'flags'            => \apply_filters( 'ep_labs_boolean_operators_flags', 'ALL', $query_vars, $search_text, $search_fields, $query ),
			),
		);

		if ( version_compare( Elasticsearch::factory()->get_elasticsearch_version(), '6.0', '>=' ) ) {
			/**
			 * Filter automatic synonym generation for boolean operators queries
			 *
			 * @hook    ep_labs_boolean_operators_generate_synonyms
			 * @since   1.2.0
			 *
			 * @param  {bool} $auto_generate_synonyms
			 * @param  {array}  $query_vars    Query variables
			 * @param  {string} $search_text   Search text modified to replace tokens
			 * @param  {array}  $search_fields Search fields
			 * @param  {array}  $query         The original query
			 *
			 * @return {bool} New fuzziness
			 */
			$simple_query['simple_query_string']['auto_generate_synonyms_phrase_query'] = \apply_filters( 'ep_labs_boolean_operators_generate_synonyms', true, $query_vars, $search_text, $search_fields, $query );
		}

		$original_text = $search_text;

		if ( 'ALL' === $simple_query['simple_query_string']['flags'] ) {
			$search_text = \str_replace( array( ' AND ', ' OR ', ' NOT ' ), array( ' +', ' | ', ' -' ), $search_text );
		} else {
			$flags = explode( '|', $simple_query['simple_query_string']['flags'] );
			$ops   = array( 'AND', 'OR', 'NOT' );

			foreach ( $ops as $flag ) {
				if ( \in_array( $flag, $flags, true ) ) {
					switch ( $flag ) {
						case 'AND':
							$search_text = \str_replace( " $flag ", ' +', $search_text );
							break;
						case 'OR':
							$search_text = \str_replace( " $flag ", ' | ', $search_text );
							break;
						case 'NOT':
							$search_text = \str_replace( " $flag ", ' -', $search_text );
							break;
					}
				}
			}
		}

		/**
		 * Filter the search text to use in boolean operator queries
		 *
		 * @hook   ep_labs_boolean_operators_search_text
		 * @since  1.2.0
		 *
		 * @param  {string} $search_text   Search text modified to replace tokens
		 * @param  {string} $original_text The original search text
		 * @param  {array}  $query_vars    Query variables
		 * @param  {array}  $search_fields Search fields
		 * @param  {array}  $query         The original query
		 *
		 * @return {string} New search text
		 */
		$simple_query['simple_query_string']['query'] = \apply_filters( 'ep_labs_boolean_operators_search_text', $search_text, $original_text, $query_vars, $search_fields, $query );

		/**
		 * Filter formatted Elasticsearch simple query string query (only contains query part)
		 *
		 * @hook   ep_labs_boolean_operators_query_args
		 * @since  1.2.0
		 *
		 * @param  {array}  $simple_query  Current query
		 * @param  {array}  $query_vars    Query variables
		 * @param  {string} $search_text   Search text modified to replace tokens
		 * @param  {array}  $search_fields Search fields
		 * @param  {array}  $query         The original query
		 *
		 * @return {array} New query
		 */
		return \apply_filters( 'ep_labs_boolean_operators_query_args', $simple_query, $query_vars, $search_text, $search_fields, $query );
	}

	/**
	 * Test if a search text uses boolean operators
	 * reference for this RegEx: https://regex101.com/r/JizB0Z/1
	 * reference for acceptable operators:
	 * https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-simple-query-string-query.html#simple-query-string-syntax
	 *
	 * # matches
	 * (museum (art))    # no extra requirements for surrounding parentheses
	 * "american museum" # no extra requirements for surrounding double quotes
	 * museum art*       # requires zero space before the operator
	 * museum art~35     # requires zero space before and at least one digit after the operator
	 * museum | art      # requires surrounding spaces
	 * museum +art       # requires space before
	 * museum -art       # requires space before
	 * museum OR art     # requires surrounding spaces and uppercase operator
	 * museum AND art    # requires surrounding spaces and uppercase operator
	 * museum NOT art    # requires surrounding spaces and uppercase operator
	 *
	 * # non matches
	 * museum art
	 * art ()
	 * museum art 1985
	 * 35 artists~
	 * museum or art
	 * museum and art
	 * museum not art
	 * "museum
	 * (museum
	 * museum)
	 * museum *art
	 *
	 * @param string $search_text the search query
	 *
	 * @return bool
	 */
	public function query_uses_boolean_operators( $search_text ) {
		$boolean_regex = '/(\".+\")|(\+)|(\-)|(\S\*)|(\S\~\d)|(.+\|.+)|(\(\S+\))|(\sOR\s)|(\sAND\s)|(\sNOT\s)/';

		return (bool) preg_match( $boolean_regex, $search_text );
	}

	/**
	 * Returns requirements status of feature
	 *
	 * Requires the search feature to be activated
	 *
	 * @return FeatureRequirementsStatus
	 */
	public function requirements_status() {
		/** Features Class @var Features $features */
		$features = Features::factory();

		/** Search Feature @var Feature\Search\Search $search */
		$search = $features->get_registered_feature( 'search' );

		if ( ! $search->is_active() ) {
			return new FeatureRequirementsStatus( 2, esc_html__( 'This feature requires the "Post Search" feature to be enabled', 'elasticpress-labs' ) );
		}

		return new FeatureRequirementsStatus( 1 );
	}

	/**
	 * Output feature box summary
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Allow boolean operators in search queries', 'elasticpress-labs' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 */
	public function output_feature_box_long() {
		if ( ! defined( 'EP_VERSION' ) || version_compare( EP_VERSION, '5.0.0', '<' ) ) {
			echo wp_kses( $this->get_instructions(), 'ep-html' );
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			esc_html__( 'Settings are now generated via the set_settings_schema() method.' ),
			'ElasticPress Labs 2.2.0'
		);
	}

	/**
	 * Generate the instructions text
	 *
	 * @since 2.2.0
	 */
	public function get_instructions() {
		ob_start();
		?>
		<p><?php esc_html_e( 'Allows users to search using the following boolean operators:', 'elasticpress-labs' ); ?></p>
		<ul>
			<li>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: <code>+</code>, 2: <code>AND</code> */
						esc_html__( '%1$s or %2$s signifies AND operation. eg.: modern +art, modern AND art', 'elasticpress-labs' ),
						'<code>+</code>',
						'<code>AND</code>'
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: <code>|</code>, 2: <code>OR</code> */
						esc_html__( '%1$s or %2$s signifies OR operation. eg.: modern | art, modern OR art', 'elasticpress-labs' ),
						'<code>|</code>',
						'<code>OR</code>'
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: <code>-</code>, 2: <code>NOT</code> */
						esc_html__( '%1$s or %2$s signifies NOT operation. eg.: modern -art, modern NOT art', 'elasticpress-labs' ),
						'<code>-</code>',
						'<code>NOT</code>'
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: <code>"</code> */
						esc_html__( '%1$s wraps characters to signify a phrase. eg.: "modern art"', 'elasticpress-labs' ),
						'<code>"</code>'
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: <code>*</code> */
						esc_html__( '%1$s signifies a prefix wildcard. eg.: art*', 'elasticpress-labs' ),
						'<code>*</code>'
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: <code>()</code> */
						esc_html__( '%1$s signifies precedence. eg.: (MoMA OR (modern AND art))', 'elasticpress-labs' ),
						'<code>()</code>'
					)
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: <code>~#</code> */
						esc_html__( '%1$s signifies slop if used on a phrase. eg.: "modern art"~2. Signifies fuzziness if used on a word: eg: modern~1', 'elasticpress-labs' ),
						'<code>~#</code>'
					)
				);
				?>
			</li>
		</ul>
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
				'key'   => 'instructions',
				'label' => $this->get_instructions(),
				'type'  => 'markup',
			],
		];
	}
}
