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

    $instance->register_parameterized_placeholder('acf', function ($arg) {
        global $post;
        if (!$post) {
            return '';
        }

        // Dot notation reaches into a repeater/group sub-field, e.g. "team_members.name"
        // extracts the "name" column across every repeater row as an array.
        $field_name = $arg;
        $subfield = null;
        $dot_pos = strpos($arg, '.');
        if ($dot_pos !== false) {
            $field_name = substr($arg, 0, $dot_pos);
            $subfield = substr($arg, $dot_pos + 1);
        }

        $value = get_field($field_name, $post->ID);
        $field = get_field_object($field_name, $post->ID);
        $type = $field ? $field['type'] : null;

        switch ($type) {
            case 'repeater':
            case 'flexible_content':
                if (!is_array($value)) {
                    return array();
                }
                return $subfield !== null ? wp_list_pluck($value, $subfield) : $value;

            case 'group':
                if (!is_array($value)) {
                    return '';
                }
                if ($subfield !== null) {
                    return isset($value[$subfield]) ? $value[$subfield] : '';
                }
                return $value;

            case 'gallery':
                if (!is_array($value)) {
                    return array();
                }
                return array_map(function ($item) {
                    if (is_array($item) && isset($item['url'])) {
                        return $item['url'];
                    }
                    if (is_numeric($item)) {
                        return wp_get_attachment_url($item);
                    }
                    return (string) $item;
                }, $value);

            case 'relationship':
            case 'post_object':
                if ($value === null || $value === false || $value === '') {
                    return array();
                }
                // Single-select post_object returns one item, not an array — normalize.
                $items = is_array($value) ? $value : array($value);
                return array_map(function ($item) {
                    if ($item instanceof WP_Post) {
                        return get_the_title($item);
                    }
                    if (is_numeric($item)) {
                        return get_the_title((int) $item);
                    }
                    return (string) $item;
                }, $items);
            // ponytail: relationship/post_object flatten to titles only — no way to pull
            // another field per related post. If that's needed later, expose {item:post_field}
            // inside [dt_loop] against the raw post list instead of growing this switch.

            default:
                // Unknown/scalar field types - unchanged V1 behavior.
                if (is_array($value)) {
                    return '';
                }
                return (string) $value;
        }
    });
}, 25); // Same priority convention as register_external_placeholder(), after the class is instantiated.
