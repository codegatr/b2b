<?php
/**
 * api/rubikpara-installments.php
 * Bayi kart numarasını girince çağrılan AJAX taksit sorgu endpoint'i.
 *
 * Input  (POST): csrf, bin (6 hane), order_id
 * Output (JSON): { ok, installments: [{installmentCount, totalAmount,
 *                  installmentAmount, commission, commissionRate, cardFamily}] }
 */
define('B2B_ROOT', dirname(__DIR__));
$cfg = require B2B_ROOT . '/config.php';
define('B2B_URL', rtrim($cfg['site_url'], '/'));
define('B2B_DEBUG', $cfg['debug'] ?? false);
require B2B_ROOT . '/includes/db.php';
require B2B_ROOT . '/includes/auth.php';
require B2B_ROOT . '/includes/functions.php';
require B2B_ROOT . '/includes/rubikpara.php';

b2b_session_start();
header('Content-Type: application/json; charset=utf-8');

function rk_out(bool $ok, string $msg = '', array $extra = []): never {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isDealer())                       rk_out(false, 'Oturum gerekli');
if ($_SERVER['REQUEST_METHOD']!=='POST') rk_out(false, 'POST gerekli');

csrfCheck();

$dealerId = (int)$_SESSION['dealer_id'];
$dealer   = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$dealerId]);
if (!$dealer) rk_out(false, 'Bayi bulunamadı');

// Bayinin kart ödeme izni var mı?
$allowed = array_filter(explode(',', $dealer['payment_methods'] ?? 'havale,kredi_karti'));
if (!in_array('kredi_karti', $allowed, true)) {
    rk_out(false, 'Kart ile ödeme izniniz yok');
}

// WAF (LiteSpeed) 6 haneli rakam içeren tek parametreyi kart no
// pattern olarak algılayıp 403 atıyor. JS bin'i 3+3 parçalı yolluyor.
$bin = preg_replace('/\D/', '',
    ($_POST['bp1'] ?? $_POST['bin'] ?? '') .
    ($_POST['bp2'] ?? '')
);
$orderId = (int)($_POST['order_id'] ?? 0);
$pending = ($_POST['pending'] ?? '') === '1';

if (strlen($bin) < 6) rk_out(false, 'BIN en az 6 hane olmalı');

if ($pending) {
    // PENDING MODE: order yok, session'dan grand_total al
    $snap = $_SESSION['pending_card'][$dealerId] ?? null;
    if (!$snap || (time() - ($snap['created_at'] ?? 0)) > 3600) {
        rk_out(false, 'Pending sepet bulunamadı veya süresi geçti, sepete dönüp tekrar deneyin');
    }
    $amount = (float)$snap['grand_total'];
} else {
    if ($orderId < 1) rk_out(false, 'Sipariş ID geçersiz');
    $order = dbRow(
        "SELECT id, grand_total, payment_status, status
         FROM b2b_orders WHERE id=? AND dealer_id=?",
        [$orderId, $dealerId]
    );
    if (!$order)                                                rk_out(false, 'Sipariş bulunamadı');
    if ($order['payment_status'] === 'odendi')                  rk_out(false, 'Sipariş zaten ödenmiş');
    if (in_array($order['status'], ['iptal','iade'], true))     rk_out(false, 'İptal/iade siparişe ödeme yapılamaz');
    $amount = (float)$order['grand_total'];
}

try {
    $installments = rubikpara()->taksitSorgula(substr($bin, 0, 6), $amount);

    // Hiç sonuç dönmediyse boş liste — helper tek çekim option'u ekleyecek
    // (admin'in 'rubikpara_single_rate' oranı varsa o yansır)
    $installments = rubikparaTaksitleriZenginlestir($installments, $amount);

    rk_out(true, '', [
        'installments' => $installments,
        'baseAmount'   => $amount,
    ]);
} catch (Throwable $e) {
    rk_out(false, $e->getMessage());
}
