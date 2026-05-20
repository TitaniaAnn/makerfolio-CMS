<?php
// Homepage section: Featured Work grid. Hidden when no featured pieces exist.
// Variables in scope: $featured
if (empty($featured)) return;
?>
<section class="section featured">
    <div class="container">
        <div class="section__header">
            <h2 class="section__title"><?= e(PageText::get('home', 'featured_work_title')) ?></h2>
            <a href="/portfolio" class="section__link"><?= e(PageText::get('home', 'featured_work_link')) ?></a>
        </div>
        <div class="grid grid--3">
            <?php foreach ($featured as $piece): ?>
            <a href="/portfolio#piece-<?= $piece['id'] ?>" class="pottery-card">
                <div class="pottery-card__img-wrap">
                    <img
                        src="/uploads/<?= e($piece['image_thumb'] ?? $piece['image_path']) ?>"
                        alt="<?= e($piece['alt_text'] ?: $piece['title']) ?>"
                        loading="lazy"
                    >
                    <div class="pottery-card__overlay">
                        <span class="pottery-card__view"><?= e(PageText::get('home', 'pottery_overlay_view')) ?></span>
                    </div>
                    <?php if (!empty($piece['event_name'])): ?>
                    <div class="pottery-card__event-ribbon pottery-card__event-ribbon--<?= e($piece['event_type'] ?? 'event') ?>">
                        <?= e($piece['event_name']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="pottery-card__info">
                    <h3 class="pottery-card__title"><?= e($piece['title']) ?></h3>
                    <?php if ($piece['technique']): ?>
                    <span class="pottery-card__tag"><?= e($piece['technique']) ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
