<?php
if (!defined('ABSPATH')) exit;

class DKI_Admin {

    const NONCE_ACTION = 'dki_admin_nonce_action';
    const NONCE_NAME   = 'nonce';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        add_action('wp_ajax_dki_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_dki_import_product', [$this, 'ajax_import_product']);
        add_action('wp_ajax_dki_import_by_id', [$this, 'ajax_import_by_id']);

        add_action('wp_ajax_dki_category_fetch_page', [$this, 'ajax_category_fetch_page']);
        add_action('wp_ajax_dki_category_import_ids', [$this, 'ajax_category_import_ids']);

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
        ]);

        wp_enqueue_style(
            'dki-admin-css',
            DKI_PLUGIN_URL . 'assets/css/admin.css',
            [],
            DKI_VERSION
        );
    }

    private function render_wc_category_checklist($selected = []) {
        if (!taxonomy_exists('product_cat')) return;

        $selected = array_map('intval', (array)$selected);
        echo '<div class="dki-wc-cat-tree">';
        echo '<div class="dki-wc-cat-tree-head">دسته‌بندی مقصد در ووکامرس</div>';
        echo '<div class="dki-wc-cat-tree-body">';
        // wp_terms_checklist requires Walker_Category_Checklist in admin
        wp_terms_checklist(0, [
            'taxonomy'     => 'product_cat',
            'checked_ontop'=> false,
            'selected_cats'=> $selected,
            'walker'       => new Walker_Category_Checklist(),
        ]);
        echo '</div>';
        echo '<p class="description">می‌توانید بیش از یک دسته‌بندی انتخاب کنید.</p>';
        echo '</div>';
    }

    public function render_page() {
        $price_mode = get_option('dki_price_mode', 'auto');
        $nofollow = get_option('dki_credit_nofollow', '1');
        ?>
        <div class="wrap dki-metronic-wrap">
            <h1 class="dki-page-title">درج محصول از دیجی‌کالا</h1>

            <div class="dki-tabs">
                <button class="dki-tab active" data-tab="tab-search">جستجو</button>
                <button class="dki-tab" data-tab="tab-id">درج با شناسه</button>
                <button class="dki-tab" data-tab="tab-category">درج از دسته‌بندی</button>
                <button class="dki-tab" data-tab="tab-settings">تنظیمات</button>
            </div>

            <div class="dki-tab-panel active" id="tab-search">
                <div class="dki-card">
                    <div class="dki-card-header"><h2 class="dki-card-title">جستجوی محصول</h2></div>
                    <div class="dki-card-body">
                        <div class="dki-form-row">
                            <input type="text" id="dki-search-query" class="dki-input" placeholder="مثلاً: آیفون 15 پرو مکس">
                            <button id="dki-search-btn" class="button button-primary dki-btn-primary">جستجو در دیجی‌کالا</button>
                        </div>

                        <div class="dki-split">
                            <div class="dki-split-main">
                                <div id="dki-search-status" class="dki-status"></div>
                                <div id="dki-search-results" class="dki-table-wrapper"></div>
                            </div>
                            <div class="dki-split-side">
                                <?php $this->render_wc_category_checklist([]); ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="dki-tab-panel" id="tab-id">
                <div class="dki-card">
                    <div class="dki-card-header"><h2 class="dki-card-title">درج مستقیم با شناسه محصول</h2></div>
                    <div class="dki-card-body">
                        <div class="dki-form-row">
                            <input type="number" id="dki-product-id" class="dki-input" placeholder="مثلاً: 19404627">
                            <button id="dki-import-by-id-btn" class="button button-primary dki-btn-primary">درج / به‌روزرسانی</button>
                        </div>

                        <div class="dki-split">
                            <div class="dki-split-main">
                                <div id="dki-id-status" class="dki-status"></div>
                            </div>
                            <div class="dki-split-side">
                                <?php $this->render_wc_category_checklist([]); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dki-tab-panel" id="tab-category">
                <div class="dki-card">
                    <div class="dki-card-header"><h2 class="dki-card-title">درج از دسته‌بندی (برند/دسته دیجی‌کالا)</h2></div>
                    <div class="dki-card-body">
                        <div class="dki-form-row">
                            <input type="text" id="dki-category-url" class="dki-input" placeholder="لینک صفحه (مثال): https://www.digikala.com/search/category-air-conditioner-2/uneva/">
                            <button id="dki-category-fetch-btn" class="button button-primary dki-btn-primary">خواندن محصولات</button>
                            <button id="dki-category-import-all-btn" class="button dki-btn-secondary" disabled>درج همه</button>
                        </div>

                        <div class="dki-split">
                            <div class="dki-split-main">
                                <div id="dki-category-status" class="dki-status"></div>
                                <div id="dki-category-results" class="dki-table-wrapper"></div>
                            </div>
                            <div class="dki-split-side">
                                <?php $this->render_wc_category_checklist([]); ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="dki-tab-panel" id="tab-settings">
                <div class="dki-card">
                    <div class="dki-card-header"><h2 class="dki-card-title">تنظیمات</h2></div>
                    <div class="dki-card-body">
                        <div class="dki-form-grid">
                            <div class="dki-form-field">
                                <label>واحد قیمت</label>
                                <select id="dki-price-mode" class="dki-input">
                                    <option value="auto" <?php selected($price_mode,'auto'); ?>>خودکار (بر اساس واحد ووکامرس)</option>
                                    <option value="irr" <?php selected($price_mode,'irr'); ?>>ریال (IRR)</option>
                                    <option value="toman" <?php selected($price_mode,'toman'); ?>>تومان (تقسیم بر 10)</option>
                                </select>
                                <p class="description">اگر سایت شما تومان است، گزینه «تومان» یا «خودکار» را انتخاب کنید.</p>
                            </div>

                            <div class="dki-form-field">
                                <label>لینک اعتباردهی</label>
                                <select id="dki-credit-nofollow" class="dki-input">
                                    <option value="1" <?php selected($nofollow,'1'); ?>>nofollow (پیشنهادی)</option>
                                    <option value="0" <?php selected($nofollow,'0'); ?>>follow</option>
                                </select>
                                <p class="description">لینک «مشاهده در دیجی‌کالا» انتهای توضیحات محصول اضافه می‌شود.</p>
                            </div>
                        </div>

                        <button id="dki-save-settings" class="button button-primary dki-btn-primary">ذخیره تنظیمات</button>
                        <div id="dki-settings-status" class="dki-status"></div>
                    </div>
                </div>
            </div>

            <div class="dki-card dki-mt-20">
                <div class="dki-card-header"><h2 class="dki-card-title">لاگ عملیات</h2></div>
                <div class="dki-card-body"><div id="dki-import-log" class="dki-log"></div></div>
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
}
