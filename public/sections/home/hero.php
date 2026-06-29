<?php
// Homepage section: Hero banner + ticker.
// Variables in scope (set by public/index.php): $tickerItems
$heroImage = setting('hero_image');
?>
<section class="hero"<?= $heroImage ? ' data-hero-bg="/uploads/' . e($heroImage) . '"' : '' ?>>
    <div class="hero__bg-overlay"></div>

    <?php if (!empty($tickerItems)): ?>
    <?php $firstTickerItem = $tickerItems[0]; ?>
    <div class="hero-ticker" id="heroTicker" role="region" aria-label="Latest announcements" data-items="<?= e(json_encode($tickerItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
        <a class="hero-ticker__item" id="heroTickerItem" href="/announcement?id=<?= (int)$firstTickerItem['id'] ?>" aria-label="Open announcement: <?= e($firstTickerItem['title']) ?>">
            <strong><?= e($firstTickerItem['title']) ?></strong>
            <span><?= e($firstTickerItem['text']) ?></span>
        </a>
    </div>
    <?php endif; ?>

    <!-- Folk art corner flourishes -->
    <div class="hero__corner hero__corner--tl">
        <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 10 Q10 60 60 60 Q10 60 10 110" stroke="#C9B48A" stroke-width="1.5" fill="none" opacity=".5"/>
            <circle cx="10" cy="10" r="4" fill="#B85C38" opacity=".6"/>
            <circle cx="60" cy="60" r="3" fill="#B85C38" opacity=".4"/>
            <path d="M10 35 Q35 35 35 10" stroke="#C9B48A" stroke-width="1" fill="none" opacity=".3"/>
            <path d="M10 85 Q60 85 60 35" stroke="#C9B48A" stroke-width="1" fill="none" opacity=".3"/>
            <circle cx="35" cy="10" r="2" fill="#C9B48A" opacity=".4"/>
            <circle cx="10" cy="85" r="2" fill="#C9B48A" opacity=".4"/>
        </svg>
    </div>
    <div class="hero__corner hero__corner--tr">
        <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 10 Q10 60 60 60 Q10 60 10 110" stroke="#C9B48A" stroke-width="1.5" fill="none" opacity=".5"/>
            <circle cx="10" cy="10" r="4" fill="#B85C38" opacity=".6"/>
            <circle cx="60" cy="60" r="3" fill="#B85C38" opacity=".4"/>
            <path d="M10 35 Q35 35 35 10" stroke="#C9B48A" stroke-width="1" fill="none" opacity=".3"/>
            <path d="M10 85 Q60 85 60 35" stroke="#C9B48A" stroke-width="1" fill="none" opacity=".3"/>
        </svg>
    </div>
    <div class="hero__corner hero__corner--bl">
        <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 10 Q10 60 60 60 Q10 60 10 110" stroke="#C9B48A" stroke-width="1.5" fill="none" opacity=".5"/>
            <circle cx="10" cy="10" r="4" fill="#B85C38" opacity=".6"/>
            <circle cx="60" cy="60" r="3" fill="#B85C38" opacity=".4"/>
        </svg>
    </div>
    <div class="hero__corner hero__corner--br">
        <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 10 Q10 60 60 60 Q10 60 10 110" stroke="#C9B48A" stroke-width="1.5" fill="none" opacity=".5"/>
            <circle cx="10" cy="10" r="4" fill="#B85C38" opacity=".6"/>
            <circle cx="60" cy="60" r="3" fill="#B85C38" opacity=".4"/>
        </svg>
    </div>

    <div class="hero__card">
    <div class="hero__content">
        <span class="hero__eyebrow"><?= e(setting('tagline', 'made by hand, with care')) ?></span>
        <h1 class="hero__title"><?= e(setting('hero_title', 'Made by Hand')) ?></h1>
        <div class="hero__title-rule">
            <span></span>
            <em>✦</em>
            <span></span>
        </div>
        <p class="hero__sub"><?= e(setting('hero_subtitle', 'Original handmade goods, made to last')) ?></p>
        <div class="hero__actions">
            <a href="/portfolio" class="btn btn--primary"><?= e(PageText::get('home', 'hero_btn_portfolio')) ?></a>
            <a href="/shop" class="btn btn--outline"><?= e(PageText::get('home', 'hero_btn_shop')) ?></a>
        </div>
    </div>
    </div><!-- /.hero__card -->
    <div class="hero__scroll-hint">
        <span><?= e(PageText::get('home', 'hero_scroll_hint')) ?></span>
        <div class="hero__scroll-line"></div>
    </div>
</section>
