<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('administrador','superadmin');
$db = getDB();

if (isset($_GET['delete'])) {
    $db->prepare("UPDATE companies SET active=0 WHERE id=?")->execute([(int)$_GET['delete']]);
    $_SESSION['success'] = 'Empresa desactivada.';
    header('Location: ' . APP_URL . '/modules/companies/list.php'); exit;
}

$companies = $db->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM branches b WHERE b.company_id=c.id AND b.active=1) AS total_branches,
        (SELECT COUNT(*) FROM users u WHERE u.company_id=c.id AND u.active=1)    AS total_users
    FROM companies c ORDER BY c.name
")->fetchAll();

$pageTitle = 'Empresas';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="flex-between">
  <div></div>
  <a href="<?= APP_URL ?>/modules/companies/form.php" class="btn btn-primary">➕ Nueva Empresa</a>
</div>
<div class="card">
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>Nombre</th><th>RUT</th><th>Autenticación</th><th>Sucursales</th><th>Usuarios</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $c): ?>
        <tr>
          <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
          <td class="text-muted"><?= htmlspecialchars($c['rut'] ?? '—') ?></td>
          <td>
            <?php if (($c['auth_method']??'microsoft') === 'local'): ?>
              <span class="badge badge-pending">🔑 Local</span>
            <?php else: ?>
              <span class="badge badge-delivered">🔵 Microsoft 365</span>
            <?php endif; ?>
          </td>
          <td><?= $c['total_branches'] ?></td>
          <td><?= $c['total_users'] ?></td>
          <td><span class="badge <?= $c['active']?'badge-approved':'badge-rejected' ?>"><?= $c['active']?'Activa':'Inactiva' ?></span></td>
          <td style="display:flex;gap:6px;">
            <a href="<?= APP_URL ?>/modules/companies/form.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">✏ Editar</a>
            <?php if ($c['active'] && $c['id']!=1): ?>
            <a href="?delete=<?= $c['id'] ?>" class="btn btn-danger btn-sm" data-confirm="¿Desactivar empresa?">✕</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
