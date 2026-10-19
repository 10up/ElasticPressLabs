<?php
/**
 * Test Chat retrieval in every kind of request
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;
use ElasticPress\Utils;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Retriever;

/**
 * Chat retriever tests.
 */
class TestChatRetriever extends BaseTestCase {

	/**
	 * Setup each test: a clean post index with one indexed post.
	 */
	public function set_up() {
		parent::set_up();

		Elasticsearch::factory()->delete_index( Indexables::factory()->get( 'post' )->get_index_name() );
		Indexables::factory()->get( 'post' )->put_mapping();

		$this->ep_factory->post->create(
			[
				'post_title'   => 'Fewer Americans use cash',
				'post_content' => 'Today, 42% of U.S. adults say they do not use cash in a typical week.',
			]
		);

		Elasticsearch::factory()->refresh_indices();
	}

	/**
	 * Leave the request context as it was.
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'] );

		parent::tear_down();
	}

	/**
	 * In a wp-admin request (the WP Async runner runs in admin-post.php), where
	 * ElasticPress does not integrate search queries by default, retrieval still runs
	 * through Elasticsearch, and only for its own query.
	 *
	 * @group chat
	 */
	public function test_retrieve_uses_elasticsearch_in_admin_requests() {
		set_current_screen( 'dashboard' );

		$this->assertTrue( is_admin() );
		$this->assertFalse( Utils\is_integrated_request( 'search' ) );

		$posts = ( new Retriever() )->search( 'cash' );

		$this->assertCount( 1, $posts );
		$this->assertTrue( ! empty( $posts[0]->elasticsearch ), 'The post came from Elasticsearch, not MySQL.' );

		// The override is gone after the query.
		$this->assertFalse( Utils\is_integrated_request( 'search' ) );
	}

	/**
	 * Password-protected posts are left out of the passages, unless a site allows them. They
	 * are only indexed when something asks for it, like the Protected Content feature.
	 *
	 * @group chat
	 */
	public function test_password_protected_posts_are_left_out() {
		add_filter( 'ep_pre_kill_sync_for_password_protected', '__return_false' );

		$protected = $this->ep_factory->post->create(
			[
				'post_title'    => 'Cash use in a protected report',
				'post_content'  => 'Protected: 99% of adults use cash every day.',
				'post_password' => 'secret',
			]
		);

		Elasticsearch::factory()->refresh_indices();

		$posts = ( new Retriever() )->search( 'cash' );

		$this->assertCount( 1, $posts );
		$this->assertNotContains( $protected, wp_list_pluck( $posts, 'ID' ) );

		add_filter( 'ep_chat_retrieve_password_protected_posts', '__return_true' );

		$this->assertContains( $protected, wp_list_pluck( ( new Retriever() )->search( 'cash' ), 'ID' ) );
	}

	/**
	 * When Elasticsearch does not answer the query, Chat gets an error (never WordPress' own
	 * search results, and never "no sources"), and an action reports it. The pipeline returns
	 * the error, which the reader can retry, instead of a refusal.
	 *
	 * @group chat
	 */
	public function test_retrieve_fails_closed_without_elasticsearch() {
		$failed = [];

		add_action(
			'ep_chat_retrieve_failed',
			static function ( $question, $query ) use ( &$failed ) {
				$failed[] = [ $question, $query instanceof \WP_Query ];
			},
			10,
			2
		);
		add_filter( 'ep_skip_query_integration', '__return_true' );

		$error = ( new Retriever() )->search( 'cash' );

		$this->assertWPError( $error );
		$this->assertSame( 'ep_chat_retrieve_failed', $error->get_error_code() );
		$this->assertSame( 503, $error->get_error_data()['status'] );
		$this->assertSame( [ [ 'cash', true ] ], $failed );

		$result = ( new Pipeline() )->run( 'cash', [], [ 'execution_mode' => 'sync' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'ep_chat_retrieve_failed', $result->get_error_code() );
		$this->assertSame( 'The search did not respond. Please try again.', $result->get_error_message() );
	}

	/**
	 * Chat's search request waits longer than the default HTTP timeout (filterable); other
	 * searches keep theirs.
	 *
	 * @group chat
	 */
	public function test_chat_search_has_its_own_timeout() {
		$timeouts = [];

		add_filter(
			'ep_query_request_args',
			static function ( $request_args ) use ( &$timeouts ) {
				$timeouts[] = $request_args['timeout'] ?? null;

				return $request_args;
			},
			20
		);

		( new Retriever() )->search( 'cash' );
		new \WP_Query(
			[
				's'            => 'cash',
				'ep_integrate' => true,
			]
		);

		add_filter(
			'ep_chat_retrieve_timeout',
			static function () {
				return 30;
			}
		);
		( new Retriever() )->search( 'cash' );

		$this->assertSame( [ 15.0, null, 30.0 ], $timeouts );
	}

	/**
	 * In a public request, retrieval keeps working as before.
	 *
	 * @group chat
	 */
	public function test_retrieve_uses_elasticsearch_in_public_requests() {
		$this->assertFalse( is_admin() );

		$posts = ( new Retriever() )->search( 'cash' );

		$this->assertCount( 1, $posts );
		$this->assertTrue( ! empty( $posts[0]->elasticsearch ) );
	}

	/**
	 * Chat's search drops ElasticPress's fuzzy matching (the slow part of its text query on a
	 * large index); site search keeps it; the filter brings it back for Chat.
	 *
	 * @group chat
	 */
	public function test_chat_search_has_no_fuzziness() {
		$bodies = [];

		add_filter(
			'ep_query_request_args',
			static function ( $request_args, $path, $index, $type, $query, $query_args ) use ( &$bodies ) {
				$bodies[] = [ ! empty( $query_args[ Retriever::QUERY_FLAG ] ), false !== strpos( $request_args['body'], 'fuzziness' ) ];

				return $request_args;
			},
			20,
			6
		);

		( new Retriever() )->search( 'cash' );
		new \WP_Query(
			[
				's'            => 'cash',
				'ep_integrate' => true,
			]
		);

		add_filter( 'ep_chat_retrieve_fuzziness', '__return_true' );
		( new Retriever() )->search( 'cash' );

		$this->assertSame( [ [ true, false ], [ false, true ], [ true, true ] ], $bodies );
		$this->assertSame(
			[ 'match' => [ 'a' => [ 'query' => 'x' ] ] ],
			Retriever::without_fuzziness(
				[
					'match' => [
						'a' => [
							'query'     => 'x',
							'fuzziness' => 'auto',
						],
					],
				]
			)
		);
	}
}
