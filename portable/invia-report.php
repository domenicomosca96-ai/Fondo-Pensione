<?php
/**
 * invia-report.php — Invio del Report Previdenziale via email
 *
 * Riceve dal frontend (POST JSON):
 *   nome, cognome, telefono, email, vantaggio, pdf (base64)
 * Risponde:
 *   { "success": true }   |   { "success": false, "error": "..." }
 *
 * Modalità di invio (in ordine di preferenza):
 *   1. PHPMailer + SMTP (se i parametri SMTP sono compilati in config.php)
 *      → consigliato: funziona con Aruba/Gmail/qualsiasi SMTP autenticato
 *   2. mail() di PHP (fallback)
 *      → funziona "out of the box" sui mailserver locali (Aruba sendmail)
 *        ma è meno affidabile in deliverability
 *
 * Vedi config.php.example per le opzioni di configurazione.
 */

declare(strict_types=1);

// ---- CORS / output ----
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')   { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Solo POST']); exit; }

// ---- Carica configurazione ----
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['success'=>false, 'error'=>'config.php mancante. Copialo da config.php.example e compila le credenziali.']);
    exit;
}
$cfg = require $configFile;

// ---- Logging interno (lato server, mai esposto al lead) ----
$logFile = __DIR__ . '/invia-report.log';
function logErr(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '['.date('c').'] '.$msg."\n", FILE_APPEND);
}

// ---- Parse input ----
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    echo json_encode(['success'=>false,'error'=>'Payload JSON non valido']);
    exit;
}

$nome      = trim((string)($input['nome']      ?? ''));
$cognome   = trim((string)($input['cognome']   ?? ''));
$telefono  = trim((string)($input['telefono']  ?? ''));
$email     = trim((string)($input['email']     ?? ''));
$vantaggio = trim((string)($input['vantaggio'] ?? ''));
$pdfB64    = (string)($input['pdf']            ?? '');

if ($nome === '' || $cognome === '' || $email === '' || $pdfB64 === '') {
    echo json_encode(['success'=>false,'error'=>'Dati lead incompleti']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'error'=>'Email destinatario non valida']);
    exit;
}

$pdfBytes = base64_decode($pdfB64, true);
if ($pdfBytes === false || strncmp($pdfBytes, '%PDF', 4) !== 0) {
    echo json_encode(['success'=>false,'error'=>'PDF allegato non valido']);
    exit;
}
$pdfFilename = 'Report_Previdenziale_' . preg_replace('/[^A-Za-z0-9]+/', '_', $nome.'_'.$cognome) . '.pdf';

