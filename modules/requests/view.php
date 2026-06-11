<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT fr.*, b.name AS branch_name, COALESCE(m.name,'—') AS machine_name, COALESCE(m.code,'—') AS machine_code, u.name AS requester_name, u.email AS requester_email, u.position AS requester_position, db2.name AS loader_name
    FROM fuel_requests fr
    JOIN branches b ON fr.branch_id=b.id
    LEFT JOIN machines m ON fr.machine_id=m.id
    JOIN users u ON fr.requester_id=u.id
    LEFT JOIN users db2 ON fr.delivered_by=db2.id
    WHERE fr.id=?");
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) { $_SESSION['error'] = 'Solicitud no encontrada.'; header('Location: ' . APP_URL . '/modules/requests/list.php'); exit; }

$approvals = $db->prepare("SELECT ra.*, u.name AS approver_name, u.email AS approver_email, u.position AS approver_position FROM request_approvals ra JOIN users u ON ra.approver_id=u.id WHERE ra.request_id=? ORDER BY ra.step_order");
$approvals->execute([$id]);
$approvals = $approvals->fetchAll();

$statusMap = ['pendiente'=>['label'=>'Pendiente','class'=>'badge-pending'],'en_aprobacion'=>['label'=>'En aprobación','class'=>'badge-process'],'aprobado'=>['label'=>'Aprobado','class'=>'badge-approved'],'rechazado'=>['label'=>'Rechazado','class'=>'badge-rejected'],'entregado'=>['label'=>'Entregado','class'=>'badge-delivered']];
$stepStatusMap = ['pendiente'=>['badge'=>'pending','icon'=>'⏳'],'aprobado'=>['badge'=>'done','icon'=>'✅'],'rechazado'=>['badge'=>'rejected','icon'=>'✕']];

$pageTitle = 'Detalle Solicitud';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;">
  <div>
    <div class="card mb-16">
      <div class="card-header">
        <span class="card-title"><?= htmlspecialchars($request['request_number']) ?></span>
        <span class="badge <?= $statusMap[$request['status']]['class'] ?>"><?= $statusMap[$request['status']]['label'] ?></span>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Tipo de Solicitud</div>
            <div style="font-weight:600;text-transform:capitalize;"><?= htmlspecialchars($request['request_type']) ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Combustible</div>
            <div style="font-weight:600;"><?= htmlspecialchars($request['fuel_type']) ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Máquina</div>
            <div style="font-weight:600;"><?= htmlspecialchars($request['machine_name']) ?> <code style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($request['machine_code']) ?></code></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Sucursal de Entrega</div>
            <div style="font-weight:600;"><?= htmlspecialchars($request['branch_name']) ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Litros Solicitados</div>
            <div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:var(--accent);"><?= number_format($request['liters_requested'],0) ?> L</div>
          </div>
          <?php if ($request['liters_delivered']): ?>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Litros Entregados</div>
            <div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:#4eca80;"><?= number_format($request['liters_delivered'],0) ?> L</div>
          </div>
          <?php endif; ?>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Solicitado por</div>
            <div style="font-weight:600;"><?= htmlspecialchars($request['requester_name']) ?></div>
            <div class="text-muted" style="font-size:12px;"><?= htmlspecialchars($request['requester_position'] ?? '') ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Fecha Solicitud</div>
            <div style="font-weight:600;"><?= date('d/m/Y H:i', strtotime($request['requested_at'])) ?></div>
          </div>
          <?php if ($request['delivered_at']): ?>
          <div>
            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">Entregado por</div>
            <div style="font-weight:600;"><?= htmlspecialchars($request['loader_name'] ?? '—') ?></div>
            <div class="text-muted" style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($request['delivered_at'])) ?></div>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($request['notes']): ?>
        <div style="margin-top:16px;padding:12px;background:var(--bg-panel);border-radius:var(--radius);border:1px solid var(--border);">
          <div class="text-muted" style="font-size:11px;margin-bottom:4px;">OBSERVACIONES</div>
          <?= nl2br(htmlspecialchars($request['notes'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Approval Timeline -->
  <div>
    <div class="card">
      <div class="card-header"><span class="card-title">Flujo de Aprobación</span></div>
      <div class="card-body">
        <div class="approval-steps">
          <?php foreach ($approvals as $ap): ?>
          <?php $sc = $stepStatusMap[$ap['status']]; ?>
          <div class="approval-step">
            <div class="step-badge <?= $sc['badge'] ?>"><?= $sc['icon'] ?></div>
            <div style="flex:1;">
              <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($ap['approver_name']) ?></div>
              <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($ap['approver_position'] ?? $ap['approver_email']) ?></div>
              <?php if ($ap['acted_at']): ?>
              <div style="font-size:11px;color:<?= $ap['status']==='aprobado'?'#4eca80':'#f1706a' ?>;"><?= date('d/m/Y H:i', strtotime($ap['acted_at'])) ?></div>
              <?php endif; ?>
              <?php if ($ap['comments']): ?>
              <div style="font-size:11px;color:var(--text-muted);font-style:italic;margin-top:2px;">"<?= htmlspecialchars($ap['comments']) ?>"</div>
              <?php endif; ?>
            </div>
            <span class="badge <?= 'badge-'.($ap['status']==='aprobado'?'approved':($ap['status']==='rechazado'?'rejected':'pending')) ?>" style="font-size:10px;"><?= ucfirst($ap['status']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div style="margin-top:12px;">
      <a href="<?= APP_URL ?>/modules/requests/list.php" class="btn btn-secondary" style="width:100%;justify-content:center;">← Volver a Solicitudes</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
