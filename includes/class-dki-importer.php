<?php
if (!defined('ABSPATH')) exit;

class DKI_Importer {

    public static function import_product(array $dk_product): int|WP_Error {

        if (!class_exists('WC_Product')) {
            return new WP_Error('no_wc', 'ووکامرس فعال نیست.');
        }

        $dk_id = isset($dk_product['id']) ? (int)$dk_product['id'] : 0;
        if ($dk_id <= 0) {
            return new WP_Error('bad_dk_id', 'شناسه دیجی‌کالا نامعتبر است.');
        }

        $title = trim((string)($dk_product['title_fa'] ?? ''));
        if ($title === '') {
            $title = trim((string)($dk_product['seo']['title'] ?? ''));
        }
        if ($title === '') {
            $title = trim((string)($dk_product['title_en'] ?? ''));
        }
        if ($title === '') {
            return new WP_Error('no_title', 'عنوان محصول یافت نشد.');
        }

        $settings = DKI_Options::get_all();
        $post_status = ($settings['post_status'] ?? 'publish') === 'draft' ? 'draft' : 'publish';
        $update_existing = ($settings['update_existing'] ?? 'yes') === 'yes';

        $existing_id = $update_existing ? self::find_existing_product_id($dk_id) : 0;

        $type = self::detect_type($dk_product);
        $product_id = 0;

        if ($existing_id) {
            $product_id = $existing_id;
            $wc_product = wc_get_product($existing_id);
            if (!$wc_product) $existing_id = 0; // fallback create new
        }

        if (!$existing_id) {
            if ($type === 'variable') {
                $wc_product = new WC_Product_Variable();
            } else {
                $wc_product = new WC_Product_Simple();
            }
            $wc_product->set_status($post_status);
        } else {
            // اگر نوع تغییر کرده باشد، محصول را به نوع مناسب تبدیل کنیم
            $wc_product = wc_get_product($existing_id);
            if (!$wc_product) {
                return new WP_Error('bad_existing', 'محصول موجود قابل بارگذاری نیست.');
            }
            $current_type = $wc_product->get_type();
            if ($type === 'variable' && $current_type !== 'variable') {
                wp_set_object_terms($existing_id, 'variable', 'product_type');
                $wc_product = new WC_Product_Variable($existing_id);
            } elseif ($type === 'simple' && $current_type !== 'simple') {
                wp_set_object_terms($existing_id, 'simple', 'product_type');
                $wc_product = new WC_Product_Simple($existing_id);
            }
            $wc_product->set_status($post_status);
        }

        // عنوان و محتوا
        $wc_product->set_name($title);

        $description = self::build_description($dk_product);
        $short_desc  = self::build_short_description($dk_product);

        $wc_product->set_description($description);
        $wc_product->set_short_description($short_desc);

        // متاها
        $wc_product->update_meta_data('_dki_product_id', $dk_id);
        $source_url = '';
        if (!empty($dk_product['url']['uri'])) {
            $source_url = 'https://www.digikala.com' . $dk_product['url']['uri'];
        }
        if ($source_url) {
            $wc_product->update_meta_data('_dki_source_url', esc_url_raw($source_url));
        }
        if (!empty($dk_product['brand']['title_fa'])) {
            $wc_product->update_meta_data('_dki_brand', sanitize_text_field($dk_product['brand']['title_fa']));
        }
        if (!empty($dk_product['category']['title_fa'])) {
            $wc_product->update_meta_data('_dki_category', sanitize_text_field($dk_product['category']['title_fa']));
        }

        // قیمت/موجودی
        if ($type === 'simple') {
            $price_info = self::extract_best_price($dk_product);
            $regular_rial = (int)($price_info['regular'] ?? 0);
            $sale_rial    = (int)($price_info['sale'] ?? 0);

            $regular = DKI_Options::price_to_store_unit($regular_rial);
            $sale    = DKI_Options::price_to_store_unit($sale_rial);

            if ($regular > 0) {
                $wc_product->set_regular_price((string)$regular);
                if ($sale > 0 && $sale < $regular) {
                    $wc_product->set_sale_price((string)$sale);
                } else {
                    $wc_product->set_sale_price('');
                }
            } else {
                $wc_product->set_regular_price('');
                $wc_product->set_sale_price('');
            }

            $stock_status = self::extract_stock_status($dk_product);
            $wc_product->set_stock_status($stock_status);
        } else {
            // variable: prices on variations
            $wc_product->set_regular_price('');
            $wc_product->set_sale_price('');
        }

        // ذخیره اولیه برای گرفتن ID
        $product_id = $wc_product->save();

        if (!$product_id) {
            return new WP_Error('save_failed', 'ذخیره محصول ناموفق بود.');
        }

        // تصاویر
        self::sync_images($product_id, $dk_product);

        // ویژگی‌ها
        self::sync_attributes($product_id, $dk_product, $type);

        // واریانت‌ها
        if ($type === 'variable') {
            $res = self::sync_variations($product_id, $dk_product);
            if (is_wp_error($res)) {
                return $res;
            }
        } else {
            // پاکسازی وارییشن‌های احتمالی قدیمی
            self::delete_all_variations($product_id);
        }

        // ذخیره نهایی
        $wc_product = wc_get_product($product_id);
        if ($wc_product) {
            $wc_product->save();
        }

        return $product_id;
    }

