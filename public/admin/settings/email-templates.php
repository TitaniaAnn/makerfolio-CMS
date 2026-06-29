<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

$errors  = [];
$notices = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'send_test') {
        // Render the currently-saved template + sample variables and dispatch via Mailer::send.
        $templateKey = (string)($_POST['template_key'] ?? '');
        if (!isset(EmailTemplates::TEMPLATES[$templateKey])) {
            flash('error', 'Unknown template.');
            redirect(SITE_URL . '/admin/settings/email-templates');
        }
        $to = trim((string)setting('contact_email', ''));
        if ($to === '') {
            flash('error', 'No contact_email is set — add one in Site Settings before sending a test.');
            redirect(SITE_URL . '/admin/settings/email-templates');
        }

        $vars    = EmailTemplates::PREVIEW_SAMPLES;
        $subject = '[TEST] ' . EmailTemplates::render($templateKey, 'subject', $vars);
        $body    = "(This is a test send from the admin Email Templates page. "
                 . "Sample variable values are filled in.)\n\n"
                 . "----------\n\n"
                 . EmailTemplates::render($templateKey, 'body', $vars);

        // Reflection on the private Mailer::send isn't worth the gymnastics —
        // just call through mail() with the same header layout the real Mailer uses.
        $fromName = preg_replace('/[\r\n\x00-\x1F]+/', ' ', (string)setting('site_name', 'My Pottery'));
        $headers  = "From: $fromName <$to>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = @mail($to, $subject, $body, $headers);

        ActivityLog::log('settings.email_templates_send_test', 'email_template', $templateKey, ['sent' => (bool)$sent, 'to' => $to]);
        if ($sent) {
            flash('success', "Test '{$templateKey}' email queued via mail() to {$to}. Check your inbox + spam folder.");
        } else {
            flash('error', "mail() returned false — your host may not have a working MTA. Check PHP error logs or swap Mailer for a transactional provider (Postmark, Resend, etc).");
        }
        redirect(SITE_URL . '/admin/settings/email-templates');
    }

    // Default action: save overrides.
    foreach (array_keys(EmailTemplates::TEMPLATES) as $templateKey) {
        foreach (['subject', 'body'] as $field) {
            $supplied = trim((string)($_POST['email'][$templateKey][$field] ?? ''));
            $default  = EmailTemplates::TEMPLATES[$templateKey][$field];
            $key      = EmailTemplates::settingKey($templateKey, $field);

            if ($supplied === '' || $supplied === trim($default)) {
                // Default → drop the override row so the catalog default is used.
                Database::query("DELETE FROM settings WHERE setting_key = ?", [$key]);
            } else {
                Database::query(
                    "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$key, $supplied]
                );
            }
        }
    }
    ActivityLog::log('settings.email_templates_save');
    flash('success', 'Email templates saved.');
    redirect(SITE_URL . '/admin/settings/email-templates');
}

// Load current override values.
$overrides = [];
$rows = Database::fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'email.%'");
foreach ($rows as $row) {
    $overrides[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Templates — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/css/pages/settings-email-templates.css">
</head>
<body>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>
<main class="admin-main">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>
    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Email Templates</h1>
            <a href="/admin/settings/" class="admin-btn">Back to Settings</a>
        </div>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <p class="u-muted-fog">
            Edit the subject and body of each outbound email. Use <code>{variable}</code>
            tokens — see the side panel for what's available per template. Leave a field
            blank (or matching the default) to restore the built-in copy. Click a variable
            name to copy it.
        </p>

        <form method="POST" id="email-form" data-samples="<?= e(json_encode(EmailTemplates::PREVIEW_SAMPLES, JSON_UNESCAPED_SLASHES)) ?>">
            <?= csrf_field() ?>

            <?php foreach (EmailTemplates::TEMPLATES as $templateKey => $tpl):
                $subjectKey = EmailTemplates::settingKey($templateKey, 'subject');
                $bodyKey    = EmailTemplates::settingKey($templateKey, 'body');
                $subject    = $overrides[$subjectKey] ?? '';
                $body       = $overrides[$bodyKey]    ?? '';
            ?>
                <section class="et-template" id="tpl-<?= e($templateKey) ?>"
                         data-template="<?= e($templateKey) ?>"
                         data-default-subject="<?= e($tpl['subject']) ?>"
                         data-default-body="<?= e($tpl['body']) ?>">
                    <div class="et-template__head">
                        <div class="et-template__head-main">
                            <h2><?= e($tpl['label']) ?></h2>
                            <p class="et-template__desc"><?= e($tpl['description']) ?></p>
                        </div>
                        <button type="button" class="admin-btn admin-btn--sm et-send-test-btn"
                                data-action="send-test" data-template-key="<?= e($templateKey) ?>"
                                title="Send a copy of the currently-saved template (with sample variables filled in) to your contact_email address. Useful for verifying mail() actually works on this host.">
                            Send test
                        </button>
                    </div>
                    <form id="send-test-<?= e($templateKey) ?>" method="POST" class="is-hidden"
                          data-confirm="Send a [TEST] copy of this template to <?= e(setting('contact_email', '(no contact email set)')) ?>?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_test">
                        <input type="hidden" name="template_key" value="<?= e($templateKey) ?>">
                    </form>

                    <div class="et-grid">
                        <div class="et-fields">
                            <label for="subject_<?= e($templateKey) ?>">Subject</label>
                            <input type="text"
                                   id="subject_<?= e($templateKey) ?>"
                                   name="email[<?= e($templateKey) ?>][subject]"
                                   value="<?= e($subject) ?>"
                                   placeholder="<?= e($tpl['subject']) ?>"
                                   data-field="subject">

                            <label for="body_<?= e($templateKey) ?>" class="et-body-label">Body</label>
                            <textarea id="body_<?= e($templateKey) ?>"
                                      name="email[<?= e($templateKey) ?>][body]"
                                      placeholder="<?= e($tpl['body']) ?>"
                                      data-field="body"
                                      rows="14"><?= e($body) ?></textarea>

                            <div class="et-preview">
                                <div class="et-preview__label">Preview (with sample data)</div>
                                <div class="et-preview__subject" data-preview="subject">…</div>
                                <pre class="et-preview__body" data-preview="body">…</pre>
                            </div>
                        </div>

                        <aside class="et-vars">
                            <h3>Available variables</h3>
                            <dl>
                                <?php foreach ($tpl['variables'] as $var => $desc): ?>
                                    <dt data-action="copy-var" data-var="<?= e($var) ?>" title="Click to copy">{<?= e($var) ?>}</dt>
                                    <dd><?= e($desc) ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        </aside>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="et-form-actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save All</button>
                <a href="/admin/settings/email-templates" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
</main>
<script src="/admin/js/email-templates.js"></script>
</body>
</html>
