=== KMPanel Connector ===
Contributors: kelyanmedia
Tags: monitoring, management, inventory
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure WordPress technical inventory for KMPanel, with automatic GitHub release updates.

== Description ==
Reports WordPress/PHP/plugin/theme versions, pending updates and basic configuration health through HMAC-authenticated requests. No backups, customer data, order data or site content are collected.

Optional remote plugin/theme/core updates and single-use administrator login are disabled by default. A local administrator must enable them and select an account for login. Multisite telemetry is supported; remote management on multisite is disabled.

Plugin updates come from https://api.github.com/repos/kelyanmedia/KMPanelConnector/releases/latest and verified release ZIP assets on GitHub. Private repositories can be proxied through the configured KMPanel HTTPS URL without storing a GitHub token on WordPress. Requests to the panel identify the site's public URL and authenticate using its own connection secret.

== Installation ==
1. Install kmpanel-connector.zip and activate.
2. Open Settings > KMPanel.
3. Add the endpoint and unique connection secret to the site's KMPanel card.
4. Automatic connector updates are enabled by default. WordPress update scheduling must run.

== Changelog ==
= 1.0.2 =
Preserve plugin activation during targeted updates launched from WP-CLI or remote management; verify activation state and restore temporary background context.
= 1.0.1 =
Dedicated 15-minute connector-only background updater, server-cron entry point, update diagnostics in telemetry, and additional package-integrity tests.
= 1.0.0 =
Initial release: signed telemetry, replay protection, automatic verified GitHub updates, optional explicit remote updates and 60-second single-use admin login.
