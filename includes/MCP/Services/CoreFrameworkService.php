<?php
/**
 * Core Framework preset service - the in-process write path for --cf-* design tokens.
 *
 * WHY THIS EXISTS
 * ---------------
 * Core Framework's generated stylesheet loads after Bricks' global-variables.min.css, so where a
 * token name exists in both surfaces the Core Framework value is the one that renders. Its REST
 * API cannot be driven from outside: every route is gated on wp_verify_nonce( $nonce, 'wp_rest' )
 * (core-framework/wp/App/Rest/AllPoints.php:203-236), and a WP nonce hashes the logged-in
 * cookie's session token, which an application-password client does not have. Bricks MCP runs
 * in-process, so it can operate on the same storage the REST routes operate on.
 *
 * WHERE THE DATA LIVES (all verified against Core Framework 1.10 source)
 * ---------------------------------------------------------------------
 * - The active preset id is core_framework_main['selected_id'] (wp/Helper.php:32-35).
 * - The preset itself is one row of {$wpdb->prefix}core_framework_presets, columns id/time/data,
 *   where data is a JSON blob (wp/Helper.php:59-85).
 * - Inside that blob, tokens live in exactly two places:
 *     1. modulesData.COLOR_SYSTEM.groups[].colors[] - colours, each with `value` (light) and,
 *        when `isDarkMode` is true, `darkValue` (dark). Shades and tints are parallel arrays
 *        (`shades`/`darkShades`, `tints`/`darkTints`) of {name, value} pairs. Transparent ramp
 *        names (`--cf-x-5` ... `--cf-x-90`) are DERIVED from `transparentVariables` and are not
 *        stored, so they cannot be written directly.
 *     2. styleSheetData.<section>[] groups whose `type` is "variable", in
 *        cssObjects[].declarations[] as {property, value, id}. These carry ONE value; the
 *        declaration schema has no dark counterpart, which is why the dark block of the generated
 *        stylesheet contains only colour-system tokens.
 * - The emitted CSS name is '--' . preset.variablePrefix . <stored name> (wp/Helper.php:849-890,
 *   :1014-1046), e.g. stored "darkblue1" with prefix "cf-" emits --cf-darkblue1.
 *
 * core_framework_colors VS colorStyles
 * ------------------------------------
 * core_framework_colors (written by update_colors(), AllPoints.php:998-1016) is NOT a second
 * source of truth. It is a flat, derived projection of modulesData.COLOR_SYSTEM that the Core
 * Framework admin app computes in the browser and posts to the server purely so the builder
 * colour palettes can be synced; each row is {name, value, raw, id, dark?} where `raw` is
 * "var(--cf-<name>)" and `dark` marks the dark-theme half of a pair. Nothing in Core Framework
 * generates CSS from it. Writing the preset without refreshing it therefore leaves a stale cache
 * behind, so update/create/delete refresh the matching rows by a targeted value-only merge (see
 * sync_colors_option()). It deliberately does NOT rebuild the builder palettes: Core Framework's
 * own CoreFrameworkBricks()->update_colors() replaces the whole "Core Framework" palette from the
 * array it is handed, which is a wholesale rewrite rather than a targeted merge. Bricks stores
 * those palette entries as raw "var(--cf-...)" references anyway, so they re-resolve on their own
 * once the stylesheet is recompiled; only the swatch preview value goes stale.
 *
 * REGENERATION - READ THIS BEFORE ADDING A CSS WRITER
 * --------------------------------------------------
 * Core Framework has NO server-side stylesheet generator. assets/public/css/core_framework.css is
 * compiled by the plugin's React admin app and posted as a finished string to /update-main, which
 * only file_put_contents() it (AllPoints.php:842-889); the Figma integration works the same way
 * via /figma/update-preset-css (:1542-1575), and App/Css/VariableExtractor.php only parses CSS,
 * it never emits it. So this service does not regenerate the stylesheet and does not hand-write
 * one: a hand-rolled generator would drift from Core Framework at its next release. Writes report
 * stylesheet_stale = true and tell the caller what actually recompiles it.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CoreFrameworkService class.
 *
 * Read-modify-write access to the active Core Framework preset's design tokens.
 */
class CoreFrameworkService {

	/**
	 * Option holding Core Framework's main preferences, including the active preset id.
	 *
	 * @var string
	 */
	private const MAIN_OPTION = 'core_framework_main';

	/**
	 * Option holding the derived flat colour projection used for builder palette sync.
	 *
	 * @var string
	 */
	private const COLORS_OPTION = 'core_framework_colors';

	/**
	 * Preset table name, without the site table prefix.
	 *
	 * @var string
	 */
	private const PRESETS_TABLE = 'core_framework_presets';

	/**
	 * Stylesheet-data sections Core Framework itself scans.
	 *
	 * Mirrors Helper::$stylesheet_data_keys. Any other section present in the blob is still
	 * preserved on write - this list only bounds where new declarations may be created.
	 *
	 * @var array<int, string>
	 */
	private const SECTION_KEYS = array(
		'colorStyles',
		'typographyStyles',
		'spacingStyles',
		'layoutsStyles',
		'designStyles',
		'componentsStyles',
		'otherStyles',
	);

	/**
	 * Token kinds that a caller may write a value to.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_KINDS = array( 'color', 'color_shade', 'color_tint', 'variable' );

	/**
	 * Substrings never allowed in a token value.
	 *
	 * A token value ends up inside a CSS custom property declaration, so anything that could
	 * close the declaration or smuggle in a rule is refused. The last four mirror Core
	 * Framework's own CSS guard (AllPoints.php:857).
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_VALUE_PATTERNS = array( ';', '{', '}', '<', '>', 'expression(', 'javascript:', '@import' );

	/**
	 * Check if Core Framework is installed and active.
	 *
	 * This is the single gate for all Core Framework functionality, mirroring
	 * BricksService::is_bricks_active(). CORE_FRAMEWORK_ABSOLUTE is defined by the plugin
	 * bootstrap and \CoreFramework\Helper is the class that owns preset access, so both being
	 * present means the storage layout this service targets is the one that is loaded.
	 *
	 * @return bool True if Core Framework is installed and active.
	 */
	public function is_core_framework_active(): bool {
		return defined( 'CORE_FRAMEWORK_ABSOLUTE' ) && class_exists( '\CoreFramework\Helper' );
	}

