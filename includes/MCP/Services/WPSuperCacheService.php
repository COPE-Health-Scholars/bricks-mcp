<?php
/**
 * WP Super Cache service.
 *
 * Invalidates WP Super Cache's page cache when MCP writes change rendered output.
 *
 * WHY THIS EXISTS
 * ---------------
 * WP Super Cache purges on edit_post, publish_post, clean_post_cache and
 * transition_post_status — the hooks wp_update_post() fires. But this plugin's content writes go
 * through update_post_meta() on the Bricks meta keys, with Bricks' own meta filters deliberately
 * unhooked, and that fires nothing WP Super Cache listens to. Every element, settings, SEO or
 * template write over MCP therefore left the cached HTML stale: post-<id>.min.css was
 * regenerated, but the supercache file kept serving the old markup until expiry or a human save.
 * Only update_meta (title/status/slug) purged, incidentally, because it is the one path that
 * calls wp_update_post().
 *
 * The purge functions wrapped here (wp_cache_clear_cache, wp_cache_post_change,
 * wpsc_delete_url_cache) are defined in files WP Super Cache loads unconditionally, so they
 * exist during REST requests; function_exists() is the only detection needed.
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
 * WP Super Cache service class.
 */
final class WPSuperCacheService {

	/**
	 * Whether WP Super Cache is installed and loaded.
	 *
	 * @return bool True when the purge API is available.
	 */
	public function is_active(): bool {
		return function_exists( 'wp_cache_clear_cache' ) && function_exists( 'wp_cache_post_change' );
	}

	/**
	 * Purge the cached pages a post change invalidates.
	 *
	 * Delegates to wp_cache_post_change(), the same handler WP Super Cache runs on edit_post: it
	 * clears the post's page and the related pages (front page, feeds) whose rendered output the
	 * post appears in. Its own gates apply — non-public post types and drafts are skipped, which
	 * is correct here too: a draft has no cached page to purge.
	 *
	 * The post-status and post-type gates are mirrored BEFORE the call purely for honest
	 * reporting: wp_cache_post_change() returns the post ID whether it purged or declined, so
	 * asking it after the fact cannot distinguish the two.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Purge report, or null when WP Super Cache is absent.
	 */
	public function purge_post( int $post_id ): ?array {
		if ( ! $this->is_active() ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( null === $post ) {
			return array(
				'purged' => false,
				'reason' => 'post_not_found',
			);
		}

		$status = (string) ( $post->post_status ?? '' );
		if ( ! in_array( $status, array( 'publish', 'private' ), true ) ) {
			return array(
				'purged' => false,
				'reason' => 'not_published',
				'status' => $status,
			);
		}

		$result = wp_cache_post_change( $post_id );

		if ( false === $result ) {
			return array(
				'purged' => false,
				'reason' => 'gc_failed',
			);
		}

		return array(
			'purged'  => true,
			'post_id' => $post_id,
		);
	}

	/**
	 * Purge the cache for one URL.
	 *
	 * The wpsc_delete_url_cache() function resolves the URL against the site's home option and
	 * refuses any URL containing a query string — WP Super Cache never caches those anyway.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return array<string, mixed>|\WP_Error Purge report or an error.
	 */
	public function purge_url( string $url ): array|\WP_Error {
		$inactive = $this->require_active();
		if ( null !== $inactive ) {
			return $inactive;
		}

		if ( ! function_exists( 'wpsc_delete_url_cache' ) ) {
			return new \WP_Error(
				'bricks_mcp_wpsc_no_url_purge',
				__( 'This WP Super Cache version does not expose wpsc_delete_url_cache().', 'bricks-mcp' )
			);
		}

		if ( str_contains( $url, '?' ) ) {
			return new \WP_Error(
				'bricks_mcp_wpsc_query_string',
				__( 'WP Super Cache does not cache URLs with query strings, so there is nothing to purge for one. Pass the URL without its query string.', 'bricks-mcp' )
			);
		}

		$purged = (bool) wpsc_delete_url_cache( $url );

		return array(
			'purged' => $purged,
			'url'    => $url,
		);
	}

	/**
	 * Purge the entire page cache.
	 *
	 * Deliberately never called automatically: on a busy site a full purge sends every next
	 * request to PHP at once. It exists for the writes whose blast radius genuinely is the whole
	 * site — global classes, theme styles, palettes, variables — where per-post purging cannot
	 * know which cached pages use the changed token.
	 *
	 * @return array<string, mixed>|\WP_Error Purge report or an error.
	 */
	public function purge_all(): array|\WP_Error {
		$inactive = $this->require_active();
		if ( null !== $inactive ) {
			return $inactive;
		}

		// 0 clears the whole cache directory; a blog ID would scope it to one multisite blog.
		wp_cache_clear_cache( 0 );

		return array(
			'purged' => true,
			'scope'  => 'all',
		);
	}

	/**
	 * Error for the explicit purge actions when WP Super Cache is absent.
	 *
	 * The automatic write-path purge stays silent instead (see purge_post returning null): a site
	 * without a page cache has nothing stale, so a missing plugin is not an error there.
	 *
	 * @return \WP_Error|null Error to return, or null when the call may proceed.
	 */
	private function require_active(): ?\WP_Error {
		if ( $this->is_active() ) {
			return null;
		}

		return new \WP_Error(
			'bricks_mcp_wpsc_inactive',
			__( 'WP Super Cache is not installed or not active on this site.', 'bricks-mcp' )
		);
	}
}
