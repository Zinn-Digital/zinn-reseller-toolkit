<?php
/**
 * Loads the MCP kit. The host plugin requires this file, then calls `Server::boot()`.
 *
 * The kit turns a plugin's WordPress abilities (the Abilities API in core since WordPress 6.9)
 * into an MCP server on the site itself, using the official WordPress MCP adapter the plugin
 * bundles under vendor/wordpress/ (wp/bin/vendor-mcp-adapter.sh). Rendered into every plugin
 * whose wp/plugins.json entry has `mcp_adapter: true` by wp/bin/build-mcp-kit.php.
 *
 * ⛔ Explicit requires, no autoloader of our own classes: every copy of the kit on a site lives
 * in its own namespace, the same rule as the AI core (docs/adr/0034).
 *
 * Generated from wp/packages/zinn-mcp-kit/src/load.php by wp/bin/build-mcp-kit.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package Zinn\Reseller\McpKit
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-adapter.php';
require_once __DIR__ . '/class-ability.php';
require_once __DIR__ . '/class-rest-bridge.php';
require_once __DIR__ . '/class-oauth.php';
require_once __DIR__ . '/class-server.php';
