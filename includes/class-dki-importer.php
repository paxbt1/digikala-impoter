<?php
if (!defined('ABSPATH')) exit;

class DKI_Importer {

    public static function get_price_mode(): string {
        $mode = get_option('dki_price_mode', 'auto'); // auto|irr|toman
        return in_array($mode, ['auto','irr','toman'], true) ? $mode : 'auto';
    }

    public static function convert_price(?int $price_irr): int {
        if (!$price_irr) return 0;
        $mode = self::get_price_mode();
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $is_toman_currency = in_array($currency, ['IRT', 'TMN', 'TOMAN', 'تومان'], true);

        if ($mode === 'toman' || ($mode === 'auto' && $is_toman_currency)) {
            return (int) floor($price_irr / 10);
        }
        // irr or auto without toman currency
        return (int) $price_irr;
    }

    public static function ensure_attribute_pa_color() {
        if (!function_exists('wc_get_attribute_taxonomies')) return;
        $tax = 'pa_color';
        $exists = taxonomy_exists($tax);
        if ($exists) return;

        // check attribute taxonomies
        $atts = wc_get_attribute_taxonomies();
        $has = false;
        if (is_array($atts)) {
            foreach ($atts as $a) {
                if (!empty($a->attribute_name) && $a->attribute_name === 'color') { $has = true; break; }
            }
        }

        if (!$has && function_exists('wc_create_attribute')) {
            $id = wc_create_attribute([
                'name'         => 'Color',
                'slug'         => 'color',
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ]);
            if (!is_wp_error($id)) {
                delete_transient('wc_attribute_taxonomies');
            }
        }

        // register taxonomy immediately (Woo registers on init, but we can trigger)
        if (!taxonomy_exists($tax)) {
            register_taxonomy(
                $tax,
                apply_filters('woocommerce_taxonomy_objects_' . $tax, ['product']),
                apply_filters('woocommerce_taxonomy_args_' . $tax, [
                    'hierarchical' => false,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                ])
            );
        }
    }

    public static function upsert_product_from_dk(array $dk, array $assign_cat_ids = []) {
        if (empty($dk['title'])) return new WP_Error('dki_no_title', 'عنوان محصول یافت نشد.');

        $external_id = (int)($dk['product_id'] ?? 0);
        $source_url = (string)($dk['source_url'] ?? '');

        // Dedup: use meta _dki_product_id
        $existing_id = 0;
        if ($external_id) {
            $q = new WP_Query([
                'post_type'      => 'product',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_dki_product_id',
                'meta_value'     => $external_id,
            ]);
            if (!empty($q->posts[0])) $existing_id = (int)$q->posts[0];
        }

        $post_args = [
            'post_title'   => wp_strip_all_tags($dk['title']),
            'post_content' => (string)($dk['description'] ?? ''),
            'post_excerpt' => (string)($dk['short_desc'] ?? ''),
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ];

        if ($existing_id) {
            $post_args['ID'] = $existing_id;
            $product_id = wp_update_post($post_args, true);
        } else {
            $product_id = wp_insert_post($post_args, true);
        }
        if (is_wp_error($product_id)) return $product_id;

        // Save source meta
        if ($external_id) update_post_meta($product_id, '_dki_product_id', $external_id);
        if ($source_url) update_post_meta($product_id, '_dki_source_url', esc_url_raw($source_url));
        if (!empty($dk['specs_html'])) update_post_meta($product_id, '_dki_specs_html', wp_kses_post($dk['specs_html']));

        // Determine variable vs simple: if variants include multiple colors
        $variants = $dk['variants'] ?? [];
        $colors_map = self::extract_colors_from_variants($variants);

        $is_variable = (count($colors_map) >= 1); // if at least 1 color variant exists, create variable (single attribute)
        // Some products might be truly simple with no color in variants -> simple.
        if (count($colors_map) === 0) $is_variable = false;

        if ($is_variable) {
            self::ensure_attribute_pa_color();
            wp_set_object_terms($product_id, 'variable', 'product_type');

            // assign categories if provided
            if (!empty($assign_cat_ids)) {
                wp_set_object_terms($product_id, array_map('intval', $assign_cat_ids), 'product_cat', false);
            }

            $color_terms = [];
            foreach ($colors_map as $color_name => $payload) {
                $term = term_exists($color_name, 'pa_color');
                if (!$term) {
                    $term = wp_insert_term($color_name, 'pa_color');
                }
                if (!is_wp_error($term) && !empty($term['term_id'])) {
                    $color_terms[] = (int)$term['term_id'];
                }
            }
            if ($color_terms) {
                wp_set_object_terms($product_id, $color_terms, 'pa_color', false);
            }

            // product attributes meta
            $attrs = [
                'pa_color' => [
                    'name'         => 'pa_color',
                    'value'        => '',
                    'position'     => 0,
                    'is_visible'   => 1,
                    'is_variation' => 1,
                    'is_taxonomy'  => 1,
                ],
            ];
            update_post_meta($product_id, '_product_attributes', $attrs);

            // build variations: ONE per color (minimum price variant in that color)
            $variation_ids = self::sync_color_variations($product_id, $colors_map);

            // choose default as min-price color
            $min_color = '';
            $min_price = null;
            foreach ($colors_map as $color_name => $payload) {
                $p = $payload['price'] ?? null;
                if ($p === null) continue;
                if ($min_price === null or $p < $min_price) {
                    $min_price = $p;
                    $min_color = $color_name;
                }
            }
            if ($min_color !== '') {
                update_post_meta($product_id, '_default_attributes', ['pa_color' => sanitize_title($min_color)]);
            }

            // set parent stock status based on any instock variation
            $parent_stock = 'outofstock';
            foreach ($colors_map as $payload) {
                if (($payload['stock_status'] ?? '') === 'instock') { $parent_stock = 'instock'; break; }
            }
            wc_update_product_stock_status($product_id, $parent_stock);

            // set price range by Woo automatically from variations; but ensure parent _price is min
            if ($min_price !== null) {
                update_post_meta($product_id, '_price', (string)$min_price);
            }

        } else {
            wp_set_object_terms($product_id, 'simple', 'product_type');

            if (!empty($assign_cat_ids)) {
                wp_set_object_terms($product_id, array_map('intval', $assign_cat_ids), 'product_cat', false);
            }

            $price = isset($dk['price']) ? self::convert_price((int)$dk['price']) : 0;
            update_post_meta($product_id, '_regular_price', (string)$price);
            update_post_meta($product_id, '_price', (string)$price);

            $stock = (string)($dk['stock_status'] ?? 'outofstock');
            wc_update_product_stock_status($product_id, $stock);
        }

        // Attributes (global product attributes - create WC attributes if needed)
        if (!empty($dk['attributes']) && is_array($dk['attributes'])) {
            self::apply_global_attributes($product_id, $dk['attributes']);
        }

        // Images: set featured + gallery
        if (!empty($dk['images']) && is_array($dk['images'])) {
            $alt = self::compute_image_alt($dk);
            self::set_images($product_id, $dk['images'], $dk['title'] ?? '', $alt);
        }

        // Append credit link to digikala (optional)
        if ($source_url) {
            self::append_credit_link($product_id, $source_url);
        }

        return (int)$product_id;
    }

