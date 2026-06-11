<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('administrador','superadmin');
$db = getDB();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$co = $id ? $db->prepare("SELECT * FROM companies WHERE id=?") : null;
if ($co) { $co->execute([$id]); $co = $co->fetch(); }
$co = $co ?: ['name'=>'','rut'=>'','address'=>'','phone'=>'','active'=>1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']    ?? '');
    $rut        = trim($_POST['rut']     ?? '');
    $address    = trim($_POST['address'] ?? '');
    $phone      = trim($_POST['phone']   ?? '');
    $authMethod = in_array($_POST['auth_method']??'', ['microsoft','local']) ? $_POST['auth_method'] : 'microsoft';
    if (!$name) { $_SESSION['error'] = 'El nombre es obligatorio.'; }
    else {
        if ($id) {
            $db->prepare("UPDATE companies SET name=?,rut=?,address=?,phone=?,auth_method=?,updated_at=NOW() WHERE id=?")
               ->execute([$name,$rut,$address,$phone,$authMethod,$id]);
            $_SESSION['success'] = 'Empresa actualizada.';
        } else {
            $db->prepare("INSERT INTO companies (name,rut,address,phone,auth_method) VALUES (?,?,?,?,?)")
               ->execute([$name,$rut,$address,$phone,$authMethod]);
            $_SESSION['success'] = 'Empresa creada.';
        }
        header('Location: ' . APP_URL . '/modules/companies/list.php'); exit;
    }
}

$pageTitle = $id ? 'Editar Empresa' : 'Nueva Empresa';
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
          <input type="text" name="name" value="<?= htmlspecialchars($co['name']) ?>" required>
        </div>
        <div class="form-group">
          <label>RUT</label>
          <input type="text" name="rut" value="<?= htmlspecialchars($co['rut']) ?>" placeholder="76.XXX.XXX-X">
        </div>
        <div class="form-group">
          <label>Teléfono</label>
          <input type="text" name="phone" value="<?= htmlspecialchars($co['phone']) ?>">
        </div>
        <div class="form-group full">
          <label>Método de autenticación</label>
          <select name="auth_method">
            <option value="microsoft" <?= ($co['auth_method']??'microsoft')==='microsoft'?'selected':'' ?>>
              🔵 Microsoft 365 (OAuth 2.0) — usuarios ingresan con su cuenta corporativa
            </option>
            <option value="local" <?= ($co['auth_method']??'')==='local'?'selected':'' ?>>
              🔑 Autenticación local — usuario y contraseña gestionados en el sistema
            </option>
          </select>
          <small class="text-muted">
            Microsoft 365: requiere que la empresa tenga Azure AD configurado.<br>
            Local: el administrador crea usuarios con contraseña desde el mantenedor de usuarios.
          </small>
        </div>
        <div class="form-group full">
          <label>Dirección</label>
          <input type="text" name="address" value="<?= htmlspecialchars($co['address']) ?>">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">💾 Guardar</button>
        <a href="<?= APP_URL ?>/modules/companies/list.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
