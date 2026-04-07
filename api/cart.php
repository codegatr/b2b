<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Sadece bayi erişimi
if (!isDealer()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Oturum gerekli']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$db     = Database::getInstance();
$dealer = currentDealer();

function cartResponse(bool $ok, string $msg = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

function cartCount(): int
{
    $db     = Database::getInstance();
    $dealer = currentDealer();
    $row    = $db->fetch(
        "SELECT COALESCE(SUM(quantity),0) AS cnt FROM b2b_cart WHERE dealer_id = ?",
        [$dealer['id']]
    );
    return (int)($row['cnt'] ?? 0);
}

function cartTotal(): float
{
    $db     = Database::getInstance();
    $dealer = currentDealer();
    $items  = $db->fetchAll(
        "SELECT c.quantity, c.price FROM b2b_cart c WHERE c.dealer_id = ?",
        [$dealer['id']]
    );
    $total = 0.0;
    foreach ($items as $i) {
        $total += (float)$i['price'] * (int)$i['quantity'];
    }
    return $total;
}

switch ($action) {

    // ─── Sepete ekle ─────────────────────────────────────────────────────────
    case 'add':
        if (!isset($_POST['product_id'], $_POST['quantity'])) {
            cartResponse(false, 'Eksik parametre');
        }
        $productId = (int)$_POST['product_id'];
        $qty       = max(1, (int)$_POST['quantity']);

        // Ürün var mı & aktif mi?
        $product = $db->fetch(
            "SELECT * FROM b2b_products WHERE id = ? AND status = 'active'",
            [$productId]
        );
        if (!$product) cartResponse(false, 'Ürün bulunamadı');

        // Minimum sipariş miktarı kontrolü
        $minQty = (int)($product['min_order_qty'] ?? 1);
        if ($qty < $minQty) {
            cartResponse(false, "Minimum sipariş miktarı: {$minQty} adet");
        }

        // Stok kontrolü
        if ((int)$product['stock'] < $qty) {
            cartResponse(false, 'Yetersiz stok (Mevcut: ' . $product['stock'] . ' adet)');
        }

        // Bayiye özel fiyat
        $price = dealerPrice($productId, (int)$dealer['price_list_id']);

        // Sepette varsa güncelle, yoksa ekle
        $existing = $db->fetch(
            "SELECT id, quantity FROM b2b_cart WHERE dealer_id = ? AND product_id = ?",
            [$dealer['id'], $productId]
        );

        if ($existing) {
            $newQty = (int)$existing['quantity'] + $qty;
            // Stok kontrolü (toplam)
            if ((int)$product['stock'] < $newQty) {
                $newQty = (int)$product['stock'];
            }
            $db->query(
                "UPDATE b2b_cart SET quantity = ?, price = ?, updated_at = NOW() WHERE id = ?",
                [$newQty, $price, $existing['id']]
            );
        } else {
            $db->query(
                "INSERT INTO b2b_cart (dealer_id, product_id, quantity, price, added_at) VALUES (?, ?, ?, ?, NOW())",
                [$dealer['id'], $productId, $qty, $price]
            );
        }

        cartResponse(true, 'Sepete eklendi', [
            'count' => cartCount(),
            'total' => cartTotal(),
        ]);

    // ─── Miktar güncelle ─────────────────────────────────────────────────────
    case 'update':
        if (!isset($_POST['product_id'], $_POST['quantity'])) {
            cartResponse(false, 'Eksik parametre');
        }
        $productId = (int)$_POST['product_id'];
        $qty       = (int)$_POST['quantity'];

        if ($qty <= 0) {
            // Sıfır = sil
            $db->query(
                "DELETE FROM b2b_cart WHERE dealer_id = ? AND product_id = ?",
                [$dealer['id'], $productId]
            );
            cartResponse(true, 'Ürün sepetten kaldırıldı', [
                'count'   => cartCount(),
                'total'   => cartTotal(),
                'removed' => true,
            ]);
        }

        $product = $db->fetch(
            "SELECT stock, min_order_qty FROM b2b_products WHERE id = ? AND status = 'active'",
            [$productId]
        );
        if (!$product) cartResponse(false, 'Ürün bulunamadı');

        $minQty = (int)($product['min_order_qty'] ?? 1);
        if ($qty < $minQty) {
            cartResponse(false, "Minimum sipariş miktarı: {$minQty} adet");
        }
        if ((int)$product['stock'] < $qty) {
            cartResponse(false, 'Yetersiz stok');
        }

        $price = dealerPrice($productId, (int)$dealer['price_list_id']);
        $db->query(
            "UPDATE b2b_cart SET quantity = ?, price = ?, updated_at = NOW()
             WHERE dealer_id = ? AND product_id = ?",
            [$qty, $price, $dealer['id'], $productId]
        );

        // Kalem toplamını da döndür
        $lineTotal = $qty * $price;
        cartResponse(true, 'Güncellendi', [
            'count'      => cartCount(),
            'total'      => cartTotal(),
            'line_total' => $lineTotal,
        ]);

    // ─── Sepetten kaldır ─────────────────────────────────────────────────────
    case 'remove':
        if (!isset($_POST['product_id'])) {
            cartResponse(false, 'Eksik parametre');
        }
        $productId = (int)$_POST['product_id'];
        $db->query(
            "DELETE FROM b2b_cart WHERE dealer_id = ? AND product_id = ?",
            [$dealer['id'], $productId]
        );
        cartResponse(true, 'Ürün kaldırıldı', [
            'count' => cartCount(),
            'total' => cartTotal(),
        ]);

    // ─── Sepeti temizle ──────────────────────────────────────────────────────
    case 'clear':
        $db->query("DELETE FROM b2b_cart WHERE dealer_id = ?", [$dealer['id']]);
        cartResponse(true, 'Sepet temizlendi', ['count' => 0, 'total' => 0]);

    // ─── Sepet özeti (badge için) ─────────────────────────────────────────────
    case 'count':
        cartResponse(true, '', [
            'count' => cartCount(),
            'total' => cartTotal(),
        ]);

    // ─── Sepet içeriği ────────────────────────────────────────────────────────
    case 'get':
        $items = $db->fetchAll(
            "SELECT c.product_id, c.quantity, c.price,
                    p.name, p.sku, p.stock, p.min_order_qty, p.image
             FROM b2b_cart c
             JOIN b2b_products p ON p.id = c.product_id
             WHERE c.dealer_id = ?
             ORDER BY c.added_at ASC",
            [$dealer['id']]
        );
        $total = 0.0;
        foreach ($items as &$item) {
            $item['line_total'] = round((float)$item['price'] * (int)$item['quantity'], 2);
            $total += $item['line_total'];
        }
        unset($item);
        cartResponse(true, '', [
            'items' => $items,
            'count' => cartCount(),
            'total' => $total,
        ]);

    default:
        http_response_code(400);
        cartResponse(false, 'Geçersiz işlem');
}
