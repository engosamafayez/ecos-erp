<?php
/**
 * Fires only on full plugin deletion from the Plugins screen (never on a mere deactivation — see
 * ecos-woocommerce-connector.php's register_deactivation_hook for that path). Removes only this
 * plugin's own WordPress option (the locally-stored base URL/channel id/credential pair); it never
 * contacts ECOS and never touches any ECOS business record — the Channel itself, its lifecycle
 * state, and its credential are an explicit, separate ECOS-side decision, never implied by a
 * WordPress-side uninstall.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('ecos_wc_connector_settings');
