<?php
/**
 * Tests for BricksService::save_elements() robustness.
 *
 * Every assertion here reads what the method actually did — the meta that
 * landed in the store, the calls that were recorded, the value returned.
 *
 * The previous version of this file asserted on substrings in the method body:
 * that `wp_cache_delete(` appeared at a lower string offset than
 * `update_post_meta(`, that the text `delete_post_meta` was present, that the
 * literal `save_elements_failed` occurred somewhere. All four would pass
 * against an implementation that contained the right words in the right order
 * and did nothing useful with them — for instance a `wp_cache_delete()` call on
 * the wrong cache group, or a `save_elements_failed` error that could never be
 * reached. They would also fail on a correct implementation that was merely
 * refactored. Substring order is not behaviour.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for save_elements() failure modes.
 */
final class SaveElementsTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var BricksService
	 */
	private BricksService $service;

	/**
	 * Install Bricks doubles and reset the in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();

		$GLOBALS['_bricks_mcp_test_generate_calls'] = array();
		$GLOBALS['_bricks_mcp_test_get_data_calls'] = array();
		$GLOBALS['_bricks_mcp_test_elements']       = array();

		\Bricks\Assets::$post_id    = 0;
		\Bricks\Assets::$inline_css = array();

		$this->service = new BricksService();
	}

	/**
	 * Reset injected failures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['_bricks_mcp_test_blocked_meta_keys'] = array();

		parent::tearDown();
	}

	/**
	 * A valid two-element tree.
	 *
	 * @return array<int, array<string, mixed>> Flat element array.
	 */
	private function elements(): array {
		return array(
			array(
				'id'       => 'aaa111',
				'name'     => 'section',
				'parent'   => 0,
				'children' => array( 'bbb222' ),
				'settings' => array(),
			),
			array(
				'id'       => 'bbb222',
				'name'     => 'heading',
				'parent'   => 'aaa111',
				'children' => array(),
				'settings' => array( 'text' => 'Hello' ),
			),
		);
	}

	/**
	 * Create a page and return its ID.
	 *
	 * @return int Page post ID.
	 */
	private function make_page(): int {
		return wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Fixture page',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * The recorded meta/cache calls, as "fn:key" strings in order.
	 *
	 * @return array<int, string> Recorded calls.
	 */
	private function recorded_calls(): array {
		return array_map(
			static fn( array $call ): string => $call['fn'] . ':' . $call['key'],
			$GLOBALS['_bricks_mcp_test_meta_calls'] ?? array()
		);
	}

	/**
	 * Index of the first recorded call matching "fn:key".
	 *
	 * @param string $needle Call signature.
	 * @return int Index, or -1 when absent.
	 */
	private function first_call( string $needle ): int {
		$index = array_search( $needle, $this->recorded_calls(), true );

		return false === $index ? -1 : (int) $index;
	}

	/**
	 * Index of the last recorded call matching "fn:key".
	 *
	 * @param string $needle Call signature.
	 * @return int Index, or -1 when absent.
	 */
	private function last_call( string $needle ): int {
		$calls = array_reverse( $this->recorded_calls(), true );
		$index = array_search( $needle, $calls, true );

		return false === $index ? -1 : (int) $index;
	}

	// -----------------------------------------------------------------------
	// The write lands
	// -----------------------------------------------------------------------

	/**
	 * A successful save must actually persist the elements.
	 *
	 * The baseline the other tests build on: without this, "returns true" proves
	 * nothing, since returning true is exactly what a silent failure did.
	 *
	 * @return void
	 */
	public function test_save_persists_the_elements(): void {
		$post_id = $this->make_page();

		$this->assertTrue( $this->service->save_elements( $post_id, $this->elements() ) );
		$this->assertSame( $this->elements(), $this->service->get_elements( $post_id ) );
	}

	/**
	 * The save must mark the post as using the Bricks editor.
	 *
	 * @return void
	 */
	public function test_save_marks_the_post_as_a_bricks_page(): void {
		$post_id = $this->make_page();

		$this->service->save_elements( $post_id, $this->elements() );

		$this->assertTrue( $this->service->is_bricks_page( $post_id ) );
	}

	// -----------------------------------------------------------------------
	// Cache clearing — asserted by call order, not source order
	// -----------------------------------------------------------------------

	/**
	 * The post_meta cache must be cleared before the write.
	 *
	 * Without this, update_post_meta compares against a stale cached value and
	 * can conclude the value is unchanged when the database disagrees.
	 *
	 * @return void
	 */
	public function test_cache_is_cleared_before_the_write(): void {
		$post_id = $this->make_page();

		$GLOBALS['_bricks_mcp_test_meta_calls'] = array();

		$this->service->save_elements( $post_id, $this->elements() );

		$cache_cleared = $this->first_call( 'wp_cache_delete:post_meta' );
		$written       = $this->first_call( 'update_post_meta:' . BricksService::META_KEY );

		$this->assertGreaterThan( -1, $cache_cleared, 'the post_meta cache must be cleared' );
		$this->assertGreaterThan( -1, $written, 'the content meta key must be written' );
		$this->assertLessThan( $written, $cache_cleared, 'the cache must be cleared before the write' );
	}

	/**
	 * The cache must be cleared again before the verification read-back.
	 *
	 * A read-back served from the cache the write just populated would confirm
	 * itself and verify nothing.
	 *
	 * @return void
	 */
	public function test_cache_is_cleared_again_before_the_verification_read(): void {
		$post_id = $this->make_page();

		$GLOBALS['_bricks_mcp_test_meta_calls'] = array();

		$this->service->save_elements( $post_id, $this->elements() );

		$written           = $this->first_call( 'update_post_meta:' . BricksService::META_KEY );
		$last_cache_clear  = $this->last_call( 'wp_cache_delete:post_meta' );

		$this->assertGreaterThan(
			$written,
			$last_cache_clear,
			'the cache must be cleared after the write, before reading back to verify'
		);
	}

	// -----------------------------------------------------------------------
	// The delete+add fallback
	// -----------------------------------------------------------------------

	/**
	 * Re-saving identical elements must still succeed and leave data intact.
	 *
	 * update_post_meta() returns false when the new value matches the stored
	 * one, which is indistinguishable from a failed write. Treating that as an
	 * error would break every idempotent redeploy.
	 *
	 * @return void
	 */
	public function test_resaving_identical_elements_still_succeeds(): void {
		$post_id = $this->make_page();

		$this->assertTrue( $this->service->save_elements( $post_id, $this->elements() ) );
		$this->assertTrue( $this->service->save_elements( $post_id, $this->elements() ) );
		$this->assertSame( $this->elements(), $this->service->get_elements( $post_id ) );
	}

	/**
	 * The no-op write must be forced through with delete + add.
	 *
	 * Asserts the fallback actually ran on the second save, rather than the save
	 * happening to succeed for some other reason.
	 *
	 * @return void
	 */
	public function test_identical_write_falls_back_to_delete_then_add(): void {
		$post_id = $this->make_page();

		$this->service->save_elements( $post_id, $this->elements() );

		$GLOBALS['_bricks_mcp_test_meta_calls'] = array();

		$this->service->save_elements( $post_id, $this->elements() );

		$deleted = $this->first_call( 'delete_post_meta:' . BricksService::META_KEY );
		$added   = $this->first_call( 'add_post_meta:' . BricksService::META_KEY );

		$this->assertGreaterThan( -1, $deleted, 'a no-op update must fall back to delete_post_meta' );
		$this->assertGreaterThan( -1, $added, 'a no-op update must fall back to add_post_meta' );
		$this->assertLessThan( $added, $deleted, 'the delete must precede the add' );
	}

	/**
	 * A first save must NOT take the fallback path.
	 *
	 * Guards the condition on the fallback. An implementation that always
	 * deleted and re-added would pass the test above while doing a destructive
	 * write on every call.
	 *
	 * @return void
	 */
	public function test_first_save_does_not_use_the_fallback(): void {
		$post_id = $this->make_page();

		$GLOBALS['_bricks_mcp_test_meta_calls'] = array();

		$this->service->save_elements( $post_id, $this->elements() );

		$this->assertSame(
			-1,
			$this->first_call( 'delete_post_meta:' . BricksService::META_KEY ),
			'a write that succeeded must not be deleted and re-added'
		);
	}

	// -----------------------------------------------------------------------
	// Verification
	// -----------------------------------------------------------------------

	/**
	 * A write that reports success but does not persist must be an error.
	 *
	 * This is the failure the read-back exists for: Bricks' own meta filters can
	 * reject a programmatic write, leaving the caller with a success response
	 * and an unchanged page.
	 *
	 * @return void
	 */
	public function test_write_that_does_not_persist_returns_wp_error(): void {
		$post_id = $this->make_page();

		$GLOBALS['_bricks_mcp_test_blocked_meta_keys'] = array( BricksService::META_KEY );

		$result = $this->service->save_elements( $post_id, $this->elements() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'save_elements_failed', $result->get_error_code() );
	}

	/**
	 * A partial write must also fail verification.
	 *
	 * The read-back compares counts, so storing some elements but not all is
	 * caught rather than reported as success.
	 *
	 * @return void
	 */
	public function test_partial_write_fails_verification(): void {
		$post_id = $this->make_page();

		// Pre-store a single element, then block the write so the read-back sees
		// the stale one-element array against a two-element save.
		update_post_meta( $post_id, BricksService::META_KEY, array( $this->elements()[0] ) );

		$GLOBALS['_bricks_mcp_test_blocked_meta_keys'] = array( BricksService::META_KEY );

		$result = $this->service->save_elements( $post_id, $this->elements() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'save_elements_failed', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Validation gates run before any write
	// -----------------------------------------------------------------------

	/**
	 * Broken parent/children linkage must be rejected without writing.
	 *
	 * Bricks renders a tree whose links disagree as an empty container rather
	 * than erroring, so a stored-but-broken tree is worse than a refusal.
	 *
	 * @return void
	 */
	public function test_broken_linkage_is_rejected_before_writing(): void {
		$post_id = $this->make_page();

		$broken = array(
			array(
				'id'       => 'aaa111',
				'name'     => 'section',
				'parent'   => 0,
				'children' => array( 'nonexistent' ),
				'settings' => array(),
			),
		);

		$result = $this->service->save_elements( $post_id, $broken );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			array(),
			$this->service->get_elements( $post_id ),
			'a rejected save must not have written anything'
		);
	}
}