// ---- Compose corpo email (HTML brandizzato) ----
$bodyHtml = '
<!DOCTYPE html><html lang="it"><body style="font-family:Arial,Helvetica,sans-serif;background:#f0f4f8;margin:0;padding:30px">
<div style="max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">
  <div style="background:linear-gradient(135deg,#003B5C 0%,#005A78 100%);padding:36px 30px;text-align:center;color:#fff">
    <h1 style="margin:0 0 8px;font-size:24px;font-weight:800">Il tuo Report Previdenziale è pronto</h1>
    <p style="margin:0;opacity:.9;font-size:14px">Analisi personalizzata del vantaggio fiscale e pensionistico</p>
  </div>
  <div style="padding:32px 30px;color:#334155;line-height:1.6">
    <p style="font-size:16px;margin-top:0">Ciao <strong>'.htmlspecialchars($nome, ENT_QUOTES, 'UTF-8').'</strong>,</p>
    <p>Grazie per aver utilizzato il calcolatore <strong>Vantaggio Pensione</strong>. In allegato trovi il report PDF completo con tutti i dettagli della tua simulazione.</p>
    <div style="background:#f0fdf4;border-left:4px solid #22c55e;padding:18px;border-radius:6px;margin:22px 0">
      <p style="margin:0 0 4px;color:#166534;font-size:12px;text-transform:uppercase;letter-spacing:.5px;font-weight:bold">Vantaggio Netto Stimato</p>
      <p style="margin:0;font-size:30px;font-weight:800;color:#16a34a">'.htmlspecialchars($vantaggio !== '' ? $vantaggio : 'vedi report', ENT_QUOTES, 'UTF-8').'</p>
    </div>
    <p style="margin-bottom:6px"><strong>Nel report troverai:</strong></p>
    <ul style="padding-left:20px;margin-top:0">
      <li>Confronto dettagliato tra scenario standard e Fondo Pensione</li>
      <li>Composizione del vantaggio (deduzione, rendimenti, contributo datore)</li>
      <li>Profilo fiscale e ipotesi di calcolo</li>
      <li>Proiezione di erogazione (rendita vs capitale)</li>
    </ul>
    <div style="background:#fef3c7;border-radius:8px;padding:16px;margin:24px 0;font-size:14px">
      <strong style="color:#92400e">Vuoi approfondire?</strong><br>
      <span style="color:#78350f">Sono disponibile per una consulenza gratuita personalizzata. Rispondi a questa email o contattami direttamente.</span>
    </div>
    <div style="border-top:1px solid #e2e8f0;padding-top:18px;margin-top:28px;text-align:center">
      <p style="font-weight:bold;color:#003B5C;margin:0 0 4px;font-size:16px">'.htmlspecialchars($cfg['ADVISOR_NAME'] ?? 'Domenico Mosca', ENT_QUOTES, 'UTF-8').'</p>
      <p style="color:#64748b;margin:0;font-size:13px">'.htmlspecialchars($cfg['ADVISOR_TITLE'] ?? 'Consulente Finanziario FinecoBank', ENT_QUOTES, 'UTF-8').'</p>
      '.(!empty($cfg['ADVISOR_PHONE']) || !empty($cfg['ADVISOR_EMAIL']) ? '<p style="color:#64748b;margin:6px 0 0;font-size:12px">'.
          (!empty($cfg['ADVISOR_PHONE']) ? 'Tel. '.htmlspecialchars($cfg['ADVISOR_PHONE'], ENT_QUOTES, 'UTF-8') : '').
          (!empty($cfg['ADVISOR_PHONE']) && !empty($cfg['ADVISOR_EMAIL']) ? ' &middot; ' : '').
          (!empty($cfg['ADVISOR_EMAIL']) ? htmlspecialchars($cfg['ADVISOR_EMAIL'], ENT_QUOTES, 'UTF-8') : '').
        '</p>' : '').'
    </div>
  </div>
  <div style="background:#f8fafc;padding:16px 30px;text-align:center;color:#94a3b8;font-size:11px">
    Simulazione a scopo informativo. Non costituisce consulenza finanziaria ai sensi del D.Lgs. 58/98.
  </div>
</div></body></html>';

$bodyTxt =
    "Ciao $nome,\n\n".
    "in allegato il tuo report previdenziale personalizzato.\n".
    "Vantaggio netto stimato: ".($vantaggio !== '' ? $vantaggio : 'vedi report').".\n\n".
    "Per qualsiasi domanda puoi rispondere a questa email.\n\n".
    "— ".($cfg['ADVISOR_NAME'] ?? 'Domenico Mosca')." (".($cfg['ADVISOR_TITLE'] ?? 'Consulente Finanziario FinecoBank').")\n";

$subject = 'Il tuo Report Previdenziale — '.($cfg['ADVISOR_NAME'] ?? 'Domenico Mosca FinecoBank');
$fromAddr = $cfg['MITTENTE_EMAIL'] ?? 'noreply@example.it';
$fromName = $cfg['MITTENTE_NOME']  ?? 'Vantaggio Pensione';
$bcc      = $cfg['BCC_CONSULENTE'] ?? null;

// ============================================================
// Tentativo 1 — PHPMailer con SMTP (consigliato)
// ============================================================
$useSmtp = !empty($cfg['SMTP_HOST']) && !empty($cfg['SMTP_USER']) && !empty($cfg['SMTP_PASS']);
$phpmailerLoaded = false;

