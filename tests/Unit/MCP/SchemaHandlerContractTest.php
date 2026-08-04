<?php
/**
 * Unit tests: schema/handler parameter contract fixes.
 *
 * Covers three defects that shared one root cause — a tool schema advertising a parameter the
 * PHP handler never read. Because no schema sets additionalProperties: false, the unknown key
 * passed validation, was never read, and the call reported success having silently discarded
 * the payload. Upstream issues #35 and #36.
 *
 * These assert on observable outcomes (what got stored) rather than on source text, because
 * the defects were invisible at the source level: every one of these handlers looked correct
 * in isolation and only disagreed with its own schema.
 *
 * @package BricksMCP\Tests\Unit\MCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\ElementIdGenerator;
use BricksMCP\MCP\Services\ElementNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Schema/handler contract tests.
 */
final class SchemaHandlerContractTest extends TestCase {

	/**
	 * Reset the in-memory WordPress state and install Bricks doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();
		$GLOBALS['_bricks_mcp_test_options']     = array();
		$GLOBALS['_bricks_mcp_test_did_actions'] = array();
		$GLOBALS['_bricks_mcp_test_elements']    = array();

		unset(
			$GLOBALS['_bricks_mcp_test_template_type'],
			$GLOBALS['_bricks_mcp_test_generate_return'],
			$GLOBALS['_bricks_mcp_test_generate_throws']
		);

		\Bricks\Assets::$inline_css = array( 'content' => '' );
	}

	/**
	 * Build a Router with Bricks schema validation switched off.
	 *
	 * These tests exercise parameter plumbing (does the handler read the key its schema
	 * advertises?), not Bricks settings validation. Router's constructor wires a
	 * ValidationService, which resolves element schemas from the real \Bricks\Elements
	 * registry — faking that registry convincingly would mean faking every element class and
	 * its controls, so validation is disabled here rather than approximated badly.
	 *
	 * @return Router Router whose BricksService performs structural checks only.
	 */
	private function router(): Router {
		$router = new Router();

		$service    = $router->get_bricks_service();
		$reflection = new \ReflectionProperty( $service, 'validation_service' );
		$reflection->setAccessible( true );
		$reflection->setValue( $service, null );

		return $router;
	}

	/**
	 * Invoke a private/protected Router method.
	 *
	 * @param string            $method Method name.
	 * @param array<int, mixed> $args   Positional arguments.
	 * @return mixed Method return value.
	 */
	private function router_call( string $method, array $args ): mixed {
		$router     = $this->router();
		$reflection = new \ReflectionMethod( $router, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $router, ...$args );
	}

	// -----------------------------------------------------------------------
	// Issue #35 — theme_style: schema says `styles`, handler read `settings`.
	// -----------------------------------------------------------------------

