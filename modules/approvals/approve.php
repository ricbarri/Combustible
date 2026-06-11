<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('aprobador');
require_once __DIR__ . '/../../includes/mailer.php';
$db   = getDB();
$user = currentUser();

$requestId = (int)($_GET['id'] ?? 0);

// Load request
$reqStmt = $db->prepare("SELECT fr.*, b.name AS branch_name, COALESCE(m.name,'—') AS machine_name, u.name AS requester_name, u.email AS requester_email, fr.service_call_number FROM fuel_requests fr JOIN branches b ON fr.branch_id=b.id LEFT JOIN machines m ON fr.machine_id=m.id JOIN users u ON fr.requester_id=u.id WHERE fr.id=?");
$reqStmt->execute([$requestId]);
$request = $reqStmt->fetch();

if (!$request) { $_SESSION['error'] = 'Solicitud no encontrada.'; header('Location: '.APP_URL.'/modules/approvals/list.php'); exit; }

// Check this approver has a pending step for this request
$myStepStmt = $db->prepare("SELECT ra.* FROM request_approvals ra WHERE ra.request_id=? AND ra.approver_id=? AND ra.status='pendiente'");
$myStepStmt->execute([$requestId, $user['id']]);
$myStep = $myStepStmt->fetch();

// Also check no prior step is still pending (sequential)
$priorPendingStmt = $db->prepare("SELECT COUNT(*) FROM request_approvals WHERE request_id=? AND step_order < ? AND status='pendiente'");
if ($myStep) {
    $priorPendingStmt->execute([$requestId, $myStep['step_order']]);
    $hasPrior = $priorPendingStmt->fetchColumn();
} else {
    $hasPrior = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $comments = trim($_POST['comments'] ?? '');

    if (!$myStep || $hasPrior) {
        $_SESSION['error'] = 'No puedes aprobar/rechazar esta solicitud en este momento.';
        header('Location: '.APP_URL.'/modules/approvals/list.php');
        exit;
    }

    if ($action === 'approve') {
        $db->beginTransaction();
        try {
            // Mark this step as approved
            $db->prepare("UPDATE request_approvals SET status='aprobado', comments=?, acted_at=NOW() WHERE id=?")->execute([$comments, $myStep['id']]);

            // Check if there's a next step
            $nextStmt = $db->prepare("SELECT ra.*, u.name, u.email FROM request_approvals ra JOIN users u ON ra.approver_id=u.id WHERE ra.request_id=? AND ra.step_order>? AND ra.status='pendiente' ORDER BY ra.step_order ASC LIMIT 1");
            $nextStmt->execute([$requestId, $myStep['step_order']]);
            $nextStep = $nextStmt->fetch();

            if ($nextStep) {
                // Notify next approver
                $db->prepare("UPDATE request_approvals SET notified_at=NOW() WHERE id=?")->execute([$nextStep['id']]);
                $reqData = [
                    'id'              => $requestId,
                    'request_number'  => $request['request_number'],
                    'request_type'    => $request['request_type'],
                    'fuel_type'       => $request['fuel_type'],
                    'liters_requested'=> $request['liters_requested'],
                    'machine_name'    => $request['machine_name'],
                    'branch_name'     => $request['branch_name'],
                    'requester_name'  => $request['requester_name'],
                ];
                emailApprovalRequest(['name'=>$nextStep['name'],'email'=>$nextStep['email']], $reqData, $myStep['step_order']+1);
                logEvent(LOG_APPROVAL, 'STEP_APPROVED',
                    "Paso #{$myStep['step_order']} aprobado para solicitud {$request['request_number']} — siguiente aprobador: {$nextStep['name']}",
                    LOG_INFO,
                    ['request_id' => $requestId, 'request_number' => $request['request_number'], 'paso' => $myStep['step_order'], 'siguiente_aprobador' => $nextStep['name'], 'comentarios' => $comments]
                );
                logEvent(LOG_MAIL, 'MAIL_NEXT_APPROVER',
                    "Correo enviado al aprobador {$nextStep['name']} ({$nextStep['email']}) — Paso #" . ($myStep['step_order']+1),
                    LOG_INFO,
                    ['destinatario' => $nextStep['email'], 'request_number' => $request['request_number'], 'paso' => $myStep['step_order']+1]
                );
            } else {
                // All approved! Update request status
                $db->prepare("UPDATE fuel_requests SET status='aprobado' WHERE id=?")->execute([$requestId]);

                // Datos completos de la solicitud para los correos
                $reqData = [
                    'id'                  => $requestId,
                    'request_number'      => $request['request_number'],
                    'request_type'        => $request['request_type'],
                    'fuel_type'           => $request['fuel_type'],
                    'liters_requested'    => $request['liters_requested'],
                    'machine_name'        => $request['machine_name'],
                    'branch_name'         => $request['branch_name'],
                    'requester_name'      => $request['requester_name'],
                    'service_call_number' => $request['service_call_number'] ?? '',
                ];

                // 1. Notificar al solicitante que su solicitud fue aprobada
                emailRequestApprovedToRequester(
                    ['name' => $request['requester_name'], 'email' => $request['requester_email']],
                    $reqData
                );

                // 2. Notificar a los cargadores de la sucursal para que entreguen
                $loadersStmt = $db->prepare("SELECT name, email FROM users WHERE branch_id=? AND profile IN ('cargador','administrador') AND active=1");
                $loadersStmt->execute([$request['branch_id']]);
                $loaders = $loadersStmt->fetchAll();
                foreach ($loaders as $loader) {
                    emailApprovalComplete($loader, $reqData);
                }

                logEvent(LOG_APPROVAL, 'FLOW_COMPLETED',
                    "Flujo completado para {$request['request_number']} — {$request['liters_requested']} L — correos enviados a solicitante y " . count($loaders) . " cargador(es)",
                    LOG_INFO,
                    ['request_id' => $requestId, 'litros' => $request['liters_requested'], 'cargadores' => count($loaders)]
                );
            }

            $db->commit();
            $_SESSION['success'] = 'Solicitud aprobada correctamente.';
            header('Location: '.APP_URL.'/modules/approvals/list.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            logException(LOG_APPROVAL, 'APPROVE_ERROR', $e, ['request_id' => $requestId]);
            $_SESSION['error'] = 'Error al procesar la aprobación.';
        }
    } elseif ($action === 'reject') {
        if (!$comments) { $_SESSION['error'] = 'El motivo de rechazo es obligatorio.'; }
        else {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE request_approvals SET status='rechazado', comments=?, acted_at=NOW() WHERE id=?")->execute([$comments, $myStep['id']]);
                $db->prepare("UPDATE fuel_requests SET status='rechazado' WHERE id=?")->execute([$requestId]);
                $db->commit();
                // Notify requester
                emailRejected(['name'=>$request['requester_name'],'email'=>$request['requester_email']], ['request_number'=>$request['request_number']], $comments);
                logEvent(LOG_APPROVAL, 'REQUEST_REJECTED',
                    "Solicitud {$request['request_number']} RECHAZADA en paso #{$myStep['step_order']} — Motivo: {$comments}",
                    LOG_WARNING,
                    ['request_id' => $requestId, 'request_number' => $request['request_number'], 'paso' => $myStep['step_order'], 'solicitante' => $request['requester_name'], 'motivo' => $comments]
                );
                logEvent(LOG_MAIL, 'MAIL_REJECTION_SENT',
                    "Correo de rechazo enviado a {$request['requester_name']} ({$request['requester_email']})",
                    LOG_INFO,
                    ['destinatario' => $request['requester_email'], 'request_number' => $request['request_number']]
                );
                $_SESSION['success'] = 'Solicitud rechazada.';
                header('Location: '.APP_URL.'/modules/approvals/list.php');
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                logException(LOG_APPROVAL, 'REJECT_ERROR', $e, ['request_id' => $requestId]);
                $_SESSION['error'] = 'Error al rechazar.';
            }
        }
    }
}

