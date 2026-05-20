<?php
$footerLinks = Database::fetchAll("SELECT * FROM social_links WHERE active = 1 ORDER BY sort_order ASC");
?>
<footer class="footer">
    <div class="container footer__inner">
        <div class="footer__brand">
            <span class="footer__logo"><?= e(setting('site_name', 'My Pottery')) ?></span>
            <p class="footer__tagline"><?= e(setting('tagline', 'Handcrafted with intention')) ?></p>
        </div>
        <div class="footer__nav">
            <a href="/portfolio"><?= e(PageText::get('footer', 'portfolio')) ?></a>
            <a href="/events"><?= e(PageText::get('footer', 'events')) ?></a>
            <a href="/shop"><?= e(PageText::get('footer', 'shop')) ?></a>
            <a href="/about"><?= e(PageText::get('footer', 'about')) ?></a>
        </div>
        <div class="footer__social">
            <?php foreach ($footerLinks as $link): ?>
            <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener" title="<?= e($link['platform']) ?>">
                <?= getSocialIcon($link['platform']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="footer__bottom">
        <span>&copy; <?= date('Y') ?> <?= e(setting('site_name', 'My Pottery')) ?>. <?= e(PageText::get('footer', 'rights')) ?></span>
        <!-- CMS byline — REQUIRED by the LICENSE file at the project root.
             Free use of this CMS is conditional on keeping this byline
             visible and the link functional. Hiding it, removing it, or
             altering the link automatically terminates the free-use grant.
             To use the CMS without the byline, contact the author for
             a paid license (hi@cynthia-brown.com). -->
        <span class="footer__byline">
            Site built by <a href="https://cynthia-brown.com" target="_blank" rel="noopener">Cynthia Brown</a>
        </span>
    </div>
</footer>