    private static function append_credit_link(int $product_id, string $source_url): void {
        $enabled = get_option('dki_credit_enabled', '1') === '1';
        if (!$enabled) {
            return;
        }

        $nofollow = get_option('dki_credit_nofollow', '1') === '1';
        $text_mode = get_option('dki_credit_text_mode', 'default');
        $custom_text = (string)get_option('dki_credit_text_custom', '');
        $anchor = 'مشاهده در دیجی‌کالا';
        if ($text_mode === 'custom' && trim($custom_text) !== '') {
            $anchor = trim($custom_text);
        }
        $rel = $nofollow ? 'nofollow noopener' : 'noopener';
        $html = '<p class="dki-credit"><a href="' . esc_url($source_url) . '" target="_blank" rel="' . esc_attr($rel) . '">' . esc_html($anchor) . '</a></p>';

        $post = get_post($product_id);
        if (!$post) return;

        // Avoid duplicate append
        if (strpos($post->post_content, 'class="dki-credit"') !== false) return;

        $new = $post->post_content . "\n\n" . $html;
        wp_update_post([
            'ID' => $product_id,
            'post_content' => $new,
        ]);
    }

    private static function extract_colors_from_variants(array $variants): array {
        $colors = [];
        foreach ($variants as $v) {
            if (!is_array($v)) continue;
            $color = $v['color'] ?? null;
            if (!is_array($color)) continue;
            $name = trim((string)($color['title'] ?? $color['title_fa'] ?? ''));
            if ($name === '') continue;

            $price_irr = $v['price']['selling_price'] ?? null;
            $price = $price_irr !== null ? self::convert_price((int)$price_irr) : null;

            $stock = 'outofstock';
            $st = (string)($v['status'] ?? '');
            $av = (string)($v['availability']['status'] ?? '');
            if ($st === 'marketable' || $av === 'in_stock' || $av === 'available') $stock = 'instock';

            // Keep the minimum priced variant per color
            if (!isset($colors[$name])) {
                $colors[$name] = ['price' => $price, 'stock_status' => $stock];
            } else {
                if ($price !== null) {
                    $prev = $colors[$name]['price'];
                    if ($prev === null || $price < $prev) $colors[$name]['price'] = $price;
                }
                if ($stock === 'instock') $colors[$name]['stock_status'] = 'instock';
            }
        }
        return $colors;
    }

