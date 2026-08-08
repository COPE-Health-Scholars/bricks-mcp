<?php
/**
 * Unit tests: previously-dropped tool parameters are now honoured.
 *
 * Each case below covers a parameter the tool schema advertised while no handler read it, so the
 * call reported success and discarded the payload. Found by a full audit of all 23 tools.
 *
 * Assertions are on observable outcomes (what got stored / which query ran) rather than source
 * text, because in every case the handler looked correct in isolation and only disagreed with
 * its own schema.
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
 * Dropped-parameter regression tests.
 */
final class DroppedParamsTest extends TestCase {

	/**
	 * Reset state and install Bricks doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();
		$GLOBALS['_bricks_mcp_test_options']          = array();
		$GLOBALS['_bricks_mcp_test_last_get_posts_args'] = array();
		$GLOBALS['_bricks_mcp_test_get_posts_return']    = array();
		$GLOBALS['_bricks_mcp_test_current_user_can']    = true;

		unset( $GLOBALS['_bricks_mcp_test_settings'] );

		\Bricks\Assets::$inline_css = array( 'content' => '' );
	}

	/**
	 * Router with schema validation disabled (these tests exercise parameter plumbing).
	 *
	 * @return Router Router instance.
	 */
	private function router(): Router {
		$router     = new Router();
		$service    = $router->get_bricks_service();
		$reflection = new \ReflectionProperty( $service, 'validation_service' );
		$reflection->setAccessible( true );
		$reflection->setValue( $service, null );

		return $router;
	}

	/**
	 * Invoke a private Router method.
	 *
	 * @param string               $method Method name.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @return mixed Result.
	 */
	private function call( string $method, array $args ): mixed {
		$router     = $this->router();
		$reflection = new \ReflectionMethod( $router, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $router, $args );
	}

	// -----------------------------------------------------------------------
	// theme_style: `active` was advertised on update but read nowhere.
	// -----------------------------------------------------------------------

	/**
	 * Create a theme style and return its ID.
	 *
	 * @param array<string, mixed> $conditions Initial conditions.
	 * @return string Style ID.
	 */
	private function seed_theme_style( array $conditions = array() ): string {
		$service = new BricksService();
		$service->create_theme_style( 'Probe', array(), $conditions );

		$styles = $GLOBALS['_bricks_mcp_test_options']['bricks_theme_styles'] ?? array();
		return (string) array_key_first( $styles );
	}

	/**
	 * Read a style's conditions.
	 *
	 * @param string $style_id Style ID.
	 * @return array<int, mixed> Conditions.
	 */
	private function style_conditions( string $style_id ): array {
		$styles = $GLOBALS['_bricks_mcp_test_options']['bricks_theme_styles'] ?? array();
		return $styles[ $style_id ]['settings']['conditions']['conditions'] ?? array();
	}

