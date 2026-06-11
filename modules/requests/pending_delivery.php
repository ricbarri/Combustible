<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('cargador','administrador');
$db   = getDB();
$user = currentUser();

$pendingStmt = $db->prepare("
    SELECT fr.*, b.name AS branch_name,
           COALESCE(m.name,'—') AS machine_name,
           COALESCE(m.code,'—') AS machine_code,
           u.name AS requester_name
    FROM fuel_requests fr
    JOIN branches b ON fr.branch_id = b.id
    LEFT JOIN machines m ON fr.machine_id = m.id
    JOIN users u ON fr.requester_id = u.id
    WHERE fr.status = 'aprobado' AND fr.branch_id = ?
    ORDER BY fr.requested_at ASC
");
$pendingStmt->execute([$user['branch_id']]);
$pending = $pendingStmt->fetchAll();

$tankStmt = $db->prepare("SELECT * FROM tanks WHERE branch_id=?");
$tankStmt->execute([$user['branch_id']]);
$tank = $tankStmt->fetch();

$pageTitle = 'Entregas Pendientes';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($tank): ?>
<div class="card mb-16" style="border-top:3px solid var(--accent);">
  <div class="card-body" style="display:flex;align-items:center;gap:24px;padding:14px 20px;">
    <span style="font-size:28px;">🛢</span>
    <div>
      <div class="text-muted" style="font-size:11px;text-transform:uppercase;">Estanque disponible</div>
      <div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:var(--accent);"><?= number_format($tank['current_liters'],0) ?> L</div>
    </div>
    <div class="gauge-bar" style="flex:1;height:16px;">
      <?php $pct = $tank['capacity'] > 0 ? ($tank['current_liters']/$tank['capacity'])*100 : 0; ?>
      <div class="gauge-fill <?= $pct<25?'low':($pct<60?'mid':'high') ?>" style="width:<?= min(max($pct,0),100) ?>%"></div>
    </div>
    <div class="text-muted" style="font-size:12px;white-space:nowrap;"><?= round($pct,1) ?>% de <?= number_format($tank['capacity'],0) ?> L</div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Solicitudes Aprobadas para Entregar</span>
    <span class="text-muted" style="font-size:12px;"><?= count($pending) ?> pendiente(s)</span>
  </div>
  <div class="card-body">
    <?php if (empty($pending)): ?>
    <div style="text-align:center;padding:40px;color:var(--text-muted);">
      <div style="font-size:48px;margin-bottom:12px;">✅</div>
      <div>No hay solicitudes pendientes de entrega.</div>
    </div>
    <?php else: ?>
    <?php foreach ($pending as $r): ?>
    <div class="card mb-16" style="border:1px solid var(--border-light);">
      <div style="padding:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
          <strong class="text-accent" style="font-size:16px;"><?= htmlspecialchars($r['request_number']) ?></strong>
          <span class="text-muted" style="font-size:12px;"><?= date('d/m/Y H:i', strtotime($r['requested_at'])) ?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
          <div>
            <div class="text-muted" style="font-size:10px;text-transform:uppercase;">Tipo</div>
            <div style="font-weight:600;font-size:13px;text-transform:capitalize;"><?= htmlspecialchars($r['request_type']) ?></div>
            <?php if ($r['service_call_number']): ?>
            <div style="font-size:11px;color:var(--accent);">N° <?= htmlspecialchars($r['service_call_number']) ?></div>
            <?php endif; ?>
          </div>
          <div>
            <div class="text-muted" style="font-size:10px;text-transform:uppercase;">Máquina</div>
            <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($r['machine_name']) ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:10px;text-transform:uppercase;">Litros Aprobados</div>
            <div style="font-family:var(--font-display);font-size:24px;font-weight:700;color:var(--accent);"><?= number_format($r['liters_requested'],0) ?> L</div>
          </div>
          <div>
            <div class="text-muted" style="font-size:10px;text-transform:uppercase;">Solicitante</div>
            <div style="font-size:13px;"><?= htmlspecialchars($r['requester_name']) ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:10px;text-transform:uppercase;">Combustible</div>
            <div style="font-size:13px;"><?= htmlspecialchars($r['fuel_type']) ?></div>
          </div>
          <div>
            <div class="text-muted" style="font-size:10px;text-transform:uppercase;">Sucursal</div>
            <div style="font-size:13px;"><?= htmlspecialchars($r['branch_name']) ?></div>
          </div>
        </div>
        <a href="<?= APP_URL ?>/modules/requests/deliver_form.php?id=<?= $r['id'] ?>"
           class="btn btn-success" style="width:100%;justify-content:center;">
          🚚 Iniciar Proceso de Entrega
        </a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
