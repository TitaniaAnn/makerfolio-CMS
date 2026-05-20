<?php
/**
 * EmailTemplates — admin-editable subject + body for outbound mail.
 *
 * Each template has:
 *   - A canonical default `subject` + `body` (defined in TEMPLATES below).
 *   - A list of variables the caller passes in at render time. Substitution
 *     uses `{var_name}` tokens; unknown variables are left in the output
 *     verbatim so missing data is visible to the admin during preview.
 *
 * Overrides live as rows in the `settings` table:
 *   email.{template_key}.subject
 *   email.{template_key}.body
 *
 * A blank or "same as default" override is treated as "use default" and the
 * settings row is removed (kept in sync with the PageText convention).
 *
 * Call sites: only [includes/Mailer.php](includes/Mailer.php).
 */
final class EmailTemplates
{
    /**
     * Catalog of email templates. Keys: stable slugs used in both the settings
     * row prefix and the admin UI dispatcher. Values:
     *   - label:       human label for the admin UI
     *   - description: one-line "when does this get sent?"
     *   - subject:     default subject (variables allowed)
     *   - body:        default body  (variables allowed; preserve \n line breaks)
     *   - variables:   ordered list of var_name => "what it is" (for the admin
     *                  available-variables panel + the preview sample values)
     */
    public const TEMPLATES = [
        'owner_new_order' => [
            'label'       => 'Owner: new order notification',
            'description' => 'Sent to the shop owner (contact_email) when a Stripe order is paid.',
            'subject'     => '[{site_name}] New order — {product_name}',
            'body'        => "You have a new order!\n\n"
                          . "Order #: {order_id}\n"
                          . "Item: {product_name}\n"
                          . "Price: \${product_price}\n"
                          . "Qty: {quantity}\n\n"
                          . "Customer: {customer_name}\n"
                          . "Email: {customer_email}\n"
                          . "Ship to: {shipping_address}\n\n"
                          . "Manage orders: {admin_url}\n",
            'variables'   => [
                'site_name'         => 'Your site name',
                'product_name'      => 'The item that was ordered',
                'product_price'     => 'Per-item price (formatted, no currency symbol)',
                'quantity'          => 'How many units',
                'order_id'          => 'Numeric order ID',
                'customer_name'     => "Buyer's full name",
                'customer_email'    => "Buyer's email address",
                'shipping_address'  => 'Single-line concatenated shipping address',
                'admin_url'         => 'Link to /admin/orders/index.php',
            ],
        ],
        'customer_receipt' => [
            'label'       => 'Customer: order receipt',
            'description' => 'Sent to the buyer right after a successful Stripe checkout.',
            'subject'     => 'Your order from {site_name} — thank you!',
            'body'        => "Hi {customer_name},\n\n"
                          . "Thank you so much for your order — it means the world!\n\n"
                          . "You ordered: {product_name}\n"
                          . "Total paid: \${total}\n\n"
                          . "I'll pack it up carefully and send you tracking info once it ships.\n\n"
                          . "Questions? Reply to this email or reach me at {contact_email}\n\n"
                          . "With gratitude,\n"
                          . "{site_name}\n",
            'variables'   => [
                'customer_name'  => "Buyer's full name",
                'product_name'   => 'The item that was ordered',
                'total'          => 'Total paid (price × quantity, formatted)',
                'contact_email'  => 'Your contact email (from Site Settings)',
                'site_name'      => 'Your site name',
            ],
        ],
        'customer_shipped' => [
            'label'       => 'Customer: shipping notification',
            'description' => 'Sent to the buyer when the order ships (manually triggered from /admin/orders/).',
            'subject'     => 'Your order has shipped! — {site_name}',
            'body'        => "Hi {customer_name},\n\n"
                          . "Great news — your {product_name} is on its way!\n"
                          . "{tracking_block}"
                          . "\nThank you again for supporting my work.\n\n"
                          . "{site_name}\n",
            'variables'   => [
                'customer_name'  => "Buyer's full name",
                'product_name'   => 'The item that was ordered',
                'tracking_block' => 'Pre-formatted tracking line (empty if no tracking was entered)',
                'site_name'      => 'Your site name',
            ],
        ],
        'admin_password_reset' => [
            'label'       => 'Admin: password reset link',
            'description' => 'Sent to an admin after they request a password reset on /admin/auth/forgot-password.php. Token expires after the time shown in the body.',
            'subject'     => 'Reset your {site_name} admin password',
            'body'        => "Hi {username},\n\n"
                          . "We received a request to reset the password on your {site_name} admin account.\n"
                          . "Click the link below within {expires_in_minutes} minutes to set a new one:\n\n"
                          . "{reset_url}\n\n"
                          . "If you didn't make this request, you can ignore this email — the link expires automatically and no changes will be made.\n\n"
                          . "{site_name}\n",
            'variables'   => [
                'username'           => "The admin's username",
                'site_name'          => 'Your site name',
                'reset_url'          => 'Single-use reset URL (expires after the TTL below)',
                'expires_in_minutes' => 'Number of minutes the reset link is valid for',
            ],
        ],
    ];

