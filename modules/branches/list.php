<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $db->prepare("UPDATE branches SET active=0 WHERE id=?")->execute([$id]);
        $_SESSION['success'] = 'Sucursal desactivada correctamente.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error al eliminar la sucursal.';
    }
    header('Location: ' . APP_URL . '/modules/branches/list.php');
    exit;
}

$branches = $db->query("SELECT b.*, COUNT(DISTINCT u.id) AS total_users, t.current_liters FROM branches b LEFT JOIN users u ON u.branch_id=b.id AND u.active=1 LEFT JOIN tanks t ON t.branch_id=b.id GROUP BY b.id ORDER BY b.name")->fetchAll();

$pageTitle = 'Sucursales';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="flex-between">
  <div></div>
  <a href="<?= APP_URL ?>/modules/branches/form.php" class="btn btn-primary">➕ Nueva Sucursal</a>
</div>
<div class="card">
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Nombre</th><th>Dirección</th><th>Teléfono</th><th>Usuarios</th><th>Estanque</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($branches as $b): ?>
        <tr>
          <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
          <td class="text-muted"><?= htmlspecialchars($b['address'] ?? '—') ?></td>
          <td class="text-muted"><?= htmlspecialchars($b['phone'] ?? '—') ?></td>
          <td><?= $b['total_users'] ?> usuarios</td>
          <td><?= $b['current_liters'] !== null ? number_format($b['current_liters'],0).' L' : '—' ?></td>
          <td><span class="badge <?= $b['active'] ? 'badge-approved' : 'badge-rejected' ?>"><?= $b['active'] ? 'Activa' : 'Inactiva' ?></span></td>
          <td style="display:flex;gap:6px;">
            <a href="<?= APP_URL ?>/modules/branches/form.php?id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">✏ Editar</a>
            <?php if ($b['active']): ?>
            <a href="?delete=<?= $b['id'] ?>" class="btn btn-danger btn-sm" data-confirm="¿Desactivar esta sucursal?">✕</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
