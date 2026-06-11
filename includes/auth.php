<?php
// includes/auth.php — Autenticación Microsoft OAuth 2.0 + perfiles internos
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/logger.php';

// ── Estado de sesión ───────────────────────────────────────────────────────

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

function requireProfile(string ...$profiles): void {
    requireLogin();
    // Superadmin tiene acceso absoluto a todo el sistema
    if ($_SESSION['user_profile'] === 'superadmin') return;
    // Administrador tiene acceso a todo dentro de su empresa
    if ($_SESSION['user_profile'] === 'administrador') return;
    if (!in_array($_SESSION['user_profile'], $profiles)) {
        logEvent(LOG_AUTH, 'ACCESS_DENIED',
            'Acceso denegado a sección restringida',
            LOG_WARNING,
            ['perfil_usuario' => $_SESSION['user_profile'], 'perfiles_requeridos' => $profiles, 'uri' => $_SERVER['REQUEST_URI'] ?? '']
        );
        $_SESSION['error'] = 'No tienes permisos para acceder a esta sección.';
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

/**
 * Verifica si el usuario actual es superadmin
 */
function isSuperAdmin(): bool {
    return ($_SESSION['user_profile'] ?? '') === 'superadmin';
}

/**
 * Verifica si el usuario puede gestionar una empresa específica
 * Superadmin → todas | Administrador → solo la suya
 */
function canManageCompany(int $companyId): bool {
    if (isSuperAdmin()) return true;
    return (int)($_SESSION['user_company_id'] ?? 0) === $companyId;
}

function currentUser(): array {
    return [
        'id'         => $_SESSION['user_id'],
        'name'       => $_SESSION['user_name'],
        'email'      => $_SESSION['user_email'],
        'profile'    => $_SESSION['user_profile'],
        'branch_id'  => $_SESSION['user_branch_id'],
        'branch'     => $_SESSION['user_branch'],
        'position'   => $_SESSION['user_position'],
        'company_id' => $_SESSION['user_company_id'] ?? 1,
        'avatar'     => $_SESSION['user_avatar'] ?? null,
    ];
}

// ── Autenticación local (usuario + contraseña) ─────────────────────────────

/**
 * Devuelve el método de autenticación de una empresa dado su dominio de email
 * o company_id. 'microsoft' | 'local'
 */
function getCompanyAuthMethod(string $email): string {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT u.profile, u.company_id, u.password, c.auth_method
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        WHERE u.email = ? AND u.active = 1
        LIMIT 1
    ");
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();

    if (!$row) return 'microsoft';

    // Si tiene contraseña guardada → siempre es local
    // independiente de lo que diga la empresa
    if (!empty($row['password'])) return 'local';

    // Sin contraseña → usar método de la empresa
    return $row['auth_method'] ?: 'microsoft';
}

/**
 * Login con email + contraseña para empresas con auth_method = 'local'
 */
function loginLocal(string $email, string $password): bool {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT u.*, COALESCE(b.name,'Global') AS branch_name, c.auth_method
        FROM users u
        LEFT JOIN branches b  ON u.branch_id  = b.id
        JOIN companies c ON u.company_id = c.id
        WHERE u.email = ? AND u.active = 1 AND c.auth_method = 'local'
    ");
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !$user['password'] || !password_verify($password, $user['password'])) {
        logEvent(LOG_AUTH, 'LOGIN_LOCAL_FAIL',
            "Intento fallido (autenticación local): {$email}", LOG_WARNING);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']         = $user['id'];
    $_SESSION['user_name']       = $user['name'];
    $_SESSION['user_email']      = $user['email'];
    $_SESSION['user_profile']    = $user['profile'];
    $_SESSION['user_branch_id']  = $user['branch_id'];
    $_SESSION['user_branch']     = $user['branch_name'];
    $_SESSION['user_position']   = $user['position'] ?? '';
    $_SESSION['user_company_id'] = $user['company_id'];
    $_SESSION['user_avatar']     = null;

    logEvent(LOG_AUTH, 'LOGIN_LOCAL_OK',
        "Login local exitoso: {$user['name']} ({$user['email']})",
        LOG_INFO, ['perfil' => $user['profile'], 'sucursal' => $user['branch_name']]
    );
    return true;
}

// ── Flujo Microsoft OAuth 2.0 ──────────────────────────────────────────────

/**
 * Genera la URL de autorización de Microsoft y redirige al usuario.
 */
function redirectToMicrosoft(): void {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'     => AZURE_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri'  => AZURE_REDIRECT_URI,
        'scope'         => AZURE_SCOPES,
        'response_mode' => 'query',
        'state'         => $state,
        'prompt'        => 'select_account',   // Siempre muestra selección de cuenta
    ]);

    header('Location: ' . AZURE_AUTH_URL . '?' . $params);
    exit;
}

