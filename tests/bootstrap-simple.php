<?php
/**
 * Simple bootstrap for unit tests without WordPress.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Define plugin constants for testing.
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'BRICKS_MCP_VERSION', '1.5.0' );
define( 'BRICKS_MCP_MIN_PHP_VERSION', '8.2' );
define( 'BRICKS_MCP_MIN_WP_VERSION', '6.4' );
define( 'BRICKS_MCP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BRICKS_MCP_PLUGIN_URL', 'http://localhost/wp-content/plugins/bricks-mcp/' );
define( 'BRICKS_MCP_PLUGIN_BASENAME', 'bricks-mcp/bricks-mcp.php' );

// Load global-namespace WordPress function stubs.
require_once __DIR__ . '/stubs/wp-functions.php';

/*
 * Load the WPForms doubles globally rather than from one test file. Router registers the wpforms
 * tool only when wpforms() exists, so defining it here is what puts that tool in front of the
 * schema/handler contract tests — which is the whole point of those tests.
 */
require_once __DIR__ . '/stubs/wpforms-classes.php';
bricks_mcp_test_reset_wpforms();

// Abilities API doubles, so the ability registrations can be asserted on.
require_once __DIR__ . '/stubs/abilities-api.php';
bricks_mcp_test_reset_abilities();

// ACF doubles: update_field()'s key-vs-name behaviour and the _field reference metas.
require_once __DIR__ . '/stubs/acf-functions.php';
bricks_mcp_test_reset_acf();

// Load the autoloader.
require_once BRICKS_MCP_PLUGIN_DIR . 'includes/Autoloader.php';
BricksMCP\Autoloader::register();
