<?php
/**
 * Thin HTTP client for the ECOS plugin-adapter endpoints. Authenticates every call after
 * pairing with the Channel-scoped `connector_token` issued by ExchangePairingCodeAction — never
 * the WooCommerce REST consumer key/secret, which this plugin never sees or handles at all.
 * The connector_token is never logged and never re-displayed once stored.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Ecos_Wc_Connector_Api_Client {

	private $base_url;
	private $channel_id;
	private $connector_token;

	public function __construct($base_url, $channel_id = null, $connector_token = null) {
		$this->base_url        = untrailingslashit($base_url);
		$this->channel_id      = $channel_id;
		$this->connector_token = $connector_token;
	}

	/**
	 * POST .../api/plugin/pair — the ONE unauthenticated call this client makes (there is no
	 * connector_token yet; the pairing code itself is the one-time proof of authorization).
	 *
	 * @return array{ok: bool, data?: array, message?: string}
	 */
	public function pair($pairing_code) {
		$response = wp_remote_post($this->base_url . '/api/plugin/pair', [
			'timeout' => 15,
			'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
			'body'    => wp_json_encode(['pairing_code' => $pairing_code]),
		]);

		return $this->parse_response($response);
	}

	/** GET .../api/plugin/channels/{channel}/status — read-only, no side effects. */
	public function fetch_status() {
		$response = wp_remote_get($this->channel_url('status'), [
			'timeout' => 15,
			'headers' => $this->auth_headers(),
		]);

		return $this->parse_response($response);
	}

	/**
	 * POST .../heartbeat — called on the plugin's own WP-Cron schedule. This is the ONLY thing
	 * that keeps ECOS's connectorHealth() from degrading a live site to Degraded/stale, so a
	 * failure here is logged but never surfaced as a fatal WordPress error — a transient
	 * network blip must not disrupt the site it runs on.
	 */
	public function heartbeat() {
		$response = wp_remote_post($this->channel_url('heartbeat'), [
			'timeout'  => 10,
			'blocking' => true,
			'headers'  => $this->auth_headers(),
		]);

		return $this->parse_response($response);
	}

	/** POST .../repair — force re-verify + re-register every Woo webhook topic via ECOS. */
	public function repair() {
		$response = wp_remote_post($this->channel_url('repair'), [
			'timeout' => 20,
			'headers' => $this->auth_headers(),
		]);

		return $this->parse_response($response);
	}

	/**
	 * POST .../deactivated — fire-and-forget: a short timeout and no blocking, so an
	 * unreachable ECOS instance never delays WordPress's own deactivation lifecycle.
	 */
	public function notify_deactivated($reason) {
		wp_remote_post($this->channel_url('deactivated'), [
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array_merge($this->auth_headers(), ['Content-Type' => 'application/json']),
			'body'     => wp_json_encode(['reason' => $reason]),
		]);
	}

	private function channel_url($action) {
		return $this->base_url . '/api/plugin/channels/' . rawurlencode((string) $this->channel_id) . '/' . $action;
	}

	private function auth_headers() {
		return [
			'Authorization' => 'Bearer ' . $this->connector_token,
			'Accept'        => 'application/json',
		];
	}

	/**
	 * @return array{ok: bool, data?: array, message?: string}
	 */
	private function parse_response($response) {
		if (is_wp_error($response)) {
			return ['ok' => false, 'message' => $response->get_error_message()];
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code < 200 || $code >= 300) {
			$message = is_array($body) && ! empty($body['message']) ? $body['message'] : ('HTTP ' . $code);

			return ['ok' => false, 'message' => $message];
		}

		return ['ok' => true, 'data' => is_array($body) ? ($body['data'] ?? $body) : []];
	}
}
