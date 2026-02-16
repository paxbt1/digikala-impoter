<?php
if (!defined('ABSPATH')) exit;

class DKI_Admin {

    const NONCE_ACTION = 'dki_admin_nonce_action';
    const NONCE_NAME   = 'nonce';
    const CRON_HOOK    = 'dki_price_update_tick';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_head', [$this, 'hide_wp_notices_on_importer_page']);
        add_action('admin_footer', [$this, 'render_variation_modal']);

        add_action('wp_ajax_dki_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_dki_import_product', [$this, 'ajax_import_product']);
        add_action('wp_ajax_dki_import_by_id', [$this, 'ajax_import_by_id']);

        add_action('wp_ajax_dki_category_fetch_page', [$this, 'ajax_category_fetch_page']);
        add_action('wp_ajax_dki_category_import_ids', [$this, 'ajax_category_import_ids']);

        add_action('wp_ajax_dki_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_dki_variation_search_products', [$this, 'ajax_variation_search_products']);
        add_action('wp_ajax_dki_variation_fetch_variants', [$this, 'ajax_variation_fetch_variants']);
        add_action('wp_ajax_dki_variation_link_product', [$this, 'ajax_variation_link_product']);
        add_action('wp_ajax_dki_price_update_now', [$this, 'ajax_price_update_now']);
        add_action('wp_ajax_dki_clear_price_logs', [$this, 'ajax_clear_price_logs']);

        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'ensure_cron_event']);
        add_action('init', [$this, 'handle_server_cron_request']);
        add_action(self::CRON_HOOK, [$this, 'cron_tick']);

        add_action('woocommerce_variation_options_pricing', [$this, 'render_variation_link_field'], 20, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_meta'], 10, 2);
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

    public function hide_wp_notices_on_importer_page() {
        if (!is_admin()) return;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'dki-importer') return;
        echo '<style id="dki-hide-core-notices">#wpbody-content > .notice, #wpbody-content > .error, #wpbody-content > .updated, #wpbody-content > .update-nag, #wpbody-content > .is-dismissible, #wpbody-content > div.fs-notice { display:none !important; }</style>';
    }

    private function is_product_edit_screen(): bool {
        if (!is_admin()) return false;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return false;

        return ($screen->base === 'post' && $screen->post_type === 'product');
    }

    public function enqueue($hook) {
        $is_importer = ($hook === 'woocommerce_page_dki-importer');
        $is_product_edit = $this->is_product_edit_screen();

        if (!$is_importer && !$is_product_edit) {
            return;
        }

        if ($is_importer) {
            wp_enqueue_style(
                'dki-metronic-bundle',
                DKI_PLUGIN_URL . 'assets/metronic/css/style.bundle.css',
                [],
                DKI_VERSION
            );
        }

        wp_enqueue_style(
            'dki-admin-css',
            DKI_PLUGIN_URL . 'assets/css/admin.css',
            $is_importer ? ['dki-metronic-bundle'] : [],
            DKI_VERSION
        );

        wp_enqueue_script(
            'dki-admin-js',
            DKI_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            DKI_VERSION,
            true
        );

        wp_localize_script('dki-admin-js', 'DKI_Admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'is_importer_page' => $is_importer,
            'is_product_edit'  => $is_product_edit,
        ]);
    }

    private function render_wc_category_checklist($selected = []) {
        if (!taxonomy_exists('product_cat')) return;

        $selected = array_map('intval', (array)$selected);
        echo '<div class="dkiux-catbox">';
        echo '<div class="dkiux-catbox-head">دسته‌بندی مقصد در ووکامرس</div>';
        echo '<div class="dkiux-catbox-body dki-wc-cat-tree-body">';
        wp_terms_checklist(0, [
            'taxonomy'     => 'product_cat',
            'checked_ontop'=> false,
            'selected_cats'=> $selected,
            'walker'       => new Walker_Category_Checklist(),
        ]);
        echo '</div>';
        echo '<div class="dkiux-catbox-foot">می‌توانید بیش از یک دسته‌بندی انتخاب کنید.</div>';
        echo '</div>';
    }

    public function render_page() {
        $price_mode = get_option('dki_price_mode', 'auto');
        $nofollow = get_option('dki_credit_nofollow', '1');

        $credit_enabled     = get_option('dki_credit_enabled', '1');
        $credit_text_mode   = get_option('dki_credit_text_mode', 'default');
        $credit_text_custom = get_option('dki_credit_text_custom', '');

        $alt_mode  = get_option('dki_image_alt_mode', 'product');
        $alt_fixed = get_option('dki_image_alt_fixed', '');

        $sync_enabled = get_option('dki_price_update_enabled', '0');
        $sync_period = get_option('dki_price_update_period', 'daily');
        $sync_weekday = get_option('dki_price_update_weekday', '0');
        $sync_time = get_option('dki_price_update_time', '09:00');
        $sync_batch_size = (int)get_option('dki_price_update_batch_size', 10);
        if ($sync_batch_size <= 0) $sync_batch_size = 10;
        $price_adjust_mode = get_option('dki_price_adjust_mode', 'none');
        $price_adjust_percent = (string)get_option('dki_price_adjust_percent', '0');
        $price_round_mode = get_option('dki_price_round_mode', 'none');
        $price_round_zeros = (int)get_option('dki_price_round_zeros', 0);
        if ($price_round_zeros < 0) $price_round_zeros = 0;
        if ($price_round_zeros > 6) $price_round_zeros = 6;
        $server_cron_enabled = get_option('dki_server_cron_enabled', '0');
        $server_cron_token = $this->get_server_cron_token();
        $server_cron_url = add_query_arg([
            'dki_cron' => '1',
            'dki_token' => $server_cron_token,
        ], home_url('/'));

        $last_report = get_option('dki_price_update_last_report', []);
        $last_report = is_array($last_report) ? $last_report : [];
        $price_logs = $this->get_price_update_logs();
        ?>
        <div class="wrap dkiux-admin" dir="rtl">
            <div class="dkiux-hero">
                <div class="dkiux-hero-titlebox">
                    <h1 class="dkiux-hero-title">درون‌ریزی و مدیریت محصولات دیجی‌کالا</h1>
                    <div class="dkiux-hero-sub">پنل حرفه‌ای افزونه با استایل اختصاصی و مستقل از قالب</div>
                </div>
                <span class="dkiux-version">DKI v<?php echo esc_html(DKI_VERSION); ?></span>
            </div>

            <div class="dkiux-shell">
                <div class="dkiux-tabs" id="dki-tabs" role="tablist">
                    <button class="dkiux-tab active" data-tab="tab-search" type="button">جستجو</button>
                    <button class="dkiux-tab" data-tab="tab-id" type="button">درج با شناسه</button>
                    <button class="dkiux-tab" data-tab="tab-category" type="button">درج از دسته‌بندی</button>
                    <button class="dkiux-tab" data-tab="tab-settings" type="button">تنظیمات</button>
                </div>
                <div class="dkiux-panels">

                    <div class="dki-tab-panel active" id="tab-search">
                        <div class="dkiux-grid">
                            <div class="dkiux-main">
                                <div class="dkiux-card">
                                    <div class="dkiux-card-head"><h3>جستجوی محصول</h3></div>
                                    <div class="dkiux-card-body">
                                        <div class="dkiux-inline">
                                            <input type="text" id="dki-search-query" class="dkiux-input" placeholder="مثلاً: آیفون 17 پرو مکس">
                                            <button id="dki-search-btn" class="dkiux-btn dkiux-btn-primary">جستجو در دیجی‌کالا</button>
                                        </div>
                                        <div id="dki-search-status" class="dkiux-status"></div>
                                        <div id="dki-search-results" class="dkiux-results"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="dkiux-side">
                                <?php $this->render_wc_category_checklist([]); ?>
                            </div>
                        </div>
                    </div>

                    <div class="dki-tab-panel" id="tab-id">
                        <div class="dkiux-grid">
                            <div class="dkiux-main">
                                <div class="dkiux-card">
                                    <div class="dkiux-card-head"><h3>درج مستقیم با شناسه</h3></div>
                                    <div class="dkiux-card-body">
                                        <div class="dkiux-inline">
                                            <input type="number" id="dki-product-id" class="dkiux-input" placeholder="مثلاً: 19404627">
                                            <button id="dki-import-by-id-btn" class="dkiux-btn dkiux-btn-primary">درج / به‌روزرسانی</button>
                                        </div>
                                        <div id="dki-id-status" class="dkiux-status"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="dkiux-side">
                                <?php $this->render_wc_category_checklist([]); ?>
                            </div>
                        </div>
                    </div>

                    <div class="dki-tab-panel" id="tab-category">
                        <div class="dkiux-grid">
                            <div class="dkiux-main">
                                <div class="dkiux-card">
                                    <div class="dkiux-card-head"><h3>درج از دسته‌بندی/برند</h3></div>
                                    <div class="dkiux-card-body">
                                        <div class="dkiux-inline">
                                            <input type="text" id="dki-category-url" class="dkiux-input" placeholder="https://www.digikala.com/search/category-air-conditioner-2/uneva/">
                                            <button id="dki-category-fetch-btn" class="dkiux-btn dkiux-btn-primary">خواندن محصولات</button>
                                            <button id="dki-category-import-all-btn" class="dkiux-btn dkiux-btn-ghost" disabled>درج همه</button>
                                        </div>
                                        <div id="dki-category-status" class="dkiux-status"></div>
                                        <div id="dki-category-results" class="dkiux-results"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="dkiux-side">
                                <?php $this->render_wc_category_checklist([]); ?>
                            </div>
                        </div>
                    </div>

                    <div class="dki-tab-panel" id="tab-settings">
                        <div class="dkiux-settings-grid">
                            <div class="dkiux-card dkiux-settings-card dkiux-settings-advanced">
                                <div class="dkiux-card-head"><h3>تنظیمات عمومی</h3></div>
                                <div class="dkiux-card-body">
                                    <div class="dkiux-field">
                                        <label>واحد قیمت</label>
                                        <select id="dki-price-mode" class="dkiux-input">
                                                <option value="auto" <?php selected($price_mode,'auto'); ?>>خودکار (تشخیص از واحد ووکامرس)</option>
                                                <option value="irr" <?php selected($price_mode,'irr'); ?>>ریال (ضربدر 10)</option>
                                                <option value="toman" <?php selected($price_mode,'toman'); ?>>تومان</option>
                                            </select>
                                        <div class="dkiux-help">قیمت دیجی‌کالا تومان است. اگر سایت روی ریال باشد، قیمت 10 برابر می‌شود.</div>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>درج لینک دیجی‌کالا در توضیحات</label>
                                        <select id="dki-credit-enabled" class="dkiux-input">
                                                <option value="1" <?php selected($credit_enabled,'1'); ?>>فعال</option>
                                                <option value="0" <?php selected($credit_enabled,'0'); ?>>غیرفعال</option>
                                            </select>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>متن لینک اعتباردهی</label>
                                        <select id="dki-credit-text-mode" class="dkiux-input">
                                                <option value="default" <?php selected($credit_text_mode,'default'); ?>>متن پیش‌فرض</option>
                                                <option value="custom" <?php selected($credit_text_mode,'custom'); ?>>متن سفارشی</option>
                                            </select>
                                        <input type="text" id="dki-credit-text-custom" class="dkiux-input" value="<?php echo esc_attr($credit_text_custom); ?>" placeholder="متن سفارشی لینک">
                                    </div>

                                    <div class="dkiux-field">
                                        <label>حالت nofollow</label>
                                        <select id="dki-credit-nofollow" class="dkiux-input">
                                                <option value="1" <?php selected($nofollow,'1'); ?>>nofollow</option>
                                                <option value="0" <?php selected($nofollow,'0'); ?>>follow</option>
                                            </select>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>آلت تصاویر</label>
                                        <select id="dki-image-alt-mode" class="dkiux-input">
                                                <option value="product" <?php selected($alt_mode,'product'); ?>>نام محصول</option>
                                                <option value="fixed" <?php selected($alt_mode,'fixed'); ?>>متن ثابت</option>
                                            </select>
                                        <input type="text" id="dki-image-alt-fixed" class="dkiux-input" value="<?php echo esc_attr($alt_fixed); ?>" placeholder="متن آلت">
                                    </div>
                                </div>
                            </div>

                            <div class="dkiux-card dkiux-settings-card dkiux-settings-advanced">
                                <div class="dkiux-card-head"><h3>بروزرسانی قیمت</h3></div>
                                <div class="dkiux-card-body">
                                    <div class="dkiux-field">
                                        <label>فعال‌سازی</label>
                                        <select id="dki-price-update-enabled" class="dkiux-input">
                                                <option value="1" <?php selected($sync_enabled,'1'); ?>>فعال</option>
                                                <option value="0" <?php selected($sync_enabled,'0'); ?>>غیرفعال</option>
                                            </select>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>پریود اجرا</label>
                                        <select id="dki-price-update-period" class="dkiux-input">
                                                <option value="daily" <?php selected($sync_period,'daily'); ?>>روزانه</option>
                                                <option value="weekly" <?php selected($sync_period,'weekly'); ?>>هفتگی</option>
                                            </select>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>روز هفته (برای حالت هفتگی)</label>
                                        <select id="dki-price-update-weekday" class="dkiux-input">
                                                <option value="0" <?php selected($sync_weekday,'0'); ?>>یکشنبه</option>
                                                <option value="1" <?php selected($sync_weekday,'1'); ?>>دوشنبه</option>
                                                <option value="2" <?php selected($sync_weekday,'2'); ?>>سه‌شنبه</option>
                                                <option value="3" <?php selected($sync_weekday,'3'); ?>>چهارشنبه</option>
                                                <option value="4" <?php selected($sync_weekday,'4'); ?>>پنجشنبه</option>
                                                <option value="5" <?php selected($sync_weekday,'5'); ?>>جمعه</option>
                                                <option value="6" <?php selected($sync_weekday,'6'); ?>>شنبه</option>
                                            </select>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>ساعت اجرا</label>
                                        <input type="time" id="dki-price-update-time" class="dkiux-input" value="<?php echo esc_attr($sync_time); ?>">
                                    </div>

                                    <div class="dkiux-field">
                                        <label>تغییر درصدی قیمت</label>
                                        <div class="dkiux-inline">
                                            <select id="dki-price-adjust-mode" class="dkiux-input">
                                                <option value="none" <?php selected($price_adjust_mode,'none'); ?>>بدون تغییر</option>
                                                <option value="increase" <?php selected($price_adjust_mode,'increase'); ?>>افزایش درصدی</option>
                                                <option value="decrease" <?php selected($price_adjust_mode,'decrease'); ?>>کاهش درصدی</option>
                                            </select>
                                            <input type="number" id="dki-price-adjust-percent" class="dkiux-input" min="0" step="0.1" value="<?php echo esc_attr($price_adjust_percent); ?>" placeholder="درصد">
                                        </div>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>رند قیمت</label>
                                        <div class="dkiux-inline">
                                            <select id="dki-price-round-mode" class="dkiux-input">
                                                <option value="none" <?php selected($price_round_mode,'none'); ?>>بدون رند</option>
                                                <option value="up" <?php selected($price_round_mode,'up'); ?>>رند به بالا</option>
                                                <option value="down" <?php selected($price_round_mode,'down'); ?>>رند به پایین</option>
                                                <option value="nearest" <?php selected($price_round_mode,'nearest'); ?>>رند نزدیک‌ترین</option>
                                            </select>
                                            <input type="number" id="dki-price-round-zeros" class="dkiux-input" min="0" max="6" step="1" value="<?php echo esc_attr((string)$price_round_zeros); ?>" placeholder="تعداد صفر">
                                        </div>
                                        <div class="dkiux-help">مثال: 3 صفر = رند روی 1000</div>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>کرون‌جاب سمت سرور (دقیق‌تر)</label>
                                        <select id="dki-server-cron-enabled" class="dkiux-input">
                                            <option value="0" <?php selected($server_cron_enabled,'0'); ?>>غیرفعال</option>
                                            <option value="1" <?php selected($server_cron_enabled,'1'); ?>>فعال</option>
                                        </select>
                                        <div class="dkiux-help">در صورت فعال بودن، کران اصلی می‌تواند از طریق cron سرور اجرا شود.</div>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>آدرس کرون سرور</label>
                                        <input type="text" class="dkiux-input" readonly value="<?php echo esc_attr($server_cron_url); ?>">
                                        <div class="dkiux-help">نمونه دستور: <code>*/5 * * * * curl -fsS '<?php echo esc_url($server_cron_url); ?>' >/dev/null</code></div>
                                    </div>

                                    <div class="dkiux-field">
                                        <label>تعداد پردازش در هر نوبت</label>
                                        <input type="number" id="dki-price-update-batch-size" class="dkiux-input" min="1" max="100" value="<?php echo esc_attr((string)$sync_batch_size); ?>">
                                    </div>

                                    <div class="dkiux-inline dkiux-settings-actions">
                                        <button id="dki-save-settings" class="dkiux-btn dkiux-btn-primary">ذخیره تنظیمات</button>
                                        <button id="dki-price-update-now" class="dkiux-btn dkiux-btn-success">اجرای دستی بروزرسانی قیمت</button>
                                    </div>
                                    <div id="dki-settings-status" class="dkiux-status dkiux-settings-wide"></div>

                                    <?php if (!empty($last_report)): ?>
                                            <div class="dkiux-alert dkiux-settings-wide">
                                                آخرین اجرای قیمت: 
                                                <strong><?php echo esc_html((string)($last_report['source'] ?? '-')); ?></strong>
                                                |
                                                <strong><?php echo esc_html((string)($last_report['ran_at'] ?? '-')); ?></strong>
                                                | موفق: <strong><?php echo esc_html((string)($last_report['ok'] ?? 0)); ?></strong>
                                                | ناموفق: <strong><?php echo esc_html((string)($last_report['failed'] ?? 0)); ?></strong>
                                                <?php if (!empty($last_report['remaining'])): ?>
                                                    | باقی‌مانده صف: <strong><?php echo esc_html((string)$last_report['remaining']); ?></strong>
                                                <?php endif; ?>
                                            </div>
                                    <?php endif; ?>

                                    <div class="dkiux-field dkiux-settings-wide">
                                        <div class="dkiux-inline dkiux-inline-between">
                                            <label>لاگ بروزرسانی قیمت (دستی/سیستمی)</label>
                                            <button type="button" id="dki-clear-price-logs" class="dkiux-btn dkiux-btn-ghost">پاک‌سازی لاگ</button>
                                        </div>
                                        <div class="dkiux-table-wrap">
                                            <table class="dkiux-table">
                                                <thead>
                                                    <tr>
                                                        <th>زمان</th>
                                                        <th>نوع</th>
                                                        <th>وضعیت</th>
                                                        <th>توضیح</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dki-price-logs-body">
                                                    <?php if (empty($price_logs)): ?>
                                                        <tr><td colspan="4">لاگی ثبت نشده است.</td></tr>
                                                    <?php else: ?>
                                                        <?php foreach ($price_logs as $log): ?>
                                                            <tr>
                                                                <td><?php echo esc_html((string)($log['time'] ?? '-')); ?></td>
                                                                <td><?php echo esc_html((string)($log['source'] ?? '-')); ?></td>
                                                                <td><?php echo esc_html((string)($log['status'] ?? '-')); ?></td>
                                                                <td><?php echo esc_html((string)($log['message'] ?? '-')); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="dkiux-card dkiux-mt-16">
                <div class="dkiux-card-head"><h3>لاگ عملیات</h3></div>
                <div class="dkiux-card-body">
                    <div id="dki-import-log" class="dki-log"></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_variation_link_field($loop, $variation_data, $variation) {
        if (!$variation || empty($variation->ID)) return;

        $variation_id = (int)$variation->ID;
        $dkp = (int)get_post_meta($variation_id, '_dki_variation_dkp', true);
        $dk_variant_id = (int)get_post_meta($variation_id, '_dki_variation_dk_variant_id', true);
        $dk_title = (string)get_post_meta($variation_id, '_dki_variation_dk_title', true);
        $dk_variant_title = (string)get_post_meta($variation_id, '_dki_variation_dk_variant_title', true);

        echo '<div class="form-row form-row-full dki-variation-connect-wrap">';
        echo '<label><strong>اتصال به دیجی‌کالا (DKP)</strong></label>';
        echo '<div class="dkiux-vc-box">';
        echo '<div class="dkiux-vc-body">';
        echo '<input type="hidden" class="dki-variation-dkp-input" name="dki_variation_dkp[' . esc_attr($variation_id) . ']" value="' . esc_attr((string)$dkp) . '">';
        echo '<input type="hidden" class="dki-variation-dk-variant-id-input" name="dki_variation_dk_variant_id[' . esc_attr($variation_id) . ']" value="' . esc_attr((string)$dk_variant_id) . '">';
        echo '<span class="dkiux-vc-chip dki-variation-dkp-badge">DKP: ' . ($dkp > 0 ? esc_html((string)$dkp) : 'متصل نیست') . '</span>';
        echo '<span class="dkiux-vc-chip dki-variation-dk-variant-badge">Variant: ' . ($dk_variant_id > 0 ? esc_html((string)$dk_variant_id) : 'انتخاب نشده') . '</span>';
        echo '<div class="dkiux-vc-title dki-variation-dkp-title">' . ($dk_title ? esc_html($dk_title) : 'محصولی انتخاب نشده است') . '</div>';
        echo '<div class="dkiux-vc-sub dki-variation-dk-variant-title">' . ($dk_variant_title ? esc_html($dk_variant_title) : 'رنگ/واریانت انتخاب نشده است') . '</div>';
        echo '<div class="dkiux-vc-actions">';
        echo '<button type="button" class="dkiux-btn dkiux-btn-primary dki-open-variation-modal" data-variation-id="' . esc_attr((string)$variation_id) . '">اتصال به دیجی‌کالا</button>';
        echo '<button type="button" class="dkiux-btn dkiux-btn-ghost dki-clear-variation-link" data-variation-id="' . esc_attr((string)$variation_id) . '">حذف اتصال</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public function save_variation_meta($variation_id, $i) {
        if (!isset($_POST['dki_variation_dkp']) || !is_array($_POST['dki_variation_dkp'])) return;

        $all = wp_unslash($_POST['dki_variation_dkp']);
        $value = isset($all[$variation_id]) ? (int)$all[$variation_id] : 0;
        $all_variant = isset($_POST['dki_variation_dk_variant_id']) && is_array($_POST['dki_variation_dk_variant_id'])
            ? wp_unslash($_POST['dki_variation_dk_variant_id'])
            : [];
        $variant_value = isset($all_variant[$variation_id]) ? (int)$all_variant[$variation_id] : 0;

        if ($value > 0) {
            update_post_meta($variation_id, '_dki_variation_dkp', $value);
            if ($variant_value > 0) {
                update_post_meta($variation_id, '_dki_variation_dk_variant_id', $variant_value);
            } else {
                delete_post_meta($variation_id, '_dki_variation_dk_variant_id');
                delete_post_meta($variation_id, '_dki_variation_dk_variant_title');
            }
        } else {
            delete_post_meta($variation_id, '_dki_variation_dkp');
            delete_post_meta($variation_id, '_dki_variation_dk_title');
            delete_post_meta($variation_id, '_dki_variation_dk_url');
            delete_post_meta($variation_id, '_dki_variation_dk_variant_id');
            delete_post_meta($variation_id, '_dki_variation_dk_variant_title');
        }
    }

    public function render_variation_modal() {
        if (!$this->is_product_edit_screen()) return;
        ?>
        <div id="dki-variation-modal" class="dki-modal" style="display:none;">
            <div class="dki-modal-backdrop"></div>
            <div class="dki-modal-dialog dkiux-modal-card">
                <div class="dkiux-modal-head">
                    <h3>اتصال متغیر به دیجیکالا</h3>
                    <button type="button" class="dkiux-modal-close dki-modal-close" aria-label="Close">×</button>
                </div>
                <div class="dkiux-modal-body">
                    <div class="dkiux-stepper">
                        <div class="dkiux-step is-active" data-step="1">1) انتخاب محصول</div>
                        <div class="dkiux-step" data-step="2">2) انتخاب رنگ/واریانت</div>
                    </div>
                    <div id="dki-variation-step1" class="dkiux-step-panel is-active">
                        <div class="dkiux-inline">
                            <input type="text" id="dki-variation-search-query" class="dkiux-input" placeholder="نام محصول را وارد کنید">
                            <button type="button" id="dki-variation-search-btn" class="dkiux-btn dkiux-btn-primary">جستجو</button>
                        </div>
                        <div id="dki-variation-search-status" class="dkiux-status"></div>
                        <div id="dki-variation-search-results" class="dkiux-results"></div>
                    </div>
                    <div id="dki-variation-step2" class="dkiux-step-panel">
                        <div class="dkiux-title-sm">واریانت‌های محصول انتخاب‌شده در دیجیکالا</div>
                        <div id="dki-variation-variant-status" class="dkiux-status"></div>
                        <div id="dki-variation-variants-list"></div>
                    </div>
                </div>
                <div class="dkiux-modal-foot">
                    <button type="button" class="dkiux-btn dkiux-btn-ghost dki-modal-close">بستن</button>
                    <button type="button" id="dki-variation-back-btn" class="dkiux-btn dkiux-btn-ghost" style="display:none;">بازگشت</button>
                    <button type="button" id="dki-variation-next-btn" class="dkiux-btn dkiux-btn-primary" disabled>مرحله بعد: انتخاب رنگ</button>
                    <button type="button" id="dki-variation-apply-btn" class="dkiux-btn dkiux-btn-success" disabled>تایید اتصال</button>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_selected_cat_ids_from_post(): array {
        $cats = $_POST['cats'] ?? [];
        if (!is_array($cats)) $cats = [];
        $cats = array_map('intval', $cats);
        $cats = array_values(array_filter($cats, function($v){ return $v > 0; }));
        return $cats;
    }

    public function ajax_save_settings() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $price_mode = isset($_POST['price_mode']) ? sanitize_key(wp_unslash($_POST['price_mode'])) : 'auto';
        $price_mode = in_array($price_mode, ['auto','irr','toman'], true) ? $price_mode : 'auto';
        update_option('dki_price_mode', $price_mode);

        $nofollow = isset($_POST['nofollow']) ? sanitize_key(wp_unslash($_POST['nofollow'])) : '1';
        $nofollow = ($nofollow === '0') ? '0' : '1';
        update_option('dki_credit_nofollow', $nofollow);

        $credit_enabled = isset($_POST['credit_enabled']) ? sanitize_key(wp_unslash($_POST['credit_enabled'])) : '1';
        $credit_enabled = ($credit_enabled === '0') ? '0' : '1';
        update_option('dki_credit_enabled', $credit_enabled);

        $credit_text_mode = isset($_POST['credit_text_mode']) ? sanitize_key(wp_unslash($_POST['credit_text_mode'])) : 'default';
        $credit_text_mode = in_array($credit_text_mode, ['default','custom'], true) ? $credit_text_mode : 'default';
        update_option('dki_credit_text_mode', $credit_text_mode);

        $credit_text_custom = isset($_POST['credit_text_custom']) ? sanitize_text_field(wp_unslash($_POST['credit_text_custom'])) : '';
        update_option('dki_credit_text_custom', $credit_text_custom);

        $alt_mode = isset($_POST['alt_mode']) ? sanitize_key(wp_unslash($_POST['alt_mode'])) : 'product';
        $alt_mode = in_array($alt_mode, ['product','fixed'], true) ? $alt_mode : 'product';
        update_option('dki_image_alt_mode', $alt_mode);

        $alt_fixed = isset($_POST['alt_fixed']) ? sanitize_text_field(wp_unslash($_POST['alt_fixed'])) : '';
        update_option('dki_image_alt_fixed', $alt_fixed);

        $sync_enabled = isset($_POST['price_update_enabled']) ? sanitize_key(wp_unslash($_POST['price_update_enabled'])) : '0';
        $sync_enabled = ($sync_enabled === '1') ? '1' : '0';
        update_option('dki_price_update_enabled', $sync_enabled);

        $sync_period = isset($_POST['price_update_period']) ? sanitize_key(wp_unslash($_POST['price_update_period'])) : 'daily';
        $sync_period = in_array($sync_period, ['daily', 'weekly'], true) ? $sync_period : 'daily';
        update_option('dki_price_update_period', $sync_period);

        $sync_weekday = isset($_POST['price_update_weekday']) ? (int)wp_unslash($_POST['price_update_weekday']) : 0;
        if ($sync_weekday < 0 || $sync_weekday > 6) $sync_weekday = 0;
        update_option('dki_price_update_weekday', (string)$sync_weekday);

        $sync_time = isset($_POST['price_update_time']) ? sanitize_text_field(wp_unslash($_POST['price_update_time'])) : '09:00';
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $sync_time)) {
            $sync_time = '09:00';
        }
        update_option('dki_price_update_time', $sync_time);

        $batch_size = isset($_POST['price_update_batch_size']) ? (int)wp_unslash($_POST['price_update_batch_size']) : 10;
        if ($batch_size <= 0) $batch_size = 10;
        if ($batch_size > 100) $batch_size = 100;
        update_option('dki_price_update_batch_size', $batch_size);

        $price_adjust_mode = isset($_POST['price_adjust_mode']) ? sanitize_key(wp_unslash($_POST['price_adjust_mode'])) : 'none';
        if (!in_array($price_adjust_mode, ['none', 'increase', 'decrease'], true)) $price_adjust_mode = 'none';
        update_option('dki_price_adjust_mode', $price_adjust_mode);

        $price_adjust_percent = isset($_POST['price_adjust_percent']) ? (float)wp_unslash($_POST['price_adjust_percent']) : 0;
        if ($price_adjust_percent < 0) $price_adjust_percent = 0;
        if ($price_adjust_percent > 500) $price_adjust_percent = 500;
        update_option('dki_price_adjust_percent', (string)$price_adjust_percent);

        $price_round_mode = isset($_POST['price_round_mode']) ? sanitize_key(wp_unslash($_POST['price_round_mode'])) : 'none';
        if (!in_array($price_round_mode, ['none', 'up', 'down', 'nearest'], true)) $price_round_mode = 'none';
        update_option('dki_price_round_mode', $price_round_mode);

        $price_round_zeros = isset($_POST['price_round_zeros']) ? (int)wp_unslash($_POST['price_round_zeros']) : 0;
        if ($price_round_zeros < 0) $price_round_zeros = 0;
        if ($price_round_zeros > 6) $price_round_zeros = 6;
        update_option('dki_price_round_zeros', $price_round_zeros);

        $server_cron_enabled = isset($_POST['server_cron_enabled']) ? sanitize_key(wp_unslash($_POST['server_cron_enabled'])) : '0';
        $server_cron_enabled = ($server_cron_enabled === '1') ? '1' : '0';
        update_option('dki_server_cron_enabled', $server_cron_enabled);

        $this->ensure_cron_event();

        wp_send_json_success(['message'=>'تنظیمات ذخیره شد.']);
    }

    public function ajax_search_products() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $page  = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        if ($query === '') wp_send_json_error(['message'=>'عبارت جستجو خالی است.'], 400);

        $results = DKI_Scraper::search_products_api($query, $page);
        if (is_wp_error($results)) wp_send_json_error(['message'=>$results->get_error_message()], 500);

        wp_send_json_success(['results'=>$results]);
    }

    public function ajax_variation_search_products() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('edit_products')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        if ($query === '') wp_send_json_error(['message'=>'عبارت جستجو خالی است.'], 400);

        $results = DKI_Scraper::search_products_api($query, 1);
        if (is_wp_error($results)) wp_send_json_error(['message'=>$results->get_error_message()], 500);

        wp_send_json_success(['results' => $results]);
    }

    public function ajax_variation_fetch_variants() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('edit_products')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $dkp = isset($_POST['dkp']) ? (int)$_POST['dkp'] : 0;
        if ($dkp <= 0) wp_send_json_error(['message'=>'شناسه محصول دیجیکالا معتبر نیست.'], 400);

        $dk = DKI_Scraper::fetch_product_v2($dkp);
        if (is_wp_error($dk)) wp_send_json_error(['message' => $dk->get_error_message()], 500);

        $variants = isset($dk['variants']) && is_array($dk['variants']) ? $dk['variants'] : [];
        $items = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) continue;
            $variant_id = isset($variant['id']) ? (int)$variant['id'] : 0;
            if ($variant_id <= 0) continue;

            $color_title = (string)($variant['color']['title'] ?? $variant['color']['title_fa'] ?? '');
            $title = (string)($variant['title'] ?? '');
            if ($title === '') {
                $title = $color_title !== '' ? ('رنگ ' . $color_title) : ('Variant #' . $variant_id);
            }

            $price = isset($variant['price']['selling_price']) ? (int)$variant['price']['selling_price'] : 0;
            $st = (string)($variant['status'] ?? '');
            $av = (string)($variant['availability']['status'] ?? '');
            $stock = ($st === 'marketable' || $av === 'in_stock' || $av === 'available') ? 'instock' : 'outofstock';
            if ($price <= 0) $stock = 'outofstock';

            $items[] = [
                'variant_id'  => $variant_id,
                'title'       => $title,
                'color_title' => $color_title,
                'price'       => $price,
                'stock_status'=> $stock,
            ];
        }

        wp_send_json_success([
            'product' => [
                'id'    => $dkp,
                'title' => (string)($dk['title'] ?? ''),
            ],
            'variants' => $items,
        ]);
    }

    public function ajax_variation_link_product() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('edit_products')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $variation_id = isset($_POST['variation_id']) ? (int)$_POST['variation_id'] : 0;
        $dkp = isset($_POST['dkp']) ? (int)$_POST['dkp'] : 0;
        $dk_variant_id = isset($_POST['dk_variant_id']) ? (int)$_POST['dk_variant_id'] : 0;

        if ($variation_id <= 0) wp_send_json_error(['message'=>'شناسه متغیر معتبر نیست.'], 400);
        if (get_post_type($variation_id) !== 'product_variation') wp_send_json_error(['message'=>'آیتم انتخابی متغیر ووکامرس نیست.'], 400);

        if ($dkp <= 0) {
            delete_post_meta($variation_id, '_dki_variation_dkp');
            delete_post_meta($variation_id, '_dki_variation_dk_title');
            delete_post_meta($variation_id, '_dki_variation_dk_url');
            delete_post_meta($variation_id, '_dki_variation_dk_variant_id');
            delete_post_meta($variation_id, '_dki_variation_dk_variant_title');
            wp_send_json_success(['message' => 'اتصال حذف شد.']);
        }

        $dk = DKI_Scraper::fetch_product_v2($dkp);
        if (is_wp_error($dk)) wp_send_json_error(['message' => $dk->get_error_message()], 500);

        update_post_meta($variation_id, '_dki_variation_dkp', $dkp);
        update_post_meta($variation_id, '_dki_variation_dk_title', (string)($dk['title'] ?? ''));
        update_post_meta($variation_id, '_dki_variation_dk_url', esc_url_raw((string)($dk['source_url'] ?? '')));
        if ($dk_variant_id > 0) {
            $variant_title = '';
            if (!empty($dk['variants']) && is_array($dk['variants'])) {
                foreach ($dk['variants'] as $variant) {
                    if (!is_array($variant)) continue;
                    if ((int)($variant['id'] ?? 0) !== $dk_variant_id) continue;
                    $variant_title = (string)($variant['title'] ?? '');
                    if ($variant_title === '') {
                        $color = (string)($variant['color']['title'] ?? $variant['color']['title_fa'] ?? '');
                        $variant_title = $color ? ('رنگ ' . $color) : ('Variant #' . $dk_variant_id);
                    }
                    break;
                }
            }
            update_post_meta($variation_id, '_dki_variation_dk_variant_id', $dk_variant_id);
            update_post_meta($variation_id, '_dki_variation_dk_variant_title', $variant_title);
        } else {
            delete_post_meta($variation_id, '_dki_variation_dk_variant_id');
            delete_post_meta($variation_id, '_dki_variation_dk_variant_title');
        }

        $sync = $this->update_variation_price_from_dk($variation_id, $dkp, $dk, $dk_variant_id > 0 ? $dk_variant_id : null);
        if (is_wp_error($sync)) {
            wp_send_json_success([
                'message' => 'DKP ذخیره شد، اما بروزرسانی قیمت انجام نشد: ' . $sync->get_error_message(),
                'dkp' => $dkp,
                'title' => (string)($dk['title'] ?? ''),
                'variant_id' => $dk_variant_id,
            ]);
        }

        wp_send_json_success([
            'message' => 'اتصال انجام شد و قیمت بروزرسانی شد.',
            'dkp' => $dkp,
            'title' => (string)($dk['title'] ?? ''),
            'variant_id' => $dk_variant_id,
            'variant_title' => (string)get_post_meta($variation_id, '_dki_variation_dk_variant_title', true),
            'price' => $sync['price'] ?? null,
        ]);
    }

    public function ajax_import_product() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if (!$url) wp_send_json_error(['message'=>'آدرس محصول ارسال نشده است.'], 400);

        $id = DKI_Scraper::extract_product_id_from_url($url);
        if (!$id) wp_send_json_error(['message'=>'شناسه محصول از لینک استخراج نشد.'], 400);

        $cats = $this->get_selected_cat_ids_from_post();

        $dk = DKI_Scraper::fetch_product_v2($id);
        if (is_wp_error($dk)) wp_send_json_error(['message'=>$dk->get_error_message()], 500);

        $product_id = DKI_Importer::upsert_product_from_dk($dk, $cats);
        if (is_wp_error($product_id)) wp_send_json_error(['message'=>$product_id->get_error_message()], 500);

        wp_send_json_success([
            'message'    => 'محصول ایجاد/به‌روزرسانی شد.',
            'product_id' => (int)$product_id,
            'edit_link'  => get_edit_post_link($product_id, 'raw'),
        ]);
    }

    public function ajax_import_by_id() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if ($id <= 0) wp_send_json_error(['message'=>'شناسه محصول معتبر نیست.'], 400);

        $cats = $this->get_selected_cat_ids_from_post();

        $dk = DKI_Scraper::fetch_product_v2($id);
        if (is_wp_error($dk)) wp_send_json_error(['message'=>$dk->get_error_message()], 500);

        $product_id = DKI_Importer::upsert_product_from_dk($dk, $cats);
        if (is_wp_error($product_id)) wp_send_json_error(['message'=>$product_id->get_error_message()], 500);

        wp_send_json_success([
            'message'    => 'محصول ایجاد/به‌روزرسانی شد.',
            'product_id' => (int)$product_id,
            'edit_link'  => get_edit_post_link($product_id, 'raw'),
        ]);
    }

    public function ajax_category_fetch_page() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $url  = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        if (!$url) wp_send_json_error(['message'=>'لینک صفحه خالی است.'], 400);

        $slugs = DKI_Scraper::parse_category_brand_url($url);
        if (empty($slugs['category_slug']) || empty($slugs['brand_slug'])) {
            wp_send_json_error(['message'=>'نتوانستم category/brand را از لینک استخراج کنم.'], 400);
        }

        $data = DKI_Scraper::fetch_category_brand_search_page($slugs['category_slug'], $slugs['brand_slug'], $page);
        if (is_wp_error($data)) wp_send_json_error(['message'=>$data->get_error_message()], 500);

        wp_send_json_success($data);
    }

    public function ajax_category_import_ids() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) wp_send_json_error(['message'=>'لیست شناسه‌ها خالی است.'], 400);
        $ids = array_values(array_filter(array_map('intval', $ids)));

        $cats = $this->get_selected_cat_ids_from_post();

        $result = [];
        foreach ($ids as $id) {
            $dk = DKI_Scraper::fetch_product_v2($id);
            if (is_wp_error($dk)) {
                $result[] = ['id'=>$id,'ok'=>false,'message'=>$dk->get_error_message()];
                continue;
            }
            $pid = DKI_Importer::upsert_product_from_dk($dk, $cats);
            if (is_wp_error($pid)) {
                $result[] = ['id'=>$id,'ok'=>false,'message'=>$pid->get_error_message()];
                continue;
            }
            $result[] = ['id'=>$id,'ok'=>true,'product_id'=>(int)$pid,'edit_link'=>get_edit_post_link($pid,'raw')];
        }

        wp_send_json_success(['results'=>$result]);
    }

    public function cron_schedules($schedules) {
        if (!isset($schedules['dki_5min'])) {
            $schedules['dki_5min'] = [
                'interval' => 300,
                'display'  => 'Every 5 Minutes (DKI)',
            ];
        }
        return $schedules;
    }

    public function ensure_cron_event() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'dki_5min', self::CRON_HOOK);
        }
    }

    private function get_server_cron_token(): string {
        $token = (string)get_option('dki_server_cron_token', '');
        if ($token === '') {
            $token = wp_generate_password(32, false, false);
            update_option('dki_server_cron_token', $token, false);
        }
        return $token;
    }

    public function handle_server_cron_request() {
        if (!isset($_GET['dki_cron'])) return;

        $enabled = (string)get_option('dki_server_cron_enabled', '0') === '1';
        if (!$enabled) return;

        $token = isset($_GET['dki_token']) ? sanitize_text_field(wp_unslash($_GET['dki_token'])) : '';
        if ($token === '' || !hash_equals($this->get_server_cron_token(), $token)) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'invalid token';
            exit;
        }

        $this->cron_tick('server');
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ok';
        exit;
    }

    private function get_price_update_logs(): array {
        $logs = get_option('dki_price_update_logs', []);
        return is_array($logs) ? $logs : [];
    }

    private function add_price_update_log(string $source, string $status, string $message): void {
        $logs = $this->get_price_update_logs();
        $logs[] = [
            'time' => wp_date('Y-m-d H:i:s'),
            'source' => $source,
            'status' => $status,
            'message' => $message,
        ];
        if (count($logs) > 300) {
            $logs = array_slice($logs, -300);
        }
        update_option('dki_price_update_logs', array_values($logs), false);
    }

    public function ajax_clear_price_logs() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        update_option('dki_price_update_logs', [], false);
        wp_send_json_success(['message' => 'لاگ‌ها پاک شدند.']);
    }

    private function is_price_update_due_now() {
        if (get_option('dki_price_update_enabled', '0') !== '1') return [false, ''];

        $period = get_option('dki_price_update_period', 'daily');
        $weekday = (int)get_option('dki_price_update_weekday', '0');
        $time = (string)get_option('dki_price_update_time', '09:00');
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            $time = '09:00';
            $m = [null, '09', '00'];
        }

        $now = current_datetime();
        $now_w = (int)$now->format('w');
        $now_minutes = ((int)$now->format('H') * 60) + (int)$now->format('i');
        $target_minutes = ((int)$m[1] * 60) + (int)$m[2];

        $is_window = ($now_minutes >= $target_minutes && $now_minutes <= ($target_minutes + 4));
        if (!$is_window) return [false, ''];

        if ($period === 'weekly' && $now_w !== $weekday) {
            return [false, ''];
        }

        $slot = $period === 'weekly'
            ? $now->format('oW') . '-w' . $weekday . '-' . $time
            : $now->format('Ymd') . '-' . $time;

        return [true, $slot];
    }

    private function build_price_update_queue(): array {
        $queue = [];

        $variation_ids = get_posts([
            'post_type'      => 'product_variation',
            'post_status'    => ['publish', 'private'],
            'numberposts'    => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_dki_variation_dkp',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        foreach ($variation_ids as $variation_id) {
            $dkp = (int)get_post_meta((int)$variation_id, '_dki_variation_dkp', true);
            if ($dkp <= 0) continue;
            $queue[] = [
                'type' => 'variation',
                'id'   => (int)$variation_id,
                'dkp'  => $dkp,
                'dk_variant_id' => (int)get_post_meta((int)$variation_id, '_dki_variation_dk_variant_id', true),
            ];
        }

        $simple_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'private'],
            'numberposts'    => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_dki_product_id',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
            ],
            'tax_query'      => [
                [
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => ['simple'],
                ],
            ],
        ]);

        foreach ($simple_ids as $product_id) {
            $dkp = (int)get_post_meta((int)$product_id, '_dki_product_id', true);
            if ($dkp <= 0) continue;
            $queue[] = [
                'type' => 'simple',
                'id'   => (int)$product_id,
                'dkp'  => $dkp,
            ];
        }

        return $queue;
    }

    private function update_simple_price_from_dk(int $product_id, int $dkp, ?array $dk = null) {
        if ($product_id <= 0 || $dkp <= 0) {
            return new WP_Error('dki_invalid_input', 'پارامتر بروزرسانی معتبر نیست.');
        }

        if ($dk === null) {
            $dk = DKI_Scraper::fetch_product_v2($dkp);
            if (is_wp_error($dk)) return $dk;
        }

        $price = DKI_Importer::convert_price(isset($dk['price']) ? (int)$dk['price'] : 0);
        if ($price > 0) {
            update_post_meta($product_id, '_regular_price', (string)$price);
            update_post_meta($product_id, '_price', (string)$price);
        } else {
            update_post_meta($product_id, '_regular_price', '0');
            update_post_meta($product_id, '_price', '0');
        }

        $stock = (string)($dk['stock_status'] ?? 'outofstock');
        if ($price <= 0) $stock = 'outofstock';
        wc_update_product_stock_status($product_id, $stock === 'instock' ? 'instock' : 'outofstock');
        wc_delete_product_transients($product_id);

        return [
            'product_id' => $product_id,
            'price'      => $price,
        ];
    }

    private function resolve_variation_stock(array $dk, string $woo_color_slug = '', ?int $dk_variant_id = null): string {
        $variants = isset($dk['variants']) && is_array($dk['variants']) ? $dk['variants'] : [];
        if (!empty($variants) && $dk_variant_id && $dk_variant_id > 0) {
            foreach ($variants as $variant) {
                if (!is_array($variant)) continue;
                if ((int)($variant['id'] ?? 0) !== $dk_variant_id) continue;
                $st = (string)($variant['status'] ?? '');
                $av = (string)($variant['availability']['status'] ?? '');
                return ($st === 'marketable' || $av === 'in_stock' || $av === 'available') ? 'instock' : 'outofstock';
            }
            return 'outofstock';
        }

        if ($woo_color_slug !== '' && !empty($variants)) {
            foreach ($variants as $variant) {
                if (!is_array($variant)) continue;
                $color = $variant['color'] ?? null;
                if (!is_array($color)) continue;

                $title = (string)($color['title'] ?? $color['title_fa'] ?? '');
                if ($title === '') continue;
                if (sanitize_title($title) !== $woo_color_slug) continue;

                $st = (string)($variant['status'] ?? '');
                $av = (string)($variant['availability']['status'] ?? '');
                if ($st === 'marketable' || $av === 'in_stock' || $av === 'available') {
                    return 'instock';
                }
            }

            return 'outofstock';
        }

        return ((string)($dk['stock_status'] ?? 'outofstock') === 'instock') ? 'instock' : 'outofstock';
    }

    private function update_variation_price_from_dk(int $variation_id, int $dkp, ?array $dk = null, ?int $dk_variant_id = null) {
        if ($variation_id <= 0 || $dkp <= 0) {
            return new WP_Error('dki_invalid_input', 'پارامتر بروزرسانی معتبر نیست.');
        }

        if ($dk === null) {
            $dk = DKI_Scraper::fetch_product_v2($dkp);
            if (is_wp_error($dk)) return $dk;
        }

        $woo_color_slug = (string)get_post_meta($variation_id, 'attribute_pa_color', true);
        $dk_variant_id = $dk_variant_id !== null ? (int)$dk_variant_id : (int)get_post_meta($variation_id, '_dki_variation_dk_variant_id', true);
        $price = 0;
        if ($dk_variant_id > 0 && !empty($dk['variants']) && is_array($dk['variants'])) {
            foreach ($dk['variants'] as $variant) {
                if (!is_array($variant)) continue;
                if ((int)($variant['id'] ?? 0) !== $dk_variant_id) continue;
                $variant_price = isset($variant['price']['selling_price']) ? (int)$variant['price']['selling_price'] : 0;
                $price = DKI_Importer::convert_price($variant_price);
                break;
            }
        }
        if ($price <= 0) {
            $price = DKI_Importer::resolve_variation_price_from_dk($dk, $woo_color_slug);
        }
        if ($price > 0) {
            update_post_meta($variation_id, '_regular_price', (string)$price);
            update_post_meta($variation_id, '_price', (string)$price);
        } else {
            update_post_meta($variation_id, '_regular_price', '0');
            update_post_meta($variation_id, '_price', '0');
        }

        $stock = $this->resolve_variation_stock($dk, $woo_color_slug, $dk_variant_id);
        if ($price <= 0) $stock = 'outofstock';
        wc_update_product_stock_status($variation_id, $stock);

        $parent_id = wp_get_post_parent_id($variation_id);
        if ($parent_id > 0) {
            if (class_exists('WC_Product_Variable')) {
                WC_Product_Variable::sync($parent_id);
            }
            wc_delete_product_transients($parent_id);
        }

        return [
            'variation_id' => $variation_id,
            'price'        => $price,
            'stock'        => $stock,
            'variant_id'   => $dk_variant_id,
        ];
    }

    private function process_queue_batch(int $batch_size, string $source = 'system'): array {
        $queue = get_option('dki_price_update_queue', []);
        $queue = is_array($queue) ? array_values($queue) : [];

        if ($batch_size <= 0) $batch_size = 10;

        $ok = 0;
        $failed = 0;

        for ($i = 0; $i < $batch_size; $i++) {
            if (empty($queue)) break;

            $item = array_shift($queue);
            if (!is_array($item)) {
                $failed++;
                continue;
            }

            $type = (string)($item['type'] ?? '');
            $id = (int)($item['id'] ?? 0);
            $dkp = (int)($item['dkp'] ?? 0);
            $dk_variant_id = (int)($item['dk_variant_id'] ?? 0);

            if ($type === 'variation') {
                $result = $this->update_variation_price_from_dk($id, $dkp, null, $dk_variant_id > 0 ? $dk_variant_id : null);
            } elseif ($type === 'simple') {
                $result = $this->update_simple_price_from_dk($id, $dkp);
            } else {
                $result = new WP_Error('dki_unknown_queue_item', 'نوع آیتم صف نامعتبر است.');
            }

            if (is_wp_error($result)) {
                $failed++;
            } else {
                $ok++;
            }
        }

        update_option('dki_price_update_queue', $queue, false);

        $ran_at = wp_date('Y-m-d H:i:s');
        update_option('dki_price_update_last_report', [
            'source'    => $source,
            'ran_at'    => $ran_at,
            'ok'        => $ok,
            'failed'    => $failed,
            'remaining' => count($queue),
        ], false);

        $this->add_price_update_log(
            $source,
            ($failed > 0 ? 'partial' : 'ok'),
            sprintf('اجرا شد. موفق: %d | ناموفق: %d | باقی‌مانده: %d', $ok, $failed, count($queue))
        );

        return [
            'ok' => $ok,
            'failed' => $failed,
            'remaining' => count($queue),
            'ran_at' => $ran_at,
        ];
    }

    public function cron_tick(string $source = 'system') {
        $queue = get_option('dki_price_update_queue', []);
        $queue = is_array($queue) ? array_values($queue) : [];

        if (empty($queue)) {
            list($due, $slot) = $this->is_price_update_due_now();
            if ($due) {
                $last_slot = (string)get_option('dki_price_update_last_slot', '');
                if ($last_slot !== $slot) {
                    $queue = $this->build_price_update_queue();
                    update_option('dki_price_update_queue', $queue, false);
                    update_option('dki_price_update_last_slot', $slot, false);
                    $this->add_price_update_log($source, 'queue', 'صف بروزرسانی قیمت ساخته شد. تعداد: ' . count($queue));
                }
            }
        }

        if (empty($queue)) {
            return;
        }

        $batch_size = (int)get_option('dki_price_update_batch_size', 10);
        $this->process_queue_batch($batch_size, $source);
    }

    public function ajax_price_update_now() {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_NAME);
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>'دسترسی ندارید.'], 403);

        $queue = get_option('dki_price_update_queue', []);
        $queue = is_array($queue) ? array_values($queue) : [];
        if (empty($queue)) {
            $queue = $this->build_price_update_queue();
            update_option('dki_price_update_queue', $queue, false);
            $this->add_price_update_log('manual', 'queue', 'صف اجرای دستی ساخته شد. تعداد: ' . count($queue));
        }

        $batch_size = (int)get_option('dki_price_update_batch_size', 10);
        $result = $this->process_queue_batch($batch_size, 'manual');

        wp_send_json_success([
            'message' => 'بروزرسانی دستی انجام شد.',
            'result'  => $result,
        ]);
    }
}
