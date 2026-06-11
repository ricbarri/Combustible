<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db   = getDB();
$user = currentUser();

// Exportar CSV
$exporting = isset($_GET['export']) && $_GET['export'] === 'csv';

// Filtros
$filterCompany = (int)($_GET['company_id'] ?? 0);
$filterVehicle = (int)($_GET['vehicle_id'] ?? 0);
$filterFrom    = $_GET['date_from'] ?? date('Y-m-01');
$filterTo      = $_GET['date_to']   ?? date('Y-m-d');
$filterUser    = trim($_GET['user_name'] ?? '');

$where  = ['sfl.load_date BETWEEN ? AND ?'];
$params = [$filterFrom, $filterTo];

// Aislamiento por empresa: no-admin solo ve su empresa
if ($user['profile'] !== 'administrador') {
    $where[]  = 'v.company_id = (SELECT company_id FROM branches WHERE id=?)';
    $params[] = $user['branch_id'];
}
if ($filterCompany) { $where[] = 'sfl.company_id=?'; $params[] = $filterCompany; }
if ($filterVehicle) { $where[] = 'sfl.vehicle_id=?'; $params[] = $filterVehicle; }
if ($filterUser)    { $where[] = 'u.name LIKE ?';    $params[] = "%{$filterUser}%"; }

$whereSQL = implode(' AND ', $where);

if ($exporting) {
    $stmt = $db->prepare("
        SELECT sfl.load_date, u.name AS usuario, c.name AS empresa,
               b.name AS sucursal, v.name AS vehiculo, sfl.plate AS patente,
               sfl.fuel_type, sfl.odometer, sfl.liters, sfl.amount_clp,
               sfl.station_name, sfl.gps_lat, sfl.gps_lng, sfl.gps_address, sfl.notes
        FROM service_fuel_loads sfl
        JOIN vehicles v  ON sfl.vehicle_id=v.id
        JOIN users u     ON sfl.user_id=u.id
        JOIN branches b  ON v.branch_id=b.id
        JOIN companies c ON sfl.company_id=c.id
        WHERE {$whereSQL} ORDER BY sfl.load_date DESC, sfl.created_at DESC
    ");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cargas_servicentro_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['Fecha','Usuario','Empresa','Sucursal','Vehículo','Patente','Combustible','Odómetro km','Litros','Monto $','Servicentro','GPS Lat','GPS Lng','Dirección GPS','Notas'],';');
    foreach ($stmt->fetchAll() as $r) fputcsv($out, array_values($r), ';');
    fclose($out); exit;
}

$loads = $db->prepare("
    SELECT sfl.*, v.name AS vehicle_name, u.name AS user_name,
           b.name AS branch_name, c.name AS company_name
    FROM service_fuel_loads sfl
    JOIN vehicles v  ON sfl.vehicle_id=v.id
    JOIN users u     ON sfl.user_id=u.id
    JOIN branches b  ON v.branch_id=b.id
    JOIN companies c ON sfl.company_id=c.id
    WHERE {$whereSQL}
    ORDER BY sfl.load_date DESC, sfl.created_at DESC
");
$loads->execute($params);
$loads = $loads->fetchAll();

$companies = $db->query("SELECT id,name FROM companies WHERE active=1 ORDER BY name")->fetchAll();
$vehicles  = $db->query("SELECT id,name,plate FROM vehicles WHERE active=1 ORDER BY name")->fetchAll();

$pageTitle = 'Combustible — Cargas en Servicentro';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Filtros -->
<div class="card mb-16">
  <div class="card-header"><span class="card-title">🔍 Filtros</span></div>
  <div class="card-body">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <div class="form-group" style="min-width:130px;">
        <label>Desde</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($filterFrom) ?>">
      </div>
      <div class="form-group" style="min-width:130px;">
        <label>Hasta</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($filterTo) ?>">
      </div>
      <?php if ($user['profile'] === 'administrador'): ?>
      <div class="form-group" style="min-width:170px;">
        <label>Empresa</label>
        <select name="company_id">
          <option value="">Todas</option>
          <?php foreach ($companies as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterCompany==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group" style="min-width:160px;">
        <label>Vehículo</label>
        <select name="vehicle_id">
          <option value="">Todos</option>
          <?php foreach ($vehicles as $v): ?>
          <option value="<?= $v['id'] ?>" <?= $filterVehicle==$v['id']?'selected':'' ?>><?= htmlspecialchars($v['name']) ?> (<?= htmlspecialchars($v['plate']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="min-width:140px;">
        <label>Usuario</label>
        <input type="text" name="user_name" value="<?= htmlspecialchars($filterUser) ?>" placeholder="Nombre...">
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn btn-secondary">⬇ CSV</a>
        <a href="<?= APP_URL ?>/modules/service_fuel/list.php" class="btn btn-secondary">✕</a>
      </div>
    </form>
  </div>
</div>

<div class="flex-between">
  <div class="text-muted" style="font-size:13px;"><?= count($loads) ?> registro(s)</div>
  <a href="<?= APP_URL ?>/modules/service_fuel/create.php" class="btn btn-primary">➕ Registrar Carga</a>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">🚗 Cargas en Servicentro</span></div>
  <div class="table-wrapper">
    <table class="data-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Usuario</th>
          <?php if ($user['profile'] === 'administrador'): ?><th>Empresa</th><?php endif; ?>
          <th>Patente</th>
          <th>Vehículo</th>
          <th>Odómetro</th>
          <th>Combustible</th>
          <th>Litros</th>
          <th>Foto</th>
          <th>GPS</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($loads as $l): ?>
        <tr>
          <td style="white-space:nowrap;"><strong><?= date('d/m/Y', strtotime($l['load_date'])) ?></strong></td>
          <td><?= htmlspecialchars($l['user_name']) ?></td>
          <?php if ($user['profile'] === 'administrador'): ?>
          <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($l['company_name']) ?></td>
          <?php endif; ?>
          <td><code style="background:var(--bg-input);padding:2px 7px;border-radius:4px;letter-spacing:1.5px;font-size:12px;"><?= htmlspecialchars($l['plate']) ?></code></td>
          <td><?= htmlspecialchars($l['vehicle_name']) ?></td>
          <td><strong><?= number_format($l['odometer'],0) ?> km</strong></td>
          <td class="text-muted" style="font-size:12px;"><?= htmlspecialchars($l['fuel_type']) ?></td>
          <td><?= $l['liters'] ? number_format($l['liters'],1).' L' : '—' ?></td>
          <td>
            <?php if ($l['photo_path']): ?>
              <a href="<?= APP_URL ?>/modules/service_fuel/photo.php?id=<?= $l['id'] ?>"
                 target="_blank" class="btn btn-secondary btn-sm">📷</a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($l['gps_lat'] && $l['gps_lng']): ?>
              <a href="https://www.google.com/maps?q=<?= $l['gps_lat'] ?>,<?= $l['gps_lng'] ?>"
                 target="_blank" class="btn btn-secondary btn-sm" title="<?= htmlspecialchars($l['gps_address'] ?? $l['gps_lat'].','.$l['gps_lng']) ?>">
                📍 Ver mapa
              </a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($loads)): ?>
        <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text-muted);">Sin registros para el período seleccionado.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
