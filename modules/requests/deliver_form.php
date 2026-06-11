<?php
require_once __DIR__ . '/../../includes/auth.php';
requireProfile('cargador','administrador');
require_once __DIR__ . '/../../includes/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$db   = getDB();
$user = currentUser();

$requestId = (int)($_GET['id'] ?? 0);

// Cargar solicitud completa
$reqStmt = $db->prepare("
    SELECT fr.*, b.name AS branch_name, b.id AS branch_id_val,
           COALESCE(m.name,'—') AS machine_name, COALESCE(m.code,'—') AS machine_code,
           u.name AS requester_name, u.email AS requester_email,
           u2.name AS approver_name
    FROM fuel_requests fr
    JOIN branches b ON fr.branch_id = b.id
    LEFT JOIN machines m ON fr.machine_id = m.id
    JOIN users u ON fr.requester_id = u.id
    LEFT JOIN users u2 ON fr.delivered_by = u2.id
    WHERE fr.id = ? AND fr.status = 'aprobado'
");
$reqStmt->execute([$requestId]);
$req = $reqStmt->fetch();

if (!$req) {
    $_SESSION['error'] = 'Solicitud no encontrada o no está en estado aprobado.';
    header('Location: ' . APP_URL . '/modules/requests/pending_delivery.php');
    exit;
}

// Estanque actual = surtidor inicial
$tankStmt = $db->prepare("SELECT * FROM tanks WHERE branch_id=?");
$tankStmt->execute([$req['branch_id']]);
$tank = $tankStmt->fetch();
$meterStart = $tank ? (float)$tank['current_liters'] : 0;

// Obtener aprobadores de esta solicitud para el correo
$approversStmt = $db->prepare("
    SELECT DISTINCT u.name, u.email
    FROM request_approvals ra
    JOIN users u ON ra.approver_id = u.id
    WHERE ra.request_id = ? AND ra.status = 'aprobado'
");
$approversStmt->execute([$requestId]);
$approvers = $approversStmt->fetchAll();

// ── POST: procesar entrega ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $litersDelivered    = (float)($_POST['liters_delivered'] ?? 0);
    $meterEnd           = (float)($_POST['meter_end'] ?? 0);
    $deliveryNotes      = trim($_POST['delivery_notes'] ?? '');
    $signatureRequester = trim($_POST['signature_requester'] ?? '');
    $signatureLoader    = trim($_POST['signature_loader'] ?? '');

    $errors = [];
    if ($litersDelivered <= 0)    $errors[] = 'Ingresa los litros entregados.';
    if ($meterEnd <= 0)           $errors[] = 'Ingresa la lectura del surtidor final.';
    if (!$signatureRequester)     $errors[] = 'Se requiere la firma del solicitante.';
    if (!$signatureLoader)        $errors[] = 'Se requiere la firma del cargador.';

    // El surtidor es un contador acumulativo que SIEMPRE SUBE (como un odómetro)
    // Surtidor final DEBE ser mayor al surtidor inicial
    // Litros entregados = Surtidor final − Surtidor inicial
    if ($meterEnd > 0 && $meterEnd <= $meterStart) {
        $errors[] = "El surtidor final ({$meterEnd}) debe ser mayor al surtidor inicial ({$meterStart}). El surtidor es un contador acumulativo que siempre aumenta.";
    }

    // Validar firma base64
    if ($signatureRequester && !preg_match('/^data:image\/(png|jpeg);base64,/', $signatureRequester)) {
        $errors[] = 'Formato de firma del solicitante inválido.';
    }
    if ($signatureLoader && !preg_match('/^data:image\/(png|jpeg);base64,/', $signatureLoader)) {
        $errors[] = 'Formato de firma del cargador inválido.';
    }

    // Procesar foto adjunta
    $photoPath = null;
    $photoName = null;
    if (!empty($_FILES['delivery_photo']['name'])) {
        $file     = $_FILES['delivery_photo'];
        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed  = ['jpg','jpeg','png','webp','heic'];
        if (!in_array($ext, $allowed)) {
            $errors[] = "Solo se permiten imágenes (JPG, PNG, WEBP). Recibido: {$ext}";
        } elseif ($file['size'] > 15 * 1024 * 1024) {
            $errors[] = 'La fotografía no puede superar 15 MB.';
        } else {
            $uploadDir = __DIR__ . '/../../uploads/delivery_photos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $safeName = 'entrega_' . $requestId . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
                $errors[] = 'Error al subir la fotografía.';
            } else {
                $photoPath = $safeName;
                $photoName = $origName;
            }
        }
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Surtidor es contador acumulativo: litros reales = final − inicial
            $litrosReales = round($meterEnd - $meterStart, 2);

            // El estanque se descuenta por los litros reales medidos
            $newTankLevel = round($tank['current_liters'] - $litrosReales, 2);

            // Actualizar solicitud con todos los datos de entrega
            $db->prepare("
                UPDATE fuel_requests SET
                    status = 'entregado',
                    liters_delivered = ?,
                    meter_start = ?,
                    meter_end = ?,
                    signature_requester = ?,
                    signature_loader = ?,
                    photo_path = ?,
                    photo_name = ?,
                    delivery_notes = ?,
                    delivered_by = ?,
                    delivered_at = NOW()
                WHERE id = ?
            ")->execute([
                $litrosReales, $meterStart, $meterEnd,
                $signatureRequester, $signatureLoader,
                $photoPath, $photoName, $deliveryNotes,
                $user['id'], $requestId
            ]);

            // Actualizar estanque
            $db->prepare("UPDATE tanks SET current_liters=?, updated_at=NOW() WHERE branch_id=?")
               ->execute([$newTankLevel, $req['branch_id']]);

            $db->commit();

            logEvent(LOG_DELIVERY, 'FUEL_DELIVERED',
                "Entrega #{$req['request_number']}: Surtidor {$meterStart}→{$meterEnd}. Litros reales: {$litrosReales} L. Estanque queda en {$newTankLevel} L",
                LOG_INFO,
                ['request_id'=>$requestId,'litros_reales'=>$litrosReales,'meter_start'=>$meterStart,'meter_end'=>$meterEnd,'tank_after'=>$newTankLevel]
            );

            // ── Generar PDF y enviar correos ──────────────────────
            $deliveryData = [
                'request'              => $req,
                'liters_delivered'     => $litrosReales,
                'liters_real'          => $litrosReales,
                'meter_start'          => $meterStart,
                'meter_end'            => $meterEnd,
                'delivery_notes'       => $deliveryNotes,
                'loader_name'          => $user['name'],
                'loader_email'         => $user['email'],
                'photo_path'           => $photoPath,
                'photo_name'           => $photoName,
                'approvers'            => $approvers,
                'new_tank_level'       => $newTankLevel,
                'delivered_at'         => date('d/m/Y H:i'),
                'signature_requester'  => $signatureRequester,
                'signature_loader'     => $signatureLoader,
            ];

            [$pdfPath, $deliveryData] = generateDeliveryPDF($deliveryData);
            sendDeliveryEmails($deliveryData, $pdfPath);

            if ($pdfPath && file_exists($pdfPath)) @unlink($pdfPath);

            $_SESSION['success'] = "Entrega registrada: {$litrosReales} L reales (surtidor {$meterStart}→{$meterEnd}). Estanque queda en {$newTankLevel} L.";
            header('Location: ' . APP_URL . '/modules/requests/pending_delivery.php');
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            if ($photoPath) @unlink(__DIR__ . '/../../uploads/delivery_photos/' . $photoPath);
            logException(LOG_DELIVERY, 'DELIVERY_ERROR', $e);
            $_SESSION['error'] = 'Error al registrar la entrega: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// ── Generar PDF de entrega con FPDF puro (sin librerías externas) ────────
// Usamos una implementación PDF mínima que no requiere librerías
function generateDeliveryPDF(array $d): array {
    $req          = $d['request'];
    $litrosReales = $d['liters_real'] ?? round($d['meter_end'] - $d['meter_start'], 2);
    $approversText = implode(', ', array_column($d['approvers'], 'name')) ?: '—';
    $machineName   = ($req['machine_name'] !== '—')
        ? $req['machine_name'] . ' (' . $req['machine_code'] . ')'
        : '—';

    // ── Preparar foto ──────────────────────────────────────────────────────
    $photoFile  = null;
    $photoExt   = null;
    if (!empty($d['photo_path'])) {
        $pf = __DIR__ . '/../../uploads/delivery_photos/' . $d['photo_path'];
        if (file_exists($pf)) {
            $photoFile = $pf;
            $photoExt  = strtolower(pathinfo($pf, PATHINFO_EXTENSION));
            // Convertir webp/heic a jpg usando GD si es necesario
            if (in_array($photoExt, ['webp','heic','gif'])) {
                $img = null;
                if ($photoExt === 'webp' && function_exists('imagecreatefromwebp')) $img = imagecreatefromwebp($pf);
                elseif ($photoExt === 'gif'  && function_exists('imagecreatefromgif'))  $img = imagecreatefromgif($pf);
                if ($img) {
                    $jpgPath = sys_get_temp_dir() . '/fc_photo_' . time() . '.jpg';
                    imagejpeg($img, $jpgPath, 90);
                    imagedestroy($img);
                    $photoFile = $jpgPath;
                    $photoExt  = 'jpg';
                }
            }
        }
    }

    // ── Preparar firmas (base64 PNG → tmp jpg) ─────────────────────────────
    function sig2tmp(string $b64): ?string {
        if (empty($b64)) return null;
        $data = preg_replace('/^data:image\/\w+;base64,/', '', $b64);
        $raw  = base64_decode($data);
        if (!$raw) return null;
        $img = @imagecreatefromstring($raw);
        if (!$img) return null;
        // Fondo blanco
        $w   = imagesx($img); $h = imagesy($img);
        $bg  = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $img, 0, 0, 0, 0, $w, $h);
        $tmp = sys_get_temp_dir() . '/fc_sig_' . uniqid() . '.jpg';
        imagejpeg($bg, $tmp, 90);
        imagedestroy($img); imagedestroy($bg);
        return $tmp;
    }

    $sigReqTmp    = sig2tmp($d['signature_requester'] ?? '');
    $sigLoaderTmp = sig2tmp($d['signature_loader']    ?? '');

    // ── Logo Latin Equipment (base64 embebido) ─────────────────────────────
    // PNG minimalista del logo LESA generado con GD
    function makeLogoImage(): ?string {
        if (!function_exists('imagecreatetruecolor')) return null;
        $w = 400; $h = 80;
        $img = imagecreatetruecolor($w, $h);
        $white  = imagecolorallocate($img, 255, 255, 255);
        $black  = imagecolorallocate($img, 26, 26, 26);
        $yellow = imagecolorallocate($img, 240, 184, 0);
        $green  = imagecolorallocate($img, 34, 139, 34);
        imagefill($img, 0, 0, $white);
        // Bloque verde con "L"
        imagefilledrectangle($img, 0, 5, 70, 70, $green);
        imagestring($img, 5, 22, 22, 'LATIN', $white);
        // Texto
        imagestring($img, 5, 80, 10, 'LATIN EQUIPMENT CHILE SPA', $black);
        imagestring($img, 3, 80, 32, 'DEPARTAMENTO ADMINISTRACION Y FINANZAS', $black);
        imagestring($img, 3, 80, 48, 'CONTROL INTERNO', $black);
        $tmp = sys_get_temp_dir() . '/fc_logo_' . time() . '.png';
        imagepng($img, $tmp);
        imagedestroy($img);
        return $tmp;
    }

    // ── Construir PDF con FPDF embebido (clase mínima) ─────────────────────
    // Incluimos una clase PDF mínima funcional sin dependencias
    $pdfOut = sys_get_temp_dir() . '/Entrega_' . preg_replace('/[^a-z0-9_\-]/i','_',$req['request_number']) . '_' . time() . '.pdf';

    ob_start();

    // ── Clase PDF mínima inline ──────────────────────────────────────────
    class MinPDF {
        private $pages = [];
        private $page  = -1;
        private $fonts = [];
        private $fontFamily = 'Helvetica';
        private $fontSize   = 10;
        private $fontStyle  = '';
        private $textColor  = [0,0,0];
        private $fillColor  = [255,255,255];
        private $drawColor  = [0,0,0];
        private $lineWidth  = 0.567;
        private $x = 10; private $y = 10;
        private $w = 210; private $h = 297;
        private $lMargin = 15; private $rMargin = 15;
        private $tMargin = 15; private $bMargin = 20;
        private $images  = [];
        private $imgData = [];
        private $objNum  = 0;
        private $offsets = [];
        private $buffer  = '';
        private $inHeader = false;

        public function __construct() {}

        public function AddPage() {
            $this->pages[] = '';
            $this->page++;
            $this->x = $this->lMargin;
            $this->y = $this->tMargin;
        }

        public function SetFont(string $family, string $style='', float $size=0) {
            if ($size > 0) $this->fontSize = $size;
            $this->fontStyle = strtoupper($style);
        }
        public function SetFontSize(float $size) { $this->fontSize = $size; }
        public function SetTextColor(int $r, int $g=0, int $b=0) { $this->textColor = [$r,$g,$b]; }
        public function SetFillColor(int $r, int $g=0, int $b=0) { $this->fillColor = [$r,$g,$b]; }
        public function SetDrawColor(int $r, int $g=0, int $b=0) { $this->drawColor = [$r,$g,$b]; }
        public function SetLineWidth(float $w) { $this->lineWidth = $w; }
        public function SetXY(float $x, float $y) { $this->x=$x; $this->y=$y; }
        public function SetX(float $x) { $this->x=$x; }
        public function SetY(float $y) { $this->y=$y; }
        public function GetX(): float { return $this->x; }
        public function GetY(): float { return $this->y; }
        public function GetPageWidth(): float { return $this->w; }

        private function _out(string $s) { $this->pages[$this->page] .= $s . "\n"; }
        private function _put(string $s) { $this->buffer .= $s . "\n"; }
        private function _newobj(): int { $this->objNum++; $this->offsets[$this->objNum] = strlen($this->buffer); $this->_put($this->objNum . ' 0 obj'); return $this->objNum; }
        private function _endobj() { $this->_put('endobj'); }

        private function _rgb(array $c): string { return round($c[0]/255,3).' '.round($c[1]/255,3).' '.round($c[2]/255,3); }
        private function _textesc(string $s): string { return str_replace(['\\','(',')'],['\\\\',' \\(','\\)'], $s); }
        private function _pt(float $mm): float { return $mm * 2.8346; }

        public function Cell(float $w, float $h=0, string $txt='', $border=0, int $ln=0, string $align='L', bool $fill=false) {
            $x = $this->x; $y = $this->y;
            $pw = ($w==0) ? ($this->w - $this->rMargin - $x) : $w;

            if ($fill) {
                $this->_out(sprintf('%.3f %.3f %.3f rg', $this->fillColor[0]/255, $this->fillColor[1]/255, $this->fillColor[2]/255));
                $this->_out(sprintf('%.3f %.3f %.3f %.3f re f', $this->_pt($x), $this->_pt($this->h - $y - $h), $this->_pt($pw), $this->_pt($h)));
            }
            if ($border) {
                $this->_out(sprintf('%.3f %.3f %.3f RG', $this->drawColor[0]/255, $this->drawColor[1]/255, $this->drawColor[2]/255));
                $this->_out(sprintf('%.3f w', $this->lineWidth));
                if ($border===1) {
                    $this->_out(sprintf('%.3f %.3f %.3f %.3f re S', $this->_pt($x), $this->_pt($this->h-$y-$h), $this->_pt($pw), $this->_pt($h)));
                }
                if (is_string($border)) {
                    if (strpos($border,'B')!==false) $this->_out(sprintf('%.3f %.3f m %.3f %.3f l S', $this->_pt($x), $this->_pt($this->h-$y-$h), $this->_pt($x+$pw), $this->_pt($this->h-$y-$h)));
                    if (strpos($border,'T')!==false) $this->_out(sprintf('%.3f %.3f m %.3f %.3f l S', $this->_pt($x), $this->_pt($this->h-$y), $this->_pt($x+$pw), $this->_pt($this->h-$y)));
                }
            }
            if ($txt !== '') {
                $this->_out(sprintf('%.3f %.3f %.3f rg', $this->textColor[0]/255, $this->textColor[1]/255, $this->textColor[2]/255));
                $fs = $this->_pt($this->fontSize * 0.352778);
                $bold = (strpos($this->fontStyle,'B')!==false) ? ',Bold' : '';
                $this->_out("BT");
                $this->_out("/F1{$bold} {$fs} Tf");
                $tw = ($align === 'C') ? ($x + ($pw - $this->stringWidth($txt))/2) : (($align==='R') ? ($x + $pw - $this->stringWidth($txt) - 1) : ($x + 1));
                $this->_out(sprintf('%.3f %.3f Td (%s) Tj', $this->_pt($tw), $this->_pt($this->h - $y - $h + ($h - $this->fontSize*0.352778)/2), $this->_textesc($txt)));
                $this->_out("ET");
            }
            if ($ln) $this->y += $h;
            else $this->x += $pw;
        }

        public function MultiCell(float $w, float $h, string $txt, $border=0, string $align='L', bool $fill=false) {
            $lines = explode("\n", wordwrap($txt, (int)($w/2.2), "\n", true));
            foreach ($lines as $i => $line) {
                $ln = ($i < count($lines)-1) ? 1 : 0;
                $this->Cell($w, $h, trim($line), ($i===count($lines)-1 && $border) ? $border : 0, $ln, $align, $fill && $i===0);
            }
            $this->y += $h;
        }

        public function Ln(float $h=5) { $this->x=$this->lMargin; $this->y+=$h; }

        private function stringWidth(string $s): float { return strlen($s) * $this->fontSize * 0.352778 * 0.5; }

        public function Line(float $x1, float $y1, float $x2, float $y2) {
            $this->_out(sprintf('%.3f %.3f %.3f RG', $this->drawColor[0]/255, $this->drawColor[1]/255, $this->drawColor[2]/255));
            $this->_out(sprintf('%.3f w', $this->lineWidth));
            $this->_out(sprintf('%.3f %.3f m %.3f %.3f l S', $this->_pt($x1), $this->_pt($this->h-$y1), $this->_pt($x2), $this->_pt($this->h-$y2)));
        }

        public function Rect(float $x, float $y, float $w, float $h, string $style='') {
            $op = ($style==='F') ? 'f' : (($style==='FD'||$style==='DF') ? 'B' : 'S');
            if ($style==='F'||$style==='FD'||$style==='DF') {
                $this->_out(sprintf('%.3f %.3f %.3f rg', $this->fillColor[0]/255, $this->fillColor[1]/255, $this->fillColor[2]/255));
            }
            $this->_out(sprintf('%.3f %.3f %.3f RG', $this->drawColor[0]/255, $this->drawColor[1]/255, $this->drawColor[2]/255));
            $this->_out(sprintf('%.3f w', $this->lineWidth));
            $this->_out(sprintf('%.3f %.3f %.3f %.3f re %s', $this->_pt($x), $this->_pt($this->h-$y-$h), $this->_pt($w), $this->_pt($h), $op));
        }

        public function Image(string $file, float $x, float $y, float $w=0, float $h=0) {
            if (!isset($this->images[$file])) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $data = file_get_contents($file);
                if (!$data) return;
                $info = @getimagesize($file);
                if (!$info) return;
                $iw = $info[0]; $ih = $info[1];
                if ($w==0 && $h==0) { $w=96; $h=$ih*$w/$iw; }
                elseif ($w==0) { $w=$iw*$h/$ih; }
                elseif ($h==0) { $h=$ih*$w/$iw; }
                $type = ($ext==='png') ? 'PNG' : 'JPEG';
                $this->images[$file] = ['w'=>$iw,'h'=>$ih,'type'=>$type,'data'=>$data,'idx'=>count($this->images)+1,'dw'=>$w,'dh'=>$h];
            }
            $img  = $this->images[$file];
            $dw   = ($w==0) ? $img['dw'] : $w;
            $dh   = ($h==0) ? $img['dh'] : $h;
            $this->_out(sprintf('q %.3f 0 0 %.3f %.3f %.3f cm /I%d Do Q',
                $this->_pt($dw), $this->_pt($dh),
                $this->_pt($x), $this->_pt($this->h - $y - $dh),
                $img['idx']));
        }

        public function Output(string $dest='', string $name=''): string {
            // Build PDF
            $this->_put('%PDF-1.4');

            // Font object
            $fontObj = $this->_newobj();
            $this->_put('<<');
            $this->_put('/Type /Font');
            $this->_put('/Subtype /Type1');
            $this->_put('/BaseFont /Helvetica');
            $this->_put('/Encoding /WinAnsiEncoding');
            $this->_put('>>');
            $this->_endobj();

            $fontBoldObj = $this->_newobj();
            $this->_put('<<');
            $this->_put('/Type /Font');
            $this->_put('/Subtype /Type1');
            $this->_put('/BaseFont /Helvetica-Bold');
            $this->_put('/Encoding /WinAnsiEncoding');
            $this->_put('>>');
            $this->_endobj();

            // Image objects
            $imgObjs = [];
            foreach ($this->images as $key => $img) {
                $obj = $this->_newobj();
                $imgObjs[$key] = $obj;
                $this->_put('<<');
                $this->_put('/Type /XObject');
                $this->_put('/Subtype /Image');
                $this->_put('/Width ' . $img['w']);
                $this->_put('/Height ' . $img['h']);
                $this->_put('/ColorSpace /DeviceRGB');
                $this->_put('/BitsPerComponent 8');
                $cs = '';
                if ($img['type']==='PNG') {
                    $this->_put('/Filter /FlateDecode');
                    // Extract raw PNG image data using GD
                    $gdImg = @imagecreatefromstring($img['data']);
                    if ($gdImg) {
                        ob_start();
                        imagejpeg($gdImg, null, 90);
                        $jpgData = ob_get_clean();
                        imagedestroy($gdImg);
                        $this->_put('/Filter /DCTDecode');
                        $this->_put('/Length ' . strlen($jpgData));
                        $this->_put('>>');
                        $this->_put('stream');
                        $this->buffer .= $jpgData . "\n";
                        $this->_put('endstream');
                        $this->_endobj();
                        continue;
                    }
                }
                $this->_put('/Filter /DCTDecode');
                $this->_put('/Length ' . strlen($img['data']));
                $this->_put('>>');
                $this->_put('stream');
                $this->buffer .= $img['data'] . "\n";
                $this->_put('endstream');
                $this->_endobj();
            }

            // Page objects
            $pageObjs = [];
            foreach ($this->pages as $i => $content) {
                // Resources
                $resObj = $this->_newobj();
                $imgRes = '';
                foreach ($this->images as $key => $img) {
                    $imgRes .= '/I' . $img['idx'] . ' ' . $imgObjs[$key] . ' 0 R ';
                }
                $this->_put('<<');
                $this->_put('/ProcSet [/PDF /Text /ImageB /ImageC]');
                $this->_put('/Font <</F1 ' . $fontObj . ' 0 R /F1,Bold ' . $fontBoldObj . ' 0 R>>');
                if ($imgRes) $this->_put('/XObject <<' . $imgRes . '>>');
                $this->_put('>>');
                $this->_endobj();

                // Content stream
                $pW = $this->_pt($this->w);
                $pH = $this->_pt($this->h);
                $streamObj = $this->_newobj();
                $this->_put('<<');
                $this->_put('/Length ' . strlen($content));
                $this->_put('>>');
                $this->_put('stream');
                $this->buffer .= $content;
                $this->_put('endstream');
                $this->_endobj();

                // Page dict
                $pageObj = $this->_newobj();
                $pageObjs[] = $pageObj;
                $this->_put('<<');
                $this->_put('/Type /Page');
                $this->_put('/MediaBox [0 0 ' . round($pW) . ' ' . round($pH) . ']');
                $this->_put('/Resources ' . $resObj . ' 0 R');
                $this->_put('/Contents ' . $streamObj . ' 0 R');
                $this->_put('>>');
                $this->_endobj();
            }

            // Pages dict
            $pagesObj = $this->_newobj();
            $kids = implode(' 0 R ', $pageObjs) . ' 0 R';
            $pW = $this->_pt($this->w); $pH = $this->_pt($this->h);
            $this->_put('<<');
            $this->_put('/Type /Pages');
            $this->_put('/Kids [' . $kids . ']');
            $this->_put('/Count ' . count($this->pages));
            $this->_put('/MediaBox [0 0 ' . round($pW) . ' ' . round($pH) . ']');
            $this->_put('>>');
            $this->_endobj();

            // Catalog
            $catalogObj = $this->_newobj();
            $this->_put('<<');
            $this->_put('/Type /Catalog');
            $this->_put('/Pages ' . $pagesObj . ' 0 R');
            $this->_put('>>');
            $this->_endobj();

            // Cross-ref table
            $xrefOffset = strlen($this->buffer);
            $this->_put('xref');
            $this->_put('0 ' . ($this->objNum + 1));
            $this->_put('0000000000 65535 f ');
            foreach ($this->offsets as $off) {
                $this->_put(sprintf('%010d 00000 n ', $off));
            }
            $this->_put('trailer');
            $this->_put('<</Size ' . ($this->objNum+1) . ' /Root ' . $catalogObj . ' 0 R>>');
            $this->_put('startxref');
            $this->_put($xrefOffset);
            $this->_put('%%EOF');

            if ($dest === 'F') {
                file_put_contents($name, $this->buffer);
                return '';
            }
            return $this->buffer;
        }
    }
    // ── End MinPDF class ──────────────────────────────────────────────────

    // ── Build the actual PDF document ─────────────────────────────────────
    $pdf = new MinPDF();
    $pdf->AddPage();

    $pageW  = $pdf->GetPageWidth(); // 210
    $lm = 15; $rm = 15;
    $cw = $pageW - $lm - $rm; // content width

    // ── HEADER: Logo area + date/solicitud ────────────────────────────────
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);

    // Header box
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetXY($lm, 12);

    // Company name
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(26, 26, 26);
    $pdf->Cell($cw * 0.6, 6, 'LATIN EQUIPMENT CHILE SPA', 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX($lm);
    $pdf->Cell($cw * 0.6, 4, 'DEPARTAMENTO ADMINISTRACION Y FINANZAS / CONTROL INTERNO', 0, 0, 'L');

    // Date and solicitud number (top right)
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(26, 26, 26);
    $pdf->SetXY($lm + $cw * 0.6, 12);
    $pdf->Cell($cw * 0.4, 5, 'Fecha solicitud: ' . date('d-m-Y', strtotime($req['requested_at'])), 0, 1, 'R');
    $pdf->SetXY($lm + $cw * 0.6, 17);
    $pdf->Cell($cw * 0.4, 5, 'Solicitud: N° ' . ltrim(strrchr($req['request_number'], '-'), '-'), 0, 0, 'R');

    // Horizontal line under header
    $pdf->SetY(24);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Line($lm, 24, $lm + $cw, 24);

    // ── PARTICIPANTES ─────────────────────────────────────────────────────
    $pdf->SetY(28);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetDrawColor(230, 230, 230);
    $pdf->SetX($lm);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(26, 26, 26);
    $pdf->Cell($cw, 6, 'Participantes', 1, 1, 'C', true);

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(26, 26, 26);
    $pdf->SetX($lm);
    $pdf->Cell(30, 6, 'Solicitante:', 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($cw - 30, 6, $req['requester_name'], 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetX($lm);
    $pdf->Cell(30, 6, 'Aprobador:', 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($cw - 30, 6, $approversText, 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetX($lm);
    $pdf->Cell(30, 6, 'Abastecedor:', 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($cw - 30, 6, $d['loader_name'], 0, 1, 'L');

    // ── DATOS DEL SUMINISTRO + FOTO ───────────────────────────────────────
    $pdf->Ln(2);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetX($lm);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(26, 26, 26);
    $pdf->Cell($cw, 6, 'Datos del Suministro', 1, 1, 'C', true);

    $dataY = $pdf->GetY();
    $leftW = $cw * 0.5;

    // Columna izquierda: datos
    $rows = [
        ['Litros solicitados:', number_format($req['liters_requested'], 0) . ' L'],
        ['Surtidor inicial:',   number_format($d['meter_start'], 0)],
        ['Surtidor final:',     number_format($d['meter_end'], 0)],
        ['Litros cargados:',    number_format($litrosReales, 0) . ' L'],
        ['Equipo Abastecido:',  $machineName],
    ];
    if (!empty($req['service_call_number'])) {
        array_splice($rows, 0, 0, [['N° Llamada:', $req['service_call_number']]]);
    }
    if (!empty($d['delivery_notes'])) {
        $rows[] = ['Observaciones:', $d['delivery_notes']];
    }

    foreach ($rows as $row) {
        $pdf->SetX($lm);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($leftW * 0.5, 6, $row[0], 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($leftW * 0.5, 6, $row[1], 0, 1, 'L');
    }

    // Columna derecha: foto
    if ($photoFile) {
        $info = @getimagesize($photoFile);
        if ($info) {
            $iw = $info[0]; $ih = $info[1];
            $pw = $cw * 0.44;
            $ph = $ih * $pw / $iw;
            if ($ph > 55) { $ph = 55; $pw = $iw * $ph / $ih; }
            $photoX = $lm + $leftW + 4;
            $photoY = $dataY;

            // Label
            $pdf->SetXY($photoX, $photoY);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetTextColor(26, 26, 26);
            $pdf->Cell($pw, 5, 'Evidencia Fotografica', 0, 1, 'C');
            $pdf->SetXY($photoX, $photoY + 6);
            $pdf->SetDrawColor(180, 180, 180);
            $pdf->SetLineWidth(0.3);
            $pdf->Rect($photoX, $photoY + 6, $pw, $ph, 'D');
            $pdf->Image($photoFile, $photoX + 0.5, $photoY + 6.5, $pw - 1, $ph - 1);
        }
    }

    // ── FIRMAS ────────────────────────────────────────────────────────────
    $sigY = max($pdf->GetY() + 10, $dataY + 65);
    $pdf->SetY($sigY);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetX($lm);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($cw, 6, 'Firmas', 1, 1, 'C', true);

    $firmaY = $pdf->GetY() + 4;
    $halfW  = $cw / 2;
    $sigW   = 55; $sigH = 25;

    // Firma solicitante
    if ($sigReqTmp && file_exists($sigReqTmp)) {
        $pdf->Image($sigReqTmp, $lm + ($halfW - $sigW)/2, $firmaY, $sigW, $sigH);
        @unlink($sigReqTmp);
    }
    // Firma cargador
    if ($sigLoaderTmp && file_exists($sigLoaderTmp)) {
        $pdf->Image($sigLoaderTmp, $lm + $halfW + ($halfW - $sigW)/2, $firmaY, $sigW, $sigH);
        @unlink($sigLoaderTmp);
    }

    $lineY = $firmaY + $sigH + 3;
    $pdf->SetDrawColor(80, 80, 80);
    $pdf->SetLineWidth(0.4);
    $pdf->Line($lm + 5,            $lineY, $lm + $halfW - 5, $lineY);
    $pdf->Line($lm + $halfW + 5,   $lineY, $lm + $cw - 5,   $lineY);

    $pdf->SetY($lineY + 2);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(26, 26, 26);
    $pdf->SetX($lm);
    $pdf->Cell($halfW, 5, 'Solicitante', 0, 0, 'C');
    $pdf->Cell($halfW, 5, 'Abastecedor', 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetX($lm);
    $pdf->Cell($halfW, 5, $req['requester_name'], 0, 0, 'C');
    $pdf->Cell($halfW, 5, $d['loader_name'], 0, 1, 'C');

    // Save PDF
    $pdf->Output('F', $pdfOut);
    ob_end_clean();

    // Cleanup temp photo
    if ($photoFile && $photoFile !== ($d['photo_path'] ? __DIR__ . '/../../uploads/delivery_photos/' . $d['photo_path'] : '')) {
        @unlink($photoFile);
    }

    if (file_exists($pdfOut) && filesize($pdfOut) > 100) {
        return [$pdfOut, $d];
    }

    // Fallback HTML si GD no está disponible
    $htmlPath = str_replace('.pdf', '.html', $pdfOut);
    file_put_contents($htmlPath, '<html><body>Comprobante ' . htmlspecialchars($req['request_number']) . '</body></html>');
    return [$htmlPath, $d];
}
// ── Enviar correos con adjunto ────────────────────────────────────────────
function sendDeliveryEmails(array $d, ?string $attachPath): void {
    global $_phpmailerLoaded;
    if (!$_phpmailerLoaded) {
        error_log('[FuelControl] PHPMailer no disponible — correos de entrega no enviados.');
        return;
    }

    $req         = $d['request'];
    $subject     = "FuelControl — Comprobante Entrega #{$req['request_number']}";
    $ext         = ($attachPath && pathinfo($attachPath, PATHINFO_EXTENSION) === 'pdf') ? 'pdf' : 'html';
    $attachName  = "Comprobante_Entrega_{$req['request_number']}.{$ext}";

    // Construir lista de destinatarios únicos
    $recipients = [];
    // 1. Solicitante
    $recipients[$req['requester_email']] = $req['requester_name'];
    // 2. Cargador (quien entrega)
    $recipients[$d['loader_email']] = $d['loader_name'];
    // 3. Aprobadores del flujo
    foreach ($d['approvers'] as $ap) {
        $recipients[$ap['email']] = $ap['name'];
    }
    // 4. Correos fijos corporativos
    $recipients['intranet@latinequip.com']       = 'Intranet Latin Equipment';
    $recipients['flavio.pereira@latinequip.com'] = 'Flavio Pereira';

    $machineName = $req['machine_name'] !== '—' ? $req['machine_name'] . ' (' . $req['machine_code'] . ')' : '—';
    $approversText = implode(', ', array_column($d['approvers'], 'name')) ?: '—';

    $htmlBody = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f0f0f0;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f0f0;padding:30px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;'>
  <tr><td style='background:#111;padding:22px 30px;border-radius:8px 8px 0 0;border-top:4px solid #F0B800;text-align:center;'>
    <span style='font-size:30px;'>⛽</span>
    <h1 style='color:#F0B800;margin:6px 0 2px;font-size:22px;letter-spacing:2px;'>FUELCONTROL</h1>
    <p style='color:#666;margin:0;font-size:11px;'>Latin Equipment Chile</p>
  </td></tr>
  <tr><td style='background:#fff;padding:28px 30px;border:1px solid #e0e0e0;border-top:none;'>
    <p style='font-size:15px;color:#222;margin-top:0;'>Se ha completado la siguiente entrega de combustible:</p>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;margin:16px 0;'>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;width:38%;border-bottom:1px solid #eee;'>N° Solicitud</td><td style='padding:8px 12px;font-size:14px;border-bottom:1px solid #eee;'><strong>{$req['request_number']}</strong></td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Tipo</td><td style='padding:8px 12px;border-bottom:1px solid #eee;text-transform:capitalize;'>{$req['request_type']}</td></tr>"
      . (!empty($req['service_call_number']) ? "<tr><td style='padding:8px 12px;background:#fff8e1;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>N° Llamada</td><td style='padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;color:#856404;'>{$req['service_call_number']}</td></tr>" : '') . "
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Solicitante</td><td style='padding:8px 12px;border-bottom:1px solid #eee;'>{$req['requester_name']}</td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Máquina</td><td style='padding:8px 12px;border-bottom:1px solid #eee;'>{$machineName}</td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Litros solicitados</td><td style='padding:8px 12px;border-bottom:1px solid #eee;'>" . number_format($req['liters_requested'],2) . " L</td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Surtidor inicial</td><td style='padding:8px 12px;border-bottom:1px solid #eee;'>" . number_format($d['meter_start'],2) . "</td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Surtidor final</td><td style='padding:8px 12px;border-bottom:1px solid #eee;'>" . number_format($d['meter_end'],2) . " L</td></tr>
      <tr><td style='padding:8px 12px;background:#fffbea;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;color:#856404;'>Litros reales entregados<br><small style='font-weight:normal;color:#aaa;'>(Surt. final − Surt. inicial)</small></td><td style='padding:8px 12px;border-bottom:1px solid #eee;font-size:18px;font-weight:bold;color:#22a355;'>" . number_format($d['liters_real'],2) . " L</td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Entregado por</td><td style='padding:8px 12px;border-bottom:1px solid #eee;'>{$d['loader_name']}</td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;border-bottom:1px solid #eee;'>Aprobado por</td><td style='padding:8px 12px;border-bottom:1px solid #eee;'>{$approversText}</td></tr>
      <tr><td style='padding:8px 12px;background:#f4f4f4;font-weight:bold;font-size:13px;'>Fecha entrega</td><td style='padding:8px 12px;'>{$d['delivered_at']}</td></tr>
    </table>"
    // Nota de foto adjunta en el correo
    . (!empty($d['photo_path']) ? "
    <div style='background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;margin:16px 0;font-size:13px;color:#555;'>
      📎 <strong>Fotografía de evidencia adjunta</strong> — " . htmlspecialchars($d['photo_name'] ?? 'evidencia.jpg') . "
    </div>" : "")
    // Firmas en el correo
    . (!empty($d['signature_requester']) || !empty($d['signature_loader']) ? "
    <p style='font-size:13px;font-weight:bold;color:#333;border-left:4px solid #F0B800;padding-left:10px;margin:20px 0 10px;text-transform:uppercase;letter-spacing:.5px;'>Firmas</p>
    <table width='100%' cellpadding='0' cellspacing='0'>
    <tr>
      " . (!empty($d['signature_requester']) ? "<td width='50%' style='text-align:center;padding:10px;'>
        <img src='{$d['signature_requester']}' style='max-width:200px;max-height:90px;border:1px solid #ccc;border-radius:4px;display:block;margin:0 auto;'>
        <p style='margin:6px 0 0;font-size:11px;color:#555;border-top:1px solid #ddd;padding-top:6px;'>Firma Solicitante<br><strong>{$req['requester_name']}</strong></p>
      </td>" : "<td width='50%'></td>") . "
      " . (!empty($d['signature_loader']) ? "<td width='50%' style='text-align:center;padding:10px;'>
        <img src='{$d['signature_loader']}' style='max-width:200px;max-height:90px;border:1px solid #ccc;border-radius:4px;display:block;margin:0 auto;'>
        <p style='margin:6px 0 0;font-size:11px;color:#555;border-top:1px solid #ddd;padding-top:6px;'>Firma Cargador<br><strong>{$d['loader_name']}</strong></p>
      </td>" : "<td width='50%'></td>") . "
    </tr></table>" : "")
    . "
    <p style='font-size:13px;color:#555;margin-top:16px;'>Se adjunta el comprobante completo con todos los detalles.</p>
  </td></tr>
  <tr><td style='background:#f8f8f8;padding:14px 30px;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;text-align:center;'>
    <p style='color:#aaa;font-size:11px;margin:0;'>Mensaje automático — FuelControl — Latin Equipment Chile S.A. &copy; " . date('Y') . "</p>
  </td></tr>
</table></td></tr></table></body></html>";

    foreach ($recipients as $email => $name) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USER;
            $mail->Password   = MAIL_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = MAIL_PORT;
            $mail->SMTPOptions = ['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->addAddress($email, $name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = "Entrega de combustible #{$req['request_number']} completada. {$d['liters_delivered']} L entregados por {$d['loader_name']}.";

            // Adjunto 1: Comprobante HTML
            if ($attachPath && file_exists($attachPath)) {
                $mail->addAttachment($attachPath, $attachName);
            }

            // Adjunto 2: Fotografía de evidencia como archivo separado
            if (!empty($d['photo_path'])) {
                $photoFile = __DIR__ . '/../../uploads/delivery_photos/' . $d['photo_path'];
                if (file_exists($photoFile)) {
                    $photoOrigName = !empty($d['photo_name']) ? $d['photo_name'] : 'fotografia_evidencia.' . pathinfo($photoFile, PATHINFO_EXTENSION);
                    $mail->addAttachment($photoFile, 'Evidencia_' . $req['request_number'] . '_' . $photoOrigName);
                }
            }

            $mail->send();
            error_log("[FuelControl Entrega] Correo OK → {$email}");
        } catch (PHPMailerException $e) {
            error_log("[FuelControl Entrega] ERROR → {$email} | " . ($mail->ErrorInfo ?? $e->getMessage()));
        } catch (\Exception $e) {
            error_log("[FuelControl Entrega] EXC → {$email} | " . $e->getMessage());
        }
    }
}

$pageTitle = 'Registrar Entrega — ' . $req['request_number'];
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.sig-canvas-wrap { border:2px solid var(--border); border-radius:var(--radius); background:#fff; position:relative; }
.sig-canvas-wrap canvas { display:block; touch-action:none; cursor:crosshair; border-radius:var(--radius); }
.sig-actions { display:flex; gap:8px; margin-top:8px; }
.sig-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--text-muted); margin-bottom:6px; }
.step-indicator { display:flex; gap:0; margin-bottom:24px; overflow-x:auto; }
.step-item { flex:1; text-align:center; padding:10px 8px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-dim); border-bottom:3px solid var(--border); white-space:nowrap; min-width:80px; }
.step-item.active { color:var(--accent); border-bottom-color:var(--accent); }
.step-item.done   { color:#3dc977; border-bottom-color:#3dc977; }
</style>

<!-- Info de la solicitud -->
<div class="card mb-16" style="border-top:3px solid var(--accent);">
  <div class="card-header">
    <span class="card-title">📋 Solicitud <?= htmlspecialchars($req['request_number']) ?></span>
    <span class="badge badge-approved">Aprobada — Lista para entregar</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;">
      <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;">Solicitante</div><div style="font-weight:600;"><?= htmlspecialchars($req['requester_name']) ?></div></div>
      <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;">Tipo</div><div style="font-weight:600;text-transform:capitalize;"><?= htmlspecialchars($req['request_type']) ?><?= $req['service_call_number'] ? ' — N° '.$req['service_call_number'] : '' ?></div></div>
      <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;">Combustible</div><div style="font-weight:600;"><?= htmlspecialchars($req['fuel_type']) ?></div></div>
      <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;">Litros aprobados</div><div style="font-family:var(--font-display);font-size:24px;font-weight:700;color:var(--accent);"><?= number_format($req['liters_requested'],0) ?> L</div></div>
      <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;">Sucursal</div><div style="font-weight:600;"><?= htmlspecialchars($req['branch_name']) ?></div></div>
      <div><div class="text-muted" style="font-size:10px;text-transform:uppercase;">Estanque actual</div><div style="font-family:var(--font-display);font-size:20px;font-weight:700;color:var(--accent);"><?= number_format($meterStart,0) ?> L</div></div>
    </div>
  </div>
</div>

<form method="POST" enctype="multipart/form-data" id="deliveryForm">

  <!-- Datos de entrega -->
  <div class="card mb-16">
    <div class="card-header"><span class="card-title">📊 Datos de Entrega</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Litros a registrar <span style="color:var(--danger)">*</span></label>
          <input type="number" name="liters_delivered" id="liters_delivered"
            min="0.01" max="<?= $req['liters_requested'] ?>" step="0.01"
            value="<?= $req['liters_requested'] ?>" required>
          <small class="text-muted">Aprobados: <?= number_format($req['liters_requested'],0) ?> L</small>
        </div>
        <div class="form-group">
          <label>Surtidor inicial (automático)</label>
          <input type="text" value="<?= number_format($meterStart,2) ?> L" disabled
            style="background:var(--bg-panel);color:var(--text-muted);">
          <input type="hidden" name="meter_start" value="<?= $meterStart ?>">
          <small class="text-muted">Nivel actual del estanque — se registra automáticamente</small>
        </div>
        <div class="form-group">
          <label>Surtidor final <span style="color:var(--danger)">*</span></label>
          <input type="number" name="meter_end" id="meter_end"
            min="<?= $meterStart + 0.01 ?>" step="0.01"
            placeholder="Debe ser mayor a <?= number_format($meterStart,2) ?>" required
            oninput="updateLitrosReales()">
          <small class="text-muted">Valor mayor a <?= number_format($meterStart,2) ?> — el surtidor siempre sube</small>
        </div>
        <div class="form-group">
          <label>Litros reales entregados</label>
          <div id="litros_reales_display" style="background:var(--bg-panel);border:1px solid var(--border);border-radius:var(--radius);padding:11px 14px;font-family:var(--font-display);font-size:22px;font-weight:700;color:var(--accent);">
            — L
          </div>
          <small class="text-muted">Calculado: Surtidor inicial − Surtidor final</small>
        </div>
        <div class="form-group full">
          <label>Fotografía de evidencia</label>
          <input type="file" name="delivery_photo" accept="image/*" capture="environment"
            style="padding:8px;cursor:pointer;">
          <small class="text-muted">JPG, PNG o WEBP — máx. 15 MB. Puedes usar la cámara del celular.</small>
        </div>
        <div class="form-group full">
          <label>Observaciones</label>
          <textarea name="delivery_notes" placeholder="Notas adicionales sobre la entrega..."></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- Firma solicitante -->
  <div class="card mb-16">
    <div class="card-header"><span class="card-title">✍ Firma del Solicitante</span></div>
    <div class="card-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">
        <strong><?= htmlspecialchars($req['requester_name']) ?></strong> debe firmar a continuación:
      </p>
      <div class="sig-canvas-wrap">
        <canvas id="sigRequester" style="width:100%;height:200px;display:block;touch-action:none;cursor:crosshair;border-radius:var(--radius);"></canvas>
      </div>
      <div class="sig-actions" style="margin-top:10px;">
        <button type="button" onclick="clearCanvas('sigRequester','signature_requester')" class="btn btn-secondary btn-sm">🗑 Limpiar firma</button>
        <span id="sig_req_status" style="font-size:12px;color:var(--text-muted);align-self:center;margin-left:8px;">Sin firma</span>
      </div>
      <input type="hidden" name="signature_requester" id="signature_requester">
    </div>
  </div>

  <!-- Firma cargador -->
  <div class="card mb-16">
    <div class="card-header"><span class="card-title">✍ Firma del Cargador</span></div>
    <div class="card-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">
        <strong><?= htmlspecialchars($user['name']) ?></strong> debe firmar a continuación:
      </p>
      <div class="sig-canvas-wrap">
        <canvas id="sigLoader" style="width:100%;height:200px;display:block;touch-action:none;cursor:crosshair;border-radius:var(--radius);"></canvas>
      </div>
      <div class="sig-actions" style="margin-top:10px;">
        <button type="button" onclick="clearCanvas('sigLoader','signature_loader')" class="btn btn-secondary btn-sm">🗑 Limpiar firma</button>
        <span id="sig_load_status" style="font-size:12px;color:var(--text-muted);align-self:center;margin-left:8px;">Sin firma</span>
      </div>
      <input type="hidden" name="signature_loader" id="signature_loader">
    </div>
  </div>

  <div style="display:flex;gap:12px;margin-top:20px;">
    <button type="submit" class="btn btn-success" style="flex:1;justify-content:center;font-size:15px;"
      onclick="return validateAndSubmit(event)">
      ✅ Confirmar y Registrar Entrega
    </button>
    <a href="<?= APP_URL ?>/modules/requests/pending_delivery.php" class="btn btn-secondary">Cancelar</a>
  </div>

</form>

<script>
const meterStartVal = <?= $meterStart ?>;

function updateLitrosReales() {
  const meterEnd = parseFloat(document.getElementById('meter_end').value) || 0;
  const display  = document.getElementById('litros_reales_display');
  const input    = document.getElementById('meter_end');

  if (meterEnd <= 0) {
    display.textContent = '— L';
    display.style.color = 'var(--accent)';
    input.style.borderColor = '';
    input.style.boxShadow   = '';
    return;
  }
  if (meterEnd <= meterStartVal) {
    display.textContent = '⚠ Debe ser mayor a ' + meterStartVal.toFixed(2);
    display.style.color = 'var(--danger)';
    input.style.borderColor = 'var(--danger)';
    input.style.boxShadow   = '0 0 0 3px rgba(224,61,61,0.15)';
    return;
  }
  // Correcto: litros = surtidor final − surtidor inicial
  input.style.borderColor = 'var(--accent)';
  input.style.boxShadow   = '0 0 0 3px var(--accent-glow)';
  const litros = meterEnd - meterStartVal;
  display.textContent = litros.toFixed(2) + ' L';
  display.style.color = 'var(--accent)';
}

// ── Pad de firma ──────────────────────────────────────────────────────────
function initCanvas(canvasId, hiddenId, statusId) {
  const canvas  = document.getElementById(canvasId);
  const hidden  = document.getElementById(hiddenId);
  const status  = document.getElementById(statusId);
  const ctx     = canvas.getContext('2d');
  let drawing   = false;

  // Escalar para retina
  function resize() {
    const rect = canvas.getBoundingClientRect();
    const dpr  = window.devicePixelRatio || 1;
    canvas.width  = rect.width  * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    ctx.strokeStyle = '#1a1a1a';
    ctx.lineWidth   = 2.5;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
  }
  resize();

  function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const src  = e.touches ? e.touches[0] : e;
    return { x: src.clientX - rect.left, y: src.clientY - rect.top };
  }
  function start(e) { e.preventDefault(); drawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
  function draw(e)  { e.preventDefault(); if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
  function end(e)   { e.preventDefault(); drawing = false; saveSignature(); }

  function saveSignature() {
    hidden.value  = canvas.toDataURL('image/png');
    status.textContent = '✅ Firmado';
    status.style.color = '#3dc977';
  }

  canvas.addEventListener('mousedown',  start);
  canvas.addEventListener('mousemove',  draw);
  canvas.addEventListener('mouseup',    end);
  canvas.addEventListener('mouseleave', end);
  canvas.addEventListener('touchstart', start, {passive:false});
  canvas.addEventListener('touchmove',  draw,  {passive:false});
  canvas.addEventListener('touchend',   end,   {passive:false});
}

function clearCanvas(canvasId, hiddenId) {
  const canvas = document.getElementById(canvasId);
  const ctx    = canvas.getContext('2d');
  const dpr    = window.devicePixelRatio || 1;
  ctx.clearRect(0, 0, canvas.width / dpr, canvas.height / dpr);
  document.getElementById(hiddenId).value = '';
  const statusMap = {'signature_requester':'sig_req_status','signature_loader':'sig_load_status'};
  const st = document.getElementById(statusMap[hiddenId]);
  if (st) { st.textContent = 'Sin firma'; st.style.color = 'var(--text-muted)'; }
}

function validateAndSubmit(e) {
  const sigReq    = document.getElementById('signature_requester').value;
  const sigLoader = document.getElementById('signature_loader').value;
  const meterEnd  = parseFloat(document.querySelector('[name="meter_end"]').value) || 0;

  if (meterEnd <= 0) {
    e.preventDefault();
    alert('⚠ Ingresa la lectura del surtidor final.');
    return false;
  }
  if (meterEnd <= meterStartVal) {
    e.preventDefault();
    alert('⚠ El surtidor final (' + meterEnd.toFixed(2) + ') debe ser mayor al surtidor inicial (' + meterStartVal.toFixed(2) + ').\n\nEl surtidor es un contador acumulativo que siempre sube.');
    document.querySelector('[name="meter_end"]').focus();
    return false;
  }
  if (!sigReq) {
    e.preventDefault();
    alert('⚠ Se requiere la firma del solicitante.');
    return false;
  }
  if (!sigLoader) {
    e.preventDefault();
    alert('⚠ Se requiere la firma del cargador.');
    return false;
  }
  return confirm('¿Confirmar la entrega de combustible? Esta acción no se puede deshacer.');
}

document.addEventListener('DOMContentLoaded', () => {
  initCanvas('sigRequester', 'signature_requester', 'sig_req_status');
  initCanvas('sigLoader',    'signature_loader',    'sig_load_status');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
