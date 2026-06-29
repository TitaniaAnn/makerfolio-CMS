<?php
/**
 * SampleContent — one-click "fill this site with demo data so I can see what
 * it looks like populated" feature for fresh CMS adopters.
 *
 * Why this exists: an empty install renders empty states everywhere
 * (no portfolio pieces, no events, no shop, no announcements). A new owner
 * can't tell what the site will look like once it's full until they've
 * spent an hour adding their own content. That's a bad evaluation experience
 * and a worse "first 10 minutes after install" experience. Loading sample
 * content lets them see a realistic populated site immediately, then edit or
 * delete the demo rows as they replace them with real content.
 *
 * Images: rather than ship binary pottery photos in the repo (copyright +
 * weight), we generate small earthy-toned SVG placeholders on the fly and
 * write them into public/uploads/{pottery,products}/. SVGs scale on the
 * client, so the same file serves as both image_path and image_thumb.
 *
 * Insert strategy: pure additive. We never DELETE existing rows — the seeder
 * appends to whatever's there. A `sample_content_seeded` settings row prevents
 * double-seeding (clicking the admin button twice won't pile up 10 fake
 * pottery pieces). The marker can be cleared by deleting the settings row or
 * via ContentReset (which wipes the rows themselves anyway).
 *
 * Callers:
 *   - admin UI: /admin/settings/sample-content.php
 *   - CLI:      bin/seed-sample-content.php
 */
final class SampleContent
{
    public const SETTINGS_MARKER_KEY = 'sample_content_seeded';

    /**
     * 5 portfolio pieces with varied techniques + earthy palette colors used
     * for their generated SVG placeholders. Featured flags mirror what a
     * realistic curated portfolio looks like (2 featured, 3 secondary).
     */
    public const SAMPLE_POTTERY = [
        ['title' => 'River Stone Vase',       'technique' => 'Wheel-thrown',       'dimensions' => '18cm × 12cm', 'year' => 2025, 'featured' => 1, 'color' => '#7a8068', 'description' => 'A wheel-thrown stoneware vase with a celadon glaze pooling around the rim. Inspired by water-smoothed pebbles from the creek behind the studio.'],
        ['title' => 'Ember Tea Bowl',         'technique' => 'Hand-built',         'dimensions' => '8cm × 11cm',  'year' => 2025, 'featured' => 1, 'color' => '#bf6b45', 'description' => 'Pinched stoneware tea bowl, glazed in a layered iron-red. Sized to cradle in two hands; comfortable for matcha or strong black tea.'],
        ['title' => 'Slip-Cast Bud Vase Set', 'technique' => 'Slip-cast',          'dimensions' => '12cm × 5cm',  'year' => 2024, 'featured' => 0, 'color' => '#c9b58a', 'description' => 'Three matched bud vases cast from the same plaster mold, finished in a matte buff slip. Each holds a single stem.'],
        ['title' => 'Reduction-Fired Mug',    'technique' => 'Wheel-thrown',       'dimensions' => '10cm × 9cm',  'year' => 2024, 'featured' => 0, 'color' => '#5c4a3a', 'description' => 'Thrown in porcelain, glazed in a copper-red reduction. The deep wine color shifts to oxblood where the glaze ran thick.'],
        ['title' => 'Carved Lidded Jar',      'technique' => 'Wheel-thrown + carved','dimensions' => '15cm × 14cm','year' => 2025, 'featured' => 0, 'color' => '#a08550', 'description' => 'Stoneware jar with carved geometric reliefs around the shoulder. The lid sits flush with a hand-rolled knob.'],
    ];

    /**
     * 3 events spread across the next 90 days, one per type. Dates are written
     * as relative offsets at seed time so they always look current regardless
     * of when the seeder runs.
     */
    public const SAMPLE_EVENTS = [
        ['event_type' => 'pottery_show',    'name' => 'Spring Open Studio',           'location' => 'Main Studio', 'description' => 'Two days of doors-open studio visits. Tea, conversation, and a chance to see work in progress on the wheel.', 'days_out' => 14, 'duration_days' => 1, 'featured' => 1],
        ['event_type' => 'class',           'name' => 'Beginner Wheel Throwing',      'location' => 'Main Studio', 'description' => 'A relaxed 4-week intro to throwing on the wheel. All clay, tools, and firing included. Limit 6 students.', 'days_out' => 30, 'duration_days' => 28, 'featured' => 0, 'class_type' => 'wheelthrowing', 'class_age_range' => 'Adults (16+)'],
        ['event_type' => 'pottery_sale',    'name' => 'Holiday Studio Sale',          'location' => 'Main Studio', 'description' => 'Annual end-of-year sale — seconds, samples, and new work from the past few months. Cash and card accepted.', 'days_out' => 75, 'duration_days' => 2, 'featured' => 0],
    ];

