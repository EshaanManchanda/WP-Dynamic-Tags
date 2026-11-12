<?php
/**
 * Value formatters for dynamic placeholder tokens, e.g. {post_title|upper} or {meta:price|currency}.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Dynamic_Tags_Formatters {

    /**
     * Formatter names that accept (and are meant to collapse) array values.
     * Every other formatter assumes a scalar; the engine skips them while a
     * value is still an array to avoid PHP 8 TypeErrors (e.g. strtoupper(array)).
     */
    private static $array_aware = array('join', 'count', 'first', 'last');

    public static function is_array_aware($name) {
        return in_array($name, self::$array_aware, true);
    }

    /**
     * Reduce a possibly-nested row (e.g. an ACF repeater row) to a single scalar
     * for display: assoc/array rows join their scalar values with a space.
     */
    private static function scalarize($item) {
        if (is_array($item)) {
            return implode(' ', array_map(array(__CLASS__, 'scalarize'), array_filter($item, 'is_scalar')));
        }
        if (is_object($item)) {
            return method_exists($item, '__toString') ? (string) $item : '';
        }
        return (string) $item;
    }

    /**
     * Parses a date/time string to a UTC timestamp. A string without a timezone is read as wall-clock time in the site's
     * timezone (what an author typing "2026-11-11 10:00" means); a string with an explicit timezone keeps it.
     *
     * @return int|null null when the text is not a date.
     */
    private static function parse_moment($value) {
        try {
            return (new DateTimeImmutable((string) $value, wp_timezone()))->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Apply a single formatter spec ("upper", "round:2", "date:F j, Y") to a value.
     * Unknown formatter names are a no-op, matching this codebase's defensive style
     * of leaving unrecognized directives unchanged rather than erroring.
     *
     * @param string|array $value
     * @param string $spec Formatter name, optionally with a ":argument" suffix.
     * @return string|array
     */
    public static function format($value, $spec) {
        $parts = array_pad(explode(':', $spec, 2), 2, null);
        $name = $parts[0];
        $arg = $parts[1];

        switch ($name) {
            case 'join':
                $sep = $arg !== null ? $arg : ', ';
                if (!is_array($value)) {
                    return self::scalarize($value);
                }
                return implode($sep, array_map(array(__CLASS__, 'scalarize'), $value));

            case 'count':
                return (string) (is_array($value) ? count($value) : (self::scalarize($value) !== '' ? 1 : 0));

            case 'first':
                if (!is_array($value)) {
                    return $value;
                }
                $first = reset($value);
                return $first === false ? '' : self::scalarize($first);

            case 'last':
                if (!is_array($value)) {
                    return $value;
                }
                $last = end($value);
                return $last === false ? '' : self::scalarize($last);

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
                // The symbol was hardcoded to "$" (L5); filter dt_currency_symbol changes it. Separators follow the site locale.
                return apply_filters('dt_currency_symbol', '$') . number_format_i18n((float) $value, 2);

            case 'decimal':
                return number_format_i18n((float) $value, $arg !== null ? (int) $arg : 2);

            case 'thousands':
                return number_format_i18n((float) $value);

            case 'round':
                return (string) round((float) $value, $arg !== null ? (int) $arg : 0);

            case 'date':
                if (!$value) {
                    return '';
                }
                // A value that cannot be parsed is returned as written. It used to be passed to date_i18n() as `false`,
                // which means "now", so a typo silently showed the current date (L5).
                $moment = self::parse_moment($value);
                return $moment === null ? $value : wp_date($arg ? $arg : get_option('date_format'), $moment);

            case 'relative':
                if (!$value) {
                    return '';
                }
                $moment = self::parse_moment($value);
                if ($moment === null) {
                    return $value;
                }
                // Both timestamps are real UTC timestamps. The old call compared strtotime() with
                // current_time('timestamp') (shifted by the site's offset), so a value with an explicit timezone was off by
                // that offset.
                return sprintf(
                    /* translators: %s: human-readable time difference, e.g. "2 days" */
                    __('%s ago', 'wp-dynamic-tags'),
                    human_time_diff($moment, time())
                );

            default:
                return $value;
        }
    }
}
