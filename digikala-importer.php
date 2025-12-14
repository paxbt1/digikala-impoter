<?php
/**
 * Plugin Name: Digikala Importer (DKI)
 * Description: Import products from Digikala into WooCommerce via Digikala public APIs (search, product, category/brand search).
 * Version: 2.1.3
 * Author: Woocom / created by Ghourbanian
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: digikala-importer
 */

if (!defined('ABSPATH')) exit;

define('DKI_VERSION', '2.1.3');
define('DKI_PLUGIN_FILE', __FILE__);
define('DKI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DKI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DKI_PLUGIN_DIR . 'includes/class-dki-scraper.php';
require_once DKI_PLUGIN_DIR . 'includes/class-dki-importer.php';
require_once DKI_PLUGIN_DIR . 'includes/class-dki-admin.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;
    new DKI_Admin();
});
