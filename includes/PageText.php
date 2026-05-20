<?php
/**
 * PageText — admin-editable display strings for public pages.
 *
 * Defaults live here in code so adding a new section to a public page is a
 * one-line edit. Overrides live in the `settings` table as rows keyed
 * `text.{group}.{key}` (e.g. `text.home.featured_work_title`). A blank or
 * missing override falls back to the default — the admin form treats this as
 * "use the built-in label" and never persists blank rows.
 *
 * Pattern at the call site:
 *
 *     <?= e(PageText::get('home', 'featured_work_title')) ?>
 *
 * Add a new string:
 *   1. Add a key + default below.
 *   2. Render it via PageText::get(...) in the public file.
 *   3. The admin page (/admin/settings/page-text.php) auto-renders a field
 *      for it grouped by the key prefix.
 */
final class PageText
{
    /**
     * Default copy keyed by group → key → string. Adding a new group adds a
     * new section in the admin editor; adding a new key adds a new field.
     */
    public const DEFAULTS = [
        'titles' => [
            // <title> tag prefixes — combined with site_name in each page's <head>.
            // E.g. "Portfolio — {site_name}". Set blank to use just the site_name.
            'portfolio'    => 'Portfolio',
            'shop'         => 'Shop',
            'about'        => 'About',
            'events'       => 'Events',
            'templates'    => 'Templates',
            'privacy'      => 'Privacy Policy',
            'order_done'   => 'Order Confirmed',
            'order_cancel' => 'Checkout Cancelled',
            'announcement_not_found' => 'Announcement Not Found',
        ],
        'checkout' => [
            // Flash error messages from public/shop/checkout.php — admin-trusted
            // strings shown to the buyer when the checkout flow can't proceed.
            'disabled'         => 'Online checkout is not available yet — please contact me to purchase.',
            'item_unavailable' => 'Sorry, that item is no longer available.',
            'not_enough_stock' => 'Not enough stock — only {quantity} left.',
            'no_price'         => 'This item does not have a price set. Please contact me to purchase.',
            'sdk_missing'      => 'Online checkout is being set up. Please contact me directly to purchase.',
            'generic_error'    => 'Something went wrong with checkout. Please try again or contact me directly.',
        ],
        'nav' => [
            'home'      => 'Home',
            'portfolio' => 'Portfolio',
            'events'    => 'Events',
            'shop'      => 'Shop',
            'about'     => 'About',
            'templates' => 'Templates',
        ],
        'footer' => [
            'portfolio' => 'Portfolio',
            'events'    => 'Events',
            'shop'      => 'Shop',
            'about'     => 'About',
            'rights'    => 'All rights reserved.',
        ],
        'home' => [
            'hero_btn_portfolio'    => 'View Portfolio',
            'hero_btn_shop'         => 'Visit the Shop',
            'hero_scroll_hint'      => 'scroll on down',
            'featured_work_title'   => 'Featured Work',
            'featured_work_link'    => 'View all pieces →',
            'pottery_overlay_view'  => 'View Piece',
            'announcements_title'   => 'Announcements',
            'events_title'          => 'Events',
            'events_link'           => 'View all events →',
            'events_empty'          => 'More events coming soon',
            'event_date_tba'        => 'Date to be announced',
            'event_card_cta'        => 'Learn More',
            'about_strip_eyebrow'   => 'About the Studio',
            'about_strip_cta'       => 'My Story',
            'social_title'          => 'From the Studio',
            'shop_teaser_eyebrow'   => 'The Shop',
            'shop_teaser_title'     => 'Own a Piece of the Studio',
            'shop_teaser_btn_pots'  => 'Original Pots',
            'shop_teaser_btn_merch' => 'Merch',
        ],
        'portfolio' => [
            'header_title' => 'Portfolio',
            'header_sub'   => 'A collection of handcrafted ceramics',
            'filter_all'   => 'All',
            'empty'        => 'No pieces to display yet. Check back soon!',
        ],
        'shop' => [
            'header_title'      => 'Shop',
            'filter_all'        => 'All',
            'filter_pots'       => 'Original Pots',
            'filter_merch'      => 'Merch',
            'empty'             => 'No products available yet. Check back soon!',
            'badge_sold'        => 'Sold',
            'badge_coming_soon' => 'Coming Soon',
            'status_coming_soon'=> 'Coming soon',
            'btn_buy_printful'  => 'Order on Printful ↗',
            'btn_buy_external'  => 'Buy Now ↗',
            'btn_buy_now'       => 'Buy Now',
            'btn_enquire'       => 'Enquire',
            'contact_to_purchase' => 'Contact to purchase',
            'printful_badge'    => 'Printed & shipped by Printful',
        ],
        'about' => [
            'header_title'   => 'About',
            'eyebrow_story'  => 'My Story',
            'eyebrow_contact'=> 'Get in Touch',
            'eyebrow_social' => 'Follow My Work',
            'cta_email'      => 'Email Me',
        ],
        'events' => [
            'header_title' => 'Events',
            'header_sub'   => 'Shows, sales, storefronts, and classes',
            'empty'        => 'More events coming soon',
            'date_tba'     => 'Date to be announced',
            'cta_learn'    => 'Learn More',
        ],
        'templates' => [
            'header_title' => 'Pottery Templates',
            'header_sub'   => 'Free patterns and guides to download and use in your studio',
            'filter_all'   => 'All',
            'empty'        => 'No templates available yet — check back soon!',
            'btn_download' => 'Download',
        ],
        'announcement' => [
            'not_found_title' => 'Announcement Not Found',
            'not_found_body'  => 'This announcement is unavailable or not published yet.',
            'back_home'       => 'Back Home',
            'meta_published'  => 'Published',
            'related_events'  => 'Related Events',
            'related_pieces'  => 'Related Pieces',
        ],
        'order' => [
            'success_title'   => 'Order Confirmed!',
            'success_script'  => 'Thank you so much',
            'success_body'    => "Your payment went through and your pot is reserved for you. I'll carefully pack it up and send it your way soon.",
            'success_for'     => 'For:',
            'success_email_to'=> 'Confirmation sent to:',
            'success_ship_to' => 'Shipping to:',
            'success_followup'=> "You'll receive a shipping confirmation email with tracking details once your order is on its way.",
            'btn_back_shop'   => 'Back to Shop',
            'btn_return_home' => 'Return Home',
            'cancel_title'    => 'No worries!',
            'cancel_sub'      => 'Your checkout was cancelled',
            'cancel_body'     => 'Nothing was charged. The item is still available if you change your mind.',
            'cancel_ask'      => 'Ask me a question',
        ],
    ];