    private static function find_existing_product_id(int $dk_id): int {
        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => ['publish','draft','pending','private'],
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_dki_product_id',
                    'value' => (string)$dk_id,
                    'compare' => '='
                ]
            ],
        ]);
        if (!empty($q->posts[0])) return (int)$q->posts[0];
        return 0;
    }

    private static function detect_type(array $p): string {
        $variants = $p['variants'] ?? [];
        if (!is_array($variants) || count($variants) < 2) {
            return 'simple';
        }
        // اگر حداقل ۲ واریانت و حداقل یک ویژگی متفاوت داشتیم => variable
        $first = $variants[0] ?? [];
        $attrs = self::variant_attributes_map($first);
        if (empty($attrs)) return 'simple';

        // بررسی اختلاف
        foreach (array_slice($variants, 1, 5) as $v) {
            $m = self::variant_attributes_map($v);
            if ($m != $attrs) return 'variable';
        }
        // اگر تعداد زیاد است ولی یکسان، همچنان simple
        return 'simple';
    }

    private static function extract_stock_status(array $p): string {
        $status = (string)($p['status'] ?? '');
        if ($status === 'in_stock') return 'instock';
        if ($status === 'out_of_stock') return 'outofstock';

        // fallback: اگر قیمت از واریانت‌ها هست
        $variants = $p['variants'] ?? [];
        if (is_array($variants)) {
            foreach ($variants as $v) {
                $st = (string)($v['status'] ?? '');
                if ($st === 'in_stock') return 'instock';
            }
        }
        return 'outofstock';
    }

    private static function extract_best_price(array $p): array {
        // برگشتی: ['regular'=>IRR, 'sale'=>IRR]
        $regular = 0; $sale = 0;

        // اول default_variant
        $dv = $p['default_variant'] ?? null;
        if (is_array($dv)) {
            $price = $dv['price'] ?? [];
            if (is_array($price)) {
                $sale = (int)($price['selling_price'] ?? 0);
                $regular = (int)($price['rrp_price'] ?? 0);
                if (!$regular) $regular = (int)($price['selling_price'] ?? 0);
            }
        }

        // اگر خالی بود، از واریانت‌ها مینیمم را بگیر
        if ($sale <= 0) {
            $variants = $p['variants'] ?? [];
            if (is_array($variants)) {
                $min_sale = 0; $min_reg = 0;
                foreach ($variants as $v) {
                    $price = $v['price'] ?? [];
                    if (!is_array($price)) continue;
                    $sv = (int)($price['selling_price'] ?? 0);
                    $rg = (int)($price['rrp_price'] ?? 0);
                    if ($sv > 0 && ($min_sale === 0 || $sv < $min_sale)) $min_sale = $sv;
                    if ($rg > 0 && ($min_reg === 0 || $rg < $min_reg)) $min_reg = $rg;
                }
                $sale = $min_sale;
                $regular = $min_reg ?: $min_sale;
            }
        }

        return [
            'regular' => max(0, $regular),
            'sale'    => max(0, $sale),
        ];
    }

    private static function build_short_description(array $p): string {
        $sr = (string)($p['expert_reviews']['short_review'] ?? '');
        $sr = trim(wp_strip_all_tags($sr));
        if ($sr) return wp_trim_words($sr, 45, '...');
        $seo = (string)($p['seo']['description'] ?? '');
        $seo = trim(wp_strip_all_tags($seo));
        if ($seo) return wp_trim_words($seo, 45, '...');
        return '';
    }

    private static function build_description(array $p): string {
        $parts = [];

        // معرفی کوتاه / expert description
        $desc = (string)($p['expert_reviews']['description'] ?? '');
        if (trim($desc) !== '') {
            $parts[] = '<h2>بررسی تخصصی</h2>' . wp_kses_post($desc);
        }

        // بخش‌های بررسی تخصصی
        $sections = $p['expert_reviews']['review_sections'] ?? [];
        if (is_array($sections) && !empty($sections)) {
            foreach ($sections as $sec) {
                $t = trim((string)($sec['title'] ?? ''));
                $c = (string)($sec['content'] ?? '');
                if ($t && trim(wp_strip_all_tags($c)) !== '') {
                    $parts[] = '<h3>' . esc_html($t) . '</h3>' . wp_kses_post($c);
                }
            }
        }

        // مشخصات (table)
        $specs = $p['specifications'] ?? [];
        if (is_array($specs) && !empty($specs)) {
            $parts[] = '<h2>مشخصات</h2>' . self::specs_to_html_table($specs);
        }

        // ویژگی‌های برجسته (review.attributes)
        $high = $p['review']['attributes'] ?? [];
        if (is_array($high) && !empty($high)) {
            $parts[] = '<h2>ویژگی‌های برجسته</h2>' . self::review_attrs_to_ul($high);
        }

        // اطلاعات SEO
        $seo_desc = (string)($p['seo']['description'] ?? '');
        if (trim($seo_desc) !== '') {
            $parts[] = '<h2>توضیحات</h2><p>' . esc_html($seo_desc) . '</p>';
        }

        $out = implode("\n", $parts);
        if ($out === '') {
            $out = '';
        }

        // لینک منبع
        if (!empty($p['url']['uri'])) {
            $out .= "\n<hr>\n<p><a href=\"" . esc_url('https://www.digikala.com' . $p['url']['uri']) . "\" target=\"_blank\" rel=\"noopener\">مشاهده در دیجی‌کالا</a></p>";
        }

        return $out;
    }

    private static function specs_to_html_table(array $specs): string {
        $html = '<div class="dki-specs"><table class="shop_attributes"><tbody>';
        foreach ($specs as $group) {
            $group_title = trim((string)($group['title'] ?? ''));
            $attrs = $group['attributes'] ?? [];
            if (!is_array($attrs) || empty($attrs)) continue;

            if ($group_title) {
                $html .= '<tr><th colspan="2" style="text-align:right;">' . esc_html($group_title) . '</th></tr>';
            }

            foreach ($attrs as $a) {
                $t = trim((string)($a['title'] ?? ''));
                $vals = $a['values'] ?? [];
                if (!is_array($vals)) $vals = [];
                $v = implode('، ', array_map('sanitize_text_field', $vals));
                if ($t === '' || $v === '') continue;

                $html .= '<tr><th>' . esc_html($t) . '</th><td>' . esc_html($v) . '</td></tr>';
            }
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    private static function review_attrs_to_ul(array $attrs): string {
        $html = '<ul class="dki-highlights">';
        foreach ($attrs as $a) {
            $t = trim((string)($a['title'] ?? ''));
            $vals = $a['values'] ?? [];
            if (!is_array($vals)) $vals = [];
            $v = implode('، ', array_map('sanitize_text_field', $vals));
            if ($t === '' || $v === '') continue;
            $html .= '<li><strong>' . esc_html($t) . ':</strong> ' . esc_html($v) . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    private static function sync_images(int $product_id, array $p): void {
        $limit = (int) DKI_Options::get('image_limit', 12);
        $limit = max(1, min(30, $limit));

        $images = [];

        // main image
        if (!empty($p['images']['main']['url'][0])) {
            $images[] = $p['images']['main']['url'][0];
        } elseif (!empty($p['variants_images']['main']['url'][0])) {
            $images[] = $p['variants_images']['main']['url'][0];
        }

        // gallery list
        $list = $p['images']['list'] ?? [];
        if (is_array($list)) {
            foreach ($list as $img) {
                if (!empty($img['url'][0])) $images[] = $img['url'][0];
                if (count($images) >= $limit) break;
            }
        }

        // unique
        $images = array_values(array_unique(array_filter($images)));

        if (empty($images)) return;

        $featured_id = 0;
        $gallery_ids = [];

        foreach ($images as $idx => $url) {
            $att_id = self::sideload_image_to_media($url, $product_id, 'Digikala Image');
            if (is_wp_error($att_id)) continue;

            if ($idx === 0) $featured_id = (int)$att_id;
            else $gallery_ids[] = (int)$att_id;
        }

        if ($featured_id) {
            set_post_thumbnail($product_id, $featured_id);
        }

        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    private static function sideload_image_to_media(string $url, int $post_id, string $desc = ''): int|WP_Error {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $url = esc_url_raw($url);
        if (!$url) return new WP_Error('bad_image_url', 'URL تصویر نامعتبر است.');

        // فایل نام بهتر
        $path = parse_url($url, PHP_URL_PATH);
        $name = $path ? basename($path) : ('dki-' . time() . '.jpg');

        $tmp = download_url($url, (int) DKI_Options::get('timeout', 25));
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $file_array = [
            'name'     => sanitize_file_name($name),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, $post_id, $desc);

        if (is_wp_error($id)) {
            @unlink($tmp);
            return $id;
        }

        return (int)$id;
    }

    private static function sync_attributes(int $product_id, array $p, string $type): void {

        $create_global = (DKI_Options::get('create_global_attributes', 'yes') === 'yes');

        // جمع‌آوری ویژگی‌ها از specifications + review.attributes
        $attrs_map = [];

        $specs = $p['specifications'] ?? [];
        if (is_array($specs)) {
            foreach ($specs as $group) {
                $attrs = $group['attributes'] ?? [];
                if (!is_array($attrs)) continue;
                foreach ($attrs as $a) {
                    $t = trim((string)($a['title'] ?? ''));
                    $vals = $a['values'] ?? [];
                    if (!is_array($vals)) $vals = [];
                    $vals = array_values(array_filter(array_map('sanitize_text_field', $vals)));
                    if ($t && !empty($vals)) {
                        $attrs_map[$t] = array_values(array_unique(array_merge($attrs_map[$t] ?? [], $vals)));
                    }
                }
            }
        }

        $high = $p['review']['attributes'] ?? [];
        if (is_array($high)) {
            foreach ($high as $a) {
                $t = trim((string)($a['title'] ?? ''));
                $vals = $a['values'] ?? [];
                if (!is_array($vals)) $vals = [];
                $vals = array_values(array_filter(array_map('sanitize_text_field', $vals)));
                if ($t && !empty($vals)) {
                    $attrs_map[$t] = array_values(array_unique(array_merge($attrs_map[$t] ?? [], $vals)));
                }
            }
        }

        // اگر variable باشد، ویژگی‌های واریانت را هم اضافه کن (و is_variation)
        $variation_attrs = [];
        if ($type === 'variable') {
            $variants = $p['variants'] ?? [];
            if (is_array($variants)) {
                foreach ($variants as $v) {
                    $vm = self::variant_attributes_map($v);
                    foreach ($vm as $k => $vval) {
                        if (!$k || !$vval) continue;
                        $variation_attrs[$k] = array_values(array_unique(array_merge($variation_attrs[$k] ?? [], [$vval])));
                        $attrs_map[$k] = array_values(array_unique(array_merge($attrs_map[$k] ?? [], [$vval])));
                    }
                }
            }
        }

        $wc_product = wc_get_product($product_id);
        if (!$wc_product) return;

        $product_attributes = [];

        foreach ($attrs_map as $label => $values) {
            if (empty($values)) continue;

            $is_variation = isset($variation_attrs[$label]);
            $taxonomy = '';

            if ($create_global) {
                $taxonomy = self::ensure_global_attribute_taxonomy($label);
            }

            $attr_obj = new WC_Product_Attribute();

            if ($taxonomy) {
                $attr_id = wc_attribute_taxonomy_id_by_name(substr($taxonomy, 3)); // remove pa_
                $attr_obj->set_id((int)$attr_id);
                $attr_obj->set_name($taxonomy);

                // terms
                $term_ids = [];
                foreach ($values as $val) {
                    $term = term_exists($val, $taxonomy);
                    if (!$term) {
                        $term = wp_insert_term($val, $taxonomy);
                    }
                    if (!is_wp_error($term) && !empty($term['term_id'])) {
                        $term_ids[] = (int)$term['term_id'];
                    }
                }
                $attr_obj->set_options($term_ids);
            } else {
                // local attribute
                $attr_obj->set_name($label);
                $attr_obj->set_options($values);
            }

            $attr_obj->set_position(0);
            $attr_obj->set_visible(true);
            $attr_obj->set_variation($is_variation);

            $product_attributes[] = $attr_obj;
        }

        $wc_product->set_attributes($product_attributes);
        $wc_product->save();
    }

    private static function label_to_attr_slug(string $label): string {
        // IMPORTANT: This function must be pure and MUST NOT call itself.
        // We intentionally use sanitize_title (not sanitize_key) to support
        // non‑Latin labels as best as WP can, with a safe hash fallback.
        $label = trim($label);

        $slug = sanitize_title($label);
        if ($slug === '') {
            $slug = 'attr-' . substr(md5($label), 0, 12);
        }

        // WC attribute taxonomy name (pa_{slug}) has limits; keep it short.
        return substr($slug, 0, 28);
    }

    private static function register_attribute_taxonomy_if_needed(string $taxonomy, string $label): void {
        if (taxonomy_exists($taxonomy)) return;

        // رجیستر موقت تا بتوانیم term بسازیم (در همین درخواست AJAX)
        register_taxonomy($taxonomy, ['product'], [
            'hierarchical' => true,
            'show_ui'      => false,
            'query_var'    => true,
            'rewrite'      => false,
            'public'       => false,
            'labels'       => [
                'name' => $label,
            ],
        ]);
    }

    private static function ensure_global_attribute_taxonomy(string $label): string {
        if (!function_exists('wc_create_attribute')) {
            return '';
        }
        $label = trim($label);
        if ($label === '') return '';

        $slug = self::label_to_attr_slug($label);
        $taxonomy = wc_attribute_taxonomy_name($slug); // pa_{slug}

        // اگر attribute در DB هست ولی taxonomy هنوز register نشده (در همین درخواست)
        $exists_id = wc_attribute_taxonomy_id_by_name($slug);
        if ($exists_id && !taxonomy_exists($taxonomy)) {
            self::register_attribute_taxonomy_if_needed($taxonomy, $label);
            return $taxonomy;
        }

        // اگر taxonomy وجود دارد، تمام
        if (taxonomy_exists($taxonomy)) {
            return $taxonomy;
        }

        // ایجاد attribute
        $attr_id = wc_create_attribute([
            'name'         => $label,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        if (is_wp_error($attr_id)) {
            return '';
        }

        delete_transient('wc_attribute_taxonomies');

        // رجیستر موقت taxonomy برای ساخت term در همین درخواست
        self::register_attribute_taxonomy_if_needed($taxonomy, $label);

        return $taxonomy;
    }

    private static function variant_attributes_map(array $variant): array {
        // map label => single value string (join)
        $out = [];

        $attrs = $variant['attributes'] ?? null;
        if (is_array($attrs)) {
            foreach ($attrs as $a) {
                $t = trim((string)($a['title'] ?? ''));
                $vals = $a['values'] ?? [];
                if (!is_array($vals)) $vals = [];
                $v = implode('، ', array_values(array_filter(array_map('sanitize_text_field', $vals))));
                if ($t && $v) $out[$t] = $v;
            }
        }

        // بعضی ساختارها ممکن است این‌طور باشند: ['variant_options'=>[...]]
        if (empty($out) && !empty($variant['variant_options']) && is_array($variant['variant_options'])) {
            foreach ($variant['variant_options'] as $a) {
                $t = trim((string)($a['title'] ?? ''));
                $v = trim((string)($a['value'] ?? ''));
                if ($t && $v) $out[$t] = sanitize_text_field($v);
            }
        }

        // رنگ
        if (empty($out) && !empty($variant['color']['title'])) {
            $out['رنگ'] = sanitize_text_field($variant['color']['title']);
        }

        return $out;
    }

    private static function sync_variations(int $product_id, array $p): bool|WP_Error {
        $wc_product = wc_get_product($product_id);
        if (!$wc_product || $wc_product->get_type() !== 'variable') {
            return new WP_Error('not_variable', 'محصول Variable نیست.');
        }

        $variants = $p['variants'] ?? [];
        if (!is_array($variants) || empty($variants)) {
            return new WP_Error('no_variants', 'واریانت‌ها در پاسخ دیجی‌کالا یافت نشد.');
        }

        // پاکسازی وارییشن‌های قبلی (برای sync درست)
        self::delete_all_variations($product_id);

        // attributes موجود روی محصول را بخوان
        $prod_attrs = $wc_product->get_attributes();
        $tax_to_label = [];
        foreach ($prod_attrs as $attr) {
            if ($attr instanceof WC_Product_Attribute) {
                $name = $attr->get_name();
                if (is_string($name) && strpos($name, 'pa_') === 0) {
                    $tax_to_label[$name] = $name;
                }
            }
        }

        $created = 0;

        foreach ($variants as $v) {
            $vmap = self::variant_attributes_map($v);
            if (empty($vmap)) {
                // اگر ساختار واریانت ناقص است، رد کن
                continue;
            }

            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);

            // price
            $price = $v['price'] ?? [];
            $sale_rial = is_array($price) ? (int)($price['selling_price'] ?? 0) : 0;
            $reg_rial  = is_array($price) ? (int)($price['rrp_price'] ?? 0) : 0;
            if (!$reg_rial) $reg_rial = $sale_rial;

            $sale = DKI_Options::price_to_store_unit($sale_rial);
            $reg  = DKI_Options::price_to_store_unit($reg_rial);

            if ($reg > 0) {
                $variation->set_regular_price((string)$reg);
                if ($sale > 0 && $sale < $reg) $variation->set_sale_price((string)$sale);
            }

            // stock
            $st = (string)($v['status'] ?? '');
            if ($st === 'in_stock') $variation->set_stock_status('instock');
            elseif ($st === 'out_of_stock') $variation->set_stock_status('outofstock');
            else $variation->set_stock_status('instock');

            // sku
            $vid = (int)($v['id'] ?? ($v['variant_id'] ?? 0));
            $variation->set_sku('DKI-' . $product_id . '-' . ($vid ?: $created+1));

            // attributes for variation (must be taxonomy key => term slug)
            $attrs_for_var = [];

            foreach ($vmap as $label => $val) {
                // پیدا کردن taxonomy از روی label در attributes محصول
                $taxonomy = self::guess_taxonomy_for_label($wc_product, $label);
                if ($taxonomy && taxonomy_exists($taxonomy)) {
                    $term = term_exists($val, $taxonomy);
                    if (!$term) $term = wp_insert_term($val, $taxonomy);
                    if (!is_wp_error($term)) {
                        $term_obj = get_term_by('id', (int)$term['term_id'], $taxonomy);
                        if ($term_obj) {
                            $attrs_for_var[$taxonomy] = $term_obj->slug;
                        }
                    }
                } else {
                    // local attribute
                    $attrs_for_var[sanitize_title($label)] = $val;
                }
            }

            if (empty($attrs_for_var)) continue;

            $variation->set_attributes($attrs_for_var);

            // variation image
            $img_url = '';
            if (!empty($v['images']['main']['url'][0])) {
                $img_url = $v['images']['main']['url'][0];
            }
            if ($img_url) {
                $att_id = self::sideload_image_to_media($img_url, $product_id, 'Variation Image');
                if (!is_wp_error($att_id)) {
                    $variation->set_image_id((int)$att_id);
                }
            }

            $variation_id = $variation->save();
            if ($variation_id) $created++;
        }

        if ($created <= 0) {
            return new WP_Error('no_variations_created', 'هیچ وارییشنی ساخته نشد. ساختار واریانت‌های دیجی‌کالا ممکن است متفاوت باشد.');
        }

        // sync variable product price range
        $wc_product->save();

        return true;
    }

    private static function guess_taxonomy_for_label(WC_Product $wc_product, string $label): string {
        $attrs = $wc_product->get_attributes();
        foreach ($attrs as $attr) {
            if (!($attr instanceof WC_Product_Attribute)) continue;
            $name = $attr->get_name();
            if (is_string($name) && strpos($name, 'pa_') === 0) {
                $id = $attr->get_id();
                // نام attribute در DB
                $tax = $name;
                // عنوان را مستقیماً نداریم؛ پس ساده‌ترین: اگر term با val وارد شده، tax درست است.
                // ولی برای انتخاب tax بر اساس label، اینجا fallback می‌زنیم:
                // اگر slug label داخل tax باشد.
                $slug = self::label_to_attr_slug($label);
                if ($slug && strpos($tax, 'pa_' . $slug) === 0) {
                    return $tax;
                }
            }
        }
        // fallback: تلاش برای tax از label
        $slug = self::label_to_attr_slug($label);
        if ($slug) {
            $tax = wc_attribute_taxonomy_name($slug);
            return $tax;
        }
        return '';
    }

    private static function delete_all_variations(int $product_id): void {
        $children = get_posts([
            'post_type'      => 'product_variation',
            'post_parent'    => $product_id,
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
        ]);
        foreach ($children as $cid) {
            wp_delete_post((int)$cid, true);
        }
    }
}
