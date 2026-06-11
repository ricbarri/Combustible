<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db   = getDB();
$user = currentUser();

$isSA    = $user['profile'] === 'superadmin';
$isAdmin = in_array($user['profile'], ['administrador','superadmin']);

// ── Filtro de empresa para superadmin ─────────────────────────────────────
$companyFilter = (int)($_GET['company_id'] ?? 0);

// ── Stats solicitudes ─────────────────────────────────────────────────────
// Superadmin ve todo; admin ve su empresa; otros ven su sucursal
$statsWhere  = '1=1';
$statsParams = [];
if ($isSA) {
    if ($companyFilter) {
        $statsWhere  = 'fr.branch_id IN (SELECT id FROM branches WHERE company_id=?)';
        $statsParams = [$companyFilter];
    }
} elseif ($user['profile'] === 'administrador') {
    $statsWhere  = 'fr.branch_id IN (SELECT id FROM branches WHERE company_id=(SELECT company_id FROM branches WHERE id=?))';
    $statsParams = [$user['branch_id']];
} elseif ($user['profile'] === 'solicitante') {
    $statsWhere  = 'fr.requester_id = ?';
    $statsParams = [$user['id']];
} else {
    $statsWhere  = 'fr.branch_id = ?';
    $statsParams = [$user['branch_id']];
}

$stmtStats = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status IN ('pendiente','en_aprobacion') THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN status = 'aprobado'  THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) AS delivered
    FROM fuel_requests fr WHERE {$statsWhere}");
$stmtStats->execute($statsParams);
$stats = $stmtStats->fetch();

