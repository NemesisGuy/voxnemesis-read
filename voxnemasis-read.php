<?php
/**
 * Plugin Name:       VoxNemasis Read
 * Plugin URI:        https://nemasisnet.co.za
 * Description:       Let readers hear your posts, with the speech generated in their own browser. No server inference, no API key, no per-request cost.
 * Version:           0.1.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Nemasis Net
 * Author URI:        https://nemasisnet.co.za
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       voxnemasis-read
 * Domain Path:       /languages
 *
 * @package voxnemasis_read
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VNRA_VERSION', '0.1.2' );
define( 'VNRA_FILE', __FILE__ );
define( 'VNRA_PATH', plugin_dir_path( __FILE__ ) );
define( 'VNRA_URL', plugin_dir_url( __FILE__ ) );
define( 'VNRA_BASENAME', plugin_basename( __FILE__ ) );
define( 'VNRA_CREDIT_URL', 'https://nemasisnet.co.za' );

require_once VNRA_PATH . 'includes/class-voxnemasis-read.php';
require_once VNRA_PATH . 'includes/class-settings.php';
require_once VNRA_PATH . 'includes/class-player.php';

/**
 * The one instance of the plugin.
 *
 * @return voxnemasis_read
 */
function voxnemasis_read() {
    return voxnemasis_read::instance();
}

voxnemasis_read()->boot();