    /**
     * 2 announcements — one tied to the upcoming Open Studio event, one
     * standalone. We resolve the event link by name after both have been
     * inserted, so the seed order is independent of FK lookup.
     */
    public const SAMPLE_ANNOUNCEMENTS = [
        ['title' => 'Open Studio in two weeks!', 'description' => "Doors open Saturday and Sunday from 10am – 4pm. Come see what's on the shelves, watch some throwing demos, and grab a cup of tea.", 'days_out' => 0, 'linked_event' => 'Spring Open Studio', 'color' => '#bf6b45'],
        ['title' => 'New work on the shelves',   'description' => "Just pulled a fresh batch of mugs and tea bowls from the kiln. A few are already up in the shop — more to come once the studio sale planning settles.", 'days_out' => 3, 'linked_event' => null, 'color' => '#7a8068'],
    ];

    /**
     * 4 shop products covering all 3 status states (available / coming_soon /
     * sold) so the storefront's badge rendering shows up correctly in the demo.
     * Category assignments use the slug — resolved against shop_categories at
     * seed time.
     */
    public const SAMPLE_PRODUCTS = [
        ['name' => 'River Stone Vase',       'price' => 95.00, 'type' => 'pot',   'status' => 'available',   'category_slug' => 'original-pots',  'dimensions' => '18cm × 12cm', 'technique' => 'Wheel-thrown', 'quantity' => 1, 'color' => '#7a8068', 'description' => 'One-of-a-kind wheel-thrown stoneware vase, celadon glaze. Ships in protective packaging within 5 business days.'],
        ['name' => 'Ember Tea Bowl',         'price' => 38.00, 'type' => 'pot',   'status' => 'available',   'category_slug' => 'original-pots',  'dimensions' => '8cm × 11cm',  'technique' => 'Hand-built',   'quantity' => 3, 'color' => '#bf6b45', 'description' => 'Hand-built tea bowl glazed in iron-red. Food-safe, dishwasher-safe, microwave-safe.'],
        ['name' => 'Studio Logo T-shirt',    'price' => 28.00, 'type' => 'merch', 'status' => 'available',   'category_slug' => 'studio-merch',   'dimensions' => 'Unisex S–XXL','technique' => '',             'quantity' => 25,'color' => '#c9b58a', 'description' => 'Soft cotton studio tee with hand-drawn logo on the chest. Available in sand and slate.'],
        ['name' => 'Reduction-Fired Mug',    'price' => 45.00, 'type' => 'pot',   'status' => 'sold',        'category_slug' => 'original-pots',  'dimensions' => '10cm × 9cm',  'technique' => 'Wheel-thrown', 'quantity' => 0, 'color' => '#5c4a3a', 'description' => 'Porcelain mug with copper-red reduction glaze. Sold — more coming after the next firing.'],
    ];

    /** 4 social links covering the icons supported by getSocialIcon(). */
    public const SAMPLE_SOCIAL_LINKS = [
        ['platform' => 'instagram', 'url' => 'https://instagram.com/yourhandle',          'handle' => '@yourhandle',    'sort_order' => 10],
        ['platform' => 'tiktok',    'url' => 'https://tiktok.com/@yourhandle',            'handle' => '@yourhandle',    'sort_order' => 20],
        ['platform' => 'youtube',   'url' => 'https://youtube.com/@yourchannel',          'handle' => '@yourchannel',   'sort_order' => 30],
    ];

