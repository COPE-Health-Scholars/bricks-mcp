<?php
/**
 * WPForms service unit tests.
 *
 * These assert on what ends up stored, not on what the handler returns, because the whole reason
 * this service exists is that the other write lanes reported success without storing anything.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\WPFormsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WPFormsService.
 */
final class WPFormsServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var WPFormsService
	 */
	private WPFormsService $service;

	/**
	 * Reset the WPForms doubles and seed a representative form.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		bricks_mcp_test_reset_wpforms();
		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		bricks_mcp_test_seed_wpforms_form( 1631, $this->contact_form() );

		$this->service = new WPFormsService();
	}

	/**
	 * Clean up globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		bricks_mcp_test_reset_wpforms();

		parent::tearDown();
	}

	/**
	 * A form shaped like the real Contact Us form: notifications, toggles, a select with choices.
	 *
	 * @return array<string, mixed> Decoded form data.
	 */
	private function contact_form(): array {
		return array(
			'id'       => '1631',
			'field_id' => '4',
			'fields'   => array(
				'1' => array(
					'id'       => '1',
					'type'     => 'name',
					'label'    => 'Name',
					'required' => '1',
				),
				'3' => array(
					'id'      => '3',
					'type'    => 'select',
					'label'   => 'Topic',
					'style'   => 'classic',
					'choices' => array(
						1 => array( 'label' => 'Sales' ),
						2 => array( 'label' => 'Support' ),
						3 => array( 'label' => 'Other' ),
					),
				),
			),
			'settings' => array(
				'form_title'          => 'Contact Us',
				'submit_text'         => 'Send',
				'honeypot'            => false,
				'antispam'            => false,
				'notification_enable' => '1',
				'notifications'       => array(
					'1' => array(
						'notification_name' => 'Default Notification',
						'email'             => '{admin_email}',
						'subject'           => 'New Entry',
					),
				),
			),
		);
	}

	/**
	 * The stored settings after whatever the last save wrote.
	 *
	 * @return array<string, mixed> Settings.
	 */
	private function stored_settings(): array {
		return bricks_mcp_test_stored_wpforms_form( 1631 )['settings'] ?? array();
	}

	/**
	 * Notification wiring is PR-5's first unreachable piece: it must land without flattening the
	 * sibling keys already in the notification.
	 *
	 * @return void
	 */
	public function test_notification_patch_merges_without_dropping_siblings(): void {
		$result = $this->service->update_settings(
			1631,
			array(
				'notifications' => array(
					'1' => array(
						'email'   => 'forms@example.org',
						'replyto' => '{field_id="2"}',
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );

		$notification = $this->stored_settings()['notifications']['1'];

		$this->assertSame( 'forms@example.org', $notification['email'] );
		$this->assertSame( '{field_id="2"}', $notification['replyto'] );
		$this->assertSame(
			'New Entry',
			$notification['subject'],
			'A sibling key the patch never mentioned must survive the merge'
		);
		$this->assertSame( 'Default Notification', $notification['notification_name'] );
	}

	/**
	 * Antispam and honeypot are readable through get-form and unwritable through WPForms; that is
	 * PR-5's second piece.
	 *
	 * @return void
	 */
	public function test_antispam_and_honeypot_toggles_are_written(): void {
		$this->service->update_settings(
			1631,
			array(
				'honeypot'    => '1',
				'antispam'    => '1',
				'ajax_submit' => '1',
			)
		);

		$settings = $this->stored_settings();

		$this->assertSame( '1', $settings['honeypot'] );
		$this->assertSame( '1', $settings['antispam'] );
		$this->assertSame( '1', $settings['ajax_submit'] );
	}

	/**
	 * Untouched settings must not be collateral damage of a patch.
	 *
	 * @return void
	 */
	public function test_patch_leaves_unmentioned_settings_and_fields_alone(): void {
		$this->service->update_settings( 1631, array( 'honeypot' => '1' ) );

		$stored = bricks_mcp_test_stored_wpforms_form( 1631 );

		$this->assertSame( 'Contact Us', $stored['settings']['form_title'] );
		$this->assertSame( 'Send', $stored['settings']['submit_text'] );
		$this->assertArrayHasKey( '3', $stored['fields'], 'A settings write must not touch fields' );
		$this->assertCount( 3, $stored['fields']['3']['choices'] );
	}

	/**
	 * A null removes a key. Emptying it is not the same thing — WPForms treats a present-but-empty
	 * setting differently from an absent one.
	 *
	 * @return void
	 */
	public function test_null_deletes_a_setting(): void {
		$this->service->update_settings( 1631, array( 'notification_enable' => null ) );

		$this->assertArrayNotHasKey( 'notification_enable', $this->stored_settings() );
	}

	/**
	 * Lists replace wholesale, or a choice list could only ever grow.
	 *
	 * @return void
	 */
	public function test_list_values_replace_rather_than_merge(): void {
		$this->service->update_field(
			1631,
			'3',
			array(
				'choices' => array(
					array( 'label' => 'Sales' ),
					array( 'label' => 'Billing' ),
				),
			)
		);

		$choices = bricks_mcp_test_stored_wpforms_form( 1631 )['fields']['3']['choices'];

		$this->assertCount( 2, $choices, 'A shorter list must shorten the stored value' );
		$this->assertSame( 'Billing', $choices[1]['label'] );
	}

	/**
	 * PR-5's third piece: the select placeholder prompt, which the WPForms field schema exposes
	 * no way to set.
	 *
	 * @return void
	 */
	public function test_select_placeholder_is_written(): void {
		$result = $this->service->update_field( 1631, '3', array( 'placeholder' => 'Select a topic' ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			'Select a topic',
			bricks_mcp_test_stored_wpforms_form( 1631 )['fields']['3']['placeholder']
		);
		$this->assertSame( array( 'fields.3.placeholder' ), $result['applied'] );
		$this->assertSame( array(), $result['unverified_paths'] );
	}

	/**
	 * The field key and the field's own id must stay in agreement.
	 *
	 * @return void
	 */
	public function test_field_id_cannot_be_rewritten_by_a_patch(): void {
		$this->service->update_field(
			1631,
			'3',
			array(
				'id'    => '99',
				'label' => 'Reason for contact',
			)
		);

		$fields = bricks_mcp_test_stored_wpforms_form( 1631 )['fields'];

		$this->assertArrayNotHasKey( '99', $fields );
		$this->assertSame( '3', $fields['3']['id'] );
		$this->assertSame( 'Reason for contact', $fields['3']['label'] );
	}

	/**
	 * A patch of nothing but id has no effect to report, and saying so beats a silent no-op save.
	 *
	 * @return void
	 */
	public function test_patch_of_only_id_is_rejected(): void {
		$result = $this->service->update_field( 1631, '3', array( 'id' => '99' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_empty_patch', $result->get_error_code() );
	}

	/**
	 * WPForms unslashes what it is handed. Passing unslashed data would strip a backslash level
	 * from every value, so the service slashes first and the round trip must be lossless.
	 *
	 * @return void
	 */
	public function test_backslashes_survive_the_save_round_trip(): void {
		$pattern = '^\d{3}-\d{4}$';

		$this->service->update_field( 1631, '1', array( 'validation_pattern' => $pattern ) );

		$this->assertSame(
			$pattern,
			bricks_mcp_test_stored_wpforms_form( 1631 )['fields']['1']['validation_pattern'],
			'A regex written through the service must come back byte-identical'
		);
	}

	/**
	 * A refused save must not be reported as an update.
	 *
	 * @return void
	 */
	public function test_refused_save_returns_an_error(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_save_fails'] = true;

		$result = $this->service->update_settings( 1631, array( 'honeypot' => '1' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_save_failed', $result->get_error_code() );
	}

	/**
	 * When WPForms rewrites a value during save, the caller has to be told which paths did not
	 * survive — that is the difference between a reported success and a real one.
	 *
	 * @return void
	 */
	public function test_a_value_rewritten_during_save_is_reported_as_unverified(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_update_filter'] = static function ( array $data ): array {
			$data['settings']['notifications']['1']['email'] = 'sanitised@example.org';
			return $data;
		};

		$result = $this->service->update_settings(
			1631,
			array(
				'honeypot'      => '1',
				'notifications' => array(
					'1' => array( 'email' => 'forms@example.org' ),
				),
			)
		);

		$this->assertSame( array( 'settings.notifications.1.email' ), $result['unverified_paths'] );
		$this->assertContains( 'settings.honeypot', $result['applied'] );
	}

	/**
	 * Booleans normalised to WPForms' own '1' / '' spelling are not a failed write.
	 *
	 * @return void
	 */
	public function test_boolean_normalisation_is_not_flagged_as_unverified(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_update_filter'] = static function ( array $data ): array {
			$data['settings']['honeypot'] = '1';
			return $data;
		};

		$result = $this->service->update_settings( 1631, array( 'honeypot' => true ) );

		$this->assertSame( array(), $result['unverified_paths'] );
	}

	/**
	 * A missing field should say what the form actually has, not just that the guess was wrong.
	 *
	 * @return void
	 */
	public function test_missing_field_error_lists_the_available_ids(): void {
		$result = $this->service->update_field( 1631, '7', array( 'placeholder' => 'x' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_field_not_found', $result->get_error_code() );
		$this->assertStringContainsString( '1, 3', $result->get_error_message() );
	}

	/**
	 * A form that does not exist must not be silently created.
	 *
	 * @return void
	 */
	public function test_unknown_form_is_an_error(): void {
		$result = $this->service->update_settings( 9999, array( 'honeypot' => '1' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_form_not_found', $result->get_error_code() );
		$this->assertSame( array(), bricks_mcp_test_stored_wpforms_form( 9999 ) );
	}

	/**
	 * An empty patch is a caller mistake, not an instruction to save the form unchanged.
	 *
	 * @return void
	 */
	public function test_empty_patch_is_rejected(): void {
		$result = $this->service->update_settings( 1631, array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_empty_patch', $result->get_error_code() );
	}

	/**
	 * Reads are scoped so a caller can pull settings without the whole field tree.
	 *
	 * @return void
	 */
	public function test_get_form_sections_are_scoped(): void {
		$settings = $this->service->get_form( 1631, 'settings' );
		$this->assertArrayHasKey( 'settings', $settings );
		$this->assertArrayNotHasKey( 'fields', $settings );
		$this->assertArrayNotHasKey( 'form_data', $settings );

		$all = $this->service->get_form( 1631 );
		$this->assertArrayHasKey( 'form_data', $all );
		$this->assertSame( 'Contact Us', $all['title'] );

		$field = $this->service->get_form( 1631, 'all', '3' );
		$this->assertSame( 'select', $field['field']['type'] );
		$this->assertArrayNotHasKey( 'form_data', $field );
	}

	/**
	 * PR-5's fourth piece: clearing probe entries, which the WPForms entry abilities cannot do.
	 *
	 * @return void
	 */
	public function test_named_entries_are_deleted_and_misses_reported(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_entries'] = array( 11, 12, 13 );

		$result = $this->service->delete_entries( array( 11, 13, 99 ) );

		$this->assertSame( array( 11, 13 ), $result['deleted'] );
		$this->assertSame( 2, $result['deleted_count'] );
		$this->assertSame( array( 99 ), $result['failed'] );
		$this->assertSame( array( 12 ), $GLOBALS['_bricks_mcp_test_wpforms_entries'] );
	}

	/**
	 * On WPForms Lite there is no entry storage, and the error should say so rather than reporting
	 * that nothing was deleted.
	 *
	 * @return void
	 */
	public function test_entry_deletion_without_pro_is_an_explicit_error(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_has_entries'] = false;

		$result = $this->service->delete_entries( array( 11 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_entries_unavailable', $result->get_error_code() );
	}

	/**
	 * Capability checks defer to WPForms' own mapping, and a denial must stop the write.
	 *
	 * @return void
	 */
	public function test_write_capability_is_enforced(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_can'] = array( WPFormsService::CAP_WRITE => false );

		$result = $this->service->update_settings( 1631, array( 'honeypot' => '1' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_forbidden', $result->get_error_code() );
		$this->assertSame(
			false,
			$this->stored_settings()['honeypot'],
			'A denied write must leave the stored value untouched'
		);
	}
}