	/**
	 * Report what this service can see, without touching anything.
	 *
	 * @return array<string, mixed> Capability-detection result.
	 */
	public function get_status(): array {
		$active = $this->is_core_framework_active();

		$status = array(
			'core_framework_active' => $active,
			'preset_id'             => null,
			'preset_readable'       => false,
			'variable_prefix'       => null,
			'token_count'           => 0,
			'stylesheet_path'       => $this->stylesheet_path(),
			'colors_option_rows'    => is_array( get_option( self::COLORS_OPTION, array() ) ) ? count( (array) get_option( self::COLORS_OPTION, array() ) ) : 0,
		);

		if ( ! $active ) {
			return $status;
		}

		$loaded = $this->load_preset();

		if ( is_wp_error( $loaded ) ) {
			$status['error'] = $loaded->get_error_code();
			return $status;
		}

		$status['preset_id']       = $loaded['id'];
		$status['preset_readable'] = true;
		$status['variable_prefix'] = $this->variable_prefix( $loaded['preset'] );
		$status['token_count']     = count( $this->collect( $loaded['preset'] ) );

		return $status;
	}

	// =========================================================================
	// Reads
	// =========================================================================

	/**
	 * List every token the active preset defines.
	 *
	 * @param string $search Optional case-insensitive substring filter on the token name.
	 * @param string $kind   Optional kind filter: color, color_shade, color_tint, color_alpha, variable.
	 * @return array<string, mixed>|\WP_Error Token list or error.
	 */
	public function list_tokens( string $search = '', string $kind = '' ): array|\WP_Error {
		$loaded = $this->load_preset();

		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$search = strtolower( trim( $search ) );
		$kind   = trim( $kind );
		$tokens = array();

		foreach ( $this->collect( $loaded['preset'] ) as $entry ) {
			if ( '' !== $kind && $entry['kind'] !== $kind ) {
				continue;
			}

			if ( '' !== $search && ! str_contains( strtolower( $entry['name'] ), $search ) ) {
				continue;
			}

			$tokens[] = $this->public_entry( $entry );
		}

		return array(
			'preset_id'       => $loaded['id'],
			'variable_prefix' => $this->variable_prefix( $loaded['preset'] ),
			'tokens'          => $tokens,
			'total'           => count( $tokens ),
			'note'            => __( 'Colour tokens carry a light value and, when isDarkMode is set, a dark one. color_alpha entries are derived from a base colour\'s transparent ramp and cannot be written directly. variable tokens have no dark counterpart: Core Framework emits its dark block from the colour system only.', 'bricks-mcp' ),
		);
	}

	/**
	 * Get a single token.
	 *
	 * @param string $token Token name, with or without the leading "--" and variable prefix.
	 * @return array<string, mixed>|\WP_Error Token data or error.
	 */
	public function get_token( string $token ): array|\WP_Error {
		$loaded = $this->load_preset();

		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$entry = $this->find( $loaded['preset'], $token );

		if ( null === $entry ) {
			return $this->not_found_error( $token );
		}

		$result              = $this->public_entry( $entry );
		$result['preset_id'] = $loaded['id'];

		return $result;
	}

	// =========================================================================
	// Writes
	// =========================================================================

	/**
	 * Update an existing token's light and/or dark value.
	 *
	 * @param string               $token              Token name.
	 * @param array<string, mixed> $fields             Fields: value (light), dark_value (dark).
	 * @param bool                 $sync_colors_option Whether to refresh the derived colour cache.
	 * @return array<string, mixed>|\WP_Error Update result or error.
	 */
	public function update_token( string $token, array $fields, bool $sync_colors_option = true ): array|\WP_Error {
		$loaded = $this->load_preset();

		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$applied = $this->apply_update( $loaded['preset'], $token, $fields );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$persisted = $this->persist( $loaded, array( $applied ), $sync_colors_option );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return array_merge( $applied, $persisted );
	}

	/**
	 * Update many tokens against a single read-modify-write of the preset blob.
	 *
	 * Follows the partial-success model used by batch_create_global_variables(): valid entries
	 * are applied, invalid ones are reported, and nothing is written when every entry failed.
	 *
	 * @param array<int, array<string, mixed>> $tokens             Entries of {token, value?, dark_value?}.
	 * @param bool                             $sync_colors_option Whether to refresh the derived colour cache.
	 * @return array<string, mixed>|\WP_Error Batch result or error.
	 */
	public function batch_update_tokens( array $tokens, bool $sync_colors_option = true ): array|\WP_Error {
		$loaded = $this->load_preset();

		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$updated = array();
		$errors  = array();

		foreach ( $tokens as $index => $definition ) {
			if ( ! is_array( $definition ) ) {
				$errors[ $index ] = __( 'Each entry must be an object with a token key.', 'bricks-mcp' );
				continue;
			}

			$name = (string) ( $definition['token'] ?? $definition['name'] ?? '' );

			if ( '' === trim( $name ) ) {
				$errors[ $index ] = __( 'Missing token', 'bricks-mcp' );
				continue;
			}

			$applied = $this->apply_update( $loaded['preset'], $name, $definition );

			if ( is_wp_error( $applied ) ) {
				$errors[ $index ] = $applied->get_error_message();
				continue;
			}

			$updated[] = $applied;
		}

		if ( array() === $updated ) {
			return array(
				'updated'              => array(),
				'errors'               => $errors,
				'preset_written'       => false,
				'stylesheet_stale'     => false,
				'colors_option_synced' => 0,
			);
		}

		$persisted = $this->persist( $loaded, $updated, $sync_colors_option );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return array_merge(
			array(
				'updated' => $updated,
				'errors'  => $errors,
			),
			$persisted
		);
	}

