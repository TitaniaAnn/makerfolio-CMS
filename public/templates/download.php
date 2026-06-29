<?php
// Rebranded to /downloads/download — 301 to the new path (preserving file_id).
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /downloads/download' . $qs, true, 301);
exit;
