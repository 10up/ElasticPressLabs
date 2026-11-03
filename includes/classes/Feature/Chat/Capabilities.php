<?php
/**
 * Chat capabilities.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPress\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The capability each Chat action needs.
 *
 * By default conversations use post capabilities, so nothing changes for anyone: Ask needs
 * `edit_posts`, everyone's conversations need `edit_others_posts`, and chat bots need
 * ElasticPress's capability. A site can give conversations their own capabilities:
 *
 * ```
 * add_filter( 'ep_chat_capability_type', fn() => [ 'ep_chat', 'ep_chats' ] );
 * ```
 *
 * Ask then needs `edit_ep_chats`, everyone's conversations `edit_others_ep_chats`, and so on.
 * No role has those, administrators included, so grant them: with a role editor, or once (for
 * example on activation) with Capabilities::grant_defaults().
 */
class Capabilities {
	/**
	 * Use Ask, and see and delete your own conversations.
	 *
	 * @var string
	 */
	public const ASK = 'ask';

	/**
	 * See and delete everyone's conversations.
	 *
	 * @var string
	 */
	public const OTHERS = 'others';

	/**
	 * Manage chat bots.
	 *
	 * @var string
	 */
	public const MANAGE_BOTS = 'manage_bots';

	/**
	 * Capability type of the conversation post type.
	 *
	 * @return string|string[] `post`, or a [ singular, plural ] pair.
	 */
	public static function get_capability_type() {
		/**
		 * Filters the capability type of Chat conversations (the `ep_chat` post type).
		 *
		 * The default, `post`, uses post capabilities. Return a [ singular, plural ] pair, such as
		 * `[ 'ep_chat', 'ep_chats' ]`, to give conversations their own capabilities
		 * (`edit_ep_chats`, `edit_others_ep_chats`, …). Grant them to roles, or nobody can use
		 * Chat: see Capabilities::grant_defaults().
		 *
		 * @since 2.6.0
		 * @hook ep_chat_capability_type
		 * @param {string|array} $capability_type Capability type. Default `post`.
		 * @return {string|array} Filtered capability type.
		 */
		$type = apply_filters( 'ep_chat_capability_type', 'post' );

		if ( is_array( $type ) ) {
			$type = array_values( array_filter( array_map( 'sanitize_key', $type ) ) );

			return 2 === count( $type ) ? $type : 'post';
		}

		$type = sanitize_key( (string) $type );

		return '' !== $type ? $type : 'post';
	}

	/**
	 * Primitive capabilities of the conversation post type.
	 *
	 * @return object Same shape as a post type's `cap` object.
	 */
	public static function get_post_type_capabilities() {
		return get_post_type_capabilities(
			(object) [
				'capability_type' => self::get_capability_type(),
				'map_meta_cap'    => true,
				'capabilities'    => [],
			]
		);
	}

	/**
	 * Capability for chat bots.
	 *
	 * @return string
	 */
	public static function get_bots_capability(): string {
		/**
		 * Filters the capability needed to manage Chat bots. Defaults to ElasticPress's
		 * capability (`ep_capability`, context `chat`), so this changes bots only.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_bots_capability
		 * @param {string} $capability Capability.
		 * @return {string} Filtered capability.
		 */
		return (string) apply_filters( 'ep_chat_bots_capability', Utils\get_capability( 'chat' ) );
	}

	/**
	 * The capability each Chat action needs.
	 *
	 * @return array<string, string> Action => capability.
	 */
	public static function get_map(): array {
		$cap = self::get_post_type_capabilities();
		$map = [
			self::ASK         => $cap->edit_posts,
			self::OTHERS      => $cap->edit_others_posts,
			self::MANAGE_BOTS => self::get_bots_capability(),
		];

		/**
		 * Filters the capability each Chat action needs.
		 *
		 * Actions: `ask` (use Ask; see and delete your own conversations), `others` (see and
		 * delete everyone's conversations), `manage_bots` (the AI Chat Bots screen and API).
		 * Access to a single conversation also goes through the `ep_chat` post type's meta
		 * capabilities (see `ep_chat_capability_type`).
		 *
		 * @since 2.6.0
		 * @hook ep_chat_capabilities
		 * @param {array} $map Action => capability.
		 * @return {array} Filtered map.
		 */
		$filtered = apply_filters( 'ep_chat_capabilities', $map );

		if ( ! is_array( $filtered ) ) {
			return $map;
		}

		foreach ( $map as $action => $capability ) {
			if ( isset( $filtered[ $action ] ) && is_string( $filtered[ $action ] ) && '' !== $filtered[ $action ] ) {
				$map[ $action ] = $filtered[ $action ];
			}
		}

		return $map;
	}

	/**
	 * Capability for one action.
	 *
	 * @param string $action Action (see the class constants).
	 * @return string
	 */
	public static function get( string $action ): string {
		$map = self::get_map();

		// An unknown action needs a capability nobody has.
		return $map[ $action ] ?? 'do_not_allow';
	}

	/**
	 * Whether the current user can do an action.
	 *
	 * @param string $action Action.
	 * @return bool
	 */
	public static function current_user_can( string $action ): bool {
		return current_user_can( self::get( $action ) );
	}

	/**
	 * Grant the conversation capabilities to roles, mirroring post access: roles that can edit
	 * posts get their own conversations, and roles that can edit others' posts get everyone's.
	 *
	 * Does nothing while conversations use post capabilities. Never runs by itself: call it once,
	 * for example on activation, after switching `ep_chat_capability_type`.
	 *
	 * @param string[]|null $roles Role slugs to grant to. Null means every role.
	 * @return string[] Roles that were changed.
	 */
	public static function grant_defaults( ?array $roles = null ): array {
		if ( 'post' === self::get_capability_type() ) {
			return [];
		}

		$cap     = self::get_post_type_capabilities();
		$own     = [ $cap->edit_posts, $cap->publish_posts, $cap->delete_posts, $cap->edit_published_posts, $cap->delete_published_posts ];
		$others  = [ $cap->edit_others_posts, $cap->delete_others_posts, $cap->edit_private_posts, $cap->delete_private_posts, $cap->read_private_posts ];
		$changed = [];

		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( null !== $roles && ! in_array( $slug, $roles, true ) ) {
				continue;
			}

			$grant = [];

			if ( $role->has_cap( 'edit_posts' ) ) {
				$grant = $own;
			}

			if ( $role->has_cap( 'edit_others_posts' ) ) {
				$grant = array_merge( $grant, $others );
			}

			foreach ( $grant as $capability ) {
				if ( ! $role->has_cap( $capability ) ) {
					$role->add_cap( $capability );
					$changed[ $slug ] = $slug;
				}
			}
		}

		return array_values( $changed );
	}
}
