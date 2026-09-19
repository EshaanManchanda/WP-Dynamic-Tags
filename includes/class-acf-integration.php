<?php
/**
 * Optional ACF (Advanced Custom Fields) integration for the {acf:field_name} placeholder.
 * Entirely inert when ACF is not installed/active.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    if (!function_exists('get_field')) {
        return;
    }

    if (!class_exists('WP_Dynamic_Tags_Placeholders')) {
        return;
    }

    $instance = WP_Dynamic_Tags_Placeholders::get_instance();
    if (!$instance || !method_exists($instance, 'register_parameterized_placeholder')) {
        return;
    }

    $instance->register_parameterized_placeholder('acf', function ($field_name) {
        global $post;
        if (!$post) {
            return '';
        }

        $value = get_field($field_name, $post->ID);

        // V1 only supports scalar ACF fields (text, number, select, etc.) — repeaters,
        // galleries, and relationship fields return arrays and are left empty for now.
        if (is_array($value)) {
            return '';
        }

        return (string) $value;
    });
}, 25); // Same priority convention as register_external_placeholder(), after the class is instantiated.
