<?php
/**
 * Unit test: global class styles are stored under `settings`, not `styles`.
 *
 * Bricks generates global class CSS from `$global_class['settings']` only (see the
 * theme's includes/assets.php, which reads that key and has no `styles` fallback).
 * An earlier implementation of this plugin persisted the payload under `styles`, so
 * every class it created emitted zero CSS while otherwise behaving normally: the
 * class was created, appeared in the builder, and `apply` wrote its id into the
 * element so the name reached the rendered class attribute. Nothing caught it —
 * the read path echoed back whatever key had been written, so list/get looked
 * correct. The same defect was previously found and fixed one surface over, for
 * theme styles (Router's styles-to-settings mapping, upstream issue #35).
 *
 * These tests are behavioural: they assert on the shape that actually lands in the
 * option store, because that array is the only thing Bricks' CSS writer consumes.
 * Asserting on the service's return value would have passed against the bug.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the global class storage-key contract.
 */
final class BricksServiceGlobalClassSettingsTest extends TestCase {

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
	 * Read the classes actually persisted to the Bricks option.
	 *
	 * @return array<int, array<string, mixed>> Stored class entries.
	 */
	private function stored_classes(): array {
		return $GLOBALS['_bricks_mcp_test_options']['bricks_global_classes'] ?? array();
	}

	/**
	 * Find one stored class by name.
	 *
	 * @param string $name Class name.
	 * @return array<string, mixed> Stored entry, or an empty array when absent.
	 */
	private function stored_class( string $name ): array {
		foreach ( $this->stored_classes() as $class ) {
			if ( ( $class['name'] ?? '' ) === $name ) {
				return $class;
			}
		}

		return array();
	}

	/**
	 * A created class puts its payload under `settings`.
	 *
	 * @return void
	 */
	public function test_create_stores_payload_under_settings(): void {
		$this->service->create_global_class(
			array(
				'name'   => 'cf-card',
				'styles' => array( '_padding' => array( 'top' => '24px' ) ),
			)
		);

		$stored = $this->stored_class( 'cf-card' );

		$this->assertArrayHasKey( 'settings', $stored, 'Bricks reads settings; a class without it emits no CSS.' );
		$this->assertSame( array( '_padding' => array( 'top' => '24px' ) ), $stored['settings'] );
	}

	/**
	 * A created class does NOT leave a shadow copy under the inert key.
	 *
	 * @return void
	 */
	public function test_create_does_not_store_styles_key(): void {
		$this->service->create_global_class(
			array(
				'name'   => 'cf-card',
				'styles' => array( '_display' => 'grid' ),
			)
		);

		$this->assertArrayNotHasKey( 'styles', $this->stored_class( 'cf-card' ) );
	}

	/**
	 * Batch creation obeys the same contract.
	 *
	 * @return void
	 */
	public function test_batch_create_stores_payload_under_settings(): void {
		$this->service->batch_create_global_classes(
			array(
				array(
					'name'   => 'cf-a',
					'styles' => array( '_display' => 'flex' ),
				),
				array(
					'name'   => 'cf-b',
					'styles' => array( '_display' => 'grid' ),
				),
			)
		);

		foreach ( array( 'cf-a' => 'flex', 'cf-b' => 'grid' ) as $name => $display ) {
			$stored = $this->stored_class( $name );
			$this->assertArrayNotHasKey( 'styles', $stored, "{$name} kept the inert key" );
			$this->assertSame( array( '_display' => $display ), $stored['settings'] ?? array() );
		}
	}

	/**
	 * Updating merges into `settings`.
	 *
	 * @return void
	 */
	public function test_update_merges_into_settings(): void {
		$created = $this->service->create_global_class(
			array(
				'name'   => 'cf-card',
				'styles' => array( '_display' => 'grid' ),
			)
		);

		$this->service->update_global_class(
			$created['id'],
			array( 'styles' => array( '_gridGap' => '24px' ) )
		);

		$stored = $this->stored_class( 'cf-card' );

		$this->assertSame(
			array(
				'_display' => 'grid',
				'_gridGap' => '24px',
			),
			$stored['settings'] ?? array()
		);
		$this->assertArrayNotHasKey( 'styles', $stored );
	}

	/**
	 * replace_styles overwrites `settings` rather than merging.
	 *
	 * @return void
	 */
	public function test_update_with_replace_overwrites_settings(): void {
		$created = $this->service->create_global_class(
			array(
				'name'   => 'cf-card',
				'styles' => array( '_display' => 'grid' ),
			)
		);

		$this->service->update_global_class(
			$created['id'],
			array(
				'styles'         => array( '_gridGap' => '24px' ),
				'replace_styles' => true,
			)
		);

		$this->assertSame(
			array( '_gridGap' => '24px' ),
			$this->stored_class( 'cf-card' )['settings'] ?? array()
		);
	}

	/**
	 * A class written by an older build under `styles` is repaired when updated,
	 * with its existing payload adopted as the merge base rather than discarded.
	 *
	 * @return void
	 */
	public function test_update_repairs_a_legacy_styles_class(): void {
		$GLOBALS['_bricks_mcp_test_options']['bricks_global_classes'] = array(
			array(
				'id'     => 'legacy',
				'name'   => 'cf-legacy',
				'styles' => array( '_display' => 'grid' ),
			),
		);

		$this->service->update_global_class(
			'legacy',
			array( 'styles' => array( '_gridGap' => '24px' ) )
		);

		$stored = $this->stored_class( 'cf-legacy' );

		$this->assertArrayNotHasKey( 'styles', $stored, 'The inert key must not survive the repair.' );
		$this->assertSame(
			array(
				'_display' => 'grid',
				'_gridGap' => '24px',
			),
			$stored['settings'] ?? array(),
			'The legacy payload should be the merge base, not dropped.'
		);
	}

	/**
	 * A legacy class is left alone when an update does not touch its styles —
	 * renaming must not silently rewrite an unrelated key.
	 *
	 * @return void
	 */
	public function test_update_without_styles_leaves_legacy_entry_untouched(): void {
		$GLOBALS['_bricks_mcp_test_options']['bricks_global_classes'] = array(
			array(
				'id'     => 'legacy',
				'name'   => 'cf-legacy',
				'styles' => array( '_display' => 'grid' ),
			),
		);

		$this->service->update_global_class( 'legacy', array( 'name' => 'cf-renamed' ) );

		$stored = $this->stored_class( 'cf-renamed' );

		$this->assertSame( array( '_display' => 'grid' ), $stored['styles'] ?? array() );
	}

	/**
	 * A class created with no styles still carries an empty `settings`, which is
	 * the shape Bricks' own name-only utility classes have.
	 *
	 * @return void
	 */
	public function test_create_without_styles_stores_empty_settings(): void {
		$this->service->create_global_class( array( 'name' => 'cf-name-only' ) );

		$stored = $this->stored_class( 'cf-name-only' );

		$this->assertArrayHasKey( 'settings', $stored );
		$this->assertSame( array(), $stored['settings'] );
	}
}
