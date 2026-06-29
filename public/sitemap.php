<?php
/**
 * Dynamic sitemap. Emits XML listing the public landing pages + every
 * published announcement (the only content type that has a unique URL per
 * row — pottery / products / events are all rendered inline on their index
 * pages, so they share the same canonical URL).
 *
 * Referenced from public/robots.txt as `Sitemap: /sitemap.php`. Search
 * engines accept any URL for sitemaps; we don't need the .xml extension.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: max-age=3600, public');

$base = rtrim(SITE_URL, '/');

// Landing pages that always exist. <lastmod> would be nice but most don't
// have a meaningful per-page timestamp — skip rather than fake one.
$landing = [
    '/',
    '/portfolio.php',
    '/shop.php',
    '/about.php',
    '/events.php',
    '/downloads.php',
    '/privacy.php',
];

// Per-announcement URLs (only content type with a stable, indexable per-row URL).
$announcements = [];
try {
    $announcements = Database::fetchAll(
        "SELECT id, COALESCE(updated_at, created_at, publish_date) AS lastmod
           FROM announcements
          WHERE publish_date IS NOT NULL
            AND publish_date <= NOW()
          ORDER BY publish_date DESC"
    );
} catch (\Throwable $_) {
    // Schema not present yet — skip the announcements block, still emit landing URLs.
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($landing as $path) {
    echo "  <url><loc>" . htmlspecialchars($base . $path, ENT_XML1, 'UTF-8') . "</loc></url>\n";
}

foreach ($announcements as $a) {
    $loc = $base . '/announcement.php?id=' . (int)$a['id'];
    $line = '  <url><loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>';
    if (!empty($a['lastmod'])) {
        $ts = strtotime($a['lastmod']);
        if ($ts) {
            $line .= '<lastmod>' . date('Y-m-d', $ts) . '</lastmod>';
        }
    }
    $line .= "</url>\n";
    echo $line;
}

echo "</urlset>\n";