	/**
	 * Create a new token in the active preset.
	 *
	 * @param string               $token              Token name.
	 * @param array<string, mixed> $fields             Fields: value, dark_value, kind, group, section, gen, format.
	 * @param bool                 $sync_colors_option Whether to refresh the derived colour cache.
	 * @return array<string, mixed>|\WP_Error Creation result or error.
	 */
	public function create_token( string $token, array $fields, bool $sync_colors_option = true ): array|\WP_Error {
		$loaded = $this->load_preset();

		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$preset = $loaded['preset'];
		$stored = $this->stored_name( $preset, $token );

		if ( '' === $stored ) {
			return new \WP_Error(
				'missing_token',
				__( 'token is required. Provide a token name such as "--cf-brand-navy", "cf-brand-navy" or "brand-navy".', 'bricks-mcp' )
			);
		}

		if ( null !== $this->find( $preset, $stored ) ) {
			return new \WP_Error(
				'token_exists',
				sprintf(
					/* translators: %s: Token name */
					__( 'Token "%s" already exists. Use action "update" to change its value.', 'bricks-mcp' ),
					$this->public_name( $preset, $stored )
				)
			);
		}

		$value = $this->clean_value( $fields['value'] ?? null );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		if ( null === $value ) {
			return new \WP_Error(
				'missing_value',
				__( 'value is required when creating a token. Provide a CSS value such as "#11175e".', 'bricks-mcp' )
			);
		}

		$dark_value = $this->clean_value( $fields['dark_value'] ?? null );

		if ( is_wp_error( $dark_value ) ) {
			return $dark_value;
		}

		$kind = (string) ( $fields['kind'] ?? 'color' );

		$created = match ( $kind ) {
			'color'    => $this->create_color( $preset, $stored, $value, $dark_value, $fields ),
			'variable' => $this->create_variable( $preset, $stored, $value, $dark_value, $fields ),
			default    => new \WP_Error(
				'invalid_kind',
				sprintf(
					/* translators: %s: Requested kind */
					__( 'Invalid kind "%s". Creatable kinds: color (colour system entry, supports a dark value), variable (plain declaration in a stylesheet variable group, light value only).', 'bricks-mcp' ),
					$kind
				)
			),
		};

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$persisted = $this->persist( $loaded, array( $created ), $sync_colors_option );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return array_merge( $created, $persisted );
	}

	/**
	 * Delete a token from the active preset.
	 *
	 * Only whole colour-system colours and plain variable declarations can be removed. Shades,
	 * tints and transparent ramp entries are positional or derived, so deleting one on its own
	 * would leave the colour inconsistent with its own shadesNumber/transparentVariables.
	 *
	 * @param string $token              Token name.
	 * @param bool   $sync_colors_option Whether to refresh the derived colour cache.
	 * @return array<string, mixed>|\WP_Error Deletion result or error.
	 */
	public function delete_token( string $token, bool $sync_colors_option = true ): array|\WP_Error {
		$loaded = $this->load_preset();

		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$preset = $loaded['preset'];
		$entry  = $this->find( $preset, $token );

		if ( null === $entry ) {
			return $this->not_found_error( $token );
		}

		if ( 'color' === $entry['kind'] ) {
			$colors  = $entry['_group']->colors;
			$removed = false;

			foreach ( $colors as $i => $color ) {
				if ( $color === $entry['_color'] ) {
					array_splice( $colors, $i, 1 );
					$removed = true;
					break;
				}
			}

			if ( ! $removed ) {
				return $this->not_found_error( $token );
			}

			$entry['_group']->colors = $colors;
		} elseif ( 'variable' === $entry['kind'] ) {
			$declarations = $entry['_css_object']->declarations;
			$removed      = false;

			foreach ( $declarations as $i => $declaration ) {
				if ( $declaration === $entry['_declaration'] ) {
					array_splice( $declarations, $i, 1 );
					$removed = true;
					break;
				}
			}

			if ( ! $removed ) {
				return $this->not_found_error( $token );
			}

			$entry['_css_object']->declarations = $declarations;
		} else {
			return new \WP_Error(
				'undeletable_token',
				sprintf(
					/* translators: 1: Token name, 2: Token kind */
					__( 'Token "%1$s" is a %2$s, which Core Framework derives from its base colour. Delete or reconfigure the base colour instead.', 'bricks-mcp' ),
					$entry['name'],
					$entry['kind']
				)
			);
		}

		$deleted = array(
			'action'      => 'deleted',
			'token'       => $entry['name'],
			'stored_name' => $entry['stored_name'],
			'kind'        => $entry['kind'],
			'value'       => $entry['value'],
			'dark_value'  => $entry['dark_value'],
			'note'        => sprintf(
				/* translators: %s: Token name */
				__( 'Anything still referencing var(%s) will fall back to its CSS fallback value once the stylesheet is recompiled.', 'bricks-mcp' ),
				$entry['name']
			),
		);

		$persisted = $this->persist( $loaded, array( $deleted ), $sync_colors_option );

		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return array_merge( $deleted, $persisted );
	}

	// =========================================================================
	// Mutation helpers
	// =========================================================================

	/**
	 * Apply one value change in place, without persisting.
	 *
	 * @param \stdClass            $preset Decoded preset.
	 * @param string               $token  Token name.
	 * @param array<string, mixed> $fields Fields: value, dark_value.
	 * @return array<string, mixed>|\WP_Error Change record or error.
	 */
	private function apply_update( \stdClass $preset, string $token, array $fields ): array|\WP_Error {
		$entry = $this->find( $preset, $token );

		if ( null === $entry ) {
			return $this->not_found_error( $token );
		}

		if ( ! in_array( $entry['kind'], self::WRITABLE_KINDS, true ) ) {
			return new \WP_Error(
				'token_not_writable',
				sprintf(
					/* translators: 1: Token name, 2: Token kind */
					__( 'Token "%1$s" is a %2$s: Core Framework derives it from a base colour rather than storing it, so it has no value to write. Update the base colour instead.', 'bricks-mcp' ),
					$entry['name'],
					$entry['kind']
				)
			);
		}

		$value = $this->clean_value( $fields['value'] ?? null );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$dark_value = $this->clean_value( $fields['dark_value'] ?? null );

		if ( is_wp_error( $dark_value ) ) {
			return $dark_value;
		}

		if ( null === $value && null === $dark_value ) {
			return new \WP_Error(
				'no_fields',
				__( 'At least one of value or dark_value is required.', 'bricks-mcp' )
			);
		}

		if ( null !== $dark_value && 'variable' === $entry['kind'] ) {
			return new \WP_Error(
				'dark_value_unsupported',
				sprintf(
					/* translators: %s: Token name */
					__( 'Token "%s" is a stylesheet variable declaration, which stores a single value - Core Framework emits its dark block from the colour system only. Drop dark_value, or move the token into the colour system.', 'bricks-mcp' ),
					$entry['name']
				)
			);
		}

		$before_value = $entry['value'];
		$before_dark  = $entry['dark_value'];

		if ( null !== $value ) {
			switch ( $entry['kind'] ) {
				case 'color':
					$entry['_color']->value = $value;
					break;
				case 'color_shade':
				case 'color_tint':
					$entry['_swatch']->value = $value;
					break;
				default:
					$entry['_declaration']->value = $value;
					break;
			}
		}

		if ( null !== $dark_value ) {
			$dark_applied = $this->apply_dark_value( $entry, $dark_value );

			if ( is_wp_error( $dark_applied ) ) {
				return $dark_applied;
			}
		}

		return array(
			'token'               => $entry['name'],
			'stored_name'         => $entry['stored_name'],
			'kind'                => $entry['kind'],
			'group'               => $entry['group'],
			'previous_value'      => $before_value,
			'previous_dark_value' => $before_dark,
			'value'               => null !== $value ? $value : $before_value,
			'dark_value'          => null !== $dark_value ? $dark_value : $before_dark,
		);
	}

