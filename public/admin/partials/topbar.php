<?php
$user = Auth::getUser();
$flash = getFlash();
?>
<!-- Per-session CSRF token, exposed so admin tools / tests can read it from
     any admin page (forms can still use csrf_field() inline). -->
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<header class="admin-topbar">
    <button class="admin-topbar__burger" id="adminBurger" aria-label="Toggle sidebar">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>
    <div class="admin-topbar__right">
        <a href="/" target="_blank" rel="noopener" class="admin-topbar__view-site" title="Open the public site in a new tab" aria-label="View public site">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="u-icon-inline">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
            </svg><span class="admin-topbar__view-site-label">View site</span>
        </a>
        <?php if ($user['avatar']): ?>
        <img src="<?= e($user['avatar']) ?>" alt="<?= e($user['name']) ?>" class="admin-topbar__avatar">
        <?php endif; ?>
        <a href="/admin/account/2fa" class="u-topbar-2fa" title="Manage your two-factor authentication">2FA</a>
        <span class="admin-topbar__name"><?= e($user['name'] ?? '') ?></span>
    </div>
</header>

<?php if ($flash): ?>
<div class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- Wires the mobile burger toggle, image-preview, and flash auto-dismiss.
     `defer` ensures the script runs after the DOM (including the burger
     button above + the sidebar emitted by the parent page) is parsed.
     Included from topbar so every admin page picks it up automatically —
     don't duplicate this in individual pages. -->
<script src="/admin/js/admin.js" defer></script>
