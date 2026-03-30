# WP Rocket + MAxCache Bridge Spec

## Goal

Build a WordPress plugin that keeps `WP Rocket` as the primary cache and invalidation UI while the bridge governs a single Apache `mod_maxcache` layer.

The product goal is not "a second cache console". The target flow is:

1. install the plugin
2. move the site to `managed mode`
3. keep using `WP Rocket`
4. open the bridge only for diagnostics, compatibility, takeover, or rollback

## Product Principles

- `WP Rocket` is the main source of day-to-day cache changes
- the bridge only adds governance over `mod_maxcache`
- the base configuration should stay close to official CloudLinux / AccelerateWP defaults
- the bridge must not silently coexist with multiple active `MaxCache` blocks
- special cases such as Cloudflare should be explicit overrides, not hidden hacks

## Validated Context

- `milatalent.cat` uses the original AccelerateWP-style single-block pattern
- `reliquiaesanctorumincatalonia.cat` was migrated in a real test to `managed mode`
- `cf-cache-status: DYNAMIC` is acceptable when origin still serves the correct static pattern
- `www.injecciodeplastics.com` / `www.inyecciondeplastico.es` validated multilingual multi-domain handling through `{HTTP_HOST}`
- `www.injecciodeplastics.com` also validated the real `cache_webp` case

## Non-Goals

- replacing `WP Rocket`
- implementing a second application cache
- claiming official support from CloudLinux, WP Rocket, or Apache
- managing Cloudflare rules automatically

## Technical Requirements

- WordPress 6.0+
- PHP 7.4+
- WP Rocket active
- Apache 2.4 with `mod_maxcache`
- WP Rocket cache layout compatible with `MaxCachePath`

## Architecture

### 1. Configuration Hierarchy

The final snippet is built in this order:

1. CloudLinux / AccelerateWP baseline
2. exclusions and signals from `WP Rocket`
3. explicit bridge overrides

### 2. Ownership Modes

The bridge detects one of these modes:

- `managed`
- `unmanaged`
- `external`
- `conflict`
- `unreadable`

Rules:

- `auto_apply` is only allowed in `managed` or `unmanaged`
- in `external` or `conflict`, takeover must be explicit

### 3. Takeover

Designed for real sites that already have manual or AccelerateWP-managed `MaxCache` blocks.

Steps:

1. backup `.htaccess`
2. remove all `maxcache_module` blocks
3. write a single WMRB block
4. move to `managed` + `in_sync`

### 4. Sync With WP Rocket

The bridge observes `wp_rocket_settings` and rebuilds the snippet.

Main sources currently used:

- `cache_reject_uri`
- `cache_reject_ua`
- `cache_reject_cookies`
- `cache_webp`
- `cache_logged_user`
- `secret_cache_key`

### 5. WebP Compatibility

If `WP Rocket` generates WebP variants, the bridge must reflect that in `MaxCachePath` through `{WEBP_SUFFIX}`.

Rules:

- auto-detect from `cache_webp`
- optional manual override via `serve_webp_variant`
- if WebP generation disappears, fall back to the non-WebP path

### 6. Logged-In User Cache

Current scope:

- support the main WP Rocket per-user logged-in cache mode
- do not support shared logged-in cache yet

Rules:

- if `cache_logged_user=1` and `secret_cache_key` exists, use `MaxCacheLoggedHash` and `{USER_SUFFIX}`
- when user cache is enabled, `wordpress_logged_in_.+` must be removed from `MaxCacheExcludeCookie`
- if `secret_cache_key` is missing, do not attempt per-user cache serving
- `{USER_SHARED_SUFFIX}` is intentionally out of scope until a real test environment exists

### 7. Gzip Compatibility

The general path pattern can follow `html{GZIP_SUFFIX}`, but it must also be possible to disable it for proxy/CDN setups where that may cause broken responses or forced downloads.

Option:

- `serve_gzip_variant`

Intent:

- `true` for compatible environments
- `false` for Cloudflare-like setups when safer

## User Interface

Single screen in `Tools > MAxCache Bridge` with:

- environment checks
- MaxCache ownership mode
- sync summary with `WP Rocket`
- `in_sync / pending_apply` state
- actions:
  - `Run checks`
  - `Apply snippet now`
  - `Take over MaxCache management`
  - `Rollback last backup`
- snippet preview
- public URL quick test

## Safety and Operations

- backup before writing `.htaccess`
- visible rollback
- WordPress nonces on admin actions
- minimum capability: `manage_options`
- no auto-apply when external ownership or conflict is detected

## Validation

### Case 1: Original AccelerateWP Pattern

- `milatalent.cat`
- single `MaxCache` block
- correct origin headers

### Case 2: Takeover To Managed Mode

- `reliquiaesanctorumincatalonia.cat`
- pre-existing MaxCache configuration
- takeover executed with backup
- final state `managed` + `in_sync`
- correct origin behaviour after the change

### Case 3: Multi-Domain + WebP

- `www.injecciodeplastics.com`
- `www.inyecciondeplastico.es`
- real validation of `{HTTP_HOST}` by language/domain
- real validation of `cache_webp = 1`
- temporary fallback test to non-WebP path and later restoration

## Current Release Scope

### v0.2.1

- updater slug fix
- fingerprint based on the effective snippet
- CloudLinux-style baseline
- `managed/external/conflict` detection
- takeover to managed mode
- clearer wording for the public quick test
- automatic WebP support via `cache_webp`
- validated multi-domain behaviour with WPML domain mapping
- initial logged-in user cache support via `MaxCacheLoggedHash` + `{USER_SUFFIX}`

## Next Work

- real login/logout validation for logged-in user cache
- better origin-test representation in the UI
- public release polish
