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
- Verifies the site still answers after every write, and reverts automatically if it does not
- Validates every exclusion coming from `WP Rocket` and drops the ones that would break the config
- Watches `wp_rocket_settings` and can auto-apply the managed block when the bridge owns it
- Can serve static HTML to generic bots/crawlers for mostly static or archive sites, off by default
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
- `serve_bot_user_agents`
- `serve_gzip_variant`
- `serve_webp_variant`
- `custom_cache_path_template`

## Bot / Crawler Cache

`serve_bot_user_agents` controls whether generic crawler user agents are allowed to hit the static MaxCache HTML path.

Default: `false`.

When disabled, the bridge adds the conservative generic UA exclusions:

- `bot`
- `crawl`
- `spider`

The bridge de-duplicates those generic fragments if they also come from WP Rocket's rejected user agents. When this option is enabled, the generated `MaxCacheExcludeUA` rule drops the generic crawler exclusions entirely.

Practical recommendation:

- mostly static/archive sites: `true` can reduce PHP pressure from crawlers
- sites with frequent edits, personalization, checkout, memberships, or time-sensitive content: keep `false`
- if the site has bot-specific PHP behavior, keep `false`

Risk:

- bots may receive stale HTML until WP Rocket purges and regenerates the cache
- any bot-specific PHP behavior will not run on cached HTML hits

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

`auto_apply` only runs in `managed` or `unmanaged`. A write to `wp_rocket_settings` triggers it only when the effective WMRB snippet fingerprint changes; unrelated WP Rocket metadata cannot apply older pending drift.

## Takeover

When the plugin detects `external` or `conflict`, you can run `Take over MaxCache management`.

That action:

1. creates a `.htaccess` backup
2. removes existing `MaxCache` blocks
3. writes a single WMRB-managed block
4. moves the site to `managed` + `in_sync`

Existing blocks are located by walking the file and tracking `<IfModule>` nesting depth, so a `MaxCache` section that wraps other sections is removed whole. Apache answers every request with a `500` when a closing tag is orphaned, and takeover by definition runs over configurations the bridge did not write.

If the file cannot be parsed safely — an `<IfModule>` section that is never closed — the takeover refuses and reports it rather than rewriting a file it does not understand.

## Quick Test

The built-in quick test uses the public WordPress URL.

That means:

- it may pass through Cloudflare or other proxies
- it is useful as a general signal
- it does not replace direct origin validation with `curl --resolve`

## Write Safety

Because `auto_apply_htaccess` is on by default, a bad write happens without anyone watching. Three mechanisms guard that path.

### Pattern validation

Exclusions from `cache_reject_uri`, `cache_reject_ua`, and `cache_reject_cookies` are typed by hand in `WP Rocket` and end up verbatim inside a regex alternation in the generated directives. Fragments are accepted one at a time, and each must:

- compile on its own, wrapped in a group
- not behave as a universal match across representative inputs for its target directive
- still compile once appended to everything accepted before it

The third rule matters as much as the first. Two fragments can each be valid yet refuse to compile together — duplicate named groups being the obvious case — and Apache discards the whole directive when that happens, taking every other exclusion with it.

A fragment failing any check is dropped and listed in the admin screen, so a typo never costs the site. The universal-match rule uses separate non-empty URI, user-agent, and cookie samples: for example, `^/` is universal for request URIs even though it is not universal for user-agent strings. It stops patterns like `.*`, `.+`, `.`, `^/` in URI exclusions, or `|/foo` from silently disabling the cache, while allowing anchored patterns such as `^$` that only target an empty value. The same pipeline feeds the sync summary, so the counts and the warnings can never disagree.

### Atomic writes

`.htaccess` is written to a temporary file in the same directory, given the original's mode and group, and renamed into place.

There is deliberately **no in-place fallback**. `file_put_contents()` truncates before writing and its lock is only advisory, so Apache can read an empty or half-written `.htaccess` and answer `500` for every request. When an atomic rename is not possible — most often because the document root is not writable — the bridge refuses the operation and says so.

