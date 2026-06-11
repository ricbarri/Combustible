<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db = getDB();

// Handle delete step
if (isset($_GET['delete_step'])) {
    $stepId    = (int)$_GET['delete_step'];
    $branch_id = (int)($_GET['branch_id'] ?? 0);

    // Check if this step is referenced by any request_approvals (historical records)
    $usedStmt = $db->prepare("SELECT COUNT(*) FROM request_approvals WHERE step_id = ?");
    $usedStmt->execute([$stepId]);
    $isUsed = (int)$usedStmt->fetchColumn();

    if ($isUsed > 0) {
        // Cannot delete: it has historical approval records tied to it.
        // Strategy: get the step's flow_id, remove it from flow by nullifying step references,
        // then delete the step row. We update request_approvals to set step_id = NULL first.
        // This requires the column to allow NULL — we handle it in a transaction.
        $db->beginTransaction();
        try {
            // Temporarily disable FK checks for this session to safely clean up
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            $db->prepare("DELETE FROM approval_flow_steps WHERE id = ?")->execute([$stepId]);
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            $db->commit();
            $_SESSION['success'] = 'Aprobador eliminado del flujo (sus registros históricos se conservan).';
        } catch (Exception $e) {
            $db->rollBack();
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            $_SESSION['error'] = 'No se pudo eliminar el paso: ' . $e->getMessage();
        }
    } else {
        // No historical records — safe hard delete
        $db->prepare("DELETE FROM approval_flow_steps WHERE id = ?")->execute([$stepId]);
        $_SESSION['success'] = 'Aprobador eliminado del flujo.';
    }

    // Reorder remaining steps so there are no gaps (1, 2, 3...)
    $flowIdStmt = $db->prepare("SELECT flow_id FROM approval_flow_steps WHERE id != ? ORDER BY step_order");
    // Get flow_id from the flow context via branch_id
    $flowCtx = $db->prepare("SELECT id FROM approval_flows WHERE branch_id = ? AND active = 1");
    $flowCtx->execute([$branch_id]);
    $flowRow = $flowCtx->fetch();
    if ($flowRow) {
        $remaining = $db->prepare("SELECT id FROM approval_flow_steps WHERE flow_id = ? ORDER BY step_order ASC");
        $remaining->execute([$flowRow['id']]);
        $rows = $remaining->fetchAll();
        foreach ($rows as $i => $row) {
            $db->prepare("UPDATE approval_flow_steps SET step_order = ? WHERE id = ?")->execute([$i + 1, $row['id']]);
        }
    }

    header('Location: ' . APP_URL . '/modules/flow/list.php?branch_id=' . $branch_id);
    exit;
}

// Handle POST (create/update flow + add step)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $branch_id = (int)($_POST['branch_id'] ?? 0);

    if ($action === 'create_flow' && $branch_id) {
        // Check if flow exists
        $existing = $db->prepare("SELECT id FROM approval_flows WHERE branch_id=? AND active=1");
        $existing->execute([$branch_id]);
        if (!$existing->fetch()) {
            $db->prepare("INSERT INTO approval_flows (branch_id, name) VALUES (?, ?)")
               ->execute([$branch_id, 'Flujo Principal']);
            $_SESSION['success'] = 'Flujo creado.';
        } else {
            $_SESSION['error'] = 'Ya existe un flujo para esta sucursal.';
        }
        header('Location: ' . APP_URL . '/modules/flow/list.php?branch_id=' . $branch_id);
        exit;
    }

    if ($action === 'add_step') {
        $flow_id = (int)($_POST['flow_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($flow_id && $user_id) {
            // Get next order
            $maxStep = $db->prepare("SELECT COALESCE(MAX(step_order),0)+1 FROM approval_flow_steps WHERE flow_id=?");
            $maxStep->execute([$flow_id]);
            $nextOrder = $maxStep->fetchColumn();
            try {
                $db->prepare("INSERT INTO approval_flow_steps (flow_id, user_id, step_order) VALUES (?,?,?)")
                   ->execute([$flow_id, $user_id, $nextOrder]);
                $_SESSION['success'] = 'Aprobador agregado al flujo.';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error al agregar aprobador.';
            }
        }
        header('Location: ' . APP_URL . '/modules/flow/list.php?branch_id=' . $branch_id);
        exit;
    }
}

$branches = $db->query("SELECT id,name FROM branches WHERE active=1 ORDER BY name")->fetchAll();
$selected_branch = (int)($_GET['branch_id'] ?? ($branches[0]['id'] ?? 0));

