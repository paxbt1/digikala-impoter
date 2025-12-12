<?php
if (!defined('ABSPATH')) exit;

class DKI_Scraper {

    public static function parse_product_id_from_url(string $url): int {
        // https://www.digikala.com/product/dkp-19404627/...
        if (preg_match('~/product/dkp-(\d+)/~', $url, $m)) {
            return (int) $m[1];
        }
        // sometimes /dkp-12345
        if (preg_match('~dkp-(\d+)~', $url, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public static function search_products(string $query, int $page = 1): array|WP_Error {
        $query = trim($query);
        if ($query === '') {
            return new WP_Error('empty_query', 'عبارت جستجو خالی است.');
        }
        $page = max(1, (int)$page);

        $api_url = add_query_arg(
            [
                'page' => $page,
                'q'    => $query,
            ],
            'https://api.digikala.com/v1/search/'
        );

        $response = wp_remote_get($api_url, [
            'timeout' => (int) DKI_Options::get('timeout', 25),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (WordPress Digikala Importer)',
                'Accept'     => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('bad_status', 'کد پاسخ غیرمنتظره از دیجی‌کالا: ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (!is_array($json) || empty($json['data'])) {
            return new WP_Error('bad_json', 'پاسخ نامعتبر از دیجی‌کالا دریافت شد.');
        }

        $products_raw = $json['data']['products'] ?? [];
        if (!is_array($products_raw) || empty($products_raw)) {
            return [];
        }

        $products = [];
        foreach ($products_raw as $p) {
            $id        = isset($p['id']) ? (int)$p['id'] : 0;
            $title_fa  = $p['title_fa'] ?? '';
            $title_en  = $p['title_en'] ?? '';
            $uri       = $p['url']['uri'] ?? '';
            $link      = $uri ? 'https://www.digikala.com' . $uri : '';
            $image_url = '';

            // تصویر اصلی
            if (!empty($p['default_variant']['images']['main']['url'][0])) {
                $image_url = $p['default_variant']['images']['main']['url'][0];
            } elseif (!empty($p['images']['main']['url'][0])) {
                $image_url = $p['images']['main']['url'][0];
            }

            // قیمت (IRR)
            $price_rial = 0;
            if (!empty($p['default_variant']['price']['selling_price'])) {
                $price_rial = (int) $p['default_variant']['price']['selling_price'];
            } elseif (!empty($p['default_variant']['price']['rrp_price'])) {
                $price_rial = (int) $p['default_variant']['price']['rrp_price'];
            }

            $products[] = [
                'id'           => $id,
                'title_fa'     => $title_fa,
                'title_en'     => $title_en,
                'url'          => $link,
                'image'        => $image_url,
                'price_rial'   => $price_rial,
                'price_store'  => DKI_Options::price_to_store_unit($price_rial),
            ];
        }

        return $products;
    }

    public static function fetch_product_by_id(int $product_id): array|WP_Error {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return new WP_Error('bad_id', 'شناسه محصول نامعتبر است.');
        }

        $api_url = 'https://api.digikala.com/v2/product/' . $product_id . '/';

        $response = wp_remote_get($api_url, [
            'timeout' => (int) DKI_Options::get('timeout', 25),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (WordPress Digikala Importer)',
                'Accept'     => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('bad_status', 'کد پاسخ غیرمنتظره از دیجی‌کالا: ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        $product = $json['data']['product'] ?? null;
        if (!is_array($product) || empty($product['id'])) {
            return new WP_Error('no_product', 'اطلاعات محصول در پاسخ دیجی‌کالا یافت نشد');
        }

        return $product;
    }

    public static function fetch_product_by_url(string $url): array|WP_Error {
        $id = self::parse_product_id_from_url($url);
        if ($id <= 0) {
            return new WP_Error('bad_url', 'شناسه محصول از لینک قابل استخراج نیست.');
        }
        return self::fetch_product_by_id($id);
    }
}