Operations take an exclusive lock, and the file is re-read and compared immediately before the rename. Anything that changed it in the meantime, including WordPress rewriting it when permalinks are saved, wins: the bridge aborts rather than clobber it.

### Post-write verification

Around every write the bridge probes the site:

1. before writing, to establish a baseline
2. after writing, to confirm nothing broke

Each probe mints a random token, stores it briefly, and requests the home page with that token in the query string. The response must contain the token echoed back. A status code alone proves little — a CDN can serve a cached page, or its own error page, while the origin is down — and a fresh token per request means no edge cache can answer it.

A failing probe is retried a few times before it counts: a single `502` or `503` is routine on shared hosting and is not worth a rollback.

If the site answered before the write and does not answer after it, `.htaccess` is restored and the error is recorded — but only if the file is still byte-for-byte what the bridge wrote. If the site was already failing beforehand, or the probe cannot run at all (loopback requests blocked), the bridge reports instead of rolling back, because it has no reliable baseline to judge against. That write is recorded as `applied_unverified`, not `in_sync`; the next state refresh probes again and promotes it to `in_sync` only after a verified response.

## Rollback

Backups are stored in `wp-content/wmrb-backups/`.

If a deployment fails:

1. run `Rollback last backup`
2. purge `WP Rocket`
3. validate headers again at origin

## Development

```bash
composer install
composer test
```

The test suite runs without a WordPress install: `tests/wp-stubs.php` reimplements the small slice of the WordPress API the plugin touches, and the filesystem is sandboxed to a temporary directory. `vendor/`, `tests/`, and the Composer files are development-only and must not be shipped in the release zip.

### Pending work as executable specifications

Tests in the `batch2` group describe behaviour that is planned but not implemented, so they fail on purpose and are excluded from the default run:

```bash
vendor/bin/phpunit --group batch2
```

Each one cites the reference implementation it was derived from — AccelerateWP's `clsop/inc/functions/htaccess.php` and WP Rocket's `inc/functions/options.php`. They cover snippet fidelity (mobile options, dynamic and mandatory cookies, exclusions contributed through WP Rocket's filters) and lifecycle gaps (uninstall, sync state that never inspects `.htaccess`, redundant option writes).

## Building a release

```bash
./build.sh
```

The archive is assembled from an allowlist rather than by excluding known-unwanted paths, so anything added to the repository later — more tests, more tooling — stays out by default instead of shipping by accident. The build then re-opens the archive and fails if a forbidden path slipped in anyway.

Pushing a `vX.Y.Z` tag runs the same script in CI, which refuses to publish unless the tag matches the version in the plugin header, and attaches the archive to the GitHub release.

## Updates via GitHub

The plugin checks GitHub Releases at:

`https://api.github.com/repos/velisnolis/wp-maxcache-rocket-bridge/releases/latest`

Expected release flow:

1. tag `vX.Y.Z`
2. push the tag; CI builds and publishes the release with `wp-maxcache-rocket-bridge.zip` attached

The updater requires that asset by name. There is no fallback to GitHub's generated source archive, which is the repository rather than the plugin: it unpacks under a version-suffixed directory and carries the test suite and build tooling. A release without the asset offers no update at all.

Lookups are cached for an hour on success and fifteen minutes on failure, so an unreachable or rate-limited GitHub cannot add a blocking request to every update check the site performs.

## Disclaimer

This plugin is an independent utility built to operate with WordPress, WP Rocket, Apache `mod_maxcache`, and publicly documented CloudLinux / AccelerateWP configuration patterns.

It is not affiliated with, endorsed by, sponsored by, or officially supported by WP Rocket, CloudLinux, AccelerateWP, Apache, or any other vendor or trademark holder mentioned in this repository.

All product names, trademarks, and registered trademarks remain the property of their respective owners. References are used only to describe interoperability and operational context.

This software is distributed as-is, without warranties or guarantees of any kind. Use it at your own risk. You are responsible for validating it in your own infrastructure, reviewing the generated configuration, and deciding whether it is appropriate for your environment.

## License

This repository is released under the MIT License. See [LICENSE](LICENSE).
