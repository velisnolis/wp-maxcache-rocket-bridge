# WP Rocket + MAxCache Bridge

WordPress plugin that keeps `WP Rocket` as the primary cache UI while managing a single Apache `mod_maxcache` layer in a safe, predictable way.

The intended workflow is simple:

1. install and activate the bridge
2. switch the site to `managed mode`
3. keep making normal changes in `WP Rocket`
4. open the bridge only for diagnostics, compatibility overrides, or takeover/rollback operations

This plugin does not try to replace `WP Rocket` or `AccelerateWP`. Its purpose is to keep one managed `MaxCache` configuration aligned with CloudLinux / AccelerateWP-style defaults and real-world setups such as Cloudflare.

## Features

- Detects whether the environment is compatible with `WP Rocket` + Apache `mod_maxcache`
- Generates a single `MaxCache` block based on:
  - CloudLinux / AccelerateWP baseline rules
  - exclusions from `WP Rocket`
  - bridge-specific overrides
- Detects and manages `.htaccess` ownership states:
  - `managed`
  - `unmanaged`
  - `external`
  - `conflict`
- Can take over an existing external `MaxCache` configuration and move the site to `managed mode`
- Keeps `.htaccess` backups and exposes rollback
- Watches `wp_rocket_settings` and can auto-apply the managed block when the bridge owns it
- Adapts `MaxCachePath` automatically for:
  - WebP variants via `cache_webp` -> `{WEBP_SUFFIX}`
  - logged-in user cache via `cache_logged_user` + `secret_cache_key` -> `MaxCacheLoggedHash` + `{USER_SUFFIX}`

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WP Rocket active
- Apache 2.4 with `mod_maxcache`

## Recommended Workflow

1. Install and activate the plugin.
2. Open `Tools > MAxCache Bridge`.
3. Run environment checks.
4. If an external or duplicate MaxCache block exists, run `Take over MaxCache management`.
5. Confirm the site reaches `managed` + `in_sync`.
6. From that point on, keep using `WP Rocket` for normal day-to-day cache configuration.

## Configuration Model

The effective snippet is built with this priority:

1. CloudLinux / AccelerateWP baseline
2. exclusions and signals from `WP Rocket`
3. explicit bridge overrides

Bridge options stored in `wmrb_options`:

- `bridge_enabled`
- `debug_mode`
- `auto_sync_enabled`
- `auto_apply_htaccess`
- `serve_gzip_variant`
- `serve_webp_variant`
- `custom_cache_path_template`

## Gzip Variant

`serve_gzip_variant` controls whether the generated `MaxCachePath` points to:

- `.../index{MOBILE_SUFFIX}{SSL_SUFFIX}.html`
- or `.../index{MOBILE_SUFFIX}{SSL_SUFFIX}.html{GZIP_SUFFIX}`

Practical recommendation:

- behind Cloudflare or other proxies/CDNs: `false` is usually safer
- direct origin setups with correct headers: `true` can be used

## WebP Variant

The bridge detects `cache_webp` from `WP Rocket` and automatically switches `MaxCachePath` to the WebP-aware variant, for example `index-https-webp.html`.

There is also a manual `serve_webp_variant` override for diagnostics or edge cases.

Practical note:

- if `WP Rocket` stops generating WebP cache files, the bridge falls back to the non-WebP path
- the site can still work, but static `mod_maxcache` hits may disappear until matching non-WebP files exist

## Logged-In User Cache

When `WP Rocket` enables `cache_logged_user`, the bridge can follow that mode automatically.

If `secret_cache_key` is also available, the managed snippet will:

- add `MaxCacheLoggedHash`
- use `{USER_SUFFIX}` in `MaxCachePath`
- stop excluding `wordpress_logged_in_.+`

If `cache_logged_user` is enabled but `secret_cache_key` is missing or unusable, the bridge stays in safe mode and keeps the logged-in cookie exclusion.

Current scope:

- supported: per-user logged-in cache via `{USER_SUFFIX}`
- not yet implemented: shared logged-in cache via `{USER_SHARED_SUFFIX}`

## Management Modes

The UI shows the detected ownership state of `.htaccess`:

- `managed`: the bridge owns the only active `MaxCache` block
- `unmanaged`: no `MaxCache` block exists yet
- `external`: a non-WMRB `MaxCache` block exists
- `conflict`: more than one active `MaxCache` block exists

`auto_apply` only runs in `managed` or `unmanaged`.

## Takeover

When the plugin detects `external` or `conflict`, you can run `Take over MaxCache management`.

That action:

1. creates a `.htaccess` backup
2. removes existing `MaxCache` blocks
3. writes a single WMRB-managed block
4. moves the site to `managed` + `in_sync`

## Quick Test

The built-in quick test uses the public WordPress URL.

That means:

- it may pass through Cloudflare or other proxies
- it is useful as a general signal
- it does not replace direct origin validation with `curl --resolve`

## Rollback

Backups are stored in `wp-content/wmrb-backups/`.

If a deployment fails:

1. run `Rollback last backup`
2. purge `WP Rocket`
3. validate headers again at origin

## Real Validation Performed

Validated environments:

- `milatalent.cat`
  - original AccelerateWP-style pattern
  - origin headers confirmed with `last-modified`, `accept-ranges`, and `gzip`
- `reliquiaesanctorumincatalonia.cat`
  - real takeover to `managed mode`
  - Cloudflare in front, `serve_gzip_variant = false`
  - origin remained correct after takeover
- `www.injecciodeplastics.com` + `www.inyecciondeplastico.es`
  - WordPress + WPML with one domain per language
  - real validation of `{HTTP_HOST}` for multilingual multi-domain cache paths
  - real validation of `cache_webp = 1`
  - temporary test switching back to the non-WebP path and restoring it afterwards

## Updates via GitHub

The plugin checks GitHub Releases at:

`https://api.github.com/repos/velisnolis/wp-maxcache-rocket-bridge/releases/latest`

Expected release flow:

1. tag `vX.Y.Z`
2. create a GitHub release
3. attach `wp-maxcache-rocket-bridge.zip`

## Disclaimer

This plugin is an independent utility built to operate with WordPress, WP Rocket, Apache `mod_maxcache`, and publicly documented CloudLinux / AccelerateWP configuration patterns.

It is not affiliated with, endorsed by, or officially supported by WP Rocket, CloudLinux, AccelerateWP, Apache, or any other vendor mentioned in this repository. It is distributed as-is, without warranties, and each deployment remains the responsibility of the operator.
