<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ImageRotateHandler.php';
Auth::requireLogin();
csrf_verify();

header('Content-Type: application/json');

$result = ImageRotateHandler::rotate([
    'imageId'           => (int) ($_GET['img_id'] ?? 0),
    'parentId'          => (int) ($_GET['product_id'] ?? 0),
    'imagesTable'       => 'product_images',
    'parentIdColumn'    => 'product_id',
    'parentTable'       => 'products',
    'parentThumbColumn' => null,            // products has no thumb column on the parent
    'direction'         => (($_GET['dir'] ?? 'cw') === 'ccw') ? 'ccw' : 'cw',
]);

echo json_encode($result);
