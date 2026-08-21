<?php
/**
 * ACF service.
 *
 * Reads and writes Advanced Custom Fields values through ACF's own API.
 *
 * WHY THIS EXISTS
 * ---------------
 * ACF stores every value as a meta pair: the value itself (overview => "...") plus a protected
 * reference meta (_overview => field_abc123) naming the field definition. Both halves matter —
 * a value without its reference renders as if it were never filled in.
 *
 * Neither half was reachable cleanly before. The XML-RPC sync lane cannot read or write
 * _-prefixed protected metas at all, and the MCP's own update_meta is deliberately narrow
 * (title/status/slug/featured image). The pipeline's workaround was to duplicate a known-good
 * post so the reference metas came along by construction — a whole extra post and a sync dance
 * to route around not being able to write two meta rows.
 *
 * update_field() called with a FIELD KEY writes both halves correctly. Called with a bare name
 * that ACF cannot resolve to a field definition, it "succeeds" by writing the value with an
 * empty reference — the exact corruption this service exists to avoid — so unresolvable names
 * are a hard per-field error here, never a fallback write.
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
 * ACF service class.
 */
final class ACFService {

	/**
	 * Whether ACF is installed and loaded.
	 *
	 * @return bool True when ACF's field API is available.
	 */
	public function is_active(): bool {
		return function_exists( 'update_field' ) && function_exists( 'acf_get_field' );
	}

	/**
	 * Read a post's ACF fields with their definitions.
	 *
	 * Returns key, name, type and label alongside each value, because the keys are exactly what
	 * a caller needs to hand back to set_fields().
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|\WP_Error Fields or an error.
	 */
	public function get_fields( int $post_id ): array|\WP_Error {
		$guard = $this->guard( $post_id );
		if ( null !== $guard ) {
			return $guard;
		}

		$objects = function_exists( 'get_field_objects' ) ? get_field_objects( $post_id ) : false;

		$fields = array();
		if ( is_array( $objects ) ) {
			foreach ( $objects as $name => $object ) {
				if ( ! is_array( $object ) ) {
					continue;
				}
				$fields[] = array(
					'key'   => (string) ( $object['key'] ?? '' ),
					'name'  => (string) $name,
					'label' => (string) ( $object['label'] ?? '' ),
					'type'  => (string) ( $object['type'] ?? '' ),
					'value' => $object['value'] ?? null,
				);
			}
		}

		return array(
			'post_id' => $post_id,
			'fields'  => $fields,
			'count'   => count( $fields ),
		);
	}

	/**
	 * Write ACF field values through update_field().
	 *
	 * Accepts a map of field key (field_xxx) or field name => value. Names are resolved to their
	 * definitions first; a name ACF cannot resolve fails that field without writing anything,
	 * because update_field() would otherwise store the value with an empty field reference and
	 * the post would carry data ACF refuses to render.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $fields  Field key or name => value.
	 * @return array<string, mixed>|\WP_Error Per-field results or an error.
	 */
	public function set_fields( int $post_id, array $fields ): array|\WP_Error {
		$guard = $this->guard( $post_id );
		if ( null !== $guard ) {
			return $guard;
		}

		if ( array() === $fields ) {
			return new \WP_Error(
				'bricks_mcp_acf_empty_patch',
				__( 'No fields supplied. Pass a fields object mapping field keys or names to values.', 'bricks-mcp' )
			);
		}

		$results = array();
		$failed  = array();

		foreach ( $fields as $selector => $value ) {
			$selector = (string) $selector;

			$field = $this->resolve_field( $selector );
			if ( null === $field ) {
				$failed[] = array(
					'selector' => $selector,
					'error'    => __( 'No ACF field definition matches this key or name. Nothing was written — a write against an unresolved selector would store the value with an empty field reference.', 'bricks-mcp' ),
				);
				continue;
			}

			$key  = (string) $field['key'];
			$name = (string) $field['name'];

			// Always write by KEY: this is what makes ACF store the _<name> reference meta.
			update_field( $key, $value, $post_id );

			/*
			 * Verify both halves landed rather than trusting the call. The reference meta is the
			 * half every other write path lost; the value is read back raw (unformatted) so what
			 * is reported is what the database holds, not what a render filter made of it.
			 */
			$reference = get_post_meta( $post_id, '_' . $name, true );
			$stored    = function_exists( 'get_field' ) ? get_field( $key, $post_id, false ) : null;

			$results[] = array(
				'selector'     => $selector,
				'key'          => $key,
				'name'         => $name,
				'stored_value' => $stored,
				'reference_ok' => $reference === $key,
			);
		}

		if ( array() === $results && array() !== $failed ) {
			return new \WP_Error(
				'bricks_mcp_acf_no_fields_resolved',
				__( 'None of the supplied selectors matched an ACF field definition. Nothing was written.', 'bricks-mcp' ),
				array( 'failed' => $failed )
			);
		}

		return array(
			'post_id' => $post_id,
			'updated' => $results,
			'failed'  => $failed,
			'count'   => count( $results ),
		);
	}

	/**
	 * Resolve a field key or name to its ACF definition.
	 *
	 * @param string $selector Field key (field_xxx) or field name.
	 * @return array<string, mixed>|null The field definition, or null when unresolved.
	 */
	private function resolve_field( string $selector ): ?array {
		if ( '' === $selector ) {
			return null;
		}

		// acf_get_field() accepts a key, name, or ID, and returns false when nothing matches.
		$field = acf_get_field( $selector );

		if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['name'] ) ) {
			return null;
		}

		return $field;
	}

	/**
	 * Shared preconditions for every ACF operation.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Error|null Error to return, or null when the call may proceed.
	 */
	private function guard( int $post_id ): ?\WP_Error {
		if ( ! $this->is_active() ) {
			return new \WP_Error(
				'bricks_mcp_acf_inactive',
				__( 'Advanced Custom Fields is not installed or not active on this site.', 'bricks-mcp' )
			);
		}

		if ( $post_id <= 0 || null === get_post( $post_id ) ) {
			return new \WP_Error(
				'bricks_mcp_acf_post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post %d was not found.', 'bricks-mcp' ),
					$post_id
				)
			);
		}

		return null;
	}
}
