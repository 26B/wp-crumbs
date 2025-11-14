<?php

namespace TSB\WP\Plugin\Crumbs;

/**
 * Class MenuCrumbs.
 *
 * Making breadcrumbs via a menu.
 *
 * @package TSB\WP\Plugin\Crumbs
 */
class MenuCrumbs {

	/**
	 * Get crumbs from a menu.
	 *
	 * @param string $menu_name      Menu name.
	 * @param bool   $network_origin Whether to include network origin (main site) in crumbs.
	 * @return array                 Crumbs array.
	 */
	public static function get_crumbs( string $menu_name, bool $network_origin = false ) : array {

		// No menu name, no crumbs.
		if ( empty( $menu_name ) ) {
			return [];
		}

		// Get the queried object.
		$queried_object = \get_queried_object();

		/**
		 * Filter the queried object before generating the breadcrumbs.
		 *
		 * @param WP_Post|WP_Term|mixed $queried_object The queried object.
		 * @return WP_Post|WP_Term|mixed The modified queried object.
		 */
		$queried_object = apply_filters( 'wp_crumbs_menu_queried_object', $queried_object );

		// No queried object, no crumbs.
		if ( ! isset( $queried_object ) || ! is_object( $queried_object ) ) {
			return [];
		}

		// Base crumbs, assuming it exists in the menu.
		$object_id = $queried_object->ID ?? $queried_object->term_id ?? null;
		$object    = $queried_object->post_type ?? $queried_object->taxonomy ?? $queried_object->name;
		$post_type = 'post_type';

		// If the queried object is a term, set the post type accordingly.
		if ( $queried_object instanceof \WP_Term ) {
			$post_type = 'taxonomy';
		}

		// If no object ID and is post type archive, adjust accordingly.
		if ( is_null( $object_id ) && $post_type === 'post_type' ) {
			$post_type = "{$post_type}_archive";
		}

		// Get crumbs from menu.
		$crumbs = self::get_menu_path( $menu_name, $object_id, $object, $post_type );

		// Current single post.
		if ( \is_singular() && empty( $crumbs ) ) {
			$post_type = \get_post_type();
			$crumbs    = [
				...self::get_menu_path( $menu_name, null, $post_type, 'post_type_archive' ),
				[
					'title' => \get_the_title(),
					'url'   => \get_permalink(),
				],
			];
		}

		// No breadcrumbs if we don't have anything here.
		if ( empty( $crumbs ) ) {
			return [];
		}

		$site_id = \get_current_blog_id();

		// If network origin is not required or not multisite, return crumbs with the current site homepage.
		if ( ! $network_origin || ! is_multisite() ) {
			$name   = get_bloginfo( 'name' );
			$crumbs = [
				[
					'title'    => $name,
					'url'      => \get_home_url(),
					'homepage' => true,
					'blog_id'  => \get_current_blog_id(),
				],
				...$crumbs,
			];
			return $crumbs;
		}

		// Add main site and sub site homepages.

		$main_site_id = get_main_site_id();
		if ( $site_id !== $main_site_id ) {
			$sub_site = \get_site( $site_id );
			$crumbs   = [
				[
					'title'    => $sub_site->blogname,
					'url'      => \home_url(),
					'homepage' => true,
					'blog_id'  => $site_id,
				],
				...$crumbs,
			];
		}

		$main_site = \get_site( $main_site_id );
		$crumbs    = [
			[
				'title'    => $main_site->blogname,
				'url'      => network_home_url(),
				'homepage' => true,
				'blog_id'  => 1,  // TODO: main site might not be ID 1.
			],
			...$crumbs,
		];

		return $crumbs;
	}

	/**
	 * Get menu path recursively.
	 *
	 * @param string   $menu_name Menu name.
	 * @param int|null $object_id Object ID.
	 * @param string   $object    Object type.
	 * @param string   $type      Type.
	 * @return array              Crumbs array.
	 */
	private static function get_menu_path( string $menu_name, ?int $object_id, string $object, string $type ) : array {
		$locations  = \get_nav_menu_locations();
		$menu_id    = $locations[ $menu_name ] ?? 0;
		$menu_items = \wp_get_nav_menu_items( $menu_id );

		$items = self::make_tree(
			array_map(
				fn ( $item ) => [
					'id'        => (int) $item->ID,
					'parent_id' => (int) $item->menu_item_parent,
					'children'  => [],
					'title'     => $item->title,
					'url'       => $item->url,
					'object_id' => $item->object_id,
					'object'    => $item->object,
					'type'      => $item->type,
				],
				is_array( $menu_items ) ? $menu_items : []
			)
		);

		return self::get_menu_path_recursive( $object_id, $object, $type, $items );
	}

	/**
	 * Get menu path recursive helper.
	 *
	 * @param int|null $object_id Object ID.
	 * @param string   $object    Object type.
	 * @param string   $type      Type.
	 * @param array    $items     Menu items.
	 * @return array              Crumbs array.
	 */
	private static function get_menu_path_recursive( ?int $object_id, string $object, string $type, array $items ) : array {
		foreach ( $items as $item ) {
			if (
				( (int) $item['object_id'] === $object_id || (int) $item['object_id'] < 0 )
				&& $item['object'] === $object
				&& $item['type'] === $type
			) {
				return [
					[
						'url'   => $item['url'],
						'title' => $item['title'],
					],
				];
			}

			if ( empty( $item['children'] ) ) {
				continue;
			}

			$child_path = self::get_menu_path_recursive( $object_id, $object, $type, $item['children'] );

			if ( ! empty( $child_path ) ) {
				return [
					[
						'url'   => $item['url'],
						'title' => $item['title'],
					],
					...$child_path,
				];
			}
		}

		return [];
	}

	/**
	 * Make tree from flat items.
	 *
	 * @param array $items Flat items.
	 * @param int   $parent_id Parent ID.
	 * @return array Tree items.
	 */
	private static function make_tree( array $items, $parent_id = 0 ) : array {
		$branch = [];

		foreach ( $items as $item ) {
			if ( $item['parent_id'] === $parent_id ) {
				$item['children'] = self::make_tree( $items, $item['id'] );
				$branch[]         = $item;
			}
		}
		return $branch;
	}
}
