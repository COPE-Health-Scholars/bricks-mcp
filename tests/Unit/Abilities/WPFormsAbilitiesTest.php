<?php
/**
 * WPForms abilities registration tests.
 *
 * The point of the abilities layer is that an existing Abilities API client picks these up with
 * no new credential, so what matters is that they register at all, that they carry the schema a
 * client needs to call them, and that their callbacks reach the same service the MCP tool uses.
 *
 * @package BricksMCP\Tests\Unit\Abilities
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Abilities;

use BricksMCP\Abilities\WPFormsAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WPFormsAbilities.
 */
final class WPFormsAbilitiesTest extends TestCase {

	/**
	 * Reset state and seed a form.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		bricks_mcp_test_reset_wpforms();
		bricks_mcp_test_reset_abilities();
		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		bricks_mcp_test_seed_wpforms_form(
			1631,
			array(
				'id'       => '1631',
				'fields'   => array(
					'3' => array(
						'id'    => '3',
						'type'  => 'select',
						'label' => 'Topic',
					),
				),
				'settings' => array(
					'form_title' => 'Contact Us',
					'honeypot'   => false,
				),
			)
		);
	}

	/**
	 * Clean up globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		bricks_mcp_test_reset_wpforms();
		bricks_mcp_test_reset_abilities();

		parent::tearDown();
	}

	/**
	 * Register and return the captured abilities.
	 *
	 * @return array<string, array<string, mixed>> Registrations keyed by ability name.
	 */
	private function register(): array {
		( new WPFormsAbilities() )->register();

		return $GLOBALS['_bricks_mcp_test_abilities'];
	}

	/**
	 * The four PR-5 gaps each need an ability, or the whole layer is decorative.
	 *
	 * @return void
	 */
	public function test_every_write_gap_gets_an_ability(): void {
		$abilities = $this->register();

		foreach (
			array(
				'bricks-mcp/wpforms-get-form',
				'bricks-mcp/wpforms-update-form-settings',
				'bricks-mcp/wpforms-update-field',
				'bricks-mcp/wpforms-delete-entries',
			) as $name
		) {
			$this->assertArrayHasKey( $name, $abilities );
		}
	}

	/**
	 * A client cannot construct a call without the required inputs being declared.
	 *
	 * @return void
	 */
	public function test_abilities_declare_their_required_inputs(): void {
		$abilities = $this->register();

		$this->assertSame(
			array( 'form_id', 'settings' ),
			$abilities['bricks-mcp/wpforms-update-form-settings']['input_schema']['required']
		);
		$this->assertSame(
			array( 'form_id', 'field_id', 'properties' ),
			$abilities['bricks-mcp/wpforms-update-field']['input_schema']['required']
		);
		$this->assertSame(
			array( 'entry_ids' ),
			$abilities['bricks-mcp/wpforms-delete-entries']['input_schema']['required']
		);
	}

	/**
	 * Deleting entries is the one irreversible operation here and must be annotated as such.
	 *
	 * @return void
	 */
	public function test_only_entry_deletion_is_annotated_destructive(): void {
		$abilities = $this->register();

		$this->assertTrue(
			$abilities['bricks-mcp/wpforms-delete-entries']['meta']['annotations']['destructive']
		);
		$this->assertFalse(
			$abilities['bricks-mcp/wpforms-update-form-settings']['meta']['annotations']['destructive']
		);
		$this->assertTrue(
			$abilities['bricks-mcp/wpforms-get-form']['meta']['annotations']['readOnly']
		);
	}

	/**
	 * The execute callback has to actually write, not just validate.
	 *
	 * @return void
	 */
	public function test_settings_execute_callback_writes_through_wpforms(): void {
		$abilities = $this->register();

		$result = ( $abilities['bricks-mcp/wpforms-update-form-settings']['execute_callback'] )(
			array(
				'form_id'  => 1631,
				'settings' => array( 'honeypot' => '1' ),
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( '1', bricks_mcp_test_stored_wpforms_form( 1631 )['settings']['honeypot'] );
	}

	/**
	 * The field callback carries the field ID through, or it would patch nothing.
	 *
	 * @return void
	 */
	public function test_field_execute_callback_writes_the_named_field(): void {
		$abilities = $this->register();

		( $abilities['bricks-mcp/wpforms-update-field']['execute_callback'] )(
			array(
				'form_id'    => 1631,
				'field_id'   => '3',
				'properties' => array( 'placeholder' => 'Select a topic' ),
			)
		);

		$this->assertSame(
			'Select a topic',
			bricks_mcp_test_stored_wpforms_form( 1631 )['fields']['3']['placeholder']
		);
	}

	/**
	 * Abilities are only as safe as their permission callbacks; a denial must be visible before
	 * the execute callback ever runs.
	 *
	 * @return void
	 */
	public function test_permission_callbacks_follow_the_wpforms_capability_map(): void {
		$abilities = $this->register();

		$GLOBALS['_bricks_mcp_test_wpforms_can'] = array(
			'wpforms_edit_forms' => false,
		);

		$this->assertFalse(
			( $abilities['bricks-mcp/wpforms-update-form-settings']['permission_callback'] )(
				array( 'form_id' => 1631 )
			)
		);
		$this->assertTrue(
			( $abilities['bricks-mcp/wpforms-get-form']['permission_callback'] )(
				array( 'form_id' => 1631 )
			)
		);
	}

	/**
	 * Registration runs on more than one action name, so a second pass must not re-register.
	 *
	 * @return void
	 */
	public function test_registration_is_idempotent(): void {
		$abilities = new WPFormsAbilities();

		$abilities->register();
		$first = $GLOBALS['_bricks_mcp_test_abilities'];

		bricks_mcp_test_reset_abilities();
		$abilities->register();

		$this->assertNotSame( array(), $first );
		$this->assertSame(
			array(),
			$GLOBALS['_bricks_mcp_test_abilities'],
			'A second register() pass must be a no-op, not a duplicate registration'
		);
	}

	/**
	 * On a build that groups abilities, every one of ours must land in a registered category —
	 * an ability naming a category that was never registered is rejected outright.
	 *
	 * @return void
	 */
	public function test_abilities_join_a_registered_category(): void {
		$abilities = $this->register();

		$this->assertArrayHasKey( 'bricks-mcp-wpforms', $GLOBALS['_bricks_mcp_test_ability_categories'] );

		foreach ( $abilities as $name => $definition ) {
			$this->assertSame(
				'bricks-mcp-wpforms',
				$definition['category'] ?? null,
				"{$name} must name the registered category"
			);
		}
	}
}
