<?php
/**
 * Unit tests: the design tool's core_framework domain honours every parameter it advertises.
 *
 * This plugin's most expensive recurring defect is a schema that declares a parameter no handler
 * reads: the unknown key validates (no schema sets additionalProperties: false), is never read,
 * and the call reports success having discarded the payload — see SchemaHandlerContractTest and
 * SchemaKeysAreReadTest. SchemaKeysAreReadTest only proves a key is read SOMEWHERE in the tree,
 * which a new domain can satisfy by accident because another domain happens to use the same
 * spelling ("value", "group", "section", "format" are all shared). These tests close that gap by
 * driving each declared key through the visible tool and asserting on the STORED preset.
 *
 * @package BricksMCP\Tests\Unit\MCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use PHPUnit\Framework\TestCase;

/**
 * Schema/handler contract tests for design domain core_framework.
 */
final class CoreFrameworkSchemaContractTest extends TestCase {

	/**
	 * Router under test.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Install both sets of doubles and seed a preset.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/stubs/bricks-classes.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/core-framework-classes.php';

		$GLOBALS['_bricks_mcp_test_options']          = array();
		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		bricks_mcp_test_reset_core_framework(
			array(
				'variablePrefix' => 'cf-',
				'styleSheetData' => array(
					'designStyles' => array(
						array(
							'id'         => 'grp-vars',
							'name'       => 'Base Variables',
							'type'       => 'variable',
							'cssObjects' => array(
								array(
									'id'           => 'obj-1',
									'selector'     => ':root',
									'declarations' => array(
										array(
											'property' => '--radius-s',
											'value'    => '6px',
											'id'       => 'dec-1',
										),
									),
								),
							),
						),
					),
				),
				'modulesData'    => array(
					'COLOR_SYSTEM' => array(
						'groups' => array(
							array(
								'id'     => 'grp-brand',
								'name'   => 'Brand',
								'colors' => array(
									array(
										'id'         => 'col-1',
										'name'       => 'darkblue1',
										'value'      => '#0f195c',
										'isDarkMode' => true,
										'darkValue'  => '#010104',
										'format'     => 'hex',
										'shades'     => array(),
										'darkShades' => array(),
										'tints'      => array(),
										'darkTints'  => array(),
										'gen'        => array(),
									),
								),
							),
							array(
								'id'     => 'grp-accent',
								'name'   => 'Accent',
								'colors' => array(),
							),
						),
					),
				),
			)
		);

		$this->router = new Router();
		$this->router->register_bricks_tools();
	}

	/**
	 * The visible design tool's declared properties.
	 *
	 * @return array<string, mixed> Schema properties.
	 */
	private function design_properties(): array {
		foreach ( $this->router->get_available_tools() as $tool ) {
			if ( 'design' === $tool['name'] ) {
				return $tool['inputSchema']['properties'];
			}
		}

		$this->fail( 'design must be exposed by tools/list' );
	}

