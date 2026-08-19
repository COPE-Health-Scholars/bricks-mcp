<?php
/**
 * WPForms spam-protection tests.
 *
 * Spam protection in WPForms has two halves that fail independently: the keyword LIST is
 * site-global, and the filter that reads it is a per-form toggle that ships off. A site can
 * therefore carry a long, well-tuned list that protects nothing. These tests pin both halves and
 * the seam between them.
 *
 * @package BricksMCP\Tests\Unit\MCP\Services
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP\Services;

use BricksMCP\MCP\Services\WPFormsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the global keyword list and the per-form spam audit.
 */
final class WPFormsSpamTest extends TestCase {

	/**
	 * The option WPForms keeps the global list in.
	 *
	 * @var string
	 */
	private const OPTION = 'wpforms_keyword_filter_keywords';

	/**
	 * Service under test.
	 *
	 * @var WPFormsService
	 */
	private WPFormsService $service;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_bricks_mcp_test_options']          = array();
		$GLOBALS['_bricks_mcp_test_option_autoload']  = array();
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = array();
		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		bricks_mcp_test_reset_wpforms();

		$this->service = new WPFormsService();
	}

	/**
	 * Clean up globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		bricks_mcp_test_reset_wpforms();
		$GLOBALS['_bricks_mcp_test_options']          = array();
		$GLOBALS['_bricks_mcp_test_get_posts_return'] = array();

		parent::tearDown();
	}

	/**
	 * The raw stored option value.
	 *
	 * @return mixed Stored value.
	 */
	private function stored_option(): mixed {
		return $GLOBALS['_bricks_mcp_test_options'][ self::OPTION ] ?? null;
	}

	/**
	 * Seed the global list.
	 *
	 * @param array<int, string> $keywords Keywords.
	 * @return void
	 */
	private function seed_keywords( array $keywords ): void {
		$GLOBALS['_bricks_mcp_test_options'][ self::OPTION ] = wp_json_encode( $keywords );
	}

	/**
	 * A never-saved list is not an empty list — five WPForms defaults are live.
	 *
	 * @return void
	 */
	public function test_unsaved_list_reports_the_live_defaults(): void {
		$result = $this->service->get_keywords();

		$this->assertTrue( $result['is_default'] );
		$this->assertContains( 'earn extra cash', $result['keywords'] );
		$this->assertSame( 5, $result['count'] );
	}

	/**
	 * The first add must not wipe the defaults that were protecting the site.
	 *
	 * @return void
	 */
	public function test_first_add_preserves_the_built_in_defaults(): void {
		$result = $this->service->update_keywords( array( 'corGM' ) );

		$this->assertContains( 'corGM', $result['keywords'] );
		$this->assertContains(
			'earn extra cash',
			$result['keywords'],
			'Adding to a never-saved list must carry the built-in defaults forward, not replace them'
		);
		$this->assertSame( 6, $result['count'] );
	}

	/**
	 * WPForms reads this option as JSON. A serialized array would decode to nothing and silently
	 * empty the site's filter.
	 *
	 * @return void
	 */
	public function test_list_is_stored_as_json_with_autoload_off(): void {
		$this->service->update_keywords( array( 'cutt.ly' ), 'replace' );

		$raw = $this->stored_option();

		$this->assertIsString( $raw );
		$this->assertSame( array( 'cutt.ly' ), json_decode( $raw, true ) );
		$this->assertSame(
			'no',
			$GLOBALS['_bricks_mcp_test_option_autoload'][ self::OPTION ] ?? null,
			'WPForms keeps this option out of the autoload set'
		);
	}

	/**
	 * Adding is the default because the list is shared and usually hard-won.
	 *
	 * @return void
	 */
	public function test_add_is_the_default_mode(): void {
		$this->seed_keywords( array( 'viagra' ) );

		$result = $this->service->update_keywords( array( 'corGM' ) );

		$this->assertSame( 'add', $result['mode'] );
		$this->assertSame( array( 'viagra', 'corGM' ), $result['keywords'] );
		$this->assertSame( array( 'corGM' ), $result['added'] );
	}

	/**
	 * Replace is available but must be asked for by name.
	 *
	 * @return void
	 */
	public function test_replace_discards_the_existing_list(): void {
		$this->seed_keywords( array( 'viagra', 'casino' ) );

		$result = $this->service->update_keywords( array( 'corGM' ), 'replace' );

		$this->assertSame( array( 'corGM' ), $result['keywords'] );
	}

	/**
	 * Removal reports what actually went.
	 *
	 * @return void
	 */
	public function test_remove_drops_only_the_named_keywords(): void {
		$this->seed_keywords( array( 'viagra', 'casino', 'corGM' ) );

		$result = $this->service->update_keywords( array( 'casino', 'never-present' ), 'remove' );

		$this->assertSame( array( 'viagra', 'corGM' ), $result['keywords'] );
		$this->assertSame( array( 'casino' ), $result['removed'] );
	}

	/**
	 * WPForms matches keywords case-insensitively, so two spellings are one rule and storing both
	 * only makes the list harder to read.
	 *
	 * @return void
	 */
	public function test_duplicates_are_collapsed_case_insensitively(): void {
		$this->seed_keywords( array( 'corGM' ) );

		$result = $this->service->update_keywords( array( 'CORGM', 'corgm', 'cutt.ly' ) );

		$this->assertSame( array( 'corGM', 'cutt.ly' ), $result['keywords'] );
		$this->assertSame( array( 'cutt.ly' ), $result['added'] );
	}

	/**
	 * Blank rows are a paste artefact, not a keyword that blocks everything.
	 *
	 * @return void
	 */
	public function test_blank_and_whitespace_keywords_are_dropped(): void {
		$result = $this->service->update_keywords( array( '  corGM  ', '', '   ' ), 'replace' );

		$this->assertSame( array( 'corGM' ), $result['keywords'] );
	}

	/**
	 * A patch of nothing usable must not silently overwrite the live list.
	 *
	 * @return void
	 */
	public function test_empty_keyword_patch_is_rejected_without_writing(): void {
		$this->seed_keywords( array( 'viagra' ) );

		$result = $this->service->update_keywords( array( '', '  ' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_no_keywords', $result->get_error_code() );
		$this->assertSame( array( 'viagra' ), json_decode( (string) $this->stored_option(), true ) );
	}

	/**
	 * An unknown mode must not fall through to a destructive default.
	 *
	 * @return void
	 */
	public function test_unknown_mode_is_rejected(): void {
		$result = $this->service->update_keywords( array( 'corGM' ), 'clear' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_wpforms_invalid_mode', $result->get_error_code() );
		$this->assertNull( $this->stored_option() );
	}

	/**
	 * Writing the shared list needs edit rights, not just read rights.
	 *
	 * @return void
	 */
	public function test_keyword_write_requires_the_edit_capability(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_can'] = array( WPFormsService::CAP_WRITE => false );

		$result = $this->service->update_keywords( array( 'corGM' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bricks_mcp_forbidden', $result->get_error_code() );
		$this->assertNull( $this->stored_option() );
	}

	/**
	 * The audit exists for exactly this: a healthy global list, and forms that never switched the
	 * filter on.
	 *
	 * @return void
	 */
	public function test_audit_separates_forms_using_the_list_from_forms_ignoring_it(): void {
		$this->seed_keywords( array( 'viagra', 'corGM' ) );

		$GLOBALS['_bricks_mcp_test_get_posts_return'] = array(
			(object) array(
				'ID'         => 2080,
				'post_title' => 'Legacy Contact',
			),
			(object) array(
				'ID'         => 4302,
				'post_title' => 'Book a Session',
			),
		);

		bricks_mcp_test_seed_wpforms_form(
			2080,
			array(
				'settings' => array(
					'anti_spam'          => array( 'keyword_filter' => array( 'enable' => '1' ) ),
					'store_spam_entries' => '1',
					'antispam_v3'        => '1',
				),
			)
		);
		bricks_mcp_test_seed_wpforms_form(
			4302,
			array(
				'settings' => array(
					'antispam_v3' => '1',
				),
			)
		);

		$audit = $this->service->spam_audit();

		$this->assertSame( array( 'viagra', 'corGM' ), $audit['global_keywords'] );
		$this->assertFalse( $audit['global_keywords_are_default'] );
		$this->assertSame( 2, $audit['form_count'] );
		$this->assertSame( 1, $audit['keyword_filter_enabled_on'] );
		$this->assertSame(
			1,
			$audit['keyword_filter_disabled_on'],
			'A form with the filter off is the failure this audit exists to surface'
		);

		$this->assertTrue( $audit['forms'][0]['keyword_filter'] );
		$this->assertTrue( $audit['forms'][0]['store_spam_entries'] );
		$this->assertFalse( $audit['forms'][1]['keyword_filter'] );
		$this->assertTrue( $audit['forms'][1]['antispam_v3'] );
	}

	/**
	 * The per-form toggle is reachable through the ordinary settings patch, which is what makes
	 * the global list take effect on a given form.
	 *
	 * @return void
	 */
	public function test_per_form_toggle_is_writable_through_update_settings(): void {
		bricks_mcp_test_seed_wpforms_form(
			4302,
			array(
				'settings' => array(
					'form_title' => 'Book a Session',
					'anti_spam'  => array( 'time_limit' => array( 'enable' => '1' ) ),
				),
			)
		);

		$this->service->update_settings(
			4302,
			array(
				'anti_spam'            => array( 'keyword_filter' => array( 'enable' => '1' ) ),
				'store_spam_entries'   => '1',
				'filtering_store_spam' => '1',
			)
		);

		$settings = bricks_mcp_test_stored_wpforms_form( 4302 )['settings'];

		$this->assertSame( '1', $settings['anti_spam']['keyword_filter']['enable'] );
		$this->assertSame(
			'1',
			$settings['anti_spam']['time_limit']['enable'],
			'Enabling one anti-spam sub-toggle must not drop the others'
		);
		$this->assertSame( '1', $settings['store_spam_entries'] );
		$this->assertSame( '1', $settings['filtering_store_spam'] );
	}

	/**
	 * Without the Pro component there is no keyword filter to read, and the bare option is the
	 * only source left.
	 *
	 * @return void
	 */
	public function test_list_falls_back_to_the_raw_option_without_the_pro_component(): void {
		$GLOBALS['_bricks_mcp_test_wpforms_has_keyword_filter'] = false;
		$this->seed_keywords( array( 'viagra' ) );

		$result = $this->service->get_keywords();

		$this->assertSame( array( 'viagra' ), $result['keywords'] );
	}
}
