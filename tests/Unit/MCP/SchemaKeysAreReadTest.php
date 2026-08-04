<?php
/**
 * Guard against the plugin's most common defect class: a tool schema advertising a parameter
 * that no handler ever reads.
 *
 * A full audit of all 23 tools found ~30 of these. Every one validated cleanly, was never read,
 * and the call reported success having silently discarded the payload. Note that
 * `additionalProperties: false` would NOT have caught a single one — in each case the key WAS
 * declared; nothing consumed it. The only durable guard is to check, for every advertised key,
 * that some code actually reads it.
 *
 * Deliberate limitation: this scans the whole includes/ tree rather than resolving each tool's
 * call graph, so it detects "no code anywhere reads this key" — the strongest and most common
 * form (theme_style's `active` and media's `filename` each had zero repo-wide reads). It cannot
 * catch a key read by a DIFFERENT tool's handler than the one declaring it. Narrowing that would
 * need real call-graph analysis; the cheap check already covers the majority and cannot produce
 * false alarms.
 *
 * @package BricksMCP\Tests\Unit\MCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use PHPUnit\Framework\TestCase;

/**
 * Every advertised schema key must be read by some handler.
 */
final class SchemaKeysAreReadTest extends TestCase {

	/**
	 * Keys that are legitimately never read as `$args['key']`.
	 *
	 * Every entry needs a reason. Keep this list short — a growing exemption list means the
	 * schemas are drifting from the handlers again.
	 *
	 * @var array<string, string>
	 */
	private const EXEMPT = array(
		// The dispatcher switch reads this via a local, not $args, in several tools.
		'action' => 'Consumed by every dispatcher as $action = $args[\'action\'] and matched, not re-read per key.',
		// Declared so callers can pass a Bricks area name through; resolved from post meta.
		'domain' => 'Routing-only key consumed by tool_design() to pick a sub-dispatcher.',
	);

	/**
	 * Concatenated plugin source, used to check whether a key is read anywhere.
	 *
	 * @var string
	 */
	private static string $source = '';

	/**
	 * Load all plugin PHP source once.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$root  = dirname( __DIR__, 3 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				self::$source .= (string) file_get_contents( $file->getPathname() );
			}
		}
	}

	/**
	 * All registered tools with their input schemas.
	 *
	 * Reads the private registry directly so hidden tools are covered too — most tools are
	 * absent from VISIBLE_TOOL_NAMES but remain callable, and their schemas still form a
	 * contract.
	 *
	 * @return array<string, array<string, mixed>> Tool name => input schema.
	 */
	private function registered_tools(): array {
		require_once dirname( __DIR__, 2 ) . '/stubs/bricks-classes.php';

		$router = new Router();

		/*
		 * The constructor defers Bricks tool registration to the after_setup_theme action, which
		 * never fires under the test stubs — without this call only the three default tools are
		 * registered and the key check below would pass vacuously.
		 */
		$router->register_bricks_tools();

		$reflection = new \ReflectionProperty( $router, 'tools' );
		$reflection->setAccessible( true );

		$out = array();
		foreach ( (array) $reflection->getValue( $router ) as $name => $tool ) {
			$schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? array();
			if ( ! empty( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				$out[ (string) $name ] = $schema;
			}
		}

		return $out;
	}

	/**
	 * Sanity check: the registry must be readable and non-trivial, or this whole test is vacuous.
	 *
	 * @return void
	 */
	public function test_tool_registry_is_discoverable(): void {
		$tools = $this->registered_tools();

		$this->assertGreaterThan(
			10,
			count( $tools ),
			'Expected the tool registry to expose many tools; if this fails the key check below is silently testing nothing'
		);
	}

	/**
	 * No advertised key may be entirely unread by the plugin.
	 *
	 * @return void
	 */
	public function test_every_advertised_schema_key_is_read_somewhere(): void {
		$unread = array();

		foreach ( $this->registered_tools() as $tool => $schema ) {
			foreach ( array_keys( $schema['properties'] ) as $key ) {
				$key = (string) $key;

				if ( array_key_exists( $key, self::EXEMPT ) ) {
					continue;
				}

				if ( ! $this->is_key_read( $key ) ) {
					$unread[] = "{$tool}.{$key}";
				}
			}
		}

		$this->assertSame(
			array(),
			$unread,
			"These schema keys are advertised to callers but no handler reads them, so the payload is silently discarded:\n  "
				. implode( "\n  ", $unread )
				. "\n\nEither read the key in the handler, remove it from the schema, or add it to SchemaKeysAreReadTest::EXEMPT with a reason."
		);
	}

	/**
	 * Whether any plugin source reads this key off an args-like array.
	 *
	 * @param string $key Schema property name.
	 * @return bool True when some code reads it.
	 */
	private function is_key_read( string $key ): bool {
		$quoted = preg_quote( $key, '/' );

		/*
		 * Two accepted spellings of "this key is consumed":
		 *   1. a direct subscript read - $args['key'], $data['key'], $settings['key'];
		 *   2. membership in an allowlist array - 'key', / 'key') / 'key'].
		 *
		 * Crucially neither may match the schema DECLARATION itself, which is `'key' => array(`
		 * and also lives in the scanned source. An earlier version of this check accepted
		 * `'key' =>` and so reported every key as read, passing vacuously. Both patterns below
		 * are anchored on what CANNOT follow a declaration: a subscript bracket, or a comma /
		 * closing bracket rather than `=>`.
		 */
		$patterns = array(
			'/\$\w+\s*\[\s*[\'"]' . $quoted . '[\'"]\s*\]/',
			'/[\'"]' . $quoted . '[\'"]\s*[,)\]]/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, self::$source ) ) {
				return true;
			}
		}

		return false;
	}
}
