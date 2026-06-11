<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

if (isset($_GET['delete'])) {
    $db->prepare("UPDATE machines SET active=0 WHERE id=?")->execute([(int)$_GET['delete']]);
    $_SESSION['success'] = 'Máquina desactivada.';
    header('Location: ' . APP_URL . '/modules/machines/list.php');
    exit;
}

$machines = $db->query("SELECT m.*, b.name AS branch_name FROM machines m JOIN branches b ON m.branch_id=b.id ORDER BY m.name")->fetchAll();

$pageTitle = 'Máquinas';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="flex-between">
  <div></div>
  <a href="<?= APP_URL ?>/modules/machines/form.php" class="btn btn-primary">➕ Nueva Máquina</a>
</div>
<div class="card">
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Nombre</th><th>Código</th><th>Sucursal</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($machines as $m): ?>
        <tr>
          <td><strong><?= htmlspecialchars($m['name']) ?></strong></td>
          <td><code style="background:var(--bg-input);padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($m['code']) ?></code></td>
          <td><?= htmlspecialchars($m['branch_name']) ?></td>
          <td><span class="badge <?= $m['active'] ? 'badge-approved' : 'badge-rejected' ?>"><?= $m['active'] ? 'Activa' : 'Inactiva' ?></span></td>
          <td style="display:flex;gap:6px;">
            <a href="<?= APP_URL ?>/modules/machines/form.php?id=<?= $m['id'] ?>" class="btn btn-secondary btn-sm">✏ Editar</a>
            <?php if ($m['active']): ?>
            <a href="?delete=<?= $m['id'] ?>" class="btn btn-danger btn-sm" data-confirm="¿Desactivar esta máquina?">✕</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
