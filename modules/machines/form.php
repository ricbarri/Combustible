<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$machine = $id ? $db->prepare("SELECT * FROM machines WHERE id=?") : null;
if ($machine) { $machine->execute([$id]); $machine = $machine->fetch(); }
$machine = $machine ?: ['name'=>'','code'=>'','branch_id'=>'','active'=>1];

$branches = $db->query("SELECT id,name FROM branches WHERE active=1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $code      = trim($_POST['code'] ?? '');
    $branch_id = (int)($_POST['branch_id'] ?? 0);

    if (!$name || !$code || !$branch_id) {
        $_SESSION['error'] = 'Todos los campos son obligatorios.';
    } else {
        try {
            if ($id) {
                $db->prepare("UPDATE machines SET name=?,code=?,branch_id=?,updated_at=NOW() WHERE id=?")->execute([$name,$code,$branch_id,$id]);
                $_SESSION['success'] = 'Máquina actualizada.';
            } else {
                $db->prepare("INSERT INTO machines (name,code,branch_id) VALUES (?,?,?)")->execute([$name,$code,$branch_id]);
                $_SESSION['success'] = 'Máquina creada.';
            }
            header('Location: ' . APP_URL . '/modules/machines/list.php');
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'El código ya existe.';
        }
    }
}

$pageTitle = $id ? 'Editar Máquina' : 'Nueva Máquina';
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
          <input type="text" name="name" value="<?= htmlspecialchars($machine['name']) ?>" required>
        </div>
        <div class="form-group">
          <label>Código <span style="color:var(--danger)">*</span></label>
          <input type="text" name="code" value="<?= htmlspecialchars($machine['code']) ?>" required>
        </div>
        <div class="form-group">
          <label>Sucursal <span style="color:var(--danger)">*</span></label>
          <select name="branch_id" required>
            <option value="">-- Seleccionar --</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $machine['branch_id']==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">💾 Guardar</button>
        <a href="<?= APP_URL ?>/modules/machines/list.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