    private static function sync_color_variations(int $product_id, array $colors_map): array {
        $created = [];

        // existing variations by attribute value
        $existing = [];
        $children = get_posts([
            'post_parent' => $product_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => ['publish','private'],
            'fields'      => 'ids',
        ]);
        foreach ($children as $vid) {
            $val = get_post_meta($vid, 'attribute_pa_color', true);
            if ($val) $existing[$val] = (int)$vid;
        }

        foreach ($colors_map as $color_name => $payload) {
            $slug = sanitize_title($color_name);
            $vid = $existing[$slug] ?? 0;

            if (!$vid) {
                $vid = wp_insert_post([
                    'post_title'  => 'Variation: ' . $color_name,
                    'post_name'   => 'product-' . $product_id . '-variation-' . $slug,
                    'post_status' => 'publish',
                    'post_parent' => $product_id,
                    'post_type'   => 'product_variation',
                    'menu_order'  => 0,
                ], true);
                if (is_wp_error($vid)) continue;
            }

            update_post_meta($vid, 'attribute_pa_color', $slug);

            $price = $payload['price'] ?? null;
            if ($price !== null) {
                update_post_meta($vid, '_regular_price', (string)$price);
                update_post_meta($vid, '_price', (string)$price);
            }

            $stock = (string)($payload['stock_status'] ?? 'outofstock');
            wc_update_product_stock_status($vid, $stock);

            $created[] = (int)$vid;
        }

        return $created;
    }

    private static function apply_global_attributes(int $product_id, array $attributes): void {
        // Create WC product attributes (global) if needed; assign per-product in _product_attributes too.
        // IMPORTANT: keep existing product attributes if any; merge.
        $existing = get_post_meta($product_id, '_product_attributes', true);
        if (!is_array($existing)) $existing = [];

        $pos = 1;
        foreach ($attributes as $name => $values) {
            $name = trim((string)$name);
            if ($name === '') continue;
            if (!is_array($values)) $values = [$values];
            $values = array_values(array_filter(array_map(function($v){ return trim((string)$v); }, $values)));
            if (!$values) continue;

            // Use custom (local) attribute (non-taxonomy) so we don't pollute global list too much,
            // but also add to visible attributes.
            // If user wants global, they can migrate later.
            $key = sanitize_title($name);
            $meta_key = $key;

            // avoid collision with pa_color
            if ($meta_key === 'pa_color' || $meta_key === 'color') $meta_key = 'dki_' . $meta_key;

            $existing[$meta_key] = [
                'name'         => $name,
                'value'        => implode(' | ', $values),
                'position'     => $pos++,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 0,
            ];
        }

        update_post_meta($product_id, '_product_attributes', $existing);
    }

    private static function compute_image_alt(array $dk): string {
        $mode = get_option('dki_image_alt_mode', 'product');
        $mode = is_string($mode) ? $mode : 'product';

        $title = isset($dk['title']) ? wp_strip_all_tags((string) $dk['title']) : '';

        if ($mode === 'fixed') {
            $fixed = get_option('dki_image_alt_fixed', '');
            $fixed = wp_strip_all_tags(is_string($fixed) ? $fixed : '');
            return trim($fixed);
        }

        // default: product title
        return trim($title);
    }

    private static function set_images(int $product_id, array $urls, string $desc = '', string $alt = ''): void {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_ids = [];
        foreach ($urls as $u) {
            $u = esc_url_raw($u);
            if (!$u) continue;

            // try to dedupe by meta _dki_image_source
            $existing = self::find_attachment_by_source($u);
            if ($existing) {
                if ($alt !== '') {
                    update_post_meta($existing, '_wp_attachment_image_alt', $alt);
                }
                $attachment_ids[] = $existing;
                continue;
            }

            $tmp = download_url($u, 30);
            if (is_wp_error($tmp)) continue;

            $file_array = [
                'name'     => basename(parse_url($u, PHP_URL_PATH)),
                'tmp_name' => $tmp,
            ];
            $id = media_handle_sideload($file_array, $product_id, $desc);
            if (is_wp_error($id)) {
                @unlink($tmp);
                continue;
            }
            update_post_meta($id, '_dki_image_source', $u);
            if ($alt !== '') {
                update_post_meta((int)$id, '_wp_attachment_image_alt', $alt);
            }
            $attachment_ids[] = (int)$id;
        }

        $attachment_ids = array_values(array_unique(array_filter($attachment_ids)));
        if (!$attachment_ids) return;

        set_post_thumbnail($product_id, $attachment_ids[0]);

        if (count($attachment_ids) > 1) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($attachment_ids, 1)));
        }
    }

    private static function find_attachment_by_source(string $url): int {
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_dki_image_source',
            'meta_value'     => $url,
        ]);
        return !empty($q->posts[0]) ? (int)$q->posts[0] : 0;
    }
}
