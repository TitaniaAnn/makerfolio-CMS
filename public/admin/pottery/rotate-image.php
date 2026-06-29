<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ImageRotateHandler.php';
Auth::requireLogin();
csrf_verify();

header('Content-Type: application/json');

$result = ImageRotateHandler::rotate([
    'imageId'           => (int) ($_GET['img_id'] ?? 0),
    'parentId'          => (int) ($_GET['piece_id'] ?? 0),
    'imagesTable'       => 'pottery_images',
    'parentIdColumn'    => 'pottery_id',
    'parentTable'       => 'pottery',
    'parentThumbColumn' => 'image_thumb',   // pottery.image_thumb exists
    'direction'         => (($_GET['dir'] ?? 'cw') === 'ccw') ? 'ccw' : 'cw',
]);

echo json_encode($result);
