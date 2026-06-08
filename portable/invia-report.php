<?php
/**
 * invia-report.php — Invio del Report Previdenziale via email
 *
 * Riceve dal frontend (POST JSON):
 *   nome, cognome, telefono, email, vantaggio, pdf (base64),
 *   privacy_consent (bool, OBBLIGATORIO), hp (honeypot, deve essere "")
 * Risponde:
 *   { "success": true }   |   { "success": false, "error": "..." }
 *
 * SICUREZZA — anti-abuse layers (richieste dal review Codex P1):
 *   1) Origin/Referer check vs ALLOWED_ORIGINS (config)
 *   2) Rate-limit per IP (RATE_LIMIT_MAX_PER_HOUR, file-based)
 *   3) Consenso privacy obbligatorio (privacy_consent === true)
 *   4) Cap dimensione PDF (PDF_MAX_BYTES, default 3 MB)
 *   5) Honeypot field "hp" (i bot lo riempiono → silent reject)
 *
 * Modalità di invio (in ordine di preferenza):
 *   1. PHPMailer + SMTP (consigliato — Aruba/Gmail/qualsiasi SMTP autenticato)
 *   2. mail() di PHP (fallback)
 */

declare(strict_types=1);

// ---- CORS / output ----
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')   { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Solo POST']); exit; }

// ---- Carica configurazione ----
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['success'=>false, 'error'=>'config.php mancante. Copialo da config.php.example e compila le credenziali.']);
    exit;
}
$cfg = require $configFile;

// ---- Logging interno (solo lato server) ----
$logFile = __DIR__ . '/invia-report.log';
function logErr(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '['.date('c').'] '.$msg."\n", FILE_APPEND);
}

function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
            break;
        }
    }
    return $ip;
}

function rejectAbuse(string $reason): void {
    logErr('REJECT '.clientIp().' :: '.$reason);
    http_response_code(400);
    // Messaggio generico: non rivelare quale check è scattato
    echo json_encode(['success' => false, 'error' => 'Richiesta non valida. Riprova dal calcolatore.']);
    exit;
}

// =====================================================
// LAYER 1 — Origin / Referer check
// =====================================================
$allowedOrigins = $cfg['ALLOWED_ORIGINS'] ?? []; // es. ['https://www.tuodominio.it']
if (is_array($allowedOrigins) && count($allowedOrigins) > 0) {
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $matched = false;
    foreach ($allowedOrigins as $allowed) {
        $allowed = rtrim((string)$allowed, '/');
        if ($origin && stripos($origin, $allowed) === 0)  { $matched = true; break; }
        if ($referer && stripos($referer, $allowed) === 0) { $matched = true; break; }
    }
    if (!$matched) {
        rejectAbuse("origin mismatch (origin=$origin, referer=$referer)");
    }
    // CORS solo verso le origin autorizzate
    if ($origin) header("Access-Control-Allow-Origin: $origin");
} else {
    // Nessuna allow-list: comportamento permissivo (sviluppo). Sconsigliato in prod.
    header('Access-Control-Allow-Origin: *');
}

// =====================================================
// LAYER 2 — Rate limit per IP (file-based, sliding window 1h)
// =====================================================
$rateMax    = (int)($cfg['RATE_LIMIT_MAX_PER_HOUR'] ?? 5);
$rateFile   = __DIR__ . '/rate-limit.json';
$rateWindow = 3600; // secondi
if ($rateMax > 0) {
    $now = time();
    $ip  = clientIp();

    // Apri/lock/leggi/aggiorna in modo atomico
    $fp = @fopen($rateFile, 'c+');
    if ($fp) {
        @flock($fp, LOCK_EX);
        $contents = stream_get_contents($fp) ?: '{}';
        $store    = json_decode($contents, true);
        if (!is_array($store)) $store = [];

        // Cleanup periodico: rimuovi IP con tutte le timestamp scadute (ogni ~50 richieste)
        if (mt_rand(0, 50) === 0) {
            foreach ($store as $k => $ts) {
                if (!is_array($ts)) { unset($store[$k]); continue; }
                $store[$k] = array_values(array_filter($ts, fn($t) => $now - (int)$t < $rateWindow));
                if (empty($store[$k])) unset($store[$k]);
            }
        }

        $hits = $store[$ip] ?? [];
        if (!is_array($hits)) $hits = [];
        $hits = array_values(array_filter($hits, fn($t) => $now - (int)$t < $rateWindow));

        if (count($hits) >= $rateMax) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            logErr("RATE-LIMIT $ip ($rateMax/h superato)");
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Troppi tentativi. Riprova fra qualche minuto.']);
            exit;
        }

        $hits[] = $now;
        $store[$ip] = $hits;

        @ftruncate($fp, 0);
        @rewind($fp);
        @fwrite($fp, json_encode($store));
        @fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

// =====================================================
// LAYER 5 — Honeypot (presente subito perché basta a stoppare i bot)
// =====================================================
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    rejectAbuse('payload JSON invalido');
}
if (isset($input['hp']) && trim((string)$input['hp']) !== '') {
    // I bot riempiono campi nascosti. Risposta "success" finta per non
    // fare imparare al bot che ha sbagliato.
    logErr('HONEYPOT '.clientIp());
    echo json_encode(['success' => true, 'method' => 'noop']);
    exit;
}

