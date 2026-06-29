<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
}

$announcement = null;
if ($id > 0) {
    $announcement = Database::fetchOne(
        "SELECT *
         FROM announcements
         WHERE id = ?
           AND publish_date <= NOW()",
        [$id]
    );
}

if (!$announcement) {
    http_response_code(404);
}

$entityLinks = [];
if ($announcement) {
    $entityLinks = Database::fetchAll(
        "SELECT entity_type, entity_id
         FROM announcement_links
         WHERE announcement_id = ?
         ORDER BY sort_order ASC",
        [$announcement['id']]
    );
}

// Collect linked entity ids per type, preserving the link order
// (announcement_links is ORDER BY sort_order; the template iterates
// $linkedEvents / $linkedPottery and shows them in that order).
$eventIds = $potteryIds = [];
foreach ($entityLinks as $link) {
    if ($link['entity_type'] === 'event')   $eventIds[]   = (int)$link['entity_id'];
    if ($link['entity_type'] === 'piece') $potteryIds[] = (int)$link['entity_id'];
}

// One IN-list query per type instead of one fetchOne per link.
// Build id -> row maps so the link-order replay below stays O(N).
$eventMap = [];
if ($eventIds) {
    $ph = implode(',', array_fill(0, count($eventIds), '?'));
    foreach (Database::fetchAll(
        "SELECT id, name, url FROM events WHERE id IN ($ph)",
        $eventIds
    ) as $row) {
        $eventMap[(int)$row['id']] = $row;
    }
}
$potteryMap = [];
if ($potteryIds) {
    $ph = implode(',', array_fill(0, count($potteryIds), '?'));
    foreach (Database::fetchAll(
        "SELECT id, title FROM piece WHERE id IN ($ph)",
        $potteryIds
    ) as $row) {
        $potteryMap[(int)$row['id']] = $row;
    }
}

// Replay in original link order so the rendered output matches what
// the per-link fetchOne version produced (skipping missing ids the
// same way).
$linkedEvents = $linkedPottery = [];
foreach ($entityLinks as $link) {
    $id = (int)$link['entity_id'];
    if ($link['entity_type'] === 'event'   && isset($eventMap[$id]))   $linkedEvents[]  = $eventMap[$id];
    if ($link['entity_type'] === 'piece' && isset($potteryMap[$id])) $linkedPottery[] = $potteryMap[$id];
}

$announcementText = '';
if ($announcement) {
    $announcementText = trim((string)($announcement['description'] ?? $announcement['content'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $announcement ? e($announcement['title']) : e(PageText::get('titles', 'announcement_not_found')) ?> — <?= e(setting('site_name', 'My Pottery')) ?></title>
    <?php
    if ($announcement) {
        $annDesc = trim((string)($announcement['description'] ?? $announcement['content'] ?? ''));
        $annImg  = $announcement['image_path'] ?? $announcement['image_thumb'] ?? '';
        echo PageMeta::renderHead([
            'title'       => $announcement['title'] . ' — ' . setting('site_name', 'My Pottery'),
            'description' => $annDesc !== '' ? mb_substr($annDesc, 0, 200) : (string)setting('tagline', ''),
            'image'       => $annImg,
            'type'        => 'article',
        ]);
    } else {
        echo PageMeta::renderHead([
            'title'       => PageText::get('titles', 'announcement_not_found') . ' — ' . setting('site_name', 'My Pottery'),
            'description' => PageText::get('announcement', 'not_found_body'),
        ]);
    }
    ?>
    <link rel="stylesheet" href="/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400;1,700&family=Caveat:wght@400;600&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .announcement-detail { padding: 6rem 0 4rem; }
        .announcement-card-full {
            max-width: 900px;
            margin: 0 auto;
            background: var(--warm-white);
            border: 1px solid var(--linen);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .announcement-card-full__image { width: 100%; max-height: 420px; object-fit: cover; }
        .announcement-card-full__body { padding: 2rem; }
        .announcement-card-full__meta { color: var(--stone); font-size: .9rem; margin-bottom: .6rem; }
        .announcement-card-full__title { margin-bottom: 1rem; }
        .announcement-card-full__text { color: var(--ink-lt); white-space: pre-line; }
        .announcement-linked { margin-top: 1.5rem; display: grid; gap: 1rem; }
        .announcement-linked h3 { font-size: 1rem; margin-bottom: .35rem; }
        .announcement-linked a { color: var(--sage); text-decoration: underline; text-underline-offset: 3px; }
        .announcement-not-found {
            max-width: 760px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid var(--linen);
            border-radius: var(--radius-lg);
            padding: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../templates/nav.php'; ?>

<section class="announcement-detail">
    <div class="container">
        <?php if (!$announcement): ?>
            <div class="announcement-not-found">
                <h1><?= e(PageText::get('announcement', 'not_found_title')) ?></h1>
                <p><?= e(PageText::get('announcement', 'not_found_body')) ?></p>
                <p style="margin-top: 1rem;"><a href="/" class="btn btn--primary"><?= e(PageText::get('announcement', 'back_home')) ?></a></p>
            </div>
        <?php else: ?>
            <article class="announcement-card-full">
                <?php if (!empty($announcement['image_path']) || !empty($announcement['image_thumb'])): ?>
                    <img class="announcement-card-full__image" src="<?= e(UPLOAD_URL . ($announcement['image_path'] ?? $announcement['image_thumb'])) ?>" alt="<?= e($announcement['image_alt'] ?: $announcement['title']) ?>">
                <?php endif; ?>
                <div class="announcement-card-full__body">
                    <div class="announcement-card-full__meta"><?= e(PageText::get('announcement', 'meta_published')) ?> <?= e(date('M j, Y g:i A', strtotime($announcement['publish_date']))) ?></div>
                    <h1 class="announcement-card-full__title"><?= e($announcement['title']) ?></h1>

                    <?php if ($announcementText !== ''): ?>
                        <p class="announcement-card-full__text"><?= e($announcementText) ?></p>
                    <?php endif; ?>

                    <div class="announcement-linked">
                        <?php if (!empty($linkedEvents)): ?>
                            <div>
                                <h3><?= e(PageText::get('announcement', 'related_events')) ?></h3>
                                <?php foreach ($linkedEvents as $event): ?>
                                    <div>
                                        <?php if (!empty($event['url'])): ?>
                                            <a href="<?= e($event['url']) ?>" target="_blank" rel="noopener"><?= e($event['name']) ?></a>
                                        <?php else: ?>
                                            <a href="/events"><?= e($event['name']) ?></a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($linkedPottery)): ?>
                            <div>
                                <h3><?= e(PageText::get('announcement', 'related_pieces')) ?></h3>
                                <?php foreach ($linkedPottery as $piece): ?>
                                    <div><a href="/portfolio#piece-<?= (int)$piece['id'] ?>"><?= e($piece['title']) ?></a></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <p style="margin-top:1.5rem;"><a class="btn btn--outline--dark" href="/"><?= e(PageText::get('announcement', 'back_home')) ?></a></p>
                </div>
            </article>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="/js/main.js"></script>
</body>
</html>