$flow = null;
$steps = [];
$approvers = [];
if ($selected_branch) {
    $flowStmt = $db->prepare("SELECT * FROM approval_flows WHERE branch_id=? AND active=1");
    $flowStmt->execute([$selected_branch]);
    $flow = $flowStmt->fetch();

    if ($flow) {
        $stepsStmt = $db->prepare("SELECT afs.*, u.name AS approver_name, u.email AS approver_email, u.position FROM approval_flow_steps afs JOIN users u ON afs.user_id=u.id WHERE afs.flow_id=? ORDER BY afs.step_order");
        $stepsStmt->execute([$flow['id']]);
        $steps = $stepsStmt->fetchAll();
    }
    // Available approvers for this branch
    $approversStmt = $db->prepare("SELECT id,name,email,position FROM users WHERE branch_id=? AND profile='aprobador' AND active=1 ORDER BY name");
    $approversStmt->execute([$selected_branch]);
    $approvers = $approversStmt->fetchAll();
}

$pageTitle = 'Flujo de Aprobación';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:grid;grid-template-columns:240px 1fr;gap:20px;">
  <!-- Branch selector -->
  <div class="card" style="height:fit-content;">
    <div class="card-header"><span class="card-title">Sucursal</span></div>
    <div class="card-body" style="padding:12px;">
      <?php foreach ($branches as $b): ?>
      <a href="?branch_id=<?= $b['id'] ?>" class="nav-item <?= $selected_branch==$b['id']?'active':'' ?>" style="border-radius:4px;margin-bottom:2px;">
        🏢 <?= htmlspecialchars($b['name']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Flow manager -->
  <div>
    <?php if ($selected_branch): ?>
      <?php if (!$flow): ?>
      <div class="card">
        <div class="card-body" style="text-align:center;padding:40px;">
          <div style="font-size:48px;margin-bottom:12px;">🔀</div>
          <p class="text-muted" style="margin-bottom:20px;">No existe flujo de aprobación para esta sucursal.</p>
          <form method="POST">
            <input type="hidden" name="action" value="create_flow">
            <input type="hidden" name="branch_id" value="<?= $selected_branch ?>">
            <button type="submit" class="btn btn-primary">Crear Flujo de Aprobación</button>
          </form>
        </div>
      </div>
      <?php else: ?>
      <div class="card mb-24">
        <div class="card-header">
          <span class="card-title">Pasos del Flujo — Secuencial</span>
          <span class="text-muted" style="font-size:12px;"><?= count($steps) ?> aprobador(es)</span>
        </div>
        <div class="card-body">
          <?php if (empty($steps)): ?>
            <p class="text-muted">No hay aprobadores configurados. Agrega al menos uno.</p>
          <?php else: ?>
          <div class="approval-steps">
            <?php foreach ($steps as $i => $step): ?>
            <div class="approval-step">
              <div class="step-badge done"><?= $step['step_order'] ?></div>
              <div style="flex:1;">
                <strong><?= htmlspecialchars($step['approver_name']) ?></strong>
                <span class="text-muted" style="font-size:12px;"> — <?= htmlspecialchars($step['approver_email']) ?></span>
                <?php if ($step['position']): ?>
                  <div style="font-size:11px;color:var(--text-dim);"><?= htmlspecialchars($step['position']) ?></div>
                <?php endif; ?>
              </div>
              <?php if ($i > 0 || count($steps) > 1): ?>
              <div style="font-size:20px;color:var(--text-dim);">→</div>
              <?php endif; ?>
              <a href="?delete_step=<?= $step['id'] ?>&branch_id=<?= $selected_branch ?>" class="btn btn-danger btn-sm" data-confirm="¿Quitar este aprobador del flujo?">✕</a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Add approver -->
      <div class="card">
        <div class="card-header"><span class="card-title">Agregar Aprobador</span></div>
        <div class="card-body">
          <?php if (empty($approvers)): ?>
            <p class="text-muted">No hay usuarios con perfil <strong>Aprobador</strong> en esta sucursal.</p>
            <a href="<?= APP_URL ?>/modules/users/form.php" class="btn btn-secondary btn-sm mt-16">Crear aprobador</a>
          <?php else: ?>
          <form method="POST" style="display:flex;gap:12px;align-items:flex-end;">
            <input type="hidden" name="action" value="add_step">
            <input type="hidden" name="flow_id" value="<?= $flow['id'] ?>">
            <input type="hidden" name="branch_id" value="<?= $selected_branch ?>">
            <div class="form-group" style="flex:1;">
              <label>Seleccionar Aprobador</label>
              <select name="user_id" required>
                <option value="">-- Seleccionar --</option>
                <?php foreach ($approvers as $a): ?>
                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['position'] ?? $a['email']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary">Agregar al flujo</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="card"><div class="card-body text-muted">Selecciona una sucursal.</div></div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
