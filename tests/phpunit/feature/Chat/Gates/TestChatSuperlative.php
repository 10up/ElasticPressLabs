<?php
/**
 * Test the Superlative_Gate
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Gates\Gates;
use ElasticPressLabs\Feature\Chat\Gates\Superlative_Gate;

/**
 * Superlative_Gate tests.
 */
class TestChatSuperlative extends \WP_UnitTestCase {

	/**
	 * Gate under test.
	 *
	 * @var Superlative_Gate
	 */
	protected $gate;

	/**
	 * Setup each test.
	 */
	public function set_up() {
		parent::set_up();
		Gates::reset();
		$this->gate = new Superlative_Gate();
	}

	/**
	 * Reset the registry.
	 */
	public function tear_down() {
		Gates::reset();
		parent::tear_down();
	}

	/**
	 * Run the gate.
	 *
	 * @param string   $answer   Answer.
	 * @param string[] $passages Passage texts.
	 * @return bool Whether the answer passes.
	 */
	protected function passes( string $answer, array $passages ): bool {
		return ! $this->gate->run(
			[
				'content'  => $answer,
				'passages' => array_map(
					static function ( $text ) {
						return [
							'title'   => 'Report',
							'excerpt' => $text,
						];
					},
					$passages
				),
			]
		)->is_refuse();
	}

	/**
	 * Each rule refuses an unsupported ranking and passes a supported one.
	 *
	 * @group chat
	 */
	public function test_each_rule() {
		$plain = [ 'In Poland, 93% view the U.S. favorably. In Hungary, 44% do.' ];

		$this->assertFalse( $this->passes( 'Poland gave the **highest** share of favorable views (93%).', $plain ) );
		$this->assertTrue( $this->passes( 'Poland gave the highest share of favorable views (93%).', [ 'Poland gives the U.S. its highest rating: 93% favorable.' ] ) );

		$this->assertFalse( $this->passes( 'Hungary had the lowest share of favorable views, 44%.', $plain ) );
		$this->assertTrue( $this->passes( 'Hungary had the lowest share of favorable views, 44%.', [ 'The U.S. gets its lowest marks in Hungary, where 44% offer a positive view.' ] ) );

		$this->assertFalse( $this->passes( 'Poland is the only country where 93% agree.', $plain ) );
		$this->assertTrue( $this->passes( 'Poland is the only country where 93% agree.', [ 'Poland is the only country surveyed where 93% agree.' ] ) );

		$this->assertFalse( $this->passes( 'Poles are more positive than any other public, at 93%.', $plain ) );
		$this->assertTrue( $this->passes( 'Poles are more positive than any other public, at 93%.', [ 'Poles, at 93%, are more positive than any other public surveyed.' ] ) );
	}

	/**
	 * "Most" and "least" alone are not rankings to support; the claim check still flags them.
	 *
	 * @group chat
	 */
	public function test_most_is_not_refused_but_is_a_ranking_claim() {
		$this->assertTrue( $this->passes( 'Most Americans (58%) say gun laws should be stricter.', [ 'A majority, 58%, say laws should be stricter.' ] ) );
		$this->assertTrue( $this->gate->has_ranking_claim( 'Most Americans (58%) say so.' ) );
		$this->assertTrue( $this->gate->has_ranking_claim( 'Poles are more positive than any other public.' ) );
		$this->assertFalse( $this->gate->has_ranking_claim( 'About 58% say so.' ) );
	}

	/**
	 * The support must be in the passage that grounds the sentence's statistic; without a
	 * statistic, in a passage sentence about the same thing.
	 *
	 * @group chat
	 */
	public function test_support_location() {
		$passages = [
			'In Poland, 93% view the U.S. favorably.',
			'Among all the topics, the economy got the highest attention.',
		];

		// "highest" is in a passage, but not in the one with 93%.
		$this->assertFalse( $this->passes( 'Poland gave the highest share of favorable views (93%).', $passages ) );

		$this->assertTrue( $this->passes( 'Poland gives the U.S. the highest share of favorable ratings.', [ 'Among the countries surveyed, Poland gives the U.S. its highest favorable rating.' ] ) );
		$this->assertFalse( $this->passes( 'Poland gives the U.S. the highest share of favorable ratings.', [ 'The economy got the highest attention of all topics.' ] ) );
	}

	/**
	 * The rules are filterable, and Labs registers the gate with its reason and prompt line.
	 *
	 * @group chat
	 */
	public function test_rules_filter_and_registration() {
		add_filter(
			'ep_chat_superlative_rules',
			static function ( $rules ) {
				$rules[] = [
					'answer'  => '/\brecord high\b/iu',
					'support' => '/\brecord\b/iu',
				];

				return $rules;
			}
		);

		$this->assertFalse( $this->passes( 'Trust hit a record high of 71%.', [ 'Trust rose to 71%.' ] ) );
		$this->assertTrue( $this->passes( 'Trust hit a record high of 71%.', [ 'Trust rose to a record 71%.' ] ) );

		$this->assertFalse( Gates::registry()->get( Superlative_Gate::SLUG )->is_required() );
		$this->assertTrue( Gates::registry()->get( Superlative_Gate::SLUG )->is_enabled_by_default() );
		$this->assertArrayHasKey( 'comparison_not_in_sources', Gates::refusal_reasons() );
		$this->assertContains(
			'Do not write a ranking or superlative (largest, smallest, only, more than any) unless a source states that comparison.',
			Gates::instructions( [ 'optional_gates' => [ Superlative_Gate::SLUG ] ] )
		);
	}
}
