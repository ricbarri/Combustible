<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$db   = getDB();
$user = currentUser();

// Vehículos activos
$vehicles = $db->query("
    SELECT v.*, b.name AS branch_name, c.name AS company_name
    FROM vehicles v
    JOIN branches b  ON v.branch_id  = b.id
    JOIN companies c ON v.company_id = c.id
    WHERE v.active = 1
    ORDER BY c.name, b.name, v.name
")->fetchAll();

// Vehículo por defecto del usuario
$defStmt = $db->prepare("SELECT default_vehicle_id FROM users WHERE id=?");
$defStmt->execute([$user['id']]);
$defaultVehicleId = (int)$defStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id   = (int)($_POST['vehicle_id']   ?? 0);
    $load_date    = $_POST['load_date']           ?? '';
    $odometer     = (float)($_POST['odometer']    ?? 0);
    $fuel_type    = $_POST['fuel_type']           ?? 'Petróleo Diesel';
    $liters       = (float)($_POST['liters']      ?? 0) ?: null;
    $amount_clp   = (float)($_POST['amount_clp']  ?? 0) ?: null;
    $station_name = trim($_POST['station_name']   ?? '');
    $notes        = trim($_POST['notes']          ?? '');
    $set_default  = isset($_POST['set_default']);
    // GPS
    $gps_lat      = trim($_POST['gps_lat']        ?? '');
    $gps_lng      = trim($_POST['gps_lng']        ?? '');
    $gps_accuracy = trim($_POST['gps_accuracy']   ?? '');
    $gps_address  = trim($_POST['gps_address']    ?? '');

    $errors = [];
    if (!$vehicle_id)   $errors[] = 'Selecciona un vehículo.';
    if (!$load_date)    $errors[] = 'Ingresa la fecha de carga.';
    if ($odometer <= 0) $errors[] = 'Ingresa el odómetro (km).';

    // Foto odómetro
    $photoPath = null; $photoName = null;
    if (!empty($_FILES['odometer_photo']['name'])) {
        $file = $_FILES['odometer_photo'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','heic'])) {
            $errors[] = 'Formato de foto no válido (JPG, PNG, WEBP).';
        } elseif ($file['size'] > 15 * 1024 * 1024) {
            $errors[] = 'La foto no puede superar 15 MB.';
        } else {
            $dir = __DIR__ . '/../../uploads/odometer_photos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $safeName = 'odo_' . $user['id'] . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . $safeName)) {
                $errors[] = 'Error al subir la foto del odómetro.';
            } else {
                $photoPath = $safeName;
                $photoName = basename($file['name']);
            }
        }
    }

    if (empty($errors)) {
        $vStmt = $db->prepare("SELECT plate, company_id FROM vehicles WHERE id=?");
        $vStmt->execute([$vehicle_id]);
        $vData = $vStmt->fetch();

        $db->prepare("
            INSERT INTO service_fuel_loads
                (company_id, vehicle_id, user_id, load_date, plate, odometer,
                 fuel_type, liters, amount_clp, station_name,
                 photo_path, photo_name,
                 gps_lat, gps_lng, gps_accuracy, gps_address, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $vData['company_id'], $vehicle_id, $user['id'],
            $load_date, $vData['plate'], $odometer,
            $fuel_type, $liters, $amount_clp, $station_name,
            $photoPath, $photoName,
            ($gps_lat  !== '' ? (float)$gps_lat  : null),
            ($gps_lng  !== '' ? (float)$gps_lng  : null),
            ($gps_accuracy !== '' ? (float)$gps_accuracy : null),
            $gps_address ?: null,
            $notes
        ]);

        if ($set_default) {
            $db->prepare("UPDATE users SET default_vehicle_id=? WHERE id=?")->execute([$vehicle_id, $user['id']]);
        }

        logEvent(LOG_REQUEST, 'SERVICE_FUEL_CREATE',
            "Carga servicentro: {$vData['plate']} — Odómetro: {$odometer} km",
            LOG_INFO, ['vehicle_id'=>$vehicle_id,'odometer'=>$odometer,'gps_lat'=>$gps_lat,'gps_lng'=>$gps_lng]
        );
        $_SESSION['success'] = 'Carga registrada correctamente.';
        header('Location: ' . APP_URL . '/modules/service_fuel/list.php'); exit;
    } else {
        if ($photoPath) @unlink(__DIR__ . '/../../uploads/odometer_photos/' . $photoPath);
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

$pageTitle = 'Registrar Carga en Servicentro';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.gps-box { background:var(--bg-panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-top:6px; }
.gps-status { display:flex;align-items:center;gap:10px;font-size:13px; }
.gps-dot { width:10px;height:10px;border-radius:50%;background:var(--text-dim);flex-shrink:0;transition:background .3s; }
.gps-dot.ok      { background:#3dc977; }
.gps-dot.waiting { background:var(--warning);animation:pulse 1s infinite; }
.gps-dot.error   { background:var(--danger); }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
</style>

<div style="max-width:680px;">
<div class="card">
  <div class="card-header"><span class="card-title">⛽ Nueva Carga en Servicentro</span></div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data" id="fuelForm">

      <!-- GPS ocultos -->
      <input type="hidden" name="gps_lat"      id="gps_lat">
      <input type="hidden" name="gps_lng"      id="gps_lng">
      <input type="hidden" name="gps_accuracy" id="gps_accuracy">
      <input type="hidden" name="gps_address"  id="gps_address">

      <div class="form-grid">

        <div class="form-group full">
          <label>Vehículo <span style="color:var(--danger)">*</span></label>
          <select name="vehicle_id" required>
            <option value="">-- Seleccionar --</option>
            <?php foreach ($vehicles as $v):
              $sel = isset($_POST['vehicle_id'])
                ? ($_POST['vehicle_id'] == $v['id'])
                : ($defaultVehicleId && $defaultVehicleId == $v['id']);
            ?>
            <option value="<?= $v['id'] ?>" <?= $sel?'selected':'' ?>>
              <?= htmlspecialchars($v['name']) ?> — <?= htmlspecialchars($v['plate']) ?>
              (<?= htmlspecialchars($v['company_name']) ?> / <?= htmlspecialchars($v['branch_name']) ?>)
              <?= ($defaultVehicleId==$v['id']) ? ' ⭐' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <label style="margin-top:8px;display:flex;align-items:center;gap:8px;font-size:12px;text-transform:none;letter-spacing:0;cursor:pointer;color:var(--text-muted);">
            <input type="checkbox" name="set_default">
            Marcar como mi vehículo por defecto
          </label>
        </div>

        <div class="form-group">
          <label>Fecha de carga <span style="color:var(--danger)">*</span></label>
          <input type="date" name="load_date" value="<?= htmlspecialchars($_POST['load_date'] ?? date('Y-m-d')) ?>" required>
        </div>

        <div class="form-group">
          <label>Tipo de combustible</label>
          <select name="fuel_type">
            <?php foreach (['Petróleo Diesel','Gasolina 93','Gasolina 95','Gasolina 97'] as $ft): ?>
            <option value="<?= $ft ?>" <?= ($_POST['fuel_type']??'Petróleo Diesel')===$ft?'selected':'' ?>><?= $ft ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Odómetro (km) <span style="color:var(--danger)">*</span></label>
          <input type="number" name="odometer" min="0" step="0.1" required
            placeholder="Ej: 45230" value="<?= htmlspecialchars($_POST['odometer'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label>Litros cargados</label>
          <input type="number" name="liters" min="0" step="0.01"
            placeholder="Ej: 45.5" value="<?= htmlspecialchars($_POST['liters'] ?? '') ?>">
          <small class="text-muted">Opcional</small>
        </div>

        <div class="form-group">
          <label>Monto ($)</label>
          <input type="number" name="amount_clp" min="0" step="1"
            placeholder="Ej: 52000" value="<?= htmlspecialchars($_POST['amount_clp'] ?? '') ?>">
          <small class="text-muted">Opcional</small>
        </div>

        <div class="form-group full">
          <label>Servicentro</label>
          <input type="text" name="station_name"
            placeholder="Ej: Copec Av. Principal"
            value="<?= htmlspecialchars($_POST['station_name'] ?? '') ?>">
        </div>

        <div class="form-group full">
          <label>Foto del odómetro <span style="color:var(--danger)">*</span></label>
          <input type="file" name="odometer_photo" id="odometer_photo"
            accept="image/*" capture="environment"
            style="padding:8px;cursor:pointer;">
          <small class="text-muted">JPG, PNG o WEBP — máx 15 MB. Puedes usar la cámara.</small>
          <!-- Preview -->
          <div id="photo_preview" style="display:none;margin-top:10px;">
            <img id="preview_img" style="max-width:100%;max-height:200px;border-radius:var(--radius);border:1px solid var(--border);">
          </div>
        </div>

        <!-- Posición GPS -->
        <div class="form-group full">
          <label>📍 Posición GPS</label>
          <div class="gps-box">
            <div class="gps-status">
              <div class="gps-dot" id="gps_dot"></div>
              <span id="gps_text" style="color:var(--text-muted);">Esperando ubicación...</span>
            </div>
            <div id="gps_detail" style="display:none;margin-top:10px;font-size:12px;color:var(--text-muted);line-height:1.8;">
              <div id="gps_coords"></div>
              <div id="gps_addr"></div>
            </div>
            <button type="button" id="btn_gps" onclick="captureGPS()" class="btn btn-secondary btn-sm" style="margin-top:10px;">
              📍 Capturar ubicación ahora
            </button>
          </div>
          <small class="text-muted">La ubicación se captura automáticamente al cargar el formulario. Puedes actualizarla antes de guardar.</small>
        </div>

        <div class="form-group full">
          <label>Observaciones</label>
          <textarea name="notes" placeholder="Notas adicionales..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>

      </div>

      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">💾 Registrar Carga</button>
        <a href="<?= APP_URL ?>/modules/service_fuel/list.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div>

<script>
// ── Preview foto ──────────────────────────────────────────────────────────
document.getElementById('odometer_photo').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('preview_img').src = e.target.result;
    document.getElementById('photo_preview').style.display = 'block';
  };
  reader.readAsDataURL(file);
});

