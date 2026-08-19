<?php
/**
 * WordPress Abilities API test doubles.
 *
 * Captures registrations so a test can assert on what a client would actually be offered, and on
 * what the permission and execute callbacks do when invoked.
 *
 * @package BricksMCP\Tests
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Reset captured ability registrations.
 *
 * @return void
 */
function bricks_mcp_test_reset_abilities(): void {
	$GLOBALS['_bricks_mcp_test_abilities']           = array();
	$GLOBALS['_bricks_mcp_test_ability_categories']  = array();
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Capture an ability registration.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability definition.
	 * @return bool True.
	 */
	function wp_register_ability( string $name, array $args = array() ): bool {
		$GLOBALS['_bricks_mcp_test_abilities'][ $name ] = $args;

		return true;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/**
	 * Capture an ability category registration.
	 *
	 * @param string               $slug Category slug.
	 * @param array<string, mixed> $args Category definition.
	 * @return bool True.
	 */
	function wp_register_ability_category( string $slug, array $args = array() ): bool {
		$GLOBALS['_bricks_mcp_test_ability_categories'][ $slug ] = $args;

		return true;
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
