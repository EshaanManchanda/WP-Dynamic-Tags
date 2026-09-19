<?php
/**
 * Optional WooCommerce integration for the {wc:field} placeholder.
 * Entirely inert when WooCommerce is not installed/active, or the current
 * post isn't a product.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    if (!function_exists('wc_get_product')) {
        return;
    }

    if (!class_exists('WP_Dynamic_Tags_Placeholders')) {
        return;
    }

    $instance = WP_Dynamic_Tags_Placeholders::get_instance();
    if (!$instance || !method_exists($instance, 'register_parameterized_placeholder')) {
        return;
    }

    $instance->register_parameterized_placeholder('wc', function ($field) {
        global $post;
        if (!$post) {
            return '';
        }

        $product = wc_get_product($post->ID);
        if (!$product) {
            return '';
        }

        switch ($field) {
            case 'price':
                return (string) $product->get_price();

            case 'regular_price':
                return (string) $product->get_regular_price();

            case 'sale_price':
                return (string) $product->get_sale_price();

            case 'sale_percent':
                $regular = (float) $product->get_regular_price();
                $sale = (float) $product->get_sale_price();
                if (!$product->is_on_sale() || $regular <= 0) {
                    return '';
                }
                return (string) round((($regular - $sale) / $regular) * 100);

            case 'sku':
                return (string) $product->get_sku();

            case 'stock_status':
                return (string) $product->get_stock_status();

            case 'stock_quantity':
                $qty = $product->get_stock_quantity();
                return $qty === null ? '' : (string) $qty;

            case 'categories':
                $terms = get_the_terms($post->ID, 'product_cat');
                return is_array($terms) ? wp_list_pluck($terms, 'name') : array();

            case 'tags':
                $terms = get_the_terms($post->ID, 'product_tag');
                return is_array($terms) ? wp_list_pluck($terms, 'name') : array();

            default:
                return '';
        }
    });
    // ponytail: cart/session/customer data is left out — this engine resolves everything
    // against "the current post" (global $post); cart state is per-visitor request state
    // with no post to bind to. A {wc_cart:item_count}-style flat prefix resolved from
    // WC()->cart would be the upgrade path if that's ever needed.
}, 25); // Same priority convention as class-acf-integration.php.
