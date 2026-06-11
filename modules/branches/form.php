<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$branch = $id ? $db->prepare("SELECT * FROM branches WHERE id=?") : null;
if ($branch) { $branch->execute([$id]); $branch = $branch->fetch(); }
$branch = $branch ?: ['name'=>'','address'=>'','phone'=>'','active'=>1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $active  = isset($_POST['active']) ? 1 : 0;

    if (!$name) { $_SESSION['error'] = 'El nombre es obligatorio.'; }
    else {
        if ($id) {
            $db->prepare("UPDATE branches SET name=?,address=?,phone=?,active=?,updated_at=NOW() WHERE id=?")->execute([$name,$address,$phone,$active,$id]);
            $_SESSION['success'] = 'Sucursal actualizada.';
        } else {
            $db->prepare("INSERT INTO branches (name,address,phone,active) VALUES (?,?,?,?)")->execute([$name,$address,$phone,1]);
            $newId = $db->lastInsertId();
            // Crear estanque automáticamente
            $db->prepare("INSERT INTO tanks (branch_id,capacity,current_liters) VALUES (?,1000,0)")->execute([$newId]);
            $_SESSION['success'] = 'Sucursal creada con estanque inicial.';
        }
        header('Location: ' . APP_URL . '/modules/branches/list.php');
        exit;
    }
}

$pageTitle = $id ? 'Editar Sucursal' : 'Nueva Sucursal';
require_once __DIR__ . '/../../includes/header.php';
?>
<div style="max-width:600px;">
<div class="card">
  <div class="card-header"><span class="card-title"><?= $pageTitle ?></span></div>
  <div class="card-body">
    <form method="POST">
      <div class="form-grid">
        <div class="form-group full">
          <label>Nombre <span style="color:var(--danger)">*</span></label>
          <input type="text" name="name" value="<?= htmlspecialchars($branch['name']) ?>" required>
        </div>
        <div class="form-group full">
          <label>Dirección</label>
          <input type="text" name="address" value="<?= htmlspecialchars($branch['address']) ?>">
        </div>
        <div class="form-group">
          <label>Teléfono</label>
          <input type="text" name="phone" value="<?= htmlspecialchars($branch['phone']) ?>">
        </div>
        <?php if ($id): ?>
        <div class="form-group" style="justify-content:flex-end;align-items:flex-end;">
          <label><input type="checkbox" name="active" <?= $branch['active']?'checked':'' ?>> Sucursal activa</label>
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">💾 Guardar</button>
        <a href="<?= APP_URL ?>/modules/branches/list.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
