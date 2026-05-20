<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$socialLinks = Database::fetchAll("SELECT * FROM social_links WHERE active = 1 ORDER BY sort_order ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(PageText::get('titles', 'about')) ?> — <?= e(setting('site_name')) ?></title>
    <?= PageMeta::renderHead([
        'title'       => PageText::get('titles', 'about') . ' — ' . setting('site_name', 'My Pottery'),
        'description' => trim((string)setting('bio', '')) !== '' ? mb_substr(trim((string)setting('bio', '')), 0, 200) : (string)setting('tagline', ''),
        'image'       => setting('profile_photo', '') ?: setting('hero_image', ''),
    ]) ?>
    <link rel="stylesheet" href="/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/favicon-512.png">
    <link rel="apple-touch-icon" href="/favicon-512.png">
</head>
<body>
<?php include __DIR__ . '/../templates/nav.php'; ?>

<div class="page-header">
    <div class="container">
        <h1 class="page-header__title"><?= e(PageText::get('about', 'header_title')) ?></h1>
    </div>
</div>

<section class="section about-page">
    <div class="container about-page__inner">
        <div class="about-page__text">
            <span class="eyebrow"><?= e(PageText::get('about', 'eyebrow_story')) ?></span>
            <h2><?= e(setting('site_name')) ?></h2>
            <div class="about-page__bio">
                <?php
                $bio = setting('bio', '');
                // Support line breaks in bio
                echo nl2br(e($bio));
                ?>
            </div>

            <?php if (PageSections::isVisible('about', 'contact') && setting('contact_email')): ?>
            <div class="about-page__contact">
                <span class="eyebrow"><?= e(PageText::get('about', 'eyebrow_contact')) ?></span>
                <a href="mailto:<?= e(setting('contact_email')) ?>" class="btn btn--primary">
                    <?= e(PageText::get('about', 'cta_email')) ?>
                </a>
            </div>
            <?php endif; ?>

            <?php if (PageSections::isVisible('about', 'social_links') && !empty($socialLinks)): ?>
            <div class="about-page__social">
                <span class="eyebrow"><?= e(PageText::get('about', 'eyebrow_social')) ?></span>
                <div class="social-links-list">
                    <?php foreach ($socialLinks as $link): ?>
                    <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener" class="social-link-item">
                        <?= getSocialIcon($link['platform']); ?>
                        <span><?= $link['handle'] ? '@' . e($link['handle']) : e($link['platform']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="about-page__decoration">
            <?php $profilePhoto = setting('profile_photo', ''); ?>
            <?php if ($profilePhoto): ?>
            <div class="about-page__photo-wrap">
                <img src="/uploads/<?= e($profilePhoto) ?>" alt="<?= e(setting('site_name')) ?>" class="about-page__photo">
            </div>
            <?php else: ?>
            <div class="folk-wheel">
                <div class="folk-wheel__outer"></div>
                <div class="folk-wheel__spokes"></div>
                <div class="folk-wheel__inner"></div>
                <div class="folk-wheel__dot"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="/js/main.js"></script>
</body>
</html>

