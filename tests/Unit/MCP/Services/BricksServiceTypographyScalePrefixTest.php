<?php
/**
 * Unit test: typography scale variable names are stored BARE, without the "--" prefix.
 *
 * Bricks keeps `bricks_global_variables[*]['name']` without leading dashes and adds
 * "--" itself when it writes uploads/bricks/css/global-variables.min.css. Scale steps
 * are stored as prefix + step name, and create/update_typography_scale used to REQUIRE
 * the caller's prefix to start with "--" — so a scale created with "--text-" stored
 * "--text-sm" and emitted `----text-sm: …;`, a syntactically valid custom property
 * that nothing resolves. Same defect as the global-variable path fixed in v1.5.2,
 * reached by a different route.
 *
 * These tests are behavioural: they read the names that actually landed in the option
 * store, which is the only thing Bricks' CSS writer consumes. A doubled prefix looks
 * entirely plausible inside the option array, so asserting on return values alone
 * would not have caught the original bug.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the bare-prefix storage contract of typography scales.
 */
final class BricksServiceTypographyScalePrefixTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var BricksService
	 */
	private BricksService $service;

	/**
	 * Reset the in-memory option store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_bricks_mcp_test_options'] = array();

		$this->service = new BricksService();
	}

	/**
	 * Read the variable names actually persisted to the Bricks option.
	 *
	 * @return array<int, string> Stored variable names.
	 */
	private function stored_names(): array {
		$variables = $GLOBALS['_bricks_mcp_test_options']['bricks_global_variables'] ?? array();

		return array_map(
			static fn( array $var ): string => $var['name'] ?? '',
			$variables
		);
	}

	/**
	 * Read the scale prefix actually persisted on a category.
	 *
	 * @param string $category_id Category ID.
	 * @return string Stored prefix.
	 */
	private function stored_prefix( string $category_id ): string {
		$categories = $GLOBALS['_bricks_mcp_test_options']['bricks_global_variables_categories'] ?? array();

		foreach ( $categories as $category ) {
			if ( ( $category['id'] ?? '' ) === $category_id ) {
				return $category['scale']['prefix'] ?? '';
			}
		}

		return '';
	}

	/**
	 * Two default steps, enough to prove the prefix is applied per step.
	 *
	 * @return array<int, array{name: string, value: string}> Steps.
	 */
	private function steps(): array {
		return array(
			array(
				'name'  => 'sm',
				'value' => '0.875rem',
			),
			array(
				'name'  => 'lg',
				'value' => '1.25rem',
			),
		);
	}

	/**
	 * A "--" prefix is accepted and the step variables are stored bare.
	 *
	 * @return void
	 */
	public function test_create_with_a_dashed_prefix_stores_bare_names(): void {
		$result = $this->service->create_typography_scale( 'Text', $this->steps(), '--text-' );

		$this->assertIsArray( $result, 'A "--" prefix must still be accepted, not rejected' );
		$this->assertSame( 'text-', $result['prefix'], 'The returned prefix must be bare' );
		$this->assertSame(
			array( 'text-sm', 'text-lg' ),
			$this->stored_names(),
			'Storing "--text-sm" would emit "----text-sm" once Bricks adds its own prefix'
		);
		$this->assertSame(
			'text-',
			$this->stored_prefix( $result['id'] ),
			'The category prefix must match the bare form the variable names use'
		);
	}

	/**
	 * A bare prefix is accepted rather than rejected as invalid_prefix.
	 *
	 * @return void
	 */
	public function test_create_with_a_bare_prefix_is_accepted(): void {
		$result = $this->service->create_typography_scale( 'Text', $this->steps(), 'text-' );

		$this->assertIsArray( $result, 'A bare prefix must no longer return invalid_prefix' );
		$this->assertSame( 'text-', $result['prefix'], 'A bare prefix must survive intact' );
		$this->assertSame(
			array( 'text-sm', 'text-lg' ),
			$this->stored_names(),
			'A bare prefix must produce the same stored names as the dashed spelling'
		);
	}

	/**
	 * Both spellings of the same prefix produce identical storage.
	 *
	 * @return void
	 */
	public function test_both_spellings_produce_the_same_stored_names(): void {
		$dashed = $this->service->create_typography_scale( 'Dashed', $this->steps(), '--text-' );
		$this->assertIsArray( $dashed, 'Fixture creation must succeed' );

		$GLOBALS['_bricks_mcp_test_options'] = array();

		$bare = $this->service->create_typography_scale( 'Bare', $this->steps(), 'text-' );
		$this->assertIsArray( $bare, 'Fixture creation must succeed' );

		$this->assertSame(
			$dashed['prefix'],
			$bare['prefix'],
			'"text-" and "--text-" must normalize to the same prefix'
		);
		$this->assertSame(
			array( 'text-sm', 'text-lg' ),
			$this->stored_names(),
			'"text-" and "--text-" must normalize to the same stored names'
		);
	}

	/**
	 * The default utility class name is derived from the bare prefix either way.
	 *
	 * @param string $prefix Prefix spelling under test.
	 * @return void
	 *
	 * @dataProvider provide_equivalent_prefixes
	 */
	public function test_default_utility_class_is_derived_from_the_bare_prefix( string $prefix ): void {
		$result = $this->service->create_typography_scale( 'Text', $this->steps(), $prefix );

		$this->assertIsArray( $result, 'Creating a valid scale must not return WP_Error' );
		$this->assertSame(
			'text-*',
			$result['utility_classes'][0]['className'] ?? '',
			'The generated utility class must never carry the leading dashes'
		);
	}

	/**
	 * A prefix rename through update stores bare names.
	 *
	 * @param string $new_prefix Replacement prefix spelling.
	 * @return void
	 *
	 * @dataProvider provide_equivalent_renames
	 */
	public function test_update_renames_to_bare_names( string $new_prefix ): void {
		$created = $this->service->create_typography_scale( 'Text', $this->steps(), 'text-' );
		$this->assertIsArray( $created, 'Fixture creation must succeed' );

		$updated = $this->service->update_typography_scale( $created['id'], null, null, $new_prefix );

		$this->assertIsArray( $updated, 'Renaming the prefix must not return WP_Error' );
		$this->assertSame( 'heading-', $updated['prefix'], 'The returned prefix must be bare' );
		$this->assertSame(
			array( 'heading-sm', 'heading-lg' ),
			$this->stored_names(),
			'A prefix rename must store the bare form, not the caller spelling'
		);
		$this->assertSame(
			'heading-',
			$this->stored_prefix( $created['id'] ),
			'The category prefix must be rewritten to the bare form too'
		);
	}

	/**
	 * A scale written before the fix is repaired, not doubled, by a prefix rename.
	 *
	 * @return void
	 */
	public function test_update_repairs_a_legacy_dashed_scale(): void {
		$this->seed_legacy_scale();

		$updated = $this->service->update_typography_scale( 'legacy', null, null, '--heading-' );

		$this->assertIsArray( $updated, 'Renaming a legacy scale must not return WP_Error' );
		$this->assertSame(
			array( 'heading-sm', 'heading-lg' ),
			$this->stored_names(),
			'Legacy "--text-sm" names must be matched and rewritten bare, not left behind'
		);
	}

	/**
	 * A step added to a legacy scale is stored bare rather than inheriting the doubled prefix.
	 *
	 * @return void
	 */
	public function test_update_adds_steps_to_a_legacy_scale_with_a_bare_prefix(): void {
		$this->seed_legacy_scale();

		$updated = $this->service->update_typography_scale(
			'legacy',
			null,
			array(
				array(
					'name'  => 'xl',
					'value' => '2rem',
				),
			)
		);

		$this->assertIsArray( $updated, 'Adding a step must not return WP_Error' );
		$this->assertContains(
			'text-xl',
			$this->stored_names(),
			'A new step must never be written with the doubling "--text-" prefix'
		);
	}

	/**
	 * An empty or dashes-only prefix is still rejected on create.
	 *
	 * @param string $prefix Prefix that must be rejected.
	 * @return void
	 *
	 * @dataProvider provide_empty_prefixes
	 */
	public function test_create_rejects_an_empty_prefix( string $prefix ): void {
		$result = $this->service->create_typography_scale( 'Text', $this->steps(), $prefix );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An empty prefix must be rejected' );
		$this->assertSame( 'invalid_prefix', $result->get_error_code(), 'Rejection must use the invalid_prefix code' );
		$this->assertSame( array(), $this->stored_names(), 'Nothing must be persisted for a rejected prefix' );
		$this->assertSame(
			array(),
			$GLOBALS['_bricks_mcp_test_options']['bricks_global_variables_categories'] ?? array(),
			'No category must be persisted for a rejected prefix'
		);
	}

	/**
	 * An empty or dashes-only prefix is still rejected on update, leaving the scale intact.
	 *
	 * @param string $prefix Prefix that must be rejected.
	 * @return void
	 *
	 * @dataProvider provide_empty_prefixes
	 */
	public function test_update_rejects_an_empty_prefix( string $prefix ): void {
		$created = $this->service->create_typography_scale( 'Text', $this->steps(), 'text-' );
		$this->assertIsArray( $created, 'Fixture creation must succeed' );

		$result = $this->service->update_typography_scale( $created['id'], null, null, $prefix );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An empty prefix must be rejected' );
		$this->assertSame( 'invalid_prefix', $result->get_error_code(), 'Rejection must use the invalid_prefix code' );
		$this->assertSame(
			array( 'text-sm', 'text-lg' ),
			$this->stored_names(),
			'The existing names must survive a rejected prefix change'
		);
		$this->assertSame(
			'text-',
			$this->stored_prefix( $created['id'] ),
			'The existing category prefix must survive a rejected prefix change'
		);
	}

	/**
	 * Updating one scale leaves another scale's variables untouched.
	 *
	 * @return void
	 */
	public function test_update_does_not_corrupt_other_scales(): void {
		$first = $this->service->create_typography_scale( 'Text', $this->steps(), 'text-' );
		$this->assertIsArray( $first, 'Fixture creation must succeed' );

		$second = $this->service->create_typography_scale( 'Heading', $this->steps(), '--heading-' );
		$this->assertIsArray( $second, 'Fixture creation must succeed' );

		$updated = $this->service->update_typography_scale( $first['id'], null, null, 'body-' );
		$this->assertIsArray( $updated, 'Renaming the first scale must not return WP_Error' );

		$this->assertSame(
			array( 'body-sm', 'body-lg', 'heading-sm', 'heading-lg' ),
			$this->stored_names(),
			'Only the renamed scale may change; the other scale keeps its bare names'
		);
		$this->assertSame(
			'heading-',
			$this->stored_prefix( $second['id'] ),
			'The untouched scale must keep its stored prefix'
		);
	}

	/**
	 * Seed a scale exactly as the pre-fix code would have written it.
	 *
	 * @return void
	 */
	private function seed_legacy_scale(): void {
		$GLOBALS['_bricks_mcp_test_options']['bricks_global_variables_categories'] = array(
			array(
				'id'             => 'legacy',
				'name'           => 'Text',
				'scale'          => array( 'prefix' => '--text-' ),
				'utilityClasses' => array(),
			),
		);

		$GLOBALS['_bricks_mcp_test_options']['bricks_global_variables'] = array(
			array(
				'id'       => 'v1',
				'name'     => '--text-sm',
				'value'    => '0.875rem',
				'category' => 'legacy',
			),
			array(
				'id'       => 'v2',
				'name'     => '--text-lg',
				'value'    => '1.25rem',
				'category' => 'legacy',
			),
		);
	}

	/**
	 * Prefix spellings that must be equivalent.
	 *
	 * @return array<string, array{0: string}> Data sets.
	 */
	public static function provide_equivalent_prefixes(): array {
		return array(
			'bare'   => array( 'text-' ),
			'dashed' => array( '--text-' ),
		);
	}

	/**
	 * Replacement prefix spellings that must be equivalent.
	 *
	 * @return array<string, array{0: string}> Data sets.
	 */
	public static function provide_equivalent_renames(): array {
		return array(
			'bare'   => array( 'heading-' ),
			'dashed' => array( '--heading-' ),
		);
	}

	/**
	 * Prefixes that normalize to the empty string.
	 *
	 * @return array<string, array{0: string}> Data sets.
	 */
	public static function provide_empty_prefixes(): array {
		return array(
			'empty string' => array( '' ),
			'two dashes'   => array( '--' ),
			'three dashes' => array( '---' ),
			'whitespace'   => array( '   ' ),
		);
	}
}
