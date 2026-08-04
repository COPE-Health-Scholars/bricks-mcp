<?php
/**
 * Unit test: BricksService::apply_template() and the element ID remap helpers.
 *
 * Behavioural throughout: every assertion reads the meta that was actually written or the
 * Assets_Files::generate_post_css_file() call that was actually recorded. The failure mode
 * these guard against is a tree that looks right element-by-element but whose parent/children
 * links no longer agree - Bricks renders that as an empty container rather than erroring.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for server-side template application.
 */
final class BricksServiceApplyTemplateTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var BricksService
	 */
	private BricksService $service;

	/**
	 * Install Bricks doubles, reset the in-memory post store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();

		$GLOBALS['_bricks_mcp_test_generate_calls'] = array();
		$GLOBALS['_bricks_mcp_test_get_data_calls'] = array();
		$GLOBALS['_bricks_mcp_test_elements']       = array();

		unset(
			$GLOBALS['_bricks_mcp_test_template_type'],
			$GLOBALS['_bricks_mcp_test_generate_return'],
			$GLOBALS['_bricks_mcp_test_generate_throws']
		);

		\Bricks\Assets::$post_id    = 0;
		\Bricks\Assets::$inline_css = array();

		$this->service = new BricksService();
	}

	/**
	 * Reset globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['_bricks_mcp_test_template_type'],
			$GLOBALS['_bricks_mcp_test_generate_return']
		);

		parent::tearDown();
	}

	/**
	 * A three-element tree: one section with two children.
	 *
	 * @param string $prefix Two-character prefix so distinct trees get distinct IDs.
	 * @return array<int, array<string, mixed>> Flat element array.
	 */
	private function tree( string $prefix ): array {
		return array(
			array(
				'id'       => $prefix . 'root',
				'name'     => 'section',
				'parent'   => 0,
				'children' => array( $prefix . 'kid1', $prefix . 'kid2' ),
				'settings' => array(),
			),
			array(
				'id'       => $prefix . 'kid1',
				'name'     => 'heading',
				'parent'   => $prefix . 'root',
				'children' => array(),
				'settings' => array( 'text' => 'One' ),
			),
			array(
				'id'       => $prefix . 'kid2',
				'name'     => 'text-basic',
				'parent'   => $prefix . 'root',
				'children' => array(),
				'settings' => array( 'text' => 'Two' ),
			),
		);
	}

	/**
	 * Create a bricks_template post holding the given elements.
	 *
	 * @param array<int, array<string, mixed>> $elements      Elements to store.
	 * @param string                           $template_type Bricks template type.
	 * @return int Template post ID.
	 */
	private function make_template( array $elements, string $template_type = 'section' ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => 'Fixture template',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $id, '_bricks_template_type', $template_type );
		update_post_meta( $id, BricksService::META_KEY, $elements );

		return $id;
	}

	/**
	 * Create a page post, optionally holding elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements to store.
	 * @return int Page post ID.
	 */
	private function make_page( array $elements = array() ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Fixture page',
				'post_status' => 'publish',
			)
		);

		if ( ! empty( $elements ) ) {
			update_post_meta( $id, BricksService::META_KEY, $elements );
		}

		return $id;
	}

	/**
	 * Collect every element ID in a flat array.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, string> IDs in document order.
	 */
	private function ids( array $elements ): array {
		return array_map( static fn( array $el ) => (string) $el['id'], $elements );
	}

	// -----------------------------------------------------------------------
	// remap_element_ids()
	// -----------------------------------------------------------------------

	/**
	 * Renaming an ID must rewrite both sides of the link, not just the ID itself.
	 *
	 * @return void
	 */
	public function test_remap_rewrites_parent_and_children_references(): void {
		$remapped = $this->service->remap_element_ids(
			$this->tree( 'aa' ),
			array( 'aaroot' => 'zzroot' )
		);

		$this->assertSame( 'zzroot', $remapped[0]['id'] );
		$this->assertSame( array( 'aakid1', 'aakid2' ), $remapped[0]['children'], 'Unmapped child IDs must be left alone' );
		$this->assertSame( 'zzroot', $remapped[1]['parent'], 'Child parent reference must follow the renamed root' );
		$this->assertSame( 'zzroot', $remapped[2]['parent'] );
		$this->assertTrue( $this->service->validate_element_linkage( $remapped ) );
	}

	/**
	 * Root elements are marked by parent 0; that marker is not an ID and must survive.
	 *
	 * @return void
	 */
	public function test_remap_never_rewrites_the_root_parent_marker(): void {
		$remapped = $this->service->remap_element_ids(
			$this->tree( 'aa' ),
			array( '0' => 'nope01', 'aakid1' => 'newkid' )
		);

		$this->assertSame( 0, $remapped[0]['parent'] );
		$this->assertSame( 'newkid', $remapped[1]['id'] );
		$this->assertSame( array( 'newkid', 'aakid2' ), $remapped[0]['children'] );
	}

	/**
	 * An empty map is a no-op rather than an error.
	 *
	 * @return void
	 */
	public function test_remap_with_empty_map_returns_input_unchanged(): void {
		$tree = $this->tree( 'aa' );
		$this->assertSame( $tree, $this->service->remap_element_ids( $tree, array() ) );
	}

	// -----------------------------------------------------------------------
	// regenerate_element_ids()
	// -----------------------------------------------------------------------

	/**
	 * Every ID changes, and the tree still validates afterwards.
	 *
	 * @return void
	 */
	public function test_regenerate_replaces_every_id_and_keeps_linkage_valid(): void {
		$original = $this->tree( 'aa' );
		$result   = $this->service->regenerate_element_ids( $original );

		$this->assertCount( 3, $result['id_map'] );
		$this->assertSame( array(), array_intersect( $this->ids( $original ), $this->ids( $result['elements'] ) ), 'No original ID may survive' );
		$this->assertTrue( $this->service->validate_element_linkage( $result['elements'] ) );
	}

	/**
	 * IDs present in the avoid list must not be minted again.
	 *
	 * @return void
	 */
	public function test_regenerate_avoids_ids_used_by_existing_content(): void {
		$existing = $this->tree( 'bb' );
		$result   = $this->service->regenerate_element_ids( $this->tree( 'aa' ), $existing );

		$this->assertSame(
			array(),
			array_intersect( $this->ids( $existing ), $this->ids( $result['elements'] ) ),
			'A regenerated ID collided with content already on the target'
		);
	}

	// -----------------------------------------------------------------------
	// find_settings_id_references()
	// -----------------------------------------------------------------------

	/**
	 * An ID baked into _cssCustom is a reference a linkage remap cannot follow.
	 *
	 * @return void
	 */
	public function test_find_settings_id_references_detects_a_baked_in_selector(): void {
		$tree                        = $this->tree( 'aa' );
		$tree[0]['settings']['_cssCustom'] = '#brxe-aakid1 { color: red; }';

		$found = $this->service->find_settings_id_references( $tree, array( 'aakid1', 'aakid2' ) );

		$this->assertSame( array( 'aakid1' ), $found );
	}

	// -----------------------------------------------------------------------
	// apply_template() — the happy paths
	// -----------------------------------------------------------------------

	/**
	 * Replace writes the template's elements over whatever the page held.
	 *
	 * @return void
	 */
	public function test_replace_overwrites_target_content_and_reports_what_it_replaced(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );
		$page_id     = $this->make_page( $this->tree( 'pp' ) );

		$result = $this->service->apply_template( $template_id, $page_id );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['applied_element_count'] );
		$this->assertSame( 3, $result['element_count'] );
		$this->assertSame( 3, $result['replaced_element_count'], 'The count of discarded elements must be measured before the save' );
		$this->assertFalse( $result['ids_regenerated'] );

		$stored = get_post_meta( $page_id, BricksService::META_KEY, true );
		$this->assertSame( array( 'ttroot', 'ttkid1', 'ttkid2' ), $this->ids( $stored ) );
	}

	/**
	 * IDs are preserved by default - that is what makes a later diff-and-patch deploy possible.
	 *
	 * @return void
	 */
	public function test_ids_are_preserved_when_they_do_not_collide(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );
		$page_id     = $this->make_page( $this->tree( 'pp' ) );

		$result = $this->service->apply_template( $template_id, $page_id, 'append' );

		$this->assertFalse( $result['ids_regenerated'] );
		$this->assertSame( 6, $result['element_count'] );

		$stored = get_post_meta( $page_id, BricksService::META_KEY, true );
		$this->assertSame( array( 'pproot', 'ppkid1', 'ppkid2', 'ttroot', 'ttkid1', 'ttkid2' ), $this->ids( $stored ) );
	}

	/**
	 * Prepend puts the template's roots ahead of the page's existing content.
	 *
	 * @return void
	 */
	public function test_prepend_places_template_content_first(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );
		$page_id     = $this->make_page( $this->tree( 'pp' ) );

		$this->service->apply_template( $template_id, $page_id, 'prepend' );

		$stored = get_post_meta( $page_id, BricksService::META_KEY, true );
		$this->assertSame( array( 'ttroot', 'ttkid1', 'ttkid2', 'pproot', 'ppkid1', 'ppkid2' ), $this->ids( $stored ) );
	}

	/**
	 * Appending a template to a page that already holds it must not duplicate IDs.
	 *
	 * @return void
	 */
	public function test_append_regenerates_ids_when_they_would_collide(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );
		$page_id     = $this->make_page( $this->tree( 'tt' ) );

		$result = $this->service->apply_template( $template_id, $page_id, 'append' );

		$this->assertTrue( $result['ids_regenerated'] );
		$this->assertSame( 6, $result['element_count'] );

		$stored = get_post_meta( $page_id, BricksService::META_KEY, true );
		$ids    = $this->ids( $stored );

		$this->assertSame( $ids, array_unique( $ids ), 'Duplicate element IDs break Bricks linkage' );
		$this->assertTrue( $this->service->validate_element_linkage( $stored ) );
	}

	/**
	 * Forcing regeneration must work even when nothing would have collided.
	 *
	 * @return void
	 */
	public function test_regenerate_ids_true_forces_new_ids_without_a_collision(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );
		$page_id     = $this->make_page();

		$result = $this->service->apply_template( $template_id, $page_id, 'replace', true );

		$this->assertTrue( $result['ids_regenerated'] );

		$stored = get_post_meta( $page_id, BricksService::META_KEY, true );
		$this->assertSame( array(), array_intersect( array( 'ttroot', 'ttkid1', 'ttkid2' ), $this->ids( $stored ) ) );
		$this->assertTrue( $this->service->validate_element_linkage( $stored ) );
	}

	/**
	 * A `#brxe-` reference inside settings is now followed, not left dangling.
	 *
	 * This previously asserted that the old ID was *named in a warning* as
	 * hand-work. It is fixed automatically now — the prefix makes the reference
	 * unambiguous — so the contract asserted here is that the stored selector
	 * names the new ID and the old one is gone. See
	 * BricksServiceSettingsIdRemapTest for the shapes still reported by hand.
	 *
	 * @return void
	 */
	public function test_regeneration_rewrites_an_id_referenced_from_settings(): void {
		$tree                              = $this->tree( 'tt' );
		$tree[0]['settings']['_cssCustom'] = '#brxe-ttkid1 { color: red; }';

		$template_id = $this->make_template( $tree );
		$page_id     = $this->make_page();

		$result = $this->service->apply_template( $template_id, $page_id, 'replace', true );

		$stored     = get_post_meta( $page_id, BricksService::META_KEY, true );
		$css_custom = $stored[0]['settings']['_cssCustom'];

		$this->assertStringNotContainsString( 'ttkid1', $css_custom, 'the stale ID must not survive' );
		$this->assertSame( '#brxe-' . $stored[1]['id'] . ' { color: red; }', $css_custom );
		$this->assertStringContainsString( 'rewritten', implode( ' ', $result['warnings'] ?? array() ) );
	}

	/**
	 * An ambiguous reference inside a longer string is still reported.
	 *
	 * A bare ID embedded in copy cannot be told from a coincidence, so it is
	 * deliberately not rewritten — and must still be surfaced rather than
	 * silently left behind.
	 *
	 * @return void
	 */
	public function test_regeneration_still_warns_about_an_ambiguous_reference(): void {
		$tree                          = $this->tree( 'tt' );
		$tree[1]['settings']['text']   = 'Anchor to ttkid2 in the sidebar.';

		$template_id = $this->make_template( $tree );
		$page_id     = $this->make_page();

		$result = $this->service->apply_template( $template_id, $page_id, 'replace', true );

		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertStringContainsString( 'ttkid2', implode( ' ', $result['warnings'] ) );
	}

	// -----------------------------------------------------------------------
	// apply_template() — CSS regeneration and meta key resolution
	// -----------------------------------------------------------------------

	/**
	 * The target's stylesheet is regenerated, and the file name is reported.
	 *
	 * @return void
	 */
	public function test_apply_regenerates_the_target_css_file(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );
		$page_id     = $this->make_page();

		$this->service->apply_template( $template_id, $page_id );

		$calls = $GLOBALS['_bricks_mcp_test_generate_calls'];
		$this->assertCount( 1, $calls );
		$this->assertSame( $page_id, $calls[0]['post_id'], 'CSS must be regenerated for the target, not the template' );
		$this->assertSame( "post-{$page_id}.min.css", $this->service->get_last_css_file() );
	}

	/**
	 * A header-template target stores content under the header key, not the content key.
	 *
	 * @return void
	 */
	public function test_applying_to_a_header_template_writes_the_header_meta_key(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );

		$target_id = wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => 'Site header',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $target_id, '_bricks_template_type', 'header' );

		$result = $this->service->apply_template( $template_id, $target_id );

		$this->assertIsArray( $result );
		$this->assertCount( 3, get_post_meta( $target_id, '_bricks_page_header_2', true ) );
		$this->assertSame( '', get_post_meta( $target_id, BricksService::META_KEY, true ), 'Content must not land in the phantom content key' );
	}

	/**
	 * Applying header content onto a regular page is legal but worth warning about.
	 *
	 * @return void
	 */
	public function test_header_source_applied_to_a_page_warns(): void {
		$template_id = wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => 'Site header',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $template_id, '_bricks_template_type', 'header' );
		update_post_meta( $template_id, '_bricks_page_header_2', $this->tree( 'tt' ) );

		$result = $this->service->apply_template( $template_id, $this->make_page() );

		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertStringContainsString( 'header template', implode( ' ', $result['warnings'] ) );
	}

	// -----------------------------------------------------------------------
	// apply_template() — refusals
	// -----------------------------------------------------------------------

	/**
	 * Silently proceeding with duplicate IDs would corrupt the page.
	 *
	 * @return void
	 */
	public function test_collision_with_regeneration_forbidden_is_refused(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );
		$page_id     = $this->make_page( $this->tree( 'tt' ) );

		$result = $this->service->apply_template( $template_id, $page_id, 'append', false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'element_id_collision', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_bricks_mcp_test_generate_calls'], 'A refused apply must not write anything' );
	}

	/**
	 * Unknown modes are rejected rather than silently treated as replace.
	 *
	 * @return void
	 */
	public function test_invalid_mode_is_refused(): void {
		$result = $this->service->apply_template(
			$this->make_template( $this->tree( 'tt' ) ),
			$this->make_page(),
			'merge'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_mode', $result->get_error_code() );
	}

	/**
	 * The source has to be a bricks_template.
	 *
	 * @return void
	 */
	public function test_non_template_source_is_refused(): void {
		$result = $this->service->apply_template( $this->make_page( $this->tree( 'tt' ) ), $this->make_page() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'template_not_found', $result->get_error_code() );
	}

	/**
	 * A missing target must not be created as a side effect.
	 *
	 * @return void
	 */
	public function test_missing_target_is_refused(): void {
		$result = $this->service->apply_template( $this->make_template( $this->tree( 'tt' ) ), 999999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Applying a template to itself would read and write the same meta key.
	 *
	 * @return void
	 */
	public function test_applying_a_template_to_itself_is_refused(): void {
		$template_id = $this->make_template( $this->tree( 'tt' ) );

		$result = $this->service->apply_template( $template_id, $template_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_target', $result->get_error_code() );
	}

	/**
	 * An empty template would otherwise silently blank the target page.
	 *
	 * @return void
	 */
	public function test_empty_template_is_refused(): void {
		$template_id = $this->make_template( array() );
		$page_id     = $this->make_page( $this->tree( 'pp' ) );

		$result = $this->service->apply_template( $template_id, $page_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_template', $result->get_error_code() );
		$this->assertCount( 3, get_post_meta( $page_id, BricksService::META_KEY, true ), 'The target must be left untouched' );
	}
}
