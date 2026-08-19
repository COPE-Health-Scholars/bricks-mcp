<?php
/**
 * WPForms abilities.
 *
 * Registers the WPForms write operations with the WordPress Abilities API, alongside the
 * abilities WPForms registers for itself.
 *
 * WHY REGISTER ABILITIES AND NOT ONLY MCP TOOLS
 * ---------------------------------------------
 * The same operations are exposed twice on purpose, and the duplication is the point:
 *
 *   - As MCP tools on this plugin's own endpoint, for a client already connected to Bricks MCP.
 *   - As abilities here, so any client already talking to the site's Abilities API surface —
 *     the one WPForms' own "MCP write access" toggle turns on — picks them up with no new
 *     credential, no second endpoint, and no firewall change. They inherit the identical
 *     application-password authentication and administrator gate as the abilities already there.
 *
 * Both routes call the same WPFormsService, so there is one implementation and one set of
 * capability checks behind them.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Abilities;

use BricksMCP\MCP\Services\WPFormsService;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WPForms abilities with the WordPress Abilities API.
 */
final class WPFormsAbilities {

	/**
	 * Ability namespace.
	 *
	 * @var string
	 */
	private const ABILITY_NAMESPACE = 'bricks-mcp';

	/**
	 * Ability category slug.
	 *
	 * @var string
	 */
	private const CATEGORY = 'bricks-mcp-wpforms';

	/**
	 * WPForms service.
	 *
	 * @var WPFormsService
	 */
	private WPFormsService $service;

	/**
	 * Whether registration has already run.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param WPFormsService|null $service Optional service instance for testing.
	 */
	public function __construct( ?WPFormsService $service = null ) {
		$this->service = $service ?? new WPFormsService();
	}

