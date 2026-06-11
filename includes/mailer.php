<?php
// includes/mailer.php
// Usa PHPMailer con SMTP Office 365 (STARTTLS puerto 587)
//
// INSTALACIÓN DE PHPMAILER (hacer una sola vez en el servidor):
// Opción A — Composer (recomendado):
//   cd fuel_system && composer require phpmailer/phpmailer
// Opción B — Manual (sin Composer):
//   1. Descargar https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.9.1.zip
//   2. Descomprimir y copiar la carpeta "src" dentro de:
//      fuel_system/vendor/phpmailer/src/
//   3. Asegurarse que existan los archivos:
//      vendor/phpmailer/src/PHPMailer.php
//      vendor/phpmailer/src/SMTP.php
//      vendor/phpmailer/src/Exception.php

// ── Cargar PHPMailer ───────────────────────────────────────────────────────
$_phpmailerLoaded = false;

// Intento 1: Composer autoload
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $_phpmailerLoaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

// Intento 2: Manual sin Composer
if (!$_phpmailerLoaded) {
    $manualPath = __DIR__ . '/../vendor/phpmailer/src/';
    if (file_exists($manualPath . 'PHPMailer.php')) {
        require_once $manualPath . 'Exception.php';
        require_once $manualPath . 'PHPMailer.php';
        require_once $manualPath . 'SMTP.php';
        $_phpmailerLoaded = true;
    }
}

if (!$_phpmailerLoaded) {
    // PHPMailer no está instalado — registrar en log y usar fallback
    error_log('[FuelControl] ADVERTENCIA: PHPMailer no encontrado. Los correos NO se enviarán. Instala PHPMailer en vendor/phpmailer/src/');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ── Función base de envío SMTP ─────────────────────────────────────────────
function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    global $_phpmailerLoaded;

    if (!$_phpmailerLoaded) {
        error_log("[FuelControl Mailer] PHPMailer no disponible. Correo NO enviado a: {$toEmail} | Asunto: {$subject}");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        // ── Servidor ───────────────────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;            // smtp.office365.com
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;            // intranet@latinequip.com
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;            // 587
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Office365: el FROM debe coincidir con el usuario autenticado
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        // ── Codificación ───────────────────────────────────────────
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        // ── Destinatario ───────────────────────────────────────────
        $mail->addAddress($toEmail, $toName);

        // ── Contenido ──────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />','</p>','</tr>'], "\n", $htmlBody));

        $mail->send();
        error_log("[FuelControl Mailer] OK → {$toEmail} | {$subject}");
        return true;

    } catch (PHPMailerException $e) {
        error_log("[FuelControl Mailer] ERROR → {$toEmail} | {$subject} | " . $mail->ErrorInfo);
        return false;
    } catch (\Exception $e) {
        error_log("[FuelControl Mailer] EXCEPCIÓN → {$toEmail} | " . $e->getMessage());
        return false;
    }
}

// ── Plantilla HTML base LESA ───────────────────────────────────────────────
function emailWrap(string $content): string {
    return "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f0f0f0;font-family:Arial,Helvetica,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f0f0;padding:30px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%;'>
  <!-- HEADER -->
  <tr><td style='background:#111111;padding:22px 30px;border-radius:8px 8px 0 0;border-top:4px solid #F0B800;text-align:center;'>
    <span style='font-size:30px;'>⛽</span>
    <h1 style='color:#F0B800;margin:6px 0 2px;font-size:22px;letter-spacing:2px;text-transform:uppercase;'>FuelControl</h1>
    <p style='color:#666;margin:0;font-size:11px;letter-spacing:1px;text-transform:uppercase;'>Latin Equipment Chile</p>
  </td></tr>
  <!-- BODY -->
  <tr><td style='background:#ffffff;padding:28px 30px;border:1px solid #e0e0e0;border-top:none;'>
    {$content}
  </td></tr>
  <!-- FOOTER -->
  <tr><td style='background:#f8f8f8;padding:14px 30px;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;text-align:center;'>
    <p style='color:#aaa;font-size:11px;margin:0;'>Mensaje generado automáticamente por FuelControl. No responder este correo.<br>
    &copy; " . date('Y') . " Latin Equipment Chile S.A.</p>
  </td></tr>
</table>
</td></tr>
</table>
</body></html>";
}

