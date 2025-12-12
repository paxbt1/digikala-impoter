<?php
if (!defined('ABSPATH')) exit;

class DKI_Admin {

    const NONCE_ACTION = 'dki_admin_nonce_action';
    const NONCE_NAME   = 'nonce';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        add_action('wp_ajax_dki_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_dki_import_product', [$this, 'ajax_import_product']);         // by URL
        add_action('wp_ajax_dki_import_product_by_id', [$this, 'ajax_import_product_by_id']);
        add_action('wp_ajax_dki_save_settings', [$this, 'ajax_save_settings']);
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'درج از دیجی‌کالا',
            'درج از دیجی‌کالا',
            'manage_woocommerce',
            'dki-importer',
            [$this, 'render_page']
        );
    }

    public function enqueue($hook) {
        if ($hook !== 'woocommerce_page_dki-importer') return;

        wp_enqueue_style(
            'dki-admin-css',
            DKI_PLUGIN_URL . 'assets/css/dki-admin.css',
            [],
            DKI_VERSION
        );

        wp_enqueue_script(
            'dki-admin-js',
            DKI_PLUGIN_URL . 'assets/js/dki-admin.js',
            ['jquery'],
            DKI_VERSION,
            true
        );

        wp_localize_script('dki-admin-js', 'DKI_Admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'i18n'     => [
                'searching' => 'در حال جستجو در دیجی‌کالا...',
                'server_error' => 'خطای ارتباط با سرور.',
            ]
        ]);
    }

    public function render_page() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>ووکامرس فعال نیست.</p></div>';
        }

        $settings = DKI_Options::get_all();
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'search';
        $tabs = [
            'search'   => 'جستجو',
            'by_id'    => 'درج با شناسه محصول',
            'settings' => 'تنظیمات',
        ];
        ?>
        <div class="wrap dki-wrap">
            <h1 class="dki-title">درج محصول از دیجی‌کالا</h1>

            <nav class="dki-tabs">
                <?php foreach ($tabs as $k => $label): ?>
                    <a class="dki-tab <?php echo $tab === $k ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url(admin_url('admin.php?page=dki-importer&tab=' . $k)); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'search'): ?>
                <div class="dki-card">
                    <div class="dki-card-header">
                        <div>
                            <div class="dki-card-title">جستجوی محصول</div>
                            <div class="dki-card-subtitle">عبارت را وارد کنید و از بین نتایج، محصول را درج کنید.</div>
                        </div>
                    </div>
                    <div class="dki-card-body">
                        <div class="dki-row">
                            <input type="text" id="dki-search-query" class="dki-input" placeholder="مثلاً: آیفون 15 پرو مکس">
                            <button id="dki-search-btn" class="button button-primary dki-btn">جستجو</button>

                            <div class="dki-page-nav">
                                <button id="dki-prev-page" class="button">قبلی</button>
                                <span id="dki-page-indicator">1</span>
                                <button id="dki-next-page" class="button">بعدی</button>
                            </div>
                        </div>

                        <div id="dki-search-status" class="dki-status"></div>
                        <div id="dki-search-results" class="dki-results"></div>
                    </div>
                </div>

                <div class="dki-card dki-mt">
                    <div class="dki-card-header">
                        <div>
                            <div class="dki-card-title">لاگ</div>
                            <div class="dki-card-subtitle">خروجی عملیات درج</div>
                        </div>
                        <button id="dki-clear-log" class="button">پاک کردن</button>
                    </div>
                    <div class="dki-card-body">
                        <div id="dki-import-log" class="dki-log"></div>
                    </div>
                </div>

            <?php elseif ($tab === 'by_id'): ?>
                <div class="dki-card">
                    <div class="dki-card-header">
                        <div>
                            <div class="dki-card-title">درج مستقیم با شناسه محصول</div>
                            <div class="dki-card-subtitle">شناسه محصول دیجی‌کالا را وارد کنید (مثال: 19404627)</div>
                        </div>
                    </div>
                    <div class="dki-card-body">
                        <div class="dki-row">
                            <input type="number" min="1" id="dki-product-id" class="dki-input" placeholder="Product ID">
                            <button id="dki-import-by-id-btn" class="button button-primary dki-btn">درج</button>
                        </div>
                        <div id="dki-by-id-status" class="dki-status"></div>
                        <div class="dki-hr"></div>
                        <div id="dki-by-id-log" class="dki-log"></div>
                    </div>
                </div>

            <?php elseif ($tab === 'settings'): ?>
                <div class="dki-card">
                    <div class="dki-card-header">
                        <div>
                            <div class="dki-card-title">تنظیمات</div>
                            <div class="dki-card-subtitle">نحوه درج قیمت، وضعیت انتشار و...</div>
                        </div>
                    </div>
                    <div class="dki-card-body">
                        <div class="dki-form-grid">

                            <div class="dki-field">
                                <label>واحد قیمت دیجی‌کالا → سایت</label>
                                <select id="dki-price-unit" class="dki-select">
                                    <option value="auto" <?php selected($settings['price_unit'], 'auto'); ?>>خودکار (بر اساس واحد پول ووکامرس)</option>
                                    <option value="toman" <?php selected($settings['price_unit'], 'toman'); ?>>تومان (تقسیم بر 10)</option>
                                    <option value="rial" <?php selected($settings['price_unit'], 'rial'); ?>>ریال (بدون تبدیل)</option>
                                </select>
                            </div>

                            <div class="dki-field">
                                <label>وضعیت محصول بعد از درج</label>
                                <select id="dki-post-status" class="dki-select">
                                    <option value="publish" <?php selected($settings['post_status'], 'publish'); ?>>انتشار</option>
                                    <option value="draft" <?php selected($settings['post_status'], 'draft'); ?>>پیش‌نویس</option>
                                </select>
                            </div>

                            <div class="dki-field">
                                <label>اگر قبلاً همین محصول درج شده باشد</label>
                                <select id="dki-update-existing" class="dki-select">
                                    <option value="yes" <?php selected($settings['update_existing'], 'yes'); ?>>به‌روزرسانی همان محصول</option>
                                    <option value="no" <?php selected($settings['update_existing'], 'no'); ?>>محصول جدید بساز</option>
                                </select>
                            </div>

                            <div class="dki-field">
                                <label>ایجاد ویژگی‌های سراسری ووکامرس</label>
                                <select id="dki-create-global-attrs" class="dki-select">
                                    <option value="yes" <?php selected($settings['create_global_attributes'], 'yes'); ?>>بله (نمایش در Attributes ووکامرس)</option>
                                    <option value="no" <?php selected($settings['create_global_attributes'], 'no'); ?>>خیر (فقط لوکال روی محصول)</option>
                                </select>
                            </div>

                            <div class="dki-field">
                                <label>حداکثر تعداد تصاویر گالری</label>
                                <input type="number" min="1" max="30" id="dki-image-limit" class="dki-input" value="<?php echo esc_attr((int)$settings['image_limit']); ?>">
                            </div>

                            <div class="dki-field">
                                <label>Timeout درخواست‌ها (ثانیه)</label>
                                <input type="number" min="5" max="60" id="dki-timeout" class="dki-input" value="<?php echo esc_attr((int)$settings['timeout']); ?>">
                            </div>

                        </div>

                        <button id="dki-save-settings" class="button button-primary dki-btn">ذخیره تنظیمات</button>
                        <span id="dki-settings-status" class="dki-status-inline"></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_search_products() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی ندارید.'], 403);
        }

        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;

        $results = DKI_Scraper::search_products($q, $page);
        if (is_wp_error($results)) {
            wp_send_json_error(['message' => $results->get_error_message()]);
        }

        wp_send_json_success([
            'results' => $results,
            'page'    => $page,
        ]);
    }

    public function ajax_import_product() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی ندارید.'], 403);
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if (!$url) {
            wp_send_json_error(['message' => 'آدرس محصول ارسال نشده است.']);
        }

        $product = DKI_Scraper::fetch_product_by_url($url);
        if (is_wp_error($product)) {
            wp_send_json_error(['message' => $product->get_error_message()]);
        }

        $product_id = DKI_Importer::import_product($product);
        if (is_wp_error($product_id)) {
            wp_send_json_error(['message' => $product_id->get_error_message()]);
        }

        wp_send_json_success([
            'product_id' => (int)$product_id,
            'edit_link'  => get_edit_post_link((int)$product_id, ''),
        ]);
    }

    public function ajax_import_product_by_id() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی ندارید.'], 403);
        }

        $id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => 'شناسه محصول نامعتبر است.']);
        }

        $product = DKI_Scraper::fetch_product_by_id($id);
        if (is_wp_error($product)) {
            wp_send_json_error(['message' => $product->get_error_message()]);
        }

        $product_id = DKI_Importer::import_product($product);
        if (is_wp_error($product_id)) {
            wp_send_json_error(['message' => $product_id->get_error_message()]);
        }

        wp_send_json_success([
            'product_id' => (int)$product_id,
            'edit_link'  => get_edit_post_link((int)$product_id, ''),
        ]);
    }

    public function ajax_save_settings() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی ندارید.'], 403);
        }

        $price_unit = isset($_POST['price_unit']) ? sanitize_key($_POST['price_unit']) : 'auto';
        if (!in_array($price_unit, ['auto','toman','rial'], true)) $price_unit = 'auto';

        $post_status = isset($_POST['post_status']) ? sanitize_key($_POST['post_status']) : 'publish';
        if (!in_array($post_status, ['publish','draft'], true)) $post_status = 'publish';

        $update_existing = isset($_POST['update_existing']) ? sanitize_key($_POST['update_existing']) : 'yes';
        if (!in_array($update_existing, ['yes','no'], true)) $update_existing = 'yes';

        $create_global = isset($_POST['create_global_attributes']) ? sanitize_key($_POST['create_global_attributes']) : 'yes';
        if (!in_array($create_global, ['yes','no'], true)) $create_global = 'yes';

        $timeout = isset($_POST['timeout']) ? (int)$_POST['timeout'] : 25;
        $timeout = max(5, min(60, $timeout));

        $image_limit = isset($_POST['image_limit']) ? (int)$_POST['image_limit'] : 12;
        $image_limit = max(1, min(30, $image_limit));

        DKI_Options::update([
            'price_unit'        => $price_unit,
            'post_status'       => $post_status,
            'update_existing'   => $update_existing,
            'timeout'           => $timeout,
            'image_limit'       => $image_limit,
            'create_global_attributes' => $create_global,
        ]);

        wp_send_json_success(['message' => 'تنظیمات ذخیره شد.']);
    }
}
