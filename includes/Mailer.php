<?php
// includes/Mailer.php — Simple PHP mail wrapper for order notifications.
// Subject + body text comes from EmailTemplates (admin-editable). This file
// just assembles the variable bag and dispatches via mail().

class Mailer {

    /**
     * Send order notification to the shop owner.
     */
    public static function notifyOwnerOfSale(array $order): void {
        $to = setting('contact_email');
        if (!$to) return; // No email configured

        $shipping = implode(', ', array_filter([
            $order['shipping_line1'] ?? '',
            $order['shipping_line2'] ?? '',
            $order['shipping_city'] ?? '',
            $order['shipping_state'] ?? '',
            $order['shipping_postal_code'] ?? '',
            $order['shipping_country'] ?? '',
        ]));

        $vars = [
            'site_name'        => setting('site_name', 'My Pottery'),
            'product_name'     => (string)($order['product_name'] ?? ''),
            'product_price'    => number_format((float)($order['product_price'] ?? 0), 2),
            'quantity'         => (string)($order['quantity'] ?? 1),
            'order_id'         => (string)($order['id'] ?? ''),
            'customer_name'    => (string)($order['customer_name'] ?? ''),
            'customer_email'   => (string)($order['customer_email'] ?? ''),
            'shipping_address' => $shipping,
            'admin_url'        => SITE_URL . '/admin/orders/index.php',
        ];

        $subject = EmailTemplates::render('owner_new_order', 'subject', $vars);
        $body    = EmailTemplates::render('owner_new_order', 'body',    $vars);

        self::send($to, $subject, $body);
    }

    /**
     * Send receipt to customer.
     */
    public static function sendCustomerReceipt(array $order): void {
        if (empty($order['customer_email'])) return;

        $price = (float)($order['product_price'] ?? 0);
        $qty   = (int)($order['quantity'] ?? 1);

        $vars = [
            'customer_name' => (string)($order['customer_name'] ?? ''),
            'product_name'  => (string)($order['product_name'] ?? ''),
            'total'         => number_format($price * $qty, 2),
            'contact_email' => setting('contact_email'),
            'site_name'     => setting('site_name', 'My Pottery'),
        ];

        $subject = EmailTemplates::render('customer_receipt', 'subject', $vars);
        $body    = EmailTemplates::render('customer_receipt', 'body',    $vars);

        self::send($order['customer_email'], $subject, $body, setting('contact_email'));
    }

    /**
     * Notify customer when order ships.
     */
    public static function notifyShipped(array $order): void {
        if (empty($order['customer_email'])) return;

        // The tracking line is conditional — render it here so the template
        // just embeds {tracking_block} verbatim. Empty string when no tracking.
        $tracking = '';
        if (!empty($order['tracking_number'])) {
            $tracking = "\nTracking: " . ($order['tracking_carrier'] ?? '') . " — " . $order['tracking_number'] . "\n";
        }

        $vars = [
            'customer_name'  => (string)($order['customer_name'] ?? ''),
            'product_name'   => (string)($order['product_name'] ?? ''),
            'tracking_block' => $tracking,
            'site_name'      => setting('site_name', 'My Pottery'),
        ];

        $subject = EmailTemplates::render('customer_shipped', 'subject', $vars);
        $body    = EmailTemplates::render('customer_shipped', 'body',    $vars);

        self::send($order['customer_email'], $subject, $body, setting('contact_email'));
    }

    /**
     * Send a password reset email to an admin. Returns true if mail() accepted
     * the message; false otherwise. The caller is responsible for token
     * generation + DB insertion — this is just the dispatch.
     *
     * Admin email is resolved from admin_users.email; falls back to the same
     * row's google_email if `email` is null. Returns false when no usable
     * recipient address exists.
     */
    public static function sendPasswordReset(int $adminId, string $rawToken, int $ttlMinutes = 60): bool {
        $admin = Database::fetchOne(
            "SELECT username, email, google_email FROM admin_users WHERE id = ? LIMIT 1",
            [$adminId]
        );
        if (!$admin) return false;

        $to = trim((string)($admin['email'] ?? '')) ?: trim((string)($admin['google_email'] ?? ''));
        if ($to === '') return false;

        $vars = [
            'username'           => (string)($admin['username'] ?? 'admin'),
            'site_name'          => setting('site_name', 'My Pottery'),
            'reset_url'          => rtrim(SITE_URL, '/') . '/admin/auth/reset-password.php?token=' . urlencode($rawToken),
            'expires_in_minutes' => (string)$ttlMinutes,
        ];

        $subject = EmailTemplates::render('admin_password_reset', 'subject', $vars);
        $body    = EmailTemplates::render('admin_password_reset', 'body',    $vars);

        $fromName    = self::sanitizeHeader(setting('site_name', 'My Pottery'));
        $fromAddress = self::sanitizeHeader(setting('contact_email')) ?: $to;
        $subject     = self::sanitizeHeader($subject);
        $to          = self::sanitizeHeader($to);

        $headers  = "From: {$fromName} <{$fromAddress}>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        return (bool)@mail($to, $subject, $body, $headers);
    }

    /**
     * Send a custom message to a beta tester. Subject + body are supplied by
     * the caller; we just personalize the greeting + signature.
     */
    public static function sendBetaEmail(string $toEmail, string $toName, string $subject, string $body): void {
        $personalised = "Hi {$toName},\n\n" . $body . "\n\n— " . setting('site_name', 'My Pottery Studio') . " Beta Team";
        self::send($toEmail, $subject, $personalised, setting('contact_email'));
    }

    private static function send(string $to, string $subject, string $body, string $replyTo = ''): void {
        $fromName    = self::sanitizeHeader(setting('site_name', 'My Pottery'));
        $fromAddress = self::sanitizeHeader(setting('contact_email'));
        $subject     = self::sanitizeHeader($subject);
        $to          = self::sanitizeHeader($to);

        $headers  = "From: {$fromName} <{$fromAddress}>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: " . self::sanitizeHeader($replyTo) . "\r\n";
        }

        // PHP mail() — works on most shared hosts.
        // For production, swap this for a transactional provider like:
        // - Postmark: https://postmarkapp.com ($0 for 100/mo free)
        // - Resend: https://resend.com (3,000/mo free)
        // - Mailgun, SendGrid, etc.
        @mail($to, $subject, $body, $headers);
    }

    private static function sanitizeHeader(string $value): string {
        // Strip CRLF and any control characters that could split headers.
        return trim(preg_replace('/[\r\n\x00-\x1F]+/', ' ', $value));
    }
}