function emailRow(string $label, string $value, string $bg = '#f4f4f4'): string {
    return "<tr>
      <td style='padding:9px 12px;background:{$bg};font-weight:bold;font-size:13px;color:#333;width:38%;border-bottom:1px solid #e8e8e8;'>{$label}</td>
      <td style='padding:9px 12px;font-size:13px;color:#222;border-bottom:1px solid #e8e8e8;'>{$value}</td>
    </tr>";
}

// ── Correo: Solicitud pendiente de aprobación ──────────────────────────────
function emailApprovalRequest(array $approver, array $request, int $step): bool {
    $url = APP_URL . '/modules/approvals/approve.php?id=' . $request['id'];

    // Filas dinámicas según tipo de solicitud
    $extraRows = '';
    if (!empty($request['service_call_number'])) {
        $extraRows .= emailRow('N° de Llamada',
            "<strong style='color:#856404;font-size:15px;'>" . htmlspecialchars($request['service_call_number']) . "</strong>",
            '#fff8e1');
    }
    if (!empty($request['machine_name']) && $request['machine_name'] !== '—') {
        $extraRows .= emailRow('Máquina', htmlspecialchars($request['machine_name']));
    }

    $content = "
    <p style='color:#222;font-size:15px;margin-top:0;'>Estimado/a <strong>" . htmlspecialchars($approver['name']) . "</strong>,</p>
    <p style='color:#555;font-size:14px;'>Tiene una solicitud de combustible pendiente de aprobación en el <strong>Paso #{$step}</strong>.</p>

    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;margin:18px 0;'>
      " . emailRow('N° Solicitud', "<strong style='color:#1a1a1a;font-size:15px;'>" . htmlspecialchars($request['request_number']) . "</strong>")
       . emailRow('Tipo', ucfirst(htmlspecialchars($request['request_type'])))
       . $extraRows
       . emailRow('Combustible', htmlspecialchars($request['fuel_type']))
       . emailRow('Litros solicitados', "<strong style='font-size:16px;'>" . number_format($request['liters_requested'], 0) . " L</strong>")
       . emailRow('Sucursal entrega', htmlspecialchars($request['branch_name']))
       . emailRow('Solicitante', htmlspecialchars($request['requester_name'])) . "
    </table>

    <div style='text-align:center;margin:24px 0 8px;'>
      <a href='{$url}' style='display:inline-block;background:#F0B800;color:#000000;padding:14px 36px;text-decoration:none;border-radius:6px;font-size:15px;font-weight:bold;letter-spacing:.5px;'>
        ✅ Revisar y Aprobar Solicitud
      </a>
    </div>
    <p style='text-align:center;color:#aaa;font-size:12px;'>O ingresa al sistema → Mis Aprobaciones</p>";

    return sendEmail(
        $approver['email'],
        $approver['name'],
        "FuelControl — Aprobación requerida #{$request['request_number']} (Paso #{$step})",
        emailWrap($content)
    );
}

