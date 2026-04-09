<?php
// pages/cart.php — Sepet
requireDealer();
$dealer = currentDealer();

$error   = '';
$success = '';

// ── Sipariş oluştur ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    csrfCheck();
    $notes = trim($_POST['notes'] ?? '');

    $cartItems = dbRows(
        "SELECT c.qty, p.id AS product_id, p.name AS product_name, p.sku AS product_sku,
                p.base_price, p.stock, p.min_order_qty, p.vat_rate, p.unit, p.is_active
         FROM b2b_cart c JOIN b2b_products p ON p.id=c.product_id
         WHERE c.dealer_id=?",
        [$dealer['id']]
    );

    if (empty($cartItems)) {
        $error = 'Sepetiniz boş.';
    } else {
        // Stok ve limit kontrolü
        foreach ($cartItems as $ci) {
            if (!$ci['is_active']) { $error = "'{$ci['product_name']}' artık satışta değil."; break; }
            if ($ci['qty'] > $ci['stock']) { $error = "'{$ci['product_name']}' için yeterli stok yok. Mevcut: {$ci['stock']} {$ci['unit']}"; break; }
            if ($ci['qty'] < $ci['min_order_qty']) { $error = "'{$ci['product_name']}' min. sipariş: {$ci['min_order_qty']} {$ci['unit']}"; break; }
        }
    }

    if (!$error) {
        // Cari limit kontrolü
        $balance = (float)dbVal(
            "SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0",
            [$dealer['id']]
        );
        $subtotal = 0; $vatTotal = 0;
        foreach ($cartItems as $ci) {
            $dp    = dealerPrice($ci['product_id'], (int)$dealer['price_list_id']);
            $price = $dp['price'];
            $vat   = $price * ($ci['vat_rate'] / 100);
            $subtotal += $price * $ci['qty'];
            $vatTotal += $vat * $ci['qty'];
        }
        $grand = $subtotal + $vatTotal;

        if ($dealer['credit_limit'] > 0 && ($balance + $grand) > $dealer['credit_limit']) {
            $error = 'Kredi limitinizi aşıyorsunuz. Açık bakiyeniz: ' . money($balance) . ', Limit: ' . money($dealer['credit_limit']);
        }
    }

    if (!$error) {
        // Otomatik onay mı?
        $autoLimit = (float)setting('order_auto_approve_limit', '0');
        $status    = ($dealer['order_approval'] === 'auto' || ($autoLimit > 0 && $grand <= $autoLimit))
                   ? 'onaylandi' : 'bekliyor';

        $orderNo = setting('order_prefix','SIP') . date('ymd') . str_pad(
            (int)dbVal("SELECT COUNT(*)+1 FROM b2b_orders WHERE DATE(created_at)=CURDATE()"), 3, '0', STR_PAD_LEFT
        );

        $orderId = dbInsertRow('b2b_orders', [
            'dealer_id'      => $dealer['id'],
            'order_no'       => $orderNo,
            'status'         => $status,
            'payment_status' => 'odenmedi',
            'payment_method' => 'acik_hesap',
            'subtotal'       => $subtotal,
            'vat_total'      => $vatTotal,
            'discount_total' => 0,
            'grand_total'    => $grand,
            'notes'          => $notes,
            'price_list_id'  => $dealer['price_list_id'],
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        foreach ($cartItems as $ci) {
            $dp = dealerPrice($ci['product_id'], (int)$dealer['price_list_id']);
            dbInsertRow('b2b_order_items', [
                'order_id'        => $orderId,
                'product_id'      => $ci['product_id'],
                'product_name'    => $ci['product_name'],
                'product_sku'     => $ci['product_sku'],
                'qty'             => $ci['qty'],
                'unit_price'      => $dp['price'],
                'vat_rate'        => $ci['vat_rate'],
                'discount_percent'=> $dp['discount'],
                'line_total'      => $dp['price'] * $ci['qty'] * (1 + $ci['vat_rate']/100),
            ]);
            // Stok düş
            dbExec("UPDATE b2b_products SET stock=stock-? WHERE id=?", [$ci['qty'], $ci['product_id']]);
        }

        // Cari borç — sadece otomatik onaylı siparişlerde
        if ($status === 'onaylandi') {
            $dueDate = date('Y-m-d', strtotime('+' . (int)($dealer['payment_term_days'] ?? 30) . ' days'));
            ledgerAdd($dealer['id'], 'borc', $grand, "Sipariş: $orderNo", 'order', $orderId, $dueDate);
        }

        // Sepeti temizle
        dbExec("DELETE FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);

        // Paraşüt otomatik fatura (onaylandıysa)
        if ($status === 'onaylandi' && function_exists('parasut')) {
            try { parasut()->syncInvoice($orderId); } catch (Exception $e) {}
        }

        auditLog('order_created', 'b2b_orders', $orderId, ['order_no'=>$orderNo]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Sipariş #$orderNo oluşturuldu."];
        header('Location: ?page=orders&action=detail&id='.$orderId.'&ordered=1');
        exit;
    }
}

// ── Sepet içeriği ──────────────────────────────────────────────
$items = dbRows(
    "SELECT c.id AS cart_id, c.qty, c.unit_price,
            p.id AS product_id, p.name, p.sku, p.image, p.stock,
            p.vat_rate, p.min_order_qty, p.max_order_qty, p.unit, p.is_active
     FROM b2b_cart c JOIN b2b_products p ON p.id=c.product_id
     WHERE c.dealer_id=? ORDER BY c.added_at DESC",
    [$dealer['id']]
);

$subtotal = 0; $vatTotal = 0;
foreach ($items as &$it) {
    $dp          = dealerPrice($it['product_id'], (int)$dealer['price_list_id']);
    $it['price'] = $dp['price'];
    $lineNet     = $dp['price'] * $it['qty'];
    $lineVat     = $lineNet * ($it['vat_rate']/100);
    $it['line_total'] = $lineNet + $lineVat;
    $subtotal += $lineNet;
    $vatTotal += $lineVat;
}
unset($it);
$grand = $subtotal + $vatTotal;
?>
<div class="page-body">
<div class="page-header">
  <div><h1 class="page-title">Sepetim</h1></div>
  <a href="?page=products" class="btn btn-secondary">← Alışverişe Devam</a>
</div>

<?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<?php if (empty($items)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:60px 24px;color:var(--text-muted)">
    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:16px;opacity:.4"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
    <p style="font-size:15px;margin-bottom:16px">Sepetiniz boş.</p>
    <a href="?page=products" class="btn btn-primary">Ürünlere Göz At</a>
  </div>
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">

<!-- Ürünler -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title"><?= count($items) ?> Ürün</h3>
    <form method="post" onsubmit="return confirm('Sepet temizlensin mi?')">
      <?= csrfField() ?>
      <input type="hidden" name="clear_cart" value="1">
    </form>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Ürün</th><th>Birim Fiyat</th><th>Miktar</th><th>Toplam</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
      <tr id="row-<?= $it['product_id'] ?>">
        <td>
          <div class="fw-600" style="font-size:13px"><?= h($it['name']) ?></div>
          <div style="font-size:11px;color:var(--text-muted)"><?= h($it['sku']??'') ?> · Stok: <?= $it['stock'] ?> <?= h($it['unit']??'Adet') ?></div>
          <?php if (!$it['is_active']): ?><span class="badge badge-danger">Pasif</span><?php endif; ?>
          <?php if ($it['qty'] > $it['stock']): ?><span class="badge badge-danger">Stok yetersiz</span><?php endif; ?>
        </td>
        <td><?= money($it['price']) ?><div style="font-size:11px;color:var(--text-muted)">+%<?= $it['vat_rate'] ?> KDV</div></td>
        <td>
          <div class="qty-spinner">
            <button type="button" class="qty-btn" onclick="changeQty(<?= $it['product_id'] ?>,-1)">−</button>
            <input class="qty-input" id="q<?= $it['product_id'] ?>" type="number"
                   value="<?= $it['qty'] ?>" min="<?= $it['min_order_qty'] ?>"
                   <?= $it['max_order_qty'] ? 'max="'.$it['max_order_qty'].'"' : '' ?>
                   onchange="updateCart(<?= $it['product_id'] ?>,this.value)">
            <button type="button" class="qty-btn" onclick="changeQty(<?= $it['product_id'] ?>,1)">+</button>
          </div>
        </td>
        <td class="fw-600"><?= money($it['line_total']) ?></td>
        <td>
          <button onclick="removeItem(<?= $it['product_id'] ?>)" class="btn btn-ghost btn-sm" style="color:var(--danger)">×</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Özet -->
<div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Sipariş Özeti</h3></div>
    <div class="card-body">
      <div style="font-size:13px">
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
          <span>Ara Toplam</span><span><?= money($subtotal) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
          <span>KDV</span><span><?= money($vatTotal) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:10px 0;font-size:16px;font-weight:800">
          <span>Toplam</span><span style="color:var(--red)"><?= money($grand) ?></span>
        </div>
      </div>
      <form method="post" style="margin-top:12px">
        <?= csrfField() ?>
        <div class="form-group">
          <label class="form-label">Sipariş Notu</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Opsiyonel not..."></textarea>
        </div>
        <button type="submit" name="checkout" value="1" class="btn btn-primary" style="width:100%;height:44px;font-size:14px">
          Siparişi Tamamla →
        </button>
      </form>
    </div>
  </div>
</div>

</div><!-- /grid -->
<?php endif; ?>
</div>

<script>
const csrfToken = document.querySelector('meta[name=csrf]')?.content || '';

function changeQty(pid, delta) {
    const inp = document.getElementById('q'+pid);
    if (!inp) return;
    const newVal = Math.max(parseInt(inp.min)||1, (parseInt(inp.value)||1) + delta);
    inp.value = newVal;
    updateCart(pid, newVal);
}

function updateCart(pid, qty) {
    fetch('/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=update&product_id=${pid}&qty=${qty}&csrf_token=${encodeURIComponent(csrfToken)}`
    }).then(r => r.json()).then(d => {
        if (d.ok) location.reload();
    });
}

function removeItem(pid) {
    fetch('/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=remove&product_id=${pid}&csrf_token=${encodeURIComponent(csrfToken)}`
    }).then(r => r.json()).then(d => {
        if (d.ok) {
            const row = document.getElementById('row-'+pid);
            if (row) row.remove();
        }
    });
}
</script>
