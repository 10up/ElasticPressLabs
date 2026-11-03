<?php
/**
 * Chat job dispatcher.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat\Jobs;

use ElasticPressLabs\Feature\Chat\Bots;
use ElasticPressLabs\Feature\Chat\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Selects mode and runner, then dispatches one question.
 */
class Dispatcher {
	/**
	 * Register worker hooks.
	 *
	 * @return void
	 */
	public function setup() {
		( new EPLabs_Async_Runner() )->setup();

		add_action( Action_Scheduler_Runner::HOOK, [ $this, 'run_job' ], 10, 2 );
	}

	/**
	 * Shared Action Scheduler / WP-Cron worker.
	 *
	 * @param int    $question_id Question comment ID.
	 * @param string $runner      Runner slug that enqueued the job.
	 * @return Job_Handle|\WP_Error
	 */
	public function run_job( $question_id, $runner = Job_Handle::RUNNER_WP_CRON ) {
		if ( Job_Handle::RUNNER_ACTION_SCHEDULER !== $runner ) {
			$runner = Job_Handle::RUNNER_WP_CRON;
		}

		return ( new Job_Processor() )->process(
			(int) $question_id,
			Job_Handle::MODE_ASYNC,
			$runner
		);
	}

	/**
	 * Dispatch one accepted question.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      ep_chat_id and question_id.
	 * @return Job_Handle|\WP_Error
	 */
	public function dispatch( string $question, array $history, array $bot, array $ids ) {
		$mode    = $this->resolve_mode( $question, $history, $bot, $ids );
		$runner  = $this->resolve_runner( $mode, $question, $history, $bot, $ids );
		$context = $this->store_context( $ids, $bot, $history );

		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return $runner->dispatch( $question, $history, $bot, $ids );
	}

	/**
	 * Persist bot and history on the question comment for the worker.
	 *
	 * @param array $ids     ep_chat_id and question_id.
	 * @param array $bot     Selected chat bot.
	 * @param array $history Prior turns.
	 * @return true|\WP_Error
	 */
	protected function store_context( array $ids, array $bot, array $history ) {
		return ( new Storage() )->set_job_context(
			(int) $ids['question_id'],
			(int) ( $bot['id'] ?? 0 ),
			$history
		);
	}

	/**
	 * Resolve sync vs async.
	 *
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      Persisted IDs.
	 * @return string
	 */
	protected function resolve_mode(
		string $question,
		array $history,
		array $bot,
		array $ids
	): string {
		$bots = new Bots();
		$mode = $bots->is_async_mode( (string) ( $bot['execution_mode'] ?? '' ) )
			? Job_Handle::MODE_ASYNC
			: Job_Handle::MODE_SYNC;

		/**
		 * Filters whether this Chat question runs sync or async.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_execution_mode
		 * @param {string} $mode     Sync or async. Default is the bot setting.
		 * @param {array}  $context  Question, history, bot, and persisted IDs.
		 * @return {string} Filtered mode.
		 */
		$filtered = apply_filters(
			'ep_chat_execution_mode',
			$mode,
			[
				'question' => $question,
				'history'  => $history,
				'bot'      => $bot,
				'ids'      => $ids,
			]
		);

		return Job_Handle::MODE_SYNC === $filtered
			? Job_Handle::MODE_SYNC
			: Job_Handle::MODE_ASYNC;
	}

	/**
	 * Resolve the runner instance.
	 *
	 * @param string $mode     Sync or async.
	 * @param string $question User question.
	 * @param array  $history  Prior turns.
	 * @param array  $bot      Selected chat bot.
	 * @param array  $ids      Persisted IDs.
	 * @return Runner
	 */
	protected function resolve_runner(
		string $mode,
		string $question,
		array $history,
		array $bot,
		array $ids
	): Runner {
		$default = Job_Handle::MODE_SYNC === $mode
			? new Sync_Runner()
			: $this->runner_for_slug( (string) ( $bot['execution_mode'] ?? '' ) );

		/**
		 * Filters the Chat runner instance.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_runner
		 * @param {Runner} $runner  Default runner for the resolved mode.
		 * @param {string} $mode    Sync or async.
		 * @param {array}  $context Question, history, bot, and persisted IDs.
		 * @return {Runner} Filtered runner.
		 */
		$runner = apply_filters(
			'ep_chat_runner',
			$default,
			$mode,
			[
				'question' => $question,
				'history'  => $history,
				'bot'      => $bot,
				'ids'      => $ids,
			]
		);

		if ( ! $runner instanceof Runner ) {
			$runner = $default;
		}

		if ( ! $runner->is_available() ) {
			$runner = new WP_Cron_Runner();
		}

		return $runner;
	}

	/**
	 * Runner for a stored bot slug. Unknown async slugs use WP Async.
	 *
	 * @param string $slug Bot execution_mode.
	 * @return Runner
	 */
	protected function runner_for_slug( string $slug ): Runner {
		if ( Job_Handle::RUNNER_ACTION_SCHEDULER === $slug ) {
			return new Action_Scheduler_Runner();
		}

		if ( Job_Handle::RUNNER_WP_CRON === $slug ) {
			return new WP_Cron_Runner();
		}

		return new EPLabs_Async_Runner();
	}
}
