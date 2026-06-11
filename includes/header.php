<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#111111">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= htmlspecialchars($pageTitle ?? 'FuelControl') ?> — FuelControl LESA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
</head>
<body>
<?php $user = currentUser(); ?>

<!-- Overlay para cerrar sidebar en mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="layout">
  <!-- ════════════════════════════════════════════════════════
       SIDEBAR
  ═════════════════════════════════════════════════════════ -->
  <aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
      <span class="brand-icon">⛽</span>
      <div class="brand-text">
        <span class="brand-name">FuelControl</span>
        <span class="brand-sub"><?= $user['profile'] === 'superadmin' ? 'Super Administrador' : 'Latin Equipment Chile' ?></span>
      </div>
    </div>

    <nav class="sidebar-nav">

      <a href="<?= APP_URL ?>/dashboard.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) === 'dashboard.php') ? 'active' : '' ?>">
        <span class="nav-icon">◼</span> Dashboard
      </a>

      <?php if (in_array($user['profile'], ['solicitante','cargador','administrador','superadmin'])): ?>
      <div class="nav-group-label">Combustible</div>
      <a href="<?= APP_URL ?>/modules/requests/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/requests/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">📋</span> Solicitudes
      </a>
      <a href="<?= APP_URL ?>/modules/requests/create.php" class="nav-item">
        <span class="nav-icon">➕</span> Nueva Solicitud
      </a>
      <?php endif; ?>

      <?php if (in_array($user['profile'], ['aprobador','administrador','superadmin'])): ?>
      <div class="nav-group-label">Aprobaciones</div>
      <a href="<?= APP_URL ?>/modules/approvals/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/approvals/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">✅</span> Mis Aprobaciones
      </a>
      <?php endif; ?>

      <?php if (in_array($user['profile'], ['cargador','administrador','superadmin'])): ?>
      <div class="nav-group-label">Estanque</div>
      <a href="<?= APP_URL ?>/modules/tanks/load.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) === 'load.php') ? 'active' : '' ?>">
        <span class="nav-icon">🛢</span> Cargar Estanque
      </a>
      <a href="<?= APP_URL ?>/modules/tanks/adjust.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) === 'adjust.php') ? 'active' : '' ?>">
        <span class="nav-icon">⚖</span> Ajuste Estanque
      </a>
      <a href="<?= APP_URL ?>/modules/requests/pending_delivery.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) === 'pending_delivery.php') ? 'active' : '' ?>">
        <span class="nav-icon">🚚</span> Entregas Pendientes
      </a>
      <?php endif; ?>

      <div class="nav-group-label">Combustible Vehículos</div>
      <a href="<?= APP_URL ?>/modules/service_fuel/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/service_fuel/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">🚗</span> Cargas Servicentro
      </a>
      <a href="<?= APP_URL ?>/modules/service_fuel/create.php" class="nav-item">
        <span class="nav-icon">➕</span> Registrar Carga
      </a>
      <a href="<?= APP_URL ?>/modules/vehicles/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/vehicles/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">🚙</span> Vehículos
      </a>

      <div class="nav-group-label">Administración</div>

      <?php if ($user['profile'] === 'superadmin'): ?>
      <!-- ── SUPERADMIN: gestión global ─────────────────────── -->
      <a href="<?= APP_URL ?>/modules/companies/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/companies/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">🏛</span> Empresas
      </a>
      <a href="<?= APP_URL ?>/modules/branches/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/branches/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">🏢</span> Sucursales
      </a>
      <a href="<?= APP_URL ?>/modules/users/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/users/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">👤</span> Usuarios
        <?php try {
          $pendCount = getDB()->query("SELECT COUNT(*) FROM v_pending_users")->fetchColumn();
          if ($pendCount > 0): ?><span style="margin-left:auto;background:#e03d3d;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;"><?= $pendCount ?></span><?php endif;
        } catch(Exception $e) {} ?>
      </a>
      <a href="<?= APP_URL ?>/modules/machines/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/machines/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">⚙</span> Máquinas
      </a>
      <a href="<?= APP_URL ?>/modules/flow/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/flow/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">🔀</span> Flujo Aprobación
      </a>
      <a href="<?= APP_URL ?>/modules/logs/viewer.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/logs/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">📜</span> Logs del Sistema
      </a>
      <a href="<?= APP_URL ?>/modules/reports/index.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/reports/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">📊</span> Reportes
      </a>

      <?php elseif ($user['profile'] === 'administrador'): ?>
      <!-- ── ADMIN: gestión dentro de su empresa ────────────── -->
      <a href="<?= APP_URL ?>/modules/branches/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/branches/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">🏢</span> Sucursales
      </a>
      <a href="<?= APP_URL ?>/modules/machines/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/machines/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">⚙</span> Máquinas
      </a>
      <a href="<?= APP_URL ?>/modules/users/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/users/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">👤</span> Usuarios
        <?php try {
          $pendCount = getDB()->query("SELECT COUNT(*) FROM v_pending_users")->fetchColumn();
          if ($pendCount > 0): ?><span style="margin-left:auto;background:#e03d3d;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;"><?= $pendCount ?></span><?php endif;
        } catch(Exception $e) {} ?>
      </a>
      <a href="<?= APP_URL ?>/modules/flow/list.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/flow/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">🔀</span> Flujo Aprobación
      </a>
      <a href="<?= APP_URL ?>/modules/logs/viewer.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/logs/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">📜</span> Logs del Sistema
      </a>
      <a href="<?= APP_URL ?>/modules/reports/index.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/reports/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">📊</span> Reportes
      </a>

      <?php else: ?>
      <!-- ── Otros perfiles: solo reportes ─────────────────── -->
      <a href="<?= APP_URL ?>/modules/reports/index.php" class="nav-item <?= (strpos($_SERVER['PHP_SELF'], '/reports/') !== false) ? 'active' : '' ?>">
        <span class="nav-icon">📊</span> Reportes
      </a>
      <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
      <div class="user-info">
        <div class="user-avatar"><?= strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
        <div class="user-details">
          <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
          <span class="user-profile <?= $user['profile'] ?>"><?= ucfirst($user['profile']) ?></span>
        </div>
      </div>
      <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">
        ⏻ Cerrar sesión
      </a>
    </div>

  </aside><!-- /sidebar -->

  <!-- ════════════════════════════════════════════════════════
       MAIN CONTENT
  ═════════════════════════════════════════════════════════ -->
  <main class="main-content">

    <!-- Topbar -->
    <div class="topbar">
      <div class="topbar-left">
        <!-- Hamburger (visible en mobile) -->
        <button class="hamburger" id="hamburger" aria-label="Abrir menú">
          <span></span><span></span><span></span>
        </button>
        <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      </div>
      <div class="topbar-branch">
        <?php if ($user['profile'] === 'superadmin'): ?>
          🌐 Global — Todas las empresas
        <?php else: ?>
          🏢 <?= htmlspecialchars($user['branch']) ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($_SESSION['success'])): ?>
      <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
      <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
      <div class="alert alert-error"><?= htmlspecialchars($_SESSION['error']) ?></div>
      <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="content-body">
