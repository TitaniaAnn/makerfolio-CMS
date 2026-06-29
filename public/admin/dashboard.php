<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/AnnouncementSocialMedia.php';
Auth::requireLogin();

$igExpiry = AnnouncementSocialMedia::getInstagramTokenExpiry();
$igToken  = AnnouncementSocialMedia::getInstagramAccessToken();
$igWarning = null;
if (!empty($igToken)) {
    if ($igExpiry === null) {
        $igWarning = 'Instagram token expiry is unknown — visit Social Tokens to refresh and capture the next expiry.';
    } else {
        $now = new DateTimeImmutable();
        if ($igExpiry < $now) {
            $igWarning = 'Instagram token expired on ' . $igExpiry->format('Y-m-d') . '. Posts will fail until you generate a new token.';
        } elseif ($igExpiry->diff($now)->days <= 7) {
            $igWarning = 'Instagram token expires on ' . $igExpiry->format('Y-m-d') . ' — refresh it from Social Tokens.';
        }
    }
}

$stats = [
    'piece'     => Database::fetchOne("SELECT COUNT(*) as n FROM piece")['n'] ?? 0,
    'products'  => Database::fetchOne("SELECT COUNT(*) as n FROM products")['n'] ?? 0,
    'featured'  => Database::fetchOne("SELECT COUNT(*) as n FROM piece WHERE featured = 1")['n'] ?? 0,
    'available' => Database::fetchOne("SELECT COUNT(*) as n FROM products WHERE status = 'available'")['n'] ?? 0,
    'orders_new'=> Database::fetchOne("SELECT COUNT(*) as n FROM orders WHERE status = 'paid'")['n'] ?? 0,
    'revenue'   => Database::fetchOne("SELECT COALESCE(SUM(product_price * quantity),0) as n FROM orders WHERE status IN ('paid','shipped')")['n'] ?? 0,
    'templates' => Database::fetchOne("SELECT COUNT(*) as n FROM piece_templates")['n'] ?? 0,
];
$recent = Database::fetchAll("SELECT * FROM piece ORDER BY created_at DESC LIMIT 5");
$user = Auth::getUser();

