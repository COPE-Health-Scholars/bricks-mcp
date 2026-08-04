<?php
/**
 * Unit tests: every path writing page customCss enforces the same gate and sanitizer.
 *
 * code:set_page_css gates on dangerous_actions, caps CSS at 100 KB and rejects six injection
 * patterns (hardening added for upstream GitHub #11). Two sibling paths bypassed all of it:
 *   - page:update_settings / content:update_settings — customCss was allowlisted, absent from the
 *     gated key set, unsanitized, and written raw;
 *   - template:import / import_url — pageSettings stripped only the script keys, so an imported
 *     template could carry arbitrary page CSS onto the site.
 *
 * The same CSS refused by the documented tool was therefore accepted by two others.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\BricksService;
use PHPUnit\Framework\TestCase;

/**
 * Gate and sanitizer parity across all customCss write paths.
 */
final class CustomCssGateTest extends TestCase {

	/**
	 * Page settings meta key.
	 *
	 * @var string
	 */
	private const SETTINGS_KEY = '_bricks_page_settings';

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 3 ) . '/stubs/bricks-classes.php';

		bricks_mcp_test_reset_posts();
		$GLOBALS['_bricks_mcp_test_options'] = array();
		unset( $GLOBALS['_bricks_mcp_test_settings'] );

		\Bricks\Assets::$inline_css = array( 'content' => '' );
	}

	/**
	 * Reset after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_bricks_mcp_test_settings'] );
		parent::tearDown();
	}

	/**
	 * Turn the dangerous-actions toggle on.
	 *
	 * @return void
	 */
	private function enable_dangerous_actions(): void {
		$GLOBALS['_bricks_mcp_test_settings'] = array( 'dangerous_actions' => true );
	}

	/**
	 * Read stored customCss for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Stored CSS, or '' when absent.
	 */
	private function stored_css( int $post_id ): string {
		$settings = get_post_meta( $post_id, self::SETTINGS_KEY, true );
		return is_array( $settings ) ? (string) ( $settings['customCss'] ?? '' ) : '';
	}

	/**
	 * CSS payloads the documented tool rejects.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_dangerous_css(): array {
		return array(
			'javascript url' => array( 'a { background: url(javascript:alert(1)); }' ),
			'expression'     => array( 'a { width: expression(alert(1)); }' ),
			'import'         => array( '@import "https://evil.test/x.css";' ),
			'data url'       => array( 'a { background: url(data:text/html,<script>alert(1)</script>); }' ),
			'moz binding'    => array( 'a { -moz-binding: url(https://evil.test/x.xml); }' ),
		);
	}

	// -----------------------------------------------------------------------
	// Baseline: the documented path still behaves as before.
	// -----------------------------------------------------------------------

	/**
	 * set_page_css requires the dangerous-actions toggle.
	 *
	 * @return void
	 */
	public function test_set_page_css_requires_dangerous_actions(): void {
		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$result = $service->update_page_css( $post_id, 'body { color: red; }' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'dangerous_actions_disabled', $result->get_error_code() );
	}

	/**
	 * set_page_css rejects each dangerous pattern even with the toggle on.
	 *
	 * @param string $css Dangerous CSS.
	 * @return void
	 *
	 * @dataProvider provide_dangerous_css
	 */
	public function test_set_page_css_rejects_dangerous_patterns( string $css ): void {
		$this->enable_dangerous_actions();

		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$result = $service->update_page_css( $post_id, $css );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'css_unsafe_content', $result->get_error_code() );
		$this->assertSame( '', $this->stored_css( $post_id ) );
	}

	/**
	 * The 100 KB cap holds.
	 *
	 * @return void
	 */
	public function test_set_page_css_enforces_size_cap(): void {
		$this->enable_dangerous_actions();

		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$result = $service->update_page_css( $post_id, str_repeat( 'a', 102401 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'css_too_large', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Bypass 1: page:update_settings / content:update_settings.
	// -----------------------------------------------------------------------

	/**
	 * update_page_settings must not write customCss without the toggle.
	 *
	 * @return void
	 */
	public function test_update_page_settings_gates_custom_css(): void {
		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$result = $service->update_page_settings( $post_id, array( 'customCss' => 'body { color: red; }' ) );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', $this->stored_css( $post_id ), 'customCss must not be written while dangerous actions are disabled' );
		$this->assertContains( 'customCss', array_column( $result['rejected'] ?? array(), 'key' ), 'The rejection must be reported, not silent' );
	}

	/**
	 * update_page_settings must apply the same pattern rejection.
	 *
	 * @param string $css Dangerous CSS.
	 * @return void
	 *
	 * @dataProvider provide_dangerous_css
	 */
	public function test_update_page_settings_rejects_dangerous_patterns( string $css ): void {
		$this->enable_dangerous_actions();

		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$result = $service->update_page_settings( $post_id, array( 'customCss' => $css ) );

		$this->assertSame( '', $this->stored_css( $post_id ), 'Dangerous CSS must never reach storage through this path either' );
		$this->assertContains( 'customCss', array_column( $result['rejected'] ?? array(), 'key' ) );
	}

	/**
	 * The size cap applies here too.
	 *
	 * @return void
	 */
	public function test_update_page_settings_enforces_size_cap(): void {
		$this->enable_dangerous_actions();

		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$result = $service->update_page_settings( $post_id, array( 'customCss' => str_repeat( 'a', 102401 ) ) );

		$this->assertSame( '', $this->stored_css( $post_id ) );
		$this->assertContains( 'customCss', array_column( $result['rejected'] ?? array(), 'key' ) );
	}

	/**
	 * Safe CSS with the toggle on still works — the gate must not block legitimate use.
	 *
	 * @return void
	 */
	public function test_update_page_settings_allows_safe_css_when_enabled(): void {
		$this->enable_dangerous_actions();

		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$service->update_page_settings( $post_id, array( 'customCss' => '.hero { color: red; }' ) );

		$this->assertSame( '.hero { color: red; }', $this->stored_css( $post_id ) );
	}

	/**
	 * Ungated keys must keep working regardless of the toggle.
	 *
	 * @return void
	 */
	public function test_update_page_settings_still_writes_ungated_keys(): void {
		$service = new BricksService();
		$post_id = wp_insert_post( array( 'post_type' => 'page' ) );

		$service->update_page_settings( $post_id, array( 'bodyClasses' => 'my-class' ) );

		$settings = get_post_meta( $post_id, self::SETTINGS_KEY, true );
		$this->assertSame( 'my-class', $settings['bodyClasses'] ?? null );
	}

	// -----------------------------------------------------------------------
	// Bypass 2: template:import / import_url.
	// -----------------------------------------------------------------------

	/**
	 * An imported template must not carry customCss in while the toggle is off.
	 *
	 * @return void
	 */
	public function test_import_template_strips_custom_css_when_gated(): void {
		$service = new BricksService();

		$result = $service->import_template(
			array(
				'title'        => 'Imported',
				'content'      => array(
					array(
						'id'       => 'aaa111',
						'name'     => 'section',
						'parent'   => 0,
						'children' => array(),
						'settings' => array(),
					),
				),
				'pageSettings' => array( 'customCss' => 'body { color: red; }' ),
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '', $this->stored_css( (int) $result['template_id'] ), 'Imported templates must not smuggle in page CSS' );
	}

	/**
	 * Import must reject dangerous CSS even with the toggle on.
	 *
	 * @return void
	 */
	public function test_import_template_rejects_dangerous_css_when_enabled(): void {
		$this->enable_dangerous_actions();

		$service = new BricksService();

		$result = $service->import_template(
			array
			(
				'title'        => 'Imported',
				'content'      => array(
					array(
						'id'       => 'aaa111',
						'name'     => 'section',
						'parent'   => 0,
						'children' => array(),
						'settings' => array(),
					),
				),
				'pageSettings' => array( 'customCss' => '@import "https://evil.test/x.css";' ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'css_unsafe_content', $result->get_error_code() );
	}
}
