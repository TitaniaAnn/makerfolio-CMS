// public/js/pages/home.js
//
// Homepage hero ticker animation. Reads the announcement list from the
// #heroTicker element's data-items attribute (JSON), then cycles each entry
// across the banner with a CSS transform animation. No deps, vanilla JS.
// Runs at end of <body>, so the DOM is already parsed.
// Apply the (tenant-content) hero background image from a data attribute via
// CSSOM. JS-applied styles are not governed by CSP style-src, so this lets us
// drop the inline style="background-image:..." attribute the hero used to emit.
(function () {
    const hero = document.querySelector('.hero[data-hero-bg]');
    if (hero) {
        hero.style.backgroundImage = "url('" + hero.dataset.heroBg + "')";
    }
})();

(function () {
    const ticker = document.getElementById('heroTicker');
    const itemEl = document.getElementById('heroTickerItem');
    if (!ticker || !itemEl) return;

    let items = [];
    try {
        items = JSON.parse(ticker.dataset.items || '[]');
    } catch (e) {
        items = [];
    }
    if (!items.length) return;

    let index = 0;
    const speed = window.matchMedia('(max-width: 768px)').matches ? 110 : 135; // px/sec

    function paintItem(entry) {
        itemEl.href = '/announcement.php?id=' + encodeURIComponent(entry.id);
        itemEl.setAttribute('aria-label', 'Open announcement: ' + entry.title);
        itemEl.innerHTML = '<strong>' + entry.title.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</strong><span>' + entry.text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
    }

    function runCycle() {
        const entry = items[index];
        paintItem(entry);

        const containerWidth = ticker.clientWidth;

        itemEl.style.transition = 'none';
        itemEl.style.transform = 'translateX(' + containerWidth + 'px)';

        requestAnimationFrame(() => {
            const itemWidth = itemEl.offsetWidth;
            const travel = containerWidth + itemWidth + 40;
            const duration = Math.max(6, travel / speed);

            itemEl.style.transition = 'transform ' + duration + 's linear';
            itemEl.style.transform = 'translateX(-' + (itemWidth + 40) + 'px)';
        });

        index = (index + 1) % items.length;
    }

    itemEl.addEventListener('transitionend', function (e) {
        if (e.propertyName !== 'transform') return;
        runCycle();
    });

    runCycle();
})();
