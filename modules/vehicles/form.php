<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('administrador','superadmin');
$db = getDB();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$v  = $id ? $db->prepare("SELECT * FROM vehicles WHERE id=?") : null;
if ($v) { $v->execute([$id]); $v = $v->fetch(); }
$v = $v ?: ['name'=>'','plate'=>'','branch_id'=>'','company_id'=>1,'responsible_id'=>null];

$companies = $db->query("SELECT id,name FROM companies WHERE active=1 ORDER BY name")->fetchAll();
$branches  = $db->query("SELECT b.id, b.name, b.company_id, c.name AS company_name FROM branches b JOIN companies c ON b.company_id=c.id WHERE b.active=1 ORDER BY c.name, b.name")->fetchAll();
$users     = $db->query("SELECT u.id, u.name, u.branch_id, b.company_id FROM users u JOIN branches b ON u.branch_id=b.id WHERE u.active=1 ORDER BY u.name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name           = trim($_POST['name'] ?? '');
    $plate          = strtoupper(trim($_POST['plate'] ?? ''));
    $branch_id      = (int)($_POST['branch_id'] ?? 0);
    $company_id     = (int)($_POST['company_id'] ?? 1);
    $responsible_id = (int)($_POST['responsible_id'] ?? 0) ?: null;

    if (!$name || !$plate || !$branch_id) {
        $_SESSION['error'] = 'Nombre, patente y sucursal son obligatorios.';
    } else {
        if ($id) {
            $db->prepare("UPDATE vehicles SET name=?,plate=?,branch_id=?,company_id=?,responsible_id=?,updated_at=NOW() WHERE id=?")
               ->execute([$name,$plate,$branch_id,$company_id,$responsible_id,$id]);
            $_SESSION['success'] = 'Vehículo actualizado.';
        } else {
            $db->prepare("INSERT INTO vehicles (name,plate,branch_id,company_id,responsible_id) VALUES (?,?,?,?,?)")
               ->execute([$name,$plate,$branch_id,$company_id,$responsible_id]);
            $_SESSION['success'] = 'Vehículo creado.';
        }
        header('Location: ' . APP_URL . '/modules/vehicles/list.php'); exit;
    }
}

$pageTitle = $id ? 'Editar Vehículo' : 'Nuevo Vehículo';
require_once __DIR__ . '/../../includes/header.php';
?>
<div style="max-width:640px;">
<div class="card">
  <div class="card-header"><span class="card-title"><?= $pageTitle ?></span></div>
  <div class="card-body">
    <form method="POST">
      <div class="form-grid">
        <div class="form-group">
          <label>Empresa <span style="color:var(--danger)">*</span></label>
          <select name="company_id" id="sel_company" required onchange="filterByCompany()">
            <?php foreach ($companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($v['company_id']==$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Sucursal <span style="color:var(--danger)">*</span></label>
          <select name="branch_id" id="sel_branch" required>
            <option value="">-- Seleccionar --</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" data-company="<?= $b['company_id'] ?>"
              <?= ($v['branch_id']==$b['id'])?'selected':'' ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Nombre / Descripción <span style="color:var(--danger)">*</span></label>
          <input type="text" name="name" value="<?= htmlspecialchars($v['name']) ?>" required placeholder="Ej: Camioneta Ford Ranger">
        </div>
        <div class="form-group">
          <label>Patente <span style="color:var(--danger)">*</span></label>
          <input type="text" name="plate" value="<?= htmlspecialchars($v['plate']) ?>" required
            placeholder="Ej: ABCD12" style="text-transform:uppercase;letter-spacing:2px;">
        </div>
        <div class="form-group full">
          <label>Responsable habitual</label>
          <select name="responsible_id" id="sel_responsible">
            <option value="">-- Sin asignar --</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" data-company="<?= $u['company_id'] ?>"
              <?= ($v['responsible_id']==$u['id'])?'selected':'' ?>>
              <?= htmlspecialchars($u['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">💾 Guardar</button>
        <a href="<?= APP_URL ?>/modules/vehicles/list.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div>
<script>
function filterByCompany() {
    const cid = parseInt(document.getElementById('sel_company').value);
    ['sel_branch','sel_responsible'].forEach(selId => {
        const sel = document.getElementById(selId);
        Array.from(sel.options).forEach(opt => {
            if (!opt.value) return;
            opt.hidden = parseInt(opt.dataset.company) !== cid;
        });
        if (sel.selectedOptions[0]?.hidden) sel.value = '';
    });
}
document.addEventListener('DOMContentLoaded', filterByCompany);
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