	/**
	 * active=true activates an inactive style by giving it the site-wide condition.
	 *
	 * @return void
	 */
	public function test_theme_style_active_true_activates(): void {
		$style_id = $this->seed_theme_style();
		$this->assertSame( array(), $this->style_conditions( $style_id ), 'Style should start inactive' );

		$result = ( new BricksService() )->update_theme_style( $style_id, null, null, null, false, true );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( array( 'main' => 'any' ) ), $this->style_conditions( $style_id ) );
		$this->assertTrue( $result['is_sitewide_active'] );
	}

	/**
	 * active=false deactivates by clearing conditions — the same mechanism as soft delete.
	 *
	 * @return void
	 */
	public function test_theme_style_active_false_deactivates(): void {
		$style_id = $this->seed_theme_style( array( array( 'main' => 'any' ) ) );
		$this->assertNotSame( array(), $this->style_conditions( $style_id ) );

		( new BricksService() )->update_theme_style( $style_id, null, null, null, false, false );

		$this->assertSame( array(), $this->style_conditions( $style_id ) );
	}

	/**
	 * Omitting active leaves activation state alone.
	 *
	 * @return void
	 */
	public function test_theme_style_active_omitted_preserves_state(): void {
		$style_id = $this->seed_theme_style( array( array( 'main' => 'any' ) ) );

		( new BricksService() )->update_theme_style( $style_id, 'Renamed' );

		$this->assertSame( array( array( 'main' => 'any' ) ), $this->style_conditions( $style_id ) );
	}

	/**
	 * Explicit conditions plus active=true keeps the given conditions rather than overwriting.
	 *
	 * @return void
	 */
	public function test_theme_style_explicit_conditions_survive_active_true(): void {
		$style_id   = $this->seed_theme_style();
		$conditions = array( array( 'main' => 'postType', 'postType' => 'page' ) );

		( new BricksService() )->update_theme_style( $style_id, null, null, $conditions, false, true );

		$this->assertSame( $conditions, $this->style_conditions( $style_id ) );
	}

	// -----------------------------------------------------------------------
	// typography_scale: create was unsatisfiable exactly as documented.
	// -----------------------------------------------------------------------

	/**
	 * The documented `settings` wrapper must satisfy create.
	 *
	 * @return void
	 */
	public function test_typography_scale_create_accepts_settings_wrapper(): void {
		$result = $this->router()->tool_typography_scale(
			array(
				'action'   => 'create',
				'name'     => 'Scale',
				'settings' => array(
					'prefix' => '--text-',
					'steps'  => array( array( 'name' => 'sm', 'value' => '0.875rem' ) ),
				),
			)
		);

		$this->assertNotInstanceOf(
			\WP_Error::class,
			$result,
			'create must accept the documented settings wrapper instead of failing with missing_prefix'
		);
	}

	/**
	 * Flat params keep working.
	 *
	 * @return void
	 */
	public function test_typography_scale_create_still_accepts_flat_params(): void {
		$result = $this->router()->tool_typography_scale(
			array(
				'action' => 'create',
				'name'   => 'Scale',
				'prefix' => '--text-',
				'steps'  => array( array( 'name' => 'sm', 'value' => '0.875rem' ) ),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Flat params win over the wrapper when both are supplied.
	 *
	 * @return void
	 */
	public function test_typography_scale_flat_params_take_precedence(): void {
		$this->router()->tool_typography_scale(
			array(
				'action'   => 'create',
				'name'     => 'Scale',
				'prefix'   => '--flat-',
				'steps'    => array( array( 'name' => 'sm', 'value' => '1rem' ) ),
				'settings' => array( 'prefix' => '--wrapped-' ),
			)
		);

		$scales = $GLOBALS['_bricks_mcp_test_options'] ?? array();
		$json   = wp_json_encode( $scales );

		// Prefixes are stored bare — Bricks prepends "--" itself when it writes the CSS.
		$this->assertStringContainsString( 'flat-', (string) $json );
		$this->assertStringNotContainsString( 'wrapped-', (string) $json );
	}

	// -----------------------------------------------------------------------
	// global_class: `class_name` was documented as a list filter but ignored.
	// -----------------------------------------------------------------------

	/**
	 * class_name filters the list instead of returning everything.
	 *
	 * @return void
	 */
	public function test_global_class_list_filters_by_class_name(): void {
		$GLOBALS['_bricks_mcp_test_options']['bricks_global_classes'] = array(
			array( 'id' => 'aaa111', 'name' => 'btn-primary', 'settings' => array() ),
			array( 'id' => 'bbb222', 'name' => 'card-header', 'settings' => array() ),
		);

		$result = $this->call( 'tool_get_global_classes', array( 'class_name' => 'btn' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'], 'class_name must filter the list, not be ignored' );
	}

	/**
	 * Without a filter the whole list still comes back.
	 *
	 * @return void
	 */
	public function test_global_class_list_unfiltered_returns_all(): void {
		$GLOBALS['_bricks_mcp_test_options']['bricks_global_classes'] = array(
			array( 'id' => 'aaa111', 'name' => 'btn-primary', 'settings' => array() ),
			array( 'id' => 'bbb222', 'name' => 'card-header', 'settings' => array() ),
		);

		$result = $this->call( 'tool_get_global_classes', array() );

		$this->assertSame( 2, $result['total'] );
	}

	// -----------------------------------------------------------------------
	// element: `element` object was documented as a source but never read.
	// -----------------------------------------------------------------------

	/**
	 * Seed a page with one section.
	 *
	 * @return int Post ID.
	 */
	private function seed_page(): int {
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		( new BricksService() )->save_elements(
			$post_id,
			array(
				array(
					'id'       => 'sec001',
					'name'     => 'section',
					'parent'   => 0,
					'children' => array(),
					'settings' => array(),
				),
			)
		);

		return $post_id;
	}

	/**
	 * The documented `element` object satisfies add, carrying name and settings.
	 *
	 * @return void
	 */
	public function test_element_add_accepts_element_object(): void {
		$post_id = $this->seed_page();

		$result = $this->call(
			'tool_add_element',
			array(
				'post_id' => $post_id,
				'element' => array(
					'name'     => 'heading',
					'settings' => array( 'text' => 'From element object' ),
					'label'    => 'My Heading',
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'element object must satisfy add rather than erroring with missing_name' );

		$elements = ( new BricksService() )->get_elements( $post_id );
		$added    = array_values( array_filter( $elements, static fn( $el ) => 'heading' === $el['name'] ) );

		$this->assertCount( 1, $added );
		$this->assertSame( 'From element object', $added[0]['settings']['text'] ?? null, 'settings must not be discarded' );
		$this->assertSame( 'My Heading', $added[0]['label'] ?? null );
	}

	/**
	 * Top-level params still win over the element object.
	 *
	 * @return void
	 */
	public function test_element_add_top_level_params_take_precedence(): void {
		$post_id = $this->seed_page();

		$this->call(
			'tool_add_element',
			array(
				'post_id' => $post_id,
				'name'    => 'heading',
				'element' => array( 'name' => 'button' ),
			)
		);

		$elements = ( new BricksService() )->get_elements( $post_id );
		$names    = array_column( $elements, 'name' );

		$this->assertContains( 'heading', $names );
		$this->assertNotContains( 'button', $names );
	}

	// -----------------------------------------------------------------------
	// template: create discarded tags/bundles; duplicate discarded title.
	// -----------------------------------------------------------------------

	/**
	 * Taxonomy terms are assigned on create, not only on update.
	 *
	 * @return void
	 */
	public function test_template_create_assigns_tags_and_bundles(): void {
		$result = $this->call(
			'tool_create_template',
			array(
				'title'   => 'Tagged',
				'type'    => 'section',
				'tags'    => array( 'hero' ),
				'bundles' => array( 'marketing' ),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$template_id = (int) $result['id'];
		$terms       = $GLOBALS['_bricks_mcp_test_terms'][ $template_id ] ?? array();

		$this->assertSame( array( 'hero' ), $terms['template_tag'] ?? null );
		$this->assertSame( array( 'marketing' ), $terms['template_bundle'] ?? null );
	}

	/**
	 * A requested title is applied to the duplicate.
	 *
	 * @return void
	 */
	public function test_template_duplicate_applies_title(): void {
		$created = $this->call(
			'tool_create_template',
			array( 'title' => 'Original', 'type' => 'section' )
		);

		$result = $this->call(
			'tool_duplicate_template',
			array( 'template_id' => (int) $created['id'], 'title' => 'Renamed Copy' )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$titles = array_column( $GLOBALS['_bricks_mcp_test_posts'], 'post_title' );
		$this->assertContains( 'Renamed Copy', $titles, 'duplicate must honour the requested title' );
	}

	// -----------------------------------------------------------------------
	// wordpress/content get_posts: `search` was declared but only `s` was read.
	// -----------------------------------------------------------------------

	/**
	 * The declared `search` key reaches WP_Query.
	 *
	 * @return void
	 */
	public function test_get_posts_accepts_search_alias(): void {
		$this->call( 'tool_get_posts', array( 'search' => 'widget' ) );

		$captured = $GLOBALS['_bricks_mcp_test_last_get_posts_args'] ?? array();
		$this->assertSame( 'widget', $captured['s'] ?? null, 'the schema-declared `search` must reach the query' );
	}

	/**
	 * `s` keeps working and wins when both are given.
	 *
	 * @return void
	 */
	public function test_get_posts_s_takes_precedence_over_search(): void {
		$this->call( 'tool_get_posts', array( 's' => 'from-s', 'search' => 'from-search' ) );

		$captured = $GLOBALS['_bricks_mcp_test_last_get_posts_args'] ?? array();
		$this->assertSame( 'from-s', $captured['s'] ?? null );
	}

	/**
	 * post_status stays locked regardless — this is a privacy control, not a droppable param.
	 *
	 * @return void
	 */
	public function test_get_posts_post_status_remains_locked(): void {
		$this->call( 'tool_get_posts', array( 'post_status' => 'draft', 'search' => 'x' ) );

		$captured = $GLOBALS['_bricks_mcp_test_last_get_posts_args'] ?? array();
		$this->assertSame( 'publish', $captured['post_status'] ?? null );
	}
}
