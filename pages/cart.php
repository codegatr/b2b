<?php
// pages/cart.php — Sepet
requireDealer();
$dealer = currentDealer();

$error = '';

// Checkout POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    csrfCheck();
    $notes = trim($_POST['notes'] ?? '');

    $cartItems = dbRows(
        "SELECT c.*, p.name AS product_name, p.base_price, p.stock, p.min_order_qty, p.tax_rate, p.unit, p.is_active
         FROM b2b_cart c JOIN b2b_products p ON p.id=c.product_id WHERE c.dealer_id=?",
        [$dealer['id']]
    );

    if (empty($cartItems)) { $error = 'Sepetiniz boş.'; }
    else {
        // Doğrulama
        foreach ($cartItems as $ci) {
            if (!$ci['is_active']) { $error = "'{$ci['product_name']}' ürünü artık satışta değil."; break; }
            if ($ci['quantity'] > $ci['stock']) { $error = "'{$ci['product_name']}' için yeterli stok yok. Mevcut: {$ci['stock']} {$ci['unit']}"; break; }
            if ($ci['quantity'] < $ci['min_order_qty']) { $error = "'{$ci['product_name']}' için minimum sipariş miktarı: {$ci['min_order_qty']} {$ci['unit']}"; break; }
        }

        if (!$error) {
            // Bakiye kontrolü
            $balance = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealer['id']]);
            $total   = 0;
            foreach ($cartItems as $ci) {
                $price  = dealerPrice($ci['product_id'], $dealer['price_list_id']);
                $total += $price * $ci['quantity'] * (1 + $ci['tax_rate']/100);
            }

            if ($dealer['credit_limit'] > 0 && ($balance + $total) > $dealer['credit_limit']) {
                $error = 'Kredi limitinizi aşıyorsunuz. Açık bakiyeniz: ' . money($balance) . ', Limit: ' . money($dealer['credit_limit']);
            } else {
                // Sipariş oluştur
                $prefix = setting('order_prefix', 'SIP');
                $orderNo = $prefix . '-' . date('Ymd') . '-' . str_pad(dbVal("SELECT COUNT(*)+1 FROM b2b_orders WHERE DATE(created_at)=CURDATE()", []), 4, '0', STR_PAD_LEFT);
                $autoApprove = $dealer['order_approval'] === 'auto';
                $autoLimit   = floatval(setting('order_auto_approve_limit', 0));
                if ($autoApprove && $autoLimit > 0 && $total > $autoLimit) $autoApprove = false;

                $orderId = dbInsertRow('b2b_orders', [
                    'dealer_id'      => $dealer['id'],
                    'order_number'   => $orderNo,
                    'status'         => $autoApprove ? 'onaylandi' : 'bekliyor',
                    'payment_status' => 'bekliyor',
                    'total_amount'   => $total,
                    'notes'          => $notes,
                    'due_date'       => date('Y-m-d', strtotime("+{$dealer['payment_term_days']} days")),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);

                foreach ($cartItems as $ci) {
                    $unitPrice = dealerPrice($ci['product_id'], $dealer['price_list_id']);
                    dbInsertRow('b2b_order_items', [
                        'order_id'     => $orderId,
                        'product_id'   => $ci['product_id'],
                        'product_name' => $ci['product_name'],
                        'unit_price'   => $unitPrice,
                        'quantity'     => $ci['quantity'],
                        'tax_rate'     => $ci['tax_rate'],
                        'unit'         => $ci['unit'],
                    ]);
                }

                if ($autoApprove) {
                    // Stok düş & cari yaz
                    foreach ($cartItems as $ci) { stockUpdate($ci['product_id'], -$ci['quantity'], 'siparis', $orderId); }
                    ledgerAdd($dealer['id'], 'borc', $total, "Sipariş: $orderNo", date('Y-m-d', strtotime("+{$dealer['payment_term_days']} days")), $orderId, 'order');
                    try { parasut()->createInvoice($orderId); } catch (Exception $e) {}
                    notifyAdmin('Yeni Sipariş (Otomatik Onay)', "$orderNo — {$dealer['company_name']} — ".money($total), 'order', $orderId);
                } else {
                    notifyAdmin('Yeni Sipariş Onay Bekliyor', "$orderNo — {$dealer['company_name']} — ".money($total), 'order', $orderId);
                }

                // Sepeti temizle
                dbExec("DELETE FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);

                header("Location: ?page=orders&action=detail&id=$orderId&placed=1"); exit;
            }
        }
    }
}

// Sepet içeriği
$items = dbRows(
    "SELECT c.*, p.name AS product_name, p.image, p.unit, p.tax_rate, p.stock, p.min_order_qty, p.is_active
     FROM b2b_cart c JOIN b2b_products p ON p.id=c.product_id WHERE c.dealer_id=? ORDER BY c.added_at",
    [$dealer['id']]
);

