<?php
/**
 * Unit test: global variable names are stored BARE, without the "--" prefix.
 *
 * Bricks keeps `bricks_global_variables[*]['name']` without leading dashes and adds
 * "--" itself when it writes uploads/bricks/css/global-variables.min.css. An earlier
 * implementation of BricksService::normalize_variable_name() prepended "--" before
 * storing, so every variable this plugin created emitted as `----name: value;` and
 * never resolved — a defect that is invisible in the option array (the name looks
 * like a plausible CSS custom property) and only shows up in the generated CSS.
 *
 * These tests are behavioural: they read the name that actually landed in the
 * option store, which is the only thing Bricks' CSS writer consumes.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the bare-name storage contract.
 */
final class BricksServiceGlobalVariableNameTest extends TestCase {

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
	 * Read the names actually persisted to the Bricks option.
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
	 * A bare name is stored unchanged — no prefix is added.
	 *
	 * @return void
	 */
	public function test_create_stores_a_bare_name_unchanged(): void {
		$result = $this->service->create_global_variable( 'foo-bar', '#0a0e46' );

		$this->assertIsArray( $result, 'Creating a valid variable must not return WP_Error' );
		$this->assertSame( 'foo-bar', $result['name'], 'The returned name must be bare' );
		$this->assertSame(
			array( 'foo-bar' ),
			$this->stored_names(),
			'Bricks prepends -- when it writes the CSS, so the stored name must have none'
		);
	}

	/**
	 * A caller-supplied "--" prefix is stripped rather than kept or doubled.
	 *
	 * @return void
	 */
	public function test_create_strips_a_leading_double_dash(): void {
		$result = $this->service->create_global_variable( '--foo-bar', '#0a0e46' );

		$this->assertIsArray( $result, 'Creating a valid variable must not return WP_Error' );
		$this->assertSame( 'foo-bar', $result['name'], 'The returned name must be bare' );
		$this->assertSame(
			array( 'foo-bar' ),
			$this->stored_names(),
			'Storing "--foo-bar" would emit "----foo-bar" once Bricks adds its own prefix'
		);
	}

	/**
	 * Both spellings of the same variable normalize to one stored name.
	 *
	 * @return void
	 */
	public function test_both_spellings_produce_the_same_stored_name(): void {
		$this->service->create_global_variable( 'spacing-md', '1rem' );
		$this->service->create_global_variable( '--spacing-md', '1rem' );

		$this->assertSame(
			array( 'spacing-md', 'spacing-md' ),
			$this->stored_names(),
			'"spacing-md" and "--spacing-md" must normalize to the same bare name'
		);
	}

	/**
	 * Batch creation follows the same contract as single creation.
	 *
	 * @return void
	 */
	public function test_batch_create_stores_bare_names(): void {
		$result = $this->service->batch_create_global_variables(
			array(
				array(
					'name'  => '--cf-navy-deep',
					'value' => '#0a0e46',
				),
				array(
					'name'  => 'cf-sky',
					'value' => '#8fd3ff',
				),
			)
		);

		$this->assertSame( 2, $result['created_count'], 'Both definitions are valid' );
		$this->assertSame(
			array( 'cf-navy-deep', 'cf-sky' ),
			$this->stored_names(),
			'Batch creation must strip the -- prefix exactly as create_global_variable does'
		);
	}

	/**
	 * A rename through update_global_variable is normalized the same way.
	 *
	 * @return void
	 */
	public function test_update_renames_to_a_bare_name(): void {
		$created = $this->service->create_global_variable( 'old-name', '1rem' );
		$this->assertIsArray( $created, 'Fixture creation must succeed' );

		$updated = $this->service->update_global_variable(
			$created['id'],
			array( 'name' => '--new-name' )
		);

		$this->assertIsArray( $updated, 'Renaming an existing variable must not return WP_Error' );
		$this->assertSame( 'new-name', $updated['name'], 'The returned name must be bare' );
		$this->assertSame(
			array( 'new-name' ),
			$this->stored_names(),
			'A rename must store the bare form, not the caller spelling'
		);
	}

	/**
	 * A rename to an already-bare name is stored unchanged.
	 *
	 * @return void
	 */
	public function test_update_keeps_an_already_bare_name(): void {
		$created = $this->service->create_global_variable( 'old-name', '1rem' );
		$this->assertIsArray( $created, 'Fixture creation must succeed' );

		$updated = $this->service->update_global_variable(
			$created['id'],
			array( 'name' => 'new-name' )
		);

		$this->assertIsArray( $updated, 'Renaming an existing variable must not return WP_Error' );
		$this->assertSame( array( 'new-name' ), $this->stored_names(), 'The bare name must survive intact' );
	}

	/**
	 * A dashes-only name normalizes to empty and is still rejected.
	 *
	 * The empty-name guard used to compare against the literal '--', which the
	 * bare-storage fix makes unreachable; this pins the replacement guard.
	 *
	 * @param string $name Name that must be rejected.
	 * @return void
	 *
	 * @dataProvider provide_empty_names
	 */
	public function test_create_rejects_an_empty_name( string $name ): void {
		$result = $this->service->create_global_variable( $name, '1rem' );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An empty name must be rejected' );
		$this->assertSame( 'missing_name', $result->get_error_code(), 'Rejection must use the missing_name code' );
		$this->assertSame( array(), $this->stored_names(), 'Nothing must be persisted for a rejected name' );
	}

	/**
	 * A rename to an empty name is rejected and leaves the variable untouched.
	 *
	 * @param string $name Name that must be rejected.
	 * @return void
	 *
	 * @dataProvider provide_empty_names
	 */
	public function test_update_rejects_an_empty_name( string $name ): void {
		$created = $this->service->create_global_variable( 'keep-me', '1rem' );
		$this->assertIsArray( $created, 'Fixture creation must succeed' );

		$result = $this->service->update_global_variable( $created['id'], array( 'name' => $name ) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'An empty name must be rejected' );
		$this->assertSame( 'missing_name', $result->get_error_code(), 'Rejection must use the missing_name code' );
		$this->assertSame( array( 'keep-me' ), $this->stored_names(), 'The existing name must survive a rejected rename' );
	}

	/**
	 * Batch creation rejects an empty name per item without aborting the batch.
	 *
	 * @return void
	 */
	public function test_batch_create_rejects_an_empty_name_per_item(): void {
		$result = $this->service->batch_create_global_variables(
			array(
				array(
					'name'  => '--',
					'value' => '1rem',
				),
				array(
					'name'  => 'good-one',
					'value' => '2rem',
				),
			)
		);

		$this->assertSame( 1, $result['created_count'], 'Only the valid definition may be created' );
		$this->assertArrayHasKey( 0, $result['errors'], 'The dashes-only name must be reported as an error' );
		$this->assertSame( array( 'good-one' ), $this->stored_names(), 'Partial success must persist only the valid item' );
	}

	/**
	 * Names that normalize to the empty string.
	 *
	 * @return array<string, array{0: string}> Data sets.
	 */
	public static function provide_empty_names(): array {
		return array(
			'empty string' => array( '' ),
			'two dashes'   => array( '--' ),
			'three dashes' => array( '---' ),
			'whitespace'   => array( '   ' ),
		);
	}
}
