<?php
/**
 * Flat-format detection tests.
 *
 * Pins the fix for the template-1588 import mangling: is_flat_format() required id, parent AND
 * children on every element, but old hand-authored Bricks rows omit children on leaves — Bricks
 * itself treats a missing children key as "no children". One such row among hundreds flipped the
 * verdict for the whole array, so a faithful flat export was fed through simplified_to_flat(),
 * which rerooted every element, discarded the children arrays, reminted every id and re-sanitized
 * all settings. 241 elements — invisible detached junk included — rendered stacked top-to-bottom.
 *
 * Fourteen weeks of imports never hit this because every prior template was generator-built, and
 * the generator writes children on every element. The first hand-built, old-era template through
 * the code path was the first input that could fail the check.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\ElementIdGenerator;
use BricksMCP\MCP\Services\ElementNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ElementNormalizer flat-format detection.
 */
final class ElementNormalizerFlatFormatTest extends TestCase {

	/**
	 * Normalizer under test.
	 *
	 * @var ElementNormalizer
	 */
	private ElementNormalizer $normalizer;

	/**
	 * Build the normalizer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->normalizer = new ElementNormalizer( new ElementIdGenerator() );
	}

	/**
	 * The 1588 shape in miniature: a flat array where some rows never stored children.
	 *
	 * @return array<int, array<string, mixed>> Flat elements.
	 */
	private function old_flat_content(): array {
		return array(
			array(
				'id'       => 'abc123',
				'name'     => 'section',
				'parent'   => 0,
				'children' => array( 'def456' ),
				'settings' => array( '_padding' => array( 'top' => '40' ) ),
			),
			array(
				// A hand-authored leaf: id and parent, no children key ever stored.
				'id'       => 'def456',
				'name'     => 'heading',
				'parent'   => 'abc123',
				'settings' => array( 'text' => 'Hello' ),
			),
			array(
				// Detached junk row: parent points at an element that no longer exists.
				'id'       => 'zzz999',
				'name'     => 'text-basic',
				'parent'   => 'gone00',
				'settings' => array( 'text' => 'orphan' ),
			),
		);
	}

	/**
	 * One missing children key must not flip the whole array out of flat format.
	 *
	 * @return void
	 */
	public function test_missing_children_key_does_not_break_flat_detection(): void {
		$this->assertTrue( $this->normalizer->is_flat_format( $this->old_flat_content() ) );
	}

	/**
	 * The exact damage: ids reminted, every element rerooted. Neither may happen to flat input.
	 *
	 * @return void
	 */
	public function test_old_flat_content_passes_through_with_ids_and_parents_intact(): void {
		$result = $this->normalizer->normalize( $this->old_flat_content() );

		$this->assertSame(
			array( 'abc123', 'def456', 'zzz999' ),
			array_column( $result, 'id' ),
			'Flat input must keep its ids — reminting orphans every stored reference to them'
		);
		$this->assertSame(
			array( 0, 'abc123', 'gone00' ),
			array_column( $result, 'parent' ),
			'Flat input must keep its parents — rerooting renders the whole tree stacked flat'
		);
		$this->assertSame(
			array( 'def456' ),
			$result[0]['children'],
			'An existing children array must survive untouched'
		);
	}

	/**
	 * The missing key is filled with an empty array — same meaning to Bricks, but this plugin's
	 * tree-walking code and structure validator index the key directly.
	 *
	 * @return void
	 */
	public function test_missing_children_key_is_defaulted_to_an_empty_array(): void {
		$result = $this->normalizer->normalize( $this->old_flat_content() );

		$this->assertSame( array(), $result[1]['children'] );
		$this->assertSame( array(), $result[2]['children'] );
	}

	/**
	 * Settings must not be re-sanitized on passthrough — that was part of the mangling too.
	 *
	 * @return void
	 */
	public function test_flat_settings_pass_through_untouched(): void {
		$input  = $this->old_flat_content();
		$result = $this->normalizer->normalize( $input );

		$this->assertSame( $input[0]['settings'], $result[0]['settings'] );
		$this->assertSame( $input[1]['settings'], $result[1]['settings'] );
	}

	/**
	 * The simplified nested format must still be detected: its nodes carry neither id nor parent,
	 * which is what keeps the two formats unambiguous with children now optional.
	 *
	 * @return void
	 */
	public function test_simplified_nested_format_is_still_converted(): void {
		$nested = array(
			array(
				'name'     => 'section',
				'settings' => array(),
				'children' => array(
					array(
						'name'     => 'heading',
						'settings' => array( 'text' => 'Hello' ),
					),
				),
			),
		);

		$this->assertFalse( $this->normalizer->is_flat_format( $nested ) );

		$result = $this->normalizer->normalize( $nested );

		$this->assertCount( 2, $result );
		$this->assertSame( 0, $result[0]['parent'] );
		$this->assertSame( $result[0]['id'], $result[1]['parent'] );
	}

	/**
	 * Generator-built content — children on every element — keeps working exactly as before.
	 *
	 * @return void
	 */
	public function test_generator_built_flat_content_still_passes_through(): void {
		$input = array(
			array(
				'id'       => 'aaa111',
				'name'     => 'section',
				'parent'   => 0,
				'children' => array(),
				'settings' => array(),
			),
		);

		$this->assertTrue( $this->normalizer->is_flat_format( $input ) );
		$this->assertSame( $input, $this->normalizer->normalize( $input ) );
	}
}
