<?php
/**
 * Removes plugin data on uninstall (not on deactivation).
 *
 * @package WPKnowledgeChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes everything the plugin stored for the current site.
 */
function wkc_uninstall_site(): void {
	global $wpdb;

	delete_option( 'wp_knowledge_chat_settings' );
	delete_option( 'wkc_metrics' );
	delete_option( 'wkc_leads' );
	delete_option( 'wkc_synced_pages' );
	delete_option( 'wkc_sync_pending' );
	wp_clear_scheduled_hook( 'wkc_retry_sync' );

	// Consultation leads (personal data) are deleted with the plugin. Export them first
	// (Analytics & Leads screen, or Tools → Export Personal Data) if they must be kept.
	$wkc_lead_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'wkc_lead' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $wkc_lead_ids as $wkc_lead_id ) {
		wp_delete_post( (int) $wkc_lead_id, true );
	}

	// Rate-limit and connection-test transients expire within minutes; remove any left over.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wkc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wkc_' ) . '%',
			$wpdb->esc_like( '_site_transient_wkc_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_wkc_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wkc_site_id ) {
		switch_to_blog( (int) $wkc_site_id );
		wkc_uninstall_site();
		restore_current_blog();
	}
} else {
	wkc_uninstall_site();
}
