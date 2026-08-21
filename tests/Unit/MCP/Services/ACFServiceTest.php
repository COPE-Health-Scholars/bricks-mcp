<?php
/**
 * ACF service unit tests.
 *
 * ACF values are meta PAIRS — the value plus a protected _<name> reference naming the field
 * definition — and a value without its reference renders as if never filled in. These tests
 * assert on both halves of what got stored, because the whole reason this service exists is that
 * every other write lane could only manage one half.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\ACFService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ACFService.
 */
final class ACFServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ACFService
	 */
	private ACFService $service;

	/**
	 * Post the fields hang off.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Seed a post and two field definitions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		bricks_mcp_test_reset_posts();
		bricks_mcp_test_reset_acf();

		$this->post_id = wp_insert_post(
			array(
				'post_type'  => 'site',
				'post_title' => 'Site Post',
			)
		);

		bricks_mcp_test_register_acf_field( 'field_abc123', 'overview', 'wysiwyg', 'Overview' );
		bricks_mcp_test_register_acf_field( 'field_def456', 'region', 'select', 'Region' );

		$this->service = new ACFService();
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		bricks_mcp_test_reset_acf();
		bricks_mcp_test_reset_posts();

		parent::tearDown();
	}

	/**
	 * Writing by field key must store both halves: the value and the _field reference.
	 *
	 * @return void
	 */
	public function test_write_by_key_stores_value_and_reference(): void {
		$result = $this->service->set_fields(
			$this->post_id,
			array( 'field_abc123' => 'Serving the western region.' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertTrue( $result['updated'][0]['reference_ok'] );

		$this->assertSame(
			'Serving the western region.',
			get_post_meta( $this->post_id, 'overview', true )
		);
		$this->assertSame(
			'field_abc123',
			get_post_meta( $this->post_id, '_overview', true ),
			'The protected reference meta is the half duplication existed to preserve'
		);
	}

	/**
	 * A field NAME resolves to its definition and writes by key — never by name.
	 *
	 * @return void
	 */
	public function test_write_by_name_resolves_to_the_key_first(): void {
		$result = $this->service->set_fields( $this->post_id, array( 'region' => 'west' ) );

		$this->assertSame( 'field_def456', $result['updated'][0]['key'] );
		$this->assertSame( 'west', get_post_meta( $this->post_id, 'region', true ) );
		$this->assertSame( 'field_def456', get_post_meta( $this->post_id, '_region', true ) );
	}

	/**
	 * An unresolvable name must fail hard without writing. update_field() would "succeed" with an
	 * empty reference — data ACF refuses to render — which is worse than an error.
	 *
	 * @return void
	 */
	public function test_unresolvable_selector_writes_nothing(): void {
		$result = $this->service->set_fields( $this->post_id, array( 'no_such_field' => 'x' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_acf_no_fields_resolved', $result->get_error_code() );
		$this->assertSame(
			'',
			get_post_meta( $this->post_id, 'no_such_field', true ),
			'A failed resolution must not leave a value meta behind'
		);
	}

	/**
	 * A mixed patch writes what resolves and reports what did not, field by field.
	 *
	 * @return void
	 */
	public function test_partial_resolution_writes_the_resolved_and_reports_the_rest(): void {
		$result = $this->service->set_fields(
			$this->post_id,
			array(
				'field_abc123' => 'Text.',
				'ghost_field'  => 'never lands',
			)
		);

		$this->assertSame( 1, $result['count'] );
		$this->assertCount( 1, $result['failed'] );
		$this->assertSame( 'ghost_field', $result['failed'][0]['selector'] );
		$this->assertSame( 'Text.', get_post_meta( $this->post_id, 'overview', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, 'ghost_field', true ) );
	}

	/**
	 * Reads return the keys a caller needs to hand straight back to set_fields.
	 *
	 * @return void
	 */
	public function test_get_fields_returns_keys_names_and_values(): void {
		$this->service->set_fields( $this->post_id, array( 'field_abc123' => 'Stored text.' ) );

		$result = $this->service->get_fields( $this->post_id );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'field_abc123', $result['fields'][0]['key'] );
		$this->assertSame( 'overview', $result['fields'][0]['name'] );
		$this->assertSame( 'wysiwyg', $result['fields'][0]['type'] );
		$this->assertSame( 'Stored text.', $result['fields'][0]['value'] );
	}

	/**
	 * A post with no field references reads as empty, not as an error.
	 *
	 * @return void
	 */
	public function test_post_without_acf_fields_reads_empty(): void {
		$result = $this->service->get_fields( $this->post_id );

		$this->assertSame( 0, $result['count'] );
		$this->assertSame( array(), $result['fields'] );
	}

	/**
	 * A missing post is a caller mistake, not a post to be conjured.
	 *
	 * @return void
	 */
	public function test_unknown_post_is_an_error(): void {
		$result = $this->service->set_fields( 99999, array( 'field_abc123' => 'x' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_acf_post_not_found', $result->get_error_code() );
	}

	/**
	 * An empty patch is rejected before any resolution runs.
	 *
	 * @return void
	 */
	public function test_empty_patch_is_rejected(): void {
		$result = $this->service->set_fields( $this->post_id, array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_acf_empty_patch', $result->get_error_code() );
	}
}