	/**
	 * Write the dark half of a colour entry.
	 *
	 * A colour only emits a dark value when isDarkMode is set, so supplying one turns the flag
	 * on - the same pairing Core Framework's own dark-mode toggle performs.
	 *
	 * @param array<string, mixed> $entry      Located token entry.
	 * @param string               $dark_value Dark value to write.
	 * @return true|\WP_Error True on success, error when there is nowhere to write it.
	 */
	private function apply_dark_value( array $entry, string $dark_value ): bool|\WP_Error {
		if ( 'color' === $entry['kind'] ) {
			$entry['_color']->darkValue  = $dark_value; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
			$entry['_color']->isDarkMode = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
			return true;
		}

		if ( null === $entry['_dark_swatch'] ) {
			return new \WP_Error(
				'dark_swatch_missing',
				sprintf(
					/* translators: 1: Token name, 2: Base colour name */
					__( 'Token "%1$s" has no stored dark counterpart. Core Framework builds the dark shade and tint arrays when dark mode is enabled on the base colour "%2$s"; enable it there first (set a dark_value on the base colour), then retry.', 'bricks-mcp' ),
					$entry['name'],
					$entry['base_name']
				)
			);
		}

		$entry['_dark_swatch']->value = $dark_value;

		$entry['_color']->isDarkMode = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.

		return true;
	}

