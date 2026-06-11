<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$user_data = $id ? $db->prepare("SELECT u.*, c.auth_method FROM users u LEFT JOIN companies c ON u.company_id=c.id WHERE u.id=?") : null;
if ($user_data) { $user_data->execute([$id]); $user_data = $user_data->fetch(); }
$user_data = $user_data ?: ['name'=>'','email'=>'','branch_id'=>'','company_id'=>1,'profile'=>'','position'=>'','active'=>1,'auth_method'=>'microsoft'];

// Obtener método de autenticación de la empresa seleccionada
$branches  = $db->query("SELECT b.id, b.name, b.company_id, c.auth_method FROM branches b JOIN companies c ON b.company_id=c.id WHERE b.active=1 ORDER BY b.name")->fetchAll();
$companies = $db->query("SELECT id, name, auth_method FROM companies WHERE active=1 ORDER BY name")->fetchAll();

// Determinar auth_method de la empresa del usuario
$companyAuthMethod = $user_data['auth_method'] ?? 'microsoft';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $profile   = $_POST['profile'] ?? '';
    $position  = trim($_POST['position'] ?? '');
    $password  = $_POST['password'] ?? '';
    $company_id= (int)($_POST['company_id'] ?? 1);
    $validProfiles = ['solicitante','aprobador','cargador','administrador','superadmin'];

    // Obtener auth_method de la empresa seleccionada
    $cStmt = $db->prepare("SELECT auth_method FROM companies WHERE id=?");
    $cStmt->execute([$company_id]);
    $companyAuthMethod = $cStmt->fetchColumn() ?: 'microsoft';

    if (!$name || !$email || !$branch_id || !in_array($profile, $validProfiles)) {
        $_SESSION['error'] = 'Completa todos los campos obligatorios.';
    } elseif ($companyAuthMethod === 'local' && !$id && !$password) {
        $_SESSION['error'] = 'La contraseña es obligatoria para empresas con autenticación local.';
    } else {
        try {
            $hashPwd = ($companyAuthMethod === 'local' && $password) ? password_hash($password, PASSWORD_DEFAULT) : null;
            if ($id) {
                if ($hashPwd) {
                    $db->prepare("UPDATE users SET name=?,email=?,branch_id=?,company_id=?,profile=?,position=?,password=?,updated_at=NOW() WHERE id=?")
                       ->execute([$name,$email,$branch_id,$company_id,$profile,$position,$hashPwd,$id]);
                } else {
                    $db->prepare("UPDATE users SET name=?,email=?,branch_id=?,company_id=?,profile=?,position=?,updated_at=NOW() WHERE id=?")
                       ->execute([$name,$email,$branch_id,$company_id,$profile,$position,$id]);
                }
                $_SESSION['success'] = 'Usuario actualizado.';
            } else {
                $db->prepare("INSERT INTO users (name,email,password,branch_id,company_id,profile,position) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$name,$email,$hashPwd,$branch_id,$company_id,$profile,$position]);
                $_SESSION['success'] = 'Usuario creado.';
            }
            logEvent(LOG_USER, $id?'USER_UPDATE':'USER_CREATE', "Usuario ".($id?'actualizado':'creado').": {$name} ({$email}) — {$profile}", LOG_INFO);
            header('Location: ' . APP_URL . '/modules/users/list.php'); exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'El correo ya está en uso.';
        }
    }
}

// Armar mapa company_id → auth_method para JS
$companyAuthMap = [];
foreach ($companies as $c) $companyAuthMap[$c['id']] = $c['auth_method'];

