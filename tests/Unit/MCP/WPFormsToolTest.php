<?php
/**
 * WPForms MCP tool tests.
 *
 * Covers the Router side of the WPForms surface: that the tool reaches tools/list at all, that
 * every advertised action is wired to a handler, and that its arguments arrive intact.
 *
 * @package BricksMCP\Tests\Unit\MCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the wpforms tool.
 */
final class WPFormsToolTest extends TestCase {

	/**
	 * Router under test.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Reset state and seed a form.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		bricks_mcp_test_reset_wpforms();
		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		bricks_mcp_test_seed_wpforms_form(
			1631,
			array(
				'id'       => '1631',
				'fields'   => array(
					'3' => array(
						'id'    => '3',
						'type'  => 'select',
						'label' => 'Topic',
					),
				),
				'settings' => array(
					'form_title' => 'Contact Us',
					'honeypot'   => false,
				),
			)
		);

		$this->router = new Router();
	}

	/**
	 * Clean up globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		bricks_mcp_test_reset_wpforms();

		parent::tearDown();
	}

	/**
	 * Fetch the tool from the MCP-visible list.
	 *
	 * @return array<string, mixed>|null The tool, or null when it is not exposed.
	 */
	private function visible_tool(): ?array {
		foreach ( $this->router->get_available_tools() as $tool ) {
			if ( 'wpforms' === $tool['name'] ) {
				return $tool;
			}
		}

		return null;
	}

	/**
	 * A tool the client never sees cannot be called, however well it is implemented.
	 *
	 * @return void
	 */
	public function test_tool_is_exposed_with_every_action_declared(): void {
		$tool = $this->visible_tool();

		$this->assertNotNull( $tool, 'wpforms must reach tools/list when WPForms is active' );
		$this->assertSame(
			array( 'list', 'get', 'update_settings', 'update_field', 'delete_entries', 'get_keywords', 'update_keywords', 'spam_audit' ),
			$tool['inputSchema']['properties']['action']['enum']
		);

		foreach ( array( 'form_id', 'field_id', 'settings', 'properties', 'entry_ids', 'keywords', 'mode' ) as $key ) {
			$this->assertArrayHasKey( $key, $tool['inputSchema']['properties'], "wpforms must declare {$key}" );
		}
	}

	/**
	 * The description has to name the merge semantics; a caller patching live form data with the
	 * wrong mental model overwrites more than it means to.
	 *
	 * @return void
	 */
	public function test_description_documents_merge_semantics_and_verification(): void {
		$description = $this->visible_tool()['description'];

		$this->assertStringContainsString( 'null value deletes the key', $description );
		$this->assertStringContainsString( 'unverified_paths', $description );
	}

	/**
	 * Writes are not read-only and must be annotated accordingly, since clients gate on this.
	 *
	 * @return void
	 */
	public function test_tool_is_annotated_as_a_write_surface(): void {
		$annotations = $this->visible_tool()['annotations'];

		$this->assertFalse( $annotations['readOnlyHint'] );
		$this->assertTrue( $annotations['destructiveHint'] );
	}

	/**
	 * The settings patch has to survive the trip through the dispatcher.
	 *
	 * @return void
	 */
	public function test_update_settings_routes_the_patch_through(): void {
		$result = $this->router->tool_wpforms(
			array(
				'action'   => 'update_settings',
				'form_id'  => 1631,
				'settings' => array( 'honeypot' => '1' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( '1', bricks_mcp_test_stored_wpforms_form( 1631 )['settings']['honeypot'] );
	}

	/**
	 * So does the field patch, including the field ID.
	 *
	 * @return void
	 */
	public function test_update_field_routes_the_patch_through(): void {
		$this->router->tool_wpforms(
			array(
				'action'     => 'update_field',
				'form_id'    => 1631,
				'field_id'   => '3',
				'properties' => array( 'placeholder' => 'Select a topic' ),
			)
		);

		$this->assertSame(
			'Select a topic',
			bricks_mcp_test_stored_wpforms_form( 1631 )['fields']['3']['placeholder']
		);
	}

	/**
	 * Reads route too, and section scoping survives the dispatcher.
	 *
	 * @return void
	 */
	public function test_get_routes_and_honours_section(): void {
		$result = $this->router->tool_wpforms(
			array(
				'action'  => 'get',
				'form_id' => 1631,
				'section' => 'settings',
			)
		);

		$this->assertSame( 'Contact Us', $result['settings']['form_title'] );
		$this->assertArrayNotHasKey( 'form_data', $result );
	}

	/**
	 * Entry IDs must arrive as a list, not be flattened to the first value.
	 *
	 * @return void
	 */
	public function test_delete_entries_routes_the_id_list_through(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_entries'] = array( 11, 12 );

		$result = $this->router->tool_wpforms(
			array(
				'action'    => 'delete_entries',
				'entry_ids' => array( 11, 12 ),
			)
		);

		$this->assertSame( 2, $result['deleted_count'] );
		$this->assertSame( array(), $GLOBALS['_bricks_mcp_test_wpforms_entries'] );
	}

	/**
	 * An unknown action must name the valid ones rather than failing opaquely.
	 *
	 * @return void
	 */
	public function test_unknown_action_is_rejected_with_the_valid_list(): void {
		$result = $this->router->tool_wpforms( array( 'action' => 'delete_form' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_action', $result->get_error_code() );
		$this->assertStringContainsString( 'update_settings', $result->get_error_message() );
		$this->assertStringContainsString( 'spam_audit', $result->get_error_message() );
	}

	/**
	 * Keyword writes default to adding, so a caller that omits mode cannot wipe the site-global
	 * list by accident.
	 *
	 * @return void
	 */
	public function test_keyword_write_defaults_to_add_through_the_dispatcher(): void {
		$GLOBALS['_bricks_mcp_test_options']['wpforms_keyword_filter_keywords'] = wp_json_encode( array( 'viagra' ) );

		$result = $this->router->tool_wpforms(
			array(
				'action'   => 'update_keywords',
				'keywords' => array( 'corGM' ),
			)
		);

		$this->assertSame( 'add', $result['mode'] );
		$this->assertSame( array( 'viagra', 'corGM' ), $result['keywords'] );

		unset( $GLOBALS['_bricks_mcp_test_options']['wpforms_keyword_filter_keywords'] );
	}

	/**
	 * The audit is the only view that shows the global list and the per-form toggles together.
	 *
	 * @return void
	 */
	public function test_spam_audit_routes_and_reports_both_halves(): void {
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = array(
			(object) array(
				'ID'         => 1631,
				'post_title' => 'Contact Us',
			),
		);

		$result = $this->router->tool_wpforms( array( 'action' => 'spam_audit' ) );

		$this->assertArrayHasKey( 'global_keywords', $result );
		$this->assertSame( 1, $result['form_count'] );
		$this->assertFalse( $result['forms'][0]['keyword_filter'] );
		$this->assertSame( 1, $result['keyword_filter_disabled_on'] );

		$GLOBALS['_bricks_mcp_test_get_posts_return'] = array();
	}

	/**
	 * The tool runs its own per-action capability checks, so the blanket manage_options gate must
	 * not shadow them.
	 *
	 * @return void
	 */
	public function test_tool_defers_capability_checks_to_the_service(): void {
		$method = new \ReflectionMethod( $this->router, 'get_tool_capability' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( $this->router, 'wpforms' ) );
	}
}