if ($useSmtp) {
    // Cerca PHPMailer in posizioni comuni
    $candidates = [
        __DIR__ . '/vendor/autoload.php',                      // composer install
        __DIR__ . '/PHPMailer/src/PHPMailer.php',              // download manuale
        __DIR__ . '/phpmailer/src/PHPMailer.php',
    ];
    foreach ($candidates as $f) {
        if (file_exists($f)) {
            require_once $f;
            // Se è il file PHPMailer.php (non l'autoload), include anche le altre classi
            if (basename($f) === 'PHPMailer.php') {
                @require_once dirname($f) . '/SMTP.php';
                @require_once dirname($f) . '/Exception.php';
            }
            $phpmailerLoaded = class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
            break;
        }
    }
}

if ($useSmtp && $phpmailerLoaded) {
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['SMTP_HOST'];
        $mail->Port       = (int)($cfg['SMTP_PORT'] ?? 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['SMTP_USER'];
        // Gmail App Password può avere spazi: rimuovili solo se la stringa
        // ha la forma esatta Gmail (4 gruppi da 4 caratteri lowercase)
        $pass = (string)$cfg['SMTP_PASS'];
        if (preg_match('/^[a-z]{4} [a-z]{4} [a-z]{4} [a-z]{4}$/', $pass)) {
            $pass = str_replace(' ', '', $pass);
        }
        $mail->Password   = $pass;
        $mail->SMTPSecure = !empty($cfg['SMTP_SSL'])
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS   // SSL diretto (porta 465)
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS (porta 587)
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
        if (!empty($cfg['SMTP_DEBUG'])) {
            $mail->SMTPDebug   = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function ($str, $level) { logErr("SMTP[$level]: $str"); };
        }

        $mail->setFrom($fromAddr, $fromName);
        $mail->addReplyTo($fromAddr, $fromName);
        $mail->addAddress($email, $nome.' '.$cognome);
        if (!empty($bcc)) $mail->addBCC($bcc);

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyTxt;
        $mail->addStringAttachment($pdfBytes, $pdfFilename, 'base64', 'application/pdf');

        $mail->send();
        echo json_encode(['success'=>true, 'method'=>'smtp']);
        exit;
    } catch (\Throwable $e) {
        logErr('PHPMailer error: '.$e->getMessage());
        echo json_encode([
            'success' => false,
            'error'   => 'Invio SMTP fallito: '.$e->getMessage(),
        ]);
        exit;
    }
}

// ============================================================
// Tentativo 2 — mail() built-in (fallback senza PHPMailer)
// ============================================================
$boundary = md5(uniqid((string)time(), true));
$headers  = "From: $fromName <$fromAddr>\r\n";
$headers .= "Reply-To: $fromAddr\r\n";
if (!empty($bcc)) $headers .= "Bcc: $bcc\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

$message  = "--$boundary\r\n";
$message .= "Content-Type: text/html; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$message .= $bodyHtml . "\r\n\r\n";
$message .= "--$boundary\r\n";
$message .= "Content-Type: application/pdf; name=\"$pdfFilename\"\r\n";
$message .= "Content-Transfer-Encoding: base64\r\n";
$message .= "Content-Disposition: attachment; filename=\"$pdfFilename\"\r\n\r\n";
$message .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
$message .= "--$boundary--";

$encodedSubject = '=?UTF-8?B?'.base64_encode($subject).'?=';

$ok = @mail($email, $encodedSubject, $message, $headers, '-f'.$fromAddr);
if ($ok) {
    echo json_encode(['success'=>true, 'method'=>'mail']);
    exit;
}

logErr('mail() returned false (PHPMailer non disponibile o config SMTP mancante)');
echo json_encode([
    'success' => false,
    'error'   => $useSmtp
        ? 'PHPMailer non installato. Esegui "composer require phpmailer/phpmailer" oppure scarica PHPMailer in /portable/PHPMailer/.'
        : 'mail() ha rifiutato l\'invio. Configura SMTP in config.php (host, user, pass) e installa PHPMailer per un invio affidabile.',
]);
