<?php
/**
 * Plugin Name: Digikala WooCommerce Importer (API v2)
 * Description: جستجو و درج محصولات دیجی‌کالا در ووکامرس با استفاده از API رسمی (v1 search + v2 product).
 * Version: 2.1.0
 * Author: Woocom.ir
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('DKI_VERSION', '2.1.0');
define('DKI_PLUGIN_FILE', __FILE__);
define('DKI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DKI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DKI_PLUGIN_DIR . 'includes/class-dki-options.php';
require_once DKI_PLUGIN_DIR . 'includes/class-dki-scraper.php';
require_once DKI_PLUGIN_DIR . 'includes/class-dki-importer.php';
require_once DKI_PLUGIN_DIR . 'includes/class-dki-admin.php';

function dki_bootstrap() {
    if (!class_exists('WooCommerce')) {
        // اجازه بده پلاگین بالا بیاد، ولی صفحه ادمین پیام بده
    }
    new DKI_Admin();
}
add_action('plugins_loaded', 'dki_bootstrap', 9);
