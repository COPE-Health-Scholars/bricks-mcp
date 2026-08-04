<?php
/**
 * Menu service for WordPress navigation menu CRUD operations.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MenuService class.
 *
 * Provides CRUD operations for WordPress navigation menus, item tree building,
 * and Bricks Builder template usage tracking for nav-menu elements.
 */
class MenuService {

	/**
	 * Create a new navigation menu.
	 *
	 * @param string $name Name for the new menu (must be unique).
	 * @return array{menu_id: int, name: string, slug: string}|\WP_Error Created menu data or error.
	 */
	public function create_menu( string $name ): array|\WP_Error {
		$term_id = wp_create_nav_menu( $name );

		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}

		$term = get_term( $term_id, 'nav_menu' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error(
				'menu_fetch_failed',
				/* translators: %d: menu ID */
				sprintf( __( 'Menu created (ID %d) but could not fetch its data.', 'bricks-mcp' ), $term_id )
			);
		}

		return array(
			'menu_id' => (int) $term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
		);
	}

	/**
	 * Update a navigation menu's name.
	 *
	 * @param int    $menu_id Menu ID (term_id) to update.
	 * @param string $name    New name for the menu.
	 * @return array{menu_id: int, name: string, slug: string}|\WP_Error Updated menu data or error.
	 */
	public function update_menu( int $menu_id, string $name ): array|\WP_Error {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new \WP_Error(
				'menu_not_found',
				/* translators: %d: menu ID */
				sprintf( __( 'Menu %d not found. Use list_menus to find valid menu IDs.', 'bricks-mcp' ), $menu_id )
			);
		}

		$result = wp_update_nav_menu_object(
			$menu_id,
			wp_slash( array( 'menu-name' => $name ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Re-fetch updated menu data.
		$updated_menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $updated_menu ) {
			return new \WP_Error(
				'menu_fetch_failed',
				/* translators: %d: menu ID */
				sprintf( __( 'Menu %d updated but could not fetch its data.', 'bricks-mcp' ), $menu_id )
			);
		}

		return array(
			'menu_id' => $menu_id,
			'name'    => $updated_menu->name,
			'slug'    => $updated_menu->slug,
		);
	}

	/**
	 * Delete a navigation menu and all its items.
	 *
	 * Handles full cleanup: deletes all items, removes location assignments.
	 *
	 * @param int $menu_id Menu ID (term_id) to delete.
	 * @return array{deleted: bool, menu_id: int, items_deleted: int, locations_cleared: array}|\WP_Error Deletion result or error.
	 */
	public function delete_menu( int $menu_id ): array|\WP_Error {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new \WP_Error(
				'menu_not_found',
				/* translators: %d: menu ID */
				sprintf( __( 'Menu %d not found. Use list_menus to find valid menu IDs.', 'bricks-mcp' ), $menu_id )
			);
		}

		// Capture item count before deletion.
		$items = wp_get_nav_menu_items( $menu_id );
		$count = is_array( $items ) ? count( $items ) : 0;

		// Get location assignments before deletion.
		$locations = $this->get_menu_locations_for_menu( $menu_id );

		$result = wp_delete_nav_menu( $menu_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'deleted'           => true,
			'menu_id'           => $menu_id,
			'items_deleted'     => $count,
			'locations_cleared' => $locations,
		);
	}

	/**
	 * Get a navigation menu with its items as a nested tree.
	 *
	 * Includes location assignments and Bricks template usage info.
	 *
	 * @param int $menu_id Menu ID (term_id) to retrieve.
	 * @return array{menu_id: int, name: string, slug: string, item_count: int, items: array, locations: array, bricks_usage: array}|\WP_Error Menu data or error.
	 */
	public function get_menu( int $menu_id ): array|\WP_Error {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new \WP_Error(
				'menu_not_found',
				/* translators: %d: menu ID */
				sprintf( __( 'Menu %d not found. Use list_menus to find valid menu IDs.', 'bricks-mcp' ), $menu_id )
			);
		}

		$items     = $this->build_item_tree( $menu_id );
		$locations = $this->get_menu_locations_for_menu( $menu_id );
		$usage     = $this->get_bricks_usage( $menu_id );

		return array(
			'menu_id'      => $menu_id,
			'name'         => $menu->name,
			'slug'         => $menu->slug,
			'item_count'   => (int) $menu->count,
			'items'        => $items,
			'locations'    => $locations,
			'bricks_usage' => $usage,
		);
	}

	/**
	 * List all navigation menus with item counts and location assignments.
	 *
	 * NOTE: Bricks usage is NOT included here (too expensive for list operations).
	 * Use get_menu to retrieve Bricks usage info for a specific menu.
	 *
	 * @return array{menus: array, total: int} List of menus with counts.
	 */
	public function list_menus(): array {
		$nav_menus = wp_get_nav_menus( array( 'orderby' => 'name' ) );

		if ( ! is_array( $nav_menus ) ) {
			return array(
				'menus' => array(),
				'total' => 0,
			);
		}

		$location_map = get_nav_menu_locations();
		if ( ! is_array( $location_map ) ) {
			$location_map = array();
		}

		$menus = array();
		foreach ( $nav_menus as $menu ) {
			$assigned_locations = array();
			foreach ( $location_map as $location_slug => $term_id ) {
				if ( (int) $term_id === (int) $menu->term_id ) {
					$assigned_locations[] = $location_slug;
				}
			}

			$menus[] = array(
				'menu_id'    => (int) $menu->term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'item_count' => (int) $menu->count,
				'locations'  => $assigned_locations,
			);
		}

		return array(
			'menus' => $menus,
			'total' => count( $menus ),
		);
	}

	/**
	 * Build a nested item tree from a flat WordPress menu items array.
	 *
	 * WordPress stores menu items as a flat array sorted by menu_order.
	 * This method reconstructs the hierarchical structure using parent/children references.
	 *
	 * @param int $menu_id Menu ID to retrieve items for.
	 * @return array Nested item tree (top-level items with children arrays).
	 */
	public function build_item_tree( int $menu_id ): array {
		$flat_items = wp_get_nav_menu_items( $menu_id );

		if ( empty( $flat_items ) || ! is_array( $flat_items ) ) {
			return array();
		}

		$by_id = array();

		// First pass: create a node for each item.
		foreach ( $flat_items as $item ) {
			$classes = isset( $item->classes ) && is_array( $item->classes )
				? array_values( array_filter( $item->classes ) )
				: array();

			$by_id[ $item->ID ] = array(
				'db_id'       => (int) $item->ID,
				'title'       => $item->title,
				'url'         => $item->url,
				'type'        => $item->type,
				'object'      => $item->object,
				'object_id'   => (int) $item->object_id,
				'target'      => $item->target,
				'classes'     => $classes,
				'xfn'         => $item->xfn,
				'attr_title'  => $item->attr_title,
				'description' => $item->description,
				'children'    => array(),
			);
		}

		$tree = array();

		// Second pass: build the hierarchy.
		foreach ( $flat_items as $item ) {
			$parent_id = (int) $item->menu_item_parent;

			if ( $parent_id > 0 && isset( $by_id[ $parent_id ] ) ) {
				$by_id[ $parent_id ]['children'][] = &$by_id[ $item->ID ];
			} else {
				$tree[] = &$by_id[ $item->ID ];
			}
		}

		// Break references to avoid memory leaks.
		unset( $by_id );

		return $tree;
	}

	/**
	 * Find Bricks templates and pages that reference a nav-menu element using this menu.
	 *
	 * Scans posts with Bricks content for nav-menu elements whose settings.menu matches $menu_id.
	 *
	 * @param int $menu_id Menu ID to search for.
	 * @return array Array of {post_id, post_title, post_type, element_id} matches.
	 */
	public function get_bricks_usage( int $menu_id ): array {
		$post_types = array( 'bricks_template' );

		// Include pages if Bricks is enabled for them.
		$bricks_settings = get_option( 'bricks_global_settings', array() );
		if ( is_array( $bricks_settings ) && ! empty( $bricks_settings['postTypes'] ) && is_array( $bricks_settings['postTypes'] ) ) {
			if ( in_array( 'page', $bricks_settings['postTypes'], true ) ) {
				$post_types[] = 'page';
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'meta_key'       => '_bricks_page_content_2', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'   => 'EXISTS',
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		$matches = array();

		foreach ( $posts as $post_id ) {
			$content = get_post_meta( $post_id, '_bricks_page_content_2', true );

			if ( ! is_array( $content ) ) {
				continue;
			}

			foreach ( $content as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				if ( isset( $element['name'] ) && 'nav-menu' === $element['name'] ) {
					$settings     = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
					$menu_setting = isset( $settings['menu'] ) ? (int) $settings['menu'] : 0;

					if ( $menu_setting === $menu_id ) {
						$post      = get_post( (int) $post_id );
						$matches[] = array(
							'post_id'    => (int) $post_id,
							'post_title' => $post ? $post->post_title : '',
							'post_type'  => $post ? $post->post_type : '',
							'element_id' => isset( $element['id'] ) ? $element['id'] : '',
						);
					}
				}
			}
		}

		return $matches;
	}

	/**
	 * Replace all items in a navigation menu with a new nested tree of items.
	 *
	 * Deletes all existing items first (force-delete, no trash), then inserts the
	 * new tree via wp_update_nav_menu_item. Validates post_type and taxonomy
	 * object references before insertion.
	 *
	 * @param int   $menu_id Menu ID (term_id) to set items on.
	 * @param array $items   Nested item tree to insert.
	 * @return array{menu_id: int, items_deleted: int, items_created: int, items: array}|\WP_Error Result or error.
	 */
	public function set_menu_items( int $menu_id, array $items ): array|\WP_Error {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new \WP_Error(
				'menu_not_found',
				/* translators: %d: menu ID */
				sprintf( __( 'Menu %d not found. Use list_menus to find valid menu IDs.', 'bricks-mcp' ), $menu_id )
			);
		}

		// Step 1: Delete all existing items.
		$existing_items = wp_get_nav_menu_items( $menu_id );
		$old_count      = 0;
		if ( is_array( $existing_items ) ) {
			$old_count = count( $existing_items );
			foreach ( $existing_items as $item ) {
				wp_delete_post( $item->ID, true );
			}
		}

		// Step 2: Insert new items from the nested tree.
		$result = $this->insert_items_recursive( $menu_id, $items, 0 );

		$response = array(
			'menu_id'       => $menu_id,
			'items_deleted' => $old_count,
			'items_created' => count( $result['created_ids'] ),
			'items'         => $this->build_item_tree( $menu_id ),
		);

		/*
		 * Surface per-item failures. insert_items_recursive() records a reason for every item it
		 * skips, but those were discarded: after force-deleting every existing item, a run where
		 * 4 of 10 items failed validation reported items_created: 6 and nothing else, so partial
		 * data loss on a destructive operation was indistinguishable from success.
		 */
		if ( ! empty( $result['errors'] ) ) {
			$failed_count = count( $result['errors'] );

			$response['items_failed'] = $failed_count;
			$response['errors']       = $result['errors'];
			$response['warning']      = sprintf(
				/* translators: %d: number of menu items that could not be created */
				_n(
					'%d menu item could not be created and was skipped. The previous items were already deleted, so this menu is now incomplete — review errors and re-send.',
					'%d menu items could not be created and were skipped. The previous items were already deleted, so this menu is now incomplete — review errors and re-send.',
					$failed_count,
					'bricks-mcp'
				),
				$failed_count
			);
		}

		return $response;
	}

	/**
	 * Recursively insert menu items from a nested tree.
	 *
	 * Validates item data before insertion. Continues processing remaining items
	 * even when individual items fail validation.
	 *
	 * @param int   $menu_id       Menu ID to insert items into.
	 * @param array $items         Array of item data to insert.
	 * @param int   $parent_db_id  DB ID of the parent item (0 for top-level).
	 * @return array{created_ids: int[], errors: array} Created IDs and any errors encountered.
	 */
	private function insert_items_recursive( int $menu_id, array $items, int $parent_db_id = 0 ): array {
		$position    = 1;
		$created_ids = array();
		$errors      = array();

		foreach ( $items as $item ) {
			// Validate required title.
			if ( empty( $item['title'] ) || ! is_string( $item['title'] ) ) {
				$errors[] = __( 'Item skipped: title is required and must be a non-empty string.', 'bricks-mcp' );
				++$position;
				continue;
			}

			$item_type = $item['type'] ?? 'custom';

			// Validate post_type items.
			if ( 'post_type' === $item_type ) {
				if ( empty( $item['object'] ) || ! isset( $item['object_id'] ) ) {
					$errors[] = sprintf(
						/* translators: %s: item title */
						__( 'Item "%s" skipped: post_type items require object (post type) and object_id.', 'bricks-mcp' ),
						$item['title']
					);
					++$position;
					continue;
				}

				$post = get_post( (int) $item['object_id'] );
				if ( null === $post ) {
					$errors[] = sprintf(
						/* translators: 1: item title, 2: object_id */
						__( 'Item "%1$s" skipped: post with ID %2$d not found.', 'bricks-mcp' ),
						$item['title'],
						(int) $item['object_id']
					);
					++$position;
					continue;
				}

				if ( get_post_type( (int) $item['object_id'] ) !== $item['object'] ) {
					$errors[] = sprintf(
						/* translators: 1: item title, 2: object, 3: actual post type */
						__( 'Item "%1$s" skipped: post %2$d is type "%3$s", not "%4$s".', 'bricks-mcp' ),
						$item['title'],
						(int) $item['object_id'],
						get_post_type( (int) $item['object_id'] ),
						$item['object']
					);
					++$position;
					continue;
				}
			}

			// Validate taxonomy items.
			if ( 'taxonomy' === $item_type ) {
				if ( empty( $item['object'] ) || ! isset( $item['object_id'] ) ) {
					$errors[] = sprintf(
						/* translators: %s: item title */
						__( 'Item "%s" skipped: taxonomy items require object (taxonomy) and object_id.', 'bricks-mcp' ),
						$item['title']
					);
					++$position;
					continue;
				}

				$term = get_term( (int) $item['object_id'] );
				if ( null === $term || is_wp_error( $term ) ) {
					$errors[] = sprintf(
						/* translators: 1: item title, 2: object_id */
						__( 'Item "%1$s" skipped: term with ID %2$d not found.', 'bricks-mcp' ),
						$item['title'],
						(int) $item['object_id']
					);
					++$position;
					continue;
				}
			}

			// Build item data for insertion.
			$item_data = array(
				'menu-item-title'       => $item['title'],
				'menu-item-url'         => $item['url'] ?? '',
				'menu-item-type'        => $item_type,
				'menu-item-object'      => $item['object'] ?? ( 'custom' === $item_type ? 'custom' : '' ),
				'menu-item-object-id'   => $item['object_id'] ?? 0,
				'menu-item-parent-id'   => $parent_db_id,
				'menu-item-position'    => $position,
				'menu-item-target'      => $item['target'] ?? '',
				'menu-item-classes'     => implode( ' ', (array) ( $item['classes'] ?? array() ) ),
				'menu-item-xfn'         => $item['xfn'] ?? '',
				'menu-item-attr-title'  => $item['attr_title'] ?? '',
				'menu-item-description' => $item['description'] ?? '',
				'menu-item-status'      => 'publish',
			);

			// Create the menu item (db_id = 0 means create new).
			$new_item_id = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $item_data ) );

			if ( is_wp_error( $new_item_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: item title, 2: error message */
					__( 'Item "%1$s" failed: %2$s', 'bricks-mcp' ),
					$item['title'],
					$new_item_id->get_error_message()
				);
			} else {
				$created_ids[] = $new_item_id;

				// Recurse into children if present.
				if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
					$child_result = $this->insert_items_recursive( $menu_id, $item['children'], $new_item_id );
					$created_ids  = array_merge( $created_ids, $child_result['created_ids'] );
					$errors       = array_merge( $errors, $child_result['errors'] );
				}
			}

			++$position;
		}

		return array(
			'created_ids' => $created_ids,
			'errors'      => $errors,
		);
	}

	/**
	 * Assign a navigation menu to a theme menu location.
	 *
	 * If the location is already assigned to another menu, that menu is unassigned
	 * from this location (but not deleted). A warning is included in the response.
	 *
	 * @param int    $menu_id  Menu ID (term_id) to assign.
	 * @param string $location Theme menu location slug.
	 * @return array{menu_id: int, location: string, assigned: bool}|\WP_Error Assignment result or error.
	 */
	public function assign_menu( int $menu_id, string $location ): array|\WP_Error {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new \WP_Error(
				'menu_not_found',
				/* translators: %d: menu ID */
				sprintf( __( 'Menu %d not found. Use list_menus to find valid menu IDs.', 'bricks-mcp' ), $menu_id )
			);
		}

		$registered_locations = get_registered_nav_menus();
		if ( ! isset( $registered_locations[ $location ] ) ) {
			return new \WP_Error(
				'location_not_found',
				sprintf(
					/* translators: %s: location slug */
					__( "Menu location '%s' not found. Use list_menu_locations to see available locations.", 'bricks-mcp' ),
					$location
				)
			);
		}

		$locations   = get_nav_menu_locations();
		$old_menu_id = $locations[ $location ] ?? 0;

		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		$response = array(
			'menu_id'  => $menu_id,
			'location' => $location,
			'assigned' => true,
		);

		if ( $old_menu_id > 0 && $old_menu_id !== $menu_id ) {
			$response['replaced_menu_id'] = $old_menu_id;
			$response['warning']          = sprintf(
				/* translators: 1: location slug, 2: old menu ID */
				__( "Location '%1\$s' was previously assigned to menu ID %2\$d. That menu still exists but is now unassigned from this location.", 'bricks-mcp' ),
				$location,
				$old_menu_id
			);
		}

		return $response;
	}

	/**
	 * Unassign a menu from a theme location without deleting the menu.
	 *
	 * The menu and its items are preserved — only the location assignment is removed.
	 *
	 * @param string $location Theme menu location slug to unassign.
	 * @return array{location: string, unassigned: bool}|\WP_Error Unassignment result or error.
	 */
	public function unassign_menu( string $location ): array|\WP_Error {
		$registered_locations = get_registered_nav_menus();
		if ( ! isset( $registered_locations[ $location ] ) ) {
			return new \WP_Error(
				'location_not_found',
				sprintf(
					/* translators: %s: location slug */
					__( "Menu location '%s' not found. Use list_menu_locations to see available locations.", 'bricks-mcp' ),
					$location
				)
			);
		}

		$locations   = get_nav_menu_locations();
		$old_menu_id = $locations[ $location ] ?? 0;

		if ( ! $old_menu_id ) {
			return array(
				'location'   => $location,
				'unassigned' => false,
				'message'    => __( 'Location has no menu assigned.', 'bricks-mcp' ),
			);
		}

		$locations[ $location ] = 0;
		set_theme_mod( 'nav_menu_locations', $locations );

		return array(
			'location'        => $location,
			'unassigned'      => true,
			'removed_menu_id' => $old_menu_id,
		);
	}

	/**
	 * List all registered theme menu locations with their current menu assignments.
	 *
	 * @return array{locations: array, total: int} Registered locations with assignment data.
	 */
	public function list_locations(): array {
		$registered  = get_registered_nav_menus();
		$assignments = get_nav_menu_locations();

		if ( ! is_array( $assignments ) ) {
			$assignments = array();
		}

		$locations = array();
		foreach ( $registered as $slug => $label ) {
			$assigned_menu_id = isset( $assignments[ $slug ] ) ? (int) $assignments[ $slug ] : 0;
			$menu_name        = '';

			if ( $assigned_menu_id > 0 ) {
				$assigned_menu = wp_get_nav_menu_object( $assigned_menu_id );
				$menu_name     = $assigned_menu ? $assigned_menu->name : '';
			}

			$locations[] = array(
				'slug'      => $slug,
				'label'     => $label,
				'menu_id'   => $assigned_menu_id,
				'menu_name' => $menu_name,
			);
		}

		return array(
			'locations' => $locations,
			'total'     => count( $registered ),
		);
	}

	/**
	 * Get theme location slugs assigned to a specific menu.
	 *
	 * @param int $menu_id Menu ID (term_id) to look up.
	 * @return string[] Array of theme location slug strings.
	 */
	public function get_menu_locations_for_menu( int $menu_id ): array {
		$location_map = get_nav_menu_locations();

		if ( ! is_array( $location_map ) ) {
			return array();
		}

		$locations = array();
		foreach ( $location_map as $location_slug => $term_id ) {
			if ( (int) $term_id === $menu_id ) {
				$locations[] = $location_slug;
			}
		}

		return $locations;
	}
}
