# Jobayer AI Agent 0.7.1

Installable WordPress agent for the Jobayer WordPress AI MCP server.

## Core capabilities

- HMAC-authenticated WordPress REST agent
- Theme/plugin source read/search/patch
- Encrypted automatic backups and rollback
- Content create/read/update/rollback with Rank Math and Yoast SEO fields (SEO title, meta description, focus keyphrase)
- Secure Media Library image uploads from ChatGPT/MCP file URLs
- Same-site URL checks
- One-click MCP site registration
- Encrypted persistent site registry on the bootstrap WordPress site
- Native WordPress update notifications from public GitHub Releases

## Updates

Version 0.6.0 introduces the GitHub updater. Sites currently running 0.5.0 need to install 0.6.0 once manually because 0.5.0 does not yet know how to check GitHub for updates.

After 0.6.0 is installed, future published releases from this repository appear in the normal WordPress Plugins screen as available updates.

Release ZIPs keep the existing plugin directory name `jobayer-ai-agent/` so WordPress upgrades the current installation instead of creating a second plugin copy.

## Media uploads

The authenticated `/wp-json/jobayer-ai/v1/media/upload` endpoint accepts a temporary HTTPS `source_url` plus optional filename, title, alt text, caption, description, parent post ID, and featured-image flag. The server validates that the downloaded file is a real JPEG, PNG, WebP, or GIF before adding it to the WordPress Media Library.

Advanced ACF/WooCommerce/taxonomy tools remain intentionally outside the core profile until the stable tool set is proven across connected sites.


## Yoast SEO support (0.7.1)

When Yoast SEO is active, authenticated content reads include the existing `yoast`
object with `title`, `description`, and `focus_keyword`, plus `yoast_available`.
The HMAC-authenticated `/content/update` endpoint accepts an optional `yoast`
object containing only those fields. Each write requires the current
`expected_revision_hash`, backs up the original post and SEO metadata, and
is rollback-compatible using `/content/rollback`.

Only specified Yoast keys are modified; `null` or an empty string clears a field.
Yoast's normal post-save watcher is triggered to refresh the indexable record.
Yoast must be active for writes, but existing Rank Math behavior remains unchanged.

For example:

```json
{
  "id": 5,
  "expected_revision_hash": "<hash from /content/get>",
  "yoast": {
    "title": "Drywall Repair Tulsa | DRS Pros",
    "description": "Professional drywall repairs in Tulsa. Request a quote.",
    "focus_keyword": "drywall repair Tulsa"
  }
}
```

The paired MCP server must also be updated to expose the `yoast` fields.
Do not edit the live website until both the agent and MCP server versions are deployed.
