<?php
/**
 * Unit test: rewriting element-ID references that live inside settings.
 *
 * An ID rewrite used to fix `id`/`parent`/`children` and leave any reference
 * held inside settings dangling, reported as hand-work. These tests pin which
 * shapes are now followed automatically and — just as importantly — which are
 * deliberately left alone, because a six-character ID can occur inside prose or
 * a URL and rewriting that would corrupt content to fix a reference that was
 * never there.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for settings-level ID reference remapping.
 */
final class BricksServiceSettingsIdRemapTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var BricksService
	 */
	private BricksService $service;

	/**
	 * Install Bricks doubles and reset the in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();

		$GLOBALS['_bricks_mcp_test_generate_calls'] = array();
		$GLOBALS['_bricks_mcp_test_get_data_calls'] = array();
		$GLOBALS['_bricks_mcp_test_elements']       = array();

		\Bricks\Assets::$post_id    = 0;
		\Bricks\Assets::$inline_css = array();

		$this->service = new BricksService();
	}

	/**
	 * One element carrying the given settings.
	 *
	 * @param array<string, mixed> $settings Settings for the element.
	 * @return array<int, array<string, mixed>> Flat element array.
	 */
	private function one_element( array $settings ): array {
		return array(
			array(
				'id'       => 'oldaaa',
				'name'     => 'block',
				'parent'   => 0,
				'children' => array(),
				'settings' => $settings,
			),
		);
	}

	/**
	 * The map used throughout: oldaaa => newaaa, oldbbb => newbbb.
	 *
	 * @return array<string, string> ID map.
	 */
	private function id_map(): array {
		return array(
			'oldaaa' => 'newaaa',
			'oldbbb' => 'newbbb',
		);
	}

	// -----------------------------------------------------------------------
	// Shape 1: #brxe- selectors
	// -----------------------------------------------------------------------

	/**
	 * A `#brxe-<id>` selector in _cssCustom must be rewritten.
	 *
	 * @return void
	 */
	public function test_brxe_selector_in_css_custom_is_rewritten(): void {
		$result = $this->service->remap_settings_id_references(
			$this->one_element( array( '_cssCustom' => '#brxe-oldbbb { color: red; }' ) ),
			$this->id_map()
		);

		$this->assertSame(
			'#brxe-newbbb { color: red; }',
			$result['elements'][0]['settings']['_cssCustom']
		);
		$this->assertSame( 1, $result['rewritten'] );
	}

	/**
	 * Several selectors in one rule block must all be rewritten and counted.
	 *
	 * @return void
	 */
	public function test_multiple_selectors_are_each_rewritten(): void {
		$result = $this->service->remap_settings_id_references(
			$this->one_element(
				array( '_cssCustom' => '#brxe-oldaaa { color: red; } #brxe-oldbbb > p { margin: 0; }' )
			),
			$this->id_map()
		);

		$this->assertSame(
			'#brxe-newaaa { color: red; } #brxe-newbbb > p { margin: 0; }',
			$result['elements'][0]['settings']['_cssCustom']
		);
		$this->assertSame( 2, $result['rewritten'] );
	}

	/**
	 * A selector naming an ID outside the map must be left alone.
	 *
	 * @return void
	 */
	public function test_selector_for_unmapped_id_is_untouched(): void {
		$result = $this->service->remap_settings_id_references(
			$this->one_element( array( '_cssCustom' => '#brxe-stranger { color: red; }' ) ),
			$this->id_map()
		);

		$this->assertSame(
			'#brxe-stranger { color: red; }',
			$result['elements'][0]['settings']['_cssCustom']
		);
		$this->assertSame( 0, $result['rewritten'] );
	}

	// -----------------------------------------------------------------------
	// Shape 2: the whole value is an ID
	// -----------------------------------------------------------------------

	/**
	 * A setting whose entire value is a remapped ID must be rewritten.
	 *
	 * This is how a third-party element names another element — a slider control
	 * pointing at its slider.
	 *
	 * @return void
	 */
	public function test_whole_value_id_reference_is_rewritten(): void {
		$result = $this->service->remap_settings_id_references(
			$this->one_element( array( 'targetSlider' => 'oldbbb' ) ),
			$this->id_map()
		);

		$this->assertSame( 'newbbb', $result['elements'][0]['settings']['targetSlider'] );
		$this->assertSame( 1, $result['rewritten'] );
	}

	/**
	 * References nested inside arrays must be followed.
	 *
	 * @return void
	 */
	public function test_nested_references_are_followed(): void {
		$result = $this->service->remap_settings_id_references(
			$this->one_element(
				array(
					'controls' => array(
						array( 'target' => 'oldaaa' ),
						array( 'target' => 'oldbbb' ),
					),
				)
			),
			$this->id_map()
		);

		$this->assertSame( 'newaaa', $result['elements'][0]['settings']['controls'][0]['target'] );
		$this->assertSame( 'newbbb', $result['elements'][0]['settings']['controls'][1]['target'] );
		$this->assertSame( 2, $result['rewritten'] );
	}

	// -----------------------------------------------------------------------
	// What must NOT be rewritten
	// -----------------------------------------------------------------------

	/**
	 * An ID occurring inside prose must not be rewritten.
	 *
	 * Copy is not a reference. Rewriting it would corrupt what the page says.
	 *
	 * @return void
	 */
	public function test_id_inside_prose_is_not_rewritten(): void {
		$text = 'Our oldaaa cohort begins in June.';

		$result = $this->service->remap_settings_id_references(
			$this->one_element( array( 'text' => $text ) ),
			$this->id_map()
		);

		$this->assertSame( $text, $result['elements'][0]['settings']['text'] );
		$this->assertSame( 0, $result['rewritten'] );
	}

	/**
	 * An ID inside a URL must not be rewritten.
	 *
	 * @return void
	 */
	public function test_id_inside_a_url_is_not_rewritten(): void {
		$url = 'https://example.com/programs/oldbbb/apply';

		$result = $this->service->remap_settings_id_references(
			$this->one_element( array( 'link' => array( 'url' => $url ) ) ),
			$this->id_map()
		);

		$this->assertSame( $url, $result['elements'][0]['settings']['link']['url'] );
	}

	/**
	 * Non-string settings values must survive untouched.
	 *
	 * @return void
	 */
	public function test_non_string_values_survive(): void {
		$result = $this->service->remap_settings_id_references(
			$this->one_element(
				array(
					'count'   => 3,
					'enabled' => true,
					'nothing' => null,
				)
			),
			$this->id_map()
		);

		$settings = $result['elements'][0]['settings'];

		$this->assertSame( 3, $settings['count'] );
		$this->assertTrue( $settings['enabled'] );
		$this->assertNull( $settings['nothing'] );
	}

	/**
	 * An empty map must be a no-op.
	 *
	 * @return void
	 */
	public function test_empty_map_is_a_no_op(): void {
		$elements = $this->one_element( array( '_cssCustom' => '#brxe-oldaaa { color: red; }' ) );

		$result = $this->service->remap_settings_id_references( $elements, array() );

		$this->assertSame( $elements, $result['elements'] );
		$this->assertSame( 0, $result['rewritten'] );
	}

	// -----------------------------------------------------------------------
	// Boundary-aware detection
	// -----------------------------------------------------------------------

	/**
	 * An ID butted up against other alphanumerics is not a reference.
	 *
	 * A plain substring search reported these, so the warning fired on content
	 * holding no reference at all — and a warning that cries wolf gets ignored.
	 *
	 * @return void
	 */
	public function test_id_inside_a_longer_word_is_not_reported(): void {
		$found = $this->service->find_settings_id_references(
			$this->one_element( array( 'text' => 'The prefixoldaaasuffix token.' ) ),
			array( 'oldaaa' )
		);

		$this->assertSame( array(), $found );
	}

	/**
	 * A standalone ID inside a longer string is still reported.
	 *
	 * @return void
	 */
	public function test_standalone_id_in_a_longer_string_is_reported(): void {
		$found = $this->service->find_settings_id_references(
			$this->one_element( array( 'text' => 'See oldaaa for details.' ) ),
			array( 'oldaaa' )
		);

		$this->assertSame( array( 'oldaaa' ), $found );
	}

	/**
	 * A `#brxe-` selector must still be detected — `-` is a boundary.
	 *
	 * Guards the exact regex trap here: excluding `-` from the boundary class
	 * would stop the most common reference shape from ever matching.
	 *
	 * @return void
	 */
	public function test_brxe_selector_is_still_detected(): void {
		$found = $this->service->find_settings_id_references(
			$this->one_element( array( '_cssCustom' => '#brxe-oldaaa { color: red; }' ) ),
			array( 'oldaaa' )
		);

		$this->assertSame( array( 'oldaaa' ), $found );
	}

	// -----------------------------------------------------------------------
	// End to end through apply_template
	// -----------------------------------------------------------------------

	/**
	 * A regenerated apply must land CSS pointing at the new IDs.
	 *
	 * The whole point: what is stored on the page must be self-consistent, with
	 * no manual follow-up.
	 *
	 * @return void
	 */
	public function test_apply_template_with_regenerated_ids_rewrites_stored_selectors(): void {
		$template_id = wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => 'Fixture template',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $template_id, '_bricks_template_type', 'section' );
		update_post_meta(
			$template_id,
			BricksService::META_KEY,
			array(
				array(
					'id'       => 'secaaa',
					'name'     => 'section',
					'parent'   => 0,
					'children' => array( 'kidbbb' ),
					'settings' => array( '_cssCustom' => '#brxe-kidbbb { color: red; }' ),
				),
				array(
					'id'       => 'kidbbb',
					'name'     => 'heading',
					'parent'   => 'secaaa',
					'children' => array(),
					'settings' => array( 'text' => 'Hello' ),
				),
			)
		);

		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Fixture page',
				'post_status' => 'publish',
			)
		);

		$result = $this->service->apply_template( $template_id, $page_id, 'replace', true );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ids_regenerated'] );

		$stored = $this->service->get_elements( $page_id );
		$new_id = $stored[1]['id'];

		$this->assertNotSame( 'kidbbb', $new_id, 'IDs were supposed to be regenerated' );
		$this->assertSame(
			'#brxe-' . $new_id . ' { color: red; }',
			$stored[0]['settings']['_cssCustom'],
			'the stored selector must name the regenerated ID'
		);
		$this->assertStringNotContainsString( 'kidbbb', $stored[0]['settings']['_cssCustom'] );
	}

	/**
	 * A followed reference must not also be reported as hand-work.
	 *
	 * @return void
	 */
	public function test_rewritten_reference_is_not_reported_as_needing_hand_work(): void {
		$template_id = wp_insert_post(
			array(
				'post_type'   => 'bricks_template',
				'post_title'  => 'Fixture template',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $template_id, '_bricks_template_type', 'section' );
		update_post_meta(
			$template_id,
			BricksService::META_KEY,
			array(
				array(
					'id'       => 'secaaa',
					'name'     => 'section',
					'parent'   => 0,
					'children' => array(),
					'settings' => array( '_cssCustom' => '#brxe-secaaa { color: red; }' ),
				),
			)
		);

		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Fixture page',
				'post_status' => 'publish',
			)
		);

		$result = $this->service->apply_template( $template_id, $page_id, 'replace', true );

		$warnings = implode( ' ', $result['warnings'] ?? array() );

		$this->assertStringNotContainsString( 'by hand', $warnings );
		$this->assertStringContainsString( 'rewritten', $warnings );
	}
}
