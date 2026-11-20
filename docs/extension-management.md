# Official WordPress.org plugin and theme management

Implemented in Jobayer AI Agent v0.8.0; deploy this WP plugin and the Node MCP independently.

## Supported actions

- List installed plugins/themes, versions and activation state.
- Inspect an exact WordPress.org plugin or theme slug without installing.
- Install from the official WordPress.org download host; activation is optional and OFF by default.
- Activate an already installed plugin or theme after explicit approval.
- No deactivate, delete, updates, custom ZIPs, arbitrary download URLs, shell commands or repository code execution. The Agent plugin cannot install/activate itself via these routes.

## Three independent security gates

1. WordPress administrator: go to Tools → Jobayer AI Agent, select **Allow my authenticated MCP to install and activate WordPress.org plugins/themes**, and save. Disabled by default for all sites.
2. Node MCP Hostinger environment: set JAI_ALLOW_WRITES=true, JAI_ALLOW_EXTENSION_MANAGEMENT=true, and JAI_EXTENSION_ALLOWED_SITES to a comma-separated list of the exact site aliases (for example honored-khaki-swallow-85-hostingersite-com). No site aliases means all installation and activation actions are blocked.
3. Each call requires exact site alias, type (plugin/theme), official directory slug, and confirmed=true after explicit user approval. Activating a theme changes the site appearance, so should be separately approved.

The Agent verifies HMAC signatures and request nonce replay protection. Extension operations verify site opt-in, reject multisite installations, and respect WordPress file modification policy. Installation uses WordPress core Plugin_Upgrader and Theme_Upgrader. Plugin and theme activation uses the native WordPress APIs. Extensions may affect site security, functionality and appearance; back up staging or production before enabling the feature.

## Signed REST endpoints

All requests are signed POST to /wp-json/jobayer-ai/v1/<route>:

- extensions/list: empty JSON body. Returns installed plugins/themes and site opt-in status.
- extensions/inspect: JSON type=plugin, slug=woocommerce. Returns WordPress.org metadata (read-only).
- extensions/install: JSON type=plugin, slug=woocommerce, activate=false, confirmed=true.
- extensions/activate: JSON type=plugin, slug=woocommerce, confirmed=true.

The theme equivalent uses type=theme with a WordPress.org theme slug such as astra. No arbitrary package source URL is accepted. Site opt-in and MCP site allowlist are required for writes.

## Deployment and release

After merging, the existing GitHub Actions release workflow publishes a versioned jobayer-ai-agent.zip on v0.8.0. The installed Agent v0.7.0 should offer an update from GitHub under WordPress Plugins (allow for its update cache). Upgrade the Agent through the WordPress dashboard and verify Tools → Jobayer AI Agent has the new opt-in setting. Separately deploy the Node MCP branch, configure its environment flags, and refresh the ChatGPT MCP tool list.

If WordPress.org is unavailable or Hostinger filesystem permissions prevent installation, the tool reports a failure. A request timeout may mean the install completed: first run extensions/list before retrying. Never automatically retry an ambiguous extension installation.

## WooCommerce staging

For the staging alias honored-khaki-swallow-85-hostingersite-com, list installed extensions, inspect the official woocommerce slug, then install/activate ONLY after specific approval. Verify the site reports a WooCommerce version in a subsequent site_info request. Payment, shipping and catalog setup are separate operations.
