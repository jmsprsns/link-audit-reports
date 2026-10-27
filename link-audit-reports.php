<?php
/**
 * Plugin Name:       Link Audit and Reports
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Audits every outbound link on your site, grouped by domain, with bulk rel changes, link stripping, whitelists, blacklists, an undo log and CSV export.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       link-audit-reports
 * Update URI:        https://github.com/jmsprsns/link-audit-reports
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$link_audit_reports_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'LINK_AUDIT_REPORTS_VERSION', $link_audit_reports_headers['version'] );
define( 'LINK_AUDIT_REPORTS_REPO', $link_audit_reports_headers['repo'] );
define( 'LINK_AUDIT_REPORTS_FILE', __FILE__ );
define( 'LINK_AUDIT_REPORTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINK_AUDIT_REPORTS_URL', plugin_dir_url( __FILE__ ) );

unset( $link_audit_reports_headers );

require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-icons.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-updater.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-menu.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-feedback.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-db.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-content.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-scanner.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-jobs.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-export.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-admin.php';
require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-plugin.php';

if ( is_admin() ) {
	require_once LINK_AUDIT_REPORTS_DIR . 'includes/class-link-audit-reports-list-table.php';
}

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Link_Audit_Reports_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Link_Audit_Reports_Plugin
 */
function link_audit_reports_plugin() {
	return Link_Audit_Reports_Plugin::instance();
}
add_action( 'plugins_loaded', 'link_audit_reports_plugin' );

/**
 * On activation: create the tables and start the one-time background scan
 * of every post (it also catches anything edited while the plugin was off).
 *
 * @return void
 */
function link_audit_reports_activate() {
	Link_Audit_Reports_DB::install();
	Link_Audit_Reports_Scanner::start();
}
register_activation_hook( LINK_AUDIT_REPORTS_FILE, 'link_audit_reports_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * The link index, lists and undo log stay put (uninstall.php removes them).
 *
 * @return void
 */
function link_audit_reports_deactivate() {
	Link_Audit_Reports_Scanner::unschedule();
	Link_Audit_Reports_Jobs::unschedule();
}
register_deactivation_hook( LINK_AUDIT_REPORTS_FILE, 'link_audit_reports_deactivate' );
