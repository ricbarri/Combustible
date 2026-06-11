<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db   = getDB();
$user = currentUser();

if (isset($_GET['delete']) && in_array($user['profile'], ['administrador','superadmin'])) {
    $db->prepare("UPDATE vehicles SET active=0 WHERE id=?")->execute([(int)$_GET['delete']]);
    $_SESSION['success'] = 'Vehículo desactivado.';
    header('Location: ' . APP_URL . '/modules/vehicles/list.php'); exit;
}

// Marcar como vehículo por defecto del usuario
if (isset($_GET['set_default'])) {
    $db->prepare("UPDATE users SET default_vehicle_id=? WHERE id=?")->execute([(int)$_GET['set_default'], $user['id']]);
    $_SESSION['success'] = 'Vehículo por defecto actualizado.';
    header('Location: ' . APP_URL . '/modules/vehicles/list.php'); exit;
}

$vehicles = $db->query("
    SELECT v.*, b.name AS branch_name, c.name AS company_name,
           u.name AS responsible_name
    FROM vehicles v
    JOIN branches b  ON v.branch_id = b.id
    JOIN companies c ON v.company_id = c.id
    LEFT JOIN users u ON v.responsible_id = u.id
    WHERE v.active = 1
    ORDER BY c.name, b.name, v.name
")->fetchAll();

// Vehículo por defecto del usuario actual
$defaultStmt = $db->prepare("SELECT default_vehicle_id FROM users WHERE id=?");
$defaultStmt->execute([$user['id']]);
$defaultVehicleId = (int)$defaultStmt->fetchColumn();

$pageTitle = 'Vehículos';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="flex-between">
  <div class="text-muted" style="font-size:13px;">
    Tu vehículo por defecto: <strong class="text-accent"><?php
      $dv = array_filter($vehicles, fn($v) => $v['id'] == $defaultVehicleId);
      echo $dv ? htmlspecialchars(array_values($dv)[0]['name'] . ' — ' . array_values($dv)[0]['plate']) : 'Sin asignar';
    ?></strong>
  </div>
  <?php if (in_array($user['profile'], ['administrador','superadmin'])): ?>
  <a href="<?= APP_URL ?>/modules/vehicles/form.php" class="btn btn-primary">➕ Nuevo Vehículo</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Nombre</th><th>Patente</th><th>Empresa</th><th>Sucursal</th><th>Responsable</th><th>Mi default</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($vehicles as $v): $isDefault = ($v['id'] == $defaultVehicleId); ?>
        <tr <?= $isDefault ? "style='background:var(--lesa-yellow-soft);'" : '' ?>>
          <td><strong><?= htmlspecialchars($v['name']) ?></strong></td>
          <td><code style="background:var(--bg-input);padding:2px 8px;border-radius:4px;letter-spacing:1.5px;"><?= htmlspecialchars($v['plate']) ?></code></td>
          <td class="text-muted"><?= htmlspecialchars($v['company_name']) ?></td>
          <td><?= htmlspecialchars($v['branch_name']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($v['responsible_name'] ?? '—') ?></td>
          <td style="text-align:center;">
            <?php if ($isDefault): ?>
              <span class="badge badge-approved">⭐ Mi vehículo</span>
            <?php else: ?>
              <a href="?set_default=<?= $v['id'] ?>" class="btn btn-secondary btn-sm">Usar por defecto</a>
            <?php endif; ?>
          </td>
          <td style="display:flex;gap:6px;">
            <?php if (in_array($user['profile'], ['administrador','superadmin'])): ?>
            <a href="<?= APP_URL ?>/modules/vehicles/form.php?id=<?= $v['id'] ?>" class="btn btn-secondary btn-sm">✏ Editar</a>
            <a href="?delete=<?= $v['id'] ?>" class="btn btn-danger btn-sm" data-confirm="¿Desactivar vehículo?">✕</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($vehicles)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:30px;">Sin vehículos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