	/**
	 * A create call using the schema's own parameter name must persist the payload.
	 *
	 * @return void
	 */
	public function test_theme_style_create_persists_styles_param(): void {
		$router = $this->router();

		$result = $router->tool_theme_style(
			array(
				'action' => 'create',
				'name'   => 'My Style',
				'styles' => array(
					'bodyTypography' => array(
						'_typography' => array( 'font-size' => '16px' ),
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'create with the documented `styles` param must succeed' );

		$stored = $GLOBALS['_bricks_mcp_test_options']['bricks_theme_styles'] ?? array();
		$this->assertNotEmpty( $stored, 'A theme style must have been written' );

		$style = reset( $stored );
		$this->assertArrayHasKey(
			'bodyTypography',
			$style['settings'] ?? array(),
			'The styles payload must be persisted, not silently dropped'
		);
		$this->assertSame( '16px', $style['settings']['bodyTypography']['_typography']['font-size'] );
	}

	/**
	 * The legacy/undocumented `settings` spelling must keep working.
	 *
	 * @return void
	 */
	public function test_theme_style_create_still_accepts_settings_param(): void {
		$router = $this->router();

		$router->tool_theme_style(
			array(
				'action'   => 'create',
				'name'     => 'Legacy Style',
				'settings' => array( 'bodyTypography' => array( '_typography' => array( 'font-size' => '18px' ) ) ),
			)
		);

		$stored = $GLOBALS['_bricks_mcp_test_options']['bricks_theme_styles'] ?? array();
		$style  = reset( $stored );

		$this->assertSame( '18px', $style['settings']['bodyTypography']['_typography']['font-size'] );
	}

	/**
	 * An explicit `settings` wins over `styles` rather than being overwritten.
	 *
	 * @return void
	 */
	public function test_theme_style_settings_takes_precedence_over_styles(): void {
		$router = $this->router();

		$router->tool_theme_style(
			array(
				'action'   => 'create',
				'name'     => 'Both',
				'settings' => array( 'marker' => 'from-settings' ),
				'styles'   => array( 'marker' => 'from-styles' ),
			)
		);

		$stored = $GLOBALS['_bricks_mcp_test_options']['bricks_theme_styles'] ?? array();
		$style  = reset( $stored );

		$this->assertSame( 'from-settings', $style['settings']['marker'] );
	}

	// -----------------------------------------------------------------------
	// Issue #36 — template:create advertised `elements` but discarded them.
	// -----------------------------------------------------------------------

	/**
	 * Elements passed to template:create must actually be stored.
	 *
	 * @return void
	 */
	public function test_template_create_persists_elements(): void {
		$result = $this->router_call(
			'tool_create_template',
			array(
				array(
					'title'    => 'Probe Template',
					'type'     => 'section',
					'elements' => array(
						array(
							'id'       => 'prb001',
							'name'     => 'section',
							'parent'   => 0,
							'children' => array(),
							'settings' => array(),
						),
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertNotEmpty( $result['elements'] ?? array(), 'template:create must persist the elements it advertises accepting' );
		$this->assertSame( 'section', $result['elements'][0]['name'] ?? null );
	}

	/**
	 * Creating without elements must still work (they are optional).
	 *
	 * @return void
	 */
	public function test_template_create_without_elements_still_succeeds(): void {
		$result = $this->router_call(
			'tool_create_template',
			array(
				array(
					'title' => 'Empty Template',
					'type'  => 'section',
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $result['elements'] ?? array() );
	}

	/**
	 * A malformed element array must fail without leaving an orphaned empty template.
	 *
	 * @return void
	 */
	public function test_template_create_rejects_bad_elements_without_creating_post(): void {
		$before = count( $GLOBALS['_bricks_mcp_test_posts'] );

		$result = $this->router_call(
			'tool_create_template',
			array(
				array(
					'title'    => 'Bad Template',
					'type'     => 'section',
					// Child points at a parent that does not exist in the array.
					'elements' => array(
						array(
							'id'       => 'aaa111',
							'name'     => 'heading',
							'parent'   => 'nope99',
							'children' => array(),
							'settings' => array(),
						),
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'Invalid element linkage must be rejected' );
		$this->assertCount(
			$before,
			$GLOBALS['_bricks_mcp_test_posts'],
			'Validation must run before post creation so no orphaned empty template is left behind'
		);
	}

	/**
	 * A non-array elements value must be rejected cleanly.
	 *
	 * @return void
	 */
	public function test_template_create_rejects_non_array_elements(): void {
		$result = $this->router_call(
			'tool_create_template',
			array(
				array(
					'title'    => 'Bad Type',
					'type'     => 'section',
					'elements' => 'not-an-array',
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_elements', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Issue #36 — element labels live in a top-level `label`, not settings._label.
	// -----------------------------------------------------------------------

	/**
	 * The normalizer must preserve a label through the simplified-format conversion.
	 *
	 * @return void
	 */
	public function test_normalizer_preserves_top_level_label(): void {
		$normalizer = new ElementNormalizer( new ElementIdGenerator() );

		$flat = $normalizer->simplified_to_flat(
			array(
				array(
					'name'     => 'section',
					'label'    => 'Overview',
					'settings' => array(),
				),
			),
			array()
		);

		$this->assertSame( 'Overview', $flat[0]['label'] ?? null, 'label must survive normalization as a top-level key' );
		$this->assertArrayNotHasKey( '_label', $flat[0]['settings'], 'label must not be smuggled into settings' );
	}

	/**
	 * No label supplied means no label key at all — not an empty one.
	 *
	 * @return void
	 */
	public function test_normalizer_omits_label_when_not_supplied(): void {
		$normalizer = new ElementNormalizer( new ElementIdGenerator() );

		$flat = $normalizer->simplified_to_flat(
			array( array( 'name' => 'section', 'settings' => array() ) ),
			array()
		);

		$this->assertArrayNotHasKey( 'label', $flat[0] );
	}

	/**
	 * A blank or whitespace-only label must not create an empty label key.
	 *
	 * @param string $label Candidate label.
	 * @return void
	 *
	 * @dataProvider provide_blank_labels
	 */
	public function test_normalizer_ignores_blank_labels( string $label ): void {
		$normalizer = new ElementNormalizer( new ElementIdGenerator() );

		$flat = $normalizer->simplified_to_flat(
			array( array( 'name' => 'section', 'label' => $label, 'settings' => array() ) ),
			array()
		);

		$this->assertArrayNotHasKey( 'label', $flat[0] );
	}

	/**
	 * Blank label candidates.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_blank_labels(): array {
		return array(
			'empty'      => array( '' ),
			'spaces'     => array( '   ' ),
			'tab/newline' => array( "\t\n" ),
		);
	}

	/**
	 * update_element must write a top-level label, where Bricks actually reads it.
	 *
	 * @return void
	 */
	public function test_update_element_sets_top_level_label(): void {
		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$service->save_elements(
			$post_id,
			array(
				array(
					'id'       => 'aaa111',
					'name'     => 'heading',
					'parent'   => 0,
					'children' => array(),
					'settings' => array( 'text' => 'hi' ),
				),
			)
		);

		$result = $service->update_element( $post_id, 'aaa111', array(), 'Renamed Heading' );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['label_updated'] );

		$stored = $service->get_elements( $post_id );

		$this->assertSame( 'Renamed Heading', $stored[0]['label'] ?? null, 'label must be a top-level element key' );
		$this->assertArrayNotHasKey( '_label', $stored[0]['settings'], 'label must never be stored inside settings' );
		$this->assertSame( 'hi', $stored[0]['settings']['text'], 'Existing settings must be preserved by a rename' );
	}

	/**
	 * An empty label clears it back to the element default.
	 *
	 * @return void
	 */
	public function test_update_element_empty_label_clears_it(): void {
		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$service->save_elements(
			$post_id,
			array(
				array(
					'id'       => 'aaa111',
					'name'     => 'heading',
					'parent'   => 0,
					'children' => array(),
					'settings' => array(),
					'label'    => 'Old Name',
				),
			)
		);

		$service->update_element( $post_id, 'aaa111', array(), '' );

		$stored = $service->get_elements( $post_id );
		$this->assertArrayNotHasKey( 'label', $stored[0], 'An empty label must remove the key entirely' );
	}

	/**
	 * Omitting the label must leave an existing one untouched.
	 *
	 * @return void
	 */
	public function test_update_element_without_label_preserves_existing(): void {
		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$service->save_elements(
			$post_id,
			array(
				array(
					'id'       => 'aaa111',
					'name'     => 'heading',
					'parent'   => 0,
					'children' => array(),
					'settings' => array(),
					'label'    => 'Keep Me',
				),
			)
		);

		$result = $service->update_element( $post_id, 'aaa111', array( 'text' => 'changed' ) );

		$this->assertFalse( $result['label_updated'] );

		$stored = $service->get_elements( $post_id );
		$this->assertSame( 'Keep Me', $stored[0]['label'] ?? null );
		$this->assertSame( 'changed', $stored[0]['settings']['text'] );
	}

	/**
	 * A rename with no settings must be accepted rather than rejected for missing settings.
	 *
	 * @return void
	 */
	public function test_element_update_allows_label_only_call(): void {
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$service = new BricksService();
		$service->save_elements(
			$post_id,
			array(
				array(
					'id'       => 'aaa111',
					'name'     => 'heading',
					'parent'   => 0,
					'children' => array(),
					'settings' => array(),
				),
			)
		);

		$result = $this->router_call(
			'tool_update_element',
			array(
				array(
					'post_id'    => $post_id,
					'element_id' => 'aaa111',
					'label'      => 'Just A Rename',
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'A label-only update must not require settings' );
	}

	/**
	 * With neither settings nor label there is nothing to do — still an error.
	 *
	 * @return void
	 */
	public function test_element_update_still_requires_settings_or_label(): void {
		$result = $this->router_call(
			'tool_update_element',
			array(
				array(
					'post_id'    => 1234,
					'element_id' => 'aaa111',
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_settings', $result->get_error_code() );
	}
}
