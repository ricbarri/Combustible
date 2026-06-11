<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT sfl.photo_path, sfl.photo_name FROM service_fuel_loads sfl WHERE sfl.id=?");
$stmt->execute([$id]);
$load = $stmt->fetch();
if (!$load || !$load['photo_path']) { http_response_code(404); exit('No encontrado.'); }
$file = __DIR__ . '/../../uploads/odometer_photos/' . basename($load['photo_path']);
if (!file_exists($file)) { http_response_code(404); exit('Archivo no encontrado.'); }
$mime = mime_content_type($file) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes($load['photo_name'] ?? 'odometro.jpg') . '"');
readfile($file);
