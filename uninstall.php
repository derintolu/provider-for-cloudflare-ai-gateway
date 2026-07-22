<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all data stored by the plugin:
 *  - Plugin settings option (cfaig_settings)
 *  - Cached model list transient (cfaig_model_list)
 *
 * @since 0.1.0
 *
 * @package WordPress\CloudflareAiGateway
 */

// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'cfaig_settings' );
delete_transient( 'cfaig_model_list' );
