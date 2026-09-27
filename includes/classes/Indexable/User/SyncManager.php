<?php
/**
 * Manage syncing of content between WP and Elasticsearch for users
 *
 * @since 2.1.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Indexable\User;

use ElasticPress\Indexables;
use ElasticPress\Elasticsearch;
use ElasticPress\SyncManager as SyncManagerAbstract;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sync manager class
 */
class SyncManager extends SyncManagerAbstract {
	/**
	 * Authors to queue after WordPress completes bulk reassignment, by site.
	 *
	 * @var array
	 */
	protected $deferred_authors = [];

	/**
	 * Authors and fallback site IDs captured before site deletion.
	 *
	 * @var array
	 */
	protected $deleted_site_authors = [];

	/**
	 * Setup actions and filters
	 */
	public function setup() {
		if ( ! Elasticsearch::factory()->get_elasticsearch_version() ) {
			return;
		}

		add_action( 'delete_user', [ $this, 'action_delete_user' ] );
		add_action( 'wpmu_delete_user', [ $this, 'action_delete_user' ] );
		add_action( 'profile_update', [ $this, 'action_sync_on_update' ] );
		add_action( 'user_register', [ $this, 'action_sync_on_update' ] );
		add_action( 'updated_user_meta', [ $this, 'action_queue_meta_sync' ], 10, 4 );
		add_action( 'added_user_meta', [ $this, 'action_queue_meta_sync' ], 10, 4 );
		add_action( 'deleted_user_meta', [ $this, 'action_queue_meta_sync' ], 10, 4 );
		add_action( 'wp_after_insert_post', [ $this, 'action_sync_post_authors' ], 10, 4 );
		add_action( 'deleted_post', [ $this, 'action_sync_author_after_post_deletion' ], 10, 2 );
		add_action( 'deleted_user', [ $this, 'action_sync_reassigned_author' ], 10, 2 );
		add_action( 'remove_user_from_blog', [ $this, 'action_defer_reassigned_authors' ], 10, 3 );
		add_action( 'wp_uninitialize_site', [ $this, 'action_capture_site_authors' ], 1 );
		add_action( 'wp_delete_site', [ $this, 'action_sync_deleted_site_authors' ] );
		add_action( 'shutdown', [ $this, 'queue_deferred_authors' ], 5 );
		add_filter( 'wp_redirect', [ $this, 'queue_deferred_authors' ], 5 );

		// Clear index settings cache
		add_action( 'ep_update_index_settings', [ $this, 'clear_index_settings_cache' ] );
		add_action( 'ep_after_put_mapping', [ $this, 'clear_index_settings_cache' ] );
		add_action( 'ep_saved_weighting_configuration', [ $this, 'clear_index_settings_cache' ] );

		// @todo Handle deleted meta
	}

	/**
	 * Dummy implementation of site unsetup method (for now)
	 */
	public function tear_down() {
	}

	/**
	 * Queue an author without requiring an interactive user (scheduled publishing).
	 *
	 * @param int $user_id Author ID.
	 */
	protected function queue_author( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 || $this->kill_sync() || apply_filters( 'ep_user_sync_kill', false, $user_id ) ) {
			return;
		}

