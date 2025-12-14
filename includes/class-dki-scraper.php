<?php
if (!defined('ABSPATH')) exit;

class DKI_Scraper
{

    public static function extract_product_id_from_url(string $url): int
    {
        // /product/dkp-19404627/...
        if (preg_match('~dkp-(\d+)~', $url, $m)) return (int)$m[1];
        // sometimes product id in query
        if (preg_match('~product/(\d+)~', $url, $m)) return (int)$m[1];
        return 0;
    }

    public static function remote_get_json(string $url, array $args = [])
    {
        $defaults = [
            'timeout' => 20,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (WordPress Digikala Importer)',
                'Accept'     => 'application/json',
            ],
        ];
        $args = wp_parse_args($args, $defaults);

        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return $res;

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);

        if ($code !== 200) {
            $msg = 'کد پاسخ غیرمنتظره از دیجی‌کالا.';
            // include short body for debugging
            if (is_string($body) && $body !== '') {
                $msg .= ' HTTP ' . $code;
            }
            return new WP_Error('dki_http_' . $code, $msg, ['code' => $code, 'body' => $body]);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return new WP_Error('dki_bad_json', 'پاسخ JSON معتبر نیست.');
        }

        return $json;
    }

    /**
     * API search (v1) - returns array of products {id,title_fa,title_en,url,image,price,stock_status}
     */
    public static function search_products_api(string $query, int $page = 1): array
    {
        $api_url = add_query_arg(
            [
                'page' => max(1, $page),
                'q'    => $query,
            ],
            'https://api.digikala.com/v1/search/'
        );

        $json = self::remote_get_json($api_url);
        if (is_wp_error($json)) return $json;

        if (empty($json['data']['products']) || !is_array($json['data']['products'])) {
            return [];
        }

        $out = [];
        foreach ($json['data']['products'] as $p) {
            $id = isset($p['id']) ? (int)$p['id'] : 0;
            if (!$id) continue;

            $title_fa = (string)($p['title_fa'] ?? '');
            $title_en = (string)($p['title_en'] ?? '');
            $uri = (string)($p['url']['uri'] ?? '');
            $link = $uri ? 'https://www.digikala.com' . $uri : '';

            $image_url = '';
            if (!empty($p['default_variant']['images']['main']['url'][0])) {
                $image_url = $p['default_variant']['images']['main']['url'][0];
            } elseif (!empty($p['images']['main']['url'][0])) {
                $image_url = $p['images']['main']['url'][0];
            }

            $price = null;
            if (!empty($p['default_variant']['price']['selling_price'])) {
                $price = (int) $p['default_variant']['price']['selling_price'];
            }

            // stock status (best effort)
            $stock = 'outofstock';
            if (!empty($p['status']) && $p['status'] === 'marketable') $stock = 'instock';
            if (!empty($p['default_variant']['status']) && $p['default_variant']['status'] === 'marketable') $stock = 'instock';

            $out[] = [
                'id'          => $id,
                'title_fa'    => $title_fa,
                'title_en'    => $title_en,
                'url'         => $link,
                'image'       => $image_url,
                'price'       => $price,
                'stock_status' => $stock,
            ];
        }
        return $out;
    }

    /**
     * Product details via API v2/product/{id}
     * returns normalized array for importer.
     */
    public static function fetch_product_v2(int $product_id)
    {
        if ($product_id <= 0) return new WP_Error('dki_no_id', 'شناسه محصول معتبر نیست.');
        $url = 'https://api.digikala.com/v2/product/' . $product_id . '/';

        $json = self::remote_get_json($url);
        if (is_wp_error($json)) return $json;

        $prod = $json['data']['product'] ?? null;
        if (!is_array($prod)) {
            return new WP_Error('dki_no_product', 'اطلاعات محصول در پاسخ دیجی‌کالا یافت نشد');
        }

        $title = (string)($prod['title_fa'] ?? '');
        if ($title === '') $title = (string)($prod['title_en'] ?? '');

        $source_uri = (string)($prod['url']['uri'] ?? '');
        $source_url = $source_uri ? 'https://www.digikala.com' . $source_uri : '';

        // Images: main + list
        $images = [];
        if (!empty($prod['images']['main']['url'][0])) $images[] = $prod['images']['main']['url'][0];
        if (!empty($prod['images']['list']) && is_array($prod['images']['list'])) {
            foreach ($prod['images']['list'] as $im) {
                if (!empty($im['url'][0])) $images[] = $im['url'][0];
            }
        }
        $images = array_values(array_unique(array_filter($images)));

        // Description / content
        $short = '';
        $long_html = '';
        $expert_desc = (string)($prod['expert_reviews']['description'] ?? '');
        $short_review = (string)($prod['expert_reviews']['short_review'] ?? '');
        if ($short_review) $short = wp_trim_words(wp_strip_all_tags($short_review), 40, '...');
        if ($expert_desc) $long_html .= '<h2>بررسی تخصصی</h2>' . wp_kses_post($expert_desc);

        // Specifications -> html list
        $specs_html = '';
        if (!empty($prod['specifications']) && is_array($prod['specifications'])) {
            $specs_html .= '<div class="dki-specs">';
            foreach ($prod['specifications'] as $group) {
                $gtitle = esc_html((string)($group['title'] ?? ''));
                if ($gtitle) $specs_html .= '<h3>' . $gtitle . '</h3>';
                if (!empty($group['attributes']) && is_array($group['attributes'])) {
                    $specs_html .= '<ul>';
                    foreach ($group['attributes'] as $attr) {
                        $at = esc_html((string)($attr['title'] ?? ''));
                        $vals = $attr['values'] ?? [];
                        if (!is_array($vals)) $vals = [$vals];
                        $vals = array_map(function ($v) {
                            return trim((string)$v);
                        }, $vals);
                        $vals = array_values(array_filter($vals, function ($v) {
                            return $v !== '';
                        }));
                        if ($at && $vals) {
                            $specs_html .= '<li><strong>' . $at . ':</strong> ' . esc_html(implode('، ', $vals)) . '</li>';
                        }
                    }
                    $specs_html .= '</ul>';
                }
            }
            $specs_html .= '</div>';
        }

        if ($specs_html) $long_html .= '<h2>مشخصات</h2>' . $specs_html;

        // Attributes from review.attributes and specifications merged
        $attributes = [];
        if (!empty($prod['review']['attributes']) && is_array($prod['review']['attributes'])) {
            foreach ($prod['review']['attributes'] as $a) {
                $t = trim((string)($a['title'] ?? ''));
                $vals = $a['values'] ?? [];
                if (!is_array($vals)) $vals = [$vals];
                $vals = array_values(array_filter(array_map('trim', array_map('strval', $vals))));
                if ($t && $vals) {
                    if (!isset($attributes[$t])) $attributes[$t] = [];
                    $attributes[$t] = array_values(array_unique(array_merge($attributes[$t], $vals)));
                }
            }
        }

        // Variants
        $variants = [];
        if (!empty($prod['variants']) && is_array($prod['variants'])) {
            foreach ($prod['variants'] as $v) {
                if (!is_array($v)) continue;
                $variants[] = $v;
            }
        }

        // Determine base price from default variant or min of variants
        $price = null;
        if (!empty($prod['default_variant']['price']['selling_price'])) {
            $price = (int) $prod['default_variant']['price']['selling_price'];
        } else {
            $min = null;
            foreach ($variants as $v) {
                $sp = $v['price']['selling_price'] ?? null;
                if ($sp === null) continue;
                $sp = (int)$sp;
                if ($sp <= 0) continue;
                if ($min === null || $sp < $min) $min = $sp;
            }
            $price = $min;
        }

        // stock status best effort: if any variant marketable -> instock
        $stock_status = 'outofstock';
        foreach ($variants as $v) {
            $st = (string)($v['status'] ?? '');
            $is = (string)($v['availability']['status'] ?? '');
            if ($st === 'marketable' || $is === 'in_stock' || $is === 'available') {
                $stock_status = 'instock';
                break;
            }
        }
        if (!empty($prod['status']) && $prod['status'] === 'marketable') $stock_status = 'instock';

        return [
            'product_id'    => $product_id,
            'title'         => $title,
            'source_url'    => $source_url,
            'images'        => $images,
            'price'         => $price,
            'stock_status'  => $stock_status,
            'description'   => $long_html,
            'short_desc'    => $short,
            'specs_html'    => $specs_html,
            'attributes'    => $attributes,
            'variants'      => $variants,
        ];
    }

    /**
     * Category/brand search API (v1) pages
     * base: https://api.digikala.com/v1/categories/{category}/brands/{brand}/search/
     */
    public static function fetch_category_brand_search_page(string $category_slug, string $brand_slug, int $page = 1)
    {
        $api_base = sprintf('https://api.digikala.com/v1/categories/%s/brands/%s/search/', rawurlencode($category_slug), rawurlencode($brand_slug));
        $api_url = add_query_arg(['page' => max(1, $page)], $api_base);

        $json = self::remote_get_json($api_url);
        if (is_wp_error($json)) return $json;

        $products = $json['data']['products'] ?? [];
        if (!is_array($products)) $products = [];

        $out = [];
        foreach ($products as $p) {
            $id = isset($p['id']) ? (int)$p['id'] : 0;
            if (!$id) continue;

            $uri = (string)($p['url']['uri'] ?? '');
            $link = $uri ? 'https://www.digikala.com' . $uri : '';

            $image_url = '';
            if (!empty($p['default_variant']['images']['main']['url'][0])) $image_url = $p['default_variant']['images']['main']['url'][0];
            elseif (!empty($p['images']['main']['url'][0])) $image_url = $p['images']['main']['url'][0];

            $price = null;
            if (!empty($p['default_variant']['price']['selling_price'])) $price = (int)$p['default_variant']['price']['selling_price'];

            // stock per product: Digikala uses status/availability
            $stock = 'outofstock';
            if (!empty($p['status']) && $p['status'] === 'marketable') $stock = 'instock';
            if (!empty($p['default_variant']['status']) && $p['default_variant']['status'] === 'marketable') $stock = 'instock';

            $out[] = [
                'id'          => $id,
                'title_fa'    => (string)($p['title_fa'] ?? ''),
                'url'         => $link,
                'image'       => $image_url,
                'price'       => $price,
                'stock_status' => $stock,
            ];
        }

        $total = (int)($json['data']['pager']['total_pages'] ?? 1);

        return [
            'items' => $out,
            'page'  => max(1, $page),
            'total_pages' => max(1, $total),
        ];
    }

    /**
     * Extract category & brand slugs from Digikala brand-category URL like:
     * https://www.digikala.com/search/category-air-conditioner-2/uneva/
     */
    public static function parse_category_brand_url(string $url): array
    {
        $url = trim($url);
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path) return ['category_slug' => '', 'brand_slug' => ''];

        // /search/category-air-conditioner-2/uneva/
        $path = trim($path, '/');
        $parts = explode('/', $path);
        $category_slug = '';
        $brand_slug = '';
        if (count($parts) >= 3 && $parts[0] === 'search' && strpos($parts[1], 'category-') === 0) {
            $category_slug = substr($parts[1], strlen('category-'));
            $brand_slug = $parts[2];
        }
        return [
            'category_slug' => sanitize_title($category_slug),
            'brand_slug'    => sanitize_title($brand_slug),
        ];
    }
}
