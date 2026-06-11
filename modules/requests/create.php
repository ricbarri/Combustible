<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('solicitante','cargador','administrador');
require_once __DIR__ . '/../../includes/mailer.php';
$db   = getDB();
$user = currentUser();

$branches = $db->query("SELECT id,name FROM branches WHERE active=1 ORDER BY name")->fetchAll();
$machines = $db->query("SELECT m.id, m.name, m.code, b.name AS branch_name FROM machines m JOIN branches b ON m.branch_id=b.id WHERE m.active=1 ORDER BY m.name")->fetchAll();

// Estanque de la sucursal del usuario
$tankStmt = $db->prepare("SELECT current_liters FROM tanks WHERE branch_id=?");
$tankStmt->execute([$user['branch_id']]);
$myTank = $tankStmt->fetch();

// JSON de estanques por sucursal para JS (validación en frontend)
$allTanks = [];
foreach ($db->query("SELECT branch_id, current_liters FROM tanks") as $t) {
    $allTanks[$t['branch_id']] = (float)$t['current_liters'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id           = (int)($_POST['branch_id'] ?? 0);
    $machine_id          = (int)($_POST['machine_id'] ?? 0);
    $request_type        = $_POST['request_type'] ?? '';
    $fuel_type           = $_POST['fuel_type'] ?? 'Petróleo Diesel';
    $liters              = (float)($_POST['liters'] ?? 0);
    $notes               = trim($_POST['notes'] ?? '');
    $service_call_number = trim($_POST['service_call_number'] ?? '');

    $validTypes = ['llamada de servicio','activo fijo','excepción'];
    $errors = [];

    if (!$branch_id) $errors[] = 'Selecciona una sucursal de entrega.';
    if (!in_array($request_type, $validTypes)) $errors[] = 'Tipo de solicitud inválido.';
    if ($liters <= 0 || $liters > REQUEST_MAX_LITERS) $errors[] = 'Los litros deben ser entre 1 y ' . REQUEST_MAX_LITERS . '.';
    if ($request_type === 'activo fijo' && !$machine_id) $errors[] = 'Selecciona una máquina para solicitud de Activo Fijo.';
    if ($request_type === 'llamada de servicio' && !$service_call_number) $errors[] = 'Ingresa el N° de llamada de servicio.';

    // ── Validación del estanque ──────────────────────────────────
    if (!$errors && $branch_id) {
        $tankCheck = $db->prepare("SELECT current_liters FROM tanks WHERE branch_id=?");
        $tankCheck->execute([$branch_id]);
        $tankData = $tankCheck->fetch();

        if (!$tankData) {
            $errors[] = 'La sucursal seleccionada no tiene estanque configurado.';
        } elseif ($tankData['current_liters'] < 0) {
            // Estanque en negativo: bloquear completamente
            $errors[] = "El estanque de la sucursal seleccionada está en estado negativo ({$tankData['current_liters']} L). No se pueden realizar solicitudes hasta que el cargador regularice el estanque.";
        } elseif ($liters > $tankData['current_liters']) {
            // Solicitud excede stock disponible: advertencia pero permite enviar
            // (la entrega real validará al momento de despachar)
            // Solo se bloquea si el estanque ya está en negativo
        }
    }

    // ── Flujo de aprobación ──────────────────────────────────────
    if (!$errors) {
        $flowStmt = $db->prepare("SELECT af.id FROM approval_flows af WHERE af.branch_id=? AND af.active=1");
        $flowStmt->execute([$branch_id]);
        $flow = $flowStmt->fetch();
        if (!$flow) $errors[] = 'No existe flujo de aprobación configurado para esa sucursal.';
    }
    if (!$errors) {
        $flowStepsStmt = $db->prepare("SELECT afs.*, u.name, u.email FROM approval_flow_steps afs JOIN users u ON afs.user_id=u.id WHERE afs.flow_id=? ORDER BY afs.step_order");
        $flowStepsStmt->execute([$flow['id']]);
        $flowSteps = $flowStepsStmt->fetchAll();
        if (empty($flowSteps)) $errors[] = 'El flujo de aprobación no tiene aprobadores configurados.';
    }

    if (empty($errors)) {
        $year   = date('Y');
        $count  = $db->query("SELECT COUNT(*) FROM fuel_requests WHERE YEAR(requested_at)={$year}")->fetchColumn();
        $reqNum = str_pad($count + 190, 4, '0', STR_PAD_LEFT);
        $machineIdToSave = ($request_type === 'activo fijo' && $machine_id) ? $machine_id : null;

        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO fuel_requests (request_number,requester_id,branch_id,machine_id,fuel_type,request_type,service_call_number,liters_requested,status,notes,requested_at) VALUES (?,?,?,?,?,?,?,?,'en_aprobacion',?,NOW())")
               ->execute([$reqNum,$user['id'],$branch_id,$machineIdToSave,$fuel_type,$request_type,$service_call_number ?: null,$liters,$notes]);
            $requestId = $db->lastInsertId();

            foreach ($flowSteps as $step) {
                $db->prepare("INSERT INTO request_approvals (request_id,flow_id,step_id,approver_id,step_order,status) VALUES (?,?,?,?,?,'pendiente')")
                   ->execute([$requestId,$flow['id'],$step['id'],$step['user_id'],$step['step_order']]);
            }

            $firstStep = $flowSteps[0];
            $db->prepare("UPDATE request_approvals SET notified_at=NOW() WHERE request_id=? AND step_order=1")->execute([$requestId]);

            $machineName = '';
            if ($machineIdToSave) {
                $mach = $db->prepare("SELECT name FROM machines WHERE id=?");
                $mach->execute([$machineIdToSave]);
                $machineName = $mach->fetchColumn() ?: '';
            }
            $brch = $db->prepare("SELECT name FROM branches WHERE id=?");
            $brch->execute([$branch_id]);

            $requestData = [
                'id'                  => $requestId,
                'request_number'      => $reqNum,
                'request_type'        => $request_type,
                'fuel_type'           => $fuel_type,
                'liters_requested'    => $liters,
                'machine_name'        => $machineName,
                'branch_name'         => $brch->fetchColumn(),
                'requester_name'      => $user['name'],
                'service_call_number' => $service_call_number,
            ];

            emailApprovalRequest(['name'=>$firstStep['name'],'email'=>$firstStep['email']], $requestData, 1);

            // Copia de confirmación al solicitante
            emailRequestConfirmation(['name'=>$user['name'],'email'=>$user['email']], $requestData);

            $db->commit();
            logEvent(LOG_REQUEST,'REQUEST_CREATED',"Solicitud {$reqNum} — {$request_type} — {$liters} L",LOG_INFO,['request_id'=>$requestId,'tipo'=>$request_type,'litros'=>$liters,'branch_id'=>$branch_id]);
            $_SESSION['success'] = "Solicitud {$reqNum} creada y enviada al flujo de aprobación.";
            header('Location: ' . APP_URL . '/modules/requests/list.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            logException(LOG_REQUEST,'REQUEST_CREATE_ERROR',$e);
            $_SESSION['error'] = 'Error al crear la solicitud: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

$pageTitle = 'Nueva Solicitud de Combustible';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:760px;">
<div class="card">
  <div class="card-header"><span class="card-title">Formulario de Solicitud</span></div>
  <div class="card-body">

    <!-- ── Estanque disponible ──────────────────────────────── -->
    <?php if ($myTank): ?>
    <?php
      $tLitros = (float)$myTank['current_liters'];
      $tPct    = min(100, max(0, ($tLitros / TANK_MAX_LITERS) * 100));
      $tClass  = $tLitros < 0 ? 'danger' : ($tPct < 25 ? 'low' : ($tPct < 60 ? 'mid' : 'high'));
      $isNegative = $tLitros < 0;
    ?>
    <div style="display:flex;align-items:center;gap:14px;background:var(--bg-panel);border:1px solid <?= $isNegative ? 'var(--danger)' : 'var(--border)' ?>;border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;">
      <span style="font-size:26px;"><?= $isNegative ? '⚠' : '🛢' ?></span>
      <div style="min-width:110px;">
        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;">Estanque disponible ahora</div>
        <div style="font-family:var(--font-display);font-size:26px;font-weight:700;color:<?= $isNegative ? 'var(--danger)' : 'var(--accent)' ?>;line-height:1.1;">
          <?= number_format($tLitros, 0) ?> L
        </div>
        <?php if ($isNegative): ?>
        <div style="font-size:11px;color:var(--danger);font-weight:600;">⛔ Estanque en negativo</div>
        <?php endif; ?>
      </div>
      <?php if (!$isNegative): ?>
      <div style="flex:1;">
        <div class="gauge-bar"><div class="gauge-fill <?= $tClass ?>" style="width:<?= $tPct ?>%"></div></div>
        <div style="text-align:right;font-size:11px;color:var(--text-muted);margin-top:3px;"><?= round($tPct,1) ?>% de <?= number_format(TANK_MAX_LITERS,0) ?> L</div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($isNegative): ?>
    <div style="background:var(--danger-bg);border:1px solid var(--danger);border-radius:var(--radius);padding:14px 18px;margin-bottom:20px;color:#f07070;font-weight:500;">
      ⛔ <strong>No puedes crear solicitudes.</strong> El estanque de tu sucursal tiene saldo negativo (<?= number_format($tLitros,0) ?> L).
      Contacta al cargador para regularizar el estanque antes de continuar.
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!isset($isNegative) || !$isNegative): ?>
    <form method="POST" id="requestForm">
      <div class="form-grid">

        <div class="form-group">
          <label>Tipo de solicitud <span style="color:var(--danger)">*</span></label>
          <select name="request_type" id="request_type" required onchange="handleTypeChange(this.value)">
            <option value="">-- Seleccionar --</option>
            <option value="llamada de servicio" <?= ($_POST['request_type']??'')==='llamada de servicio'?'selected':'' ?>>Llamada de Servicio</option>
            <option value="activo fijo"         <?= ($_POST['request_type']??'')==='activo fijo'?'selected':'' ?>>Activo Fijo</option>
            <option value="excepción"           <?= ($_POST['request_type']??'')==='excepción'?'selected':'' ?>>Excepción</option>
          </select>
        </div>

        <div class="form-group">
          <label>Tipo de combustible <span style="color:var(--danger)">*</span></label>
          <select name="fuel_type" required>
            <option value="Petróleo Diesel" selected>Petróleo Diesel</option>
          </select>
        </div>

        <!-- N° Llamada: aparece solo para "llamada de servicio" -->
        <div class="form-group full" id="field_call_number" style="display:none;">
          <label>N° de Llamada <span style="color:var(--danger)">*</span></label>
          <input type="text" name="service_call_number" id="service_call_number"
            placeholder="Ej: LS-2025-00123"
            value="<?= htmlspecialchars($_POST['service_call_number'] ?? '') ?>">
          <small class="text-muted">Número de la llamada de servicio asociada a esta solicitud</small>
        </div>

        <!-- Máquina: aparece solo para "activo fijo" -->
        <div class="form-group full" id="field_machine" style="display:none;">
          <label>Máquina <span style="color:var(--danger)">*</span></label>
          <select name="machine_id" id="machine_id">
            <option value="">-- Seleccionar máquina --</option>
            <?php foreach ($machines as $m): ?>
            <option value="<?= $m['id'] ?>" <?= ($_POST['machine_id']??'')==$m['id']?'selected':'' ?>>
              <?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['code']) ?>) — <?= htmlspecialchars($m['branch_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Sucursal de entrega <span style="color:var(--danger)">*</span></label>
          <select name="branch_id" id="branch_id" required onchange="updateTankInfo(this.value)">
            <option value="">-- Seleccionar --</option>
            <?php foreach ($branches as $b): ?>
            <?php
              // Preseleccionar: si hay POST usar ese valor, si no usar la sucursal del usuario
              $isSelected = isset($_POST['branch_id'])
                ? ($_POST['branch_id'] == $b['id'])
                : ($b['id'] == $user['branch_id']);
            ?>
            <option value="<?= $b['id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <!-- Info de estanque de la sucursal seleccionada -->
          <div id="branch_tank_info" style="margin-top:6px;display:none;"></div>
        </div>

        <div class="form-group">
          <label>Litros a solicitar <span style="color:var(--danger)">*</span></label>
          <input type="number" name="liters" min="1" max="<?= REQUEST_MAX_LITERS ?>" step="0.01" required
            placeholder="Ej: 100" value="<?= htmlspecialchars($_POST['liters'] ?? '') ?>">
          <small class="text-muted">Máximo: <?= REQUEST_MAX_LITERS ?> litros por solicitud</small>
        </div>

        <div class="form-group full">
          <label>Observaciones (opcional)</label>
          <textarea name="notes" placeholder="Detalles adicionales..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>

      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">📤 Enviar Solicitud</button>
        <a href="<?= APP_URL ?>/modules/requests/list.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>
</div>

<script>
// Datos de estanques por sucursal
const tankData = <?= json_encode($allTanks) ?>;

function handleTypeChange(type) {
  const callField    = document.getElementById('field_call_number');
  const machineField = document.getElementById('field_machine');
  const callInput    = document.getElementById('service_call_number');
  const machineInput = document.getElementById('machine_id');

  callField.style.display    = 'none';
  machineField.style.display = 'none';
  callInput.required         = false;
  machineInput.required      = false;

  if (type === 'llamada de servicio') {
    callField.style.display = 'block';
    callInput.required      = true;
  } else if (type === 'activo fijo') {
    machineField.style.display = 'block';
    machineInput.required      = true;
  }
}

function updateTankInfo(branchId) {
  const infoDiv = document.getElementById('branch_tank_info');
  if (!branchId || !(branchId in tankData)) {
    infoDiv.style.display = 'none';
    return;
  }
  const litros = tankData[branchId];
  const max    = <?= REQUEST_MAX_LITERS ?>;
  const pct    = Math.min(100, Math.max(0, (litros / max) * 100));
  const isNeg  = litros < 0;
  const color  = isNeg ? '#f07070' : (pct < 25 ? '#f07070' : (pct < 60 ? '#e8a020' : '#3dc977'));

  infoDiv.style.display = 'block';
  infoDiv.innerHTML = `
    <div style="background:var(--bg-input);border:1px solid ${isNeg ? 'var(--danger)' : 'var(--border)'};border-radius:var(--radius);padding:10px 12px;font-size:12px;">
      ${isNeg
        ? `<span style="color:var(--danger);font-weight:700;">⛔ Estanque en negativo: ${litros.toFixed(0)} L — No se puede despachar</span>`
        : `🛢 Estanque disponible en esta sucursal: <strong style="color:${color}">${litros.toFixed(0)} L</strong> (${pct.toFixed(1)}%)`
      }
    </div>`;
}

document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('request_type');
  if (sel && sel.value) handleTypeChange(sel.value);

  // Mostrar estanque de la sucursal preseleccionada al cargar
  const branchSel = document.getElementById('branch_id');
  if (branchSel && branchSel.value) {
    updateTankInfo(branchSel.value);
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
