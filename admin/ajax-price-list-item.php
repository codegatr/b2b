<?php
/**
 * Admin AJAX endpoint — Fiyat Listesi item kaydetme/silme
 *
 * KRITIK: Bu dosya admin/index.php'yi İÇERMEZ. Layout HTML basılmaz.
 * jsonResponse() öncesinde HİÇBİR çıktı YOK, header güvenle set edilebilir.
 *
 * URL'ler:
 *   POST /admin/ajax-price-list-item.php?action=save&list_id=X
 *   POST /admin/ajax-price-list-item.php?action=delete
 */

// Sadece gerekli kütüphaneler
define('B2B_ROOT', dirname(__DIR__));

if (!file_exists(B2B_ROOT . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Sistem henüz kurulmamış.']);
    exit;
}

$cfg = require B2B_ROOT . '/config.php';
if (!($cfg['installed'] ?? false)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Sistem henüz kurulmamış.']);
    exit;
}

define('B2B_URL', rtrim($cfg['site_url'], '/'));
define('B2B_DEBUG', $cfg['debug'] ?? false);

require B2B_ROOT . '/includes/db.php';
require B2B_ROOT . '/includes/auth.php';
require B2B_ROOT . '/includes/functions.php';

b2b_session_start();

// ── Auth kontrolü
if (!isAdmin()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Oturum açmanız gerekli.']);
    exit;
}

// ── POST kontrolü
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}

// ── CSRF
csrfCheck();

$action = $_GET['action'] ?? '';
$listId = (int)($_GET['list_id'] ?? 0);

// ── SAVE ITEM ─────────────────────────────────────────────────
if ($action === 'save') {
    $productId = (int)($_POST['product_id'] ?? 0);

    $parseFloat = function($v) {
        if (!isset($v) || trim($v) === '') return null;
        return (float)str_replace(',', '.', trim($v));
    };
    $parseInt = function($v) {
        if (!isset($v) || trim($v) === '') return null;
        return (int)trim($v);
    };

    // Input'lar KDV DAHİL, DB'ye NET yazılır
    $priceInc  = $parseFloat($_POST['price'] ?? '');
    $disc      = $parseFloat($_POST['discount_percent'] ?? '');
    $adjustInc = $parseFloat($_POST['price_adjust'] ?? '');
    $minQty    = $parseInt($_POST['min_order_qty'] ?? '');

    if (!$listId || !$productId) {
        jsonResponse(['ok' => false, 'msg' => 'Eksik parametre (listId veya productId).']);
    }

    // KDV oranı ile NET'e çevir
    $productInfo = dbRow("SELECT vat_rate FROM b2b_products WHERE id=?", [$productId]);
    if (!$productInfo) {
        jsonResponse(['ok' => false, 'msg' => 'Ürün bulunamadı.']);
    }
    $vatRate = (float)($productInfo['vat_rate'] ?? 20);
    $vatM    = 1 + $vatRate / 100;

    $price  = $priceInc !== null  ? round($priceInc / $vatM, 4)  : null;
    $adjust = $adjustInc !== null ? round($adjustInc / $vatM, 4) : null;

    // Tüm alanlar boş → kayıt sil (liste kuralına dön)
    $allEmpty = ($price === null) && ($disc === null) && ($adjust === null) && ($minQty === null);

    if ($allEmpty) {
        dbExec("DELETE FROM b2b_price_list_items WHERE price_list_id=? AND product_id=?", [$listId, $productId]);
        jsonResponse(['ok' => true, 'msg' => 'Özel fiyat silindi (liste kuralına döndü).']);
    }

    $priceVal = $price ?? 0;

    try {
        dbExec(
            "INSERT INTO b2b_price_list_items (price_list_id,product_id,price,discount_percent,price_adjust,min_order_qty)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE price=VALUES(price),discount_percent=VALUES(discount_percent),price_adjust=VALUES(price_adjust),min_order_qty=VALUES(min_order_qty)",
            [$listId, $productId, $priceVal, $disc, $adjust, $minQty]
        );
    } catch (\Throwable $e) {
        // Eski şema (migration_020 koşmamış) — price_adjust olmadan
        dbExec(
            "INSERT INTO b2b_price_list_items (price_list_id,product_id,price,discount_percent,min_order_qty)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE price=VALUES(price),discount_percent=VALUES(discount_percent),min_order_qty=VALUES(min_order_qty)",
            [$listId, $productId, $priceVal, $disc, $minQty]
        );
    }
    jsonResponse(['ok' => true, 'msg' => 'Kaydedildi.']);
}

// ── DELETE ITEM ───────────────────────────────────────────────
if ($action === 'delete') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    if (!$itemId) {
        jsonResponse(['ok' => false, 'msg' => 'Eksik item_id.']);
    }
    dbExec("DELETE FROM b2b_price_list_items WHERE id=?", [$itemId]);
    jsonResponse(['ok' => true, 'msg' => 'Silindi.']);
}

jsonResponse(['ok' => false, 'msg' => 'Bilinmeyen action.']);
