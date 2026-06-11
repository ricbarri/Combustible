<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('aprobador');
$db   = getDB();
$user = currentUser();

$filter = $_GET['filter'] ?? 'all';

$baseWhere = 'WHERE ra.approver_id = ?';
$params    = [$user['id']];

switch ($filter) {
    case 'pending':  $baseWhere .= " AND ra.status='pendiente'"; break;
    case 'approved': $baseWhere .= " AND ra.status='aprobado'"; break;
    case 'rejected': $baseWhere .= " AND ra.status='rechazado'"; break;
}

$stmt = $db->prepare("SELECT ra.*, fr.request_number, fr.request_type, fr.service_call_number, fr.fuel_type, fr.liters_requested, fr.status AS req_status, fr.requested_at,
       b.name AS branch_name, COALESCE(m.name,'—') AS machine_name, u.name AS requester_name
       FROM request_approvals ra
       JOIN fuel_requests fr ON ra.request_id=fr.id
       JOIN branches b ON fr.branch_id=b.id
       LEFT JOIN machines m ON fr.machine_id=m.id
       JOIN users u ON fr.requester_id=u.id
       $baseWhere ORDER BY fr.requested_at DESC");
$stmt->execute($params);
$approvals = $stmt->fetchAll();

$statusMap = ['pendiente'=>['label'=>'Sin aprobar','class'=>'badge-pending'],'aprobado'=>['label'=>'Aprobado','class'=>'badge-approved'],'rechazado'=>['label'=>'Rechazado','class'=>'badge-rejected']];

// Counts
$counts = ['all'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
$allStmt = $db->prepare("SELECT ra.status, COUNT(*) AS cnt FROM request_approvals ra WHERE ra.approver_id=? GROUP BY ra.status");
$allStmt->execute([$user['id']]);
foreach ($allStmt->fetchAll() as $row) {
    $counts['all'] += $row['cnt'];
    if ($row['status']==='pendiente')  $counts['pending']  = $row['cnt'];
    if ($row['status']==='aprobado')   $counts['approved'] = $row['cnt'];
    if ($row['status']==='rechazado')  $counts['rejected'] = $row['cnt'];
}

$pageTitle = 'Mis Aprobaciones';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="filter-tabs mb-16">
  <a href="?filter=all"      class="filter-tab <?= $filter==='all'?'active':'' ?>">Todas (<?= $counts['all'] ?>)</a>
  <a href="?filter=pending"  class="filter-tab <?= $filter==='pending'?'active':'' ?>">Sin Aprobar (<?= $counts['pending'] ?>)</a>
  <a href="?filter=approved" class="filter-tab <?= $filter==='approved'?'active':'' ?>">Aprobadas (<?= $counts['approved'] ?>)</a>
  <a href="?filter=rejected" class="filter-tab <?= $filter==='rejected'?'active':'' ?>">Rechazadas (<?= $counts['rejected'] ?>)</a>
</div>

<div class="card">
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>N° Solicitud</th><th>Tipo</th><th>Máquina</th><th>Sucursal</th><th>Litros</th><th>Solicitante</th><th>Fecha</th><th>Mi Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($approvals as $a): ?>
        <tr>
          <td><strong class="text-accent"><?= htmlspecialchars($a['request_number']) ?></strong></td>
          <td style="text-transform:capitalize;">
            <?= htmlspecialchars($a['request_type']) ?>
            <?php if (!empty($a['service_call_number'])): ?>
              <br><small class="text-muted">N° <?= htmlspecialchars($a['service_call_number']) ?></small>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= htmlspecialchars($a['machine_name']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($a['branch_name']) ?></td>
          <td><?= number_format($a['liters_requested'],0) ?> L</td>
          <td class="text-muted"><?= htmlspecialchars($a['requester_name']) ?></td>
          <td class="text-muted" style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($a['requested_at'])) ?></td>
          <td><span class="badge <?= $statusMap[$a['status']]['class'] ?>"><?= $statusMap[$a['status']]['label'] ?></span></td>
          <td style="display:flex;gap:6px;">
            <?php if ($a['status'] === 'pendiente'): ?>
            <a href="<?= APP_URL ?>/modules/approvals/approve.php?id=<?= $a['request_id'] ?>" class="btn btn-primary btn-sm">✅ Revisar</a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/modules/requests/view.php?id=<?= $a['request_id'] ?>" class="btn btn-secondary btn-sm">👁 Ver</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($approvals)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:30px;">Sin aprobaciones en esta categoría</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
