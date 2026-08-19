# Bricks MCP

AI-powered assistant for [Bricks Builder](https://bricksbuilder.io/). Control your website with natural language through MCP-compatible AI tools like Claude.

**Talk to your website. It listens.**

## What It Does

Bricks MCP is a WordPress plugin that implements an [MCP (Model Context Protocol)](https://modelcontextprotocol.io/) server, letting AI assistants read and write your Bricks Builder site. Connect Claude Code, Claude Desktop, Cursor, or any compatible MCP client and manage pages, templates, global classes, theme styles, WooCommerce layouts, and more through natural language.

## Features

- 12 canonical MCP tools covering the full Bricks Builder data model, plus WPForms
- Read and write pages, templates, elements, and global settings
- WooCommerce support (product pages, cart, checkout, account templates)
- Global classes, theme styles, typography scales, color palettes, variables
- Media library management with Unsplash integration
- WordPress menus, fonts, and custom code management
- WPForms form settings, notification wiring, field properties, and entry cleanup
- Built-in connection tester and config snippet generator
- Works with Claude Code, Claude Desktop, Cursor, and other compatible MCP clients

## Requirements

- WordPress 6.4+
- PHP 8.2+
- Bricks Builder 1.6+ for the Bricks tools; the WordPress-wide tools and abilities work without it
- WPForms (any edition) for the `wpforms` tool; WPForms Pro for entry deletion

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
| `wpforms` | Read WPForms forms and write settings, notifications, field properties, and entries |

## WPForms

WPForms exposes its own abilities for AI clients, but its editing ability accepts only
`form_title`, `form_desc` and `submit_text`. Everything else is readable and unwritable, and the
usual workarounds are closed: the REST and XML-RPC post routes return 401 on the `wpforms` post
type even for an administrator who authored the form, so no role, capability or firewall change
opens that lane. WPForms wants writes to go through its own save path.

The `wpforms` tool does exactly that. It reads the stored form data, deep-merges your patch into
it, and hands the whole structure back to `wpforms()->form->update()` — the same call the form
builder makes when a human clicks Save. That reaches:

- **Notifications** — `settings.notifications` (recipient, subject, sender name, sender address,
  reply-to) and `notification_enable`
- **Spam and submission toggles** — `honeypot`, `antispam`, `ajax_submit`
- **Field properties** — including a select's `placeholder` prompt, which the WPForms field
  schema does not expose
- **Entries** — deleting test submissions by ID (WPForms Pro, which is what stores entries)

### Spam protection: global list, per-form switch

WPForms splits keyword filtering in two, and the halves fail independently:

- The **keyword list is site-global**, stored in the `wpforms_keyword_filter_keywords` option as a
  JSON array (not a serialized one), with autoload off.
- The **filter that reads it is a per-form toggle**, `settings.anti_spam.keyword_filter.enable`,
  and new forms ship with it **off**.

So a site can carry a long, well-tuned list that no recently built form consults. Reading one
form's settings cannot show that. `spam_audit` can:

```
Run a WPForms spam audit and tell me which forms have the keyword filter switched off.
```

It returns every form's `keyword_filter`, `country_filter`, `time_limit`, `antispam_v3`,
`honeypot`, `store_spam_entries` and `filtering_store_spam` alongside the global list and a count
of how many forms are ignoring it.

`get_keywords` reads the list; `update_keywords` writes it with `mode` = `add` (default), `remove`
or `replace`. Adding is the default deliberately — the list is shared by every form and is usually
the accumulated record of past spam waves, so replacing it is something you ask for by name.

One trap the API handles for you: when the option has **never been saved**, WPForms falls back to
five built-in keywords that are genuinely live on the site. Reading the bare option would see
nothing there, and a naive first write would delete them. `update_keywords` bases its merge on the
effective list, so the defaults carry forward.

### What a keyword actually matches

Worth knowing before you pick keywords, because the matching is narrower than it looks. WPForms
compiles each keyword to `/(?<=^|\W)keyword(?=\W|$)/i`, which means:

- **Case-insensitive** — `corGM`, `CORGM` and `corgm` are one rule, and the API collapses such
  duplicates for you.
- **Whole word or phrase only** — a keyword never matches inside a longer word. `corGM` blocks
  `corGM` and `corGM spam`, but **not** `corGMartin` or `xcorGM`. If the string you're targeting is
  embedded in a larger token, the keyword filter will not catch it.
- **Punctuation is literal** — `cutt.ly` matches `cutt.ly/abc` and `https://cutt.ly/abc`, since
  `/` and `:` are non-word characters on both sides.

It also only scans a subset of fields: `text`, `textarea`, `name`, `email`, `address`, `url` and
`richtext`. Spam sitting in a select, phone or number field is never keyword-checked.

### Merge semantics

Nested objects merge key by key, so patching one notification field leaves its siblings alone.
Arrays and scalars replace wholesale, so a shorter `choices` list actually shortens the stored
one. A `null` value deletes the key outright, which is different from emptying it.

Read the form first — patches land on live data:

```
Read form 1631's settings, then set its notification recipient to forms@example.org
and turn on the honeypot.
```

### Verifying a write

Every write returns `unverified_paths`: the patched paths whose stored value came back different
from what was sent. WPForms sanitises on save, so a non-empty list is not automatically a failure
— but it is the difference between a write that reported success and a value that is actually
stored. Treat it as the real result.

### Also available as abilities

The same operations register with the WordPress Abilities API as
`bricks-mcp/wpforms-get-form`, `bricks-mcp/wpforms-update-form-settings`,
`bricks-mcp/wpforms-update-field`, `bricks-mcp/wpforms-delete-entries`,
`bricks-mcp/wpforms-get-spam-keywords`, `bricks-mcp/wpforms-update-spam-keywords` and
`bricks-mcp/wpforms-spam-audit`. A client already talking to the site's abilities surface picks
them up with no new credential and no second endpoint — they inherit the same application-password
authentication and capability gate as the abilities WPForms registers for itself. Both routes call
the same service, so there is one implementation and one set of capability checks behind them.

Capabilities go through `wpforms_current_user_can()`, so WPForms' own per-form mapping is what
applies: the `view_forms` category to read, `edit_forms` to write, and `delete_entries` to delete
entries. Those are WPForms capability *categories*, not WordPress capabilities — each expands to
the own/others pair that actually sits on the role, so `view_forms` becomes
`wpforms_view_own_forms` + `wpforms_view_others_forms`. Over the MCP endpoint they sit behind the
server's existing `manage_options` gate; over the Abilities API they are the gate, alongside
whatever authentication the abilities client already passed.

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
