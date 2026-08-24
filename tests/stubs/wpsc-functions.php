<?php
/**
 * WP Super Cache test doubles.
 *
 * Record purge calls so tests can assert a write actually requested invalidation — the failure
 * mode this surface exists to fix is precisely a write that succeeds while the cached page keeps
 * serving stale markup, so what matters is whether the purge was called, not what it returned.
 *
 * @package BricksMCP\Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Reset the WP Super Cache double state.
 *
 * @return void
 */
function bricks_mcp_test_reset_wpsc(): void {
	$GLOBALS['_bricks_mcp_test_wpsc_post_purges'] = array();
	$GLOBALS['_bricks_mcp_test_wpsc_url_purges']  = array();
	$GLOBALS['_bricks_mcp_test_wpsc_full_purges'] = 0;
	$GLOBALS['_bricks_mcp_test_wpsc_gc_fails']    = false;
}

if ( ! function_exists( 'wp_cache_post_change' ) ) {
	/**
	 * Record a post-change purge.
	 *
	 * @param mixed $post_id Post ID.
	 * @return mixed The post ID, or false when the GC lock could not be taken.
	 */
	function wp_cache_post_change( mixed $post_id = 0 ): mixed {
		if ( ! empty( $GLOBALS['_bricks_mcp_test_wpsc_gc_fails'] ) ) {
			return false;
		}

		$GLOBALS['_bricks_mcp_test_wpsc_post_purges'][] = (int) $post_id;

		return $post_id;
	}
}

if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
	/**
	 * Record a URL purge, refusing query strings as the real function does.
	 *
	 * @param string $url URL to purge.
	 * @return bool True when the purge ran.
	 */
	function wpsc_delete_url_cache( string $url = '' ): bool {
		if ( str_contains( $url, '?' ) ) {
			return false;
		}

		$GLOBALS['_bricks_mcp_test_wpsc_url_purges'][] = $url;

		return true;
	}
}

if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
	/**
	 * Record a full purge.
	 *
	 * @param mixed $blog_id Blog ID (0 clears everything).
	 * @return void
	 */
	function wp_cache_clear_cache( mixed $blog_id = 0 ): void {
		$GLOBALS['_bricks_mcp_test_wpsc_full_purges'] = ( $GLOBALS['_bricks_mcp_test_wpsc_full_purges'] ?? 0 ) + 1;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
