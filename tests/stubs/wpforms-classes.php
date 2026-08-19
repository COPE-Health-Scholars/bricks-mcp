<?php
/**
 * WPForms test doubles.
 *
 * The form handler double reproduces the one behaviour of the real
 * WPForms_Form_Handler::update() that the service has to be written around: it calls
 * wp_unslash() on whatever it is handed, because its only in-tree caller passes the slashed
 * request payload. A double that skipped that would let a slashing bug through unnoticed.
 *
 * @package BricksMCP\Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Reset all WPForms double state.
 *
 * @return void
 */
function bricks_mcp_test_reset_wpforms(): void {
	$GLOBALS['_bricks_mcp_test_wpforms_forms']         = array();
	$GLOBALS['_bricks_mcp_test_wpforms_save_fails']    = false;
	$GLOBALS['_bricks_mcp_test_wpforms_can']           = true;
	$GLOBALS['_bricks_mcp_test_wpforms_has_entries']   = true;
	$GLOBALS['_bricks_mcp_test_wpforms_entries']       = array();
	$GLOBALS['_bricks_mcp_test_wpforms_update_filter'] = null;
	$GLOBALS['_bricks_mcp_test_wpforms_sent_data']     = null;
}

/**
 * Seed a form.
 *
 * @param int                  $form_id   Form ID.
 * @param array<string, mixed> $form_data Decoded form data.
 * @return void
 */
function bricks_mcp_test_seed_wpforms_form( int $form_id, array $form_data ): void {
	$GLOBALS['_bricks_mcp_test_wpforms_forms'][ $form_id ] = $form_data;
}

/**
 * Read a stored form back.
 *
 * @param int $form_id Form ID.
 * @return array<string, mixed> Decoded form data.
 */
function bricks_mcp_test_stored_wpforms_form( int $form_id ): array {
	return $GLOBALS['_bricks_mcp_test_wpforms_forms'][ $form_id ] ?? array();
}

if ( ! class_exists( 'Bricks_MCP_Test_WPForms_Form_Handler' ) ) {
	/**
	 * Stand-in for WPForms_Form_Handler.
	 */
	class Bricks_MCP_Test_WPForms_Form_Handler {

		/**
		 * Get a form.
		 *
		 * @param mixed                $id   Form ID.
		 * @param array<string, mixed> $args Query args.
		 * @return mixed Decoded form data, a post-like object, or false.
		 */
		public function get( mixed $id = '', array $args = array() ): mixed {
			$forms   = $GLOBALS['_bricks_mcp_test_wpforms_forms'] ?? array();
			$form_id = (int) $id;

			if ( ! isset( $forms[ $form_id ] ) ) {
				return false;
			}

			if ( ! empty( $args['content_only'] ) ) {
				return $forms[ $form_id ];
			}

			return (object) array( 'ID' => $form_id );
		}

		/**
		 * Update a form.
		 *
		 * @param mixed                $id   Form ID.
		 * @param array<string, mixed> $data Form data, expected slashed.
		 * @param array<string, mixed> $args Save args.
		 * @return mixed Form ID on success, false on refusal.
		 */
		public function update( mixed $id = '', array $data = array(), array $args = array() ): mixed {
			if ( ! empty( $GLOBALS['_bricks_mcp_test_wpforms_save_fails'] ) ) {
				return false;
			}

			$form_id = (int) $id;

			// Record exactly what the service handed over, before any unslashing.
			$GLOBALS['_bricks_mcp_test_wpforms_sent_data'] = $data;

			// The real handler opens with this, which is why the service slashes first.
			$stored = wp_unslash( $data );

			// Lets a test model WPForms rewriting a value during save.
			$filter = $GLOBALS['_bricks_mcp_test_wpforms_update_filter'] ?? null;
			if ( is_callable( $filter ) ) {
				$stored = $filter( $stored );
			}

			$GLOBALS['_bricks_mcp_test_wpforms_forms'][ $form_id ] = $stored;

			return $form_id;
		}
	}
}

if ( ! class_exists( 'Bricks_MCP_Test_WPForms_Entry_Handler' ) ) {
	/**
	 * Stand-in for the WPForms Pro entry handler.
	 */
	class Bricks_MCP_Test_WPForms_Entry_Handler {

		/**
		 * Delete an entry.
		 *
		 * @param mixed $entry_id Entry ID.
		 * @return bool True when the entry existed and was removed.
		 */
		public function delete( mixed $entry_id = 0 ): bool {
			$id      = (int) $entry_id;
			$entries = $GLOBALS['_bricks_mcp_test_wpforms_entries'] ?? array();

			if ( ! in_array( $id, $entries, true ) ) {
				return false;
			}

			$GLOBALS['_bricks_mcp_test_wpforms_entries'] = array_values(
				array_diff( $entries, array( $id ) )
			);

			return true;
		}
	}
}

if ( ! class_exists( 'Bricks_MCP_Test_WPForms' ) ) {
	/**
	 * Stand-in for the wpforms() container.
	 */
	class Bricks_MCP_Test_WPForms {

		/**
		 * Component instances.
		 *
		 * @var array<string, object>
		 */
		private array $components = array();

		/**
		 * Resolve a component.
		 *
		 * @param string $name Component name.
		 * @return object|null The component, or null when unavailable.
		 */
		public function get( string $name ): ?object {
			if ( 'entry' === $name && empty( $GLOBALS['_bricks_mcp_test_wpforms_has_entries'] ) ) {
				return null;
			}

			if ( ! isset( $this->components[ $name ] ) ) {
				$this->components[ $name ] = match ( $name ) {
					'form'  => new Bricks_MCP_Test_WPForms_Form_Handler(),
					'entry' => new Bricks_MCP_Test_WPForms_Entry_Handler(),
					default => new stdClass(),
				};
			}

			return $this->components[ $name ];
		}
	}
}

if ( ! function_exists( 'wpforms' ) ) {
	/**
	 * The WPForms container accessor.
	 *
	 * @return object The container.
	 */
	function wpforms(): object {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new Bricks_MCP_Test_WPForms();
		}

		return $instance;
	}
}

if ( ! function_exists( 'wpforms_current_user_can' ) ) {
	/**
	 * WPForms capability check.
	 *
	 * @param string $cap     Capability.
	 * @param int    $form_id Form ID.
	 * @return bool True when permitted.
	 */
	function wpforms_current_user_can( string $cap = '', int $form_id = 0 ): bool {
		$can = $GLOBALS['_bricks_mcp_test_wpforms_can'] ?? true;

		// An array value lets a test deny one capability while allowing the rest.
		if ( is_array( $can ) ) {
			return (bool) ( $can[ $cap ] ?? true );
		}

		return (bool) $can;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
