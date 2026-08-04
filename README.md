# Bricks MCP

AI-powered assistant for [Bricks Builder](https://bricksbuilder.io/). Control your website with natural language through MCP-compatible AI tools like Claude.

**Talk to your website. It listens.**

## What It Does

Bricks MCP is a WordPress plugin that implements an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server, letting AI assistants read and write your Bricks Builder site. Connect Claude Code, Claude Desktop, Cursor, or any compatible MCP client and manage pages, templates, global classes, theme styles, WooCommerce layouts, and more through natural language.

## Features

- 11 canonical MCP tools covering the full Bricks Builder data model
- Read and write pages, templates, elements, and global settings
- WooCommerce support (product pages, cart, checkout, account templates)
- Global classes, theme styles, typography scales, color palettes, variables
- Media library management with Unsplash integration
- WordPress menus, fonts, and custom code management
- Built-in connection tester and config snippet generator
- Works with Claude Code, Claude Desktop, Cursor, and other compatible MCP clients

## Requirements

- WordPress 6.4+
- PHP 8.2+
- Bricks Builder 1.6+

## Installation

No Composer required. The plugin ships its own PSR-4 autoloader (`includes/Autoloader.php`) that maps the `BricksMCP\` namespace to the `includes/` directory. Simply upload and activate -- no build step needed.

1. Download the latest release from [GitHub Releases](https://github.com/COPE-Health-Scholars/bricks-mcp/releases)
2. Upload to your WordPress site via Plugins > Add New > Upload Plugin
3. Activate the plugin
4. Go to Settings > Bricks MCP to configure

### LocalWP / Manual Server Setup

If you run LocalWP or a custom server stack, PHP and Nginx may need configuration changes for stable MCP connections (SSE streaming requires longer timeouts and unbuffered responses). See the [LocalWP Setup Guide](docs/LOCALWP_SETUP.md) for step-by-step instructions.

## Connecting Your AI Tool

### Claude Code

```bash
claude mcp add bricks-mcp https://yoursite.com/wp-json/bricks-mcp/v1/mcp --transport http
```

### Claude Desktop / Cursor / Other MCP Clients

Add to your MCP config (`.mcp.json` or equivalent):

```json
{
  "mcpServers": {
    "bricks-mcp": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/bricks-mcp/v1/mcp",
      "headers": {
        "Authorization": "Basic BASE64_ENCODED_CREDENTIALS"
      }
    }
  }
}
```

Authentication uses WordPress [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) (Users > Profile > Application Passwords).

ChatGPT is not currently supported as an MCP client for this plugin. ChatGPT's MCP flow requires OAuth 2.1 and dynamic client registration, while Bricks MCP uses WordPress Application Passwords for authentication.

## Available Tools

| Tool | Description |
|------|-------------|
| `get_site_info` | Read WordPress site details and run connection diagnostics |
| `get_builder_guide` | Read the Bricks builder guide before editing content |
| `bricks` | Manage Bricks builder settings, schema, queries, and references |
| `content` | Manage WordPress and Bricks content across posts, pages, and elements |
| `template` | Manage Bricks templates, conditions, and template taxonomies |
| `design` | Manage Bricks design tokens across classes, styles, palettes, variables, and fonts |
| `media` | Upload media, search Unsplash, manage library |
| `menu` | WordPress menu management |
| `component` | Bricks component (reusable element) management |
| `woocommerce` | WooCommerce page templates and product layouts |
| `code` | Page-level CSS and JavaScript |

## Try It Out

Once connected, try these prompts with your AI tool. Each one exercises different MCP tools and can be used to verify the integration is working.

### Basic checks

```
What WordPress site am I connected to? What version is it running?
```

```
List all active plugins on this site.
```

```
Show me the Bricks Builder guide so I understand how to build pages.
```

### Page building

```
Create a new page called "About Us" with a hero section containing a heading
"About Our Company" and a paragraph of placeholder text below it.
```

```
Add a two-column container to the About Us page. Put a heading and text in the
left column, and an image placeholder in the right column.
```

```
List all my pages and show which ones use Bricks Builder.
```

### Global styles

```
Create a global class called "btn-primary" with 12px 24px padding, white text,
#2563eb background, 6px border radius, and 600 font weight.
```

```
Create a color palette called "Brand Colors" with: Primary #2563eb, Secondary
#7c3aed, Accent #f59e0b, and Neutral #64748b.
```

```
Create a theme style that sets all H1 headings to 48px bold and H2 to 36px
semibold.
```

### Templates

```
Create a section template called "CTA Banner" with a dark background section
containing a centered heading and a button.
```

```
List all my templates and their types.
```

### Menus

```
Create a navigation menu called "Main Menu" with links to Home (/), About
(/about/), Services (/services/), and Contact (/contact/).
```

### Advanced

```
Show me the Bricks settings and breakpoints configured on this site.
```

```
Create a typography scale with steps: xs 12px, sm 14px, base 16px, lg 20px,
xl 24px, 2xl 32px, 3xl 48px. Use the prefix --fs-.
```

```
Create a set of global CSS variables for spacing: --space-xs 4px, --space-sm
8px, --space-md 16px, --space-lg 32px, --space-xl 64px.
```

## Configuration

Go to **Settings > Bricks MCP** in WordPress admin:

- **Enable MCP Server** — toggle the server on/off
- **Require Authentication** — restrict access to authenticated users
- **Custom Base URL** — for reverse proxies or custom domains
- **Dangerous Actions** — enable write access to global Bricks settings and code execution

## Extending

Add custom tools using the `bricks_mcp_tools` filter:

```php
add_filter( 'bricks_mcp_tools', function( $tools ) {
    $tools['my_custom_tool'] = [
        'name'        => 'my_custom_tool',
        'description' => 'My custom tool description',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [],
        ],
        'handler'     => function( $args ) {
            return ['result' => 'success'];
        },
    ];
    return $tools;
});
```

## Local Development

Prerequisites: [Docker](https://docs.docker.com/get-docker/) and [Node.js](https://nodejs.org/) 18+.

```bash
git clone https://github.com/COPE-Health-Scholars/bricks-mcp.git
cd bricks-mcp
npm install
npm run start
```

That's it. The first start takes a few minutes to download WordPress and set up the containers. Composer dependencies are installed automatically inside the containers.

Local site: http://localhost:8888 (admin / password)

### Available Commands

```bash
npm run start        # Start WordPress environment (Docker via wp-env)
npm run stop         # Stop the environment
npm run test         # Run all PHPUnit tests
npm run test:unit    # Run unit tests only
npm run lint         # WordPress coding standards check
npm run lint:fix     # Auto-fix linting issues
npm run wp <command> # Run WP-CLI commands
npm run logs:watch   # Tail the PHP debug log
```

### How It Works

The dev environment uses [@wordpress/env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (wp-env), which runs WordPress in Docker containers. The plugin directory is mounted directly into the container, so file changes are reflected immediately.

A [mu-plugin](mu-plugins/wp-env-fixes.php) is included to fix Docker networking quirks (REST API loopback and Application Passwords over HTTP).

## License

GPL-2.0-or-later
