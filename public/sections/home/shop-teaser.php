<?php
// Homepage section: Shop teaser with links to the pots and merch filters.
?>
<section class="section shop-teaser">
    <div class="container">
        <div class="shop-teaser__inner">
            <div class="shop-teaser__text">
                <span class="eyebrow"><?= e(PageText::get('home', 'shop_teaser_eyebrow')) ?></span>
                <h2><?= e(PageText::get('home', 'shop_teaser_title')) ?></h2>
                <p><?= e(setting('shop_intro', '')) ?></p>
                <div class="shop-teaser__btns">
                    <a href="/shop?type=pot" class="btn btn--primary"><?= e(PageText::get('home', 'shop_teaser_btn_pots')) ?></a>
                    <a href="/shop?type=merch" class="btn btn--outline"><?= e(PageText::get('home', 'shop_teaser_btn_merch')) ?></a>
                </div>
            </div>
        </div>
    </div>
</section>
