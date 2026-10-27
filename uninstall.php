<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data
 * (the link index, the undo log, the lists and settings) from every site.
 *
 * Posts aren't touched: links the plugin changed stay as they are.
 *
 * @package Link_Audit_Reports
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function link_audit_reports_uninstall_site() {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lar_links, {$wpdb->prefix}lar_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names only.

	foreach ( array( 'settings', 'lists', 'scan', 'scan_lock', 'job_lock', 'db_version' ) as $name ) {
		delete_option( 'link_audit_reports_' . $name );
	}

	wp_clear_scheduled_hook( 'link_audit_reports_scan_batch' );
	wp_clear_scheduled_hook( 'link_audit_reports_job_batch' );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $link_audit_reports_site ) {
		switch_to_blog( $link_audit_reports_site );
		link_audit_reports_uninstall_site();
		restore_current_blog();
	}
} else {
	link_audit_reports_uninstall_site();
}

// Rows-per-page choice, saved per user.
delete_metadata( 'user', 0, 'link_audit_reports_per_page', '', true );

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-link-audit-reports' );