	/**
	 * Append a new colour to a colour-system group.
	 *
	 * @param \stdClass            $preset     Decoded preset.
	 * @param string               $stored     Bare token name.
	 * @param string               $value      Light value.
	 * @param string|null          $dark_value Dark value, or null.
	 * @param array<string, mixed> $fields     Remaining fields (group, gen, format).
	 * @return array<string, mixed>|\WP_Error Change record or error.
	 */
	private function create_color( \stdClass $preset, string $stored, string $value, ?string $dark_value, array $fields ): array|\WP_Error {
		$groups = $this->color_groups( $preset );

		if ( array() === $groups ) {
			return new \WP_Error(
				'color_system_missing',
				__( 'This preset has no modulesData.COLOR_SYSTEM.groups to add a colour to. Create a colour group in the Core Framework admin UI first, or create the token with kind "variable".', 'bricks-mcp' )
			);
		}

		$wanted = trim( (string) ( $fields['group'] ?? '' ) );
		$target = null;

		foreach ( $groups as $group ) {
			if ( '' === $wanted ) {
				if ( empty( $group->isDisabled ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
					$target = $group;
					break;
				}
				continue;
			}

			if ( ( $group->name ?? '' ) === $wanted || ( $group->id ?? '' ) === $wanted ) {
				$target = $group;
				break;
			}
		}

		if ( null === $target ) {
			return new \WP_Error(
				'group_not_found',
				sprintf(
					/* translators: %s: Requested group */
					__( 'Colour group "%s" not found. Use action "list" to see the groups this preset defines.', 'bricks-mcp' ),
					$wanted
				)
			);
		}

		$gen = array();

		if ( isset( $fields['gen'] ) && is_array( $fields['gen'] ) ) {
			foreach ( $fields['gen'] as $candidate ) {
				if ( in_array( $candidate, array( 'bg', 'text', 'border' ), true ) ) {
					$gen[] = $candidate;
				}
			}
		}

		$color = (object) array(
			'id'         => $this->generate_preset_id(),
			'name'       => $stored,
			'value'      => $value,
			'format'     => $this->detect_format( $value, (string) ( $fields['format'] ?? '' ) ),
			'shades'     => array(),
			'darkShades' => array(),
			'tints'      => array(),
			'darkTints'  => array(),
			'gen'        => $gen,
		);

		if ( null !== $dark_value ) {
			$color->isDarkMode = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
			$color->darkValue  = $dark_value; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
		}

		if ( ! isset( $target->colors ) || ! is_array( $target->colors ) ) {
			$target->colors = array();
		}

		$colors         = $target->colors;
		$colors[]       = $color;
		$target->colors = $colors;

		return array(
			'action'      => 'created',
			'token'       => $this->public_name( $preset, $stored ),
			'stored_name' => $stored,
			'kind'        => 'color',
			'group'       => (string) ( $target->name ?? '' ),
			'value'       => $value,
			'dark_value'  => $dark_value,
		);
	}

	/**
	 * Append a new declaration to a stylesheet variable group.
	 *
	 * @param \stdClass            $preset     Decoded preset.
	 * @param string               $stored     Bare token name.
	 * @param string               $value      Value.
	 * @param string|null          $dark_value Dark value, refused for this kind.
	 * @param array<string, mixed> $fields     Remaining fields (group, section).
	 * @return array<string, mixed>|\WP_Error Change record or error.
	 */
	private function create_variable( \stdClass $preset, string $stored, string $value, ?string $dark_value, array $fields ): array|\WP_Error {
		if ( null !== $dark_value ) {
			return new \WP_Error(
				'dark_value_unsupported',
				__( 'kind "variable" stores a single value: Core Framework\'s declaration schema has no dark counterpart and its dark block is emitted from the colour system only. Use kind "color" for a token that must invert.', 'bricks-mcp' )
			);
		}

		$section = trim( (string) ( $fields['section'] ?? '' ) );

		if ( '' !== $section && ! in_array( $section, self::SECTION_KEYS, true ) ) {
			return new \WP_Error(
				'invalid_section',
				sprintf(
					/* translators: %s: Comma-separated section list */
					__( 'Invalid section. Core Framework reads these: %s.', 'bricks-mcp' ),
					implode( ', ', self::SECTION_KEYS )
				)
			);
		}

		$wanted   = trim( (string) ( $fields['group'] ?? '' ) );
		$sections = '' !== $section ? array( $section ) : self::SECTION_KEYS;
		$target   = null;
		$found_in = '';

		foreach ( $sections as $section_key ) {
			foreach ( $this->variable_groups( $preset, $section_key ) as $group ) {
				if ( '' === $wanted ) {
					if ( empty( $group->isDisabled ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
						$target   = $group;
						$found_in = $section_key;
						break 2;
					}
					continue;
				}

				if ( ( $group->name ?? '' ) === $wanted || ( $group->id ?? '' ) === $wanted ) {
					$target   = $group;
					$found_in = $section_key;
					break 2;
				}
			}
		}

		if ( null === $target ) {
			return new \WP_Error(
				'group_not_found',
				sprintf(
					/* translators: %s: Requested group */
					__( 'No stylesheet variable group matched "%s". Use action "list" to see the groups this preset defines, or create one in the Core Framework admin UI.', 'bricks-mcp' ),
					$wanted
				)
			);
		}

		$css_object = null;

		foreach ( (array) ( $target->cssObjects ?? array() ) as $candidate ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
			if ( $candidate instanceof \stdClass && empty( $candidate->isDisabled ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
				$css_object = $candidate;
				break;
			}
		}

		if ( null === $css_object ) {
			return new \WP_Error(
				'css_object_missing',
				sprintf(
					/* translators: %s: Group name */
					__( 'Variable group "%s" has no enabled cssObject to hold a declaration. Pick another group.', 'bricks-mcp' ),
					(string) ( $target->name ?? '' )
				)
			);
		}

		$declaration = (object) array(
			'property' => '--' . $stored,
			'value'    => $value,
			'id'       => $this->generate_preset_id(),
		);

		$declarations             = is_array( $css_object->declarations ?? null ) ? $css_object->declarations : array();
		$declarations[]           = $declaration;
		$css_object->declarations = $declarations;

		return array(
			'action'      => 'created',
			'token'       => $this->public_name( $preset, $stored ),
			'stored_name' => $stored,
			'kind'        => 'variable',
			'group'       => (string) ( $target->name ?? '' ),
			'section'     => $found_in,
			'value'       => $value,
			'dark_value'  => null,
		);
	}

	// =========================================================================
	// Preset load / persist
	// =========================================================================

	/**
	 * Load and decode the active preset.
	 *
	 * Decoding to objects rather than associative arrays is deliberate: an empty JSON object in
	 * the blob decodes to an empty PHP array under assoc mode and re-encodes as [], which would
	 * silently change the shape of a key this service never meant to touch.
	 *
	 * @return array{id: string, preset: \stdClass, raw: string}|\WP_Error Loaded preset or error.
	 */
	private function load_preset(): array|\WP_Error {
		if ( ! $this->is_core_framework_active() ) {
			return new \WP_Error(
				'core_framework_inactive',
				__( 'Core Framework must be installed and active to manage --cf-* tokens. Its design tokens live in the Core Framework preset, not in Bricks.', 'bricks-mcp' )
			);
		}

		$options = get_option( self::MAIN_OPTION, array() );

		if ( ! is_array( $options ) || empty( $options['selected_id'] ) || ! is_string( $options['selected_id'] ) ) {
			return new \WP_Error(
				'core_framework_no_preset',
				__( 'Core Framework has no active preset (core_framework_main["selected_id"] is unset). Open Core Framework and select a preset; this tool will not invent one.', 'bricks-mcp' )
			);
		}

		$id = $options['selected_id'];

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return new \WP_Error(
				'core_framework_no_database',
				__( 'No database handle is available to read the Core Framework preset table.', 'bricks-mcp' )
			);
		}

		$table = $wpdb->prefix . self::PRESETS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return new \WP_Error(
				'core_framework_table_missing',
				sprintf(
					/* translators: %s: Table name */
					__( 'Core Framework preset table "%s" does not exist. This tool will not create it - reactivate Core Framework so it builds its own schema.', 'bricks-mcp' ),
					$table
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, data FROM {$table} WHERE id = %s", $id ) );

		if ( ! $row || ! isset( $row->data ) || ! is_string( $row->data ) || '' === $row->data ) {
			return new \WP_Error(
				'core_framework_preset_missing',
				sprintf(
					/* translators: %s: Preset ID */
					__( 'Core Framework preset "%s" was not found in the preset table. This tool will not create one.', 'bricks-mcp' ),
					$id
				)
			);
		}

		$preset = json_decode( $row->data );

		if ( ! $preset instanceof \stdClass ) {
			return new \WP_Error(
				'core_framework_preset_unreadable',
				sprintf(
					/* translators: 1: Preset ID, 2: JSON error message */
					__( 'Core Framework preset "%1$s" is not a readable JSON object (%2$s). Refusing to overwrite it.', 'bricks-mcp' ),
					$id,
					json_last_error_msg()
				)
			);
		}

		if ( ! isset( $preset->styleSheetData ) && ! isset( $preset->modulesData ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON keys.
			return new \WP_Error(
				'core_framework_schema_unexpected',
				sprintf(
					/* translators: %s: Preset ID */
					__( 'Core Framework preset "%s" has neither styleSheetData nor modulesData, so it is not the schema this tool understands. Refusing to write.', 'bricks-mcp' ),
					$id
				)
			);
		}

		return array(
			'id'     => $id,
			'preset' => $preset,
			'raw'    => $row->data,
		);
	}

	/**
	 * Re-encode and store the mutated preset, then report what is now stale.
	 *
	 * @param array{id: string, preset: \stdClass, raw: string} $loaded             Loaded preset.
	 * @param array<int, array<string, mixed>>                  $changes            Applied change records.
	 * @param bool                                              $sync_colors_option Whether to refresh the colour cache.
	 * @return array<string, mixed>|\WP_Error Persistence report or error.
	 */
	private function persist( array $loaded, array $changes, bool $sync_colors_option ): array|\WP_Error {
		$json = wp_json_encode( $loaded['preset'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) || '' === $json ) {
			return new \WP_Error(
				'core_framework_encode_failed',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'The modified preset could not be re-encoded (%s); nothing was written.', 'bricks-mcp' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! json_decode( $json ) instanceof \stdClass ) {
			return new \WP_Error(
				'core_framework_encode_lossy',
				__( 'The re-encoded preset did not decode back to an object; nothing was written.', 'bricks-mcp' )
			);
		}

		/*
		 * A targeted merge only ever adds or replaces values, so it cannot roughly halve the
		 * blob. If it did, something structural was dropped and the safe move is to write
		 * nothing rather than persist a truncated preset over a working one.
		 */
		if ( strlen( $json ) * 2 < strlen( $loaded['raw'] ) ) {
			return new \WP_Error(
				'core_framework_unsafe_write',
				sprintf(
					/* translators: 1: New byte length, 2: Original byte length */
					__( 'Refusing to write: the re-encoded preset is %1$d bytes against the stored %2$d, which means data was lost rather than merged.', 'bricks-mcp' ),
					strlen( $json ),
					strlen( $loaded['raw'] )
				)
			);
		}

		global $wpdb;

		$table = $wpdb->prefix . self::PRESETS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = $wpdb->update(
			$table,
			array(
				'data' => $json,
				'time' => current_time( 'mysql' ),
			),
			array( 'id' => $loaded['id'] ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $written ) {
			return new \WP_Error(
				'core_framework_write_failed',
				sprintf(
					/* translators: %s: Database error */
					__( 'Writing the Core Framework preset failed: %s', 'bricks-mcp' ),
					(string) ( $wpdb->last_error ?? '' )
				)
			);
		}

		$synced = $sync_colors_option ? $this->sync_colors_option( $changes ) : 0;

		$this->purge_core_framework_cache();

		return array(
			'preset_id'              => $loaded['id'],
			'preset_written'         => true,
			'preset_bytes'           => strlen( $json ),
			'colors_option_synced'   => $synced,
			'stylesheet_stale'       => true,
			'stylesheet_path'        => $this->stylesheet_path(),
			'stylesheet_regenerated' => $this->regenerate_stylesheet(),
			'next_step'              => __( 'The preset is updated but assets/public/css/core_framework.css is NOT: Core Framework compiles that file in its browser admin app and posts the finished CSS to its own /update-main route, so there is no server-side generator to call and this tool deliberately does not hand-write one. Open Core Framework in wp-admin once (or push CSS through its /figma/update-preset-css route) to recompile, then confirm the new value is present in core_framework.css.', 'bricks-mcp' ),
		);
	}

	/**
	 * Refresh the derived colour projection for the tokens that changed.
	 *
	 * Value-only, matched on name plus the light/dark flag: no row is added, removed or
	 * reordered, so an option this service has never seen before is left exactly as it was
	 * apart from the values it now disagrees with.
	 *
	 * @param array<int, array<string, mixed>> $changes Applied change records.
	 * @return int Number of rows refreshed.
	 */
	private function sync_colors_option( array $changes ): int {
		$colors = get_option( self::COLORS_OPTION, array() );

		if ( ! is_array( $colors ) || array() === $colors ) {
			return 0;
		}

		$light = array();
		$dark  = array();

		foreach ( $changes as $change ) {
			$stored = (string) ( $change['stored_name'] ?? '' );

			if ( '' === $stored || 'deleted' === ( $change['action'] ?? '' ) ) {
				continue;
			}

			if ( isset( $change['value'] ) && is_string( $change['value'] ) ) {
				$light[ $stored ] = $change['value'];
			}

			if ( isset( $change['dark_value'] ) && is_string( $change['dark_value'] ) ) {
				$dark[ $stored ] = $change['dark_value'];
			}
		}

		if ( array() === $light && array() === $dark ) {
			return 0;
		}

		$synced = 0;

		foreach ( $colors as $index => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_string( $row['name'] ) ) {
				continue;
			}

			$is_dark = ! empty( $row['dark'] );
			$source  = $is_dark ? $dark : $light;

			if ( ! isset( $source[ $row['name'] ] ) ) {
				continue;
			}

			if ( ( $row['value'] ?? null ) === $source[ $row['name'] ] ) {
				continue;
			}

			$colors[ $index ]['value'] = $source[ $row['name'] ];
			++$synced;
		}

		if ( $synced > 0 ) {
			update_option( self::COLORS_OPTION, $colors, false );
		}

		return $synced;
	}

	/**
	 * Ask Core Framework to regenerate its stylesheet, if it ever gains a way to.
	 *
	 * As of Core Framework 1.10 there is no server-side generator: /update-main and
	 * /figma/update-preset-css both receive already-compiled CSS from a client and only
	 * file_put_contents() it, and App/Css/VariableExtractor.php parses CSS rather than emitting
	 * it. The feature check below therefore returns false today; it exists so a future Core
	 * Framework that ships a generator is used automatically instead of this service growing a
	 * parallel one that would drift from it.
	 *
	 * @return bool True only if Core Framework itself regenerated the file.
	 */
	private function regenerate_stylesheet(): bool {
		foreach ( array( 'generateStylesheet', 'regenerateStylesheet', 'buildStylesheet' ) as $method ) {
			if ( method_exists( '\CoreFramework\Helper', $method ) ) {
				$helper = new \CoreFramework\Helper();
				$helper->$method();
				return true;
			}
		}

		return false;
	}

	/**
	 * Run Core Framework's own cache purge, exactly as its REST write paths do.
	 *
	 * @return void
	 */
	private function purge_core_framework_cache(): void {
		if ( ! function_exists( 'CoreFramework' ) ) {
			return;
		}

		$core = \CoreFramework();

		if ( is_object( $core ) && method_exists( $core, 'purge_cache' ) ) {
			$core->purge_cache();
		}
	}

	/**
	 * Absolute path of the generated stylesheet, when Core Framework can tell us.
	 *
	 * @return string|null Stylesheet path, or null when Core Framework is not loaded.
	 */
	private function stylesheet_path(): ?string {
		if ( ! $this->is_core_framework_active() || ! method_exists( '\CoreFramework\Helper', 'getStylesheetPath' ) ) {
			return null;
		}

		$helper = new \CoreFramework\Helper();

		return (string) $helper->getStylesheetPath();
	}

	// =========================================================================
	// Token index
	// =========================================================================

	/**
	 * Build the full token index for a preset.
	 *
	 * @param \stdClass $preset Decoded preset.
	 * @return array<int, array<string, mixed>> Token entries, including private object handles.
	 */
	private function collect( \stdClass $preset ): array {
		$entries = array();

		foreach ( $this->color_groups( $preset ) as $group ) {
			$group_name = (string) ( $group->name ?? '' );

			foreach ( (array) ( $group->colors ?? array() ) as $color ) {
				if ( ! $color instanceof \stdClass || ! isset( $color->name ) || ! is_string( $color->name ) ) {
					continue;
				}

				$has_dark = ! empty( $color->isDarkMode ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.

				$entries[] = array(
					'stored_name'  => $color->name,
					'name'         => $this->public_name( $preset, $color->name ),
					'kind'         => 'color',
					'value'        => isset( $color->value ) ? (string) $color->value : null,
					'dark_value'   => $has_dark && isset( $color->darkValue ) ? (string) $color->darkValue : null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
					'group'        => $group_name,
					'section'      => 'modulesData.COLOR_SYSTEM',
					'base_name'    => $color->name,
					'writable'     => true,
					'_color'       => $color,
					'_group'       => $group,
					'_swatch'      => null,
					'_dark_swatch' => null,
					'_declaration' => null,
					'_css_object'  => null,
				);

				foreach ( array( 'shade', 'tint' ) as $variant ) {
					$light_key = 'shade' === $variant ? 'shades' : 'tints';
					$dark_key  = 'shade' === $variant ? 'darkShades' : 'darkTints';

					foreach ( (array) ( $color->{$light_key} ?? array() ) as $i => $swatch ) {
						if ( ! $swatch instanceof \stdClass || ! isset( $swatch->name ) || ! is_string( $swatch->name ) ) {
							continue;
						}

						$dark_list   = (array) ( $color->{$dark_key} ?? array() );
						$dark_swatch = ( $has_dark && isset( $dark_list[ $i ] ) && $dark_list[ $i ] instanceof \stdClass ) ? $dark_list[ $i ] : null;

						$entries[] = array(
							'stored_name'  => $swatch->name,
							'name'         => $this->public_name( $preset, $swatch->name ),
							'kind'         => 'shade' === $variant ? 'color_shade' : 'color_tint',
							'value'        => isset( $swatch->value ) ? (string) $swatch->value : null,
							'dark_value'   => ( null !== $dark_swatch && isset( $dark_swatch->value ) ) ? (string) $dark_swatch->value : null,
							'group'        => $group_name,
							'section'      => 'modulesData.COLOR_SYSTEM',
							'base_name'    => $color->name,
							'writable'     => true,
							'_color'       => $color,
							'_group'       => $group,
							'_swatch'      => $swatch,
							'_dark_swatch' => $dark_swatch,
							'_declaration' => null,
							'_css_object'  => null,
						);
					}
				}

				if ( empty( $color->transparent ) ) {
					continue;
				}

				foreach ( (array) ( $color->transparentVariables ?? array() ) as $step ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
					$alpha_name = $color->name . '-' . $step;

					$entries[] = array(
						'stored_name'  => $alpha_name,
						'name'         => $this->public_name( $preset, $alpha_name ),
						'kind'         => 'color_alpha',
						'value'        => null,
						'dark_value'   => null,
						'group'        => $group_name,
						'section'      => 'modulesData.COLOR_SYSTEM',
						'base_name'    => $color->name,
						'writable'     => false,
						'_color'       => $color,
						'_group'       => $group,
						'_swatch'      => null,
						'_dark_swatch' => null,
						'_declaration' => null,
						'_css_object'  => null,
					);
				}
			}
		}

		foreach ( self::SECTION_KEYS as $section ) {
			foreach ( $this->variable_groups( $preset, $section ) as $group ) {
				foreach ( (array) ( $group->cssObjects ?? array() ) as $css_object ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.
					if ( ! $css_object instanceof \stdClass ) {
						continue;
					}

					foreach ( (array) ( $css_object->declarations ?? array() ) as $declaration ) {
						if ( ! $declaration instanceof \stdClass || ! isset( $declaration->property ) || ! is_string( $declaration->property ) ) {
							continue;
						}

						$stored = ltrim( $declaration->property, '-' );

						if ( '' === $stored ) {
							continue;
						}

						$entries[] = array(
							'stored_name'  => $stored,
							'name'         => $this->public_name( $preset, $stored ),
							'kind'         => 'variable',
							'value'        => isset( $declaration->value ) ? (string) $declaration->value : null,
							'dark_value'   => null,
							'group'        => (string) ( $group->name ?? '' ),
							'section'      => $section,
							'base_name'    => $stored,
							'writable'     => true,
							'_color'       => null,
							'_group'       => $group,
							'_swatch'      => null,
							'_dark_swatch' => null,
							'_declaration' => $declaration,
							'_css_object'  => $css_object,
						);
					}
				}
			}
		}

		return $entries;
	}

	/**
	 * Find one token entry by any accepted spelling of its name.
	 *
	 * @param \stdClass $preset Decoded preset.
	 * @param string    $token  Token name.
	 * @return array<string, mixed>|null Entry, or null when the preset does not define it.
	 */
	private function find( \stdClass $preset, string $token ): ?array {
		$stored = $this->stored_name( $preset, $token );

		if ( '' === $stored ) {
			return null;
		}

		foreach ( $this->collect( $preset ) as $entry ) {
			if ( $entry['stored_name'] === $stored ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Colour-system groups, or an empty list when the preset has none.
	 *
	 * @param \stdClass $preset Decoded preset.
	 * @return array<int, \stdClass> Colour groups.
	 */
	private function color_groups( \stdClass $preset ): array {
		$groups = $preset->modulesData->COLOR_SYSTEM->groups ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON keys.

		if ( ! is_array( $groups ) ) {
			return array();
		}

		return array_values( array_filter( $groups, static fn( $group ) => $group instanceof \stdClass ) );
	}

	/**
	 * Variable-type stylesheet groups within one section.
	 *
	 * @param \stdClass $preset  Decoded preset.
	 * @param string    $section Section key.
	 * @return array<int, \stdClass> Variable groups.
	 */
	private function variable_groups( \stdClass $preset, string $section ): array {
		$groups = $preset->styleSheetData->{$section} ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.

		if ( ! is_array( $groups ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$groups,
				static fn( $group ) => $group instanceof \stdClass && 'variable' === ( $group->type ?? '' )
			)
		);
	}

	// =========================================================================
	// Naming and values
	// =========================================================================

	/**
	 * The preset's variable prefix, e.g. "cf-".
	 *
	 * @param \stdClass $preset Decoded preset.
	 * @return string Prefix, possibly empty.
	 */
	private function variable_prefix( \stdClass $preset ): string {
		$prefix = $preset->variablePrefix ?? ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Core Framework's own JSON key.

		return is_string( $prefix ) ? $prefix : '';
	}

	/**
	 * Reduce any accepted spelling of a token name to the form the preset stores.
	 *
	 * Core Framework stores names bare and prepends "--" plus the preset's variable prefix when
	 * it emits CSS, so "--cf-darkblue1", "cf-darkblue1" and "darkblue1" all address the same
	 * token. Getting this wrong is the exact defect that shipped "----cf-*" into Bricks' variable
	 * CSS, so both halves are stripped here rather than at each call site.
	 *
	 * @param \stdClass $preset Decoded preset.
	 * @param string    $token  Token name in any accepted spelling.
	 * @return string Bare stored name, or '' when the input was empty.
	 */
	private function stored_name( \stdClass $preset, string $token ): string {
		/*
		 * A token name becomes a CSS custom property, so it is reduced to the character set a
		 * custom property can safely carry rather than merely trimmed. sanitize_text_field() is
		 * deliberately not used anywhere in this class: it strips %XX sequences, which is
		 * harmless for names but silently corrupts CSS values such as hsla(0,0%,25%,1).
		 */
		$name = ltrim( trim( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $token ) ), '-' );

		if ( '' === $name ) {
			return '';
		}

		$prefix = $this->variable_prefix( $preset );

		if ( '' !== $prefix && str_starts_with( $name, $prefix ) ) {
			$candidate = substr( $name, strlen( $prefix ) );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return $name;
	}

	/**
	 * The CSS custom property a stored name emits.
	 *
	 * @param \stdClass $preset Decoded preset.
	 * @param string    $stored Bare stored name.
	 * @return string Full custom property name, e.g. "--cf-darkblue1".
	 */
	private function public_name( \stdClass $preset, string $stored ): string {
		return '--' . $this->variable_prefix( $preset ) . $stored;
	}

	/**
	 * Strip the private object handles from an index entry.
	 *
	 * @param array<string, mixed> $entry Index entry.
	 * @return array<string, mixed> Caller-safe entry.
	 */
	private function public_entry( array $entry ): array {
		foreach ( array_keys( $entry ) as $key ) {
			if ( str_starts_with( (string) $key, '_' ) ) {
				unset( $entry[ $key ] );
			}
		}

		return $entry;
	}

	/**
	 * Validate and normalize a caller-supplied token value.
	 *
	 * @param mixed $value Raw value, or null when the caller omitted it.
	 * @return string|null|\WP_Error Cleaned value, null when omitted, or error when unusable.
	 */
	private function clean_value( mixed $value ): string|null|\WP_Error {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			return new \WP_Error(
				'invalid_value',
				__( 'Token values must be CSS value strings, e.g. "#11175e" or "clamp(1rem, 2vw, 2rem)".', 'bricks-mcp' )
			);
		}

		/*
		 * Not sanitize_text_field(): it strips %XX sequences, which turns hsla(0,0%,25%,1) —
		 * the live value of --cf-text-body — into something subtly different while still
		 * looking like a plausible colour. Tags and control characters are removed instead, and
		 * FORBIDDEN_VALUE_PATTERNS below rejects anything that could escape the declaration.
		 */
		$clean = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_strip_all_tags( $value ) ) );

		if ( '' === $clean ) {
			return new \WP_Error(
				'invalid_value',
				__( 'Token values cannot be empty. Omit the field to leave a value unchanged.', 'bricks-mcp' )
			);
		}

		foreach ( self::FORBIDDEN_VALUE_PATTERNS as $pattern ) {
			if ( false !== stripos( $clean, $pattern ) ) {
				return new \WP_Error(
					'invalid_value',
					sprintf(
						/* translators: %s: Rejected substring */
						__( 'Token value rejected: it contains "%s", which would break out of the CSS declaration it is written into.', 'bricks-mcp' ),
						$pattern
					)
				);
			}
		}

		return $clean;
	}

	/**
	 * Infer Core Framework's colour format tag from a value.
	 *
	 * @param string $value     Colour value.
	 * @param string $requested Explicit format from the caller, if any.
	 * @return string One of Core Framework's accepted format tags.
	 */
	private function detect_format( string $value, string $requested = '' ): string {
		$allowed = array( 'hex', 'hexa', 'rgb', 'rgba', 'hsl', 'hsla', 'raw' );

		if ( in_array( $requested, $allowed, true ) ) {
			return $requested;
		}

		$value = strtolower( trim( $value ) );

		if ( str_starts_with( $value, '#' ) ) {
			return in_array( strlen( $value ), array( 5, 9 ), true ) ? 'hexa' : 'hex';
		}

		foreach ( array( 'hsla', 'hsl', 'rgba', 'rgb' ) as $prefix ) {
			if ( str_starts_with( $value, $prefix . '(' ) ) {
				return $prefix;
			}
		}

		return 'raw';
	}

	/**
	 * Generate an id in the shape Core Framework uses for preset objects.
	 *
	 * Core Framework's own ids are 26-character Crockford base32 ULIDs. Uniqueness within the
	 * preset is all that matters here, so a random string of the same shape is used rather than
	 * a real ULID implementation.
	 *
	 * @return string Generated id.
	 */
	private function generate_preset_id(): string {
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$id       = '';

		for ( $i = 0; $i < 26; $i++ ) {
			$id .= $alphabet[ random_int( 0, 31 ) ];
		}

		return $id;
	}

	/**
	 * The standard "no such token" error.
	 *
	 * @param string $token Requested token name.
	 * @return \WP_Error Not-found error.
	 */
	private function not_found_error( string $token ): \WP_Error {
		return new \WP_Error(
			'token_not_found',
			sprintf(
				/* translators: %s: Token name */
				__( 'Core Framework token "%s" not found in the active preset. Use action "list" to see what it defines; note that Bricks global variables are a separate surface managed through domain "global_variable".', 'bricks-mcp' ),
				$token
			)
		);
	}
}
