# MyBB CloudFlare Manager

Maintained fork: <https://github.com/astoltz/MyBB-CloudFlare-Manager>

This plugin manages common Cloudflare zone operations from the MyBB ACP. It started from the older `dequeues/MyBB-CloudFlare-Manager` codebase and is now maintained as a fork with MyBB 1.8 compatibility, security hardening, and Cloudflare API updates.

## Current fork changes

- GitHub metadata, issue links, and the built-in version checker now point at this fork.
- The plugin supports a scoped Cloudflare API token and falls back to the legacy global API key/email pair only when no token is configured.
- cURL transport now verifies TLS certificates and returns structured API errors for network and malformed-response failures.
- Existing installs can pick up new settings on activate without requiring a clean reinstall.
- Added ACP controls for browser-side Cloudflare integrations via Configuration Rules:
  - `disable_rum`
  - `disable_zaraz`
- Added extension hooks for setting definitions and footer backlink output:
  - `cloudflare_setting_definitions`
  - `cloudflare_backlink_html`
  - `cloudflare_api_headers`
  - `cloudflare_request_context`

## Features

- Overview dashboard with zone details and traffic summaries
- Development mode toggle
- Firewall access rule management
  - whitelist
  - blacklist
  - challenge
  - IPv6 toggle
- Cache management
  - cache level
  - purge entire cache
  - purge specific URLs
- Security level management
- Browser integrations management
  - disable Cloudflare Web Analytics / RUM
  - disable Cloudflare Zaraz
- About / update check / issue redirect pages

## Configuration

The plugin needs:

- `cloudflare_domain`
- Either:
  - `cloudflare_api_token`
  - or the legacy pair `cloudflare_api` + `cloudflare_email`

Use an API token when possible. The legacy global API key path is still supported for older installs but should be treated as compatibility mode.

## Real client IP behind Cloudflare

This plugin does **not** rewrite MyBB's request IP handling by itself.

If your forum is behind Cloudflare and you want MyBB to log the real visitor IP, the recommended pattern is:

1. Enable MyBB forwarded-IP handling with `ip_forwarded_check`.
2. Patch MyBB core IP resolution to prefer Cloudflare's `CF-Connecting-IP` header before the generic forwarded-IP headers.

The relevant MyBB core change is in `inc/functions.php`, in the IP resolution path. The precedence should be:

1. `HTTP_CF_CONNECTING_IP`
2. `HTTP_X_FORWARDED_FOR`
3. `HTTP_X_REAL_IP`
4. fallback to `REMOTE_ADDR`

This fork documents that requirement because the Cloudflare plugin and the forum's application-layer IP handling are separate concerns.

## Upgrade notes

- Activate the plugin after deploying updated files so missing settings are synchronized into MyBB settings.
- The update checker reads the version constant from this fork's `inc/plugins/cloudflare.php`.
- The ACP issue/report page redirects to this fork's GitHub issues list.

## Development notes

- Formatting defaults are defined in `.editorconfig`.
- The codebase follows existing MyBB 1.8 conventions instead of forcing a large style-only refactor.
- Security-sensitive changes in this fork focused on:
  - token support
  - TLS verification
  - safer remote version checks
  - clearer credential validation

## Support

- Repository: <https://github.com/astoltz/MyBB-CloudFlare-Manager>
- Issues: <https://github.com/astoltz/MyBB-CloudFlare-Manager/issues>

## Credit

- Original author: Nathan Malcolm
- Prior maintenance: MyBB Security Group / dequeues
- Current fork maintenance: astoltz
