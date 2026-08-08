<?php
/**
 * Minimal Core Framework doubles plus a $wpdb double, for unit tests without WordPress or the
 * Core Framework plugin installed.
 *
 * Only the surface CoreFrameworkService actually touches is modelled: the presence signals it
 * gates on (CORE_FRAMEWORK_ABSOLUTE, \CoreFramework\Helper), the cache purge its write paths
 * call, and the four $wpdb methods it uses.
 *
 * The $wpdb double does not parse SQL. prepare() returns an encoded {query, args} envelope which
 * get_var()/get_row() decode, so a test asserts on which row was addressed rather than on the
 * exact SQL text — mirroring how the Bricks doubles record calls instead of matching source.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

namespace {

	if ( ! defined( 'CORE_FRAMEWORK_ABSOLUTE' ) ) {
		define( 'CORE_FRAMEWORK_ABSOLUTE', '/tmp/wordpress/wp-content/plugins/core-framework/core-framework.php' );
	}

	if ( ! class_exists( 'wpdb' ) ) {
		/**
		 * In-memory stand-in for the WordPress database handle.
		 */
		class wpdb {

			/**
			 * Site table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Last database error, mirroring the real property.
			 *
			 * @var string
			 */
			public $last_error = '';

			/**
			 * Encode a query and its bound arguments.
			 *
			 * @param string $query Query template.
			 * @param mixed  ...$args Bound arguments.
			 * @return string Encoded envelope.
			 */
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}

				return (string) json_encode( array( 'q' => $query, 'a' => array_values( $args ) ) );
			}

			/**
			 * Decode an envelope produced by prepare().
			 *
			 * @param string $query Encoded envelope, or a raw query.
			 * @return array{q: string, a: array<int, mixed>} Decoded query.
			 */
			private function decode( $query ) {
				$decoded = json_decode( (string) $query, true );

				if ( is_array( $decoded ) && isset( $decoded['q'] ) ) {
					return array(
						'q' => (string) $decoded['q'],
						'a' => (array) ( $decoded['a'] ?? array() ),
					);
				}

				return array(
					'q' => (string) $query,
					'a' => array(),
				);
			}

			/**
			 * Only the SHOW TABLES existence probe is modelled.
			 *
			 * @param string $query Encoded query.
			 * @return string|null Table name when it exists, null otherwise.
			 */
			public function get_var( $query ) {
				$decoded = $this->decode( $query );

				if ( false === strpos( $decoded['q'], 'SHOW TABLES' ) ) {
					return null;
				}

				$wanted = (string) ( $decoded['a'][0] ?? '' );

				if ( empty( $GLOBALS['_bricks_mcp_test_cf_table_exists'] ) ) {
					return null;
				}

				return $wanted === $this->prefix . 'core_framework_presets' ? $wanted : null;
			}

			/**
			 * Fetch a preset row by the id bound into the query.
			 *
			 * @param string $query Encoded query.
			 * @return object|null Row, or null when absent.
			 */
			public function get_row( $query ) {
				$decoded = $this->decode( $query );
				$id      = (string) ( $decoded['a'][0] ?? '' );
				$row     = $GLOBALS['_bricks_mcp_test_cf_presets'][ $id ] ?? null;

				return is_array( $row ) ? (object) $row : null;
			}

			/**
			 * Update a preset row.
			 *
			 * @param string               $table        Table name.
			 * @param array<string, mixed> $data         Column values.
			 * @param array<string, mixed> $where        Where clause.
			 * @param array<int, string>   $format       Value formats.
			 * @param array<int, string>   $where_format Where formats.
			 * @return int|false Rows affected, or false on failure.
			 */
			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$GLOBALS['_bricks_mcp_test_cf_update_calls'][] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);

				if ( ! empty( $GLOBALS['_bricks_mcp_test_cf_update_fails'] ) ) {
					$this->last_error = 'forced failure';
					return false;
				}

				$id = (string) ( $where['id'] ?? '' );

				if ( ! isset( $GLOBALS['_bricks_mcp_test_cf_presets'][ $id ] ) ) {
					return 0;
				}

				$GLOBALS['_bricks_mcp_test_cf_presets'][ $id ] = array_merge(
					$GLOBALS['_bricks_mcp_test_cf_presets'][ $id ],
					$data
				);

				return 1;
			}

			/**
			 * Never called by CoreFrameworkService; present so an accidental insert is loud.
			 *
			 * @param string               $table Table name.
			 * @param array<string, mixed> $data  Column values.
			 * @return int|false Always false.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function insert( $table, $data ) {
				throw new \RuntimeException( 'CoreFrameworkService must never insert a preset row.' );
			}

			/**
			 * Never called by CoreFrameworkService; present so an accidental delete is loud.
			 *
			 * @param string               $table Table name.
			 * @param array<string, mixed> $where Where clause.
			 * @return int|false Always false.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function delete( $table, $where ) {
				throw new \RuntimeException( 'CoreFrameworkService must never delete a preset row.' );
			}
		}
	}

	if ( ! function_exists( 'bricks_mcp_test_reset_core_framework' ) ) {
		/**
		 * Install a $wpdb double holding one preset row and point Core Framework at it.
		 *
		 * @param array<string, mixed>|null $preset    Preset structure, or null to store no row.
		 * @param string                    $preset_id Preset id.
		 * @return void
		 */
		function bricks_mcp_test_reset_core_framework( ?array $preset = null, string $preset_id = 'testpreset' ): void {
			$GLOBALS['_bricks_mcp_test_cf_table_exists'] = true;
			$GLOBALS['_bricks_mcp_test_cf_update_fails'] = false;
			$GLOBALS['_bricks_mcp_test_cf_update_calls'] = array();
			$GLOBALS['_bricks_mcp_test_cf_presets']      = array();
			$GLOBALS['_bricks_mcp_test_cf_purges']       = 0;
			$GLOBALS['wpdb']                             = new \wpdb();

			if ( null !== $preset ) {
				$GLOBALS['_bricks_mcp_test_cf_presets'][ $preset_id ] = array(
					'id'   => $preset_id,
					'time' => '2026-01-01 00:00:00',
					'data' => (string) json_encode( $preset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				);
			}

			update_option( 'core_framework_main', array( 'selected_id' => $preset_id ) );
		}
	}

	if ( ! function_exists( 'bricks_mcp_test_cf_stored_preset' ) ) {
		/**
		 * Read back what the service actually wrote, decoded.
		 *
		 * @param string $preset_id Preset id.
		 * @return array<string, mixed>|null Stored preset, or null when absent.
		 */
		function bricks_mcp_test_cf_stored_preset( string $preset_id = 'testpreset' ): ?array {
			$row = $GLOBALS['_bricks_mcp_test_cf_presets'][ $preset_id ] ?? null;

			if ( ! is_array( $row ) || ! isset( $row['data'] ) ) {
				return null;
			}

			$decoded = json_decode( (string) $row['data'], true );

			return is_array( $decoded ) ? $decoded : null;
		}
	}

	if ( ! function_exists( 'bricks_mcp_test_cf_raw_preset' ) ) {
		/**
		 * Read back the stored JSON blob verbatim, for byte-level drift assertions.
		 *
		 * @param string $preset_id Preset id.
		 * @return string Stored blob, or '' when absent.
		 */
		function bricks_mcp_test_cf_raw_preset( string $preset_id = 'testpreset' ): string {
			return (string) ( $GLOBALS['_bricks_mcp_test_cf_presets'][ $preset_id ]['data'] ?? '' );
		}
	}

	if ( ! function_exists( 'CoreFramework' ) ) {
		/**
		 * Core Framework's global accessor, used here only for its cache purge.
		 *
		 * @return \CoreFramework\Functions Functions double.
		 */
		function CoreFramework(): \CoreFramework\Functions {
			return new \CoreFramework\Functions();
		}
	}
}

namespace CoreFramework {

	/**
	 * Presence of this class is what CoreFrameworkService::is_core_framework_active() tests for.
	 *
	 * Deliberately carries no stylesheet-generation method: Core Framework 1.10 has none, and a
	 * double that invented one would let a test pass against behaviour the plugin cannot perform.
	 */
	class Helper {

		/**
		 * Absolute path of the generated stylesheet.
		 *
		 * @return string Stylesheet path.
		 */
		public function getStylesheetPath(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			return '/tmp/wordpress/wp-content/plugins/core-framework/assets/public/css/core_framework.css';
		}
	}

	/**
	 * Core Framework's shared functions; only the cache purge is modelled.
	 */
	class Functions {

		/**
		 * Record that Core Framework's own purge ran.
		 *
		 * @return void
		 */
		public function purge_cache(): void {
			$GLOBALS['_bricks_mcp_test_cf_purges'] = ( $GLOBALS['_bricks_mcp_test_cf_purges'] ?? 0 ) + 1;
		}
	}
}
