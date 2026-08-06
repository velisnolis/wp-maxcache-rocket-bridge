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

Behaviour below was confirmed against production sites on a CloudLinux host. The sites are referred to by label; identifying them adds nothing to the specification.

- `site A` runs the original AccelerateWP-style single-block pattern
- `site B` was migrated in a real test to `managed mode`
- `cf-cache-status: DYNAMIC` is acceptable when origin still serves the correct static pattern
- `site C`, a multilingual pair of domains sharing one install, validated multi-domain handling through `{HTTP_HOST}`
- `site C` also validated the real `cache_webp` case

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
2. remove all `maxcache_module` blocks, matched by nesting depth rather than by regex
3. write a single WMRB block
4. move to `managed` + `in_sync`

Takeover refuses to run when the file contains an unterminated `<IfModule>` section.

### Observed Apache behaviour

Measured against Apache with `mod_maxcache` on a live CloudLinux host:

| Condition | Result |
| --- | --- |
| `MaxCacheExcludeURI` regex that does not compile | `200`, still cached — exclusions silently discarded |
| `MaxCacheExcludeURI` matching every request | `200`, all requests fall through to PHP — cache silently off |
| unknown directive inside the block | `500` |
| orphaned `</IfModule>` | `500` |
| file truncated mid-section | `200`, cache off |

Nothing is written to the error log in the silent cases, which is why the bridge validates patterns itself instead of relying on the server to complain.

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
- `in_sync / pending_apply / applied_unverified` state
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
- every exclusion inherited from `WP Rocket` must compile as a regex on its own, must not behave as a universal match across representative inputs specific to its directive (URI, user-agent, or cookie), and must still compile once combined with the others, or it is dropped and reported
- `.htaccess` writes go through a temporary file and `rename()`, preserving mode and group; there is no non-atomic fallback, and the operation is refused when an atomic rename is impossible
- writes hold an exclusive lock, and the file is re-read and compared immediately before the rename so a concurrent change is never clobbered
- writes are verified with a tokenised probe whose response must echo the token back, retried a few times, and reverted automatically when a healthy site stops answering — but only while the file is still exactly what the bridge wrote
- a write that would not change the file is skipped entirely (no backup, no probes)
- takeover parses section tags as directives only, ignoring comments and quoted literals, and declines on ambiguous syntax

## Validation

### Case 1: Original AccelerateWP Pattern

- `site A`: WordPress + WP Rocket, AccelerateWP-managed
- single `MaxCache` block
- correct origin headers

### Case 2: Takeover To Managed Mode

- `site B`: pre-existing MaxCache configuration not written by the bridge
- takeover executed with backup
- final state `managed` + `in_sync`
- correct origin behaviour after the change

### Case 3: Multi-Domain + WebP

- `site C`: two language domains served from one install, behind Cloudflare
- real validation of `{HTTP_HOST}` by language/domain
- real validation of `cache_webp = 1`
- temporary fallback test to non-WebP path and later restoration

### Case 4: Apache Failure Modes

Measured directly against `mod_maxcache` by writing each condition into a live
`.htaccess` and requesting the origin. Full results under *Observed Apache
behaviour* in section 3.

- a non-compiling exclusion regex is not fatal, but the exclusions are lost silently
- an exclusion matching every request disables the cache silently
- an unknown directive, or an orphaned `</IfModule>`, returns `500` for every request
- none of the silent cases write anything to the error log

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