    /** Sample values used by the admin preview pane. Keep keys aligned with TEMPLATES[*].variables. */
    public const PREVIEW_SAMPLES = [
        'site_name'        => 'My Pottery Studio',
        'product_name'     => 'Mountain Bowl (Speckled Glaze)',
        'product_price'    => '45.00',
        'quantity'         => '1',
        'order_id'         => '42',
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'shipping_address' => '123 Main St, Springfield, IL 12345, USA',
        'admin_url'        => 'https://yourdomain.com/admin/orders/index.php',
        'total'            => '45.00',
        'contact_email'    => 'hello@yourdomain.com',
        'tracking_block'   => "\nTracking: USPS — 9405511899223344556677\n",
        'username'         => 'jane_admin',
        'reset_url'        => 'https://yourdomain.com/admin/auth/reset-password.php?token=abc123def456',
        'expires_in_minutes' => '60',
    ];

    /**
     * Returns the raw template text (override or default). Pass to render() to
     * substitute variables.
     */
    public static function get(string $templateKey, string $field): string
    {
        self::assertKnown($templateKey, $field);
        $settingKey = self::settingKey($templateKey, $field);
        $override   = '';
        if (function_exists('setting')) {
            $override = (string)setting($settingKey, '');
        }
        if ($override !== '') {
            return $override;
        }
        return self::TEMPLATES[$templateKey][$field];
    }

    /**
     * Get the template and substitute {var_name} tokens. Unknown variables are
     * left as-is in the output so missing data is visible (better than silent
     * blanks during admin preview).
     *
     * @param array<string, string|int|float> $variables
     */
    public static function render(string $templateKey, string $field, array $variables): string
    {
        $template = self::get($templateKey, $field);
        return self::substitute($template, $variables);
    }

    /** Pure substitution helper — public so the admin preview pane can call it directly. */
    public static function substitute(string $template, array $variables): string
    {
        $out = $template;
        foreach ($variables as $key => $value) {
            $out = str_replace('{' . $key . '}', (string)$value, $out);
        }
        return $out;
    }

    /** Convention for the settings row that holds an override. */
    public static function settingKey(string $templateKey, string $field): string
    {
        return 'email.' . $templateKey . '.' . $field;
    }

    /** @return array<string, array> The whole TEMPLATES catalog, for admin UI iteration. */
    public static function all(): array
    {
        return self::TEMPLATES;
    }

    /**
     * Return all setting-key prefixes used by email templates. Used by
     * ContentReset to wipe overrides without hard-coding the list.
     *
     * @return string[]
     */
    public static function allSettingKeys(): array
    {
        $keys = [];
        foreach (self::TEMPLATES as $tk => $_) {
            $keys[] = self::settingKey($tk, 'subject');
            $keys[] = self::settingKey($tk, 'body');
        }
        return $keys;
    }

    private static function assertKnown(string $templateKey, string $field): void
    {
        if (!isset(self::TEMPLATES[$templateKey])) {
            throw new \InvalidArgumentException("EmailTemplates: unknown template '$templateKey'");
        }
        if ($field !== 'subject' && $field !== 'body') {
            throw new \InvalidArgumentException("EmailTemplates: unknown field '$field' (must be 'subject' or 'body')");
        }
    }
}
