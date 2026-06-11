<?php
// modules/auth/ms_callback.php — Callback OAuth de Microsoft
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/auth.php';

// Error directo de Microsoft (ej: usuario canceló)
if (isset($_GET['error'])) {
    $desc = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    logEvent(LOG_AUTH, 'OAUTH_MS_ERROR', 'Microsoft devolvió error OAuth: ' . $desc, LOG_WARNING);
    $_SESSION['login_error'] = 'Microsoft devolvió un error: ' . $desc;
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

if (empty($_GET['code']) || empty($_GET['state'])) {
    $_SESSION['login_error'] = 'Respuesta incompleta de Microsoft.';
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$result = handleOAuthCallback($_GET['code'], $_GET['state']);

switch ($result['status']) {

    case 'ok':
        // Login exitoso → redirigir a destino original o dashboard
        $redirect = $_SESSION['redirect_after_login'] ?? '';
        unset($_SESSION['redirect_after_login']);
        // Evitar redirigir a URLs de auth para no entrar en bucle
        if (empty($redirect) || strpos($redirect, '/modules/auth/') !== false || strpos($redirect, '/index.php') !== false) {
            $redirect = APP_URL . '/index.php';
        } else {
            $redirect = APP_URL . $redirect;
        }
        header('Location: ' . $redirect);
        exit;

    case 'no_profile':
        // Usuario se autenticó bien en MS pero no tiene perfil asignado en el sistema
        $_SESSION['login_warning'] =
            'Hola ' . htmlspecialchars($result['ms_data']['name'] ?? '') . ', tu cuenta Microsoft fue reconocida. ' .
            'Un administrador debe asignarte un perfil y sucursal antes de que puedas acceder. ' .
            'Contacta a tu administrador de sistema.';
        header('Location: ' . APP_URL . '/index.php');
        exit;

    case 'inactive':
        $_SESSION['login_error'] =
            'Tu cuenta está desactivada en el sistema. Contacta al administrador.';
        header('Location: ' . APP_URL . '/index.php');
        exit;

    case 'error':
    default:
        $_SESSION['login_error'] = $result['message'] ?? 'Error de autenticación.';
        header('Location: ' . APP_URL . '/index.php');
        exit;
}
