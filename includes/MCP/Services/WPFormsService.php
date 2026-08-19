<?php
/**
 * WPForms service.
 *
 * Wraps WPForms' own internals so form settings, individual field properties, and entries can
 * be written over MCP.
 *
 * WHY THIS EXISTS
 * ---------------
 * WPForms ships an Abilities API surface, but its editing ability (update-form-settings) accepts
 * only form_title, form_desc and submit_text. Everything else a real form needs — notification
 * wiring, honeypot/antispam/ajax toggles, select placeholders — is readable through get-form and
 * unreachable through every write path WPForms exposes:
 *
 *   - The REST and XML-RPC post routes return 401 on the wpforms CPT even for an administrator
 *     who authored the form. WPForms blocks edits to its own post type outside its own surfaces,
 *     so no role, capability or firewall change opens that lane.
 *   - Writing the CPT directly with wp_update_post() would bypass the sanitisation, revision
 *     handling and cache invalidation WPForms performs on save.
 *
 * The supported path is WPForms' own form handler. This service reads the decoded form data,
 * deep-merges a caller patch into it, and hands the whole structure back to
 * wpforms()->form->update() — the identical call the form builder makes when a human clicks Save.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPForms service class.
 */
final class WPFormsService {

	/**
	 * Capability required to read forms.
	 *
	 * @var string
	 */
	public const CAP_READ = 'wpforms_view_forms';

	/**
	 * Capability required to edit forms.
	 *
	 * @var string
	 */
	public const CAP_WRITE = 'wpforms_edit_forms';

	/**
	 * Capability required to delete entries.
	 *
	 * @var string
	 */
	public const CAP_DELETE_ENTRIES = 'wpforms_delete_entries';

	/**
	 * The WPForms form post type.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'wpforms';

	/**
	 * Whether WPForms is installed and loaded.
	 *
	 * @return bool True when the wpforms() container is available.
	 */
	public function is_active(): bool {
		return function_exists( 'wpforms' );
	}

	/**
	 * Whether the WPForms entry handler is available.
	 *
	 * Entries are a WPForms Pro feature; on Lite the entry component does not exist.
	 *
	 * @return bool True when entries can be read and deleted.
	 */
	public function has_entries(): bool {
		return null !== $this->component( 'entry' );
	}

