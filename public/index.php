<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/bootstrap.php';

// Fetch featured pottery with best matching event per piece.
// "Best matching" = nearest upcoming event by start_date, or currently active event if no upcoming.
$featured = Database::fetchAll(
    "SELECT
        p.*,
        e.id as event_id,
        e.name as event_name,
        e.url as event_url,
        e.event_type as event_type
    FROM piece p
    LEFT JOIN events e ON e.id = (
        SELECT ep.event_id
        FROM event_piece ep
        LEFT JOIN events e2 ON e2.id = ep.event_id
        WHERE ep.piece_id = p.id
            AND e2.publish_date IS NOT NULL
            AND e2.publish_date <= CURDATE()
        ORDER BY
            CASE WHEN e2.start_date > CURDATE() THEN 0 ELSE 1 END,
            CASE WHEN e2.start_date > CURDATE() THEN e2.start_date ELSE e2.end_date END DESC
        LIMIT 1
    )
    WHERE p.featured = 1
    ORDER BY p.sort_order ASC, p.created_at DESC
    LIMIT 6"
);
$socialLinks = Database::fetchAll(
    "SELECT * FROM social_links WHERE active = 1 ORDER BY sort_order ASC"
);
$socialPosts = Database::fetchAll(
    "SELECT * FROM social_posts WHERE featured = 1 ORDER BY sort_order ASC LIMIT 6"
);

$events = Database::fetchAll(
        "SELECT *
         FROM events
         WHERE publish_date IS NOT NULL
             AND publish_date <= CURDATE()
             AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY
             CASE WHEN start_date IS NULL THEN 1 ELSE 0 END,
             CASE WHEN start_date >= CURDATE() THEN 0 ELSE 1 END,
             start_date ASC,
             sort_order ASC,
             created_at DESC
         LIMIT 3"
);

$announcements = Database::fetchAll(
    "SELECT *
     FROM announcements
     WHERE publish_date <= NOW()
     ORDER BY publish_date DESC, created_at DESC
     LIMIT 5"
);

$tickerItems = [];
foreach ($announcements as $ann) {
    $tickerText = trim((string)($ann['description'] ?? $ann['content'] ?? ''));
    if ($tickerText === '') {
        $tickerText = $ann['title'];
    }
    if (strlen($tickerText) > 90) {
        $tickerText = substr($tickerText, 0, 90) . '...';
    }
    $tickerItems[] = [
        'id'    => (int)$ann['id'],
        'title' => $ann['title'],
        'text'  => $tickerText,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(setting('site_name', 'My Pottery')) ?></title>
    <?= PageMeta::renderHead([
        'title'       => setting('site_name', 'My Pottery'),
        'description' => setting('tagline', ''),
        'type'        => 'website',
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

<?php
// Section dispatcher: render each visible homepage section in admin-defined
// order. Section keys are snake_case; partial filenames are kebab-case.
foreach (PageSections::enabled('home') as $section) {
    $partial = __DIR__ . '/sections/home/' . str_replace('_', '-', $section) . '.php';
    if (is_file($partial)) {
        include $partial;
    }
}
?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="/js/pages/home.js"></script>
<script src="/js/main.js"></script>
</body>
</html>