// All approval steps for context
$allSteps = $db->prepare("SELECT ra.*, u.name AS approver_name FROM request_approvals ra JOIN users u ON ra.approver_id=u.id WHERE ra.request_id=? ORDER BY ra.step_order");
$allSteps->execute([$requestId]);
$allSteps = $allSteps->fetchAll();

$pageTitle = 'Revisar Solicitud';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="max-width:800px;">
  <div class="card mb-16">
    <div class="card-header">
      <span class="card-title">Solicitud <?= htmlspecialchars($request['request_number']) ?></span>
    </div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;">
        <div>
          <div class="text-muted" style="font-size:11px;text-transform:uppercase;">Tipo</div>
          <div style="font-weight:600;text-transform:capitalize;"><?= htmlspecialchars($request['request_type']) ?></div>
        </div>
        <div>
          <div class="text-muted" style="font-size:11px;text-transform:uppercase;">Combustible</div>
          <div style="font-weight:600;"><?= htmlspecialchars($request['fuel_type']) ?></div>
        </div>
        <div>
          <div class="text-muted" style="font-size:11px;text-transform:uppercase;">Litros Solicitados</div>
          <div style="font-family:var(--font-display);font-size:26px;font-weight:700;color:var(--accent);"><?= number_format($request['liters_requested'],0) ?> L</div>
        </div>
        <div>
          <div class="text-muted" style="font-size:11px;text-transform:uppercase;">Máquina</div>
          <div style="font-weight:600;"><?= htmlspecialchars($request['machine_name']) ?></div>
        </div>
        <div>
          <div class="text-muted" style="font-size:11px;text-transform:uppercase;">Sucursal</div>
          <div style="font-weight:600;"><?= htmlspecialchars($request['branch_name']) ?></div>
        </div>
        <div>
          <div class="text-muted" style="font-size:11px;text-transform:uppercase;">Solicitante</div>
          <div style="font-weight:600;"><?= htmlspecialchars($request['requester_name']) ?></div>
        </div>
      </div>

      <!-- Approval Steps Progress -->
      <div class="approval-steps mb-24">
        <?php foreach ($allSteps as $step): ?>
        <?php $stC = ['pendiente'=>'pending','aprobado'=>'done','rechazado'=>'rejected'][$step['status']]; ?>
        <div class="approval-step">
          <div class="step-badge <?= $stC ?>"><?= ['pendiente'=>$step['step_order'],'aprobado'=>'✓','rechazado'=>'✕'][$step['status']] ?></div>
          <div style="flex:1;">
            <strong><?= htmlspecialchars($step['approver_name']) ?></strong>
            <?php if ($step['approver_id'] == $user['id']): ?><span style="font-size:11px;color:var(--accent);"> (tú)</span><?php endif; ?>
          </div>
          <span class="badge <?= 'badge-'.($step['status']==='aprobado'?'approved':($step['status']==='rechazado'?'rejected':'pending')) ?>" style="font-size:10px;"><?= ucfirst($step['status']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($myStep && !$hasPrior): ?>
      <form method="POST">
        <div class="form-group" style="margin-bottom:16px;">
          <label>Comentarios</label>
          <textarea name="comments" placeholder="Observaciones (requerido para rechazo)..."><?= htmlspecialchars($_POST['comments'] ?? '') ?></textarea>
        </div>
        <div style="display:flex;gap:12px;">
          <button type="submit" name="action" value="approve" class="btn btn-success" style="flex:1;justify-content:center;">✅ Aprobar Solicitud</button>
          <button type="submit" name="action" value="reject"  class="btn btn-danger"  style="flex:1;justify-content:center;" onclick="const c=this.form.comments;if(!c.value.trim()){alert('Ingresa el motivo de rechazo.');c.focus();return false;}return true;">✕ Rechazar Solicitud</button>
        </div>
      </form>
      <?php elseif ($hasPrior): ?>
        <div style="background:var(--warning-bg);border:1px solid var(--warning);border-radius:var(--radius);padding:12px 16px;color:var(--warning);">
          ⏳ Debes esperar que el aprobador anterior complete su acción.
        </div>
      <?php else: ?>
        <div style="background:var(--bg-panel);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;color:var(--text-muted);">
          Esta solicitud ya fue procesada por ti.
        </div>
      <?php endif; ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/modules/approvals/list.php" class="btn btn-secondary">← Volver a Aprobaciones</a>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
