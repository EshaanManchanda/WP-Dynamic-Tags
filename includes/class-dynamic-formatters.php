<?php
/**
 * Value formatters for dynamic placeholder tokens, e.g. {post_title|upper} or {meta:price|currency}.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Formatters {

    /**
     * Apply a single formatter spec ("upper", "round:2", "date:F j, Y") to a value.
     * Unknown formatter names are a no-op, matching this codebase's defensive style
     * of leaving unrecognized directives unchanged rather than erroring.
     *
     * @param string $value
     * @param string $spec Formatter name, optionally with a ":argument" suffix.
     * @return string
     */
    public static function format($value, $spec) {
        $parts = array_pad(explode(':', $spec, 2), 2, null);
        $name = $parts[0];
        $arg = $parts[1];

        switch ($name) {
            case 'upper':
                return strtoupper($value);

            case 'lower':
                return strtolower($value);

            case 'capitalize':
                return ucwords($value);

            case 'trim':
                return trim($value);

            case 'strip_html':
                return wp_strip_all_tags($value);

            case 'currency':
                return '$' . number_format((float) $value, 2);

            case 'decimal':
                return number_format((float) $value, $arg !== null ? (int) $arg : 2);

            case 'thousands':
                return number_format((float) $value);

            case 'round':
                return (string) round((float) $value, $arg !== null ? (int) $arg : 0);

            case 'date':
                return $value ? date_i18n($arg ? $arg : get_option('date_format'), strtotime($value)) : '';

            case 'relative':
                if (!$value) {
                    return '';
                }
                return sprintf(
                    /* translators: %s: human-readable time difference, e.g. "2 days" */
                    __('%s ago', 'wp-dynamic-tags'),
                    human_time_diff(strtotime($value), current_time('timestamp'))
                );

            default:
                return $value;
        }
    }
}
