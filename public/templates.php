<?php
// The "templates" feature was rebranded to "Downloads". This path 301-redirects
// to /downloads (preserving any ?category= filter) so old links keep working.
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /downloads' . $qs, true, 301);
exit;
