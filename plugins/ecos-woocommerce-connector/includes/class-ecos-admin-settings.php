<?php
/**
 * Store-side configuration screen. TASK-...-CONSOLIDATED-REMEDIATION-001-R2 — replaces the
 * previous "paste base URL + channel id + Woo consumer key/secret" screen with a pairing flow:
 * the merchant enters the ECOS base URL and a short-lived pairing code an ECOS operator
 * generated for this store's Channel; everything else (which Channel, which Brand, which
 * Company, the WooCommerce REST credential, the webhook subscriptions) is resolved and
 * configured automatically by ECOS during the pairing exchange. No webhook URL, topic, or
 * secret is ever shown to or entered by the merchant.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Ecos_Wc_Connector_Admin_Settings {

	private static $instance = null;

	public static function instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'register_page']);
		add_action('admin_init', [$this, 'maybe_handle_submit']);
	}

	public function register_page() {
		add_options_page(
			__('ECOS Connector', 'ecos-wc-connector'),
			__('ECOS Connector', 'ecos-wc-connector'),
			'manage_options',
			'ecos-wc-connector',
			[$this, 'render_page']
		);
	}

	public function maybe_handle_submit() {
		if (! current_user_can('manage_options')) {
			return;
		}

		if (isset($_POST['ecos_wc_connector_pair'])) {
			check_admin_referer('ecos_wc_connector_settings');
			$this->handle_pair_submit();

			return;
		}

		if (isset($_POST['ecos_wc_connector_disconnect'])) {
			check_admin_referer('ecos_wc_connector_settings');
			$this->handle_disconnect_submit();

			return;
		}

		if (isset($_POST['ecos_wc_connector_repair'])) {
			check_admin_referer('ecos_wc_connector_settings');
			$this->handle_repair_submit();
		}
	}

	private function handle_pair_submit() {
		$existing = get_option(ECOS_WC_CONNECTOR_OPTION, []);

		// The base URL is LOCKED once paired (042A/R2 §28) — re-pointing an already-paired
		// plugin at a different ECOS instance without an explicit disconnect first would
		// silently detach it from the Channel the operator believes it is still talking to.
		if (! empty($existing['connector_token'])) {
			add_settings_error('ecos_wc_connector', 'already_paired', __('Already paired. Disconnect first to pair with a different ECOS environment.', 'ecos-wc-connector'), 'error');

			return;
		}

		$base_url = isset($_POST['base_url']) ? esc_url_raw(wp_unslash($_POST['base_url'])) : '';
		$pairing_code = isset($_POST['pairing_code']) ? sanitize_text_field(wp_unslash($_POST['pairing_code'])) : '';

		if ($base_url === '' || $pairing_code === '') {
			add_settings_error('ecos_wc_connector', 'missing_fields', __('ECOS Base URL and Pairing Code are both required.', 'ecos-wc-connector'), 'error');

			return;
		}

		$client = new Ecos_Wc_Connector_Api_Client($base_url);
		$result = $client->pair($pairing_code);

		if (! $result['ok']) {
			add_settings_error('ecos_wc_connector', 'pair_failed', sprintf(
				/* translators: %s: error message returned by ECOS or the HTTP client. */
				__('Pairing failed: %s', 'ecos-wc-connector'),
				$result['message']
			), 'error');

			return;
		}

		update_option(ECOS_WC_CONNECTOR_OPTION, [
			'base_url'        => untrailingslashit($base_url),
			'channel_id'      => $result['data']['channel_id'],
			'connector_token' => $result['data']['connector_token'],
		], false);

		Ecos_Wc_Connector_Heartbeat::schedule();

		add_settings_error('ecos_wc_connector', 'paired', __('Paired successfully. Required webhooks were configured automatically.', 'ecos-wc-connector'), 'success');
	}

	private function handle_disconnect_submit() {
		$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

		if (! empty($settings['connector_token'])) {
			$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['connector_token']);
			$client->notify_deactivated('manual_disconnect');
		}

		delete_option(ECOS_WC_CONNECTOR_OPTION);
		Ecos_Wc_Connector_Heartbeat::unschedule();

		add_settings_error('ecos_wc_connector', 'disconnected', __('Disconnected. You can pair with a new ECOS environment or Channel now.', 'ecos-wc-connector'), 'success');
	}

	private function handle_repair_submit() {
		$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

		if (empty($settings['connector_token'])) {
			return;
		}

		$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['connector_token']);
		$result = $client->repair();

		add_settings_error(
			'ecos_wc_connector',
			'repair_result',
			$result['ok'] ? __('Repair complete — webhooks re-verified.', 'ecos-wc-connector') : sprintf(__('Repair failed: %s', 'ecos-wc-connector'), $result['message']),
			$result['ok'] ? 'success' : 'error'
		);
	}

	public function render_page() {
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);
		$paired = ! empty($settings['connector_token']);

		settings_errors('ecos_wc_connector');

		echo '<div class="wrap"><h1>' . esc_html__('ECOS Connector', 'ecos-wc-connector') . '</h1>';
		echo '<p>' . esc_html__('Connects this store to ECOS. Products, prices, availability, customers, and orders sync automatically once paired — no webhook setup is required.', 'ecos-wc-connector') . '</p>';

		if ($paired) {
			$this->render_connected_panel($settings);
			$this->render_diagnostics($settings);
		} else {
			$this->render_pair_form($settings);
		}

		echo '</div>';
	}

	private function render_pair_form($settings) {
		?>
		<form method="post">
			<?php wp_nonce_field('ecos_wc_connector_settings'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ecos_base_url"><?php esc_html_e('ECOS Base URL', 'ecos-wc-connector'); ?></label></th>
					<td><input type="url" id="ecos_base_url" name="base_url" class="regular-text" value="<?php echo esc_attr($settings['base_url'] ?? ''); ?>" placeholder="https://erp.example.com" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="ecos_pairing_code"><?php esc_html_e('Pairing Code', 'ecos-wc-connector'); ?></label></th>
					<td>
						<input type="text" id="ecos_pairing_code" name="pairing_code" class="regular-text" autocomplete="off" required>
						<p class="description"><?php esc_html_e('Ask your ECOS operator for a pairing code from the Channel settings screen. It is valid for 15 minutes and can only be used once.', 'ecos-wc-connector'); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(__('Connect / Pair', 'ecos-wc-connector'), 'primary', 'ecos_wc_connector_pair'); ?>
		</form>
		<?php
	}

	private function render_connected_panel($settings) {
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__('ECOS Base URL', 'ecos-wc-connector') . '</th><td>' . esc_html($settings['base_url']) . '</td></tr>';
		echo '<tr><th>' . esc_html__('Channel ID', 'ecos-wc-connector') . '</th><td><code>' . esc_html($settings['channel_id']) . '</code></td></tr>';
		echo '</tbody></table>';

		echo '<form method="post" style="display:inline-block;margin-right:8px">';
		wp_nonce_field('ecos_wc_connector_settings');
		submit_button(__('Repair Connection', 'ecos-wc-connector'), 'secondary', 'ecos_wc_connector_repair', false);
		echo '</form>';

		echo '<form method="post" style="display:inline-block" onsubmit="return confirm(\'' . esc_js(__('Disconnect this store from ECOS?', 'ecos-wc-connector')) . '\');">';
		wp_nonce_field('ecos_wc_connector_settings');
		submit_button(__('Disconnect', 'ecos-wc-connector'), 'delete', 'ecos_wc_connector_disconnect', false);
		echo '</form>';
	}

	private function render_diagnostics($settings) {
		$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['connector_token']);
		$result = $client->fetch_status();

		echo '<h2>' . esc_html__('Connection Status', 'ecos-wc-connector') . '</h2>';

		if (! $result['ok']) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				/* translators: %s: error message returned by ECOS or the HTTP client. */
				sprintf(__('Could not reach ECOS: %s', 'ecos-wc-connector'), $result['message'])
			) . '</p></div>';

			return;
		}

		$data = $result['data'];
		$channel = $data['channel'] ?? [];
		$webhooks = $data['webhooks'] ?? [];

		$health = $channel['connector_health'] ?? 'never_connected';
		$notice_class = $health === 'healthy' ? 'notice-success' : ($health === 'degraded' ? 'notice-warning' : 'notice-error');
		echo '<div class="notice ' . esc_attr($notice_class) . ' inline"><p>' . esc_html($channel['connector_health_label'] ?? '—') . '</p></div>';

		echo '<table class="widefat striped" style="max-width:600px"><tbody>';
		$this->render_diagnostic_row(__('Store', 'ecos-wc-connector'), $channel['name'] ?? '—');
		$this->render_diagnostic_row(__('Lifecycle State', 'ecos-wc-connector'), $channel['lifecycle_state_label'] ?? '—');
		$this->render_diagnostic_row(__('Connection Status', 'ecos-wc-connector'), $channel['connection_status'] ?? '—');
		$this->render_diagnostic_row(__('Health', 'ecos-wc-connector'), $channel['health_status'] ?? '—');
		$this->render_diagnostic_row(__('Last Sync', 'ecos-wc-connector'), $data['last_sync_at'] ?? __('Never', 'ecos-wc-connector'));
		$this->render_diagnostic_row(__('Last Webhook Received', 'ecos-wc-connector'), $data['last_webhook_received_at'] ?? __('Never', 'ecos-wc-connector'));
		$this->render_diagnostic_row(__('Last Heartbeat', 'ecos-wc-connector'), $data['connector_last_heartbeat_at'] ?? __('Never', 'ecos-wc-connector'));

		$registered = array_filter($webhooks);
		$this->render_diagnostic_row(
			__('Webhooks Registered', 'ecos-wc-connector'),
			sprintf('%d / %d', count($registered), count($webhooks))
		);
		echo '</tbody></table>';

		if (! empty($data['last_error_message']) && ($channel['health_status'] ?? '') === 'error') {
			echo '<p class="description">' . esc_html(
				/* translators: %s: the last recorded sync error message. */
				sprintf(__('Last error: %s', 'ecos-wc-connector'), $data['last_error_message'])
			) . '</p>';
		}
	}

	private function render_diagnostic_row($label, $value) {
		echo '<tr><th style="width:220px">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
	}
}
