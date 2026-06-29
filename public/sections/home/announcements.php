<?php
// Homepage section: Announcements grid. Hidden when no published announcements exist.
// Variables in scope: $announcements
if (empty($announcements)) return;
?>
<section class="section announcements">
    <div class="container">
        <div class="section__header">
            <h2 class="section__title"><?= e(PageText::get('home', 'announcements_title')) ?></h2>
        </div>
        <div class="grid grid--3">
            <?php foreach ($announcements as $ann): ?>
            <article class="announcement-card">
                <a class="announcement-card__stretched-link" href="/announcement?id=<?= (int)$ann['id'] ?>" aria-label="Open announcement: <?= e($ann['title']) ?>"></a>
                <?php if (!empty($ann['image_thumb']) || !empty($ann['image_path'])): ?>
                <div class="announcement-card__img-wrap">
                    <img
                        src="<?= e(UPLOAD_URL . ($ann['image_thumb'] ?? $ann['image_path'])) ?>"
                        alt="<?= e($ann['image_alt'] ?: $ann['title']) ?>"
                        loading="lazy"
                    >
                </div>
                <?php endif; ?>

                <div class="announcement-card__content">
                    <h3 class="announcement-card__title"><?= e($ann['title']) ?></h3>

                    <?php $announcementText = trim((string)($ann['description'] ?? $ann['content'] ?? '')); ?>
                    <?php if ($announcementText !== ''): ?>
                    <p class="announcement-card__desc">
                        <?php
                            $desc = $announcementText;
                            if (strlen($desc) > 150) {
                                $desc = substr($desc, 0, 150) . '...';
                            }
                            echo e($desc);
                        ?>
                    </p>
                    <?php endif; ?>

                    <?php
                        $links = Database::fetchAll(
                            "SELECT entity_type, entity_id FROM announcement_links WHERE announcement_id = ? ORDER BY sort_order ASC LIMIT 3",
                            [$ann['id']]
                        );
                    ?>
                    <?php if (!empty($links)): ?>
                    <div class="announcement-card__links">
                        <?php foreach ($links as $link): ?>
                            <?php if ($link['entity_type'] === 'event'): ?>
                                <?php $event = Database::fetchOne("SELECT id, name, url FROM events WHERE id = ?", [$link['entity_id']]); ?>
                                <?php if ($event): ?>
                                <a href="<?= e($event['url'] ?? '/events#event-' . $event['id']) ?>" class="announcement-card__link-tag">📅 <?= e($event['name']) ?></a>
                                <?php endif; ?>
                            <?php elseif ($link['entity_type'] === 'piece'): ?>
                                <?php $pottery = Database::fetchOne("SELECT id, title FROM piece WHERE id = ?", [$link['entity_id']]); ?>
                                <?php if ($pottery): ?>
                                <a href="/portfolio#piece-<?= $pottery['id'] ?>" class="announcement-card__link-tag">🏺 <?= e($pottery['title']) ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
