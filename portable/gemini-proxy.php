<?php
/**
 * gemini-proxy.php — Proxy lato server verso Google Gemini
 *
 * Riceve dal frontend (POST JSON):  { "message": "..." }
 * Risponde nel formato API Gemini:  { "candidates": [...] }  o  { "error": {...} }
 *
 * Difese anti-abuse (l'endpoint costa: ogni call consuma quota Gemini):
 *   - Origin check vs ALLOWED_ORIGINS
 *   - Rate-limit per IP (RATE_LIMIT_MAX_PER_HOUR_GEMINI, default 20)
 *   - Cap dimensione messaggio (4000 char)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')   { http_response_code(405); echo json_encode(['error'=>['message'=>'Solo POST']]); exit; }

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['error'=>['message'=>'config.php mancante.']]);
    exit;
}
$cfg = require $configFile;

$logFile = __DIR__ . '/invia-report.log';
function gemLog(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '['.date('c').'] gemini :: '.$msg."\n", FILE_APPEND);
}

function gemClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) { $ip = trim(explode(',', (string)$_SERVER[$h])[0]); break; }
    }
    return $ip;
}

// ---- Origin check ----
$allowedOrigins = $cfg['ALLOWED_ORIGINS'] ?? [];
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
        gemLog('REJECT '.gemClientIp().' :: origin mismatch');
        http_response_code(400);
        echo json_encode(['error'=>['message'=>'Richiesta non valida.']]);
        exit;
    }
    if ($origin) header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: *');
}

// ---- Rate limit per IP ----
$rateMax    = (int)($cfg['RATE_LIMIT_MAX_PER_HOUR_GEMINI'] ?? 20);
$rateFile   = __DIR__ . '/rate-limit.json';
$rateWindow = 3600;
if ($rateMax > 0) {
    $now = time();
    $ip  = 'gem:'.gemClientIp();
    $fp = @fopen($rateFile, 'c+');
    if ($fp) {
        @flock($fp, LOCK_EX);
        $contents = stream_get_contents($fp) ?: '{}';
        $store = json_decode($contents, true);
        if (!is_array($store)) $store = [];
        $hits = $store[$ip] ?? [];
        if (!is_array($hits)) $hits = [];
        $hits = array_values(array_filter($hits, fn($t) => $now - (int)$t < $rateWindow));
        if (count($hits) >= $rateMax) {
            @flock($fp, LOCK_UN); @fclose($fp);
            gemLog("RATE-LIMIT $ip");
            http_response_code(429);
            echo json_encode(['error'=>['message'=>'Troppe richieste AI. Riprova tra qualche minuto.']]);
            exit;
        }
        $hits[] = $now;
        $store[$ip] = $hits;
        @ftruncate($fp, 0); @rewind($fp);
        @fwrite($fp, json_encode($store));
        @fflush($fp); @flock($fp, LOCK_UN); @fclose($fp);
    }
}

$apiKey = $cfg['GEMINI_API_KEY'] ?? '';
$model  = $cfg['GEMINI_MODEL']   ?? 'gemini-2.5-flash';
if ($apiKey === '') {
    echo json_encode(['error'=>['message'=>'GEMINI_API_KEY non configurata.']]);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$message = is_array($input) ? trim((string)($input['message'] ?? '')) : '';
if ($message === '') {
    echo json_encode(['error'=>['message'=>'Campo "message" mancante.']]);
    exit;
}
if (strlen($message) > 4000) {
    echo json_encode(['error'=>['message'=>'Messaggio troppo lungo (max 4000 caratteri).']]);
    exit;
}

$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
$payload  = json_encode(['contents' => [['parts' => [['text' => $message]]]]]);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    echo json_encode(['error'=>['message'=>'Errore di rete: '.$curlErr]]);
    exit;
}
if ($httpCode >= 400) {
    echo $resp;
    exit;
}
echo $resp;