$subtotal = 0; $tax = 0;
foreach ($items as $it) {
    $price     = dealerPrice($it['product_id'], $dealer['price_list_id']);
    $lineTotal = $price * $it['quantity'];
    $lineTax   = $lineTotal * ($it['tax_rate']/100);
    $subtotal += $lineTotal;
    $tax      += $lineTax;
}
$total = $subtotal + $tax;
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Sepetim</h1>
        <p class="page-sub"><?= count($items) ?> kalem</p>
    </div>
    <a href="?page=products" class="btn btn-ghost">← Alışverişe Devam</a>
</div>

<?php if ($error): ?><div class="alert alert-error mb-4"><?= h($error) ?></div><?php endif; ?>

<?php if (empty($items)): ?>
<div class="card" style="text-align:center;padding:64px 32px">
    <div style="font-size:56px;margin-bottom:16px">🛒</div>
    <h2 style="font-size:18px;margin-bottom:8px">Sepetiniz Boş</h2>
    <p style="color:var(--text-muted);margin-bottom:24px">Ürün kataloğundan sipariş vermek için ürün ekleyin.</p>
    <a href="?page=products" class="btn btn-primary">Ürünleri İncele</a>
</div>

<?php else: ?>
<div class="grid grid-cols-3 gap-6" style="align-items:start">
    <!-- Ürünler -->
    <div class="col-span-2">
    <div class="card">
    <table class="table">
        <thead><tr><th>Ürün</th><th>Birim Fiyat</th><th>Miktar</th><th>Toplam</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it):
            $price     = dealerPrice($it['product_id'], $dealer['price_list_id']);
            $lineTotal = $price * $it['quantity'];
        ?>
        <tr id="cart-row-<?= $it['product_id'] ?>">
            <td>
                <div style="display:flex;align-items:center;gap:12px">
                    <?php if ($it['image']): ?>
                    <img src="<?= h($it['image']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:8px;flex-shrink:0">
                    <?php endif; ?>
                    <div>
                        <div class="font-medium"><?= h($it['product_name']) ?></div>
                        <?php if (!$it['is_active'] || $it['quantity'] > $it['stock']): ?>
                        <div class="text-danger text-xs mt-1">
                            <?= !$it['is_active'] ? '⚠ Stokta yok / pasif' : "⚠ Stok yetersiz (Mevcut: {$it['stock']})" ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td><?= money($price) ?> / <?= h($it['unit']) ?></td>
            <td>
                <div class="qty-control">
                    <button class="qty-btn" onclick="Cart.change(<?= $it['product_id'] ?>, -1)">−</button>
                    <input type="number" class="qty-input" id="qty-<?= $it['product_id'] ?>" value="<?= $it['quantity'] ?>"
                           min="<?= $it['min_order_qty'] ?>" max="<?= $it['stock'] ?>"
                           onchange="Cart.setQty(<?= $it['product_id'] ?>, this.value)" style="width:60px">
                    <button class="qty-btn" onclick="Cart.change(<?= $it['product_id'] ?>, 1)">+</button>
                </div>
            </td>
            <td class="font-medium"><?= money($lineTotal) ?></td>
            <td>
                <button class="btn btn-xs btn-ghost text-danger" onclick="Cart.remove(<?= $it['product_id'] ?>)">✕</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <!-- Özet & Checkout -->
    <div>
    <div class="card">
        <div class="card-header"><h3>Sipariş Özeti</h3></div>
        <div class="card-body">
            <dl class="summary-list">
                <dt>Ara Toplam</dt><dd><?= money($subtotal) ?></dd>
                <dt>KDV</dt><dd><?= money($tax) ?></dd>
                <dt class="font-bold text-lg">Toplam</dt><dd class="font-bold text-lg"><?= money($total) ?></dd>
            </dl>

            <?php
            $balance = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealer['id']]);
            if ($dealer['credit_limit'] > 0):
                $available = $dealer['credit_limit'] - $balance;
            ?>
            <div class="mt-4 p-3 bg-muted rounded text-sm">
                <div>Kredi Limiti: <?= money($dealer['credit_limit']) ?></div>
                <div>Kullanılan: <?= money($balance) ?></div>
                <div class="font-medium <?= $available<$total?'text-danger':'' ?>">Kullanılabilir: <?= money($available) ?></div>
            </div>
            <?php endif; ?>

            <form method="post" class="mt-4">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>Sipariş Notu (isteğe bağlı)</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Teslimat adresi, özel istek…"></textarea>
                </div>
                <button type="submit" name="checkout" value="1" class="btn btn-primary w-full btn-lg">
                    ✓ Siparişi Ver (<?= money($total) ?>)
                </button>
            </form>
        </div>
    </div>

    <!-- Ödeme Bilgisi -->
    <?php $bankAccounts = setting('bank_accounts'); if ($bankAccounts): ?>
    <div class="card mt-4">
        <div class="card-header"><h3>Ödeme Bilgisi</h3></div>
        <div class="card-body">
            <p class="text-sm text-muted mb-3">Siparişinizi verdikten sonra aşağıdaki hesaba havale yapabilirsiniz:</p>
            <pre class="text-sm" style="white-space:pre-wrap;font-family:inherit"><?= h($bankAccounts) ?></pre>
        </div>
    </div>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>
