<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

if (isset($_GET['delete'])) {
    $db->prepare("UPDATE users SET active=0 WHERE id=?")->execute([(int)$_GET['delete']]);
    logEvent(LOG_USER, 'USER_DEACTIVATED', 'Usuario desactivado ID:' . (int)$_GET['delete'], LOG_WARNING);
    $_SESSION['success'] = 'Usuario desactivado.';
    header('Location: ' . APP_URL . '/modules/users/list.php');
    exit;
}

$tab = $_GET['tab'] ?? 'active';

// Usuarios activos con perfil asignado
$activeUsers = $db->query("
    SELECT u.*, b.name AS branch_name
    FROM users u
    LEFT JOIN branches b ON u.branch_id = b.id
    WHERE u.active = 1 AND u.profile IS NOT NULL
    ORDER BY u.name
")->fetchAll();

// Usuarios pendientes (sin perfil o sucursal)
$pendingUsers = $db->query("
    SELECT * FROM v_pending_users
")->fetchAll();

$profileLabels = ['administrador'=>'Administrador','solicitante'=>'Solicitante','aprobador'=>'Aprobador','cargador'=>'Cargador'];
$pageTitle = 'Usuarios';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="flex-between mb-16">
  <div class="filter-tabs">
    <a href="?tab=active"  class="filter-tab <?= $tab==='active'?'active':'' ?>">Activos (<?= count($activeUsers) ?>)</a>
    <a href="?tab=pending" class="filter-tab <?= $tab==='pending'?'active':'' ?>">
      Pendientes de activación
      <?php if (count($pendingUsers) > 0): ?>
        <span style="background:#e74c3c;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;margin-left:4px;"><?= count($pendingUsers) ?></span>
      <?php endif; ?>
    </a>
  </div>
</div>

<?php if ($tab === 'pending'): ?>
<!-- ── Usuarios pendientes ──────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <span class="card-title">⏳ Pendientes de Activación</span>
    <span class="text-muted" style="font-size:12px;">Usuarios que iniciaron sesión con Microsoft pero aún no tienen perfil asignado</span>
  </div>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Nombre</th><th>Email (Microsoft)</th><th>Cargo (MS)</th><th>Primer acceso</th><th>Acción</th></tr>
      </thead>
      <tbody>
        <?php foreach ($pendingUsers as $u): ?>
        <tr>
          <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
          <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($u['position'] ?? '—') ?></td>
          <td class="text-muted"><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></td>
          <td>
            <a href="<?= APP_URL ?>/modules/users/assign.php?id=<?= $u['id'] ?>" class="btn btn-primary btn-sm">🔑 Asignar Perfil</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($pendingUsers)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:30px;">
          ✅ No hay usuarios pendientes de activación.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- ── Usuarios activos ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Usuarios del Sistema</span>
  </div>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Nombre</th><th>Correo corporativo</th><th>Sucursal</th><th>Perfil</th><th>Cargo</th><th>Microsoft ID</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($activeUsers as $u): ?>
        <tr>
          <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
          <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
          <td><?= htmlspecialchars($u['branch_name'] ?? '—') ?></td>
          <td>
            <span class="badge user-profile <?= $u['profile'] ?>">
              <?= $profileLabels[$u['profile']] ?? $u['profile'] ?>
            </span>
          </td>
          <td class="text-muted"><?= htmlspecialchars($u['position'] ?? '—') ?></td>
          <td style="font-size:11px;color:var(--text-dim);font-family:monospace;">
            <?= $u['ms_id'] ? '✅ Vinculado' : '<span style="color:var(--warning)">Sin vincular</span>' ?>
          </td>
          <td style="display:flex;gap:6px;">
            <a href="<?= APP_URL ?>/modules/users/assign.php?id=<?= $u['id'] ?>" class="btn btn-secondary btn-sm">✏ Editar perfil</a>
            <?php if ($u['id'] != currentUser()['id']): ?>
            <a href="?delete=<?= $u['id'] ?>" class="btn btn-danger btn-sm" data-confirm="¿Desactivar este usuario?">✕</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($activeUsers)): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">Sin usuarios activos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
