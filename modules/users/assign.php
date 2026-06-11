<?php
// modules/users/assign.php — Asignar perfil y sucursal a un usuario Microsoft
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/users/list.php'); exit; }

$userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$id]);
$editUser = $userStmt->fetch();
if (!$editUser) { $_SESSION['error'] = 'Usuario no encontrado.'; header('Location: ' . APP_URL . '/modules/users/list.php'); exit; }

$branches = $db->query("SELECT id, name FROM branches WHERE active=1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profile   = $_POST['profile']   ?? '';
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $position  = trim($_POST['position'] ?? '');
    $active    = 1;

    $validProfiles = ['solicitante', 'aprobador', 'cargador', 'administrador', 'superadmin'];

    if (!in_array($profile, $validProfiles)) {
        $_SESSION['error'] = 'Selecciona un perfil válido.';
    } elseif (!$branch_id) {
        $_SESSION['error'] = 'Selecciona una sucursal.';
    } else {
        $db->prepare("
            UPDATE users
            SET profile = ?, branch_id = ?, position = ?, active = 1, updated_at = NOW()
            WHERE id = ?
        ")->execute([$profile, $branch_id, $position, $id]);

        logEvent(LOG_USER, 'USER_PROFILE_ASSIGNED',
            "Perfil asignado a {$editUser['name']} ({$editUser['email']}): perfil={$profile}, sucursal ID={$branch_id}",
            LOG_INFO,
            ['user_id' => $id, 'email' => $editUser['email'], 'perfil' => $profile, 'branch_id' => $branch_id]
        );

        $_SESSION['success'] = "Perfil asignado correctamente a {$editUser['name']}. Ya puede acceder al sistema.";
        header('Location: ' . APP_URL . '/modules/users/list.php');
        exit;
    }
}

$isPending = empty($editUser['profile']) || !$editUser['active'];
$pageTitle = $isPending ? 'Activar Usuario' : 'Editar Perfil de Usuario';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:620px;">

  <?php if ($isPending): ?>
  <div style="background:rgba(245,166,35,.1);border:1px solid var(--accent);border-radius:var(--radius);padding:14px 18px;margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;">
    <span style="font-size:24px;">⏳</span>
    <div>
      <strong style="color:var(--accent);">Usuario pendiente de activación</strong><br>
      <span class="text-muted" style="font-size:13px;">
        <strong><?= htmlspecialchars($editUser['name']) ?></strong> (<?= htmlspecialchars($editUser['email']) ?>)
        inició sesión con Microsoft pero aún no tiene perfil asignado.<br>
        Asígnale un perfil y sucursal para que pueda acceder al sistema.
      </span>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><span class="card-title"><?= $pageTitle ?></span></div>
    <div class="card-body">

      <!-- Info del usuario desde Microsoft -->
      <div style="background:var(--bg-panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:20px;">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--text-dim);margin-bottom:10px;">
          Datos sincronizados desde Microsoft 365
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <div class="text-muted" style="font-size:11px;">Nombre</div>
            <div style="font-weight:600;"><?= htmlspecialchars($editUser['name']) ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:11px;">Email corporativo</div>
            <div style="font-weight:600;"><?= htmlspecialchars($editUser['email']) ?></div>
          </div>
          <?php if ($editUser['ms_id']): ?>
          <div>
            <div class="text-muted" style="font-size:11px;">Microsoft ID</div>
            <div style="font-size:11px;font-family:monospace;color:var(--text-dim);"><?= htmlspecialchars($editUser['ms_id']) ?></div>
          </div>
          <?php endif; ?>
          <div>
            <div class="text-muted" style="font-size:11px;">Primer acceso</div>
            <div style="font-size:13px;"><?= date('d/m/Y H:i', strtotime($editUser['created_at'])) ?></div>
          </div>
        </div>
      </div>

      <form method="POST">
        <div class="form-grid">
          <div class="form-group">
            <label>Perfil en FuelControl <span style="color:var(--danger)">*</span></label>
            <select name="profile" required>
              <option value="">-- Seleccionar perfil --</option>
              <option value="superadmin" <?= ($editUser['profile']==='superadmin')?'selected':'' ?>>
                🌐 Super Administrador — Acceso global sobre todas las empresas
              </option>
              <option value="administrador" <?= ($editUser['profile']==='administrador')?'selected':'' ?>>
                ⭐ Administrador — Acceso total dentro de su empresa
              </option>
              <option value="solicitante" <?= ($editUser['profile']==='solicitante')?'selected':'' ?>>
                Solicitante — Crea solicitudes de combustible
              </option>
              <option value="aprobador" <?= ($editUser['profile']==='aprobador')?'selected':'' ?>>
                Aprobador — Aprueba o rechaza solicitudes
              </option>
              <option value="cargador" <?= ($editUser['profile']==='cargador')?'selected':'' ?>>
                Cargador — Gestiona estanque y realiza entregas
              </option>
            </select>
          </div>
          <div class="form-group">
            <label>Sucursal <span style="color:var(--danger)">*</span></label>
            <select name="branch_id" required>
              <option value="">-- Seleccionar sucursal --</option>
              <?php foreach ($branches as $b): ?>
              <option value="<?= $b['id'] ?>" <?= ($editUser['branch_id']==$b['id'])?'selected':'' ?>>
                <?= htmlspecialchars($b['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full">
            <label>Cargo en la empresa</label>
            <input type="text" name="position"
              value="<?= htmlspecialchars($editUser['position'] ?? '') ?>"
              placeholder="Se sincroniza desde Microsoft, puedes ajustarlo">
          </div>
        </div>

        <!-- Descripción de perfiles -->
        <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;margin:16px 0;font-size:12px;color:var(--text-muted);line-height:1.8;">
          <strong style="color:var(--text);display:block;margin-bottom:6px;">Descripción de perfiles:</strong>
          ⭐ <strong style="color:var(--accent);">Administrador</strong> — Acceso total: puede hacer todo lo que hacen los demás perfiles.<br>
          🟡 <strong>Solicitante</strong> — Puede crear solicitudes de combustible y ver el estado de las suyas.<br>
          🟠 <strong>Aprobador</strong> — Recibe notificaciones y aprueba/rechaza solicitudes en el flujo configurado.<br>
          🟢 <strong>Cargador</strong> — Gestiona el estanque, realiza ajustes y registra entregas de combustible.
        </div>

        <div style="display:flex;gap:10px;margin-top:20px;">
          <button type="submit" class="btn btn-primary">
            <?= $isPending ? '🔑 Activar y asignar perfil' : '💾 Guardar cambios' ?>
          </button>
          <a href="<?= APP_URL ?>/modules/users/list.php" class="btn btn-secondary">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
