<?php
/**
 * Plugin Name:       ECOS WooCommerce Connector
 * Description:       Connects this store to its ECOS ERP channel: pairs the store with the
 *                     ECOS-configured channel credential and shows connection/webhook health.
 *                     All product, price, stock, customer, and order data continues to flow
 *                     entirely through WooCommerce's own REST API and webhooks, unmodified by
 *                     this plugin — it is a diagnostics/bootstrap surface only.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ECOS ERP
 * License:           GPL-2.0-or-later
 * Text Domain:       ecos-wc-connector
 *
 * TASK-ECOS-V1.1-WOO-07-OFFICIAL-WOOCOMMERCE-WORDPRESS-ADAPTER — architecture authority 042A §7
 * / 042A-R1 §7-8. Both architecture reports confirm no WordPress/WooCommerce plugin existed
 * anywhere in the ECOS workspace before this ticket, and that a plugin is only structurally
 * necessary for: (a) the store-side connection bootstrap/settings screen, and (b) integration
 * diagnostics/version/health surfaced on the WordPress side. Everything else — product, price,
 * stock, customer, and order data ownership, all business logic and policy decisions — belongs to
 * ECOS ERP unconditionally and is already fully served by WooCommerce's own REST API + webhook
 * system (see the ECOS-side WebhookManagerService, which registers/deregisters all 7 topics
 * directly against Woo's REST API — this plugin does NOT register, relay, or verify webhooks
 * itself).
 *
 * Deliberately NOT built here (see the WOO-07 final report's "Open Implementation Items"): a
 * synchronous checkout-time bridge (e.g. a custom WC_Shipping_Method calling ECOS's
 * ShippingQuoteController). Both architecture reports flag "plugin necessity" beyond bootstrap as
 * a genuine, still-unresolved business decision — whether real-time checkout-time validation is
 * actually required — not something derivable from existing code. This plugin is therefore the
 * bootstrap-only outcome both reports explicitly describe as a valid interim state.
 */

if (! defined('ABSPATH')) {
	exit;
}

define('ECOS_WC_CONNECTOR_VERSION', '1.0.0');
define('ECOS_WC_CONNECTOR_OPTION', 'ecos_wc_connector_settings');
define('ECOS_WC_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once ECOS_WC_CONNECTOR_DIR . 'includes/class-ecos-api-client.php';
require_once ECOS_WC_CONNECTOR_DIR . 'includes/class-ecos-admin-settings.php';

/**
 * Entry point. A thin bootstrap only — all real behaviour lives in the two included classes so
 * this file stays a manifest, not an implementation.
 */
function ecos_wc_connector_init() {
	Ecos_Wc_Connector_Admin_Settings::instance();
}
add_action('plugins_loaded', 'ecos_wc_connector_init');

/**
 * Deactivation is treated as a visible breadcrumb for the ECOS operator, never as an implicit
 * "disconnect the channel" action — Channel pause/disable/delete stays an explicit, separate ECOS
 * lifecycle decision (see PluginAdapterController::deactivated() on the ECOS side). This call is
 * deliberately non-blocking (a short timeout, no retry) so a slow or unreachable ECOS instance
 * never delays WordPress's own deactivation.
 */
function ecos_wc_connector_on_deactivate() {
	$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

	if (empty($settings['base_url']) || empty($settings['channel_id']) || empty($settings['consumer_key']) || empty($settings['consumer_secret'])) {
		return;
	}

	$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['consumer_key'], $settings['consumer_secret']);
	$client->notify_deactivated('wordpress_plugin_deactivated');
}
register_deactivation_hook(__FILE__, 'ecos_wc_connector_on_deactivate');
