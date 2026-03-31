<?php
/**
 * Plugin Name: Unused Image Finder
 * Description: Scans your WordPress media library for images not used in posts, pages, widgets, or theme options.
 * Version: 2.3.2
 * Author: Sites at Scale
 * License: GPL v2 or later
 * Text Domain: unused-image-finder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UIF_VERSION', '2.3.2' );
define( 'UIF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UIF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once UIF_PLUGIN_DIR . 'includes/class-uif-scanner.php';
require_once UIF_PLUGIN_DIR . 'includes/class-uif-admin.php';

add_action( 'plugins_loaded', function () {
    UIF_Admin::init();
} );
