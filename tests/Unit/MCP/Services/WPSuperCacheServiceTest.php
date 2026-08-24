<?php
/**
 * WP Super Cache purge tests.
 *
 * The failure this surface fixes: MCP content writes go through update_post_meta(), which fires
 * none of the hooks WP Super Cache purges on, so every write left the cached page serving stale
 * markup while post-<id>.min.css was already regenerated. What matters here is that writes
 * request invalidation — asserted on the recorded purge calls, not on return values.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\WPSuperCacheService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WPSuperCacheService and the automatic write-path purge.
 */
final class WPSuperCacheServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var WPSuperCacheService
	 */
	private WPSuperCacheService $service;

	/**
	 * Reset doubles and seed a published post.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();
		bricks_mcp_test_reset_wpsc();
		$GLOBALS['_bricks_mcp_test_options']      = array();
		$GLOBALS['_bricks_mcp_test_did_actions']  = array();
		$GLOBALS['_bricks_mcp_test_elements']     = array();

		\Bricks\Assets::$inline_css = array( 'content' => '' );

		$this->service = new WPSuperCacheService();
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		bricks_mcp_test_reset_wpsc();
		bricks_mcp_test_reset_posts();

		parent::tearDown();
	}

	/**
	 * Seed a post with a given status.
	 *
	 * @param string $status Post status.
	 * @return int Post ID.
	 */
	private function seed_post( string $status = 'publish' ): int {
		return wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Cached Page',
				'post_status' => $status,
			)
		);
	}

	/**
	 * The IDs wp_cache_post_change() was called with.
	 *
	 * @return array<int, int> Post IDs.
	 */
	private function recorded_post_purges(): array {
		return $GLOBALS['_bricks_mcp_test_wpsc_post_purges'] ?? array();
	}

	/**
	 * Purging a published post delegates to WP Super Cache's own post-change handler.
	 *
	 * @return void
	 */
	public function test_purge_post_calls_wpsc_post_change(): void {
		$post_id = $this->seed_post();

		$result = $this->service->purge_post( $post_id );

		$this->assertTrue( $result['purged'] );
		$this->assertSame( array( $post_id ), $this->recorded_post_purges() );
	}

	/**
	 * A draft has no cached page; the skip is reported, and reported honestly — WP Super Cache's
	 * own handler returns the post ID whether it purged or declined, so the gate is mirrored
	 * before the call rather than divined after it.
	 *
	 * @return void
	 */
	public function test_draft_post_is_skipped_with_a_reason(): void {
		$post_id = $this->seed_post( 'draft' );

		$result = $this->service->purge_post( $post_id );

		$this->assertFalse( $result['purged'] );
		$this->assertSame( 'not_published', $result['reason'] );
		$this->assertSame( array(), $this->recorded_post_purges(), 'A skipped purge must not reach WP Super Cache' );
	}

	/**
	 * A failed GC lock must not be reported as a purge.
	 *
	 * @return void
	 */
	public function test_gc_failure_is_not_reported_as_purged(): void {
		$post_id = $this->seed_post();

		$GLOBALS['_bricks_mcp_test_wpsc_gc_fails'] = true;

		$result = $this->service->purge_post( $post_id );

		$this->assertFalse( $result['purged'] );
		$this->assertSame( 'gc_failed', $result['reason'] );
	}

	/**
	 * URL purges refuse query strings up front — WP Super Cache never caches those, so "purged"
	 * would be a lie.
	 *
	 * @return void
	 */
	public function test_url_purge_refuses_query_strings(): void {
		$result = $this->service->purge_url( 'https://example.org/page/?preview=1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpsc_query_string', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_bricks_mcp_test_wpsc_url_purges'] );
	}

	/**
	 * A clean URL purge runs and reports.
	 *
	 * @return void
	 */
	public function test_url_purge_runs_for_clean_urls(): void {
		$result = $this->service->purge_url( 'https://example.org/contact/' );

		$this->assertTrue( $result['purged'] );
		$this->assertSame( array( 'https://example.org/contact/' ), $GLOBALS['_bricks_mcp_test_wpsc_url_purges'] );
	}

	/**
	 * The full purge is the explicit action; it must actually clear everything.
	 *
	 * @return void
	 */
	public function test_full_purge_clears_the_whole_cache(): void {
		$result = $this->service->purge_all();

		$this->assertTrue( $result['purged'] );
		$this->assertSame( 1, $GLOBALS['_bricks_mcp_test_wpsc_full_purges'] );
	}

	/**
	 * The regression that motivated all of this: a content write must purge the post's cache.
	 * save_elements() writes meta directly, which fires nothing WP Super Cache listens to.
	 *
	 * @return void
	 */
	public function test_save_elements_purges_the_page_cache(): void {
		$post_id = $this->seed_post();

		$bricks = new BricksService();
		$bricks->set_page_cache_service( $this->service );

		$saved = $bricks->save_elements(
			$post_id,
			array(
				array(
					'id'       => 'abc123',
					'name'     => 'section',
					'parent'   => 0,
					'children' => array(),
					'settings' => array(),
				),
			)
		);

		$this->assertTrue( $saved );
		$this->assertSame( array( $post_id ), $this->recorded_post_purges(), 'The cached page is stale the moment the write lands' );
		$this->assertTrue( $bricks->get_last_cache_purge()['purged'] );
	}

	/**
	 * Page-settings writes change rendered output through a different meta key and must purge
	 * exactly the same way.
	 *
	 * @return void
	 */
	public function test_update_page_settings_purges_the_page_cache(): void {
		$post_id = $this->seed_post();

		$bricks = new BricksService();
		$bricks->set_page_cache_service( $this->service );

		$result = $bricks->update_page_settings( $post_id, array( 'bodyClasses' => 'landing' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( $post_id ), $this->recorded_post_purges() );
	}

	/**
	 * Without the service injected, writes behave exactly as before — no purge, no report.
	 *
	 * @return void
	 */
	public function test_writes_without_the_service_do_not_purge(): void {
		$post_id = $this->seed_post();

		$bricks = new BricksService();

		$bricks->update_page_settings( $post_id, array( 'bodyClasses' => 'landing' ) );

		$this->assertSame( array(), $this->recorded_post_purges() );
		$this->assertNull( $bricks->get_last_cache_purge() );
	}

	/**
	 * A failed save must not report a purge left over from an earlier successful call.
	 *
	 * @return void
	 */
	public function test_failed_save_clears_the_previous_purge_record(): void {
		$post_id = $this->seed_post();

		$bricks = new BricksService();
		$bricks->set_page_cache_service( $this->service );

		$element = array(
			'id'       => 'abc123',
			'name'     => 'section',
			'parent'   => 0,
			'children' => array(),
			'settings' => array(),
		);

		$this->assertTrue( $bricks->save_elements( $post_id, array( $element ) ) );
		$this->assertNotNull( $bricks->get_last_cache_purge() );

		// Invalid linkage: a parent that does not exist in the payload.
		$broken           = $element;
		$broken['parent'] = 'nonexistent';

		$this->assertInstanceOf( \WP_Error::class, $bricks->save_elements( $post_id, array( $broken ) ) );
		$this->assertNull( $bricks->get_last_cache_purge(), 'A failed write purged nothing and must say so' );
	}
}
