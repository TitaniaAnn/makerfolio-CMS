<?php
// Homepage section: Upcoming Events preview (with empty-state).
// Variables in scope: $events
?>
<section class="section events-preview">
    <div class="container">
        <div class="section__header">
            <h2 class="section__title"><?= e(PageText::get('home', 'events_title')) ?></h2>
            <a href="/events" class="section__link"><?= e(PageText::get('home', 'events_link')) ?></a>
        </div>

        <?php if (empty($events)): ?>
        <p class="empty-state events-preview__empty"><?= e(PageText::get('home', 'events_empty')) ?></p>
        <?php else: ?>
        <div class="grid grid--3">
            <?php foreach ($events as $event): ?>
            <article class="event-card">
                <div class="event-card__type event-card__type--<?= e(EventTypes::cssClass($event['event_type'])) ?>"><?= e(EventTypes::label($event['event_type'])) ?></div>
                <h3 class="event-card__title"><?= e($event['name']) ?></h3>

                <?php if (!empty($event['location'])): ?>
                <p class="event-card__meta"><?= e($event['location']) ?></p>
                <?php endif; ?>

                <p class="event-card__date">
                    <?php if (!empty($event['start_date']) && !empty($event['end_date'])): ?>
                        <?= e(date('M j', strtotime($event['start_date']))) ?> - <?= e(date('M j, Y', strtotime($event['end_date']))) ?>
                    <?php elseif (!empty($event['start_date'])): ?>
                        <?= e(date('M j, Y', strtotime($event['start_date']))) ?>
                    <?php else: ?>
                        <?= e(PageText::get('home', 'event_date_tba')) ?>
                    <?php endif; ?>
                </p>

                <?php if (!empty($event['description'])): ?>
                <p class="event-card__desc"><?= e($event['description']) ?></p>
                <?php endif; ?>

                <?php if (!empty($event['url'])): ?>
                <a href="<?= e($event['url']) ?>" target="_blank" rel="noopener" class="btn btn--small btn--outline--dark"><?= e(PageText::get('home', 'event_card_cta')) ?></a>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
