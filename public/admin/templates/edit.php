<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
redirect(SITE_URL . '/admin/templates/add?id=' . (int)($_GET['id'] ?? 0));
