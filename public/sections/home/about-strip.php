<?php
// Homepage section: About strip linking to /about.php.
?>
<section class="about-strip">
    <div class="container about-strip__inner">
        <div class="about-strip__text">
            <span class="eyebrow"><?= e(PageText::get('home', 'about_strip_eyebrow')) ?></span>
            <h2><?= e(setting('site_name', 'My Pottery')) ?></h2>
            <p><?= e(setting('about_text', '')) ?></p>
            <a href="/about" class="btn btn--dark"><?= e(PageText::get('home', 'about_strip_cta')) ?></a>
        </div>
        <div class="about-strip__decoration">
            <div class="about-strip__sunburst"></div>
            <div class="about-strip__sunburst-inner"></div>
        </div>
    </div>
</section>
