<?php
/**
 * Test External Content feature
 *
 * @since 2.3.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Features;
use ElasticPressLabs\Feature\ExternalContent;

/**
 * External Content test class
 */
class TestExternalContent extends \WP_UnitTestCase {

	/**
	 * The body reponse for HTTP requests
	 *
	 * @var string
	 */
	protected $html_return = '';

	/**
	 * Setup each test
	 */
	public function set_up() {
		$instance = new ExternalContent();
		Features::factory()->register_feature( $instance );
		Features::factory()->activate_feature( 'external_content' );
		Features::factory()->setup_features();

		add_filter( 'pre_option_ep_feature_settings', [ $this, 'set_settings' ] );
		add_filter( 'pre_http_request', [ $this, 'force_http_response' ] );
	}

	/**
	 * Clean up after each test
	 */
	public function tear_down() {
		remove_filter( 'pre_option_ep_feature_settings', [ $this, 'set_settings' ] );
		remove_filter( 'pre_http_request', [ $this, 'force_http_response' ] );
	}

	/**
	 * Get External Content feature
	 *
	 * @return ExternalContent
	 */
	protected function get_feature() {
		return \ElasticPress\Features::factory()->get_registered_feature( 'external_content' );
	}

	/**
	 * Test construct
	 *
	 * @group external-content
	 */
	public function test_construct() {
		$instance = $this->get_feature();

		$this->assertEquals( 'external_content', $instance->slug );
		$this->assertEquals( 'External Content', $instance->title );
	}

	/**
	 * Test requirements_status
	 *
	 * @group external-content
	 */
	public function test_requirements_status() {
		$requirements_status = $this->get_feature()->requirements_status();

		$this->assertSame( 1, $requirements_status->code );
	}

	/**
	 * Test set_settings_schema
	 *
	 * @group external-content
	 */
	public function test_set_settings_schema() {
		$this->get_feature()->set_settings_schema();

		$expected_schema = [
			'default' => '',
			'help'    => '<p>Add one field per line</p>',
			'key'     => 'meta_fields',
			'label'   => 'Meta fields with external URLs',
			'type'    => 'textarea',
		];

		$this->assertSame( $this->get_feature()->get_settings_schema()[1], $expected_schema );
	}

	/**
	 * Test append_external_content
	 *
	 * @group external-content
	 */
	public function test_append_external_content() {
		$this->html_return = '{"id":123,"content":"Lorem ipsum"}';

		$original_post_meta = [
			'meta_key_1' => 'https://example.org/news/wp-json/wp/v2/posts/1',
		];

		$post_meta = $this->get_feature()->append_external_content( $original_post_meta );

		$this->assertSame( $post_meta['ep_external_content_meta_key_1'], ' {"id":123,"content":"Lorem ipsum"}' );

		$change_via_filter = function ( $content ) {
			$this->assertSame( $content, '{"id":123,"content":"Lorem ipsum"}' );
			return 'Something different';
		};
		add_filter( 'ep_external_content_file_content', $change_via_filter );

		$post_meta = $this->get_feature()->append_external_content( $original_post_meta );
		$this->assertSame( $post_meta['ep_external_content_meta_key_1'], ' Something different' );

		remove_filter( 'ep_external_content_file_content', $change_via_filter );

		$this->assertSame( 2, did_action( 'ep_external_content_item_processed' ) );
	}

	/**
	 * Test that append_external_content strips all tags
	 *
	 * @group external-content
	 */
	public function test_append_external_content_strip_all_tags() {
		$this->html_return = '<script>alert("XSS Injection");</script><p>Some text</p>';

		$original_post_meta = [
			'meta_key_1' => 'https://example.org/news/wp-json/wp/v2/posts/1',
		];

		$post_meta = $this->get_feature()->append_external_content( $original_post_meta );

		$this->assertSame( $post_meta['ep_external_content_meta_key_1'], ' Some text' );
	}

