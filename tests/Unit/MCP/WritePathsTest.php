<?php
/**
 * Unit test: write paths that previously bypassed save_elements(), plus the css_file evidence
 * every write now reports.
 *
 * Three distinct defects are covered here, all of the same shape - a write that reported
 * success while the thing it was supposed to affect never changed:
 *
 * 1. import_template() wrote content before setting the template type, so a header/footer
 *    import landed in the content meta key Bricks never reads.
 * 2. duplicate_page() copied meta with the Bricks meta filters still hooked and never
 *    regenerated CSS, so the copy could arrive empty and always arrived unstyled.
 * 3. component create/update stored elements raw and overwrote the root ID without moving the
 *    children's parent references with it, orphaning every child.
 *
 * @package BricksMCP\Tests\Unit\MCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the element write paths and their CSS reporting.
 */
final class WritePathsTest extends TestCase {

	/**
	 * Router under test.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Service under test.
	 *
	 * @var BricksService
	 */
	private BricksService $service;

	/**
	 * Install Bricks doubles and reset the in-memory stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();
		update_option( 'bricks_components', array() );

		/*
		 * The Router wires a ValidationService, which rejects element types that are not in the
		 * Bricks registry. The doubles register no real element classes, so the schema cache is
		 * seeded directly with the types these fixtures use. Entries carry no settings_schema, so
		 * the type is recognised and settings validation is skipped.
		 */
		update_option(
			'bricks_mcp_schema_cache_unknown',
			array(
				'section'    => array( 'name' => 'section' ),
				'heading'    => array( 'name' => 'heading' ),
				'text-basic' => array( 'name' => 'text-basic' ),
			)
		);
		update_option( 'bricks_mcp_schema_cache_expires', time() + 3600 );

		$GLOBALS['_bricks_mcp_test_generate_calls'] = array();
		$GLOBALS['_bricks_mcp_test_get_data_calls'] = array();
		$GLOBALS['_bricks_mcp_test_elements']       = array();
		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		unset(
			$GLOBALS['_bricks_mcp_test_template_type'],
			$GLOBALS['_bricks_mcp_test_generate_return'],
			$GLOBALS['_bricks_mcp_test_generate_throws']
		);

		\Bricks\Assets::$post_id    = 0;
		\Bricks\Assets::$inline_css = array();

