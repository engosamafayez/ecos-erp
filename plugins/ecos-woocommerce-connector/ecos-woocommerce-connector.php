<?php
/**
 * Plugin Name:       ECOS WooCommerce Connector
 * Description:       The complete connection agent between this store and its ECOS ERP
 *                     Channel. Install, pair with a short-lived code from ECOS, and sync
 *                     operates automatically — no webhook, topic, or callback URL setup is
 *                     ever required. All product, price, availability, customer, and order
 *                     business logic remains entirely in ECOS; this plugin transports data and
 *                     manages the connection only.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ECOS ERP
 * License:           GPL-2.0-or-later
 * Text Domain:       ecos-wc-connector
 *
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 (CTO business-rule correction) — supersedes the
 * WOO-07 bootstrap/diagnostics-only plugin. The Connector now owns: pairing to one ECOS
 * Channel, its own dedicated authentication (connector_token — never the WooCommerce REST
 * consumer_key/secret ECOS uses to call Woo, which this plugin never sees), automatic webhook
 * setup/repair (delegated entirely to ECOS's existing WebhookManagerService — this plugin never
 * registers a Woo webhook itself), connection health heartbeats, and deactivation/disconnect
 * notification. It still owns NO business logic: it does not calculate product availability,
 * does not read raw-material stock or recipes, does not match customers, and does not decide
 * fulfillment or Finance outcomes — those remain exclusively ECOS's authorities, reached only
 * through Woo's own native REST API and webhook system plus the endpoints in this plugin.
 */

if (! defined('ABSPATH')) {
	exit;
}

define('ECOS_WC_CONNECTOR_VERSION', '2.0.0');
define('ECOS_WC_CONNECTOR_OPTION', 'ecos_wc_connector_settings');
define('ECOS_WC_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once ECOS_WC_CONNECTOR_DIR . 'includes/class-ecos-api-client.php';
require_once ECOS_WC_CONNECTOR_DIR . 'includes/class-ecos-heartbeat.php';
require_once ECOS_WC_CONNECTOR_DIR . 'includes/class-ecos-admin-settings.php';

function ecos_wc_connector_init() {
	Ecos_Wc_Connector_Admin_Settings::instance();
	Ecos_Wc_Connector_Heartbeat::boot();
}
add_action('plugins_loaded', 'ecos_wc_connector_init');
add_filter('cron_schedules', ['Ecos_Wc_Connector_Heartbeat', 'register_interval']);

/**
 * Re-arm the heartbeat schedule on activation if this site was already paired (e.g. the
 * plugin was deactivated and reactivated without disconnecting first) — reactivation alone
 * must restore normal operation without requiring the merchant to re-pair.
 */
function ecos_wc_connector_on_activate() {
	$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

	if (! empty($settings['connector_token'])) {
		Ecos_Wc_Connector_Heartbeat::schedule();
	}
}
register_activation_hook(__FILE__, 'ecos_wc_connector_on_activate');

/**
 * Deactivation marks the Connector OFFLINE in ECOS and deregisters this channel's Woo webhooks
 * (reusing WebhookManagerService via the deactivated endpoint — not a second deregistration
 * mechanism) — it never touches ECOS business data (Channel lifecycle, Brand, Orders, Products,
 * Customers, historical SyncLogs all stay exactly as they are). Deliberately non-blocking: a
 * slow or unreachable ECOS instance never delays WordPress's own deactivation, and the
 * heartbeat schedule is cleared locally regardless of whether the notify call succeeds.
 */
function ecos_wc_connector_on_deactivate() {
	$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

	Ecos_Wc_Connector_Heartbeat::unschedule();

	if (empty($settings['connector_token'])) {
		return;
	}

	$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['connector_token']);
	$client->notify_deactivated('wordpress_plugin_deactivated');
}
register_deactivation_hook(__FILE__, 'ecos_wc_connector_on_deactivate');
