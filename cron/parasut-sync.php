<?php
/**
 * Paraşüt Cache Senkronizasyonu — Cron Endpoint
 * ════════════════════════════════════════════════════════════════════
 *
 * KULLANIM (cPanel/DirectAdmin Cron Job):
 *
 *   Haftalık (Pazartesi 03:00):
 *   0 3 * * 1 /usr/bin/curl -s "https://bayi.lemondedutacos.com/cron/parasut-sync.php?token=YOUR_CRON_TOKEN"
 *
 *   Günlük (her gece 03:00):
 *   0 3 * * * /usr/bin/curl -s "https://bayi.lemondedutacos.com/cron/parasut-sync.php?token=YOUR_CRON_TOKEN"
 *
 * GÜVENLİK:
 * - `settings → cron_token` setting'inde tanımlı token ile çağırılmalı
 * - HTTPS zorunlu (token plaintext'te gider)
 * - IP whitelist eklemek için config.php'ye `CRON_ALLOWED_IPS` array tanımla
 *
 * MANUEL TEST:
 *   curl "https://bayi.lemondedutacos.com/cron/parasut-sync.php?token=XXX"
 *   → JSON response döner
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/parasut.php';

// JSON yanıt header'ı
header('Content-Type: application/json; charset=utf-8');

// CRON uzun süre çalışabilir
@set_time_limit(900);
@ini_set('max_execution_time', 900);
@ignore_user_abort(true);

// Çıktıyı topla (cPanel cron e-postası için kullanışlı)
$startTime = microtime(true);

// ─── GÜVENLİK ───
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';
$expectedToken = setting('cron_token', '');

if ($expectedToken === '' || strlen($expectedToken) < 16) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'Cron token not configured. Set in admin → Settings → Cron.',
    ]);
    exit;
}

if (!hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid cron token',
    ]);
    error_log('Cron parasut-sync: Invalid token attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

// Opsiyonel IP whitelist
if (defined('CRON_ALLOWED_IPS') && is_array(CRON_ALLOWED_IPS) && !empty(CRON_ALLOWED_IPS)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, CRON_ALLOWED_IPS, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'IP not allowed', 'ip' => $ip]);
        exit;
    }
}

// ─── SENKRONİZASYON (Products + Contacts) ───
$productResult = parasut_cache_sync_products();
$contactResult = parasut_cache_sync_contacts();
$duration = round(microtime(true) - $startTime, 2);

// Toplam sonuç
$success = $productResult['success'] || $contactResult['success'];
$combined = [
    'success'  => $success,
    'products' => $productResult,
    'contacts' => $contactResult,
    'duration' => $duration,
    'time'     => date('c'),
];

// Settings'e cron log
settingSave('parasut_cron_last_run_at', date('Y-m-d H:i:s'));
settingSave('parasut_cron_last_result', json_encode($combined, JSON_UNESCAPED_UNICODE));

http_response_code($success ? 200 : 500);
echo json_encode($combined, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
