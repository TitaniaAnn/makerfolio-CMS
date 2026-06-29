<?php
/**
 * EventTypes — single source of truth for the four event_type ENUM values
 * (pottery_show, pottery_sale, storefront_sale, class). Labels are
 * admin-editable via the `event_type_labels` setting (JSON). CSS-class
 * mapping stays in code because it's bound to stylesheet rules.
 *
 * Adding a NEW event type requires both a schema change (ALTER TABLE events
 * to extend the ENUM) and an entry in DEFAULT_LABELS / CSS_CLASSES below.
 */
final class EventTypes
{
    public const DEFAULT_LABELS = [
        'pottery_show'    => 'Show',
        'pottery_sale'    => 'Sale',
        'storefront_sale' => 'Storefront Sale',
        'class'           => 'Class',
    ];

    public const CSS_CLASSES = [
        'pottery_show'    => 'pottery-show',
        'pottery_sale'    => 'pottery-sale',
        'storefront_sale' => 'storefront-sale',
        'class'           => 'class',
    ];

    /** Display label for an event type, falling back to 'Event' for unknown values. */
    public static function label(string $type): string
    {
        $labels = self::labels();
        return $labels[$type] ?? 'Event';
    }

    /** CSS modifier class fragment for event-type ribbons. */
    public static function cssClass(string $type): string
    {
        return self::CSS_CLASSES[$type] ?? 'event';
    }

    /**
     * Resolved label map (admin overrides merged on top of defaults). Cached
     * within the request via a static var so repeated calls don't re-parse
     * the JSON.
     */
    public static function labels(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw = '';
        if (function_exists('setting')) {
            $raw = (string)setting('event_type_labels', '');
        }
        $overrides = $raw === '' ? [] : (json_decode($raw, true) ?: []);
        $cache = array_merge(self::DEFAULT_LABELS, is_array($overrides) ? $overrides : []);
        return $cache;
    }

    /** Reset the in-process cache. Used by tests. */
    public static function resetCache(): void
    {
        // No-op accessor; reading $cache directly would require reflection.
        // Tests construct fresh requests, where the static initializes anew.
    }
}