		$this->router  = new Router();
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
			$GLOBALS['_bricks_mcp_test_current_user_can']
		);

		parent::tearDown();
	}

	/**
	 * A two-element tree: one section with a single child.
	 *
	 * @param string $prefix Prefix so distinct trees get distinct IDs.
	 * @return array<int, array<string, mixed>> Flat element array.
	 */
	private function tree( string $prefix ): array {
		return array(
			array(
				'id'       => $prefix . 'root',
				'name'     => 'section',
				'parent'   => 0,
				'children' => array( $prefix . 'kid1' ),
				'settings' => array(),
			),
			array(
				'id'       => $prefix . 'kid1',
				'name'     => 'heading',
				'parent'   => $prefix . 'root',
				'children' => array(),
				'settings' => array( 'text' => 'Hello' ),
			),
		);
	}

	// -----------------------------------------------------------------------
	// import_template()
	// -----------------------------------------------------------------------

	/**
	 * A header template's content must land under the header meta key.
	 *
	 * @return void
	 */
	public function test_header_template_import_writes_the_header_meta_key(): void {
		$result = $this->service->import_template(
			array(
				'title'        => 'Imported header',
				'content'      => $this->tree( 'hh' ),
				'templateType' => 'header',
			)
		);

		$this->assertIsArray( $result );

		$template_id = $result['template_id'];

		$this->assertCount( 2, get_post_meta( $template_id, '_bricks_page_header_2', true ) );
		$this->assertSame(
			'',
			get_post_meta( $template_id, BricksService::META_KEY, true ),
			'Writing content before the template type is what produced phantom content in the wrong key'
		);
	}

	/**
	 * A section import keeps using the content key, and regenerates CSS.
	 *
	 * @return void
	 */
	public function test_section_template_import_regenerates_css(): void {
		$result = $this->service->import_template(
			array(
				'title'   => 'Imported section',
				'content' => $this->tree( 'ss' ),
			)
		);

		$template_id = $result['template_id'];

		$this->assertCount( 2, get_post_meta( $template_id, BricksService::META_KEY, true ) );
		$this->assertCount( 1, $GLOBALS['_bricks_mcp_test_generate_calls'] );
		$this->assertSame( $template_id, $GLOBALS['_bricks_mcp_test_generate_calls'][0]['post_id'] );
		$this->assertSame( "post-{$template_id}.min.css", $this->service->get_last_css_file() );
	}

	/**
	 * A broken tree is refused, and no orphaned template post is left behind.
	 *
	 * @return void
	 */
	public function test_import_with_broken_linkage_is_refused_without_creating_a_post(): void {
		$broken = $this->tree( 'bb' );
		// The child now claims a parent that does not exist in the array.
		$broken[1]['parent'] = 'ghost1';

		$before = count( $GLOBALS['_bricks_mcp_test_posts'] );
		$result = $this->service->import_template(
			array(
				'title'   => 'Broken import',
				'content' => $broken,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( $before, $GLOBALS['_bricks_mcp_test_posts'], 'A refused import must not leave an empty template behind' );
	}

	// -----------------------------------------------------------------------
	// duplicate_page()
	// -----------------------------------------------------------------------

	/**
	 * The copy needs its own stylesheet; nothing else on this path writes one.
	 *
	 * @return void
	 */
	public function test_duplicate_regenerates_css_for_the_copy(): void {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Original',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $page_id, BricksService::META_KEY, $this->tree( 'oo' ) );

		$new_id = $this->service->duplicate_page( $page_id );

		$this->assertIsInt( $new_id );
		$this->assertCount( 2, get_post_meta( $new_id, BricksService::META_KEY, true ), 'Bricks content must actually reach the copy' );

		$calls = $GLOBALS['_bricks_mcp_test_generate_calls'];
		$this->assertCount( 1, $calls );
		$this->assertSame( $new_id, $calls[0]['post_id'], 'CSS must be regenerated for the copy, not the original' );
		$this->assertSame( "post-{$new_id}.min.css", $this->service->get_last_css_file() );
	}

	// -----------------------------------------------------------------------
	// Components
	// -----------------------------------------------------------------------

	/**
	 * Re-rooting a component must carry the children's parent references with it.
	 *
	 * @return void
	 */
	public function test_component_create_reroots_without_orphaning_children(): void {
		$result = $this->router->tool_component(
			array(
				'action'   => 'create',
				'label'    => 'Card',
				'elements' => $this->tree( 'cc' ),
			)
		);

		$this->assertIsArray( $result );

		$components = get_option( 'bricks_components', array() );
		$this->assertCount( 1, $components );

		$stored       = $components[0]['elements'];
		$component_id = $components[0]['id'];

		$this->assertSame( $component_id, $stored[0]['id'], 'Bricks requires the root element ID to equal the component ID' );
		$this->assertSame(
			$component_id,
			$stored[1]['parent'],
			'The child still pointed at the pre-rename root ID, which is what orphaned every MCP-created component'
		);
		$this->assertSame( array( 'cckid1' ), $stored[0]['children'] );
		$this->assertTrue( $this->service->validate_element_linkage( $stored ) );
	}

	/**
	 * A simplified nested payload is normalized rather than stored in a shape Bricks cannot read.
	 *
	 * @return void
	 */
	public function test_component_create_normalizes_a_simplified_tree(): void {
		$result = $this->router->tool_component(
			array(
				'action'   => 'create',
				'label'    => 'Nested card',
				'elements' => array(
					array(
						'name'     => 'section',
						'settings' => array(),
						'children' => array(
							array(
								'name'     => 'heading',
								'settings' => array( 'text' => 'Hi' ),
							),
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['element_count'] );

		$components = get_option( 'bricks_components', array() );
		$stored     = $components[0]['elements'];

		$this->assertSame( $components[0]['id'], $stored[0]['id'] );
		$this->assertTrue( $this->service->validate_element_linkage( $stored ) );
	}

	/**
	 * The same re-rooting rule applies when elements are replaced on update.
	 *
	 * @return void
	 */
	public function test_component_update_reroots_replacement_elements(): void {
		$created      = $this->router->tool_component(
			array(
				'action'   => 'create',
				'label'    => 'Card',
				'elements' => $this->tree( 'cc' ),
			)
		);
		$component_id = $created['id'];

		$result = $this->router->tool_component(
			array(
				'action'       => 'update',
				'component_id' => $component_id,
				'elements'     => $this->tree( 'dd' ),
			)
		);

		$this->assertIsArray( $result );

		$components = get_option( 'bricks_components', array() );
		$stored     = $components[0]['elements'];

		$this->assertSame( $component_id, $stored[0]['id'] );
		$this->assertSame( $component_id, $stored[1]['parent'] );
		$this->assertTrue( $this->service->validate_element_linkage( $stored ) );
	}

	/**
	 * A malformed replacement must not half-overwrite a working component.
	 *
	 * @return void
	 */
	public function test_component_update_with_broken_linkage_leaves_the_definition_intact(): void {
		$created      = $this->router->tool_component(
			array(
				'action'   => 'create',
				'label'    => 'Card',
				'elements' => $this->tree( 'cc' ),
			)
		);
		$component_id = $created['id'];

		$broken             = $this->tree( 'dd' );
		$broken[1]['parent'] = 'ghost1';

		$result = $this->router->tool_component(
			array(
				'action'       => 'update',
				'component_id' => $component_id,
				'elements'     => $broken,
				'label'        => 'Renamed',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );

		$components = get_option( 'bricks_components', array() );
		$this->assertSame( 'Card', $components[0]['label'], 'The rename must not survive a refused update' );
		$this->assertSame( array( 'cckid1' ), $components[0]['elements'][0]['children'] );
	}

	// -----------------------------------------------------------------------
	// css_file evidence
	// -----------------------------------------------------------------------

	/**
	 * Create a Bricks page holding a two-element tree.
	 *
	 * @return int Post ID.
	 */
	private function make_bricks_page(): int {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Target',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $page_id, BricksService::META_KEY, $this->tree( 'aa' ) );

		return $page_id;
	}

	/**
	 * An element edit is a style edit: without the file name the caller cannot tell whether the
	 * frontend actually changed.
	 *
	 * @return void
	 */
	public function test_element_update_reports_the_css_file_it_wrote(): void {
		$page_id = $this->make_bricks_page();

		$result = $this->router->tool_element(
			array(
				'action'     => 'update',
				'post_id'    => $page_id,
				'element_id' => 'aakid1',
				'settings'   => array( 'text' => 'Changed' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( "post-{$page_id}.min.css", $result['css_file'] );
		$this->assertArrayNotHasKey( 'css_note', $result );
	}

	/**
	 * Every element write path reports it, not just the one that happened to be wired up.
	 *
	 * @return void
	 */
	public function test_all_element_write_actions_report_a_css_file(): void {
		$calls = array(
			'add'         => array(
				'action'  => 'add',
				'name'    => 'heading',
				'settings' => array( 'text' => 'Added' ),
			),
			'remove'      => array(
				'action'     => 'remove',
				'element_id' => 'aakid1',
			),
			'move'        => array(
				'action'     => 'move',
				'element_id' => 'aakid1',
				'target_parent_id' => '0',
			),
			'bulk_update' => array(
				'action'  => 'bulk_update',
				'updates' => array(
					array(
						'element_id' => 'aakid1',
						'settings'   => array( 'text' => 'Bulk' ),
					),
				),
			),
		);

		foreach ( $calls as $label => $args ) {
			$page_id = $this->make_bricks_page();
			$result  = $this->router->tool_element( array_merge( $args, array( 'post_id' => $page_id ) ) );

			$this->assertIsArray( $result, "element:{$label} returned an error" );
			$this->assertSame( "post-{$page_id}.min.css", $result['css_file'], "element:{$label} did not report its CSS file" );
		}
	}

	/**
	 * Setting conditions used to write the content meta key directly, skipping every guard.
	 *
	 * @return void
	 */
	public function test_set_conditions_saves_through_save_elements(): void {
		$page_id = $this->make_bricks_page();

		$result = $this->router->tool_element(
			array(
				'action'     => 'set_conditions',
				'post_id'    => $page_id,
				'element_id' => 'aakid1',
				'conditions' => array(
					array(
						array(
							'key'     => 'user_logged_in',
							'compare' => '==',
							'value'   => true,
						),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( "post-{$page_id}.min.css", $result['css_file'] );

		$stored = get_post_meta( $page_id, BricksService::META_KEY, true );
		$this->assertArrayHasKey( '_conditions', $stored[1]['settings'] );
	}

	/**
	 * page:apply_template must be reachable through the dispatcher and report its CSS file.
	 *
	 * @return void
	 */
	public function test_page_apply_template_is_dispatched_and_reports_css(): void {
		$template_id = wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => 'Section',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $template_id, '_bricks_template_type', 'section' );
		update_post_meta( $template_id, BricksService::META_KEY, $this->tree( 'tt' ) );

		$page_id = $this->make_bricks_page();

		$result = $this->router->tool_page(
			array(
				'action'      => 'apply_template',
				'post_id'     => $page_id,
				'template_id' => $template_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( "post-{$page_id}.min.css", $result['css_file'] );
		$this->assertSame( 2, $result['element_count'] );
		$this->assertSame( 2, $result['replaced_element_count'] );
	}

	/**
	 * The dispatcher must distinguish an omitted regenerate_ids from an explicit false.
	 *
	 * @return void
	 */
	public function test_apply_template_passes_explicit_false_through_to_the_service(): void {
		$template_id = wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => 'Section',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $template_id, '_bricks_template_type', 'section' );
		update_post_meta( $template_id, BricksService::META_KEY, $this->tree( 'aa' ) );

		$page_id = $this->make_bricks_page();

		// Same IDs on both sides, so append collides; false must refuse rather than auto-rewrite.
		$result = $this->router->tool_page(
			array(
				'action'         => 'apply_template',
				'post_id'        => $page_id,
				'template_id'    => $template_id,
				'mode'           => 'append',
				'regenerate_ids' => false,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'element_id_collision', $result->get_error_code() );
	}
}
