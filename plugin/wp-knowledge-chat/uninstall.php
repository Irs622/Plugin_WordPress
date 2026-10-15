<?php
/**
 * Removes plugin data on uninstall (not on deactivation).
 *
 * @package WPKnowledgeChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_knowledge_chat_settings' );
delete_option( 'wkc_metrics' );
delete_option( 'wkc_leads' );

// Rate-limit and connection-test transients expire within minutes; remove any left over.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wkc_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wkc_' ) . '%',
		$wpdb->esc_like( '_site_transient_wkc_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_wkc_' ) . '%'
	)
);
