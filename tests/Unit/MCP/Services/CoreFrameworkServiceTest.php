<?php
/**
 * Unit tests: the Core Framework preset write path.
 *
 * Assertions are on the STORED preset blob rather than on return values, for the same reason
 * BricksServiceGlobalVariableNameTest asserts on the stored option: a write that reports the
 * right value while persisting the wrong one is exactly the defect class this plugin keeps
 * hitting, and a return-value assertion cannot see it.
 *
 * Two hazards specific to this path are pinned here:
 *   1. Colour tokens are emitted twice by Core Framework — a light block and a dark-inversion
 *      block — so a write that sets only one leaves the other on the old value.
 *   2. The preset is one JSON blob covering the whole design system, so a read-modify-write must
 *      preserve every key it did not intend to change, including empty objects that assoc-mode
 *      decoding would silently turn into arrays.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\CoreFrameworkService;
use PHPUnit\Framework\TestCase;

/**
 * A service that reports Core Framework absent.
 *
 * class_exists() cannot be un-declared once the doubles are loaded, so the absent case is
 * modelled by overriding the single gate every entry point runs through. That is what the test
 * actually needs to prove: that no read or write path skips the gate.
 */
final class InactiveCoreFrameworkService extends CoreFrameworkService {

	/**
	 * Always report Core Framework missing.
	 *
	 * @return bool Always false.
	 */
	public function is_core_framework_active(): bool {
		return false;
	}
}

/**
 * Behavioural tests for CoreFrameworkService.
 */
