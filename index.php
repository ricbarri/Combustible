<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

// ── Paso 1: usuario ingresa su email para detectar método de auth ──────────
// ── Paso 2a: si es Microsoft → redirigir OAuth ────────────────────────────
// ── Paso 2b: si es local → mostrar campo de contraseña ───────────────────

$step    = 1;   // 1=pedir email, 2=pedir password (local), 3=procesando
$email   = trim($_POST['email'] ?? $_GET['email'] ?? '');
$method  = '';  // 'microsoft' | 'local'
$error   = $_SESSION['login_error']   ?? '';
$warning = $_SESSION['login_warning'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_warning']);

// Acción directa: login Microsoft (viene del callback o del botón)
if (isset($_GET['action']) && $_GET['action'] === 'ms_login') {
    redirectToMicrosoft();
}

// POST: determinar método o procesar login local
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (isset($_POST['check_method']) && $email) {
        // Paso 1 → detectar método
        $method = getCompanyAuthMethod($email);
        if ($method === 'microsoft') {
            // Redirigir directo a Microsoft
            redirectToMicrosoft();
        } else {
            // Mostrar campo contraseña
            $step = 2;
        }
    } elseif (isset($_POST['do_login']) && $email) {
        // Paso 2 → login local
        $password = $_POST['password'] ?? '';
        if (loginLocal($email, $password)) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        } else {
            $error = 'Correo o contraseña incorrectos.';
            $step  = 2;
            $method = 'local';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#111111">
<title>FuelControl — Acceso</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
<style>
.ms-btn {
  display:flex;align-items:center;justify-content:center;gap:12px;
  width:100%;padding:13px 20px;background:#fff;color:#1a1a1a;
  border:1.5px solid #d0d0d0;border-radius:6px;
  font-family:var(--font-ui);font-size:15px;font-weight:700;
  text-decoration:none;cursor:pointer;transition:all .2s;min-height:var(--touch-min);
}
.ms-btn:hover { background:#f3f3f3;border-color:#0078d4;box-shadow:0 2px 12px rgba(0,120,212,.2); }
.ms-logo { width:22px;height:22px;flex-shrink:0; }
.divider { display:flex;align-items:center;gap:10px;margin:18px 0;color:var(--text-dim);font-size:12px; }
.divider::before,.divider::after { content:'';flex:1;height:1px;background:var(--border); }
.info-box { background:rgba(240,184,0,.07);border:1px solid rgba(240,184,0,.25);border-radius:var(--radius);padding:14px 16px;font-size:12px;color:var(--text-muted);margin-top:16px;line-height:1.8; }
.info-box strong { color:var(--accent); }
.login-box { border-top:3px solid var(--accent); }
.login-wrap { background-image:radial-gradient(ellipse at 10% 30%,rgba(240,184,0,0.07) 0%,transparent 50%),radial-gradient(ellipse at 90% 70%,rgba(240,184,0,0.04) 0%,transparent 50%); }
.back-link { display:block;text-align:center;margin-top:14px;font-size:12px;color:var(--text-muted);text-decoration:none; }
.back-link:hover { color:var(--accent); }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-header">
      <div class="login-logo-wrap">
        <span class="login-icon">⛽</span>
        <span class="login-title">FuelControl</span>
      </div>
      <div class="login-sub">Sistema de Gestión de Combustible</div>
    </div>
    <div class="login-body">

      <?php if ($error): ?>
        <div class="alert alert-error" style="margin:0 0 16px;"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($warning): ?>
        <div class="alert" style="background:rgba(240,184,0,.1);border-color:var(--accent);color:var(--accent);margin:0 0 16px;"><?= htmlspecialchars($warning) ?></div>
      <?php endif; ?>

      <?php if ($step === 1): ?>
      <!-- ── Paso 1: ingresar email ──────────────────────────────── -->
      <p style="color:var(--text-muted);font-size:13px;text-align:center;margin-bottom:20px;line-height:1.5;">
        Ingresa tu correo corporativo para continuar
      </p>
      <form method="POST">
        <input type="hidden" name="check_method" value="1">
        <div class="form-group" style="margin-bottom:16px;">
          <label>Correo electrónico</label>
          <input type="email" name="email" required autofocus
            placeholder="usuario@empresa.cl"
            value="<?= htmlspecialchars($email) ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
          Continuar →
        </button>
      </form>

      <div class="divider">o</div>

      <!-- Botón directo Microsoft (para empresas que saben que usan MS) -->
      <a href="?action=ms_login" class="ms-btn">
        <svg class="ms-logo" viewBox="0 0 23 23" xmlns="http://www.w3.org/2000/svg">
          <rect x="1"  y="1"  width="10" height="10" fill="#f25022"/>
          <rect x="12" y="1"  width="10" height="10" fill="#7fba00"/>
          <rect x="1"  y="12" width="10" height="10" fill="#00a4ef"/>
          <rect x="12" y="12" width="10" height="10" fill="#ffb900"/>
        </svg>
        Continuar con Microsoft 365
      </a>

      <div class="info-box">
        <strong>¿Cuál usar?</strong><br>
        • Si tu empresa usa <strong>Office 365</strong> → haz clic en "Continuar con Microsoft"<br>
        • Si tu empresa usa <strong>acceso local</strong> → ingresa tu correo arriba
      </div>

      <?php elseif ($step === 2): ?>
      <!-- ── Paso 2: contraseña (auth local) ────────────────────── -->
      <div style="text-align:center;margin-bottom:16px;">
        <div style="font-size:12px;color:var(--text-muted);">Ingresando como</div>
        <div style="font-weight:700;color:var(--accent);font-size:14px;"><?= htmlspecialchars($email) ?></div>
      </div>
      <form method="POST">
        <input type="hidden" name="do_login" value="1">
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
        <div class="form-group" style="margin-bottom:16px;">
          <label>Contraseña</label>
          <input type="password" name="password" required autofocus placeholder="••••••••">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
          Iniciar sesión
        </button>
      </form>
      <a href="<?= APP_URL ?>/index.php" class="back-link">← Volver / Cambiar correo</a>

      <?php endif; ?>

    </div>
  </div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
