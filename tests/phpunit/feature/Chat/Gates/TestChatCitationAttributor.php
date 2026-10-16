<?php
/**
 * Test the Citation_Attributor_Gate
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Gates\Citation_Attributor_Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate;
use ElasticPressLabs\Feature\Chat\Gates\Gate_Registry;
use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Jobs\Job_Processor;
use ElasticPressLabs\Feature\Chat\Pipeline;
use ElasticPressLabs\Feature\Chat\Steps;
use ElasticPressLabs\Feature\Chat\Storage;

/**
 * Citation_Attributor_Gate and pipeline tests.
 */
class TestChatCitationAttributor extends \WP_UnitTestCase {

	/**
	 * Answer the fake model returns.
	 *
	 * @var string
	 */
	protected $answer = '';

	/**
	 * Setup each test: one retrieved post, a fake model.
	 */
	public function set_up() {
		parent::set_up();
		Gates::reset();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Fewer Americans use cash',
				'post_excerpt' => 'Today, 42% of U.S. adults say they do not use cash in a typical week, up from 24% in 2015.',
				'post_status'  => 'publish',
				'post_date'    => '2026-05-01 10:00:00',
			]
		);

		add_filter(
			'ep_chat_retrieve',
			static function () use ( $post_id ) {
				return [ get_post( $post_id ) ];
			}
		);
		add_filter(
			'ep_chat_generate_text',
			function () {
				return $this->answer;
			}
		);
	}

	/**
	 * Reset the registry.
	 */
	public function tear_down() {
		Gates::reset();
		parent::tear_down();
	}

	/**
	 * Run the pipeline for a question.
	 *
	 * @param string $answer Answer from the fake model.
	 * @return array
	 */
	protected function ask( string $answer ): array {
		$this->answer = $answer;

		// The answer checks, without the optional model-based ones.
		return ( new Pipeline() )->run(
			'How many adults use cash?',
			[],
			[
				'execution_mode' => 'sync',
				'optional_gates' => [ 'figure_match', 'scope', 'superlative', 'refusal', 'citation_attributor' ],
			]
		);
	}

	/**
	 * Labs registers the attributor as the required gate of its own last stage, with no
	 * refusal reasons; it has a progress label.
	 *
	 * @group chat
	 */
	public function test_gate_is_optional_on_its_own_stage() {
		$gate = Gates::registry()->get( Citation_Attributor_Gate::SLUG );

		$this->assertInstanceOf( Citation_Attributor_Gate::class, $gate );
		$this->assertSame( Gate::STAGE_ATTRIBUTE_ANSWER, $gate->get_stage() );
		$this->assertFalse( $gate->is_required() );
		$this->assertTrue( $gate->is_enabled_by_default() );
		$this->assertSame( [], $gate->get_refusal_reasons() );
		$this->assertArrayHasKey( Gate::STAGE_ATTRIBUTE_ANSWER, Steps::get_labels() );
	}

	/**
	 * A passed answer gets markers and provenance, after every verify-answer gate saw the
	 * text the model wrote.
	 *
	 * @group chat
	 */
	public function test_runs_after_the_ladder() {
		$seen  = [];
		$steps = [];

		add_action(
			'ep_chat_register_gate',
			function ( Gate_Registry $registry ) use ( &$seen ) {
				$registry->register( new FixtureSpyGate( 'late_verify', Gate::STAGE_VERIFY_ANSWER, $seen ) );
			}
		);
		add_action(
			'ep_chat_step',
			static function ( $step ) use ( &$steps ) {
				$steps[] = $step;
			}
		);

		$result = $this->ask( 'About 42% of adults do not use cash [[4]].' );

		$this->assertSame( [ 'About 42% of adults do not use cash [[4]].' ], $seen );
		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( 'About 42%[[1]] of adults do not use cash.', $result['content'] );
		$this->assertSame( Gate::STAGE_ATTRIBUTE_ANSWER, end( $steps ) );

		$entry = $result['provenance'][0];

		$this->assertSame( 1, $entry['k'] );
		$this->assertSame( 1, $entry['n'] );
		$this->assertSame( '42%', $entry['figure'] );
		$this->assertSame( 'Fewer Americans use cash', $entry['title'] );
		$this->assertSame( get_the_date( '', $result['hits'][0]['id'] ), $entry['date'] );
		$this->assertStringContainsString( '#:~:text=Today%2C%2042%25', $entry['verify_url'] );
		$this->assertSame( $result['provenance'], $result['meta']['provenance'] );
	}

	/**
	 * A refused answer is never attributed.
	 *
	 * @group chat
	 */
	public function test_does_not_run_on_refusals() {
		$result = $this->ask( 'About 77% of adults do not use cash.' );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertArrayNotHasKey( 'provenance', $result );
		$this->assertArrayNotHasKey( 'provenance', $result['meta'] );
	}

	/**
	 * The async worker stores the provenance with the answer, so History shows it later.
	 *
	 * @group chat
	 */
	public function test_provenance_is_stored_with_the_answer() {
		$storage = new Storage();
		$storage->register_post_type();
		$bots = new Bots();
		$bots->register_post_type();
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->answer = 'About 42% of adults do not use cash.';

		$bot      = $bots->get( $bots->create( 'Citations bot', Bots::MODE_WP_ASYNC ) );
		$accepted = $storage->accept_question( 'How many adults use cash?' );
		$storage->set_job_context( $accepted['question_id'], $bot['id'], [] );

		$handle = ( new Job_Processor() )->process( $accepted['question_id'] );
		$turns  = $storage->get_turns( $accepted['ep_chat_id'] );
		$answer = end( $turns );

		$this->assertSame( Storage::STATUS_DONE, $handle->get_status() );
		$this->assertSame( 'About 42%[[1]] of adults do not use cash.', $answer['content'] );
		$this->assertSame( '42%', $answer['payload']['provenance'][0]['figure'] );
		$this->assertSame( 1, $answer['payload']['provenance'][0]['n'] );
	}
}