// ── Correo: Flujo completo → notificar al cargador ────────────────────────
function emailApprovalComplete(array $loader, array $request): bool {
    $url = APP_URL . '/modules/requests/pending_delivery.php';

    $content = "
    <p style='color:#222;font-size:15px;margin-top:0;'>Estimado/a <strong>" . htmlspecialchars($loader['name']) . "</strong>,</p>
    <p style='color:#555;font-size:14px;'>La siguiente solicitud ha sido <strong style='color:#22a355;'>APROBADA COMPLETAMENTE</strong> y está lista para ser despachada.</p>

    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;margin:18px 0;'>
      " . emailRow('N° Solicitud', "<strong style='color:#1a1a1a;font-size:15px;'>" . htmlspecialchars($request['request_number']) . "</strong>")
       . emailRow('Combustible', htmlspecialchars($request['fuel_type']))
       . emailRow('Litros a entregar', "<strong style='font-size:18px;color:#22a355;'>" . number_format($request['liters_requested'], 0) . " L</strong>") . "
    </table>

    <div style='background:#f0faf4;border:1px solid #a8ddb8;border-radius:6px;padding:14px 18px;margin:18px 0;'>
      <p style='margin:0;color:#1e7e34;font-size:14px;'>⚠ Por favor, registre la entrega en el sistema a la brevedad posible.</p>
    </div>

    <div style='text-align:center;margin:24px 0 8px;'>
      <a href='{$url}' style='display:inline-block;background:#22a355;color:#ffffff;padding:14px 36px;text-decoration:none;border-radius:6px;font-size:15px;font-weight:bold;'>
        🚚 Ir a Entregas Pendientes
      </a>
    </div>";

    return sendEmail(
        $loader['email'],
        $loader['name'],
        "FuelControl — Combustible aprobado para entrega #{$request['request_number']}",
        emailWrap($content)
    );
}

// ── Correo: Confirmación al solicitante al crear la solicitud ─────────────
function emailRequestConfirmation(array $requester, array $request): bool {
    $url = APP_URL . '/modules/requests/view.php?id=' . $request['id'];

    $extraRows = '';
    if (!empty($request['service_call_number'])) {
        $extraRows .= emailRow('N° de Llamada',
            "<strong style='color:#856404;font-size:15px;'>" . htmlspecialchars($request['service_call_number']) . "</strong>",
            '#fff8e1');
    }
    if (!empty($request['machine_name']) && $request['machine_name'] !== '—') {
        $extraRows .= emailRow('Máquina', htmlspecialchars($request['machine_name']));
    }

    $content = "
    <p style='color:#222;font-size:15px;margin-top:0;'>Estimado/a <strong>" . htmlspecialchars($requester['name']) . "</strong>,</p>
    <p style='color:#555;font-size:14px;'>Tu solicitud de combustible ha sido <strong>creada exitosamente</strong> y se encuentra en proceso de aprobación.</p>

    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;margin:18px 0;'>
      " . emailRow('N° Solicitud', "<strong style='color:#1a1a1a;font-size:16px;'>" . htmlspecialchars($request['request_number']) . "</strong>")
       . emailRow('Tipo', ucfirst(htmlspecialchars($request['request_type'])))
       . $extraRows
       . emailRow('Combustible', htmlspecialchars($request['fuel_type']))
       . emailRow('Litros solicitados', "<strong style='font-size:16px;'>" . number_format($request['liters_requested'], 0) . " L</strong>")
       . emailRow('Sucursal de entrega', htmlspecialchars($request['branch_name']))
       . emailRow('Estado', "<span style='color:#e08c00;font-weight:bold;'>En proceso de aprobación</span>") . "
    </table>

    <div style='background:#fffbea;border:1px solid #F0B800;border-radius:6px;padding:14px 18px;margin:18px 0;'>
      <p style='margin:0;color:#7a5c00;font-size:14px;'>Te notificaremos por correo cuando tu solicitud sea aprobada o rechazada.</p>
    </div>

    <div style='text-align:center;margin:24px 0 8px;'>
      <a href='{$url}' style='display:inline-block;background:#111111;color:#F0B800;padding:14px 36px;text-decoration:none;border-radius:6px;font-size:15px;font-weight:bold;letter-spacing:.5px;'>
        📋 Ver Estado de mi Solicitud
      </a>
    </div>";

    return sendEmail(
        $requester['email'],
        $requester['name'],
        "FuelControl — Solicitud creada #{$request['request_number']}",
        emailWrap($content)
    );
}