	/**
	 * Test append_external_content request filters
	 *
	 * @group external-content
	 */
	public function test_append_external_content_remote_request_filters() {
		$change_url = function ( $url ) {
			$this->assertSame( 'https://example.org/news/wp-json/wp/v2/posts/1', $url );
			return 'https://example.local/changed';
		};
		add_filter( 'ep_external_content_remote_request_url', $change_url );

		$change_args = function ( $args ) {
			$this->assertEmpty( $args );
			return [ 'method' => 'DELETE' ];
		};
		add_filter( 'ep_external_content_remote_request_args', $change_args );

		$check = function ( $http_request, $parsed_args, $url ) {
			$this->assertSame( $parsed_args['method'], 'DELETE' );
			$this->assertSame( $url, 'https://example.local/changed' );

			return $http_request;
		};
		add_filter( 'pre_http_request', $check, 10, 3 );

		$original_post_meta = [
			'meta_key_1' => 'https://example.org/news/wp-json/wp/v2/posts/1',
		];

		$this->get_feature()->append_external_content( $original_post_meta );

		remove_filter( 'ep_external_content_remote_request_url', $change_url );
		remove_filter( 'ep_external_content_remote_request_args', $change_args );
		remove_filter( 'pre_http_request', $check );
	}

	/**
	 * Test get_meta_keys
	 *
	 * @group external-content
	 */
	public function test_get_meta_keys() {
		$expected = [ 'meta_key_1', 'meta_key_2' ];

		$this->assertSame( $this->get_feature()->get_meta_keys(), $expected );

		$change_via_filter = function ( $meta_keys ) {
			$meta_keys[] = 'meta_key_3';
			return $meta_keys;
		};
		add_filter( 'ep_external_content_meta_keys', $change_via_filter );

		$this->assertSame( $this->get_feature()->get_meta_keys(), array_merge( $expected, [ 'meta_key_3' ] ) );

		remove_filter( 'ep_external_content_meta_keys', $change_via_filter );
	}

	/**
	 * Test get_stored_meta_key
	 *
	 * @group external-content
	 */
	public function test_get_stored_meta_key() {
		$this->assertSame( $this->get_feature()->get_stored_meta_key( 'meta_key' ), 'ep_external_content_meta_key' );

		$change_via_filter = function ( $stored_meta_key, $meta_key ) {
			$this->assertSame( $meta_key, 'meta_key' );
			$stored_meta_key .= 'changed';
			return $stored_meta_key;
		};
		add_filter( 'ep_external_content_stored_meta_key', $change_via_filter, 10, 2 );

		$this->assertSame( $this->get_feature()->get_stored_meta_key( 'meta_key' ), 'ep_external_content_meta_keychanged' );

		remove_filter( 'ep_external_content_stored_meta_key', $change_via_filter );
	}

	/**
	 * Test allow_meta_keys
	 *
	 * @group external-content
	 */
	public function test_allow_meta_keys() {
		$expected = [
			'some_other_key',
			'ep_external_content_meta_key_1',
			'ep_external_content_meta_key_2',
		];

		$this->assertSame( $this->get_feature()->allow_meta_keys( [ 'some_other_key' ] ), $expected );
	}

	/**
	 * Test maybe_limit_size
	 *
	 * @group external-content
	 * @dataProvider limit_size_provider
	 * @param string $content Content of the file
	 * @param string $assert  Assertion to be made
	 */
	public function test_maybe_limit_size( $content, $assert ) {
		$this->html_return = $content;

		$original_post_meta = [
			'meta_key_1' => 'https://example.org/news/wp-json/wp/v2/posts/1',
		];

		$post_meta = $this->get_feature()->append_external_content( $original_post_meta );

		$this->$assert( ' (trimmed)', $post_meta['ep_external_content_meta_key_1'] );
	}

	/**
	 * Data Provider of the test_maybe_limit_size test
	 *
	 * @return array
	 */
	public function limit_size_provider() {
		return [
			[ $this->generate_random_string( 15 * KB_IN_BYTES ), 'assertStringNotContainsString' ],
			[ $this->generate_random_string( 22 * KB_IN_BYTES ), 'assertStringContainsString' ],
		];
	}

