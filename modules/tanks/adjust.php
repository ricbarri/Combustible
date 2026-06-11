<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('cargador'); // Solo cargador puede ajustar estanque
$db   = getDB();
$user = currentUser();

define('UPLOAD_DIR_ADJ', __DIR__ . '/../../uploads/tank_docs/');
$allowedExts = ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx'];

$tankStmt = $db->prepare("SELECT t.*, b.name AS branch_name FROM tanks t JOIN branches b ON t.branch_id=b.id WHERE t.branch_id=?");
$tankStmt->execute([$user['branch_id']]);
$tank = $tankStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_liters = (float)($_POST['new_liters'] ?? -1);
    $reason     = trim($_POST['reason'] ?? '');

    if ($new_liters < 0) {
        $_SESSION['error'] = 'Los litros no pueden ser negativos.';
    } elseif ($new_liters > TANK_MAX_LITERS) {
        $_SESSION['error'] = 'Los litros no pueden superar el máximo de ' . TANK_MAX_LITERS . ' L.';
    } elseif (!$reason) {
        $_SESSION['error'] = 'El motivo del ajuste es obligatorio.';
    } elseif (!$tank) {
        $_SESSION['error'] = 'No existe estanque para tu sucursal.';
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
                header('Location: ' . APP_URL . '/modules/tanks/adjust.php');
                exit;
            }
            if ($file['size'] > 10 * 1024 * 1024) {
                $_SESSION['error'] = 'El archivo no puede superar 10 MB.';
                header('Location: ' . APP_URL . '/modules/tanks/adjust.php');
                exit;
            }
            if (!is_dir(UPLOAD_DIR_ADJ)) mkdir(UPLOAD_DIR_ADJ, 0755, true);
            $safeName = 'ajuste_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR_ADJ . $safeName)) {
                $_SESSION['error'] = 'Error al subir el archivo.';
                header('Location: ' . APP_URL . '/modules/tanks/adjust.php');
                exit;
            }
            $docPath = $safeName;
            $docName = $origName;
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tanks SET current_liters=?, updated_at=NOW() WHERE id=?")->execute([$new_liters, $tank['id']]);
            $db->prepare("INSERT INTO tank_adjustments (tank_id,branch_id,user_id,liters_before,liters_after,reason,document_path,document_name,adjusted_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
               ->execute([$tank['id'],$tank['branch_id'],$user['id'],$tank['current_liters'],$new_liters,$reason,$docPath,$docName]);
            $db->commit();
            $diff = $new_liters - $tank['current_liters'];
            logEvent(LOG_TANK, 'TANK_ADJUST',
                "Ajuste: {$tank['current_liters']} L → {$new_liters} L (" . ($diff>=0?"+$diff":$diff) . " L) en {$tank['branch_name']}",
                LOG_WARNING,
                ['tank_id'=>$tank['id'],'antes'=>$tank['current_liters'],'despues'=>$new_liters,'motivo'=>$reason,'documento'=>$docName]
            );
            $_SESSION['success'] = "Ajuste registrado. Litros actualizados a: {$new_liters} L.";
            header('Location: ' . APP_URL . '/modules/tanks/adjust.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            if ($docPath && file_exists(UPLOAD_DIR_ADJ . $docPath)) unlink(UPLOAD_DIR_ADJ . $docPath);
            logException(LOG_TANK, 'TANK_ADJUST_ERROR', $e);
            $_SESSION['error'] = 'Error al registrar el ajuste.';
        }
    }
}

$adjustments = $db->prepare("SELECT ta.*, u.name AS user_name FROM tank_adjustments ta JOIN users u ON ta.user_id=u.id WHERE ta.branch_id=? ORDER BY ta.adjusted_at DESC LIMIT 30");
$adjustments->execute([$user['branch_id']]);
$adjustments = $adjustments->fetchAll();

$pageTitle = 'Ajuste de Estanque';
require_once __DIR__ . '/../../includes/header.php';
?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;">
  <div>
    <?php if ($tank): ?>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">🛢 Estado Actual</span></div>
      <div class="card-body" style="text-align:center;">
        <div style="font-family:var(--font-display);font-size:52px;font-weight:700;color:var(--warning);"><?= number_format($tank['current_liters'],0) ?></div>
        <div class="text-muted">litros actuales</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">⚖ Ajustar Litros</span></div>
      <div class="card-body">
        <div style="background:var(--warning-bg);border:1px solid var(--warning);border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--warning);">
          ⚠ Esta acción queda registrada en el sistema con fecha, hora y usuario responsable.
        </div>
        <form method="POST" enctype="multipart/form-data">
          <div class="form-group" style="margin-bottom:14px;">
            <label>Nuevo valor de litros <span style="color:var(--danger)">*</span></label>
            <input type="number" name="new_liters" min="0" max="<?= TANK_MAX_LITERS ?>" step="0.01" required
              placeholder="Ej: 450" value="<?= htmlspecialchars($_POST['new_liters'] ?? '') ?>">
            <small class="text-muted">Entre 0 y <?= TANK_MAX_LITERS ?> litros</small>
          </div>
          <div class="form-group" style="margin-bottom:14px;">
            <label>Motivo del ajuste <span style="color:var(--danger)">*</span></label>
            <textarea name="reason" required placeholder="Descripción del motivo del ajuste..."><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
          </div>
          <div class="form-group" style="margin-bottom:18px;">
            <label>Documento de respaldo (opcional)</label>
            <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"
              style="padding:8px;cursor:pointer;">
            <small class="text-muted">PDF, imagen o documento — máx. 10 MB</small>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;"
            onclick="return confirm('¿Confirmas el ajuste del estanque? Esta acción quedará registrada.')">
            Confirmar Ajuste
          </button>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="card-body text-muted">No hay estanque configurado para tu sucursal.</div></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">Historial de Ajustes</span></div>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr><th>Fecha y Hora</th><th>Antes</th><th>Después</th><th>Diferencia</th><th>Usuario</th><th>Motivo</th><th>Respaldo</th></tr>
        </thead>
        <tbody>
          <?php foreach ($adjustments as $a): ?>
          <?php $diff = $a['liters_after'] - $a['liters_before']; ?>
          <tr>
            <td style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($a['adjusted_at'])) ?></td>
            <td class="text-muted"><?= number_format($a['liters_before'],0) ?> L</td>
            <td><?= number_format($a['liters_after'],0) ?> L</td>
            <td style="color:<?= $diff >= 0 ? '#3dc977' : '#f07070' ?>;font-weight:600;">
              <?= ($diff >= 0 ? '+' : '') . number_format($diff,0) ?> L
            </td>
            <td><?= htmlspecialchars($a['user_name']) ?></td>
            <td class="text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($a['reason']) ?></td>
            <td>
              <?php if ($a['document_path']): ?>
                <a href="<?= APP_URL ?>/modules/tanks/download.php?type=adjust&id=<?= $a['id'] ?>"
                   class="btn btn-secondary btn-sm" title="<?= htmlspecialchars($a['document_name']) ?>">
                  📎 <?= htmlspecialchars(substr($a['document_name'], 0, 15)) . (strlen($a['document_name']) > 15 ? '…' : '') ?>
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($adjustments)): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px;">Sin ajustes registrados</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
