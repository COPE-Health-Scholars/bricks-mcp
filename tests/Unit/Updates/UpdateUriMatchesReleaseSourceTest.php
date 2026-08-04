<?php
/**
 * Guard: the plugin's Update URI header must name this fork's repository.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\Updates;

use PHPUnit\Framework\TestCase;
use BricksMCP\Updates\UpdateChecker;

/**
 * Tests that the update *source* cannot drift back to upstream.
 *
 * This fork takes its releases from COPE-Health-Scholars/bricks-mcp. The
 * `Update URI` header in bricks-mcp.php is a comment, so it cannot reference
 * UpdateChecker::GITHUB_REPO and nothing but a test can keep the two in step.
 *
 * The failure this prevents is not cosmetic: WordPress derives the
 * `update_plugins_{$hostname}` hook from the header, and UpdateChecker fetches
 * releases from GITHUB_REPO. If either names upstream, an upstream release
 * presents as an update to this build and overwrites it — silently reinstating
 * every bug fixed in the fork.
 */
final class UpdateUriMatchesReleaseSourceTest extends TestCase {

	/**
	 * The repository this fork must never take updates from.
	 *
	 * @var string
	 */
	private const UPSTREAM_REPO = 'cristianuibar/bricks-mcp';

	/**
	 * Reset the recorded filter registrations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_bricks_mcp_test_added_filters'] = [];
	}

	/**
	 * Clean up recorded filter registrations.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		parent::tearDown();

		$GLOBALS['_bricks_mcp_test_added_filters'] = [];
	}

	/**
	 * Read the `Update URI` value out of the real plugin header.
	 *
	 * @return string The declared update URI.
	 */
	private function read_declared_update_uri(): string {
		$main_file = BRICKS_MCP_PLUGIN_DIR . 'bricks-mcp.php';

		$this->assertFileExists( $main_file, 'the plugin main file must exist' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source = (string) file_get_contents( $main_file );

		$matched = preg_match_all( '/^\s*\*\s*Update URI:\s*(\S+)\s*$/m', $source, $matches );

		$this->assertSame(
			1,
			$matched,
			'bricks-mcp.php must declare exactly one Update URI header'
		);

		return $matches[1][0];
	}

	/**
	 * Extract "owner/repo" from a GitHub URL.
	 *
	 * @param string $url A GitHub repository URL.
	 * @return string The owner/repo pair.
	 */
	private function repo_from_url( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
	}

	/**
	 * Extract the hostname from a URL.
	 *
	 * @param string $url Any absolute URL.
	 * @return string The hostname.
	 */
	private function host_from_url( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return (string) parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Test: the header names this fork, not upstream.
	 *
	 * @return void
	 */
	public function test_update_uri_header_names_this_fork(): void {
		$uri = $this->read_declared_update_uri();

		$this->assertSame(
			UpdateChecker::GITHUB_REPO,
			$this->repo_from_url( $uri ),
			'the Update URI header must name the same repository UpdateChecker fetches releases from'
		);
	}

	/**
	 * Test: neither the header nor the release source is upstream.
	 *
	 * Asserted separately from the match above, because both could agree on
	 * upstream and that would be exactly the bug.
	 *
	 * @return void
	 */
	public function test_neither_header_nor_release_source_is_upstream(): void {
		$this->assertNotSame(
			self::UPSTREAM_REPO,
			UpdateChecker::GITHUB_REPO,
			'this fork must not fetch its releases from upstream'
		);

		$this->assertNotSame(
			self::UPSTREAM_REPO,
			$this->repo_from_url( $this->read_declared_update_uri() ),
			'the Update URI header must not point at upstream'
		);
	}

	/**
	 * Test: the hooked update filter matches the header's hostname.
	 *
	 * WordPress builds the hook name from the Update URI host, so a header on
	 * any other host would leave `update_plugins_github.com` never firing —
	 * an update mechanism that is silently dead rather than loudly broken.
	 *
	 * @return void
	 */
	public function test_registered_update_filter_matches_header_hostname(): void {
		$host = $this->host_from_url( $this->read_declared_update_uri() );

		$this->assertSame( 'github.com', $host, 'releases are served from GitHub' );

		( new UpdateChecker() )->init();

		$hooks = array_column( $GLOBALS['_bricks_mcp_test_added_filters'], 'hook' );

		$this->assertContains(
			'update_plugins_' . $host,
			$hooks,
			'UpdateChecker must hook the filter WordPress derives from the Update URI host'
		);
	}

	/**
	 * Test: the release API URL is built from the repo constant.
	 *
	 * @return void
	 */
	public function test_release_api_url_is_derived_from_the_repo_constant(): void {
		$reflection = new \ReflectionClass( UpdateChecker::class );
		$api_url    = (string) $reflection->getConstant( 'GITHUB_API_URL' );

		$this->assertSame(
			'https://api.github.com/repos/' . UpdateChecker::GITHUB_REPO . '/releases/latest',
			$api_url,
			'GITHUB_API_URL must be derived from GITHUB_REPO so the two cannot disagree'
		);
	}
}