// ── GPS ───────────────────────────────────────────────────────────────────
function setGPSStatus(state, msg) {
  const dot  = document.getElementById('gps_dot');
  const text = document.getElementById('gps_text');
  dot.className  = 'gps-dot ' + state;
  text.textContent = msg;
}

function captureGPS() {
  if (!navigator.geolocation) {
    setGPSStatus('error', 'Tu dispositivo no soporta GPS.');
    return;
  }
  setGPSStatus('waiting', 'Obteniendo ubicación...');
  document.getElementById('btn_gps').disabled = true;

  navigator.geolocation.getCurrentPosition(
    pos => {
      const lat = pos.coords.latitude.toFixed(7);
      const lng = pos.coords.longitude.toFixed(7);
      const acc = pos.coords.accuracy ? pos.coords.accuracy.toFixed(1) : '';

      document.getElementById('gps_lat').value      = lat;
      document.getElementById('gps_lng').value      = lng;
      document.getElementById('gps_accuracy').value = acc;

      setGPSStatus('ok', 'Ubicación capturada correctamente');
      document.getElementById('gps_detail').style.display = 'block';
      document.getElementById('gps_coords').innerHTML =
        `📌 Lat: ${lat}, Lng: ${lng}` + (acc ? ` (±${acc} m)` : '');

      // Reverse geocoding con Nominatim (OpenStreetMap, gratuito)
      fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&accept-language=es`)
        .then(r => r.json())
        .then(data => {
          const addr = data.display_name || '';
          document.getElementById('gps_address').value = addr;
          document.getElementById('gps_addr').innerHTML = `📍 ${addr}`;
        })
        .catch(() => {});

      document.getElementById('btn_gps').disabled = false;
    },
    err => {
      const msgs = {1:'Permiso denegado',2:'Posición no disponible',3:'Tiempo de espera agotado'};
      setGPSStatus('error', msgs[err.code] || 'Error al obtener ubicación');
      document.getElementById('btn_gps').disabled = false;
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
  );
}

// Capturar GPS automáticamente al cargar
document.addEventListener('DOMContentLoaded', captureGPS);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