$pageTitle = $id ? 'Editar Usuario' : 'Nuevo Usuario';
require_once __DIR__ . '/../../includes/header.php';
?>
<div style="max-width:700px;">
<div class="card">
  <div class="card-header"><span class="card-title"><?= $pageTitle ?></span></div>
  <div class="card-body">
    <form method="POST" id="userForm">
      <div class="form-grid">
        <div class="form-group full">
          <label>Nombre completo <span style="color:var(--danger)">*</span></label>
          <input type="text" name="name" value="<?= htmlspecialchars($user_data['name']) ?>" required>
        </div>
        <div class="form-group">
          <label>Correo electrónico <span style="color:var(--danger)">*</span></label>
          <input type="email" name="email" value="<?= htmlspecialchars($user_data['email']) ?>" required>
        </div>
        <div class="form-group">
          <label>Empresa <span style="color:var(--danger)">*</span></label>
          <select name="company_id" id="sel_company" onchange="onCompanyChange(this.value)">
            <?php foreach ($companies as $c): ?>
            <option value="<?= $c['id'] ?>"
              data-auth="<?= $c['auth_method'] ?>"
              <?= ($user_data['company_id']==$c['id'])?'selected':'' ?>>
              <?= htmlspecialchars($c['name']) ?>
              (<?= $c['auth_method']==='local'?'🔑 Local':'🔵 Microsoft' ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Sucursal <span style="color:var(--danger)">*</span></label>
          <select name="branch_id" id="sel_branch" required>
            <option value="">-- Seleccionar --</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= $b['id'] ?>" data-company="<?= $b['company_id'] ?>"
              <?= ($user_data['branch_id']==$b['id'])?'selected':'' ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Contraseña: visible solo para empresas con auth local -->
        <div class="form-group full" id="password_field" style="display:<?= $companyAuthMethod==='local'?'flex':'none' ?>;">
          <label>
            Contraseña
            <?php if ($companyAuthMethod === 'local' && !$id): ?><span style="color:var(--danger)">*</span><?php endif; ?>
            <?php if ($id): ?><small class="text-muted">(dejar en blanco para no cambiar)</small><?php endif; ?>
          </label>
          <input type="password" name="password" id="password_input" placeholder="••••••••"
            <?= ($companyAuthMethod==='local' && !$id) ? 'required' : '' ?>>
          <small class="text-muted">Solo requerido para empresas con autenticación local</small>
        </div>

        <div class="form-group">
          <label>Perfil <span style="color:var(--danger)">*</span></label>
          <select name="profile" required>
            <option value="">-- Seleccionar --</option>
            <option value="superadmin"    <?= $user_data['profile']==='superadmin'?'selected':'' ?>>🌐 Super Administrador</option>
            <option value="administrador" <?= $user_data['profile']==='administrador'?'selected':'' ?>>⭐ Administrador</option>
            <option value="solicitante"   <?= $user_data['profile']==='solicitante'?'selected':'' ?>>Solicitante</option>
            <option value="aprobador"     <?= $user_data['profile']==='aprobador'?'selected':'' ?>>Aprobador</option>
            <option value="cargador"      <?= $user_data['profile']==='cargador'?'selected':'' ?>>Cargador</option>
          </select>
        </div>
        <div class="form-group">
          <label>Cargo en la empresa</label>
          <input type="text" name="position" value="<?= htmlspecialchars($user_data['position']) ?>" placeholder="Ej: Jefe de Operaciones">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">💾 Guardar</button>
        <a href="<?= APP_URL ?>/modules/users/list.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div>
<script>
const companyAuthMap = <?= json_encode($companyAuthMap) ?>;
const isNew = <?= $id ? 'false' : 'true' ?>;

function onCompanyChange(companyId) {
    const method = companyAuthMap[companyId] || 'microsoft';
    const pwField = document.getElementById('password_field');
    const pwInput = document.getElementById('password_input');
    pwField.style.display = (method === 'local') ? 'flex' : 'none';
    pwInput.required = (method === 'local' && isNew);

    // Filtrar sucursales por empresa
    const branchSel = document.getElementById('sel_branch');
    Array.from(branchSel.options).forEach(opt => {
        if (!opt.value) return;
        opt.hidden = parseInt(opt.dataset.company) !== parseInt(companyId);
    });
    if (branchSel.selectedOptions[0]?.hidden) branchSel.value = '';
}
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('sel_company');
    if (sel) onCompanyChange(sel.value);
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
