<?php
/**
 * Minimal \Bricks\* class doubles for unit tests without the Bricks theme installed.
 *
 * Only the surface BricksService actually touches is modelled. Behaviour is driven through
 * $GLOBALS so the doubles stay stateless between test cases, and every call is recorded so
 * tests can assert on what was actually invoked rather than grepping source.
 *
 * Signatures deliberately mirror the real Bricks theme, including its loose typing, so that
 * an argument-order mistake fails here the same way it would in production.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

namespace Bricks {

	/**
	 * Presence of this class is what BricksService::is_bricks_active() tests for.
	 */
	class Elements {}

	/**
	 * Template area resolution.
	 */
	class Templates {

		/**
		 * Real Bricks returns '' for a regular (non-template) page.
		 *
		 * @param int $post_id Post ID.
		 * @return string Bricks content area.
		 */
		public static function get_template_type( $post_id = 0 ) {
			return $GLOBALS['_bricks_mcp_test_template_type'] ?? '';
		}
	}

	/**
	 * Element data reads.
	 */
	class Database {

		/**
		 * Read element data for a content area.
		 *
		 * @param int    $post_id      Post ID.
		 * @param string $content_area Bricks content area.
		 * @return array<int, array<string, mixed>> Elements.
		 */
		public static function get_data( $post_id = 0, $content_area = '' ) {
			$GLOBALS['_bricks_mcp_test_get_data_calls'][] = array( $post_id, $content_area );
			return $GLOBALS['_bricks_mcp_test_elements'] ?? array();
		}
	}

	/**
	 * CSS accumulator. Mirrors the real class' pre-declared keys.
	 */
	class Assets {

		/**
		 * Post ID read by the template-preview path.
		 *
		 * @var int
		 */
		public static $post_id = 0;

		/**
		 * Per-area inline CSS accumulator. Never reset by Bricks itself.
		 *
		 * @var array<string, string>
		 */
		public static $inline_css = array();

		/**
		 * Accumulator-only method. Writes no file - calling this instead of
		 * Assets_Files::generate_post_css_file() is the bug under test.
		 *
		 * @param array<int, array<string, mixed>> $elements Elements.
		 * @param string                           $css_type Content area.
		 * @return void
		 */
		public static function generate_css_from_elements( $elements, $css_type ) {
			$GLOBALS['_bricks_mcp_test_legacy_calls'][] = array( $elements, $css_type );
		}
	}

	/**
	 * The only Bricks API that writes post-<id>.min.css to disk.
	 */
	class Assets_Files {

		/**
		 * Record the call, then return a file name (or honour a configured failure).
		 *
		 * @param int                              $post_id      Post ID.
		 * @param string                           $content_type Bricks content area.
		 * @param array<int, array<string, mixed>> $elements     Elements.
		 * @return string|null Written file name, or null when nothing was written.
		 *
		 * @throws \RuntimeException When the test configures a failure.
		 */
		public static function generate_post_css_file( $post_id, $content_type, $elements ) {
			$GLOBALS['_bricks_mcp_test_generate_calls'][] = array(
				'post_id'        => $post_id,
				'content_type'   => $content_type,
				'elements'       => $elements,
				'assets_post_id' => Assets::$post_id,
				'inline_css'     => Assets::$inline_css,
			);

			if ( isset( $GLOBALS['_bricks_mcp_test_generate_throws'] ) ) {
				throw new \RuntimeException( (string) $GLOBALS['_bricks_mcp_test_generate_throws'] );
			}

			// array_key_exists, not ??, so a configured null is honoured rather than defaulted.
			if ( array_key_exists( '_bricks_mcp_test_generate_return', $GLOBALS ) ) {
				return $GLOBALS['_bricks_mcp_test_generate_return'];
			}

			return "post-{$post_id}.min.css";
		}
	}
}
