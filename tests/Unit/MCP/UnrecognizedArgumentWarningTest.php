<?php
/**
 * Unit test: undeclared tool arguments are reported back as warnings.
 *
 * The defect this addresses is silence, not permissiveness — a mistyped
 * parameter used to be accepted and ignored, so the call reported success while
 * doing something other than what was asked. These tests therefore assert both
 * halves: the warning appears, AND the call still succeeds. A regression to
 * `additionalProperties: false` would fail the second half.
 *
 * @package BricksMCP\Tests\Unit\MCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Tests\Unit\MCP;

use BricksMCP\MCP\Router;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for unrecognized-argument reporting.
 */
final class UnrecognizedArgumentWarningTest extends TestCase {

	/**
	 * Router under test.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_bricks_mcp_test_current_user_can'] = true;

		$this->router = new Router();
	}

	/**
	 * Clean up globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_bricks_mcp_test_current_user_can'] );

		parent::tearDown();
	}

	/**
	 * Register a throwaway tool whose handler returns a fixed payload.
	 *
	 * @param string               $name    Tool name.
	 * @param array<string, mixed> $props   Declared schema properties.
	 * @param mixed                $returns What the handler returns.
	 * @return void
	 */
	private function register_probe( string $name, array $props, mixed $returns ): void {
		$this->router->register_tool(
			$name,
			'Probe tool for argument reporting.',
			array(
				'type'       => 'object',
				'properties' => $props,
			),
			static fn( array $args ): mixed => $returns
		);
	}

	/**
	 * Call a tool and decode the JSON payload it produced.
	 *
	 * @param string               $name Tool name.
	 * @param array<string, mixed> $args Arguments.
	 * @return mixed Decoded payload.
	 */
	private function call( string $name, array $args ): mixed {
		$response = $this->router->execute_tool( $name, $args );
		$data     = $response->get_data();

		return json_decode( $data['content'][0]['text'], true );
	}

	/**
	 * An undeclared argument must come back named in `warnings`.
	 *
	 * @return void
	 */
	public function test_undeclared_argument_is_named_in_warnings(): void {
		$this->register_probe(
			'probe_named',
			array( 'post_id' => array( 'type' => 'integer' ) ),
			array( 'ok' => true )
		);

		$payload = $this->call(
			'probe_named',
			array(
				'post_id'    => 12,
				'post_idd'   => 12,
			)
		);

		$this->assertArrayHasKey( 'warnings', $payload );
		$this->assertStringContainsString( 'post_idd', $payload['warnings'][0] );
	}

	/**
	 * The call must still succeed — this is a warning, not a rejection.
	 *
	 * @return void
	 */
	public function test_undeclared_argument_does_not_fail_the_call(): void {
		$this->register_probe(
			'probe_succeeds',
			array( 'post_id' => array( 'type' => 'integer' ) ),
			array( 'ok' => true )
		);

		$response = $this->router->execute_tool(
			'probe_succeeds',
			array(
				'post_id' => 12,
				'bogus'   => 'x',
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$payload = json_decode( $response->get_data()['content'][0]['text'], true );
		$this->assertTrue( $payload['ok'] );
	}

	/**
	 * Declared arguments must never be warned about.
	 *
	 * False positives would be worse than the silence this replaces — a warning
	 * on every well-formed call trains the caller to ignore warnings.
	 *
	 * @return void
	 */
	public function test_declared_arguments_produce_no_warning(): void {
		$this->register_probe(
			'probe_clean',
			array(
				'post_id' => array( 'type' => 'integer' ),
				'view'    => array( 'type' => 'string' ),
			),
			array( 'ok' => true )
		);

		$payload = $this->call(
			'probe_clean',
			array(
				'post_id' => 12,
				'view'    => 'structure',
			)
		);

		$this->assertArrayNotHasKey( 'warnings', $payload );
	}

	/**
	 * `response_format` is injected into every schema and must not be warned about.
	 *
	 * It is added by register_tool rather than declared by each tool, which is
	 * exactly the shape of thing a naive diff would report as unrecognized.
	 *
	 * @return void
	 */
	public function test_injected_response_format_is_not_warned_about(): void {
		$this->register_probe(
			'probe_response_format',
			array( 'post_id' => array( 'type' => 'integer' ) ),
			array( 'ok' => true )
		);

		$payload = $this->call(
			'probe_response_format',
			array(
				'post_id'         => 12,
				'response_format' => 'verbose',
			)
		);

		$this->assertArrayNotHasKey( 'warnings', $payload );
	}

	/**
	 * Multiple undeclared arguments must all be named, in one warning.
	 *
	 * @return void
	 */
	public function test_all_undeclared_arguments_are_named(): void {
		$this->register_probe(
			'probe_many',
			array( 'post_id' => array( 'type' => 'integer' ) ),
			array( 'ok' => true )
		);

		$payload = $this->call(
			'probe_many',
			array(
				'post_id' => 12,
				'alpha'   => 1,
				'beta'    => 2,
			)
		);

		$this->assertCount( 1, $payload['warnings'] );
		$this->assertStringContainsString( 'alpha', $payload['warnings'][0] );
		$this->assertStringContainsString( 'beta', $payload['warnings'][0] );
	}

	/**
	 * A handler's own warnings must survive alongside ours.
	 *
	 * @return void
	 */
	public function test_existing_handler_warnings_are_preserved(): void {
		$this->register_probe(
			'probe_existing',
			array( 'post_id' => array( 'type' => 'integer' ) ),
			array(
				'ok'       => true,
				'warnings' => array( 'IDs were regenerated.' ),
			)
		);

		$payload = $this->call(
			'probe_existing',
			array(
				'post_id' => 12,
				'bogus'   => 'x',
			)
		);

		$this->assertCount( 2, $payload['warnings'] );
		$this->assertSame( 'IDs were regenerated.', $payload['warnings'][0] );
		$this->assertStringContainsString( 'bogus', $payload['warnings'][1] );
	}

	/**
	 * A tool declaring no properties must not warn about everything.
	 *
	 * Such a tool accepts anything by definition, so there is nothing to
	 * compare against and warning would be pure noise.
	 *
	 * @return void
	 */
	public function test_tool_without_declared_properties_stays_quiet(): void {
		$this->router->register_tool(
			'probe_schemaless',
			'Probe tool with no declared properties.',
			array( 'type' => 'object' ),
			static fn( array $args ): array => array( 'ok' => true )
		);

		$payload = $this->call( 'probe_schemaless', array( 'anything' => 1 ) );

		$this->assertArrayNotHasKey( 'warnings', $payload );
	}

	/**
	 * A list-shaped result must not be turned into an object.
	 *
	 * Adding a string key to a list would change the response shape for every
	 * caller, which is a worse outcome than dropping the warning.
	 *
	 * @return void
	 */
	public function test_list_result_shape_is_left_alone(): void {
		$this->register_probe(
			'probe_list',
			array( 'post_id' => array( 'type' => 'integer' ) ),
			array( 'one', 'two' )
		);

		$payload = $this->call(
			'probe_list',
			array(
				'post_id' => 12,
				'bogus'   => 'x',
			)
		);

		$this->assertSame( array( 'one', 'two' ), $payload );
	}
}