	/**
	 * Hook registration onto the Abilities API init action.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		/*
		 * The init action was renamed while the Abilities API was stabilising, and which name a
		 * site fires depends on whether it is running core's copy or the feature plugin. Hooking
		 * both costs nothing: register() is idempotent, so whichever fires first wins and the
		 * other is a no-op.
		 */
		add_action( 'abilities_api_init', array( $this, 'register' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );

		// Late fallback for a build that exposes the function but fires neither action.
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	/**
	 * Register the abilities.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$this->registered = true;

		// Abilities are only useful when there is a WPForms install behind them.
		if ( ! $this->service->is_active() ) {
			return;
		}

		$category = array();
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => __( 'WPForms (Bricks MCP)', 'bricks-mcp' ),
					'description' => __( 'Read and write WPForms form settings, fields and entries.', 'bricks-mcp' ),
				)
			);
			$category = array( 'category' => self::CATEGORY );
		}

		foreach ( $this->definitions() as $name => $definition ) {
			wp_register_ability( self::ABILITY_NAMESPACE . '/' . $name, array_merge( $definition, $category ) );
		}
	}

	/**
	 * Build the ability definitions.
	 *
	 * @return array<string, array<string, mixed>> Definitions keyed by unprefixed ability name.
	 */
	private function definitions(): array {
		$service = $this->service;

		return array(
			'wpforms-get-form'             => array(
				'label'               => __( 'Read a WPForms form', 'bricks-mcp' ),
				'description'         => __( 'Return a WPForms form exactly as it is stored, including settings, notifications and every field property. Use this before a write to see what a patch will land on.', 'bricks-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Form ID.', 'bricks-mcp' ),
						),
						'section'  => array(
							'type'        => 'string',
							'enum'        => array( 'all', 'settings', 'fields', 'meta' ),
							'default'     => 'all',
							'description' => __( 'Which part of the form to return. Use settings or fields to keep the payload small.', 'bricks-mcp' ),
						),
						'field_id' => array(
							'type'        => 'string',
							'description' => __( 'Return only this field.', 'bricks-mcp' ),
						),
					),
					'required'   => array( 'form_id' ),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'meta'                => array(
					'annotations' => array(
						'readOnly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
				'permission_callback' => static fn( array $input = array() ): bool => $service->current_user_can(
					WPFormsService::CAP_READ,
					isset( $input['form_id'] ) ? (int) $input['form_id'] : 0
				),
				'execute_callback'    => static fn( array $input = array() ): mixed => $service->get_form(
					isset( $input['form_id'] ) ? (int) $input['form_id'] : 0,
					isset( $input['section'] ) ? (string) $input['section'] : 'all',
					isset( $input['field_id'] ) ? (string) $input['field_id'] : ''
				),
			),

			'wpforms-update-form-settings' => array(
				'label'               => __( 'Update WPForms form settings', 'bricks-mcp' ),
				'description'         => __( 'Deep-merge a patch into a form\'s settings and save it through WPForms. Reaches everything the built-in editing ability cannot: notifications (recipient, subject, sender name, sender address, reply-to), notification_enable, honeypot, antispam, ajax_submit and confirmations. A null value removes a key.', 'bricks-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Form ID.', 'bricks-mcp' ),
						),
						'settings' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'Settings patch, deep-merged into the stored settings. Nested objects merge key by key; arrays and scalars replace; null deletes.', 'bricks-mcp' ),
						),
					),
					'required'   => array( 'form_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'meta'                => array(
					'annotations' => array(
						'readOnly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
				'permission_callback' => static fn( array $input = array() ): bool => $service->current_user_can(
					WPFormsService::CAP_WRITE,
					isset( $input['form_id'] ) ? (int) $input['form_id'] : 0
				),
				'execute_callback'    => static fn( array $input = array() ): mixed => $service->update_settings(
					isset( $input['form_id'] ) ? (int) $input['form_id'] : 0,
					isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array()
				),
			),

			'wpforms-update-field'         => array(
				'label'               => __( 'Update a WPForms field', 'bricks-mcp' ),
				'description'         => __( 'Deep-merge a patch into one field on a form and save it through WPForms. Reaches per-field properties the built-in field schema does not expose, including the select placeholder prompt, required, description, default value and conditional logic. A null value removes a key.', 'bricks-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Form ID.', 'bricks-mcp' ),
						),
						'field_id'   => array(
							'type'        => 'string',
							'description' => __( 'Field ID as keyed in the form data. Read the form first if unsure.', 'bricks-mcp' ),
						),
						'properties' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'Field property patch, e.g. {"placeholder": "Select a topic"}. The field id itself cannot be changed.', 'bricks-mcp' ),
						),
					),
					'required'   => array( 'form_id', 'field_id', 'properties' ),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'meta'                => array(
					'annotations' => array(
						'readOnly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
				'permission_callback' => static fn( array $input = array() ): bool => $service->current_user_can(
					WPFormsService::CAP_WRITE,
					isset( $input['form_id'] ) ? (int) $input['form_id'] : 0
				),
				'execute_callback'    => static fn( array $input = array() ): mixed => $service->update_field(
					isset( $input['form_id'] ) ? (int) $input['form_id'] : 0,
					isset( $input['field_id'] ) ? (string) $input['field_id'] : '',
					isset( $input['properties'] ) && is_array( $input['properties'] ) ? $input['properties'] : array()
				),
			),

			'wpforms-delete-entries'       => array(
				'label'               => __( 'Delete WPForms entries', 'bricks-mcp' ),
				'description'         => __( 'Permanently delete WPForms entries by ID. Entry IDs must be named explicitly; there is no delete-all mode. Requires WPForms Pro, which is what stores entries.', 'bricks-mcp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'entry_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'minItems'    => 1,
							'description' => __( 'Entry IDs to delete.', 'bricks-mcp' ),
						),
					),
					'required'   => array( 'entry_ids' ),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'meta'                => array(
					'annotations' => array(
						'readOnly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature parity with the other permission callbacks; entry deletion is not per-form.
				'permission_callback' => static fn( array $input = array() ): bool => $service->current_user_can(
					WPFormsService::CAP_DELETE_ENTRIES
				),
				'execute_callback'    => static fn( array $input = array() ): mixed => $service->delete_entries(
					isset( $input['entry_ids'] ) && is_array( $input['entry_ids'] ) ? $input['entry_ids'] : array()
				),
			),
		);
	}
}