// ── Correo: Flujo completado → notificar al solicitante que fue aprobada ──
function emailRequestApprovedToRequester(array $requester, array $request): bool {
    $url = APP_URL . '/modules/requests/view.php?id=' . $request['id'];

    $extraRows = '';
    if (!empty($request['service_call_number'])) {
        $extraRows .= emailRow('N° de Llamada',
            "<strong style='color:#856404;'>" . htmlspecialchars($request['service_call_number']) . "</strong>",
            '#fff8e1');
    }
    if (!empty($request['machine_name']) && $request['machine_name'] !== '—') {
        $extraRows .= emailRow('Máquina', htmlspecialchars($request['machine_name']));
    }

    $content = "
    <p style='color:#222;font-size:15px;margin-top:0;'>Estimado/a <strong>" . htmlspecialchars($requester['name']) . "</strong>,</p>
    <p style='color:#555;font-size:14px;'>Tu solicitud de combustible ha sido <strong style='color:#22a355;'>APROBADA</strong>. El equipo de cargadores procederá a gestionar la entrega.</p>

    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;margin:18px 0;'>
      " . emailRow('N° Solicitud', "<strong style='color:#1a1a1a;font-size:16px;'>" . htmlspecialchars($request['request_number']) . "</strong>")
       . emailRow('Tipo', ucfirst(htmlspecialchars($request['request_type'])))
       . $extraRows
       . emailRow('Combustible', htmlspecialchars($request['fuel_type']))
       . emailRow('Litros aprobados', "<strong style='font-size:18px;color:#22a355;'>" . number_format($request['liters_requested'], 0) . " L</strong>")
       . emailRow('Sucursal de entrega', htmlspecialchars($request['branch_name']))
       . emailRow('Estado', "<span style='color:#22a355;font-weight:bold;'>✅ Aprobada — Pendiente de entrega</span>") . "
    </table>

    <div style='background:#f0faf4;border:1px solid #a8ddb8;border-radius:6px;padding:14px 18px;margin:18px 0;'>
      <p style='margin:0;color:#1e7e34;font-size:14px;'>El cargador responsable realizará la entrega a la brevedad. Puedes revisar el estado de tu solicitud en el sistema.</p>
    </div>

    <div style='text-align:center;margin:24px 0 8px;'>
      <a href='{$url}' style='display:inline-block;background:#22a355;color:#ffffff;padding:14px 36px;text-decoration:none;border-radius:6px;font-size:15px;font-weight:bold;'>
        📋 Ver mi Solicitud
      </a>
    </div>";

    return sendEmail(
        $requester['email'],
        $requester['name'],
        "FuelControl — Tu solicitud #{$request['request_number']} fue aprobada ✅",
        emailWrap($content)
    );
}


function emailRejected(array $requester, array $request, string $comments): bool {
    $content = "
    <p style='color:#222;font-size:15px;margin-top:0;'>Estimado/a <strong>" . htmlspecialchars($requester['name']) . "</strong>,</p>
    <p style='color:#555;font-size:14px;'>Lamentamos informarle que su solicitud ha sido <strong style='color:#e03d3d;'>RECHAZADA</strong>.</p>

    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;margin:18px 0;'>
      " . emailRow('N° Solicitud', "<strong style='color:#1a1a1a;font-size:15px;'>" . htmlspecialchars($request['request_number']) . "</strong>") . "
    </table>

    <div style='background:#fdf2f2;border:1px solid #f5b7b1;border-radius:6px;padding:14px 18px;margin:18px 0;'>
      <p style='margin:0 0 6px;font-weight:bold;color:#922b21;font-size:12px;text-transform:uppercase;letter-spacing:.5px;'>Motivo del rechazo:</p>
      <p style='margin:0;color:#641e16;font-size:14px;'>" . nl2br(htmlspecialchars($comments)) . "</p>
    </div>

    <p style='color:#555;font-size:14px;'>Si tiene dudas, contáctese con su supervisor o con el área responsable.</p>";

    return sendEmail(
        $requester['email'],
        $requester['name'],
        "FuelControl — Solicitud rechazada #{$request['request_number']}",
        emailWrap($content)
    );
}
