<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('cargador'); // Solo cargador puede cargar estanque
$db   = getDB();
$user = currentUser();

define('UPLOAD_DIR', __DIR__ . '/../../uploads/tank_docs/');
define('UPLOAD_URL', APP_URL . '/uploads/tank_docs/');
$allowedExts = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];

$tankStmt = $db->prepare("SELECT t.*, b.name AS branch_name FROM tanks t JOIN branches b ON t.branch_id=b.id WHERE t.branch_id=?");
$tankStmt->execute([$user['branch_id']]);
$tank = $tankStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $liters = (float)($_POST['liters'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');

    if ($liters <= 0) {
        $_SESSION['error'] = 'Los litros deben ser mayor a 0.';
    } elseif (!$tank) {
        $_SESSION['error'] = 'No existe estanque para tu sucursal.';
    } else {
        $newTotal = $tank['current_liters'] + $liters;
        if ($newTotal > TANK_MAX_LITERS) {
            $_SESSION['error'] = "No es posible cargar {$liters} L. El estanque quedaría en {$newTotal} L, superando el máximo de " . TANK_MAX_LITERS . " L. Disponible para cargar: " . (TANK_MAX_LITERS - $tank['current_liters']) . " L.";
        } else {
            // ── Procesar archivo adjunto ──────────────────────────
            $docPath = null;
            $docName = null;
            if (!empty($_FILES['document']['name'])) {
                $file     = $_FILES['document'];
                $origName = basename($file['name']);
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExts)) {
                    $_SESSION['error'] = "Tipo de archivo no permitido ({$ext}). Use: " . implode(', ', $allowedExts);
                    header('Location: ' . APP_URL . '/modules/tanks/load.php');
                    exit;
                }
                if ($file['size'] > 10 * 1024 * 1024) {
                    $_SESSION['error'] = 'El archivo no puede superar 10 MB.';
                    header('Location: ' . APP_URL . '/modules/tanks/load.php');
                    exit;
                }
                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                $safeName = 'carga_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $safeName)) {
                    $_SESSION['error'] = 'Error al subir el archivo.';
                    header('Location: ' . APP_URL . '/modules/tanks/load.php');
                    exit;
                }
                $docPath = $safeName;
                $docName = $origName;
            }

            $db->beginTransaction();
            try {
                $db->prepare("UPDATE tanks SET current_liters=?, updated_at=NOW() WHERE id=?")->execute([$newTotal, $tank['id']]);
                $db->prepare("INSERT INTO tank_loads (tank_id,branch_id,user_id,liters_added,liters_before,liters_after,notes,document_path,document_name,loaded_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                   ->execute([$tank['id'],$tank['branch_id'],$user['id'],$liters,$tank['current_liters'],$newTotal,$notes,$docPath,$docName]);
                $db->commit();
                logEvent(LOG_TANK, 'TANK_LOAD',
                    "Carga: +{$liters} L → {$newTotal} L en {$tank['branch_name']}",
                    LOG_INFO,
                    ['tank_id'=>$tank['id'],'antes'=>$tank['current_liters'],'despues'=>$newTotal,'documento'=>$docName]
                );
                $_SESSION['success'] = "Estanque cargado correctamente. Litros actuales: {$newTotal} L.";
                header('Location: ' . APP_URL . '/modules/tanks/load.php');
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                if ($docPath && file_exists(UPLOAD_DIR . $docPath)) unlink(UPLOAD_DIR . $docPath);
                logException(LOG_TANK, 'TANK_LOAD_ERROR', $e);
                $_SESSION['error'] = 'Error al registrar la carga.';
            }
        }
    }
}

$history = $db->prepare("SELECT tl.*, u.name AS loader_name FROM tank_loads tl JOIN users u ON tl.user_id=u.id WHERE tl.branch_id=? ORDER BY tl.loaded_at DESC LIMIT 30");
$history->execute([$user['branch_id']]);
$history = $history->fetchAll();

$pageTitle = 'Carga de Estanque';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;">
  <div>
    <?php if ($tank): ?>
    <?php $pct = $tank['capacity'] > 0 ? ($tank['current_liters'] / $tank['capacity']) * 100 : 0; ?>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">🛢 Estado Actual</span></div>
      <div class="card-body">
        <div style="text-align:center;padding:10px 0;">
          <div style="font-family:var(--font-display);font-size:52px;font-weight:700;color:var(--accent);"><?= number_format($tank['current_liters'],0) ?></div>
          <div class="text-muted">litros disponibles</div>
        </div>
        <div class="gauge-bar" style="margin:10px 0;">
          <div class="gauge-fill <?= $pct<25?'low':($pct<60?'mid':'high') ?>" style="width:<?= min(max($pct,0),100) ?>%"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);">
          <span>0 L</span><span><?= round($pct,1) ?>%</span><span><?= number_format($tank['capacity'],0) ?> L máx</span>
        </div>
        <div style="margin-top:12px;font-size:12px;color:var(--text-muted);">
          Disponible para cargar: <strong style="color:var(--accent);"><?= number_format(TANK_MAX_LITERS - $tank['current_liters'], 0) ?> L</strong>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Nueva Carga</span></div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <div class="form-group" style="margin-bottom:14px;">
            <label>Litros a cargar <span style="color:var(--danger)">*</span></label>
            <input type="number" name="liters" min="1" max="<?= TANK_MAX_LITERS - $tank['current_liters'] ?>" step="0.01" required placeholder="Ej: 200">
            <small class="text-muted">Máximo disponible: <?= number_format(TANK_MAX_LITERS - $tank['current_liters'], 0) ?> L</small>
          </div>
          <div class="form-group" style="margin-bottom:14px;">
            <label>Notas (opcional)</label>
            <textarea name="notes" placeholder="Ej: Carga mensual proveedor X"></textarea>
          </div>
          <div class="form-group" style="margin-bottom:18px;">
            <label>Documento de respaldo (opcional)</label>
            <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
              style="padding:8px;cursor:pointer;">
            <small class="text-muted">PDF, imagen o documento — máx. 10 MB</small>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">⛽ Registrar Carga</button>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="card-body text-muted">No hay estanque configurado para tu sucursal.</div></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Historial de Cargas</span></div>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr><th>Fecha y Hora</th><th>Litros</th><th>Antes</th><th>Después</th><th>Cargador</th><th>Notas</th><th>Respaldo</th></tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
          <tr>
            <td style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($h['loaded_at'])) ?></td>
            <td><strong class="text-accent">+<?= number_format($h['liters_added'],0) ?> L</strong></td>
            <td class="text-muted"><?= number_format($h['liters_before'],0) ?> L</td>
            <td><?= number_format($h['liters_after'],0) ?> L</td>
            <td><?= htmlspecialchars($h['loader_name']) ?></td>
            <td class="text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
            <td>
              <?php if ($h['document_path']): ?>
                <a href="<?= APP_URL ?>/modules/tanks/download.php?type=load&id=<?= $h['id'] ?>"
                   class="btn btn-secondary btn-sm" title="<?= htmlspecialchars($h['document_name']) ?>">
                  📎 <?= htmlspecialchars(substr($h['document_name'], 0, 15)) . (strlen($h['document_name']) > 15 ? '…' : '') ?>
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($history)): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px;">Sin registros</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
