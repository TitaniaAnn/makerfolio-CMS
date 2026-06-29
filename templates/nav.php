<?php
// Theme overrides — emit before <nav> so :root variables apply to the whole
// document. Public pages share this include; admin pages do not.
echo Theme::googleFontsLink();
echo Theme::styleBlock();

// Optional external nav link (admin-configurable). Blank URL hides it entirely.
$navExternalUrl   = trim((string)setting('nav_external_url', ''));
$navExternalLabel = trim((string)setting('nav_external_label', 'App'));
$showExternal     = $navExternalUrl !== '' && $navExternalLabel !== '';
?>
<nav class="nav" id="nav">
    <div class="nav__inner">
        <a href="/" class="nav__logo">
            <span class="nav__logo-text"><?= e(setting('site_name', 'My Pottery')) ?></span>
        </a>
        <ul class="nav__links">
            <li><a href="/" class="nav__link"><?= e(PageText::get('nav', 'home')) ?></a></li>
            <li><a href="/portfolio" class="nav__link"><?= e(PageText::get('nav', 'portfolio')) ?></a></li>
            <li><a href="/events" class="nav__link"><?= e(PageText::get('nav', 'events')) ?></a></li>
            <li><a href="/shop" class="nav__link"><?= e(PageText::get('nav', 'shop')) ?></a></li>
            <li><a href="/about" class="nav__link"><?= e(PageText::get('nav', 'about')) ?></a></li>
            <li><a href="/downloads" class="nav__link"><?= e(PageText::get('nav', 'templates')) ?></a></li>
            <?php if ($showExternal): ?>
            <li><a href="<?= e($navExternalUrl) ?>" class="nav__link" target="_blank" rel="noopener"><?= e($navExternalLabel) ?></a></li>
            <?php endif; ?>
        </ul>
        <button class="nav__burger" aria-label="Menu" id="burger">
            <span></span><span></span><span></span>
        </button>
    </div>
    <!-- Mobile menu -->
    <div class="nav__mobile" id="mobileMenu">
        <a href="/" class="nav__mobile-link"><?= e(PageText::get('nav', 'home')) ?></a>
        <a href="/portfolio" class="nav__mobile-link"><?= e(PageText::get('nav', 'portfolio')) ?></a>
        <a href="/events" class="nav__mobile-link"><?= e(PageText::get('nav', 'events')) ?></a>
        <a href="/shop" class="nav__mobile-link"><?= e(PageText::get('nav', 'shop')) ?></a>
        <a href="/about" class="nav__mobile-link"><?= e(PageText::get('nav', 'about')) ?></a>
        <a href="/downloads" class="nav__mobile-link"><?= e(PageText::get('nav', 'templates')) ?></a>
        <?php if ($showExternal): ?>
        <a href="<?= e($navExternalUrl) ?>" class="nav__mobile-link" target="_blank" rel="noopener"><?= e($navExternalLabel) ?></a>
        <?php endif; ?>
    </div>
</nav>
