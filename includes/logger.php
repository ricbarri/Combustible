<?php
// includes/logger.php
// Sistema de trazabilidad de eventos FuelControl
// Escribe simultáneamente en: BD (system_logs) + archivo .log diario

// -- Categorías de eventos --------------------------------------------------
defined('LOG_AUTH')     or define('LOG_AUTH',     'AUTH');
defined('LOG_USER')     or define('LOG_USER',     'USUARIO');
defined('LOG_BRANCH')   or define('LOG_BRANCH',   'SUCURSAL');
defined('LOG_MACHINE')  or define('LOG_MACHINE',  'MAQUINA');
defined('LOG_TANK')     or define('LOG_TANK',     'ESTANQUE');
defined('LOG_REQUEST')  or define('LOG_REQUEST',  'SOLICITUD');
defined('LOG_APPROVAL') or define('LOG_APPROVAL', 'APROBACION');
defined('LOG_DELIVERY') or define('LOG_DELIVERY', 'ENTREGA');
defined('LOG_FLOW')     or define('LOG_FLOW',     'FLUJO');
defined('LOG_MAIL')     or define('LOG_MAIL',     'CORREO');
defined('LOG_ERROR')    or define('LOG_ERROR',    'ERROR');

// -- Niveles ----------------------------------------------------------------
defined('LOG_INFO')      or define('LOG_INFO',      'INFO');
defined('LOG_WARNING')   or define('LOG_WARNING',   'WARNING');
defined('LOG_ERROR_LVL') or define('LOG_ERROR_LVL', 'ERROR');
defined('LOG_CRITICAL')  or define('LOG_CRITICAL',  'CRITICAL');

// -- Directorio de archivos log ---------------------------------------------
define('LOG_DIR', __DIR__ . '/../logs');

/**
 * Registra un evento en BD y en archivo .log
 *
 * @param string      $category  Categoría (usar constantes LOG_*)
 * @param string      $action    Acción específica (ej: 'LOGIN_OK', 'TANK_LOAD')
 * @param string      $description Descripción legible del evento
 * @param string      $level     Nivel (INFO / WARNING / ERROR / CRITICAL)
 * @param array|null  $extra     Datos adicionales en JSON (antes/después, IDs, etc.)
 */
function logEvent(
    string $category,
    string $action,
    string $description,
    string $level = LOG_INFO,
    ?array $extra = null
): void {
    // -- Contexto ----------------------------------------------
    $userId    = $_SESSION['user_id']    ?? null;
    $userName  = $_SESSION['user_name']  ?? 'Sistema';
    $userEmail = $_SESSION['user_email'] ?? '';
    $branchId  = $_SESSION['user_branch_id'] ?? null;
    $ip        = getClientIP();
    $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    $uri       = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? 'cli');
    $extraJson = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $now       = date('Y-m-d H:i:s');

    // -- 1. Guardar en BD --------------------------------------
    try {
        $db = getDB();
        $db->prepare("
            INSERT INTO system_logs
                (level, category, action, description, user_id, user_name, user_email,
                 branch_id, ip_address, user_agent, request_uri, extra_data, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $level, $category, $action, $description,
            $userId, $userName, $userEmail,
            $branchId, $ip, $ua, $uri, $extraJson
        ]);
    } catch (Throwable $e) {
        // Si la BD falla, no detenemos el sistema — solo log en archivo
        error_log('[FuelControl] BD log error: ' . $e->getMessage());
    }

    // -- 2. Guardar en archivo diario --------------------------
    writeLogFile($level, $category, $action, $description, $userId, $userName, $ip, $extraJson, $now);
}

/**
 * Escribe la línea en el archivo log diario logs/YYYY-MM-DD.log
 */
function writeLogFile(
    string $level,
    string $category,
    string $action,
    string $description,
    ?int   $userId,
    string $userName,
    string $ip,
    ?string $extra,
    string $now
): void {
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
        // Proteger el directorio de acceso web
        @file_put_contents(LOG_DIR . '/.htaccess', "Require all denied\n");
    }

    $filename = LOG_DIR . '/' . date('Y-m-d') . '.log';
    $extraStr = $extra ? ' | EXTRA: ' . $extra : '';
    $userStr  = $userId ? "UID:{$userId} [{$userName}]" : 'Sistema';
    $line     = "[{$now}] [{$level}] [{$category}] [{$action}] {$userStr} IP:{$ip} — {$description}{$extraStr}" . PHP_EOL;

    @file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Obtiene la IP real del cliente considerando proxies
 */
function getClientIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Shortcut para loguear errores con contexto de excepción
 */
function logException(string $category, string $action, Throwable $e, ?array $extra = null): void {
    $extra = array_merge($extra ?? [], [
        'exception' => get_class($e),
        'message'   => $e->getMessage(),
        'file'      => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
    logEvent($category, $action, 'Excepción: ' . $e->getMessage(), LOG_ERROR_LVL, $extra);
}
