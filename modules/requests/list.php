<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db   = getDB();
$user = currentUser();

$filter = $_GET['filter'] ?? 'all';

$statusMap = [
    'pendiente'    => ['label'=>'Pendiente',    'class'=>'badge-pending'],
    'en_aprobacion'=> ['label'=>'En aprobación','class'=>'badge-process'],
    'aprobado'     => ['label'=>'Aprobado',     'class'=>'badge-approved'],
    'rechazado'    => ['label'=>'Rechazado',    'class'=>'badge-rejected'],
    'entregado'    => ['label'=>'Entregado',    'class'=>'badge-delivered'],
];

$where  = '';
$params = [];

// Base: solicitante only sees own, cargador/aprobador see all
if ($user['profile'] === 'solicitante') {
    $where  = 'WHERE fr.requester_id = ?';
    $params = [$user['id']];
} else {
    $where  = 'WHERE 1=1';
}

switch ($filter) {
    case 'sent':     $where .= ' AND fr.status IN (\'pendiente\',\'en_aprobacion\')'; break;
    case 'rejected': $where .= ' AND fr.status = \'rechazado\''; break;
    case 'approved': $where .= ' AND fr.status IN (\'aprobado\',\'entregado\')'; break;
}

$sql = "SELECT fr.*, b.name AS branch_name, COALESCE(m.name,'—') AS machine_name, u.name AS requester_name
        FROM fuel_requests fr
        JOIN branches b ON fr.branch_id=b.id
        LEFT JOIN machines m ON fr.machine_id=m.id
        JOIN users u ON fr.requester_id=u.id
        $where ORDER BY fr.requested_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Counts for tabs
$countAll = $countSent = $countRejected = $countApproved = 0;
foreach ($requests as $r) {
    $countAll++;
    if (in_array($r['status'],['pendiente','en_aprobacion'])) $countSent++;
    if ($r['status']==='rechazado') $countRejected++;
    if (in_array($r['status'],['aprobado','entregado'])) $countApproved++;
}

$pageTitle = 'Solicitudes de Combustible';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="flex-between">
  <div class="filter-tabs">
    <a href="?filter=all"      class="filter-tab <?= $filter==='all'?'active':'' ?>">Todas (<?= $countAll ?>)</a>
    <a href="?filter=sent"     class="filter-tab <?= $filter==='sent'?'active':'' ?>">Enviadas (<?= $countSent ?>)</a>
    <a href="?filter=rejected" class="filter-tab <?= $filter==='rejected'?'active':'' ?>">Rechazadas (<?= $countRejected ?>)</a>
    <a href="?filter=approved" class="filter-tab <?= $filter==='approved'?'active':'' ?>">Aprobadas (<?= $countApproved ?>)</a>
  </div>
  <?php if (in_array($user['profile'],['solicitante','cargador'])): ?>
  <a href="<?= APP_URL ?>/modules/requests/create.php" class="btn btn-primary">➕ Nueva Solicitud</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr><th>N° Solicitud</th><th>Tipo</th><th>Combustible</th><th>Máquina</th><th>Sucursal</th><th>Litros</th><th>Estado</th><th>Solicitante</th><th>Fecha</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr>
          <td><strong class="text-accent"><?= htmlspecialchars($r['request_number']) ?></strong></td>
          <td style="text-transform:capitalize;"><?= htmlspecialchars($r['request_type']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($r['fuel_type']) ?></td>
          <td><?= htmlspecialchars($r['machine_name']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($r['branch_name']) ?></td>
          <td><?= number_format($r['liters_requested'],0) ?> L <?php if ($r['liters_delivered']): ?><span class="text-success" style="font-size:11px;">(Entregado: <?= number_format($r['liters_delivered'],0) ?> L)</span><?php endif; ?></td>
          <td><span class="badge <?= $statusMap[$r['status']]['class'] ?>"><?= $statusMap[$r['status']]['label'] ?></span></td>
          <td class="text-muted"><?= htmlspecialchars($r['requester_name']) ?></td>
          <td class="text-muted" style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($r['requested_at'])) ?></td>
          <td>
            <a href="<?= APP_URL ?>/modules/requests/view.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">👁 Ver</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--text-muted);padding:30px;">Sin solicitudes en esta categoría</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
