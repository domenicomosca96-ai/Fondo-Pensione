<?php
/**
 * gemini-proxy.php — Proxy lato server verso Google Gemini
 *
 * Riceve dal frontend (POST JSON):
 *   { "message": "..." }
 * Risponde nel formato atteso dal frontend (calcato sull'API Gemini):
 *   { "candidates": [ { "content": { "parts": [ { "text": "..." } ] } } ] }
 *   oppure: { "error": { "message": "..." } }
 *
 * La chiave Gemini è in config.php (mai esposta al browser).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')   { http_response_code(405); echo json_encode(['error'=>['message'=>'Solo POST']]); exit; }

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['error'=>['message'=>'config.php mancante.']]);
    exit;
}
$cfg = require $configFile;
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

$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
$payload  = json_encode([
    'contents' => [
        ['parts' => [['text' => $message]]],
    ],
]);

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
    // Restituisci comunque il body originale dell'errore Gemini (utile per il debug)
    echo $resp;
    exit;
}

// Pass-through: il body è già nel formato che il frontend si aspetta.
echo $resp;
