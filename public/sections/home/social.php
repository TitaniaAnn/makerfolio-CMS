<?php
// Homepage section: Social feed preview. Hidden when no featured social posts exist.
// Variables in scope: $socialPosts, $socialLinks
if (empty($socialPosts)) return;
?>
<section class="section social-preview">
    <div class="container">
        <div class="section__header">
            <h2 class="section__title"><?= e(PageText::get('home', 'social_title')) ?></h2>
            <div class="social-icons">
                <?php foreach ($socialLinks as $link): ?>
                <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener" class="social-icon social-icon--<?= strtolower(e($link['platform'])) ?>" title="Follow on <?= e($link['platform']) ?>">
                    <?php echo getSocialIcon($link['platform']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="social-grid">
            <?php foreach ($socialPosts as $post): ?>
            <a href="<?= e($post['post_url']) ?>" target="_blank" rel="noopener" class="social-post">
                <?php if ($post['thumbnail_url']): ?>
                <img src="<?= e($post['thumbnail_url']) ?>" alt="Social post" loading="lazy">
                <?php elseif ($post['embed_code']): ?>
                <div class="social-post__embed"><?= $post['embed_code'] ?></div>
                <?php endif; ?>
                <div class="social-post__platform"><?= e($post['platform']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
