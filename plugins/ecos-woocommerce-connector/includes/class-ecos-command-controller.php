<?php
/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §7/§8/§9/§11 — the store-side application half of
 * the bidirectional Connector transport. Registers ONE WordPress REST route
 * (`POST /wp-json/ecos-connector/v1/commands`) that ECOS calls (via WooOutboundCommandDispatcher
 * on the ECOS side) instead of Woo's own public REST API, for any Channel that has completed
 * Connector pairing.
 *
 * Deliberately does NOT reimplement WooCommerce's product/order/webhook field mapping or
 * validation: every command is applied by constructing a WP_REST_Request and handing it to
 * WooCommerce's OWN REST controller classes (WC_REST_Products_Controller,
 * WC_REST_Orders_Controller, WC_REST_Webhooks_Controller) — the exact same, already-correct
 * logic Woo's public REST API runs, just invoked in-process instead of over HTTP. This plugin
 * decides no business value: `fields` is always the canonical payload ECOS already computed.
 *
 * IMPORTANT — RESIDUAL RISK FLAGGED FOR REVIEW: invoking these controller classes outside
 * WordPress's normal REST dispatch (which would otherwise resolve a current user from Basic
 * Auth/application-password credentials before calling them) means their own internal
 * capability checks would otherwise fail with no current user set. This impersonates the
 * store's first active administrator for the duration of one command only (see
 * first_administrator() below) — a standard pattern for system-initiated privileged actions
 * with no browser session (the same shape WP-CLI/cron already use), restoring the previous
 * user afterward. The exact capability strings each WC_REST_*_Controller method checks
 * internally have not been verified against a live WooCommerce install in this session;
 * confirm this against a real WooCommerce site before certification.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Ecos_Wc_Connector_Command_Controller {

	const REST_NAMESPACE = 'ecos-connector/v1';

	public static function boot() {
		add_action('rest_api_init', [self::class, 'register_routes']);
	}

	public static function register_routes() {
		register_rest_route(self::REST_NAMESPACE, '/commands', [
			'methods'             => 'POST',
			'callback'            => [self::class, 'handle'],
			'permission_callback' => [self::class, 'authenticate'],
		]);
	}

	/**
	 * Fail-closed Bearer connector_token check — the same shared, Channel-scoped secret ECOS
	 * issued during pairing, presented back in the reverse direction (ECOS -> Plugin) rather
	 * than a new credential type.
	 */
	public static function authenticate($request) {
		$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

		if (empty($settings['connector_token'])) {
			return false;
		}

		$header = $request->get_header('authorization');

		if (! is_string($header) || stripos($header, 'Bearer ') !== 0) {
			return false;
		}

		$presented = trim(substr($header, 7));

		return hash_equals($settings['connector_token'], $presented);
	}

	public static function handle($request) {
		if (! class_exists('WooCommerce')) {
			return new WP_Error('ecos_woocommerce_missing', 'WooCommerce is not active on this site.', ['status' => 503]);
		}

		$resource  = sanitize_key((string) $request->get_param('resource'));
		$operation = sanitize_key((string) $request->get_param('operation'));
		$woo_id    = $request->get_param('woo_id');
		$fields    = $request->get_param('fields');
		$fields    = is_array($fields) ? $fields : [];

		$controller = self::controller_for($resource);

		if ($controller === null) {
			return new WP_Error('ecos_unknown_resource', 'Unknown resource: ' . $resource, ['status' => 422]);
		}

		if (! in_array($operation, ['create', 'update', 'delete'], true)) {
			return new WP_Error('ecos_unknown_operation', 'Unknown operation: ' . $operation, ['status' => 422]);
		}

		// Customers carry no ECOS-stored Woo id (see WooOutboundCommandDispatcher::upsertCustomer):
		// ECOS sends operation=create with a null woo_id and the ECOS-authoritative email in the
		// fields. Resolve the Woo customer LOCALLY by that exact email (Woo's own unique key) so the
		// apply is idempotent create-or-update — the connector-mode equivalent of the legacy job's
		// own resolve-by-email. This is a mechanical Woo-side lookup on an ECOS-decided value only:
		// it never matches among ECOS customers, merges, decides tenant/company, or runs CRM logic.
		if ($resource === 'customers') {
			$existing_id = self::resolve_customer_id($fields);

			if ($existing_id !== null) {
				$operation = 'update';
				$woo_id    = $existing_id;
			} else {
				$operation = 'create';
				$woo_id    = null;
			}
		}

		if ($operation !== 'create' && empty($woo_id)) {
			return new WP_Error('ecos_missing_woo_id', 'woo_id is required for update/delete.', ['status' => 422]);
		}

		$admin = self::first_administrator();

		if ($admin === null) {
			return new WP_Error('ecos_no_administrator', 'No administrator account exists on this site to apply the command.', ['status' => 500]);
		}

		$previous_user_id = get_current_user_id();
		wp_set_current_user($admin->ID);

		try {
			$inner = new WP_REST_Request($operation === 'create' ? 'POST' : ($operation === 'delete' ? 'DELETE' : 'PUT'));
			$inner->set_body_params($fields);

			if (! empty($woo_id)) {
				$inner->set_param('id', $woo_id);
			}

			$response = self::dispatch($controller, $operation, $inner);
		} finally {
			wp_set_current_user($previous_user_id);
		}

		if (is_wp_error($response)) {
			return $response;
		}

		$data = ($response instanceof WP_REST_Response) ? $response->get_data() : $response;

		return new WP_REST_Response(['data' => is_array($data) ? $data : (array) $data], 200);
	}

	private static function dispatch($controller, $operation, $request) {
		switch ($operation) {
			case 'create':
				return $controller->create_item($request);
			case 'delete':
				return $controller->delete_item($request);
			default:
				return $controller->update_item($request);
		}
	}

	private static function controller_for($resource) {
		switch ($resource) {
			case 'products':
				return class_exists('WC_REST_Products_Controller') ? new WC_REST_Products_Controller() : null;
			case 'orders':
				return class_exists('WC_REST_Orders_Controller') ? new WC_REST_Orders_Controller() : null;
			case 'webhooks':
				return class_exists('WC_REST_Webhooks_Controller') ? new WC_REST_Webhooks_Controller() : null;
			case 'customers':
				return class_exists('WC_REST_Customers_Controller') ? new WC_REST_Customers_Controller() : null;
			default:
				return null;
		}
	}

	/**
	 * Find an existing Woo customer's id by the email ECOS supplied in the command fields (checked
	 * at the top level first, then inside the billing block). Returns the WordPress user id — which
	 * is the WooCommerce customer id WC_REST_Customers_Controller operates on — or null when no
	 * email is present or no customer with that email exists. Pure Woo-local lookup on an
	 * ECOS-authoritative value; no identity/merge/CRM decision is made here.
	 */
	private static function resolve_customer_id($fields) {
		$email = '';

		if (isset($fields['email']) && is_string($fields['email'])) {
			$email = trim($fields['email']);
		} elseif (isset($fields['billing']['email']) && is_string($fields['billing']['email'])) {
			$email = trim($fields['billing']['email']);
		}

		if ($email === '') {
			return null;
		}

		$user = get_user_by('email', $email);

		return $user ? (int) $user->ID : null;
	}

	private static function first_administrator() {
		$admins = get_users([
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		]);

		return $admins[0] ?? null;
	}
}
