<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(SITE_URL . '/admin/users/');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Invalid user id.');
    redirect(SITE_URL . '/admin/users/');
}

$me = Auth::getUser();
if ($me && (int)$me['id'] === $id) {
    flash('error', "You can't delete the account you're signed in with. Sign in as a different admin first.");
    redirect(SITE_URL . '/admin/users/');
}

$target = Database::fetchOne(
    "SELECT id, username FROM admin_users WHERE id = ? LIMIT 1",
    [$id]
);
if (!$target) {
    flash('error', 'Admin user not found.');
    redirect(SITE_URL . '/admin/users/');
}

// Last-admin guard: refuse to delete if the row has any login method AND
// removing it would leave zero rows.
$total = (int)Database::fetchOne("SELECT COUNT(*) AS c FROM admin_users")['c'];
if ($total <= 1) {
    flash('error', "Can't delete the last admin user — at least one must remain.");
    redirect(SITE_URL . '/admin/users/');
}

Database::delete('admin_users', 'id = ?', [$id]);
ActivityLog::log('users.delete', 'admin_user', $id, ['username' => $target['username'] ?? null]);
flash('success', 'Admin user "' . ($target['username'] ?? '(unnamed)') . '" deleted.');
redirect(SITE_URL . '/admin/users/');
