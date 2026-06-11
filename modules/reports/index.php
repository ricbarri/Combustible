<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db   = getDB();
$user = currentUser();

$isSA    = $user['profile'] === 'superadmin';
$isAdmin = in_array($user['profile'], ['administrador','superadmin']);

// ── Sección activa: estanque | servicentro ────────────────────────────────
$section = $_GET['section'] ?? 'estanque';

// ── Filtros comunes ───────────────────────────────────────────────────────
$groupBy      = $_GET['group_by']   ?? 'branch';
$dateFrom     = $_GET['date_from']  ?? date('Y-m-01');
$dateTo       = $_GET['date_to']    ?? date('Y-m-d');
$branchFilter = (int)($_GET['branch_id']  ?? 0);
$companyFilter= (int)($_GET['company_id'] ?? 0);
$exporting    = isset($_GET['export']) && $_GET['export'] === 'csv';

$companies = $isSA ? $db->query("SELECT id,name FROM companies WHERE active=1 ORDER BY name")->fetchAll() : [];
$branches  = $db->query("SELECT id,name FROM branches WHERE active=1 ORDER BY name")->fetchAll();
$vehicles  = $db->query("SELECT id,name,plate FROM vehicles WHERE active=1 ORDER BY name")->fetchAll();

// ════════════════════════════════════════════════════════════════════════════
// SECCIÓN: ESTANQUE (solicitudes de combustible)
// ════════════════════════════════════════════════════════════════════════════
if ($section === 'estanque') {

    $validGroups = ['branch','user','machine','type'];
    if (!in_array($groupBy, $validGroups)) $groupBy = 'branch';

    $where  = ["fr.status IN ('entregado','aprobado')", "DATE(fr.requested_at) BETWEEN ? AND ?"];
    $params = [$dateFrom, $dateTo];

    if ($isSA) {
        if ($companyFilter) { $where[] = 'b.company_id = ?'; $params[] = $companyFilter; }
        if ($branchFilter)  { $where[] = 'fr.branch_id = ?'; $params[] = $branchFilter; }
    } elseif ($isAdmin) {
        if ($branchFilter)  { $where[] = 'fr.branch_id = ?'; $params[] = $branchFilter; }
    } else {
        $where[] = 'fr.branch_id = ?'; $params[] = $user['branch_id'];
    }

    $whereSQL = implode(' AND ', $where);

    $selectMap = [
        'branch'  => "b.name AS label, b.id AS entity_id",
        'user'    => "u.name AS label, u.id AS entity_id",
        'machine' => "COALESCE(m.name,'Sin máquina') AS label, COALESCE(m.id,0) AS entity_id",
        'type'    => "fr.request_type AS label, fr.request_type AS entity_id",
    ];
    $groupMap = ['branch'=>"b.id",'user'=>"u.id",'machine'=>"m.id",'type'=>"fr.request_type"];

    $sql = "SELECT {$selectMap[$groupBy]},
        COUNT(*) AS total_solicitudes,
        SUM(COALESCE(fr.liters_delivered, fr.liters_requested)) AS total_litros,
        SUM(CASE WHEN fr.status='entregado' THEN COALESCE(fr.liters_delivered,0) ELSE 0 END) AS litros_entregados,
        COUNT(CASE WHEN fr.status='entregado' THEN 1 END) AS entregadas,
        COUNT(CASE WHEN fr.status='aprobado'  THEN 1 END) AS pendientes_entrega
        FROM fuel_requests fr
        JOIN branches b ON fr.branch_id = b.id
        JOIN users u ON fr.requester_id = u.id
        LEFT JOIN machines m ON fr.machine_id = m.id
        WHERE {$whereSQL}
        GROUP BY {$groupMap[$groupBy]}, label
        ORDER BY total_litros DESC";

    $rows   = $db->prepare($sql);
    $rows->execute($params);
    $rows   = $rows->fetchAll();

    $totalStmt = $db->prepare("SELECT COUNT(*) AS total_sol,
        SUM(COALESCE(liters_delivered, liters_requested)) AS total_litros,
        COUNT(DISTINCT requester_id) AS total_usuarios,
        COUNT(DISTINCT branch_id) AS total_sucursales
        FROM fuel_requests fr
        JOIN branches b ON fr.branch_id = b.id
        WHERE {$whereSQL}");
    $totalStmt->execute($params);
    $totals = $totalStmt->fetch();

    $detailStmt = $db->prepare("SELECT fr.*, b.name AS branch_name, u.name AS requester_name,
        COALESCE(m.name,'—') AS machine_name, m.code AS machine_code, u2.name AS delivered_by_name
        FROM fuel_requests fr
        JOIN branches b ON fr.branch_id = b.id
        JOIN users u ON fr.requester_id = u.id
        LEFT JOIN machines m ON fr.machine_id = m.id
        LEFT JOIN users u2 ON fr.delivered_by = u2.id
        WHERE {$whereSQL} ORDER BY fr.requested_at DESC LIMIT 200");
    $detailStmt->execute($params);
    $details = $detailStmt->fetchAll();

    if ($exporting) {
        $expStmt = $db->prepare("SELECT fr.request_number, fr.requested_at, fr.request_type,
            fr.service_call_number, fr.fuel_type, fr.liters_requested, fr.liters_delivered,
            fr.status, b.name AS sucursal, u.name AS solicitante,
            COALESCE(m.name,'') AS maquina, u2.name AS entregado_por, fr.delivered_at
            FROM fuel_requests fr
            JOIN branches b ON fr.branch_id = b.id
            JOIN users u ON fr.requester_id = u.id
            LEFT JOIN machines m ON fr.machine_id = m.id
            LEFT JOIN users u2 ON fr.delivered_by = u2.id
            WHERE {$whereSQL} ORDER BY fr.requested_at DESC");
        $expStmt->execute($params);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reporte_estanque_'.date('Ymd').'.csv"');
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out,['N° Solicitud','Fecha','Tipo','N° Llamada','Combustible','Litros Solic.','Litros Entregados','Estado','Sucursal','Solicitante','Máquina','Entregado por','Fecha Entrega'],';');
        foreach ($expStmt->fetchAll() as $r) fputcsv($out, array_values($r), ';');
        fclose($out); exit;
    }
}

// ════════════════════════════════════════════════════════════════════════════
// SECCIÓN: SERVICENTRO (cargas vehículos)
// ════════════════════════════════════════════════════════════════════════════
if ($section === 'servicentro') {

    $groupBy     = $_GET['group_by'] ?? 'vehicle';
    $validGroups = ['vehicle','user','fuel_type','company'];
    if (!in_array($groupBy, $validGroups)) $groupBy = 'vehicle';
    $vehicleFilter = (int)($_GET['vehicle_id'] ?? 0);

    $where  = ["DATE(sfl.load_date) BETWEEN ? AND ?"];
    $params = [$dateFrom, $dateTo];

    if ($isSA) {
        if ($companyFilter)  { $where[] = 'sfl.company_id = ?'; $params[] = $companyFilter; }
        if ($vehicleFilter)  { $where[] = 'sfl.vehicle_id = ?'; $params[] = $vehicleFilter; }
    } elseif ($isAdmin) {
        $where[] = 'v.company_id = (SELECT company_id FROM branches WHERE id=?)';
        $params[] = $user['branch_id'];
        if ($vehicleFilter) { $where[] = 'sfl.vehicle_id = ?'; $params[] = $vehicleFilter; }
    } else {
        $where[] = 'v.company_id = (SELECT company_id FROM branches WHERE id=?)';
        $params[] = $user['branch_id'];
    }

    $whereSQL = implode(' AND ', $where);

    $selectMap = [
        'vehicle'   => "CONCAT(v.name,' (',sfl.plate,')') AS label, v.id AS entity_id",
        'user'      => "u.name AS label, u.id AS entity_id",
        'fuel_type' => "sfl.fuel_type AS label, sfl.fuel_type AS entity_id",
        'company'   => "c.name AS label, c.id AS entity_id",
    ];
    $groupMapSF = [
        'vehicle'   => "v.id",
        'user'      => "u.id",
        'fuel_type' => "sfl.fuel_type",
        'company'   => "c.id",
    ];

    $sfSql = "SELECT {$selectMap[$groupBy]},
        COUNT(*) AS total_cargas,
        SUM(COALESCE(sfl.liters,0)) AS total_litros,
        SUM(COALESCE(sfl.amount_clp,0)) AS total_monto,
        MAX(sfl.odometer) AS max_odometer,
        MIN(sfl.odometer) AS min_odometer
        FROM service_fuel_loads sfl
        JOIN vehicles v  ON sfl.vehicle_id = v.id
        JOIN users u     ON sfl.user_id    = u.id
        JOIN companies c ON sfl.company_id = c.id
        WHERE {$whereSQL}
        GROUP BY {$groupMapSF[$groupBy]}, label
        ORDER BY total_cargas DESC";

    $sfRows = $db->prepare($sfSql);
    $sfRows->execute($params);
    $sfRows = $sfRows->fetchAll();

    $sfTotalsStmt = $db->prepare("SELECT COUNT(*) AS total_cargas,
        SUM(COALESCE(sfl.liters,0)) AS total_litros,
        SUM(COALESCE(sfl.amount_clp,0)) AS total_monto,
        COUNT(DISTINCT sfl.vehicle_id) AS total_vehiculos,
        COUNT(DISTINCT sfl.user_id) AS total_usuarios
        FROM service_fuel_loads sfl
        JOIN vehicles v ON sfl.vehicle_id = v.id
        JOIN companies c ON sfl.company_id = c.id
        WHERE {$whereSQL}");
    $sfTotalsStmt->execute($params);
    $sfTotals = $sfTotalsStmt->fetch();

    $sfDetailStmt = $db->prepare("SELECT sfl.load_date, sfl.plate, v.name AS vehicle_name,
        u.name AS user_name, c.name AS company_name, b.name AS branch_name,
        sfl.fuel_type, sfl.odometer, sfl.liters, sfl.amount_clp,
        sfl.station_name, sfl.gps_lat, sfl.gps_lng, sfl.notes
        FROM service_fuel_loads sfl
        JOIN vehicles v  ON sfl.vehicle_id = v.id
        JOIN users u     ON sfl.user_id    = u.id
        JOIN companies c ON sfl.company_id = c.id
        JOIN branches b  ON v.branch_id    = b.id
        WHERE {$whereSQL}
        ORDER BY sfl.load_date DESC, sfl.created_at DESC
        LIMIT 200");
    $sfDetailStmt->execute($params);
    $sfDetails = $sfDetailStmt->fetchAll();

    if ($exporting) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reporte_servicentro_'.date('Ymd').'.csv"');
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out,['Fecha','Patente','Vehículo','Usuario','Empresa','Sucursal','Combustible','Odómetro','Litros','Monto $','Servicentro','GPS Lat','GPS Lng','Notas'],';');
        foreach ($sfDetails as $r) fputcsv($out, array_values($r), ';');
        fclose($out); exit;
    }
}

$groupLabels = ['branch'=>'Sucursal','user'=>'Usuario','machine'=>'Máquina','type'=>'Tipo'];
$sfGroupLabels = ['vehicle'=>'Vehículo','user'=>'Usuario','fuel_type'=>'Tipo Combustible','company'=>'Empresa'];
$statusMap   = ['pendiente'=>'Pendiente','en_aprobacion'=>'En aprobación','aprobado'=>'Aprobado','rechazado'=>'Rechazado','entregado'=>'Entregado'];
$statusBadge = ['pendiente'=>'badge-pending','en_aprobacion'=>'badge-process','aprobado'=>'badge-approved','rechazado'=>'badge-rejected','entregado'=>'badge-delivered'];

$pageTitle = 'Reportes';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.section-tabs  { display:flex;gap:6px;margin-bottom:20px; }
.section-tab   { padding:10px 22px;border-radius:var(--radius);text-decoration:none;font-size:14px;font-weight:700;border:1px solid var(--border);color:var(--text-muted);background:var(--bg-panel);transition:all .15s;min-height:var(--touch-min);display:flex;align-items:center;gap:8px; }
.section-tab:hover  { border-color:var(--accent);color:var(--text); }
.section-tab.active { background:var(--accent);color:#000;border-color:var(--accent); }
.report-tabs { display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap; }
.report-tab  { padding:8px 16px;border-radius:var(--radius);text-decoration:none;font-size:12px;font-weight:600;color:var(--text-muted);background:var(--bg-panel);border:1px solid var(--border);transition:all .15s;min-height:38px;display:flex;align-items:center; }
.report-tab:hover  { color:var(--text);border-color:var(--accent); }
.report-tab.active { background:var(--accent);color:#000;border-color:var(--accent); }
.bar-wrap { background:var(--bg-input);border-radius:4px;height:10px;overflow:hidden;margin-top:4px; }
.bar-fill { height:100%;border-radius:4px;background:linear-gradient(90deg,var(--accent-dark),var(--accent));transition:width .6s ease; }
</style>

<!-- Selector de sección -->
<div class="section-tabs">
  <a href="?section=estanque&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
     class="section-tab <?= $section==='estanque'?'active':'' ?>">
    🛢 Estanque / Solicitudes
  </a>
  <a href="?section=servicentro&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
     class="section-tab <?= $section==='servicentro'?'active':'' ?>">
    🚗 Cargas Servicentro
  </a>
</div>

<!-- Filtros -->
<div class="card mb-16">
  <div class="card-header"><span class="card-title">🔍 Filtros</span></div>
  <div class="card-body">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <input type="hidden" name="section"   value="<?= htmlspecialchars($section) ?>">
      <input type="hidden" name="group_by"  value="<?= htmlspecialchars($groupBy) ?>">
      <div class="form-group" style="min-width:130px;">
        <label>Desde</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="form-group" style="min-width:130px;">
        <label>Hasta</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
      </div>
      <?php if ($isSA): ?>
      <div class="form-group" style="min-width:170px;">
        <label>Empresa</label>
        <select name="company_id">
          <option value="">Todas</option>
          <?php foreach ($companies as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $companyFilter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($isAdmin && $section==='estanque'): ?>
      <div class="form-group" style="min-width:170px;">
        <label>Sucursal</label>
        <select name="branch_id">
          <option value="">Todas</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $branchFilter==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($isAdmin && $section==='servicentro'): ?>
      <div class="form-group" style="min-width:170px;">
        <label>Vehículo</label>
        <select name="vehicle_id">
          <option value="">Todos</option>
          <?php foreach ($vehicles as $v): ?>
          <option value="<?= $v['id'] ?>" <?= (isset($vehicleFilter)&&$vehicleFilter==$v['id'])?'selected':'' ?>><?= htmlspecialchars($v['name']) ?> (<?= htmlspecialchars($v['plate']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn btn-secondary">⬇ CSV</a>
      </div>
    </form>
  </div>
</div>

<?php if ($section === 'estanque'): ?>
<!-- ════ ESTANQUE ════════════════════════════════════════════════════════ -->

<!-- Stats -->
<div class="stats-grid mb-16" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card"><div class="stat-label">Solicitudes</div><div class="stat-value"><?= number_format($totals['total_sol']??0) ?></div><div class="stat-unit">en el periodo</div></div>
  <div class="stat-card"><div class="stat-label">Litros consumidos</div><div class="stat-value text-accent"><?= number_format($totals['total_litros']??0,0) ?></div><div class="stat-unit">litros</div></div>
  <div class="stat-card"><div class="stat-label">Usuarios activos</div><div class="stat-value"><?= $totals['total_usuarios']??0 ?></div><div class="stat-unit">solicitantes</div></div>
  <div class="stat-card"><div class="stat-label">Sucursales</div><div class="stat-value"><?= $totals['total_sucursales']??0 ?></div><div class="stat-unit">con actividad</div></div>
</div>

<!-- Tabs agrupación -->
<div class="report-tabs">
  <?php foreach (['branch'=>'🏢 Sucursal','user'=>'👤 Usuario','machine'=>'⚙ Máquina','type'=>'📋 Tipo'] as $k=>$lbl): ?>
  <a href="?<?= http_build_query(array_merge($_GET,['group_by'=>$k])) ?>" class="report-tab <?= $groupBy===$k?'active':'' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="card mb-16">
  <div class="card-header">
    <span class="card-title">Consumo por <?= $groupLabels[$groupBy] ?></span>
    <span class="text-muted" style="font-size:12px;"><?= $dateFrom ?> → <?= $dateTo ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($rows)): ?>
    <div style="text-align:center;padding:30px;color:var(--text-muted);">Sin datos para el periodo.</div>
    <?php else: $maxL = max(array_column($rows,'litros_entregados')?:[1]); ?>
    <div class="table-wrapper">
    <table class="data-table" style="min-width:480px;">
      <thead><tr><th><?= $groupLabels[$groupBy] ?></th><th>Solicitudes</th><th>Litros entregados</th><th>% total</th><th>Pendientes</th><th>Gráfico</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $pct    = ($totals['total_litros']??0)>0 ? round($r['litros_entregados']/($totals['total_litros'])*100,1) : 0;
          $barPct = $maxL>0 ? round($r['litros_entregados']/$maxL*100) : 0; ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['label']) ?></strong></td>
          <td><?= $r['total_solicitudes'] ?></td>
          <td><strong class="text-accent"><?= number_format($r['litros_entregados'],0) ?> L</strong></td>
          <td><?= $pct ?>%</td>
          <td><?= $r['pendientes_entrega']>0 ? '<span class="badge badge-pending">'.$r['pendientes_entrega'].'</span>' : '—' ?></td>
          <td style="min-width:100px;"><div class="bar-wrap"><div class="bar-fill" style="width:<?= $barPct ?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Detalle Solicitudes</span><span class="text-muted" style="font-size:12px;"><?= count($details) ?> registros</span></div>
  <div class="table-wrapper">
    <table class="data-table">
      <thead><tr><th>N° Sol.</th><th>Fecha</th><th>Tipo</th><th>N° Llamada</th><th>Máquina</th><th>Sucursal</th><th>Solicitante</th><th>Litros sol.</th><th>Litros entregados</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($details as $d): ?>
        <tr>
          <td><strong class="text-accent"><?= htmlspecialchars($d['request_number']) ?></strong></td>
          <td class="text-muted" style="white-space:nowrap;"><?= date('d/m/Y',strtotime($d['requested_at'])) ?></td>
          <td style="text-transform:capitalize;"><?= htmlspecialchars($d['request_type']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($d['service_call_number']??'—') ?></td>
          <td class="text-muted"><?= htmlspecialchars($d['machine_name']) ?></td>
          <td><?= htmlspecialchars($d['branch_name']) ?></td>
          <td><?= htmlspecialchars($d['requester_name']) ?></td>
          <td><?= number_format($d['liters_requested'],0) ?> L</td>
          <td><?= $d['liters_delivered'] ? '<strong>'.number_format($d['liters_delivered'],0).' L</strong>' : '—' ?></td>
          <td><span class="badge <?= $statusBadge[$d['status']]??'' ?>"><?= $statusMap[$d['status']]??$d['status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($details)): ?><tr><td colspan="10" style="text-align:center;padding:24px;color:var(--text-muted);">Sin datos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- ════ SERVICENTRO ═════════════════════════════════════════════════════ -->

<!-- Stats -->
<div class="stats-grid mb-16" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card"><div class="stat-label">Total cargas</div><div class="stat-value"><?= number_format($sfTotals['total_cargas']??0) ?></div><div class="stat-unit">registros</div></div>
  <div class="stat-card"><div class="stat-label">Litros cargados</div><div class="stat-value text-accent"><?= number_format($sfTotals['total_litros']??0,0) ?></div><div class="stat-unit">litros</div></div>
  <div class="stat-card"><div class="stat-label">Vehículos</div><div class="stat-value"><?= $sfTotals['total_vehiculos']??0 ?></div><div class="stat-unit">distintos</div></div>
  <div class="stat-card"><div class="stat-label">Monto total</div><div class="stat-value" style="font-size:20px;">$<?= number_format($sfTotals['total_monto']??0,0,',','.') ?></div><div class="stat-unit">pesos</div></div>
</div>

<!-- Tabs agrupación -->
<div class="report-tabs">
  <?php
  $sfTabs = ['vehicle'=>'🚗 Vehículo','user'=>'👤 Usuario','fuel_type'=>'⛽ Combustible'];
  if ($isSA) $sfTabs['company'] = '🏛 Empresa';
  foreach ($sfTabs as $k=>$lbl): ?>
  <a href="?<?= http_build_query(array_merge($_GET,['group_by'=>$k])) ?>" class="report-tab <?= $groupBy===$k?'active':'' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="card mb-16">
  <div class="card-header">
    <span class="card-title">Cargas por <?= $sfGroupLabels[$groupBy]??$groupBy ?></span>
    <span class="text-muted" style="font-size:12px;"><?= $dateFrom ?> → <?= $dateTo ?></span>
  </div>
  <div class="card-body">
    <?php if (empty($sfRows)): ?>
    <div style="text-align:center;padding:30px;color:var(--text-muted);">Sin datos para el periodo.</div>
    <?php else: $maxC = max(array_column($sfRows,'total_cargas')?:[1]); ?>
    <div class="table-wrapper">
    <table class="data-table" style="min-width:480px;">
      <thead><tr><th><?= $sfGroupLabels[$groupBy]??'Label' ?></th><th>Cargas</th><th>Litros</th><th>Monto $</th><th>Gráfico</th></tr></thead>
      <tbody>
        <?php foreach ($sfRows as $r):
          $barPct = $maxC>0 ? round($r['total_cargas']/$maxC*100) : 0; ?>
        <tr>
          <td><strong><?= htmlspecialchars($r['label']) ?></strong></td>
          <td><?= $r['total_cargas'] ?></td>
          <td><strong class="text-accent"><?= $r['total_litros']>0 ? number_format($r['total_litros'],1).' L' : '—' ?></strong></td>
          <td><?= $r['total_monto']>0 ? '$'.number_format($r['total_monto'],0,',','.') : '—' ?></td>
          <td style="min-width:100px;"><div class="bar-wrap"><div class="bar-fill" style="width:<?= $barPct ?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Detalle Cargas Servicentro</span><span class="text-muted" style="font-size:12px;"><?= count($sfDetails) ?> registros</span></div>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Patente</th>
          <th>Vehículo</th>
          <th>Usuario</th>
          <?php if ($isSA): ?><th>Empresa</th><?php endif; ?>
          <th>Odómetro</th>
          <th>Combustible</th>
          <th>Litros</th>
          <th>Monto</th>
          <th>Servicentro</th>
          <th>GPS</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sfDetails as $d): ?>
        <tr>
          <td style="white-space:nowrap;"><strong><?= date('d/m/Y',strtotime($d['load_date'])) ?></strong></td>
          <td><code style="background:var(--bg-input);padding:2px 7px;border-radius:4px;letter-spacing:1.5px;font-size:12px;"><?= htmlspecialchars($d['plate']) ?></code></td>
          <td><?= htmlspecialchars($d['vehicle_name']) ?></td>
          <td><?= htmlspecialchars($d['user_name']) ?></td>
          <?php if ($isSA): ?><td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($d['company_name']) ?></td><?php endif; ?>
          <td><strong><?= number_format($d['odometer'],0) ?> km</strong></td>
          <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($d['fuel_type']) ?></td>
          <td><?= $d['liters'] ? number_format($d['liters'],1).' L' : '—' ?></td>
          <td><?= $d['amount_clp'] ? '$'.number_format($d['amount_clp'],0,',','.') : '—' ?></td>
          <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($d['station_name']??'—') ?></td>
          <td>
            <?php if ($d['gps_lat'] && $d['gps_lng']): ?>
            <a href="https://www.google.com/maps?q=<?= $d['gps_lat'] ?>,<?= $d['gps_lng'] ?>" target="_blank" class="btn btn-secondary btn-sm">📍</a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($sfDetails)): ?><tr><td colspan="11" style="text-align:center;padding:24px;color:var(--text-muted);">Sin datos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