/**
 * Intercambia el código de autorización por un access token.
 */
function exchangeCodeForToken(string $code): ?array {
    $postData = http_build_query([
        'client_id'     => AZURE_CLIENT_ID,
        'client_secret' => AZURE_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => AZURE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
        'scope'         => AZURE_SCOPES,
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($postData),
        'content' => $postData,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents(AZURE_TOKEN_URL, false, $ctx);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    return isset($data['access_token']) ? $data : null;
}

/**
 * Obtiene el perfil del usuario desde Microsoft Graph API.
 */
function getMicrosoftProfile(string $accessToken): ?array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Bearer {$accessToken}\r\nAccept: application/json",
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents(AZURE_GRAPH_URL, false, $ctx);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    return isset($data['mail']) || isset($data['userPrincipalName']) ? $data : null;
}

/**
 * Callback OAuth: recibe el código, obtiene token, carga/crea usuario en BD
 * y establece la sesión si el usuario tiene perfil asignado.
 *
 * @return array ['status' => 'ok'|'no_profile'|'inactive'|'error', 'user' => array|null, 'ms_data' => array|null]
 */
function handleOAuthCallback(string $code, string $state): array {
    // Validar state CSRF
    if (empty($_SESSION['oauth_state']) || $state !== $_SESSION['oauth_state']) {
        logEvent(LOG_AUTH, 'OAUTH_STATE_MISMATCH', 'State CSRF inválido en callback OAuth', LOG_WARNING);
        return ['status' => 'error', 'message' => 'Error de seguridad (state inválido). Intenta nuevamente.'];
    }
    unset($_SESSION['oauth_state']);

    // Intercambiar código por token
    $tokenData = exchangeCodeForToken($code);
    if (!$tokenData) {
        logEvent(LOG_AUTH, 'OAUTH_TOKEN_FAIL', 'No se pudo obtener access token de Microsoft', LOG_ERROR_LVL);
        return ['status' => 'error', 'message' => 'No se pudo autenticar con Microsoft. Intenta nuevamente.'];
    }

    // Obtener perfil de Graph API
    $msProfile = getMicrosoftProfile($tokenData['access_token']);
    if (!$msProfile) {
        logEvent(LOG_AUTH, 'OAUTH_PROFILE_FAIL', 'No se pudo obtener perfil desde Microsoft Graph', LOG_ERROR_LVL);
        return ['status' => 'error', 'message' => 'No se pudo obtener tu perfil de Microsoft.'];
    }

    $email    = strtolower(trim($msProfile['mail'] ?? $msProfile['userPrincipalName'] ?? ''));
    $name     = trim($msProfile['displayName'] ?? '');
    $position = trim($msProfile['jobTitle'] ?? '');
    $msId     = $msProfile['id'] ?? null;

    if (!$email) {
        return ['status' => 'error', 'message' => 'No se pudo obtener tu email de Microsoft.'];
    }

    $db = getDB();

    // Buscar usuario en sistema por email
    $stmt = $db->prepare("
        SELECT u.*, b.name AS branch_name
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        WHERE u.email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // ── Usuario nuevo: crear registro pendiente de asignación de perfil ──
        // Se guarda con perfil NULL y active=0 hasta que un admin le asigne perfil
        $db->prepare("
            INSERT INTO users (name, email, ms_id, position, profile, branch_id, active, created_at)
            VALUES (?, ?, ?, ?, NULL, NULL, 0, NOW())
            ON DUPLICATE KEY UPDATE name=VALUES(name), ms_id=VALUES(ms_id), position=VALUES(position)
        ")->execute([$name, $email, $msId, $position]);

        logEvent(LOG_AUTH, 'LOGIN_NO_PROFILE',
            "Primer acceso sin perfil asignado: {$name} ({$email})",
            LOG_WARNING,
            ['email' => $email, 'ms_id' => $msId]
        );

        return ['status' => 'no_profile', 'ms_data' => ['name' => $name, 'email' => $email]];
    }

    // Actualizar datos Microsoft (nombre y ms_id pueden cambiar)
    $db->prepare("UPDATE users SET ms_id=?, name=?, position=COALESCE(NULLIF(?,''),(position)), updated_at=NOW() WHERE id=?")
       ->execute([$msId, $name, $position, $user['id']]);

    if (!$user['active']) {
        logEvent(LOG_AUTH, 'LOGIN_INACTIVE',
            "Intento de acceso con usuario inactivo: {$name} ({$email})",
            LOG_WARNING,
            ['user_id' => $user['id']]
        );
        return ['status' => 'inactive', 'ms_data' => ['name' => $name, 'email' => $email]];
    }

    if (empty($user['profile'])) {
        return ['status' => 'no_profile', 'ms_data' => ['name' => $name, 'email' => $email]];
    }

    // Superadmin no requiere sucursal ni empresa asignada
    if ($user['profile'] !== 'superadmin' && !$user['active']) {
        logEvent(LOG_AUTH, 'LOGIN_INACTIVE',
            "Intento de acceso con usuario inactivo: {$name} ({$email})",
            LOG_WARNING, ['user_id' => $user['id']]
        );
        return ['status' => 'inactive', 'ms_data' => ['name' => $name, 'email' => $email]];
    }

    // ── Todo OK: establecer sesión ─────────────────────────────────────────
    session_regenerate_id(true);
    $_SESSION['user_id']         = $user['id'];
    $_SESSION['user_name']       = $name;
    $_SESSION['user_email']      = $email;
    $_SESSION['user_profile']    = $user['profile'];
    $_SESSION['user_branch_id']  = $user['branch_id'];   // NULL para superadmin
    $_SESSION['user_branch']     = $user['branch_name'] ?? 'Global';
    $_SESSION['user_position']   = $user['position'] ?? $position;
    $_SESSION['user_company_id'] = $user['company_id'];  // NULL para superadmin
    $_SESSION['user_avatar']     = null; // Graph requiere token separado para foto

    logEvent(LOG_AUTH, 'LOGIN_OK',
        "Inicio de sesión Microsoft exitoso — {$name} ({$email})",
        LOG_INFO,
        ['perfil' => $user['profile'], 'sucursal' => $user['branch_name'] ?? '', 'ms_id' => $msId]
    );

    return ['status' => 'ok', 'user' => $user];
}

/**
 * Cierra la sesión local y redirige al logout de Microsoft.
 */
function logout(): void {
    if (isLoggedIn()) {
        logEvent(LOG_AUTH, 'LOGOUT',
            'Cierre de sesión — ' . ($_SESSION['user_name'] ?? '') . ' (' . ($_SESSION['user_email'] ?? '') . ')',
            LOG_INFO
        );
    }

    session_destroy();

    // Logout también en Microsoft para limpiar la sesión SSO
    $logoutUrl = 'https://login.microsoftonline.com/' . AZURE_TENANT_ID
        . '/oauth2/v2.0/logout?post_logout_redirect_uri=' . urlencode(APP_URL . '/index.php');

    header('Location: ' . $logoutUrl);
    exit;
}
