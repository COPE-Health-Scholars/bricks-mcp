<?php
/**
 * ACF test doubles.
 *
 * Reproduce the two behaviours the service is written around: update_field() called with a field
 * KEY writes both the value meta and the protected _<name> reference meta, and acf_get_field()
 * resolves a key or a name to the field definition, returning false when nothing matches. Values
 * land in the shared in-memory post meta store so tests assert on what got stored, not on what
 * the call returned.
 *
 * @package BricksMCP\Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Reset the ACF double state.
 *
 * @return void
 */
function bricks_mcp_test_reset_acf(): void {
	$GLOBALS['_bricks_mcp_test_acf_fields'] = array();
}

/**
 * Register a field definition with the double.
 *
 * @param string $key   Field key (field_xxx).
 * @param string $name  Field name.
 * @param string $type  Field type.
 * @param string $label Field label.
 * @return void
 */
function bricks_mcp_test_register_acf_field( string $key, string $name, string $type = 'text', string $label = '' ): void {
	$GLOBALS['_bricks_mcp_test_acf_fields'][ $key ] = array(
		'key'   => $key,
		'name'  => $name,
		'type'  => $type,
		'label' => '' === $label ? ucfirst( $name ) : $label,
	);
}

if ( ! function_exists( 'acf_get_field' ) ) {
	/**
	 * Resolve a field key or name to its definition.
	 *
	 * @param mixed $selector Field key, name or ID.
	 * @return array<string, mixed>|false The field, or false when unresolved.
	 */
	function acf_get_field( mixed $selector = null ): array|false {
		$fields   = $GLOBALS['_bricks_mcp_test_acf_fields'] ?? array();
		$selector = (string) $selector;

		if ( isset( $fields[ $selector ] ) ) {
			return $fields[ $selector ];
		}

		foreach ( $fields as $field ) {
			if ( $field['name'] === $selector ) {
				return $field;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'update_field' ) ) {
	/**
	 * Write a field value the way real ACF does when handed a field KEY.
	 *
	 * @param string $selector Field key.
	 * @param mixed  $value    Value to store.
	 * @param mixed  $post_id  Post ID.
	 * @return bool True on write.
	 */
	function update_field( string $selector, mixed $value, mixed $post_id = false ): bool {
		$field = acf_get_field( $selector );

		if ( is_array( $field ) && str_starts_with( $selector, 'field_' ) ) {
			// The key path: both halves written, which is the whole point of writing by key.
			update_post_meta( (int) $post_id, $field['name'], $value );
			update_post_meta( (int) $post_id, '_' . $field['name'], $field['key'] );
			return true;
		}

		/*
		 * The name path real ACF takes when it cannot resolve a reference: the value is stored
		 * under the selector with an EMPTY reference meta. This is the corruption the service must
		 * never trigger, so the double reproduces it faithfully for a test to catch.
		 */
		update_post_meta( (int) $post_id, $selector, $value );
		update_post_meta( (int) $post_id, '_' . $selector, is_array( $field ) ? $field['key'] : '' );

		return true;
	}
}

if ( ! function_exists( 'get_field' ) ) {
	/**
	 * Read a field value back.
	 *
	 * @param string $selector     Field key or name.
	 * @param mixed  $post_id      Post ID.
	 * @param bool   $format_value Whether to format (ignored; the double stores raw).
	 * @return mixed The stored value, or null.
	 */
	function get_field( string $selector, mixed $post_id = false, bool $format_value = true ): mixed {
		$field = acf_get_field( $selector );
		$name  = is_array( $field ) ? $field['name'] : $selector;

		$value = get_post_meta( (int) $post_id, $name, true );

		return '' === $value ? null : $value;
	}
}

if ( ! function_exists( 'get_field_objects' ) ) {
	/**
	 * Return the field objects for every registered field with a reference on the post.
	 *
	 * @param mixed $post_id Post ID.
	 * @return array<string, array<string, mixed>>|false Field objects keyed by name, or false.
	 */
	function get_field_objects( mixed $post_id = false ): array|false {
		$fields  = $GLOBALS['_bricks_mcp_test_acf_fields'] ?? array();
		$objects = array();

		foreach ( $fields as $field ) {
			$reference = get_post_meta( (int) $post_id, '_' . $field['name'], true );
			if ( $reference !== $field['key'] ) {
				continue;
			}

			$object          = $field;
			$object['value'] = get_field( $field['key'], $post_id );

			$objects[ $field['name'] ] = $object;
		}

		return array() === $objects ? false : $objects;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