// ── Stats servicentro ─────────────────────────────────────────────────────
$sfWhere  = '1=1';
$sfParams = [];
if ($isSA) {
    if ($companyFilter) { $sfWhere = 'sfl.company_id=?'; $sfParams = [$companyFilter]; }
} elseif ($user['profile'] === 'administrador') {
    $sfWhere  = 'v.company_id=(SELECT company_id FROM branches WHERE id=?)';
    $sfParams = [$user['branch_id']];
} else {
    $sfWhere  = 'sfl.user_id=?';
    $sfParams = [$user['id']];
}
$sfStmt = $db->prepare("SELECT COUNT(*) AS total_cargas,
    COUNT(DISTINCT sfl.vehicle_id) AS total_vehiculos
    FROM service_fuel_loads sfl
    JOIN vehicles v ON sfl.vehicle_id=v.id
    WHERE {$sfWhere}");
$sfStmt->execute($sfParams);
$sfStats = $sfStmt->fetch();

// ── Estanques ─────────────────────────────────────────────────────────────
if ($isSA) {
    $tankQ = $companyFilter
        ? "SELECT t.*, b.name AS branch_name, c.name AS company_name FROM tanks t JOIN branches b ON t.branch_id=b.id JOIN companies c ON b.company_id=c.id WHERE b.active=1 AND b.company_id={$companyFilter} ORDER BY c.name,b.name"
        : "SELECT t.*, b.name AS branch_name, c.name AS company_name FROM tanks t JOIN branches b ON t.branch_id=b.id JOIN companies c ON b.company_id=c.id WHERE b.active=1 ORDER BY c.name,b.name";
    $allTanks = $db->query($tankQ)->fetchAll();
} elseif ($user['profile'] === 'administrador') {
    $allTanks = $db->query("SELECT t.*, b.name AS branch_name FROM tanks t JOIN branches b ON t.branch_id=b.id WHERE b.active=1 ORDER BY b.name")->fetchAll();
} else {
    $t = $db->prepare("SELECT t.*, b.name AS branch_name FROM tanks t JOIN branches b ON t.branch_id=b.id WHERE t.branch_id=?");
    $t->execute([$user['branch_id']]);
    $allTanks = $t->fetchAll();
}

// ── Últimas solicitudes ───────────────────────────────────────────────────
if ($user['profile'] === 'solicitante') {
    $rStmt = $db->prepare("SELECT fr.*, b.name AS branch_name, COALESCE(m.name,'—') AS machine_name
        FROM fuel_requests fr JOIN branches b ON fr.branch_id=b.id LEFT JOIN machines m ON fr.machine_id=m.id
        WHERE fr.requester_id=? ORDER BY fr.requested_at DESC LIMIT 6");
    $rStmt->execute([$user['id']]);
} elseif ($user['profile'] === 'aprobador') {
    $rStmt = $db->prepare("SELECT fr.*, b.name AS branch_name, COALESCE(m.name,'—') AS machine_name
        FROM fuel_requests fr JOIN branches b ON fr.branch_id=b.id LEFT JOIN machines m ON fr.machine_id=m.id
        JOIN request_approvals ra ON ra.request_id=fr.id
        WHERE ra.approver_id=? ORDER BY fr.requested_at DESC LIMIT 6");
    $rStmt->execute([$user['id']]);
} else {
    // admin, superadmin, cargador
    $rWhere = $isSA ? ($companyFilter ? "WHERE b.company_id={$companyFilter}" : '') : "WHERE fr.branch_id='{$user['branch_id']}'";
    if ($isSA && !$companyFilter) $rWhere = '';
    $rStmt = $db->prepare("SELECT fr.*, b.name AS branch_name, COALESCE(m.name,'—') AS machine_name
        FROM fuel_requests fr JOIN branches b ON fr.branch_id=b.id LEFT JOIN machines m ON fr.machine_id=m.id
        {$rWhere} ORDER BY fr.requested_at DESC LIMIT 6");
    $rStmt->execute([]);
}
$recentRequests = $rStmt->fetchAll();

// ── Últimas cargas servicentro ────────────────────────────────────────────
$sfRecentStmt = $db->prepare("SELECT sfl.load_date, sfl.plate, v.name AS vehicle_name,
    u.name AS user_name, sfl.odometer, sfl.liters
    FROM service_fuel_loads sfl
    JOIN vehicles v ON sfl.vehicle_id=v.id
    JOIN users u ON sfl.user_id=u.id
    WHERE {$sfWhere}
    ORDER BY sfl.load_date DESC, sfl.created_at DESC LIMIT 5");
$sfRecentStmt->execute($sfParams);
$sfRecent = $sfRecentStmt->fetchAll();

// ── Empresas para filtro superadmin ──────────────────────────────────────
$companies = $isSA ? $db->query("SELECT id,name FROM companies WHERE active=1 ORDER BY name")->fetchAll() : [];

$statusMap = [
    'pendiente'     => ['label'=>'Pendiente',     'class'=>'badge-pending'],
    'en_aprobacion' => ['label'=>'En aprobación', 'class'=>'badge-process'],
    'aprobado'      => ['label'=>'Aprobado',      'class'=>'badge-approved'],
    'rechazado'     => ['label'=>'Rechazado',     'class'=>'badge-rejected'],
    'entregado'     => ['label'=>'Entregado',     'class'=>'badge-delivered'],
];

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($isSA && !empty($companies)): ?>
<!-- Selector de empresa para superadmin -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
  <span style="font-size:12px;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.8px;">Filtrar por empresa:</span>
  <div style="display:flex;gap:6px;flex-wrap:wrap;">
    <a href="?" class="btn <?= !$companyFilter?'btn-primary':'btn-secondary' ?> btn-sm">Todas</a>
    <?php foreach ($companies as $c): ?>
    <a href="?company_id=<?= $c['id'] ?>" class="btn <?= $companyFilter==$c['id']?'btn-primary':'btn-secondary' ?> btn-sm">
      <?= htmlspecialchars($c['name']) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Stats generales -->
<div class="stats-grid mb-20">
  <div class="stat-card">
    <div class="stat-label">Solicitudes totales</div>
    <div class="stat-value"><?= number_format($stats['total']) ?></div>
    <div class="stat-unit">combustible estanque</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">En proceso</div>
    <div class="stat-value" style="color:var(--warning)"><?= $stats['pending'] ?></div>
    <div class="stat-unit">pendientes aprobación</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Aprobadas</div>
    <div class="stat-value" style="color:#3dc977"><?= $stats['approved'] ?></div>
    <div class="stat-unit">listas para entrega</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Entregadas</div>
    <div class="stat-value" style="color:#5baef7"><?= $stats['delivered'] ?></div>
    <div class="stat-unit">completadas</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Cargas servicentro</div>
    <div class="stat-value" style="color:#c39bd3"><?= number_format($sfStats['total_cargas']) ?></div>
    <div class="stat-unit"><?= $sfStats['total_vehiculos'] ?> vehículo(s)</div>
  </div>
</div>

<!-- Estanques -->
<?php if (!empty($allTanks)): ?>
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title">🛢 Estanques<?= count($allTanks)>1?' por Sucursal':'' ?></span>
    <?php if (in_array($user['profile'],['cargador','administrador','superadmin'])): ?>
    <div style="display:flex;gap:8px;">
      <a href="<?= APP_URL ?>/modules/tanks/load.php"   class="btn btn-primary btn-sm">Cargar</a>
      <a href="<?= APP_URL ?>/modules/tanks/adjust.php" class="btn btn-secondary btn-sm">Ajustar</a>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-body" style="padding:14px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
      <?php foreach ($allTanks as $t):
        $pct    = $t['capacity'] > 0 ? min(100, max(0, ($t['current_liters'] / $t['capacity']) * 100)) : 0;
        $gClass = $t['current_liters'] < 0 ? 'low' : ($pct < 25 ? 'low' : ($pct < 60 ? 'mid' : 'high'));
        $color  = $t['current_liters'] < 0 ? 'var(--danger)' : 'var(--accent)';
      ?>
      <div style="background:var(--bg-panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px;">
        <?php if ($isSA && isset($t['company_name'])): ?>
        <div style="font-size:10px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px;"><?= htmlspecialchars($t['company_name']) ?></div>
        <?php endif; ?>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:8px;">
          🏢 <?= htmlspecialchars($t['branch_name']) ?>
        </div>
        <div style="font-family:var(--font-display);font-size:28px;font-weight:700;color:<?= $color ?>;line-height:1;">
          <?= number_format($t['current_liters'], 0) ?> <span style="font-size:13px;">L</span>
        </div>
        <div class="gauge-bar" style="margin:8px 0 4px;">
          <div class="gauge-fill <?= $gClass ?>" style="width:<?= $pct ?>%"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);">
          <span><?= round($pct,1) ?>%</span>
          <span>Máx <?= number_format($t['capacity'],0) ?> L</span>
        </div>
        <?php if ($t['current_liters'] < 0): ?>
        <div style="margin-top:6px;font-size:11px;color:var(--danger);font-weight:700;">⛔ Negativo</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Grid inferior: solicitudes + servicentro -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

  <!-- Últimas solicitudes -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">📋 Últimas Solicitudes</span>
      <a href="<?= APP_URL ?>/modules/requests/list.php" class="btn btn-secondary btn-sm">Ver todas</a>
    </div>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr><th>N°</th><th>Tipo</th><th>Litros</th><th>Estado</th><th>Fecha</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentRequests as $r): ?>
          <tr>
            <td><span class="text-accent" style="font-size:12px;"><?= htmlspecialchars($r['request_number']) ?></span></td>
            <td style="text-transform:capitalize;font-size:12px;"><?= htmlspecialchars($r['request_type']) ?></td>
            <td style="font-size:12px;"><?= number_format($r['liters_requested'],0) ?> L</td>
            <td><span class="badge <?= $statusMap[$r['status']]['class'] ?>" style="font-size:10px;"><?= $statusMap[$r['status']]['label'] ?></span></td>
            <td class="text-muted" style="font-size:11px;white-space:nowrap;"><?= date('d/m/Y', strtotime($r['requested_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($recentRequests)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:20px;">Sin solicitudes</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (in_array($user['profile'],['solicitante','administrador','superadmin'])): ?>
    <div style="padding:12px 14px;border-top:1px solid var(--border);">
      <a href="<?= APP_URL ?>/modules/requests/create.php" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;">➕ Nueva Solicitud</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Últimas cargas servicentro -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">🚗 Cargas Servicentro</span>
      <a href="<?= APP_URL ?>/modules/service_fuel/list.php" class="btn btn-secondary btn-sm">Ver todas</a>
    </div>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr><th>Fecha</th><th>Patente</th><th>Vehículo</th><th>Odómetro</th></tr>
        </thead>
        <tbody>
          <?php foreach ($sfRecent as $sf): ?>
          <tr>
            <td style="font-size:12px;white-space:nowrap;"><?= date('d/m/Y', strtotime($sf['load_date'])) ?></td>
            <td><code style="background:var(--bg-input);padding:1px 6px;border-radius:3px;font-size:11px;letter-spacing:1px;"><?= htmlspecialchars($sf['plate']) ?></code></td>
            <td style="font-size:12px;"><?= htmlspecialchars($sf['vehicle_name']) ?></td>
            <td style="font-size:12px;"><strong><?= number_format($sf['odometer'],0) ?> km</strong></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($sfRecent)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px;">Sin registros</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:12px 14px;border-top:1px solid var(--border);">
      <a href="<?= APP_URL ?>/modules/service_fuel/create.php" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;">➕ Registrar Carga</a>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