		$this->add_to_queue( $user_id );
	}

	/**
	 * Refresh authors only when publication, author, or post type changes.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Saved post.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Previous post, or null on insertion.
	 */
	public function action_sync_post_authors( $post_id, $post, $update, $post_before ) {
		if ( 'revision' === $post->post_type ) {
			return;
		}

		$was_published = $post_before && 'publish' === $post_before->post_status;
		$is_published  = 'publish' === $post->post_status;

		if ( ! $was_published && ! $is_published ) {
			return;
		}

		// Skip updates that leave publication status, author, and post type unchanged.
		if ( $post_before && $post_before->post_status === $post->post_status &&
			$post_before->post_author === $post->post_author && $post_before->post_type === $post->post_type ) {
			return;
		}

		// Refresh the previous author because this post no longer belongs to them.
		if ( $post_before && $post_before->post_author !== $post->post_author ) {
			$this->queue_author( $post_before->post_author );
		}

		$this->queue_author( $post->post_author );
	}

	/**
	 * Refresh after deletion so the removed post no longer contributes.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Deleted post.
	 */
	public function action_sync_author_after_post_deletion( $post_id, $post ) {
		if ( 'publish' === $post->post_status && 'revision' !== $post->post_type ) {
			$this->queue_author( $post->post_author );
		}
	}

	/**
	 * Refresh the recipient of wp_delete_user()'s direct SQL reassignment.
	 *
	 * @param int      $user_id  Deleted or removed user ID.
	 * @param int|null $reassign Receiving author ID.
	 */
	public function action_sync_reassigned_author( $user_id, $reassign ) {
		if ( get_userdata( $user_id ) ) {
			$this->queue_author( $user_id );
		} elseif ( ! $this->kill_sync() ) {
			// An early queue flush may have reindexed this user during post/meta deletion.
			Indexables::factory()->get( 'user' )->delete( $user_id, false );
		}

		$this->queue_author( $reassign );
	}

	/**
	 * Defer bulk reassignment because this hook runs before WordPress changes posts.
	 *
	 * @param int $user_id  Removed user ID.
	 * @param int $blog_id  Site ID.
	 * @param int $reassign Receiving author ID, or zero.
	 */
	public function action_defer_reassigned_authors( $user_id, $blog_id, $reassign ) {
		// Membership alone is already handled by the existing user-meta hooks.
		if ( ! $reassign || $this->kill_sync() ) {
			return;
		}

		foreach ( [ $user_id, $reassign ] as $author_id ) {
			if ( $author_id > 0 && ! apply_filters( 'ep_user_sync_kill', false, $author_id ) ) {
				$this->deferred_authors[ $blog_id ][ $author_id ] = true;
			}
		}
	}

	/**
	 * Add deferred authors before the normal shutdown or redirect queue flush.
	 *
	 * @param string|null $location Redirect location, when called as a filter.
	 * @return string|null Unchanged redirect location.
	 */
	public function queue_deferred_authors( $location = null ) {
		$authors                = $this->deferred_authors;
		$this->deferred_authors = [];

		foreach ( $authors as $blog_id => $user_ids ) {
			$switch = get_current_blog_id() !== (int) $blog_id;

			if ( $switch ) {
				switch_to_blog( $blog_id );
			}

			foreach ( array_keys( $user_ids ) as $user_id ) {
				$this->queue_author( $user_id );
			}

			if ( $switch ) {
				restore_current_blog();
			}
		}

		return $location;
	}

	/**
	 * Capture published authors, including nonmembers, before a site's tables disappear.
	 *
	 * @param \WP_Site $site Site being deleted.
	 */
	public function action_capture_site_authors( $site ) {
		global $wpdb;

		$this->deleted_site_authors[ $site->blog_id ] = [
			'blog_id' => get_main_site_id( $site->network_id ),
			'authors' => [],
		];

		if ( wp_is_site_initialized( $site ) ) {
			$posts_table = $wpdb->get_blog_prefix( $site->blog_id ) . 'posts';
			$this->deleted_site_authors[ $site->blog_id ]['authors'] = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT DISTINCT post_author FROM {$posts_table} WHERE post_status = 'publish' AND post_author > 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- %i is unavailable before WordPress 6.2.
			);
		}
	}

	/**
	 * Requeue affected users from a surviving site after deletion completes.
	 *
	 * @param \WP_Site $site Deleted site.
	 */
	public function action_sync_deleted_site_authors( $site ) {
		$fallback_blog_id = $this->deleted_site_authors[ $site->blog_id ]['blog_id'];
		$user_ids         = array_unique(
			array_merge(
				$this->deleted_site_authors[ $site->blog_id ]['authors'],
				array_keys( $this->sync_queue[ $site->blog_id ] ?? [] ),
				array_keys( $this->deferred_authors[ $site->blog_id ] ?? [] )
			)
		);
		unset( $this->deleted_site_authors[ $site->blog_id ], $this->sync_queue[ $site->blog_id ], $this->deferred_authors[ $site->blog_id ] );
		$switch = get_current_blog_id() === (int) $site->blog_id;

		if ( $switch ) {
			switch_to_blog( $fallback_blog_id );
		}

		foreach ( $user_ids as $user_id ) {
			$this->queue_author( $user_id );
		}

		if ( $switch ) {
			restore_current_blog();
		}
	}

	/**
	 * Avoid preparing users while site tables are being dropped.
	 */
	public function index_sync_queue() {
		if ( ! empty( $this->deleted_site_authors ) ) {
			return;
		}

		parent::index_sync_queue();
	}

	/**
	 * When whitelisted meta is updated/added/deleted, queue the object for reindex
	 *
	 * @param int       $meta_id Meta id.
	 * @param int|array $object_id Object id.
	 * @param string    $meta_key Meta key.
	 * @param string    $meta_value Meta value.
	 */
	public function action_queue_meta_sync( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( $this->kill_sync() ) {
			return;
		}

		/**
		 * Filter whether to kill sync for a particular user
		 *
		 * @hook ep_user_sync_kill
		 * @param {bool} $kill    True means dont sync
		 * @param {int}  $object_id User ID
		 * @return {bool} New kill value
		 */
		if ( apply_filters( 'ep_user_sync_kill', false, $object_id ) ) {
			return;
		}

		$this->add_to_queue( $object_id );
	}

	/**
	 * Delete ES user when WP user is deleted
	 *
	 * @param int $user_id User ID
	 */
	public function action_delete_user( $user_id ) {
		if ( $this->kill_sync() ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		Indexables::factory()->get( 'user' )->delete( $user_id, false );
	}

	/**
	 * Sync ES index with what happened to the user being saved
	 *
	 * @param int $user_id User id.
	 */
	public function action_sync_on_update( $user_id ) {
		if ( $this->kill_sync() ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		/**
		 * Filter whether to kill sync for a particular user
		 *
		 * @hook ep_user_sync_kill
		 * @param {bool} $kill    True means dont sync
		 * @param {int}  $user_id User ID
		 * @since 2.1.0
		 * @return {bool} New kill value
		 */
		if ( apply_filters( 'ep_user_sync_kill', false, $user_id ) ) {
			return;
		}

		/**
		 * Fires before adding user to sync queue
		 *
		 * @hook ep_sync_user_on_transition
		 * @param {int} $user_id User ID
		 * @since 2.1.0
		 */
		do_action( 'ep_sync_user_on_transition', $user_id );

		$this->add_to_queue( $user_id );
	}
}
