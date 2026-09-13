<?php
/**
 * Thin HTTP client for the two ECOS-side plugin-adapter endpoints
 * (PluginAdapterController::status / ::deactivated). No WooCommerce or ECOS business data ever
 * passes through this class — it only carries the channel id + the same consumer key/secret pair
 * already issued for this channel's WooCommerce REST API connection, presented as HTTP Basic Auth.
 * That pair is never logged and never echoed back into the admin UI once saved.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Ecos_Wc_Connector_Api_Client {

	private $base_url;
	private $channel_id;
	private $consumer_key;
	private $consumer_secret;

	public function __construct($base_url, $channel_id, $consumer_key, $consumer_secret) {
		$this->base_url        = untrailingslashit($base_url);
		$this->channel_id      = $channel_id;
		$this->consumer_key    = $consumer_key;
		$this->consumer_secret = $consumer_secret;
	}

	/**
	 * GET .../api/plugin/channels/{channel}/status — read-only, no side effects on the ECOS side.
	 *
	 * @return array{ok: bool, data?: array, message?: string}
	 */
	public function fetch_status() {
		$url = $this->base_url . '/api/plugin/channels/' . rawurlencode($this->channel_id) . '/status';

		$response = wp_remote_get($url, [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret),
				'Accept'        => 'application/json',
			],
		]);

		return $this->parse_response($response);
	}

	/**
	 * POST .../api/plugin/channels/{channel}/deactivated — fire-and-forget: a short timeout and
	 * no blocking, so an unreachable ECOS instance never delays WordPress's own deactivation
	 * lifecycle. Failure here is intentionally silent (there is nothing an operator deactivating
	 * a plugin can action on a failed notice, and it is never business-critical — see the class
	 * docblock on the ECOS side).
	 */
	public function notify_deactivated($reason) {
		$url = $this->base_url . '/api/plugin/channels/' . rawurlencode($this->channel_id) . '/deactivated';

		wp_remote_post($url, [
			'timeout'   => 5,
			'blocking'  => false,
			'headers'   => [
				'Authorization' => 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'      => wp_json_encode(['reason' => $reason]),
		]);
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
