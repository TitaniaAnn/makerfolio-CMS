<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ImageCropHandler.php';
Auth::requireLogin();
csrf_verify();

header('Content-Type: application/json');

$result = ImageCropHandler::crop([
    'imageId'           => (int) ($_POST['img_id'] ?? 0),
    'parentId'          => (int) ($_POST['product_id'] ?? 0),
    'imagesTable'       => 'product_images',
    'parentIdColumn'    => 'product_id',
    'parentTable'       => 'products',
    'parentThumbColumn' => null,
    'x'                 => (int) ($_POST['x'] ?? 0),
    'y'                 => (int) ($_POST['y'] ?? 0),
    'w'                 => (int) ($_POST['w'] ?? 0),
    'h'                 => (int) ($_POST['h'] ?? 0),
]);

echo json_encode($result);