	/**
	 * Test maybe_limit_size with the ep_external_content_max_size filter
	 *
	 * @group external-content
	 */
	public function test_maybe_limit_size_filter_max_size() {
		$this->html_return = $this->generate_random_string( 50 * KB_IN_BYTES );

		$change_size = function ( $size ) {
			$this->assertSame( $size, 20 * KB_IN_BYTES );
			return 0;
		};
		add_filter( 'ep_external_content_max_size', $change_size );

		$original_post_meta = [
			'meta_key_1' => 'https://example.org/news/wp-json/wp/v2/posts/1',
		];

		$post_meta = $this->get_feature()->append_external_content( $original_post_meta );

		$this->assertStringNotContainsString( ' (trimmed)', $post_meta['ep_external_content_meta_key_1'] );

		remove_filter( 'ep_external_content_max_size', $change_size );
	}

	/**
	 * Test maybe_parse_js
	 *
	 * @group external-content
	 */
	public function test_maybe_parse_js() {
		$content = $this->get_javascript_contents();
		$this->get_feature()->maybe_parse_js( $content, 'file.txt' );

		$this->assertSame( $this->get_feature()->maybe_parse_js( $content, 'file.txt' ), $content );

		$parsed_js = $this->get_feature()->maybe_parse_js( $content, 'file.js' );

		$this->assertStringContainsString( 'Excludes this post from the results ', $parsed_js );
		$this->assertStringContainsString( 'Exclude from search results', $parsed_js );
		$this->assertStringNotContainsString( 'WordPress dependencies', $parsed_js );
		$this->assertStringNotContainsString( 'PluginPostStatusInfo', $parsed_js );
	}

	/**
	 * Test maybe_parse_js with `remove_js_reserved_words`
	 *
	 * @group external-content
	 */
	public function test_maybe_parse_js_remove_js_reserved_words() {
		$content = $this->get_javascript_contents();

		$change_via_filter = function ( $method ) {
			$this->assertSame( $method, 'only_strings' );
			return 'remove_js_reserved_words';
		};
		add_filter( 'ep_external_content_parse_js_method', $change_via_filter );

		$parsed_js = $this->get_feature()->maybe_parse_js( $content, 'file.js' );

		$this->assertStringContainsString( 'Excludes this post from the results ', $parsed_js );
		$this->assertStringContainsString( 'Exclude from search results', $parsed_js );
		$this->assertStringContainsString( 'WordPress dependencies', $parsed_js );
		$this->assertStringContainsString( 'PluginPostStatusInfo', $parsed_js );
	}

	/**
	 * Set the feature settings
	 *
	 * @group external-content
	 */
	public function set_settings() {
		return [
			'external_content' => [
				'meta_fields' => "meta_key_1\nmeta_key_2",
			],
		];
	}

	/**
	 * Utilitary function to force HTTP responses
	 *
	 * @return array
	 */
	public function force_http_response() {
		return [ 'body' => $this->html_return ];
	}

	/**
	 * Utilitary function to get a JS file contents
	 *
	 * @return string
	 */
	protected function get_javascript_contents() {
		return '
		/**
		 * WordPress dependencies.
		 */

		export default () => {
			const { editPost } = useDispatch(\'core/editor\');

			const { ep_exclude_from_search = false, ...meta } = useSelect(
				(select) => select(\'core/editor\').getEditedPostAttribute(\'meta\') || {},
			);

			const onChange = (ep_exclude_from_search) => {
				editPost({ meta: { ...meta, ep_exclude_from_search } });
			};

			return (
				<PluginPostStatusInfo>
					<CheckboxControl
						label={__(\'Exclude from search results\', \'elasticpress\')}
						help={__(
							"Excludes this post from the results of your site\'s search form while ElasticPress is active.",
							\'elasticpress\',
						)}
						checked={ep_exclude_from_search}
						onChange={onChange}
					/>
				</PluginPostStatusInfo>
			);
		};';
	}

	/**
	 * Generates a random string
	 *
	 * @param integer $length length of the string
	 * @return string
	 */
	protected function generate_random_string( $length = 10 ) {
		$characters        = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$characters_length = strlen( $characters );
		$random_string     = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$random_string .= $characters[ random_int( 0, $characters_length - 1 ) ];
		}

		return $random_string;
	}
}