// Onboarding checklist — hidden once the admin clicks Dismiss (per-install
// flag in settings) OR once every item is done. Each "done" check uses
// detectable site state so adopters get automatic ticks as they fill things in.
$onboardingDismissed = setting('onboarding_dismissed', '0') === '1';
$onboardingChecks = [
    [
        'label' => 'Set your site name and tagline',
        'done'  => setting('site_name', '') !== '' && setting('site_name', '') !== 'My Pottery',
        'href'  => '/admin/settings/index.php',
    ],
    [
        'label' => 'Add a contact email',
        'done'  => setting('contact_email', '') !== '',
        'href'  => '/admin/settings/index.php',
    ],
    [
        'label' => 'Write your About copy',
        'done'  => setting('bio', '') !== '' || setting('about_text', '') !== '',
        'href'  => '/admin/settings/index.php',
    ],
    [
        'label' => 'Upload your first pottery piece',
        'done'  => (int)$stats['piece'] > 0,
        'href'  => '/admin/pieces/add.php',
    ],
    [
        'label' => 'Customize the theme',
        'done'  => setting('theme_preset', '') !== '' && setting('theme_preset', '') !== 'terra-gold',
        'href'  => '/admin/settings/theme.php',
    ],
];
$onboardingDone  = array_sum(array_map(fn($c) => $c['done'] ? 1 : 0, $onboardingChecks));
$onboardingTotal = count($onboardingChecks);
$showOnboarding  = !$onboardingDismissed && $onboardingDone < $onboardingTotal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<main class="admin-main">
    <?php include __DIR__ . '/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="admin-page-header">
            <h1>Dashboard</h1>
            <p>Welcome back, <?= e($user['name'] ?? 'Admin') ?>!</p>
        </div>

        <?php if ($igWarning): ?>
        <div class="alert alert--error">
            <?= e($igWarning) ?>
            <a href="/admin/social/tokens" style="margin-left:.5rem;">Open Social Tokens →</a>
        </div>
        <?php endif; ?>

        <?php if ($showOnboarding): ?>
        <div class="onboarding-card">
            <div class="onboarding-card__header">
                <div>
                    <h2 class="onboarding-card__title">Welcome — let's get your site set up</h2>
                    <p class="onboarding-card__subtitle"><?= $onboardingDone ?> of <?= $onboardingTotal ?> complete</p>
                </div>
                <form method="post" action="/admin/onboarding-dismiss" class="onboarding-card__dismiss-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="onboarding-card__dismiss" title="Hide this card">Dismiss</button>
                </form>
            </div>
            <div class="onboarding-card__progress">
                <div class="onboarding-card__progress-bar" style="width: <?= (int)(($onboardingDone / max(1,$onboardingTotal)) * 100) ?>%;"></div>
            </div>
            <ul class="onboarding-card__list">
                <?php foreach ($onboardingChecks as $check): ?>
                    <li class="onboarding-card__item <?= $check['done'] ? 'is-done' : '' ?>">
                        <span class="onboarding-card__mark"><?= $check['done'] ? '✓' : '○' ?></span>
                        <?php if ($check['done']): ?>
                            <span class="onboarding-card__label"><?= e($check['label']) ?></span>
                        <?php else: ?>
                            <a href="<?= e($check['href']) ?>" class="onboarding-card__label"><?= e($check['label']) ?> →</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card__num"><?= $stats['piece'] ?></div>
                <div class="stat-card__label">Portfolio Pieces</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__num"><?= $stats['available'] ?></div>
                <div class="stat-card__label">Items for Sale</div>
            </div>
            <div class="stat-card <?= $stats['orders_new'] > 0 ? 'stat-card--alert' : '' ?>">
                <div class="stat-card__num"><?= $stats['orders_new'] ?></div>
                <div class="stat-card__label">New Orders <small>(needs shipping)</small></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__num">$<?= number_format($stats['revenue'], 0) ?></div>
                <div class="stat-card__label">Total Revenue</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__num"><?= $stats['templates'] ?></div>
                <div class="stat-card__label">Templates</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h2>Quick Add</h2>
            <div class="quick-actions__grid">
                <a href="/admin/orders/" class="quick-action-btn">
                    <span class="quick-action-btn__icon">📦</span>
                    <span>View Orders</span>
                </a>
                <a href="/admin/pieces/add" class="quick-action-btn">
                    <span class="quick-action-btn__icon">🏺</span>
                    <span>Add Pottery Piece</span>
                </a>
                <a href="/admin/shop/add-product" class="quick-action-btn">
                    <span class="quick-action-btn__icon">🛒</span>
                    <span>Add Shop Product</span>
                </a>
                <a href="/admin/social/" class="quick-action-btn">
                    <span class="quick-action-btn__icon">📸</span>
                    <span>Manage Social Posts</span>
                </a>
                <a href="/admin/templates/add" class="quick-action-btn">
                    <span class="quick-action-btn__icon">📄</span>
                    <span>Add Template</span>
                </a>
                <a href="/admin/settings/" class="quick-action-btn">
                    <span class="quick-action-btn__icon">⚙️</span>
                    <span>Site Settings</span>
                </a>
            </div>
        </div>

        <!-- Recent Pottery -->
        <?php if (!empty($recent)): ?>
        <div class="admin-section">
            <div class="admin-section__header">
                <h2>Recent Pieces</h2>
                <a href="/admin/pieces/" class="admin-link">Manage all →</a>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Title</th>
                            <th>Technique</th>
                            <th>Featured</th>
                            <th>Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $p): ?>
                        <tr>
                            <td>
                                <img src="/uploads/<?= e($p['image_thumb'] ?? $p['image_path']) ?>"
                                     alt="<?= e($p['title']) ?>" class="admin-table__thumb">
                            </td>
                            <td><?= e($p['title']) ?></td>
                            <td><?= e($p['technique'] ?? '—') ?></td>
                            <td><?= $p['featured'] ? '⭐' : '—' ?></td>
                            <td><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                            <td>
                                <a href="/admin/pieces/edit?id=<?= $p['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                                <a href="/admin/pieces/delete?id=<?= $p['id'] ?>&csrf=<?= e(csrf_token()) ?>" class="admin-btn admin-btn--sm admin-btn--danger"
                                   data-confirm="Delete this piece?">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