    /**
     * Top-level entry point.
     *
     * @param string $uploadPath Absolute path to public/uploads/.
     * @return array{
     *   created:array<string,int>,
     *   skipped:bool,
     *   image_dir_warnings:string[]
     * }
     */
    public static function seed(string $uploadPath): array
    {
        // Idempotency: refuse to double-seed.
        if (self::isSeeded()) {
            return ['created' => [], 'skipped' => true, 'image_dir_warnings' => []];
        }

        $warnings = self::ensureUploadDirs($uploadPath, ['pottery', 'products']);

        $created = [
            'pottery'       => 0,
            'events'        => 0,
            'announcements' => 0,
            'products'      => 0,
            'social_links'  => 0,
        ];

        Database::transaction(function () use ($uploadPath, &$created) {
            // -- Pottery (also collect the new IDs by title so we can link from announcements/events later)
            $potteryIdByTitle = [];
            foreach (self::SAMPLE_POTTERY as $sortIdx => $piece) {
                $filename = self::svgFilenameFor('pottery', $piece['title']);
                self::writeSvgPlaceholder($uploadPath . '/pottery/' . $filename, $piece['color'], $piece['title']);

                $id = Database::insert('piece', [
                    'title'       => $piece['title'],
                    'description' => $piece['description'],
                    'technique'   => $piece['technique'],
                    'dimensions'  => $piece['dimensions'],
                    'year'        => $piece['year'],
                    'image_path'  => 'pottery/' . $filename,
                    'image_thumb' => 'pottery/' . $filename,
                    'alt_text'    => $piece['title'] . ' — sample image (replace when you upload your own).',
                    'featured'    => $piece['featured'],
                    'sort_order'  => ($sortIdx + 1) * ListReorder::SORT_ORDER_GAP,
                ]);
                $potteryIdByTitle[$piece['title']] = $id;
                $created['pottery']++;
            }

            // -- Events
            $eventIdByName = [];
            foreach (self::SAMPLE_EVENTS as $sortIdx => $ev) {
                $start = (new DateTimeImmutable())->modify('+' . (int)$ev['days_out'] . ' days');
                $end   = $start->modify('+' . (int)$ev['duration_days'] . ' days');
                $row = [
                    'event_type'   => $ev['event_type'],
                    'name'         => $ev['name'],
                    'description'  => $ev['description'],
                    'location'     => $ev['location'],
                    'start_date'   => $start->format('Y-m-d'),
                    'end_date'     => $end->format('Y-m-d'),
                    'publish_date' => (new DateTimeImmutable())->format('Y-m-d'),
                    'featured'     => $ev['featured'],
                    'sort_order'   => ($sortIdx + 1) * ListReorder::SORT_ORDER_GAP,
                ];
                if (!empty($ev['class_type'])) {
                    $row['class_type']       = $ev['class_type'];
                    $row['class_age_range']  = $ev['class_age_range'] ?? '';
                    $row['class_date_start'] = $start->format('Y-m-d');
                    $row['class_date_end']   = $end->format('Y-m-d');
                    $row['class_time_start'] = '18:00:00';
                    $row['class_time_end']   = '20:00:00';
                }
                $eventIdByName[$ev['name']] = Database::insert('events', $row);
                $created['events']++;
            }

            // -- Announcements
            foreach (self::SAMPLE_ANNOUNCEMENTS as $ann) {
                $publishAt = (new DateTimeImmutable())->modify('-' . (int)$ann['days_out'] . ' days');
                $filename  = self::svgFilenameFor('announcement', $ann['title']);
                self::writeSvgPlaceholder($uploadPath . '/announcements/' . $filename, $ann['color'], $ann['title']);
                $annId = Database::insert('announcements', [
                    'title'        => $ann['title'],
                    'description'  => $ann['description'],
                    'publish_date' => $publishAt->format('Y-m-d H:i:s'),
                    'image_path'   => 'announcements/' . $filename,
                    'image_thumb'  => 'announcements/' . $filename,
                    'image_alt'    => $ann['title'] . ' — sample image.',
                ]);
                $created['announcements']++;

                if ($ann['linked_event'] && isset($eventIdByName[$ann['linked_event']])) {
                    Database::insert('announcement_links', [
                        'announcement_id' => $annId,
                        'entity_type'     => 'event',
                        'entity_id'       => $eventIdByName[$ann['linked_event']],
                        'sort_order'      => 0,
                    ]);
                }
            }

            // -- Shop products (resolve category_slug → category_id; skip rows whose category isn't present)
            $categories = Database::fetchAll("SELECT id, slug FROM shop_categories");
            $categoryIdBySlug = array_column($categories, 'id', 'slug');
            foreach (self::SAMPLE_PRODUCTS as $sortIdx => $product) {
                $filename = self::svgFilenameFor('product', $product['name']);
                self::writeSvgPlaceholder($uploadPath . '/products/' . $filename, $product['color'], $product['name']);
                Database::insert('products', [
                    'category_id' => $categoryIdBySlug[$product['category_slug']] ?? null,
                    'name'        => $product['name'],
                    'description' => $product['description'],
                    'price'       => $product['price'],
                    'type'        => $product['type'],
                    'status'      => $product['status'],
                    'is_visible'  => 1,
                    'image_path'  => 'products/' . $filename,
                    'alt_text'    => $product['name'] . ' — sample image.',
                    'dimensions'  => $product['dimensions'],
                    'technique'   => $product['technique'],
                    'quantity'    => $product['quantity'],
                    'sort_order'  => ($sortIdx + 1) * ListReorder::SORT_ORDER_GAP,
                ]);
                $created['products']++;
            }

            // -- Social links
            foreach (self::SAMPLE_SOCIAL_LINKS as $link) {
                Database::insert('social_links', [
                    'platform'   => $link['platform'],
                    'url'        => $link['url'],
                    'handle'     => $link['handle'],
                    'active'     => 1,
                    'sort_order' => $link['sort_order'],
                ]);
                $created['social_links']++;
            }

            // Marker row so a second call short-circuits.
            Database::query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, '1')
                 ON DUPLICATE KEY UPDATE setting_value = '1'",
                [self::SETTINGS_MARKER_KEY]
            );
        });

        return ['created' => $created, 'skipped' => false, 'image_dir_warnings' => $warnings];
    }

    /** True when the seeder has already run on this install. */
    public static function isSeeded(): bool
    {
        try {
            $row = Database::fetchOne(
                "SELECT setting_value FROM settings WHERE setting_key = ?",
                [self::SETTINGS_MARKER_KEY]
            );
        } catch (\Throwable $_) {
            return false;
        }
        return !empty($row['setting_value']) && $row['setting_value'] === '1';
    }

    // -- SVG placeholder generation -----------------------------------------

    /**
     * Filesystem-safe filename for a sample image. Prefixed with `sample-` so
     * a manual cleanup (`rm public/uploads/pottery/sample-*.svg`) is trivial.
     */
    public static function svgFilenameFor(string $kind, string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return 'sample-' . ($slug !== '' ? $slug : $kind) . '.svg';
    }

    /**
     * Write an 800×800 solid-color SVG with the title text overlaid. SVG is
     * used because it's tiny (< 1 KB per file), scales without thumbnailing,
     * and doesn't need GD. The same file serves both image_path and
     * image_thumb consumers.
     *
     * SECURITY: $bgColor must be a 6-digit hex (`#rrggbb`) — we validate
     * explicitly rather than trust the SAMPLE_* consts so a future maintainer
     * adding a row with an attacker-influenced color can't break out of the
     * `fill="…"` attribute into onload= or similar SVG-script vectors.
     */
    public static function writeSvgPlaceholder(string $path, string $bgColor, string $label): void
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor)) {
            throw new InvalidArgumentException("SampleContent: refusing unsafe SVG color '$bgColor'");
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $textColor = self::contrastingTextColor($bgColor); // returns one of two hardcoded hex values
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800" preserveAspectRatio="xMidYMid slice">
  <rect width="800" height="800" fill="{$bgColor}"/>
  <circle cx="400" cy="420" r="180" fill="{$textColor}" fill-opacity="0.12"/>
  <text x="400" y="380" font-family="Georgia, serif" font-size="42" fill="{$textColor}" text-anchor="middle" font-style="italic" opacity="0.85">{$safeLabel}</text>
  <text x="400" y="430" font-family="sans-serif" font-size="18" fill="{$textColor}" text-anchor="middle" opacity="0.55">sample image</text>
</svg>
SVG;
        file_put_contents($path, $svg, LOCK_EX);
    }

    /** Cheap luminance check; returns either dark or light text for legibility on $bgHex. */
    public static function contrastingTextColor(string $bgHex): string
    {
        $h = ltrim($bgHex, '#');
        if (strlen($h) !== 6) return '#222';
        $r = hexdec(substr($h, 0, 2));
        $g = hexdec(substr($h, 2, 2));
        $b = hexdec(substr($h, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance > 0.55 ? '#3a2e22' : '#fdf8ef';
    }

    /** Ensure upload subdirs exist. Returns a list of dirs that couldn't be created. */
    private static function ensureUploadDirs(string $uploadPath, array $subdirs): array
    {
        $warnings = [];
        foreach ($subdirs as $sub) {
            $dir = $uploadPath . '/' . $sub;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $warnings[] = $dir;
            }
        }
        return $warnings;
    }
}