// =====================================================
// LAYER 3 — Consenso privacy obbligatorio
// =====================================================
$consent = $input['privacy_consent'] ?? false;
if ($consent !== true && $consent !== 'true' && $consent !== 1) {
    rejectAbuse('privacy_consent mancante o non true');
}

// ---- Parse input ----
$nome      = trim((string)($input['nome']      ?? ''));
$cognome   = trim((string)($input['cognome']   ?? ''));
$telefono  = trim((string)($input['telefono']  ?? ''));
$email     = trim((string)($input['email']     ?? ''));
$vantaggio = trim((string)($input['vantaggio'] ?? ''));
$pdfB64    = (string)($input['pdf']            ?? '');

if ($nome === '' || $cognome === '' || $email === '' || $pdfB64 === '') {
    rejectAbuse('campi lead incompleti');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    rejectAbuse('email destinatario non valida');
}
// Limita la lunghezza dei campi (anti-overflow / anti-junk)
if (strlen($nome) > 120 || strlen($cognome) > 120 || strlen($telefono) > 40 || strlen($email) > 254) {
    rejectAbuse('campi lead troppo lunghi');
}

// =====================================================
// LAYER 4 — Cap dimensione PDF
// =====================================================
$pdfMaxBytes = (int)($cfg['PDF_MAX_BYTES'] ?? (3 * 1024 * 1024)); // 3 MB default
// Stima rapida: 1 byte base64 ≈ 0.75 byte binari
if (strlen($pdfB64) > (int)($pdfMaxBytes * 1.4)) {
    rejectAbuse('PDF base64 oltre il limite');
}
$pdfBytes = base64_decode($pdfB64, true);
if ($pdfBytes === false || strncmp($pdfBytes, '%PDF', 4) !== 0) {
    rejectAbuse('PDF allegato non valido (header)');
}
if (strlen($pdfBytes) > $pdfMaxBytes) {
    rejectAbuse('PDF binario oltre il limite ('.strlen($pdfBytes).' > '.$pdfMaxBytes.')');
}

$pdfFilename = 'Report_Previdenziale_' . preg_replace('/[^A-Za-z0-9]+/', '_', $nome.'_'.$cognome) . '.pdf';

// ---- Compose corpo email (HTML brandizzato dal config) ----
$advisorName  = (string)($cfg['ADVISOR_NAME']  ?? 'Domenico Mosca');
$advisorTitle = (string)($cfg['ADVISOR_TITLE'] ?? 'Consulente Finanziario FinecoBank');
$advisorPhone = (string)($cfg['ADVISOR_PHONE'] ?? '');
$advisorEmail = (string)($cfg['ADVISOR_EMAIL'] ?? '');

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
      <p style="font-weight:bold;color:#003B5C;margin:0 0 4px;font-size:16px">'.htmlspecialchars($advisorName, ENT_QUOTES, 'UTF-8').'</p>
      <p style="color:#64748b;margin:0;font-size:13px">'.htmlspecialchars($advisorTitle, ENT_QUOTES, 'UTF-8').'</p>
      '.($advisorPhone !== '' || $advisorEmail !== '' ? '<p style="color:#64748b;margin:6px 0 0;font-size:12px">'.
          ($advisorPhone !== '' ? 'Tel. '.htmlspecialchars($advisorPhone, ENT_QUOTES, 'UTF-8') : '').
          ($advisorPhone !== '' && $advisorEmail !== '' ? ' &middot; ' : '').
          ($advisorEmail !== '' ? htmlspecialchars($advisorEmail, ENT_QUOTES, 'UTF-8') : '').
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
    "— $advisorName ($advisorTitle)\n";

$subject  = 'Il tuo Report Previdenziale — '.$advisorName;
// Se MITTENTE_EMAIL non è compilato, usa lo stesso utente SMTP come mittente
$fromAddr = !empty($cfg['MITTENTE_EMAIL']) ? $cfg['MITTENTE_EMAIL'] : ($cfg['SMTP_USER'] ?? 'noreply@example.it');
$fromName = $cfg['MITTENTE_NOME']  ?? 'Vantaggio Pensione';
$bcc      = $cfg['BCC_CONSULENTE'] ?? null;

// ============================================================
// Tentativo 1 — PHPMailer con SMTP (consigliato)
// ============================================================
$useSmtp = !empty($cfg['SMTP_HOST']) && !empty($cfg['SMTP_USER']) && !empty($cfg['SMTP_PASS']);
$phpmailerLoaded = false;

if ($useSmtp) {
    $candidates = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/PHPMailer/src/PHPMailer.php',
        __DIR__ . '/phpmailer/src/PHPMailer.php',
    ];
    foreach ($candidates as $f) {
        if (file_exists($f)) {
            require_once $f;
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
        $pass = (string)$cfg['SMTP_PASS'];
        if (preg_match('/^[a-z]{4} [a-z]{4} [a-z]{4} [a-z]{4}$/', $pass)) {
            $pass = str_replace(' ', '', $pass);
        }
        $mail->Password   = $pass;
        $mail->SMTPSecure = !empty($cfg['SMTP_SSL'])
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
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
// Tentativo 2 — mail() built-in (fallback)
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
