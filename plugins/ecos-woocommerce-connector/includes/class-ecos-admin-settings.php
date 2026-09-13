<?php
/**
 * Store-side configuration screen (042A §7 "Required store-side configuration screen: paste the
 * ECOS-issued key, show connection/webhook health"). A single admin page under Settings — no
 * WooCommerce UI is touched, no new admin menu structure beyond one page, no demo/fake data: the
 * diagnostics panel only ever renders what PluginAdapterController::status() actually returned.
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

	/**
	 * Capability check + nonce verification, exactly as WordPress requires for any settings
	 * write — this is the only state-changing entry point in the plugin's own admin UI (the
	 * ECOS-side pairing itself is read-only from here; see class docblock).
	 */
	public function maybe_handle_submit() {
		if (! isset($_POST['ecos_wc_connector_submit'])) {
			return;
		}

		if (! current_user_can('manage_options')) {
			return;
		}

		check_admin_referer('ecos_wc_connector_settings');

		$existing = get_option(ECOS_WC_CONNECTOR_OPTION, []);

		$base_url   = isset($_POST['base_url']) ? esc_url_raw(wp_unslash($_POST['base_url'])) : '';
		$channel_id = isset($_POST['channel_id']) ? sanitize_text_field(wp_unslash($_POST['channel_id'])) : '';
		$consumer_key = isset($_POST['consumer_key']) ? sanitize_text_field(wp_unslash($_POST['consumer_key'])) : '';

		// A blank secret field on save means "keep the existing secret" — the real value is
		// never re-rendered into the form, so leaving it blank is how an operator saves other
		// fields (e.g. rotating only the base URL) without having to re-enter it.
		$consumer_secret_input = isset($_POST['consumer_secret']) ? sanitize_text_field(wp_unslash($_POST['consumer_secret'])) : '';
		$consumer_secret = $consumer_secret_input !== '' ? $consumer_secret_input : ($existing['consumer_secret'] ?? '');

		$settings = [
			'base_url'        => untrailingslashit($base_url),
			'channel_id'      => $channel_id,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		];

		update_option(ECOS_WC_CONNECTOR_OPTION, $settings, false);

		add_settings_error('ecos_wc_connector', 'saved', __('Settings saved.', 'ecos-wc-connector'), 'success');
	}

	public function render_page() {
		if (! current_user_can('manage_options')) {
			return;
		}

		$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);
		$configured = ! empty($settings['base_url']) && ! empty($settings['channel_id']) && ! empty($settings['consumer_key']) && ! empty($settings['consumer_secret']);

		settings_errors('ecos_wc_connector');

		echo '<div class="wrap"><h1>' . esc_html__('ECOS Connector', 'ecos-wc-connector') . '</h1>';
		echo '<p>' . esc_html__('Pairs this store with its ECOS ERP channel. Products, prices, stock, customers, and orders continue to sync entirely through WooCommerce\'s own REST API and webhooks — this screen only manages the connection itself.', 'ecos-wc-connector') . '</p>';

		$this->render_form($settings);

		if ($configured) {
			$this->render_diagnostics($settings);
		}

		echo '</div>';
	}

	private function render_form($settings) {
		?>
		<form method="post">
			<?php wp_nonce_field('ecos_wc_connector_settings'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ecos_base_url"><?php esc_html_e('ECOS Base URL', 'ecos-wc-connector'); ?></label></th>
					<td><input type="url" id="ecos_base_url" name="base_url" class="regular-text" value="<?php echo esc_attr($settings['base_url'] ?? ''); ?>" placeholder="https://erp.example.com" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="ecos_channel_id"><?php esc_html_e('Channel ID', 'ecos-wc-connector'); ?></label></th>
					<td><input type="text" id="ecos_channel_id" name="channel_id" class="regular-text" value="<?php echo esc_attr($settings['channel_id'] ?? ''); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="ecos_consumer_key"><?php esc_html_e('Consumer Key', 'ecos-wc-connector'); ?></label></th>
					<td><input type="text" id="ecos_consumer_key" name="consumer_key" class="regular-text" value="<?php echo esc_attr($settings['consumer_key'] ?? ''); ?>" autocomplete="off" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="ecos_consumer_secret"><?php esc_html_e('Consumer Secret', 'ecos-wc-connector'); ?></label></th>
					<td>
						<input type="password" id="ecos_consumer_secret" name="consumer_secret" class="regular-text" value="" autocomplete="off" placeholder="<?php echo ! empty($settings['consumer_secret']) ? esc_attr__('•••••••• (unchanged — leave blank to keep)', 'ecos-wc-connector') : ''; ?>">
						<p class="description"><?php esc_html_e('The same consumer key/secret pair already issued for this channel\'s WooCommerce REST API connection in ECOS. Never displayed again once saved.', 'ecos-wc-connector'); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(__('Save & Test Connection', 'ecos-wc-connector'), 'primary', 'ecos_wc_connector_submit'); ?>
		</form>
		<?php
	}

	private function render_diagnostics($settings) {
		$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['consumer_key'], $settings['consumer_secret']);
		$result = $client->fetch_status();

		echo '<h2>' . esc_html__('Connection Status', 'ecos-wc-connector') . '</h2>';

		if (! $result['ok']) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				/* translators: %s: error message returned by ECOS or the HTTP client. */
				sprintf(__('Connection failed: %s', 'ecos-wc-connector'), $result['message'])
			) . '</p></div>';

			return;
		}

		$data = $result['data'];
		$channel = $data['channel'] ?? [];
		$webhooks = $data['webhooks'] ?? [];

		echo '<div class="notice notice-success inline"><p>' . esc_html__('Connected.', 'ecos-wc-connector') . '</p></div>';

		echo '<table class="widefat striped" style="max-width:600px"><tbody>';
		$this->render_diagnostic_row(__('Store', 'ecos-wc-connector'), $channel['name'] ?? '—');
		$this->render_diagnostic_row(__('Lifecycle State', 'ecos-wc-connector'), $channel['lifecycle_state_label'] ?? '—');
		$this->render_diagnostic_row(__('Connection Status', 'ecos-wc-connector'), $channel['connection_status'] ?? '—');
		$this->render_diagnostic_row(__('Health', 'ecos-wc-connector'), $channel['health_status'] ?? '—');
		$this->render_diagnostic_row(__('Last Sync', 'ecos-wc-connector'), $data['last_sync_at'] ?? __('Never', 'ecos-wc-connector'));
		$this->render_diagnostic_row(__('Last Webhook Received', 'ecos-wc-connector'), $data['last_webhook_received_at'] ?? __('Never', 'ecos-wc-connector'));

		$registered = array_filter($webhooks);
		$this->render_diagnostic_row(
			__('Webhooks Registered', 'ecos-wc-connector'),
			sprintf('%d / %d', count($registered), count($webhooks))
		);
		echo '</tbody></table>';

		if (! empty($data['last_error_message']) && $channel['health_status'] === 'error') {
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
