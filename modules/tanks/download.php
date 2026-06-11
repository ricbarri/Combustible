<?php
// Descarga segura de documentos adjuntos del estanque
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db   = getDB();

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!$id || !in_array($type, ['load','adjust'])) {
    http_response_code(400); exit('Parámetros inválidos.');
}

if ($type === 'load') {
    $stmt = $db->prepare("SELECT document_path, document_name, branch_id FROM tank_loads WHERE id=?");
} else {
    $stmt = $db->prepare("SELECT document_path, document_name, branch_id FROM tank_adjustments WHERE id=?");
}
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row || !$row['document_path']) {
    http_response_code(404); exit('Archivo no encontrado.');
}

// Solo admin o cargador de esa sucursal puede descargar
$user = currentUser();
if ($user['profile'] !== 'administrador' && $user['branch_id'] != $row['branch_id']) {
    http_response_code(403); exit('Sin permisos.');
}

$filePath = __DIR__ . '/../../uploads/tank_docs/' . basename($row['document_path']);
if (!file_exists($filePath)) {
    http_response_code(404); exit('Archivo no encontrado en el servidor.');
}

$mime = mime_content_type($filePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($row['document_name']) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