	/**
	 * Read back the stored colour-system colours.
	 *
	 * @param int $group Group index.
	 * @return array<int, array<string, mixed>> Colours.
	 */
	private function stored_colors( int $group = 0 ): array {
		return bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][ $group ]['colors'];
	}

	// -----------------------------------------------------------------------
	// The advertised surface.
	// -----------------------------------------------------------------------

	/**
	 * The domain has to be in the enum or a schema-respecting client cannot select it.
	 *
	 * @return void
	 */
	public function test_design_tool_advertises_the_core_framework_domain(): void {
		$properties = $this->design_properties();

		$this->assertContains( 'core_framework', $properties['domain']['enum'] );
		$this->assertContains( 'batch_update', $properties['action']['enum'] );
	}

	/**
	 * Every parameter this domain reads must be declared.
	 *
	 * @return void
	 */
	public function test_design_tool_declares_every_core_framework_parameter(): void {
		$properties = $this->design_properties();

		foreach ( array( 'token', 'tokens', 'value', 'dark_value', 'kind', 'group', 'section', 'gen', 'format', 'sync_colors_option', 'search' ) as $key ) {
			$this->assertArrayHasKey( $key, $properties, "design must declare {$key} for the core_framework domain" );
		}
	}

	/**
	 * The description is the only contract most callers read, so the domain must appear in it —
	 * including the warning that the generated stylesheet is not refreshed by these writes.
	 *
	 * @return void
	 */
	public function test_design_description_documents_the_domain_and_its_staleness(): void {
		$description = '';

		foreach ( $this->router->get_available_tools() as $tool ) {
			if ( 'design' === $tool['name'] ) {
				$description = (string) $tool['description'];
			}
		}

		$this->assertStringContainsString( '- core_framework:', $description );
		$this->assertStringContainsString( 'stylesheet_stale', $description );
	}

	/**
	 * The domain routes rather than falling through as invalid.
	 *
	 * @return void
	 */
	public function test_core_framework_domain_is_routed_not_rejected(): void {
		$result = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'get_status',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['core_framework_active'] );
	}

	/**
	 * An unknown action is rejected by name rather than doing something arbitrary.
	 *
	 * @return void
	 */
	public function test_unknown_action_is_rejected(): void {
		$result = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'nonsense',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_action', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Each declared parameter, driven end to end.
	// -----------------------------------------------------------------------

	/**
	 * token + value + dark_value must all reach storage on an update.
	 *
	 * @return void
	 */
	public function test_update_honours_token_value_and_dark_value(): void {
		$result = $this->router->tool_design(
			array(
				'domain'     => 'core_framework',
				'action'     => 'update',
				'token'      => '--cf-darkblue1',
				'value'      => '#11175e',
				'dark_value' => '#05061f',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$stored = $this->stored_colors()[0];

		$this->assertSame( '#11175e', $stored['value'], 'value must not be dropped between schema and storage' );
		$this->assertSame( '#05061f', $stored['darkValue'], 'dark_value must not be dropped between schema and storage' );
	}

	/**
	 * `name` is the spelling the other design domains use, so it must alias onto token.
	 *
	 * @return void
	 */
	public function test_update_accepts_name_as_an_alias_for_token(): void {
		$this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'update',
				'name'   => '--cf-darkblue1',
				'value'  => '#11175e',
			)
		);

		$this->assertSame( '#11175e', $this->stored_colors()[0]['value'] );
	}

	/**
	 * tokens must reach the batch handler and every entry must land.
	 *
	 * @return void
	 */
	public function test_batch_update_honours_the_tokens_array(): void {
		$result = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'batch_update',
				'tokens' => array(
					array(
						'token'      => 'darkblue1',
						'value'      => '#11175e',
						'dark_value' => '#05061f',
					),
					array(
						'token' => 'radius-s',
						'value' => '8px',
					),
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 2, $result['updated'] );

		$stored = bricks_mcp_test_cf_stored_preset();

		$this->assertSame( '#11175e', $stored['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][0]['value'] );
		$this->assertSame( '#05061f', $stored['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][0]['darkValue'] );
		$this->assertSame( '8px', $stored['styleSheetData']['designStyles'][0]['cssObjects'][0]['declarations'][0]['value'] );
	}

	/**
	 * batch_update without tokens is rejected by name rather than silently doing nothing.
	 *
	 * @return void
	 */
	public function test_batch_update_requires_tokens(): void {
		$result = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'batch_update',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_tokens', $result->get_error_code() );
	}

	/**
	 * kind, group, gen and format must all reach the created colour.
	 *
	 * @return void
	 */
	public function test_create_honours_kind_group_gen_and_format(): void {
		$result = $this->router->tool_design(
			array(
				'domain'     => 'core_framework',
				'action'     => 'create',
				'token'      => '--cf-navy-deep',
				'value'      => '#0a0e46',
				'dark_value' => '#04061f',
				'kind'       => 'color',
				'group'      => 'Accent',
				'gen'        => array( 'bg' ),
				'format'     => 'hex',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$this->assertCount( 1, $this->stored_colors( 0 ), 'group must decide where the colour lands' );

		$created = $this->stored_colors( 1 )[0];

		$this->assertSame( 'navy-deep', $created['name'] );
		$this->assertSame( '#0a0e46', $created['value'] );
		$this->assertSame( '#04061f', $created['darkValue'] );
		$this->assertSame( array( 'bg' ), $created['gen'], 'gen must not be dropped' );
		$this->assertSame( 'hex', $created['format'], 'format must not be dropped' );
	}

	/**
	 * kind and section must steer a variable-kind create.
	 *
	 * @return void
	 */
	public function test_create_honours_kind_variable_and_section(): void {
		$result = $this->router->tool_design(
			array(
				'domain'  => 'core_framework',
				'action'  => 'create',
				'token'   => '--cf-radius-xxl',
				'value'   => '40px',
				'kind'    => 'variable',
				'section' => 'designStyles',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'designStyles', $result['section'] );

		$declarations = bricks_mcp_test_cf_stored_preset()['styleSheetData']['designStyles'][0]['cssObjects'][0]['declarations'];

		$this->assertCount( 2, $declarations );
		$this->assertSame( '--radius-xxl', $declarations[1]['property'] );
	}

	/**
	 * A section this preset does not use must not silently fall back to another one.
	 *
	 * @return void
	 */
	public function test_create_reports_an_unusable_section_rather_than_guessing(): void {
		$result = $this->router->tool_design(
			array(
				'domain'  => 'core_framework',
				'action'  => 'create',
				'token'   => '--cf-radius-xxl',
				'value'   => '40px',
				'kind'    => 'variable',
				'section' => 'spacingStyles',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'group_not_found', $result->get_error_code() );
	}

	/**
	 * sync_colors_option must be honoured rather than ignored.
	 *
	 * @return void
	 */
	public function test_sync_colors_option_is_honoured_in_both_positions(): void {
		$rows = array(
			array(
				'name'  => 'darkblue1',
				'value' => '#0f195c',
			),
		);

		update_option( 'core_framework_colors', $rows );

		$off = $this->router->tool_design(
			array(
				'domain'             => 'core_framework',
				'action'             => 'update',
				'token'              => 'darkblue1',
				'value'              => '#11175e',
				'sync_colors_option' => false,
			)
		);

		$this->assertSame( 0, $off['colors_option_synced'] );
		$this->assertSame( '#0f195c', get_option( 'core_framework_colors' )[0]['value'] );

		$on = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'update',
				'token'  => 'darkblue1',
				'value'  => '#22186f',
			)
		);

		$this->assertSame( 1, $on['colors_option_synced'], 'The default is to keep the projection in step with its source' );
		$this->assertSame( '#22186f', get_option( 'core_framework_colors' )[0]['value'] );
	}

	/**
	 * search must filter the list rather than being ignored.
	 *
	 * @return void
	 */
	public function test_list_honours_search_and_kind(): void {
		$all = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'list',
			)
		);

		$this->assertSame( 2, $all['total'] );

		$filtered = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'list',
				'search' => 'radius',
			)
		);

		$this->assertSame( 1, $filtered['total'] );
		$this->assertSame( '--cf-radius-s', $filtered['tokens'][0]['name'] );

		$by_kind = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'list',
				'kind'   => 'color',
			)
		);

		$this->assertSame( 1, $by_kind['total'] );
		$this->assertSame( '--cf-darkblue1', $by_kind['tokens'][0]['name'] );
	}

	/**
	 * token must reach get and delete too.
	 *
	 * @return void
	 */
	public function test_get_and_delete_honour_token(): void {
		$got = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'get',
				'token'  => '--cf-darkblue1',
			)
		);

		$this->assertSame( '#0f195c', $got['value'] );
		$this->assertSame( '#010104', $got['dark_value'] );

		$deleted = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'delete',
				'token'  => '--cf-darkblue1',
			)
		);

		$this->assertSame( 'deleted', $deleted['action'] );
		$this->assertSame( array(), $this->stored_colors() );
	}

	/**
	 * Each write action must refuse a missing token by name rather than acting on nothing.
	 *
	 * @param string $action Action name.
	 * @return void
	 *
	 * @dataProvider provide_token_requiring_actions
	 */
	public function test_token_is_required_by_every_single_token_action( string $action ): void {
		$result = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => $action,
				'value'  => '#11175e',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_token', $result->get_error_code() );
	}

	/**
	 * Actions that address a single token.
	 *
	 * @return array<string, array{0: string}> Actions.
	 */
	public static function provide_token_requiring_actions(): array {
		return array(
			'get'    => array( 'get' ),
			'create' => array( 'create' ),
			'update' => array( 'update' ),
			'delete' => array( 'delete' ),
		);
	}

	/**
	 * An update naming neither value is refused, so a caller cannot believe a no-op succeeded.
	 *
	 * @return void
	 */
	public function test_update_without_either_value_is_refused(): void {
		$result = $this->router->tool_design(
			array(
				'domain' => 'core_framework',
				'action' => 'update',
				'token'  => 'darkblue1',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_fields', $result->get_error_code() );
	}
}
