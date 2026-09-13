<?php
/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 §26 — the smallest practical heartbeat mechanism:
 * WordPress's own standard cron (wp_schedule_event), not a new scheduler platform. This is the
 * ONLY thing that keeps ECOS's Channel::connectorHealth() reading Healthy rather than
 * Degraded — a site that goes offline without ever calling deactivate simply stops refreshing
 * the heartbeat, and ECOS reads that as staleness on its own, with no cron running here.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Ecos_Wc_Connector_Heartbeat {

	const HOOK = 'ecos_wc_connector_heartbeat';

	public static function boot() {
		add_action(self::HOOK, [self::class, 'send']);
	}

	public static function schedule() {
		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time(), 'ecos_wc_connector_five_minutes', self::HOOK);
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook(self::HOOK);
	}

	public static function register_interval($schedules) {
		$schedules['ecos_wc_connector_five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __('Every 5 Minutes (ECOS Connector)', 'ecos-wc-connector'),
		];

		return $schedules;
	}

	public static function send() {
		$settings = get_option(ECOS_WC_CONNECTOR_OPTION, []);

		if (empty($settings['connector_token'])) {
			self::unschedule();

			return;
		}

		$client = new Ecos_Wc_Connector_Api_Client($settings['base_url'], $settings['channel_id'], $settings['connector_token']);
		$client->heartbeat();
	}
}
