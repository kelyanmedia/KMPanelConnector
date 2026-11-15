# KMPanel Connector

A small WordPress plugin for the KMPanel Laravel application. It works on independently hosted sites and does not need SSH, server agents or hosting-provider credentials.

- WordPress 6.0+, PHP 7.4+; no Composer dependencies.
- Technical inventory and configuration health, authenticated using a unique per-site HMAC secret.
- Optional explicit plugin/theme/core upgrades and one-time administrator login, disabled until a local administrator opts in.
- Automatic updates of **this connector only** through GitHub Releases, with a verified SHA-256 package. No GitHub credentials on WordPress.
- No backups, customer records, order information, arbitrary PHP execution or general-purpose file access.

## Install and connect

Install the ZIP from [Releases](https://github.com/kelyanmedia/KMPanelConnector/releases), activate it, then open **Settings → KMPanel**. Copy the status endpoint and generated secret into the corresponding KMPanel site card. The plugin's source must live under `wp-content/plugins/kmpanel-connector/`.

The endpoint is usually `https://site.example/wp-json/kmpanel/v1/status`. Sites with plain permalinks use the `?rest_route=/kmpanel/v1/status` URL returned by WordPress; signatures still use the REST route, not the URL query.

Secrets can be rotated or revoked in the settings screen. Rotating also invalidates unconsumed one-time login links. Telemetry is read-only; site availability is checked independently by KMPanel.

### WordPress options / WP-CLI provisioning

| Option | Default | Purpose |
|---|---|---|
| `kmpanel_secret` | Random 64 hex on activation | Per-site HMAC secret; never log or commit it |
| `kmpanel_auto_update` | `true` | Automatic connector updates only |
| `kmpanel_update_source` | `github` | `github` public releases, or `panel` private-release proxy |
| `kmpanel_panel_url` | Empty | Public HTTPS panel base URL for proxy mode |
| `kmpanel_remote_management` | `false` | Local opt-in to explicit remote changes |
| `kmpanel_login_user` | `0` | Local administrator ID; `0` disables one-time login |
| `kmpanel_last_contact` | Empty | Last authenticated panel request |
| `kmpanel_update_status` | Empty | Last release check result, without credentials |

Activation schedules hourly nonce cleanup; the active plugin also schedules a connector-only update check every 15 minutes. If WP-Cron is disabled, the existing server scheduler must continue running due WordPress cron events. Deactivation keeps connection settings; uninstall removes them. On multisite the plugin should be configured for each individual site; remote management is intentionally disabled because network-wide updates require a separate permission model.

## Authentication protocol

All connector endpoints require:

```
X-KM-Timestamp: UNIX seconds
X-KM-Nonce: unique random ASCII identifier, 16–128 characters [A-Za-z0-9_-]
X-KM-Signature: lowercase hex HMAC-SHA256
```

The exact signed string is:

```
{timestamp}\n{nonce}\n{UPPERCASE_METHOD}\n{REST_ROUTE}\n{sha256(raw_request_body)}
```

For example: `GET`, `/kmpanel/v1/status`, and SHA-256 of the empty string. Use the **exact encoded request bytes** for POST signatures. Signatures allow clock skew up to 300 seconds. Nonces are consumed atomically through a unique option row and cannot be replayed. Use HTTPS and synchronized clocks. Responses must not be publicly cached.

### `GET /kmpanel/v1/status`

Returns `site_url`, `wp_version`, `php_version`, `plugin_version`, `updates: {core, plugins, themes}`, arrays `plugins` and `themes`, `health`, `update_checks`, and `collected_at` in UTC ISO-8601.

Each plugin has `file`, `name`, `version`, `active`, `update_version`. Themes use `slug` in place of `file`. Health contains `cron_disabled`, `debug_enabled`, `php_memory_limit`, `ssl`, `multisite`, `remote_management_enabled`, `admin_login_enabled`.

Update counts come from WordPress's cached update checks. Their `update_checks.*_at` timestamps expose freshness; every panel poll does not trigger expensive update discovery. PHP's configured memory limit is **not** live RAM usage of the site or server.

### `POST /kmpanel/v1/update`

Requires local `kmpanel_remote_management` opt-in. JSON:

```json
{"type":"plugin","slug":"example/example.php"}
```

Types: `plugin`, `theme`, `core` (no slug for core). Only installed, recognized plugins/themes may be updated from their existing WordPress update sources; arbitrary ZIP URLs cannot be submitted. A site-wide lock prevents overlapping updates. Returns `{success, changed, type, slug, version}` or a structured error. `DISALLOW_FILE_MODS` is respected, direct filesystem access is required, and multisite management is disabled.

If PHP crashes during an operation, the lock remains fail-closed. After confirming that no updater is running, the local administrator can clear `kmpanel_update_lock`. No automatic updates of other components are enabled by this plugin.

### `POST /kmpanel/v1/login`

Requires remote-management opt-in and a locally selected `kmpanel_login_user` with `manage_options`. Returns `{url, expires_at}`. Open the URL once within 60 seconds. It signs in as that explicitly selected administrator with a nonpersistent secure cookie. Token use is atomic; rotation, revocation, account removal or disabling local settings invalidates it.

Treat the returned URL as a credential: never log it, put it in activity messages or send it to analytics. The response is `no-store` and the consumption endpoint sets `Referrer-Policy: no-referrer`.

## Automatic GitHub updates

The `Update URI` header prevents a similarly named wordpress.org plugin from replacing this connector. The update filter affects only `kmpanel-connector/kmpanel-connector.php`.

The default public mode reads GitHub's latest stable release. A release must attach:

- `kmpanel-connector.zip` with the stable top-level directory `kmpanel-connector/`.
- `kmpanel-connector.zip.sha256` (standard sha256sum format).

A GitHub asset `sha256:` digest may also supply the checksum. Archives are downloaded through WordPress's safe HTTP client and checked before the WordPress upgrader receives them. Failed downloads or checksums leave the installed connector untouched.

### Releasing an update

1. Bump the plugin header and `KMPANEL_CONNECTOR_VERSION` in `kmpanel-connector.php` and `Stable tag` in `readme.txt` together.
2. Add a changelog entry, run tests, and push to `main`.
3. GitHub Actions tests PHP 7.4/8.1/8.4, builds the ZIP and publishes `vX.Y.Z` with both assets. Existing release versions are never overwritten; increment the version for every update.
4. WordPress discovers and installs the release through its normal background update schedule. This is not instant on Git push: it depends on WordPress cron, filesystem access and host policy. The ordinary release cache is one hour; the connector-only 15-minute check refreshes it. Low traffic can delay WP-Cron, so use the dedicated server cron below when available.

The dedicated updater respects WordPress update policies and never invokes the broad `WP_Automatic_Updater::run()` routine. If `AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`, or host configuration disables automatic updates, a release cannot override that. Settings → KMPanel → Check for updates refreshes discovery; a controlled test can use `wp plugin update kmpanel-connector` after verifying the expected release.

### Dedicated server cron (only this plugin)

Run the following as the site's Unix owner. It checks and updates only KMPanel Connector, respects automatic-update policy and obtains the WordPress updater lock. It does not update other plugins, themes or core.

```sh
wp --path=/path/to/wordpress eval 'do_action("kmpanel_connector_update");'
```

A system cron every 15 minutes makes this independent of website traffic. Do not replace the site's existing WordPress cron or use `wp cron event run --all` for this purpose. Version 1.0.2 adds scoped background semantics so WP-CLI updates preserve activation. For upgrading from 1.0.0 or 1.0.1, use the current `scripts/auto-update-smoke.php` with `wp eval-file`: it applies those semantics externally, then checks both version and activation. Do not use the old smoke script or the unwrapped 1.0.1 hook for that transition. As an alternative bootstrap, call the old hook from a real WordPress cron context. The updater does not blindly reactivate a failed package.

## Private repository proxy contract

Select update source `panel` and configure an HTTPS KMPanel URL. The plugin never stores or sends a GitHub PAT. Laravel keeps any repository credential server-side.

- `GET {panel}/api/connector/releases/latest`
- `GET {panel}/api/connector/releases/download/{version}`

Both use the same HMAC algorithm, signing the **URL path** as the route, plus `X-KM-Site-Url: {home_url()}` so the panel can locate the site secret. Neither route uses query parameters. Download redirects are disabled so credentials cannot follow a redirect.

Latest response:

```json
{
  "version": "1.0.0",
  "download_url": "https://panel.example/api/connector/releases/download/1.0.0",
  "sha256": "64 lowercase hexadecimal characters",
  "requires": "6.0",
  "requires_php": "7.4",
  "tested": ""
}
```

The download must stream that exact version's ZIP. Do not redirect to a credential-bearing GitHub URL. The plugin accepts only the configured panel origin and the pinned download path.

## Development checks

```sh
php tests/run.php
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
python3 scripts/package.py
```

The standalone suite covers signature expiry and tampering, atomic nonce replay handling, remote-management opt-in, update-source restrictions, checksum verification and automatic-update isolation. It is not a replacement for a real WordPress integration smoke test. The two pilot sites should verify signed telemetry, unsigned rejection and connector update installation; do not invoke core/plugin/theme updates merely to test connectivity.
