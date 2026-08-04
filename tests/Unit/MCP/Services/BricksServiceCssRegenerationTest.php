<?php
/**
 * Unit test: BricksService::trigger_css_regeneration() behaviour.
 *
 * These are behavioural tests, not source-inspection tests: they install fake \Bricks\*
 * classes and assert on the call that was actually made. The bug being guarded against here
 * would have survived any plausible source grep - the old implementation called a real Bricks
 * method with a real-looking argument and fired a Bricks-namespaced action. Both were no-ops
 * (the action has no listener in Bricks core; the method writes no file), so only asserting on
 * the recorded call distinguishes a working implementation from a broken one.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for CSS file regeneration after a programmatic save.
 */
final class BricksServiceCssRegenerationTest extends TestCase {

	/**
	 * Install the \Bricks\* doubles and reset their recorded state.
	 *
	 * The require lives here rather than at file scope on purpose: PHPUnit loads every test
	 * file up front to discover classes, so a file-scope require would make
	 * is_bricks_active() true for the entire run, including tests that assume otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/bricks-classes.php';

		$GLOBALS['_bricks_mcp_test_generate_calls'] = array();
		$GLOBALS['_bricks_mcp_test_get_data_calls'] = array();
		$GLOBALS['_bricks_mcp_test_legacy_calls']   = array();
		$GLOBALS['_bricks_mcp_test_did_actions']    = array();
		$GLOBALS['_bricks_mcp_test_elements']       = array(
			array(
				'id'     => 'aaa111',
				'name'   => 'section',
				'parent' => 0,
			),
		);

		unset(
			$GLOBALS['_bricks_mcp_test_template_type'],
			$GLOBALS['_bricks_mcp_test_generate_return'],
			$GLOBALS['_bricks_mcp_test_generate_throws']
		);

		$this->reset_inline_css();
	}

	/**
	 * Restore Bricks' pre-declared accumulator keys.
	 *
	 * @return void
	 */
	private function reset_inline_css(): void {
		\Bricks\Assets::$post_id    = 0;
		\Bricks\Assets::$inline_css = array(
			'theme_style' => 'PRE-EXISTING',
			'global'      => 'PRE-EXISTING',
			'page'        => 'PRE-EXISTING',
			'content'     => 'PRE-EXISTING',
		);
	}

	/**
	 * Invoke the private method under test.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Returned CSS file name.
	 */
	private function regenerate( int $post_id = 3331 ): ?string {
		$service    = new BricksService();
		$reflection = new \ReflectionMethod( $service, 'trigger_css_regeneration' );
		$reflection->setAccessible( true );

		/** @var string|null $result */
		$result = $reflection->invoke( $service, $post_id );
		return $result;
	}

	/**
	 * Fetch the nth recorded generate_post_css_file() call.
	 *
	 * @param int $index Call index.
	 * @return array<string, mixed> Recorded call.
	 */
	private function generate_call( int $index = 0 ): array {
		$this->assertArrayHasKey(
			$index,
			$GLOBALS['_bricks_mcp_test_generate_calls'],
			'Expected a recorded Assets_Files::generate_post_css_file() call'
		);
		return $GLOBALS['_bricks_mcp_test_generate_calls'][ $index ];
	}

	/**
	 * The file-writing API must actually be called. This is the whole bug.
	 *
	 * @return void
	 */
	public function test_calls_the_file_writing_api(): void {
		$this->regenerate( 3331 );

		$this->assertCount(
			1,
			$GLOBALS['_bricks_mcp_test_generate_calls'],
			'Assets_Files::generate_post_css_file() must be called exactly once - it is the only Bricks API that writes post-<id>.min.css'
		);
	}

	/**
	 * The post ID must land in the post ID parameter, and the area in the area parameter.
	 *
	 * Regression guard for the original defect: the post ID was passed into the $css_type
	 * parameter of Assets::generate_css_from_elements(), which silently wrote nothing to disk.
	 *
	 * @return void
	 */
	public function test_passes_post_id_and_area_in_the_right_positions(): void {
		$GLOBALS['_bricks_mcp_test_template_type'] = 'content';

		$this->regenerate( 3331 );
		$call = $this->generate_call();

		$this->assertSame( 3331, $call['post_id'], 'post_id must be passed as the post ID' );
		$this->assertSame( 'content', $call['content_type'], 'content_type must be the Bricks area string, never the post ID' );
		$this->assertNotSame( $call['post_id'], $call['content_type'], 'the post ID must never be used as the content type' );
	}

	/**
	 * A regular page has an empty template type, and Bricks forwards that verbatim.
	 *
	 * Guards against "helpfully" substituting 'content', which would diverge from the CSS a
	 * real builder save produces for a non-template page.
	 *
	 * @return void
	 */
	public function test_passes_empty_area_through_for_a_regular_page(): void {
		$GLOBALS['_bricks_mcp_test_template_type'] = '';

		$this->regenerate( 3331 );

		$this->assertSame( '', $this->generate_call()['content_type'], 'An empty template type must be forwarded unchanged, matching Bricks\' own save_post handler' );
		$this->assertSame( array( 3331, '' ), $GLOBALS['_bricks_mcp_test_get_data_calls'][0], 'Element data must be read for the same area that is passed to the generator' );
	}

