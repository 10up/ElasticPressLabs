<?php
/**
 * Test the Chat text helpers
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabsTest;

use ElasticPressLabs\Feature\Chat\Figures;
use ElasticPressLabs\Feature\Chat\Text;

/**
 * Sentences, markers, passages, model replies, and an answer's statistics.
 */
class TestChatText extends \WP_UnitTestCase {

	/**
	 * Lines split, then sentences; initials and decimals stay whole.
	 *
	 * @group chat
	 */
	public function test_split_sentences() {
		$this->assertSame(
			[ 'About 3.5% of U.S. adults do.', 'Teens differ.', '- 21% in 2021' ],
			Text::split_sentences( "About 3.5% of U.S. adults do. Teens differ.\n\n- 21% in 2021" )
		);
	}

	/**
	 * Markers go, with the space before them when asked; plain text also loses bold and code marks.
	 *
	 * @group chat
	 */
	public function test_markers_and_plain_text() {
		$this->assertSame( 'Most do .', Text::strip_markers( 'Most do [[1]].' ) );
		$this->assertSame( 'Most do.', Text::strip_markers( 'Most do [[1]][[2, 3]].', true ) );
		$this->assertSame( 'Most do 62%.', Text::to_plain( '**Most** do `62%`[[1]].' ) );
	}

	/**
	 * Passages as a list, and each one's text.
	 *
	 * @group chat
	 */
	public function test_passages() {
		$context = [
			'passages' => [
				'a' => [
					'title'   => 'Title',
					'excerpt' => 'Excerpt',
				],
				'b' => 'not a passage',
				'c' => [ 'text' => 'Prepared text' ],
			],
		];

		$this->assertCount( 2, Text::get_passages( $context ) );
		$this->assertSame( [ "Title\nExcerpt", 'Prepared text' ], Text::get_passage_texts( $context['passages'] ) );
	}

	/**
	 * The last balanced JSON object of a reply.
	 *
	 * @group chat
	 */
	public function test_last_json_object() {
		$this->assertSame( [ 'b' => [ 'c' => 1 ] ], Text::get_last_json_object( 'First {"a": 1}, then {"b": {"c": 1}} and done.' ) );
		$this->assertNull( Text::get_last_json_object( 'No object {here' ) );
	}

	/**
	 * An answer's statistics, with a list item taking its lead-in line.
	 *
	 * @group chat
	 */
	public function test_answer_statistics() {
		$found = ( new Figures() )->locate_answer_statistics(
			"Among teens:\n- 21% use it [[1]]\n\nIn 2024, 68% did.",
			static fn( $line ) => false !== strpos( $line, 'teens' ) ? 'teens' : null
		);

		$this->assertSame( [ '21%', '68%' ], wp_list_pluck( wp_list_pluck( $found, 'figure' ), 'figure' ) );
		$this->assertSame( [ 'teens', null ], wp_list_pluck( $found, 'lead' ) );
		$this->assertSame( '- 21% use it', $found[0]['sentence'] );
	}
}
