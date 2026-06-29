<?php
require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/ImageCropHandler.php';
Auth::requireLogin();
csrf_verify();

header('Content-Type: application/json');

$result = ImageCropHandler::crop([
    'imageId'           => (int) ($_POST['img_id'] ?? 0),
    'parentId'          => (int) ($_POST['piece_id'] ?? 0),
    'imagesTable'       => 'pottery_images',
    'parentIdColumn'    => 'pottery_id',
    'parentTable'       => 'pottery',
    'parentThumbColumn' => 'image_thumb',
    'x'                 => (int) ($_POST['x'] ?? 0),
    'y'                 => (int) ($_POST['y'] ?? 0),
    'w'                 => (int) ($_POST['w'] ?? 0),
    'h'                 => (int) ($_POST['h'] ?? 0),
]);

echo json_encode($result);