	/**
	 * Template areas must regenerate against their own content area.
	 *
	 * @param string $area Bricks content area.
	 * @return void
	 *
	 * @dataProvider provide_template_areas
	 */
	public function test_forwards_template_areas( string $area ): void {
		$GLOBALS['_bricks_mcp_test_template_type'] = $area;

		$this->regenerate( 4242 );

		$this->assertSame( $area, $this->generate_call()['content_type'] );
		$this->assertSame( array( 4242, $area ), $GLOBALS['_bricks_mcp_test_get_data_calls'][0], 'Elements must be read from the matching area, or a header template would regenerate from page content' );
	}

	/**
	 * Areas to check.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_template_areas(): array {
		return array(
			'header'  => array( 'header' ),
			'footer'  => array( 'footer' ),
			'content' => array( 'content' ),
			'popup'   => array( 'popup' ),
		);
	}

	/**
	 * Assets::$post_id must be set, because generate_post_css_file() reads it.
	 *
	 * @return void
	 */
	public function test_sets_assets_post_id_before_generating(): void {
		$this->regenerate( 3331 );

		$this->assertSame(
			3331,
			$this->generate_call()['assets_post_id'],
			'Assets::$post_id must be set before generation - the template-preview path reads it and it defaults to 0'
		);
	}

	/**
	 * Only the target area key may be reset, never the whole accumulator.
	 *
	 * Bricks never resets Assets::$inline_css itself, so a second regeneration in the same
	 * request would otherwise append the first post's CSS to the second post's file. Clearing
	 * the entire array instead would discard Bricks' pre-declared keys.
	 *
	 * @return void
	 */
	public function test_resets_only_the_target_area_of_the_inline_css_accumulator(): void {
		$GLOBALS['_bricks_mcp_test_template_type'] = 'content';
		\Bricks\Assets::$inline_css['content']     = 'LEFTOVER FROM PREVIOUS POST';

		$this->regenerate( 3331 );
		$inline_css = $this->generate_call()['inline_css'];

		$this->assertSame( '', $inline_css['content'], 'The target area must be cleared so it cannot inherit a previous post\'s CSS' );
		$this->assertSame( 'PRE-EXISTING', $inline_css['theme_style'], 'Unrelated pre-declared keys must survive' );
		$this->assertSame( 'PRE-EXISTING', $inline_css['global'], 'Unrelated pre-declared keys must survive' );
		$this->assertSame( 'PRE-EXISTING', $inline_css['page'], 'Unrelated pre-declared keys must survive' );
	}

	/**
	 * Successive regenerations in one request must not accumulate.
	 *
	 * @return void
	 */
	public function test_second_regeneration_does_not_inherit_the_first(): void {
		$GLOBALS['_bricks_mcp_test_template_type'] = 'content';

		$this->regenerate( 3331 );
		\Bricks\Assets::$inline_css['content'] = 'CSS FROM POST 3331';
		$this->regenerate( 3332 );

		$second = $this->generate_call( 1 );

		$this->assertSame( '', $second['inline_css']['content'], 'Post 3332 must not start with post 3331 CSS in the accumulator' );
		$this->assertSame( 3332, $second['assets_post_id'] );
	}

	/**
	 * The written file name must be returned so callers can prove regeneration happened.
	 *
	 * @return void
	 */
	public function test_returns_written_file_name(): void {
		$GLOBALS['_bricks_mcp_test_generate_return'] = 'post-3331.min.css';

		$this->assertSame( 'post-3331.min.css', $this->regenerate( 3331 ) );
	}

	/**
	 * No file written (e.g. cssLoading is not "file") must surface as null, not false success.
	 *
	 * @return void
	 */
	public function test_returns_null_when_no_file_written(): void {
		$GLOBALS['_bricks_mcp_test_generate_return'] = null;

		$this->assertNull( $this->regenerate( 3331 ) );
	}

	/**
	 * The courtesy hook is retained for third-party listeners.
	 *
	 * @return void
	 */
	public function test_still_fires_the_courtesy_action(): void {
		$this->regenerate( 3331 );

		$hooks = array_column( $GLOBALS['_bricks_mcp_test_did_actions'], 'hook' );

		$this->assertContains( 'bricks/save_post', $hooks, 'The bricks/save_post action is retained for any third-party listeners' );
	}

	/**
	 * Firing that action is not sufficient - Bricks core registers no listener on it.
	 *
	 * @return void
	 */
	public function test_does_not_rely_on_the_action_for_file_generation(): void {
		$this->regenerate( 3331 );

		$this->assertNotEmpty(
			$GLOBALS['_bricks_mcp_test_generate_calls'],
			'Bricks core registers no listener on bricks/save_post, so the file API must be called directly'
		);
	}

	/**
	 * The accumulator-only API must not be used as the generation mechanism.
	 *
	 * Bricks calls it internally from generate_post_css_file(); what must not happen is the
	 * MCP calling it directly and treating that as a regeneration.
	 *
	 * @return void
	 */
	public function test_does_not_call_the_accumulator_api_directly(): void {
		$this->regenerate( 3331 );

		$this->assertSame(
			array(),
			$GLOBALS['_bricks_mcp_test_legacy_calls'],
			'Assets::generate_css_from_elements() writes no file; calling it directly is the original defect'
		);
	}

	/**
	 * A throwing generator must be swallowed - a CSS failure must not fail the content save.
	 *
	 * @return void
	 */
	public function test_swallows_generator_exceptions(): void {
		$GLOBALS['_bricks_mcp_test_generate_throws'] = 'disk full';

		$this->assertNull( $this->regenerate( 3331 ), 'A failed regeneration reports null rather than propagating' );
	}
}
