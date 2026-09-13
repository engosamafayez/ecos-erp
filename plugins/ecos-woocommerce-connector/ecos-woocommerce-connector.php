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
require_once ECOS_WC_CONNECTOR_DIR . 'includes/class-ecos-command-controller.php';

function ecos_wc_connector_init() {
	Ecos_Wc_Connector_Admin_Settings::instance();
	Ecos_Wc_Connector_Heartbeat::boot();
	Ecos_Wc_Connector_Command_Controller::boot();
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
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §16 — deactivation has TWO best-effort steps,
 * both non-blocking so a slow/unreachable ECOS instance or WooCommerce quirk never delays
 * WordPress's own deactivation:
 *
 *   1. LOCAL: delete this Channel's Woo webhooks directly, in-process, via WooCommerce's own
 *      wc_get_webhooks()/WC_Webhook::delete() — found by matching delivery_url against this
 *      channel's own callback path, not a locally-cached id list (the plugin holds no webhook
 *      state of its own; ECOS's external_webhook_*_id columns remain the source of truth).
 *      This does not depend on ECOS being reachable.
 *   2. REMOTE: notify ECOS, which marks the Connector disconnected (Channel lifecycle, Brand,
 *      Orders, Products, Customers, and historical SyncLogs are never touched) and — as a
 *      belt-and-suspenders fallback if step 1 could not run — separately tries to reach this
 *      same webhook-deletion capability via the command endpoint.
 */
function ecos_wc_connector_on_deactivate() {
	$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

	Ecos_Wc_Connector_Heartbeat::unschedule();

	if (empty($settings['connector_token']) || empty($settings['channel_id'])) {
		return;
	}

	if (function_exists('wc_get_webhooks')) {
		$needle = '/api/webhooks/woocommerce/' . $settings['channel_id'] . '/';

		foreach (wc_get_webhooks() as $webhook_id) {
			$webhook = wc_get_webhook($webhook_id);

			if ($webhook && strpos((string) $webhook->get_delivery_url(), $needle) !== false) {
				$webhook->delete(true);
			}
		}
	}

	$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['connector_token']);
	$client->notify_deactivated('wordpress_plugin_deactivated');
}
register_deactivation_hook(__FILE__, 'ecos_wc_connector_on_deactivate');
