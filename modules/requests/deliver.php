<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
// Redirect to pending_delivery if cargador, or to view if not
if (currentUser()['profile'] === 'cargador') {
    header('Location: ' . APP_URL . '/modules/requests/pending_delivery.php');
} else {
    $id = (int)($_GET['id'] ?? 0);
    header('Location: ' . APP_URL . '/modules/requests/view.php?id=' . $id);
}
exit;
