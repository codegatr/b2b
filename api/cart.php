<?php
// api/cart.php — Sepet AJAX API
define('B2B_ROOT', dirname(__DIR__));
require B2B_ROOT . '/config.php';
$cfg = require B2B_ROOT . '/config.php';
define('B2B_URL', rtrim($cfg['site_url'], '/'));
define('B2B_DEBUG', $cfg['debug'] ?? false);
require B2B_ROOT . '/includes/db.php';
require B2B_ROOT . '/includes/auth.php';
require B2B_ROOT . '/includes/functions.php';

b2b_session_start();
header('Content-Type: application/json; charset=utf-8');

function out(bool $ok, string $msg = '', array $extra = []): never {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isDealer()) out(false, 'Oturum gerekli');

csrfCheck();
$dealerId = (int)$_SESSION['dealer_id'];
$action   = trim($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {

    // ── Sepete ekle / miktarı güncelle ──────────────────────────
    case 'add':
    case 'update':
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty'] ?? $_POST['quantity'] ?? 1));

        $product = dbRow("SELECT * FROM b2b_products WHERE id=? AND is_active=1", [$pid]);
        if (!$product) out(false, 'Ürün bulunamadı.');

        $minQty = (int)($product['min_order_qty'] ?? 1);
        if ($qty < $minQty) out(false, "Minimum sipariş miktarı: $minQty");
        if ($product['stock'] > 0 && $qty > $product['stock']) {
            out(false, "Yetersiz stok. Mevcut: {$product['stock']} {$product['unit']}");
        }

        // UPSERT
        $existing = dbVal("SELECT id FROM b2b_cart WHERE dealer_id=? AND product_id=?", [$dealerId, $pid]);
        if ($existing) {
            dbExec("UPDATE b2b_cart SET qty=?, unit_price=NULL WHERE dealer_id=? AND product_id=?",
                   [$qty, $dealerId, $pid]);
        } else {
            dbInsertRow('b2b_cart', [
                'dealer_id'  => $dealerId,
                'product_id' => $pid,
                'qty'        => $qty,
                'added_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $total = (int)dbVal("SELECT COALESCE(SUM(qty),0) FROM b2b_cart WHERE dealer_id=?", [$dealerId]);
        out(true, 'Eklendi', ['cart_total' => $total]);

    // ── Kaldır ──────────────────────────────────────────────────
    case 'remove':
        $pid = (int)($_POST['product_id'] ?? 0);
        dbExec("DELETE FROM b2b_cart WHERE dealer_id=? AND product_id=?", [$dealerId, $pid]);
        $total = (int)dbVal("SELECT COALESCE(SUM(qty),0) FROM b2b_cart WHERE dealer_id=?", [$dealerId]);
        out(true, 'Kaldırıldı', ['cart_total' => $total]);

    // ── Sepeti temizle ──────────────────────────────────────────
    case 'clear':
        dbExec("DELETE FROM b2b_cart WHERE dealer_id=?", [$dealerId]);
        out(true, 'Sepet temizlendi', ['cart_total' => 0]);

    // ── Sepet sayısı ────────────────────────────────────────────
    case 'count':
        $total = (int)dbVal("SELECT COALESCE(SUM(qty),0) FROM b2b_cart WHERE dealer_id=?", [$dealerId]);
        out(true, '', ['cart_total' => $total]);

    default:
        out(false, 'Geçersiz işlem.');
}
