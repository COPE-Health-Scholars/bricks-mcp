<?php
/**
 * Main plugin class.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin class.
 *
 * The main orchestrator class for the plugin.
 * Uses singleton pattern to ensure only one instance runs.
 */
final class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * MCP Server instance.
	 *
	 * @var MCP\Server|null
	 */
	private ?MCP\Server $mcp_server = null;

	/**
	 * Update checker instance.
	 *
	 * @var Updates\UpdateChecker|null
	 */
	private ?Updates\UpdateChecker $update_checker = null;

	/**
	 * Admin settings instance.
	 *
	 * @var Admin\Settings|null
	 */
	private ?Admin\Settings $admin_settings = null;

	/**
	 * WPForms abilities instance.
	 *
	 * @var Abilities\WPFormsAbilities|null
	 */
	private ?Abilities\WPFormsAbilities $wpforms_abilities = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self The plugin instance.
	 */
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception When attempting to unserialize.
	 * @return void
	 */
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	private function init(): void {
		// Migrate stored settings (strip orphaned keys from previous versions).
		$this->migrate_settings();

		// Initialize internationalization.
		$this->init_i18n();

		// Initialize update checker (unconditional — fires on admin and cron).
		$this->update_checker = new Updates\UpdateChecker();
		$this->update_checker->init();

		// Initialize MCP server.
		$this->init_mcp_server();

		// Expose the WordPress-wide operations through the Abilities API as well as MCP.
		$this->init_abilities();

		// Initialize admin functionality only in admin context.
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Register hooks.
		$this->register_hooks();
	}

	/**
	 * Initialize internationalization.
	 *
	 * @return void
	 */
	private function init_i18n(): void {
		$i18n = new I18n();
		$i18n->init();
	}

	/**
	 * Initialize MCP server.
	 *
	 * @return void
	 */
	private function init_mcp_server(): void {
		$this->mcp_server = new MCP\Server();
		$this->mcp_server->init();
	}

	/**
	 * Initialize WordPress Abilities API registrations.
	 *
	 * No-op on a site without the Abilities API; WPFormsAbilities checks for it before hooking.
	 *
	 * @return void
	 */
	private function init_abilities(): void {
		$this->wpforms_abilities = new Abilities\WPFormsAbilities();
		$this->wpforms_abilities->init();
	}

	/**
	 * Initialize admin functionality.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		$this->admin_settings = new Admin\Settings();
		$this->admin_settings->init();

		$site_health = new Admin\SiteHealth();
		$site_health->init();
	}

	/**
	 * Migrate stored settings to strip orphaned keys from previous versions.
	 *
	 * Removes rate_limit, rate_limit_window, and allowed_endpoints keys
	 * that are no longer used. No-op after first run (keys already removed).
	 *
	 * @return void
	 */
	private function migrate_settings(): void {
		$settings = get_option( 'bricks_mcp_settings', [] );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$dirty = false;

		foreach ( [ 'rate_limit', 'rate_limit_window', 'allowed_endpoints' ] as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				unset( $settings[ $key ] );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			update_option( 'bricks_mcp_settings', $settings );
		}
	}

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Add plugin action links.
		add_filter(
			'plugin_action_links_' . BRICKS_MCP_PLUGIN_BASENAME,
			[ $this, 'add_action_links' ]
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string> Modified action links.
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=bricks-mcp' ) ),
			esc_html__( 'Settings', 'bricks-mcp' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Get the update checker instance.
	 *
	 * @return Updates\UpdateChecker|null The update checker instance.
	 */
	public function get_update_checker(): ?Updates\UpdateChecker {
		return $this->update_checker;
	}

	/**
	 * Get the MCP server instance.
	 *
	 * @return MCP\Server|null The MCP server instance.
	 */
	public function get_mcp_server(): ?MCP\Server {
		return $this->mcp_server;
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string The plugin version.
	 */
	public function get_version(): string {
		return BRICKS_MCP_VERSION;
	}
}
