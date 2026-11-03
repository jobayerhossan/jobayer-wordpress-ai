<?php
/**
 * Plugin Name: Jobayer AI Agent
 * Description: Secure WordPress AI development agent with code/content/media/Elementor editing, encrypted backups, rollback, diagnostics, and one-click MCP registration.
 * Version: 0.7.0
 * Author: Jobayer Hossan
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Update URI: https://github.com/jobayerhossan/jobayer-wordpress-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JAI_AGENT_VERSION', '0.7.0' );
define( 'JAI_AGENT_FILE', __FILE__ );
define( 'JAI_AGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'JAI_BACKUP_DIR', WP_CONTENT_DIR . '/jobayer-ai-backups' );

require_once JAI_AGENT_DIR . 'includes/class-jai-auth.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-backups.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-files.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-runtime.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-content.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-media.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-elementor.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-registry.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-rest.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-registry-rest.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-admin.php';
require_once JAI_AGENT_DIR . 'includes/class-jai-updater.php';

JAI_Updater::init();

register_activation_hook( __FILE__, function () {
    if ( ! get_option( 'jai_site_id' ) ) {
        update_option( 'jai_site_id', 'site_' . wp_generate_password( 18, false, false ), false );
    }
    if ( ! get_option( 'jai_site_secret' ) ) {
        update_option( 'jai_site_secret', bin2hex( random_bytes( 32 ) ), false );
    }
    if ( ! get_option( 'jai_backup_key' ) ) {
        update_option( 'jai_backup_key', bin2hex( random_bytes( 32 ) ), false );
    }
    if ( ! get_option( 'jai_registry_key' ) ) {
        update_option( 'jai_registry_key', bin2hex( random_bytes( 32 ) ), false );
    }
    JAI_Backups::ensure_backup_dir();
} );

add_action( 'plugins_loaded', function () {
    JAI_REST::init();
    JAI_Registry_REST::init();
    JAI_Admin::init();
} );
