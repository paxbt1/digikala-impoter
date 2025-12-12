<?php
if (!defined('ABSPATH')) exit;

class DKI_Options {

    const OPTION_KEY = 'dki_settings';

    public static function defaults(): array {
        return [
            'price_unit'        => 'auto',   // auto | toman | rial
            'post_status'       => 'publish',// publish | draft
            'update_existing'   => 'yes',    // yes | no
            'timeout'           => 25,       // seconds
            'image_limit'       => 12,       // gallery images to import
            'create_global_attributes' => 'yes', // yes | no
        ];
    }

    public static function get_all(): array {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) $saved = [];
        return array_merge(self::defaults(), $saved);
    }

    public static function get(string $key, $default = null) {
        $all = self::get_all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function update(array $new): bool {
        $all = self::get_all();
        $merged = array_merge($all, $new);
        return update_option(self::OPTION_KEY, $merged, false);
    }

    public static function price_to_store_unit(int $price_rial): int {
        $unit = self::get('price_unit', 'auto');
        $wc_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';

        // دیجی‌کالا معمولاً IRR برمی‌گرداند
        // اگر سایت تومان باشد (IRT) یا کاربر گزینه تومان را انتخاب کند، تقسیم بر 10
        $to_toman = false;

        if ($unit === 'toman') $to_toman = true;
        if ($unit === 'rial')  $to_toman = false;

        if ($unit === 'auto') {
            // IRT / تومان => divide
            if (strtoupper($wc_currency) === 'IRT' || strtoupper($wc_currency) === 'TMN') {
                $to_toman = true;
            }
        }

        if ($to_toman) {
            return (int) floor($price_rial / 10);
        }
        return (int) $price_rial;
    }
}