	/**
	 * List forms.
	 *
	 * @param array<string, mixed> $args Optional search, posts_per_page and paged.
	 * @return array<string, mixed>|\WP_Error Form summaries or an error.
	 */
	public function list_forms( array $args = array() ): array|\WP_Error {
		$guard = $this->guard( self::CAP_READ );
		if ( null !== $guard ) {
			return $guard;
		}

		$per_page = isset( $args['posts_per_page'] ) ? absint( $args['posts_per_page'] ) : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$paged    = isset( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;
		$search   = isset( $args['search'] ) ? (string) $args['search'] : '';

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$forms = get_posts( $query_args );

		$results = array();
		foreach ( $forms as $form ) {
			$results[] = array(
				'form_id' => (int) $form->ID,
				'title'   => (string) $form->post_title,
				'status'  => (string) $form->post_status,
			);
		}

		return array(
			'forms' => $results,
			'count' => count( $results ),
			'paged' => $paged,
		);
	}

	/**
	 * Read a form's decoded data.
	 *
	 * This is the raw structure WPForms stores in post_content — the same thing the builder
	 * edits — so a caller can see exactly what a patch will land on top of.
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $section  One of all, settings, fields or meta.
	 * @param string $field_id Optional single field ID to return.
	 * @return array<string, mixed>|\WP_Error Form data or an error.
	 */
	public function get_form( int $form_id, string $section = 'all', string $field_id = '' ): array|\WP_Error {
		$guard = $this->guard( self::CAP_READ, $form_id );
		if ( null !== $guard ) {
			return $guard;
		}

		$form_data = $this->read_form_data( $form_id );
		if ( is_wp_error( $form_data ) ) {
			return $form_data;
		}

		$result = array(
			'form_id' => $form_id,
			'title'   => (string) ( $form_data['settings']['form_title'] ?? get_the_title( $form_id ) ),
		);

		if ( '' !== $field_id ) {
			$field = $form_data['fields'][ $field_id ] ?? null;
			if ( null === $field ) {
				return $this->field_not_found( $form_id, $field_id, $form_data );
			}
			$result['field'] = $field;
			return $result;
		}

		switch ( $section ) {
			case 'settings':
				$result['settings'] = $form_data['settings'] ?? array();
				break;
			case 'fields':
				$result['fields'] = $form_data['fields'] ?? array();
				break;
			case 'meta':
				$result['meta']             = $form_data['meta'] ?? array();
				$result['field_id_counter'] = $form_data['field_id'] ?? null;
				break;
			case 'all':
			default:
				$result['form_data'] = $form_data;
				break;
		}

		return $result;
	}

	/**
	 * Merge a patch into a form's settings and save through WPForms.
	 *
	 * Reaches everything the WPForms editing ability cannot: settings.notifications (recipient,
	 * subject, sender_name, sender_address, replyto), notification_enable, honeypot, antispam,
	 * ajax_submit, confirmations, and anything else WPForms keeps under settings.
	 *
	 * @param int                  $form_id  Form ID.
	 * @param array<string, mixed> $settings Settings patch, deep-merged. A null value deletes a key.
	 * @return array<string, mixed>|\WP_Error Result or an error.
	 */
	public function update_settings( int $form_id, array $settings ): array|\WP_Error {
		$guard = $this->guard( self::CAP_WRITE, $form_id );
		if ( null !== $guard ) {
			return $guard;
		}

		if ( array() === $settings ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_empty_patch',
				__( 'No settings supplied. Pass a settings object with at least one key.', 'bricks-mcp' )
			);
		}

		$form_data = $this->read_form_data( $form_id );
		if ( is_wp_error( $form_data ) ) {
			return $form_data;
		}

		$merged             = $form_data;
		$merged['settings'] = $this->merge_deep( $form_data['settings'] ?? array(), $settings );

		$saved = $this->save( $form_id, $merged );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$stored = $this->read_form_data( $form_id );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'form_id'          => $form_id,
			'updated'          => true,
			'applied'          => $this->patch_paths( $settings, 'settings' ),
			'unverified_paths' => $this->unverified_paths(
				$settings,
				$merged['settings'],
				$stored['settings'] ?? array(),
				'settings'
			),
			'settings'         => $stored['settings'] ?? array(),
		);
	}

	/**
	 * Merge a patch into a single field and save through WPForms.
	 *
	 * Reaches per-field properties the WPForms field schema does not expose — most notably
	 * `placeholder` on select fields, which is the prompt row shown above the choices.
	 *
	 * @param int                  $form_id    Form ID.
	 * @param string               $field_id   Field ID as keyed in the form's fields array.
	 * @param array<string, mixed> $properties Field property patch. A null value deletes a key.
	 * @return array<string, mixed>|\WP_Error Result or an error.
	 */
	public function update_field( int $form_id, string $field_id, array $properties ): array|\WP_Error {
		$guard = $this->guard( self::CAP_WRITE, $form_id );
		if ( null !== $guard ) {
			return $guard;
		}

		if ( array() === $properties ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_empty_patch',
				__( 'No properties supplied. Pass a properties object with at least one key.', 'bricks-mcp' )
			);
		}

		$form_data = $this->read_form_data( $form_id );
		if ( is_wp_error( $form_data ) ) {
			return $form_data;
		}

		if ( ! isset( $form_data['fields'][ $field_id ] ) || ! is_array( $form_data['fields'][ $field_id ] ) ) {
			return $this->field_not_found( $form_id, $field_id, $form_data );
		}

		/*
		 * The field's own `id` is the key WPForms indexes fields by. Letting a patch rewrite it
		 * would orphan the field from its key and break every entry value, notification token and
		 * conditional-logic rule pointing at it.
		 */
		unset( $properties['id'] );
		if ( array() === $properties ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_empty_patch',
				__( 'Only "id" was supplied, and a field ID cannot be changed. Pass at least one other property.', 'bricks-mcp' )
			);
		}

		$merged                        = $form_data;
		$merged['fields'][ $field_id ] = $this->merge_deep( $form_data['fields'][ $field_id ], $properties );

		$saved = $this->save( $form_id, $merged );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$stored = $this->read_form_data( $form_id );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'form_id'          => $form_id,
			'field_id'         => $field_id,
			'updated'          => true,
			'applied'          => $this->patch_paths( $properties, 'fields.' . $field_id ),
			'unverified_paths' => $this->unverified_paths(
				$properties,
				$merged['fields'][ $field_id ],
				$stored['fields'][ $field_id ] ?? array(),
				'fields.' . $field_id
			),
			'field'            => $stored['fields'][ $field_id ] ?? array(),
		);
	}

	/**
	 * Delete form entries by ID.
	 *
	 * Entry IDs must be named explicitly — there is deliberately no "delete every entry on this
	 * form" mode, because the intended use is clearing a handful of test submissions.
	 *
	 * @param array<int, int|string> $entry_ids Entry IDs to delete.
	 * @return array<string, mixed>|\WP_Error Result or an error.
	 */
	public function delete_entries( array $entry_ids ): array|\WP_Error {
		$guard = $this->guard( self::CAP_DELETE_ENTRIES );
		if ( null !== $guard ) {
			return $guard;
		}

		$entry = $this->component( 'entry' );
		if ( null === $entry ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_entries_unavailable',
				__( 'WPForms entry storage is unavailable. Entries are a WPForms Pro feature; WPForms Lite does not store them.', 'bricks-mcp' )
			);
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $entry_ids ) ) ) );
		if ( array() === $ids ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_no_entry_ids',
				__( 'No valid entry IDs supplied. Pass entry_ids as an array of positive integers.', 'bricks-mcp' )
			);
		}

		$deleted = array();
		$failed  = array();
		foreach ( $ids as $id ) {
			if ( $entry->delete( $id ) ) {
				$deleted[] = $id;
				continue;
			}
			$failed[] = $id;
		}

		return array(
			'deleted'       => $deleted,
			'deleted_count' => count( $deleted ),
			'failed'        => $failed,
		);
	}

	/**
	 * Build the "no such field" error, listing what the form does have.
	 *
	 * @param int                  $form_id   Form ID.
	 * @param string               $field_id  Requested field ID.
	 * @param array<string, mixed> $form_data Decoded form data.
	 * @return \WP_Error Error naming the available field IDs.
	 */
	private function field_not_found( int $form_id, string $field_id, array $form_data ): \WP_Error {
		$available = array_map( 'strval', array_keys( (array) ( $form_data['fields'] ?? array() ) ) );

		return new \WP_Error(
			'bricks_mcp_wpforms_field_not_found',
			sprintf(
				/* translators: 1: Field ID, 2: Form ID, 3: Comma-separated list of existing field IDs */
				__( 'Field "%1$s" does not exist on form %2$d. Existing field IDs: %3$s', 'bricks-mcp' ),
				$field_id,
				$form_id,
				array() === $available ? __( 'none', 'bricks-mcp' ) : implode( ', ', $available )
			)
		);
	}

	/**
	 * Read and decode a form's stored data.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>|\WP_Error Decoded form data or an error.
	 */
	private function read_form_data( int $form_id ): array|\WP_Error {
		if ( $form_id <= 0 ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_invalid_form_id',
				__( 'A positive form_id is required.', 'bricks-mcp' )
			);
		}

		$form = $this->component( 'form' );
		if ( null === $form ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_inactive',
				__( 'WPForms is not installed or not active on this site.', 'bricks-mcp' )
			);
		}

		$data = $form->get( $form_id, array( 'content_only' => true ) );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_form_not_found',
				sprintf(
					/* translators: %d: Form ID */
					__( 'Form %d was not found, or its stored data could not be decoded.', 'bricks-mcp' ),
					$form_id
				)
			);
		}

		return $data;
	}

	/**
	 * Hand a complete form-data structure back to WPForms to save.
	 *
	 * @param int                  $form_id   Form ID.
	 * @param array<string, mixed> $form_data Complete merged form data.
	 * @return true|\WP_Error True on success, error otherwise.
	 */
	private function save( int $form_id, array $form_data ): true|\WP_Error {
		$form = $this->component( 'form' );
		if ( null === $form ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_inactive',
				__( 'WPForms is not installed or not active on this site.', 'bricks-mcp' )
			);
		}

		/*
		 * WPForms_Form_Handler::update() opens by calling wp_unslash() on the data it is given,
		 * because its only in-tree caller is the builder's AJAX save, which hands it the slashed
		 * request payload. Passing an unslashed array here would therefore strip one level of
		 * backslashes from every value — quietly corrupting regex validation patterns and any
		 * escaped character in a notification body. Slashing first round-trips cleanly.
		 */
		$result = $form->update( $form_id, wp_slash( $form_data ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_save_failed',
				sprintf(
					/* translators: %d: Form ID */
					__( 'WPForms refused the save for form %d. This is usually a capability check (wpforms_edit_forms) or a form locked by another editor.', 'bricks-mcp' ),
					$form_id
				)
			);
		}

		return true;
	}

	/**
	 * Deep-merge a patch into an existing structure.
	 *
	 * Associative arrays merge key by key; lists (choices, conditional rules) and scalars replace
	 * wholesale, so a caller can shorten a choice list rather than only ever grow it. A null value
	 * deletes the key outright, which is how a setting gets removed rather than emptied.
	 *
	 * @param array<string, mixed> $base  Existing structure.
	 * @param array<string, mixed> $patch Patch to apply.
	 * @return array<string, mixed> Merged structure.
	 */
	private function merge_deep( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( null === $value ) {
				unset( $base[ $key ] );
				continue;
			}

			if (
				is_array( $value )
				&& ! array_is_list( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
			) {
				$base[ $key ] = $this->merge_deep( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/**
	 * Flatten a patch into the dotted paths it touches.
	 *
	 * @param array<string, mixed> $patch  Patch structure.
	 * @param string               $prefix Path prefix.
	 * @return array<int, string> Dotted paths.
	 */
	private function patch_paths( array $patch, string $prefix = '' ): array {
		$paths = array();

		foreach ( $patch as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) && ! array_is_list( $value ) && array() !== $value ) {
				$paths = array_merge( $paths, $this->patch_paths( $value, $path ) );
				continue;
			}

			$paths[] = $path;
		}

		return $paths;
	}

	/**
	 * Report which patched paths did not come back as sent after the save.
	 *
	 * WPForms sanitises on save, so a non-empty list is not automatically a failure — but it is
	 * the difference between "the write reported success" and "the value is actually stored",
	 * which is the exact gap that made every other write lane untrustworthy.
	 *
	 * @param array<string, mixed> $patch    Patch that was applied.
	 * @param mixed                $expected Merged value that was sent.
	 * @param mixed                $actual   Value read back after the save.
	 * @param string               $prefix   Path prefix.
	 * @return array<int, string> Dotted paths whose stored value differs from what was sent.
	 */
	private function unverified_paths( array $patch, mixed $expected, mixed $actual, string $prefix = '' ): array {
		$paths    = array();
		$expected = is_array( $expected ) ? $expected : array();
		$actual   = is_array( $actual ) ? $actual : array();

		foreach ( $patch as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( null === $value ) {
				if ( array_key_exists( $key, $actual ) ) {
					$paths[] = $path;
				}
				continue;
			}

			if (
				is_array( $value )
				&& ! array_is_list( $value )
				&& array() !== $value
				&& isset( $expected[ $key ] )
				&& is_array( $expected[ $key ] )
			) {
				$paths = array_merge(
					$paths,
					$this->unverified_paths( $value, $expected[ $key ], $actual[ $key ] ?? array(), $path )
				);
				continue;
			}

			/*
			 * Loose comparison on purpose: WPForms normalises checkbox-backed settings to '1' and
			 * '' on save, so a caller passing true/false would otherwise be told every toggle it
			 * set had failed to store.
			 */
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			if ( ( $expected[ $key ] ?? null ) != ( $actual[ $key ] ?? null ) ) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * Resolve a WPForms component across the accessors different WPForms versions expose.
	 *
	 * @param string $name Component name, e.g. form or entry.
	 * @return object|null The component, or null when unavailable.
	 */
	private function component( string $name ): ?object {
		if ( ! $this->is_active() ) {
			return null;
		}

		$wpforms = wpforms();
		if ( ! is_object( $wpforms ) ) {
			return null;
		}

		/*
		 * WPForms 1.8.7+ prefers obj(); get() is the long-standing accessor; the magic property is
		 * the oldest. Each yields null rather than throwing when a component is absent, which is
		 * how the Lite build reports that entries do not exist.
		 */
		foreach ( array( 'obj', 'get' ) as $accessor ) {
			if ( ! method_exists( $wpforms, $accessor ) ) {
				continue;
			}
			$component = $wpforms->$accessor( $name );
			if ( is_object( $component ) ) {
				return $component;
			}
		}

		$component = $wpforms->$name ?? null;

		return is_object( $component ) ? $component : null;
	}

	/**
	 * Check that WPForms is present and the current user holds a capability.
	 *
	 * @param string $cap     Capability to require.
	 * @param int    $form_id Optional form ID for per-form capability checks.
	 * @return \WP_Error|null Error to return, or null when the call may proceed.
	 */
	private function guard( string $cap, int $form_id = 0 ): ?\WP_Error {
		if ( ! $this->is_active() ) {
			return new \WP_Error(
				'bricks_mcp_wpforms_inactive',
				__( 'WPForms is not installed or not active on this site.', 'bricks-mcp' )
			);
		}

		if ( ! $this->current_user_can( $cap, $form_id ) ) {
			return new \WP_Error(
				'bricks_mcp_forbidden',
				sprintf(
					/* translators: %s: Required capability */
					__( 'You do not have the required capability (%s) to perform this action.', 'bricks-mcp' ),
					$cap
				)
			);
		}

		return null;
	}

	/**
	 * Capability check that honours WPForms' own per-form capability mapping.
	 *
	 * @param string $cap     Capability to check.
	 * @param int    $form_id Optional form ID.
	 * @return bool True when permitted.
	 */
	public function current_user_can( string $cap, int $form_id = 0 ): bool {
		if ( function_exists( 'wpforms_current_user_can' ) ) {
			return (bool) wpforms_current_user_can( $cap, $form_id );
		}

		return current_user_can( $cap );
	}
}