    /**
     * Keys flagged as "high-impact" — the strings a new adopter most
     * likely wants to customize first (CTAs, subtitles, post-purchase copy).
     * The admin page-text editor surfaces these in a "Top priorities" panel
     * and stars them in the main list so adopters aren't faced with 50+ rows
     * of equally-weighted fields. Add/remove entries here as the public-page
     * copy evolves.
     */
    public const HIGH_IMPACT = [
        'footer.rights',
        'home.hero_btn_portfolio',
        'home.hero_btn_shop',
        'home.featured_work_title',
        'home.about_strip_cta',
        'home.shop_teaser_title',
        'portfolio.header_sub',
        'shop.empty',
        'about.eyebrow_story',
        'about.cta_email',
        'events.header_sub',
        'order.success_title',
        'order.success_body',
    ];

    public static function isHighImpact(string $group, string $key): bool
    {
        return in_array("{$group}.{$key}", self::HIGH_IMPACT, true);
    }

    /** Returns the override (if non-empty) or the default. Unknown keys throw. */
    public static function get(string $group, string $key): string
    {
        if (!isset(self::DEFAULTS[$group][$key])) {
            throw new \InvalidArgumentException("PageText: unknown key {$group}.{$key}");
        }
        $settingKey = self::settingKey($group, $key);
        $override   = '';
        if (function_exists('setting')) {
            $override = trim((string)setting($settingKey, ''));
        }
        return $override !== '' ? $override : self::DEFAULTS[$group][$key];
    }

    /** Convention for the row in the `settings` table that holds an override. */
    public static function settingKey(string $group, string $key): string
    {
        return 'text.' . $group . '.' . $key;
    }

    /** All known groups in declaration order (for admin UI iteration). */
    public static function groups(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** All (group, key) pairs as flat tuples — useful for the admin save loop. */
    public static function allKeys(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $group => $keys) {
            foreach (array_keys($keys) as $k) {
                $out[] = [$group, $k];
            }
        }
        return $out;
    }
}