final class CoreFrameworkServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var CoreFrameworkService
	 */
	private CoreFrameworkService $service;

	/**
	 * Install the doubles and seed a preset shaped like the live one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/core-framework-classes.php';

		$GLOBALS['_bricks_mcp_test_options'] = array();

		bricks_mcp_test_reset_core_framework( $this->preset_fixture() );

		$this->service = new CoreFrameworkService();
	}

	/**
	 * A preset with the same shape as the live one: colour system with light/dark pairs, shades,
	 * a transparent ramp, a stylesheet variable group, and untouched neighbours.
	 *
	 * @return array<string, mixed> Preset fixture.
	 */
	private function preset_fixture(): array {
		return array(
			'variablePrefix' => 'cf-',
			'classPrefix'    => 'cf-',
			'preferences'    => array(
				'min_screen_width' => 360,
				'max_screen_width' => 1440,
			),
			// An empty JSON object: assoc-mode decoding would re-encode this as [] and change
			// a key this service never meant to touch.
			'figmaData'      => new \stdClass(),
			'styleSheetData' => array(
				'colorStyles'   => array(
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
					array(
						'id'         => 'grp-classes',
						'name'       => 'Cards',
						'type'       => 'class',
						'cssObjects' => array(
							array(
								'id'           => 'obj-2',
								'selector'     => '.card',
								'declarations' => array(
									array(
										'property' => 'padding',
										'value'    => '1rem',
										'id'       => 'dec-2',
									),
								),
							),
						),
					),
				),
				'designStyles'  => array(),
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
									'gen'        => array( 'bg', 'text' ),
								),
								array(
									'id'                   => 'col-2',
									'name'                 => 'primary',
									'value'                => '#123456',
									'isDarkMode'           => true,
									'darkValue'            => '#001122',
									'format'               => 'hex',
									'transparent'          => true,
									'transparentVariables' => array( 5, 10 ),
									'shades'               => array(
										array(
											'name'  => 'primary-d-1',
											'value' => '#0f2b44',
										),
									),
									'darkShades'           => array(
										array(
											'name'  => 'primary-d-1',
											'value' => '#03101a',
										),
									),
									'tints'                => array(),
									'darkTints'            => array(),
									'gen'                  => array( 'bg' ),
								),
							),
						),
						array(
							'id'     => 'grp-text',
							'name'   => 'Text',
							'colors' => array(
								array(
									'id'         => 'col-3',
									'name'       => 'text-body',
									'value'      => 'hsla(0,0%,25%,1)',
									'isDarkMode' => true,
									'darkValue'  => 'hsla(0,0%,75%,1)',
									'format'     => 'hsla',
									'shades'     => array(),
									'darkShades' => array(),
									'tints'      => array(),
									'darkTints'  => array(),
									'gen'        => array( 'text' ),
								),
							),
						),
					),
				),
				'FONTS'        => array( 'fonts' => array() ),
			),
		);
	}

	/**
	 * Pull one token out of a list result.
	 *
	 * @param array<string, mixed> $result Result of list_tokens().
	 * @param string               $name   Full token name.
	 * @return array<string, mixed>|null Token entry.
	 */
	private function token_in( array $result, string $name ): ?array {
		foreach ( $result['tokens'] as $token ) {
			if ( $token['name'] === $name ) {
				return $token;
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Capability detection — fail closed, never improvise storage.
	// -----------------------------------------------------------------------

	/**
	 * Every entry point must refuse when Core Framework is not installed.
	 *
	 * @return void
	 */
	public function test_every_entry_point_fails_closed_without_core_framework(): void {
		$inactive = new InactiveCoreFrameworkService();

		$calls = array(
			'list_tokens'         => $inactive->list_tokens(),
			'get_token'           => $inactive->get_token( '--cf-darkblue1' ),
			'update_token'        => $inactive->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) ),
			'batch_update_tokens' => $inactive->batch_update_tokens( array( array( 'token' => 'darkblue1', 'value' => '#11175e' ) ) ),
			'create_token'        => $inactive->create_token( '--cf-new', array( 'value' => '#000000' ) ),
			'delete_token'        => $inactive->delete_token( '--cf-darkblue1' ),
		);

		foreach ( $calls as $method => $result ) {
			$this->assertInstanceOf( \WP_Error::class, $result, "{$method} must fail closed" );
			$this->assertSame( 'core_framework_inactive', $result->get_error_code(), "{$method} must name the missing dependency" );
		}

		$this->assertFalse( $inactive->get_status()['core_framework_active'] );
	}

	/**
	 * Detection is positive when the doubles are installed.
	 *
	 * @return void
	 */
	public function test_detects_core_framework_when_present(): void {
		$this->assertTrue( $this->service->is_core_framework_active() );

		$status = $this->service->get_status();

		$this->assertTrue( $status['preset_readable'] );
		$this->assertSame( 'testpreset', $status['preset_id'] );
		$this->assertSame( 'cf-', $status['variable_prefix'] );
		$this->assertGreaterThan( 0, $status['token_count'] );
	}

	/**
	 * No selected preset means no write, and no invented preset.
	 *
	 * @return void
	 */
	public function test_missing_preset_selection_is_refused(): void {
		update_option( 'core_framework_main', array() );

		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'core_framework_no_preset', $result->get_error_code() );
	}

	/**
	 * A missing table is reported, never created.
	 *
	 * @return void
	 */
	public function test_missing_table_is_reported_not_created(): void {
		$GLOBALS['_bricks_mcp_test_cf_table_exists'] = false;

		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'core_framework_table_missing', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_bricks_mcp_test_cf_update_calls'] );
	}

	/**
	 * A preset id that has no row is an error, not an insert.
	 *
	 * @return void
	 */
	public function test_absent_preset_row_is_refused(): void {
		bricks_mcp_test_reset_core_framework( null );

		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'core_framework_preset_missing', $result->get_error_code() );
	}

	/**
	 * An unparseable blob must never be overwritten.
	 *
	 * @return void
	 */
	public function test_unreadable_preset_is_refused_without_writing(): void {
		$GLOBALS['_bricks_mcp_test_cf_presets']['testpreset']['data'] = 'not json at all';

		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'core_framework_preset_unreadable', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_bricks_mcp_test_cf_update_calls'] );
	}

	/**
	 * A blob that parses but is not the expected schema must be refused.
	 *
	 * @return void
	 */
	public function test_unexpected_schema_is_refused(): void {
		bricks_mcp_test_reset_core_framework( array( 'somethingElse' => true ) );

		$result = $this->service->list_tokens();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'core_framework_schema_unexpected', $result->get_error_code() );
	}

	/**
	 * A failed database write is surfaced rather than reported as success.
	 *
	 * @return void
	 */
	public function test_database_write_failure_is_surfaced(): void {
		$GLOBALS['_bricks_mcp_test_cf_update_fails'] = true;

		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'core_framework_write_failed', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Reads.
	// -----------------------------------------------------------------------

	/**
	 * Colour tokens are listed with both theme values and the prefixed CSS name.
	 *
	 * @return void
	 */
	public function test_list_reports_both_theme_values(): void {
		$result = $this->service->list_tokens();

		$token = $this->token_in( $result, '--cf-darkblue1' );

		$this->assertNotNull( $token, 'The prefixed name is what a caller sees in CSS' );
		$this->assertSame( 'color', $token['kind'] );
		$this->assertSame( '#0f195c', $token['value'] );
		$this->assertSame( '#010104', $token['dark_value'] );
		$this->assertSame( 'Brand', $token['group'] );
		$this->assertTrue( $token['writable'] );
	}

	/**
	 * Stylesheet variable declarations are listed too, with no dark half.
	 *
	 * @return void
	 */
	public function test_list_includes_stylesheet_variable_declarations(): void {
		$token = $this->token_in( $this->service->list_tokens(), '--cf-radius-s' );

		$this->assertNotNull( $token );
		$this->assertSame( 'variable', $token['kind'] );
		$this->assertSame( '6px', $token['value'] );
		$this->assertNull( $token['dark_value'] );
		$this->assertSame( 'colorStyles', $token['section'] );
	}

	/**
	 * Class-type groups are not variables and must not appear as tokens.
	 *
	 * @return void
	 */
	public function test_list_excludes_class_group_declarations(): void {
		$names = array_column( $this->service->list_tokens()['tokens'], 'name' );

		$this->assertContains( '--cf-radius-s', $names, 'Guard against an empty list making this pass vacuously' );
		$this->assertNotContains( '--cf-padding', $names, 'A class rule is not a design token' );
	}

	/**
	 * Transparent ramp names resolve in CSS but are derived, so they are listed unwritable.
	 *
	 * @return void
	 */
	public function test_list_marks_derived_alpha_tokens_unwritable(): void {
		$token = $this->token_in( $this->service->list_tokens(), '--cf-primary-10' );

		$this->assertNotNull( $token, 'The transparent ramp does emit CSS, so it must be discoverable' );
		$this->assertSame( 'color_alpha', $token['kind'] );
		$this->assertFalse( $token['writable'] );
	}

	/**
	 * Shades are stored pairs and are listed with both halves.
	 *
	 * @return void
	 */
	public function test_list_reports_shades_with_their_dark_counterpart(): void {
		$token = $this->token_in( $this->service->list_tokens(), '--cf-primary-d-1' );

		$this->assertNotNull( $token );
		$this->assertSame( 'color_shade', $token['kind'] );
		$this->assertSame( '#0f2b44', $token['value'] );
		$this->assertSame( '#03101a', $token['dark_value'] );
	}

	/**
	 * The search filter narrows the list.
	 *
	 * @return void
	 */
	public function test_list_search_filters_by_name(): void {
		$result = $this->service->list_tokens( 'darkblue' );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( '--cf-darkblue1', $result['tokens'][0]['name'] );
	}

	/**
	 * All three spellings of a name address the same token.
	 *
	 * @param string $spelling Token name as a caller might write it.
	 * @return void
	 *
	 * @dataProvider provide_name_spellings
	 */
	public function test_get_accepts_every_spelling_of_a_name( string $spelling ): void {
		$result = $this->service->get_token( $spelling );

		$this->assertNotInstanceOf( \WP_Error::class, $result, "{$spelling} must resolve" );
		$this->assertSame( '--cf-darkblue1', $result['name'] );
		$this->assertSame( 'darkblue1', $result['stored_name'] );
	}

	/**
	 * Accepted spellings.
	 *
	 * @return array<string, array{0: string}> Spellings.
	 */
	public static function provide_name_spellings(): array {
		return array(
			'full custom property' => array( '--cf-darkblue1' ),
			'prefixed, no dashes'  => array( 'cf-darkblue1' ),
			'bare stored name'     => array( 'darkblue1' ),
		);
	}

	/**
	 * An unknown token is a clear error, not a silent create.
	 *
	 * @return void
	 */
	public function test_unknown_token_is_not_found(): void {
		$result = $this->service->get_token( '--cf-nope' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'token_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Updates.
	// -----------------------------------------------------------------------

	/**
	 * A colour update must land in the stored blob for both themes.
	 *
	 * @return void
	 */
	public function test_update_writes_both_theme_values_to_storage(): void {
		$result = $this->service->update_token(
			'--cf-darkblue1',
			array(
				'value'      => '#11175e',
				'dark_value' => '#05061f',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['preset_written'] );

		$stored = bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][0];

		$this->assertSame( '#11175e', $stored['value'], 'The light value must be persisted' );
		$this->assertSame( '#05061f', $stored['darkValue'], 'The dark value must be persisted too' );
		$this->assertTrue( $stored['isDarkMode'], 'A dark value only emits when isDarkMode is set' );
	}

	/**
	 * A light-only update must not disturb the dark value.
	 *
	 * @return void
	 */
	public function test_update_light_only_leaves_the_dark_value_alone(): void {
		$this->service->update_token( '--cf-text-body', array( 'value' => '#5e6579' ) );

		$stored = bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][1]['colors'][0];

		$this->assertSame( '#5e6579', $stored['value'] );
		$this->assertSame( 'hsla(0,0%,75%,1)', $stored['darkValue'] );
	}

	/**
	 * The one changed value aside, the blob must come back byte-identical.
	 *
	 * @return void
	 */
	public function test_update_preserves_every_other_key(): void {
		$before = json_decode( bricks_mcp_test_cf_raw_preset(), true );

		$this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$after = bricks_mcp_test_cf_stored_preset();

		$before['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][0]['value'] = '#11175e';

		$this->assertSame( $before, $after, 'A targeted merge must change exactly one leaf' );
	}

	/**
	 * An empty JSON object must survive the round trip as an object.
	 *
	 * Decoding to associative arrays would turn {} into [] and re-encode it as a list, changing
	 * the shape of a key this service never touched — the classic re-encoding drift.
	 *
	 * @return void
	 */
	public function test_update_does_not_turn_empty_objects_into_arrays(): void {
		$this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertStringContainsString(
			'"figmaData":{}',
			bricks_mcp_test_cf_raw_preset(),
			'An empty object must not be re-encoded as an empty array'
		);
	}

	/**
	 * Slashes and non-ASCII must not be escaped back into the blob.
	 *
	 * @return void
	 */
	public function test_update_does_not_escape_slashes_or_unicode(): void {
		$preset = $this->preset_fixture();
		$preset['styleSheetData']['colorStyles'][0]['cssObjects'][0]['declarations'][] = array(
			'property' => '--brand-mark',
			'value'    => 'url(https://example.com/a.svg)',
			'id'       => 'dec-9',
		);
		$preset['projectName'] = 'Café / Bar';

		bricks_mcp_test_reset_core_framework( $preset );

		$this->service->update_token( '--cf-radius-s', array( 'value' => '8px' ) );

		$raw = bricks_mcp_test_cf_raw_preset();

		$this->assertStringContainsString( 'https://example.com/a.svg', $raw );
		$this->assertStringNotContainsString( '\\/', $raw );
		$this->assertStringContainsString( 'Café / Bar', $raw );
	}

	/**
	 * A shade update writes both parallel arrays at the right index.
	 *
	 * @return void
	 */
	public function test_update_of_a_shade_writes_both_arrays(): void {
		$result = $this->service->update_token(
			'--cf-primary-d-1',
			array(
				'value'      => '#111111',
				'dark_value' => '#222222',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$color = bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][1];

		$this->assertSame( '#111111', $color['shades'][0]['value'] );
		$this->assertSame( '#222222', $color['darkShades'][0]['value'] );
	}

	/**
	 * A variable declaration has no dark half, and saying so beats writing a key Core Framework
	 * would never read.
	 *
	 * @return void
	 */
	public function test_update_refuses_a_dark_value_on_a_variable_declaration(): void {
		$result = $this->service->update_token(
			'--cf-radius-s',
			array(
				'value'      => '8px',
				'dark_value' => '10px',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dark_value_unsupported', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_bricks_mcp_test_cf_update_calls'], 'A rejected update must write nothing' );
	}

	/**
	 * A derived ramp entry has no stored value, so writing to it must be refused loudly.
	 *
	 * @return void
	 */
	public function test_update_of_a_derived_alpha_token_is_refused(): void {
		$result = $this->service->update_token( '--cf-primary-10', array( 'value' => '#00000010' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'token_not_writable', $result->get_error_code() );
	}

	/**
	 * An update with neither value is rejected rather than reported as a no-op success.
	 *
	 * @return void
	 */
	public function test_update_requires_at_least_one_value(): void {
		$result = $this->service->update_token( '--cf-darkblue1', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_fields', $result->get_error_code() );
	}

	/**
	 * A value that could escape its declaration is rejected.
	 *
	 * @param string $value Hostile value.
	 * @return void
	 *
	 * @dataProvider provide_hostile_values
	 */
	public function test_update_rejects_values_that_escape_the_declaration( string $value ): void {
		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => $value ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_value', $result->get_error_code() );
	}

	/**
	 * Hostile value candidates.
	 *
	 * @return array<string, array{0: string}> Values.
	 */
	public static function provide_hostile_values(): array {
		return array(
			'declaration break' => array( '#fff; color: red' ),
			'rule injection'    => array( '#fff} body{display:none' ),
			'import'            => array( 'red; @import url("http://evil.test/x.css")' ),
			'legacy expression' => array( 'expression(alert(1))' ),
		);
	}

	/**
	 * Percent-bearing colour values must survive intact.
	 *
	 * sanitize_text_field() strips %XX sequences, so using it here would quietly rewrite the
	 * live value of --cf-text-body into a different colour.
	 *
	 * @return void
	 */
	public function test_update_preserves_percentages_in_colour_values(): void {
		$this->service->update_token( '--cf-text-body', array( 'value' => 'hsla(0,0%,25%,1)' ) );

		$stored = bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][1]['colors'][0];

		$this->assertSame( 'hsla(0,0%,25%,1)', $stored['value'] );
	}

	// -----------------------------------------------------------------------
	// Batch updates.
	// -----------------------------------------------------------------------

	/**
	 * A batch applies every entry against one read-modify-write.
	 *
	 * @return void
	 */
	public function test_batch_update_applies_everything_in_one_write(): void {
		$result = $this->service->batch_update_tokens(
			array(
				array(
					'token'      => '--cf-darkblue1',
					'value'      => '#11175e',
					'dark_value' => '#05061f',
				),
				array(
					'token' => '--cf-text-body',
					'value' => '#5e6579',
				),
				array(
					'token' => '--cf-radius-s',
					'value' => '8px',
				),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 3, $result['updated'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 1, $GLOBALS['_bricks_mcp_test_cf_update_calls'], 'A batch must not write once per token' );

		$stored = bricks_mcp_test_cf_stored_preset();

		$this->assertSame( '#11175e', $stored['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][0]['value'] );
		$this->assertSame( '#05061f', $stored['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][0]['darkValue'] );
		$this->assertSame( '#5e6579', $stored['modulesData']['COLOR_SYSTEM']['groups'][1]['colors'][0]['value'] );
		$this->assertSame( '8px', $stored['styleSheetData']['colorStyles'][0]['cssObjects'][0]['declarations'][0]['value'] );
	}

	/**
	 * A bad entry is reported without discarding the good ones.
	 *
	 * @return void
	 */
	public function test_batch_update_reports_per_entry_errors(): void {
		$result = $this->service->batch_update_tokens(
			array(
				array(
					'token' => '--cf-darkblue1',
					'value' => '#11175e',
				),
				array(
					'token' => '--cf-does-not-exist',
					'value' => '#000000',
				),
			)
		);

		$this->assertCount( 1, $result['updated'] );
		$this->assertArrayHasKey( 1, $result['errors'] );
		$this->assertSame( '#11175e', bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'][0]['value'] );
	}

	/**
	 * When nothing was applicable, nothing is written.
	 *
	 * @return void
	 */
	public function test_batch_update_writes_nothing_when_every_entry_failed(): void {
		$result = $this->service->batch_update_tokens( array( array( 'token' => '--cf-nope', 'value' => '#000000' ) ) );

		$this->assertSame( array(), $result['updated'] );
		$this->assertFalse( $result['preset_written'] );
		$this->assertSame( array(), $GLOBALS['_bricks_mcp_test_cf_update_calls'] );
	}

	// -----------------------------------------------------------------------
	// Create and delete.
	// -----------------------------------------------------------------------

	/**
	 * A new colour lands in the named group with both theme values.
	 *
	 * @return void
	 */
	public function test_create_colour_token_with_both_theme_values(): void {
		$result = $this->service->create_token(
			'--cf-navy-deep',
			array(
				'value'      => '#0a0e46',
				'dark_value' => '#04061f',
				'kind'       => 'color',
				'group'      => 'Brand',
				'gen'        => array( 'bg', 'text' ),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'created', $result['action'] );
		$this->assertSame( '--cf-navy-deep', $result['token'] );

		$colors = bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'];
		$new    = end( $colors );

		$this->assertSame( 'navy-deep', $new['name'], 'Names are stored bare; the -- and the prefix are added on emit' );
		$this->assertSame( '#0a0e46', $new['value'] );
		$this->assertSame( '#04061f', $new['darkValue'] );
		$this->assertTrue( $new['isDarkMode'] );
		$this->assertSame( 'hex', $new['format'] );
		$this->assertSame( array( 'bg', 'text' ), $new['gen'] );
		$this->assertNotEmpty( $new['id'] );
	}

	/**
	 * Creating leaves the colours that were already there untouched.
	 *
	 * @return void
	 */
	public function test_create_does_not_disturb_existing_colours(): void {
		$this->service->create_token( 'navy-deep', array( 'value' => '#0a0e46' ) );

		$colors = bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'];

		$this->assertCount( 3, $colors );
		$this->assertSame( '#0f195c', $colors[0]['value'] );
		$this->assertSame( '#123456', $colors[1]['value'] );
	}

	/**
	 * A variable-kind create appends a declaration to a variable group.
	 *
	 * @return void
	 */
	public function test_create_variable_token_appends_a_declaration(): void {
		$result = $this->service->create_token(
			'--cf-radius-xxl',
			array(
				'value' => '40px',
				'kind'  => 'variable',
				'group' => 'Base Variables',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$declarations = bricks_mcp_test_cf_stored_preset()['styleSheetData']['colorStyles'][0]['cssObjects'][0]['declarations'];

		$this->assertCount( 2, $declarations );
		$this->assertSame( '--radius-xxl', $declarations[1]['property'], 'The stored property omits the preset prefix' );
		$this->assertSame( '40px', $declarations[1]['value'] );
	}

	/**
	 * A variable declaration cannot carry a dark value, and saying so is better than dropping it.
	 *
	 * @return void
	 */
	public function test_create_variable_token_refuses_a_dark_value(): void {
		$result = $this->service->create_token(
			'--cf-radius-xxl',
			array(
				'value'      => '40px',
				'dark_value' => '20px',
				'kind'       => 'variable',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dark_value_unsupported', $result->get_error_code() );
	}

	/**
	 * Creating over an existing name is refused rather than silently duplicating it.
	 *
	 * @return void
	 */
	public function test_create_refuses_an_existing_token(): void {
		$result = $this->service->create_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'token_exists', $result->get_error_code() );
	}

	/**
	 * An unknown target group is an error, not a new group.
	 *
	 * @return void
	 */
	public function test_create_refuses_an_unknown_group(): void {
		$result = $this->service->create_token(
			'navy-deep',
			array(
				'value' => '#0a0e46',
				'group' => 'No Such Group',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'group_not_found', $result->get_error_code() );
	}

	/**
	 * Deleting a colour removes exactly that colour.
	 *
	 * @return void
	 */
	public function test_delete_removes_only_the_named_colour(): void {
		$result = $this->service->delete_token( '--cf-darkblue1' );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'deleted', $result['action'] );

		$colors = bricks_mcp_test_cf_stored_preset()['modulesData']['COLOR_SYSTEM']['groups'][0]['colors'];

		$this->assertCount( 1, $colors );
		$this->assertSame( 'primary', $colors[0]['name'] );
	}

	/**
	 * Deleting a variable removes only that declaration.
	 *
	 * @return void
	 */
	public function test_delete_removes_only_the_named_declaration(): void {
		$this->service->delete_token( '--cf-radius-s' );

		$stored = bricks_mcp_test_cf_stored_preset();

		$this->assertSame( array(), $stored['styleSheetData']['colorStyles'][0]['cssObjects'][0]['declarations'] );
		$this->assertCount( 1, $stored['styleSheetData']['colorStyles'][1]['cssObjects'][0]['declarations'], 'The class group is untouched' );
	}

	/**
	 * A derived entry cannot be deleted on its own.
	 *
	 * @return void
	 */
	public function test_delete_refuses_a_derived_token(): void {
		$result = $this->service->delete_token( '--cf-primary-10' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'undeletable_token', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// The derived colour cache, and what regeneration this path can honestly claim.
	// -----------------------------------------------------------------------

	/**
	 * core_framework_colors is a projection of the preset, so a write refreshes the matching
	 * rows — light against light, dark against dark.
	 *
	 * @return void
	 */
	public function test_write_refreshes_the_derived_colour_cache_by_theme(): void {
		update_option(
			'core_framework_colors',
			array(
				array(
					'name'  => 'darkblue1',
					'value' => '#0f195c',
					'raw'   => 'var(--cf-darkblue1)',
					'id'    => 'col-1',
				),
				array(
					'name'  => 'darkblue1',
					'value' => '#010104',
					'raw'   => 'var(--cf-darkblue1)',
					'id'    => 'col-1.d',
					'dark'  => true,
				),
				array(
					'name'  => 'text-body',
					'value' => 'hsla(0,0%,25%,1)',
					'raw'   => 'var(--cf-text-body)',
					'id'    => 'col-3',
				),
			)
		);

		$result = $this->service->update_token(
			'--cf-darkblue1',
			array(
				'value'      => '#11175e',
				'dark_value' => '#05061f',
			)
		);

		$this->assertSame( 2, $result['colors_option_synced'] );

		$colors = get_option( 'core_framework_colors' );

		$this->assertSame( '#11175e', $colors[0]['value'], 'The light row follows the light value' );
		$this->assertSame( '#05061f', $colors[1]['value'], 'The dark row follows the dark value' );
		$this->assertSame( 'hsla(0,0%,25%,1)', $colors[2]['value'], 'An unrelated row is untouched' );
		$this->assertSame( 'var(--cf-darkblue1)', $colors[0]['raw'], 'Only the value is merged; nothing else is rewritten' );
	}

	/**
	 * The cache refresh can be switched off.
	 *
	 * @return void
	 */
	public function test_colour_cache_sync_can_be_disabled(): void {
		update_option(
			'core_framework_colors',
			array(
				array(
					'name'  => 'darkblue1',
					'value' => '#0f195c',
				),
			)
		);

		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ), false );

		$this->assertSame( 0, $result['colors_option_synced'] );
		$this->assertSame( '#0f195c', get_option( 'core_framework_colors' )[0]['value'] );
	}

	/**
	 * The stylesheet is not regenerated, and the result says so instead of implying otherwise.
	 *
	 * Core Framework compiles core_framework.css in its browser admin app and posts the finished
	 * string to /update-main; there is no server-side generator to call, and hand-writing one
	 * here would drift from Core Framework at its next release.
	 *
	 * @return void
	 */
	public function test_write_reports_the_stylesheet_as_stale_and_not_regenerated(): void {
		$result = $this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertTrue( $result['stylesheet_stale'] );
		$this->assertFalse( $result['stylesheet_regenerated'] );
		$this->assertStringContainsString( 'core_framework.css', (string) $result['stylesheet_path'] );
		$this->assertNotEmpty( $result['next_step'] );
	}

	/**
	 * Core Framework's own cache purge runs after a write, as it does on its REST write paths.
	 *
	 * @return void
	 */
	public function test_write_runs_core_frameworks_own_cache_purge(): void {
		$this->service->update_token( '--cf-darkblue1', array( 'value' => '#11175e' ) );

		$this->assertSame( 1, $GLOBALS['_bricks_mcp_test_cf_purges'] );
	}
}
