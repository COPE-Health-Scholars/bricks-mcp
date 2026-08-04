<?php
/**
 * Unit test: BricksService::get_page_structure() — the compact diffing view.
 *
 * Behavioural throughout: every assertion reads the rows actually produced from
 * stored meta. The canonicalization tests pin the exact JSON string that gets
 * hashed, because the whole feature is worthless if a caller cannot reproduce
 * these hashes locally against its own generated content.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the compact structural read.
 */
final class BricksServiceStructureTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var BricksService
	 */
	private BricksService $service;

	/**
	 * Install Bricks doubles and reset the in-memory post store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();

		$this->service = new BricksService();
	}

	/**
	 * Store elements on a new page and return its ID.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements to store.
	 * @return int Page post ID.
	 */
	private function make_page( array $elements ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Fixture page',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $id, BricksService::META_KEY, $elements );

		return $id;
	}

	/**
	 * Build one element.
	 *
	 * @param string               $id       Element ID.
	 * @param string               $name     Element name.
	 * @param string|int           $parent   Parent ID, or 0 for root.
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed> The element.
	 */
	private function element( string $id, string $name, string|int $parent, array $settings = array() ): array {
		return array(
			'id'       => $id,
			'name'     => $name,
			'parent'   => $parent,
			'children' => array(),
			'settings' => $settings,
		);
	}

	/**
	 * Split a row back into its four fields.
	 *
	 * @param string $row One row string.
	 * @return array<int, string> The fields.
	 */
	private function fields( string $row ): array {
		return explode( '|', $row );
	}

	/**
	 * Build a nested fixture shaped like a real generated page.
	 *
	 * Nesting matters to the size comparison: `summary` emits a node object per
	 * element inside nested `children` arrays, so pretty-print indentation grows
	 * with depth, while a flat row list does not. A shallow fixture would
	 * understate the difference — the real 950-element page runs four levels deep
	 * in places.
	 *
	 * @param int $sections How many seven-element sections to emit.
	 * @return array<int, array<string, mixed>> Flat element array, depths 0-3.
	 */
	private function nested_fixture( int $sections ): array {
		$elements = array();

		for ( $s = 0; $s < $sections; $s++ ) {
			$section = sprintf( 'sec%03d', $s );
			$block   = sprintf( 'blk%03d', $s );
			$inner   = sprintf( 'inn%03d', $s );
			$heading = sprintf( 'hdg%03d', $s );
			$text    = sprintf( 'txt%03d', $s );
			$deep_a  = sprintf( 'dpa%03d', $s );
			$deep_b  = sprintf( 'dpb%03d', $s );

			$elements[] = array(
				'id'       => $section,
				'name'     => 'section',
				'parent'   => 0,
				'children' => array( $block ),
				'settings' => array( '_padding' => array( 'top' => '108px', 'bottom' => '108px' ) ),
			);
			$elements[] = array(
				'id'       => $block,
				'name'     => 'block',
				'parent'   => $section,
				'children' => array( $heading, $text, $inner ),
				'settings' => array( '_direction' => 'row' ),
			);
			$elements[] = array(
				'id'       => $heading,
				'name'     => 'heading',
				'parent'   => $block,
				'children' => array(),
				'settings' => array(
					'text'        => 'Section heading ' . $s,
					'_typography' => array( 'font-size' => '59px', 'font-weight' => '800' ),
				),
			);
			$elements[] = array(
				'id'       => $text,
				'name'     => 'text-basic',
				'parent'   => $block,
				'children' => array(),
				'settings' => array( 'text' => 'Representative body copy for section ' . $s ),
			);
			$elements[] = array(
				'id'       => $inner,
				'name'     => 'block',
				'parent'   => $block,
				'children' => array( $deep_a, $deep_b ),
				'settings' => array( '_direction' => 'row' ),
			);
			$elements[] = array(
				'id'       => $deep_a,
				'name'     => 'text-basic',
				'parent'   => $inner,
				'children' => array(),
				'settings' => array( 'text' => 'Inner copy A ' . $s ),
			);
			$elements[] = array(
				'id'       => $deep_b,
				'name'     => 'text-basic',
				'parent'   => $inner,
				'children' => array(),
				'settings' => array( 'text' => 'Inner copy B ' . $s ),
			);
		}

		return $elements;
	}

	// -----------------------------------------------------------------------
	// Shape and alignment
	// -----------------------------------------------------------------------

	/**
	 * Rows must be index-aligned with stored element order.
	 *
	 * This is the whole premise of the diff workflow: index n on the live page
	 * corresponds to index n in the generated tree.
	 *
	 * @return void
	 */
	public function test_rows_are_index_aligned_with_stored_order(): void {
		$post_id = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'heading', 'aaa111', array( 'text' => 'One' ) ),
				$this->element( 'ccc333', 'text-basic', 'aaa111', array( 'text' => 'Two' ) ),
			)
		);

		$structure = $this->service->get_page_structure( $post_id );

		$this->assertSame( 3, $structure['total'] );
		$this->assertSame( 'id|name|parent|settings_hash', $structure['row_format'] );
		$this->assertCount( 3, $structure['rows'] );

		$this->assertSame( array( 'aaa111', 'section', '0' ), array_slice( $this->fields( $structure['rows'][0] ), 0, 3 ) );
		$this->assertSame( array( 'bbb222', 'heading', 'aaa111' ), array_slice( $this->fields( $structure['rows'][1] ), 0, 3 ) );
		$this->assertSame( array( 'ccc333', 'text-basic', 'aaa111' ), array_slice( $this->fields( $structure['rows'][2] ), 0, 3 ) );
	}

	/**
	 * Every row must carry a 12-character hex fingerprint.
	 *
	 * @return void
	 */
	public function test_each_row_carries_a_twelve_char_hex_fingerprint(): void {
		$post_id = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'heading', 'aaa111', array( 'text' => 'One' ) ),
			)
		);

		$structure = $this->service->get_page_structure( $post_id );

		foreach ( $structure['rows'] as $row ) {
			$hash = $this->fields( $row )[3];
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $hash );
		}
	}

	/**
	 * Type counts must tally the element names.
	 *
	 * @return void
	 */
	public function test_type_counts_tally_element_names(): void {
		$post_id = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'text-basic', 'aaa111' ),
				$this->element( 'ccc333', 'text-basic', 'aaa111' ),
			)
		);

		$structure = $this->service->get_page_structure( $post_id );

		$this->assertSame(
			array(
				'section'    => 1,
				'text-basic' => 2,
			),
			$structure['type_counts']
		);
	}

	/**
	 * An empty page must not produce hashes of nothing.
	 *
	 * A hash of the empty string would compare equal between two unrelated
	 * empty pages and read as "these correspond", which is meaningless.
	 *
	 * @return void
	 */
	public function test_empty_page_returns_empty_rows_and_no_hashes(): void {
		$post_id = $this->make_page( array() );

		$structure = $this->service->get_page_structure( $post_id );

		$this->assertSame( 0, $structure['total'] );
		$this->assertSame( array(), $structure['rows'] );
		$this->assertSame( '', $structure['name_sequence_hash'] );
		$this->assertSame( '', $structure['rows_hash'] );
	}

	/**
	 * An element with no id or name must not break the row shape.
	 *
	 * @return void
	 */
	public function test_missing_id_and_name_degrade_without_breaking_the_row(): void {
		$post_id = $this->make_page(
			array(
				array(
					'parent'   => 0,
					'settings' => array(),
				),
			)
		);

		$structure = $this->service->get_page_structure( $post_id );

		$fields = $this->fields( $structure['rows'][0] );

		$this->assertCount( 4, $fields );
		$this->assertSame( '', $fields[0] );
		$this->assertSame( 'unknown', $fields[1] );
	}

	// -----------------------------------------------------------------------
	// Canonicalization — the contract a local script has to reproduce
	// -----------------------------------------------------------------------

	/**
	 * The fingerprint must be sha1 of key-sorted compact JSON, first 12 hex.
	 *
	 * Pins the exact canonical string. If canonicalization changes, this fails
	 * loudly rather than silently invalidating every caller's local diff.
	 *
	 * @return void
	 */
	public function test_fingerprint_is_sha1_of_key_sorted_compact_json(): void {
		$post_id = $this->make_page(
			array(
				$this->element(
					'aaa111',
					'heading',
					0,
					array(
						'text' => 'Hi',
						'tag'  => 'h2',
					)
				),
			)
		);

		$structure = $this->service->get_page_structure( $post_id );

		$expected = substr( sha1( '{"tag":"h2","text":"Hi"}' ), 0, 12 );

		$this->assertSame( $expected, $this->fields( $structure['rows'][0] )[3] );
	}

	/**
	 * Map key order must not change the fingerprint.
	 *
	 * PHP's storage order is not meaningful and differs between a generator's
	 * output and what Bricks stores, so it must not register as a difference.
	 *
	 * @return void
	 */
	public function test_map_key_order_does_not_change_the_fingerprint(): void {
		$a = $this->make_page(
			array(
				$this->element(
					'aaa111',
					'heading',
					0,
					array(
						'text' => 'Hi',
						'tag'  => 'h2',
					)
				),
			)
		);
		$b = $this->make_page(
			array(
				$this->element(
					'aaa111',
					'heading',
					0,
					array(
						'tag'  => 'h2',
						'text' => 'Hi',
					)
				),
			)
		);

		$this->assertSame(
			$this->service->get_page_structure( $a )['rows'],
			$this->service->get_page_structure( $b )['rows']
		);
	}

	/**
	 * Nested map key order must not change the fingerprint either.
	 *
	 * @return void
	 */
	public function test_nested_map_key_order_does_not_change_the_fingerprint(): void {
		$a = $this->make_page(
			array(
				$this->element(
					'aaa111',
					'heading',
					0,
					array(
						'_typography' => array(
							'font-size'   => '59px',
							'font-weight' => '800',
						),
					)
				),
			)
		);
		$b = $this->make_page(
			array(
				$this->element(
					'aaa111',
					'heading',
					0,
					array(
						'_typography' => array(
							'font-weight' => '800',
							'font-size'   => '59px',
						),
					)
				),
			)
		);

		$this->assertSame(
			$this->service->get_page_structure( $a )['rows'],
			$this->service->get_page_structure( $b )['rows']
		);
	}

	/**
	 * List order MUST change the fingerprint.
	 *
	 * Order is meaningful in Bricks list-valued settings — `_attributes`
	 * repeaters, query filters — so reordering one is a real change.
	 *
	 * @return void
	 */
	public function test_list_order_does_change_the_fingerprint(): void {
		$a = $this->make_page(
			array(
				$this->element( 'aaa111', 'block', 0, array( '_attributes' => array( 'one', 'two' ) ) ),
			)
		);
		$b = $this->make_page(
			array(
				$this->element( 'aaa111', 'block', 0, array( '_attributes' => array( 'two', 'one' ) ) ),
			)
		);

		$this->assertNotSame(
			$this->service->get_page_structure( $a )['rows'],
			$this->service->get_page_structure( $b )['rows']
		);
	}

	/**
	 * A changed settings value must change the fingerprint.
	 *
	 * @return void
	 */
	public function test_changed_value_changes_the_fingerprint(): void {
		$a = $this->make_page(
			array( $this->element( 'aaa111', 'heading', 0, array( 'text' => 'One' ) ) )
		);
		$b = $this->make_page(
			array( $this->element( 'aaa111', 'heading', 0, array( 'text' => 'Two' ) ) )
		);

		$this->assertNotSame(
			$this->fields( $this->service->get_page_structure( $a )['rows'][0] )[3],
			$this->fields( $this->service->get_page_structure( $b )['rows'][0] )[3]
		);
	}

	/**
	 * Empty settings must hash as `{}`, not as `[]`.
	 *
	 * PHP cannot tell an empty map from an empty list, so both normalize to
	 * `{}`. Without this, every element with no settings would mismatch a
	 * generator that emitted `"settings": {}`.
	 *
	 * @return void
	 */
	public function test_empty_settings_hash_as_an_empty_object(): void {
		$post_id = $this->make_page(
			array( $this->element( 'aaa111', 'section', 0, array() ) )
		);

		$structure = $this->service->get_page_structure( $post_id );

		$this->assertSame(
			substr( sha1( '{}' ), 0, 12 ),
			$this->fields( $structure['rows'][0] )[3]
		);
	}

	/**
	 * A missing settings key must hash the same as an empty one.
	 *
	 * @return void
	 */
	public function test_absent_settings_hash_the_same_as_empty_settings(): void {
		$with = $this->make_page(
			array( $this->element( 'aaa111', 'section', 0, array() ) )
		);
		$without = $this->make_page(
			array(
				array(
					'id'     => 'aaa111',
					'name'   => 'section',
					'parent' => 0,
				),
			)
		);

		$this->assertSame(
			$this->service->get_page_structure( $with )['rows'],
			$this->service->get_page_structure( $without )['rows']
		);
	}

	/**
	 * Slashes and unicode must not be escaped in the hashed form.
	 *
	 * @return void
	 */
	public function test_slashes_and_unicode_are_not_escaped_before_hashing(): void {
		$post_id = $this->make_page(
			array(
				$this->element( 'aaa111', 'text-basic', 0, array( 'text' => 'a/b – é' ) ),
			)
		);

		$structure = $this->service->get_page_structure( $post_id );

		$this->assertSame(
			substr( sha1( '{"text":"a/b – é"}' ), 0, 12 ),
			$this->fields( $structure['rows'][0] )[3]
		);
	}

	// -----------------------------------------------------------------------
	// Correspondence hashes
	// -----------------------------------------------------------------------

	/**
	 * The name-sequence hash must match for trees with the same element order.
	 *
	 * This is step one of the deploy workflow — "do these two trees correspond"
	 * — answered by one comparison instead of by shipping 950 names.
	 *
	 * @return void
	 */
	public function test_name_sequence_hash_ignores_ids_and_settings(): void {
		$a = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'heading', 'aaa111', array( 'text' => 'One' ) ),
			)
		);
		$b = $this->make_page(
			array(
				$this->element( 'zzz999', 'section', 0 ),
				$this->element( 'yyy888', 'heading', 'zzz999', array( 'text' => 'Different' ) ),
			)
		);

		$this->assertSame(
			$this->service->get_page_structure( $a )['name_sequence_hash'],
			$this->service->get_page_structure( $b )['name_sequence_hash']
		);
	}

	/**
	 * A changed element name must change the name-sequence hash.
	 *
	 * @return void
	 */
	public function test_name_sequence_hash_changes_when_the_tree_diverges(): void {
		$a = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'heading', 'aaa111' ),
			)
		);
		$b = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'text-basic', 'aaa111' ),
			)
		);

		$this->assertNotSame(
			$this->service->get_page_structure( $a )['name_sequence_hash'],
			$this->service->get_page_structure( $b )['name_sequence_hash']
		);
	}

	/**
	 * rows_hash must change when any single settings value changes.
	 *
	 * @return void
	 */
	public function test_rows_hash_changes_when_one_setting_changes(): void {
		$a = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'heading', 'aaa111', array( 'text' => 'One' ) ),
			)
		);
		$b = $this->make_page(
			array(
				$this->element( 'aaa111', 'section', 0 ),
				$this->element( 'bbb222', 'heading', 'aaa111', array( 'text' => 'Changed' ) ),
			)
		);

		$this->assertNotSame(
			$this->service->get_page_structure( $a )['rows_hash'],
			$this->service->get_page_structure( $b )['rows_hash']
		);
	}

	// -----------------------------------------------------------------------
	// The point of the feature: it has to actually be small
	// -----------------------------------------------------------------------

	/**
	 * The structure view must be far smaller than the summary view.
	 *
	 * Encoded pretty-printed, because that is the form whose size drove
	 * `summary` to 344 KB for 950 elements and made it spill to a file. A
	 * regression that reintroduces per-element objects would pass every
	 * correctness test above and fail here.
	 *
	 * @return void
	 */
	public function test_structure_is_far_smaller_than_summary_for_a_large_tree(): void {
		$post_id = $this->make_page( $this->nested_fixture( 40 ) );

		$structure_bytes = strlen( (string) wp_json_encode( $this->service->get_page_structure( $post_id ), JSON_PRETTY_PRINT ) );
		$summary_bytes   = strlen( (string) wp_json_encode( $this->service->get_page_summary( $post_id ), JSON_PRETTY_PRINT ) );

		$this->assertLessThan(
			$summary_bytes / 3,
			$structure_bytes,
			sprintf(
				'structure (%d bytes) should be well under a third of summary (%d bytes)',
				$structure_bytes,
				$summary_bytes
			)
		);
	}

	/**
	 * The response must tell the caller how to reproduce the hashes.
	 *
	 * Without the recipe the hashes are opaque and the caller still has to read
	 * full settings to find what changed — which is the gap this closes.
	 *
	 * @return void
	 */
	public function test_response_carries_the_hash_recipe(): void {
		$post_id = $this->make_page(
			array( $this->element( 'aaa111', 'section', 0 ) )
		);

		$recipe = $this->service->get_page_structure( $post_id )['hash_recipe'];

		$this->assertStringContainsString( 'sha1', $recipe );
		$this->assertStringContainsString( 'sort_keys', $recipe );
		$this->assertStringContainsString( '12', $recipe );
	}
}
