<?php
/**
 * Unit test: what tools/list actually hands a caller.
 *
 * get_available_tools() exposes only the consolidated tools, so an action added to `page` or
 * `element` is unreachable in practice until `content` routes it and documents it. That is how
 * a working server-side action can ship invisible. These tests pin both halves of the contract:
 * the action must be dispatchable through the visible tool, and its parameters must be
 * documented in the description the caller receives.
 *
 * @package BricksMCP\Tests\Unit\MCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the visible (consolidated) tool surface.
 */
final class VisibleToolContractTest extends TestCase {

	/**
	 * Router under test.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Build a Router.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		/*
		 * The Bricks-specific tools are only registered when Bricks is active, so without the
		 * doubles get_available_tools() returns just get_site_info and content - and the rest of
		 * the visible surface would silently pass unexamined.
		 */
		require_once dirname( __DIR__, 2 ) . '/stubs/bricks-classes.php';

		$this->router = new Router();

		/*
		 * In WordPress these register on after_setup_theme, because Bricks is a theme and is not
		 * loaded yet on plugins_loaded. There is no action dispatch under the test stubs, so the
		 * callback is invoked directly.
		 */
		$this->router->register_bricks_tools();
	}

	/**
	 * Fetch one tool definition from the MCP-visible list.
	 *
	 * @param string $name Tool name.
	 * @return array<string, mixed>|null The tool, or null when it is not exposed.
	 */
	private function visible_tool( string $name ): ?array {
		foreach ( $this->router->get_available_tools() as $tool ) {
			if ( $tool['name'] === $name ) {
				return $tool;
			}
		}

		return null;
	}

	/**
	 * The consolidated content tool is the one callers see; it must accept apply_template.
	 *
	 * @return void
	 */
	public function test_content_tool_advertises_apply_template(): void {
		$content = $this->visible_tool( 'content' );

		$this->assertNotNull( $content, 'content is the tool tools/list exposes for page and element work' );
		$this->assertContains(
			'apply_template',
			$content['inputSchema']['properties']['action']['enum'],
			'A page action missing from the content enum cannot be called by a schema-respecting client'
		);
	}

	/**
	 * Its parameters have to be declared, or a caller cannot pass them.
	 *
	 * @return void
	 */
	public function test_content_tool_declares_apply_template_parameters(): void {
		$properties = $this->visible_tool( 'content' )['inputSchema']['properties'];

		foreach ( array( 'template_id', 'mode', 'regenerate_ids' ) as $key ) {
			$this->assertArrayHasKey( $key, $properties, "content must declare {$key}" );
		}

		$this->assertSame(
			array( 'replace', 'append', 'prepend' ),
			$properties['mode']['enum']
		);
	}

	/**
	 * A description that stops at the one-line summary documents nothing a caller can act on.
	 *
	 * @return void
	 */
	public function test_visible_tool_descriptions_keep_their_action_documentation(): void {
		foreach ( array( 'content', 'template', 'design', 'component', 'media', 'menu', 'bricks', 'code' ) as $name ) {
			$description = (string) $this->visible_tool( $name )['description'];

			$this->assertStringContainsString(
				"\n- ",
				$description,
				"The {$name} tool's description is a bare summary: it lists no actions, so a caller has no contract to work from"
			);
		}
	}

	/**
	 * The normalized summary is still the first thing in the description.
	 *
	 * @return void
	 */
	public function test_normalized_summary_remains_the_lede(): void {
		$description = (string) $this->visible_tool( 'content' )['description'];

		$this->assertStringStartsWith( 'Manage WordPress and Bricks content', $description );
	}

	/**
	 * apply_template routes to the page dispatcher rather than falling through as invalid.
	 *
	 * @return void
	 */
	public function test_apply_template_is_routed_not_rejected_as_invalid_action(): void {
		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		// No post_id, so the page handler is reached and complains about that instead.
		$result = $this->router->tool_content( array( 'action' => 'apply_template' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame(
			'invalid_action',
			$result->get_error_code(),
			'apply_template must be in the content dispatcher\'s page action list'
		);

		unset( $GLOBALS['_bricks_mcp_test_current_user_can'] );
	}
}
