<?php
// pages/cart.php — Sepet

// ── DEBUG: Tüm akış doğrulanana kadar kalacak ──────────────────
ini_set('display_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo '<div style="background:#fee2e2;border:2px solid #dc2626;color:#991b1b;padding:16px;margin:16px;border-radius:8px;font-family:monospace;font-size:13px;line-height:1.6">';
        echo '<strong style="font-size:15px">⚠️ PHP FATAL ERROR (cart.php debug)</strong><br><br>';
        echo '<strong>Mesaj:</strong> ' . htmlspecialchars($err['message']) . '<br>';
        echo '<strong>Dosya:</strong> ' . htmlspecialchars($err['file']) . '<br>';
        echo '<strong>Satır:</strong> ' . (int)$err['line'];
        echo '</div>';
    }
});
// ────────────────────────────────────────────────────────────────

requireDealer();
$dealer = currentDealer();

$error   = '';
$success = '';

// Bayi ödeme izinleri (admin → Bayiler → Düzenle)
$allowedPayMethods = array_filter(explode(',', $dealer['payment_methods'] ?? 'havale,kredi_karti'));
$cardEnabled = in_array('kredi_karti', $allowedPayMethods, true)
            && function_exists('rubikpara')
            && rubikpara()->ayarliMi();
$bankEnabled = in_array('havale', $allowedPayMethods, true);

// ── Sipariş oluştur ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    csrfCheck();
    $notes = trim($_POST['notes'] ?? '');

    // Ödeme yöntemi seçimi (modal'dan veya default)
    $methodChoice = $_POST['payment_method_choice'] ?? '';
    if ($methodChoice === 'kredi_karti' && !$cardEnabled) $methodChoice = '';
    if ($methodChoice === 'havale_eft' && !$bankEnabled) $methodChoice = '';
    if ($methodChoice === '') {
        // Bayi seçim yapmadıysa veya geçersizse fallback'i belirle
        if ($cardEnabled && $bankEnabled) {
            $error = 'Lütfen bir ödeme yöntemi seçin.';
        } else {
            $methodChoice = $cardEnabled ? 'kredi_karti'
                          : ($bankEnabled ? 'havale_eft' : 'acik_hesap');
        }
    }

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
        // ── KART AKIŞI: order DB'ye yazılmaz, sepet snapshot session'a ──
        // Bayi 3DS başarılı olunca callback'te order oluşturulur. Vazgeçer
        // veya 3DS fail olursa hiçbir kayıt kalmaz, stok düşmez, sepet dolu.
        if ($methodChoice === 'kredi_karti') {
            $cartSnap = [];
            foreach ($cartItems as $ci) {
                $dp = dealerPrice($ci['product_id'], (int)$dealer['price_list_id']);
                $cartSnap[] = [
                    'product_id'        => (int)$ci['product_id'],
                    'product_name'      => $ci['product_name'],
                    'product_sku'       => $ci['product_sku'],
                    'qty'               => (int)$ci['qty'],
                    'unit_price'        => (float)$dp['price'],
                    'vat_rate'          => (float)$ci['vat_rate'],
                    'discount_percent'  => (float)$dp['discount'],
                    'line_total'        => $dp['price'] * $ci['qty'] * (1 + $ci['vat_rate']/100),
                ];
            }
            $_SESSION['pending_card'][(int)$dealer['id']] = [
                'items'         => $cartSnap,
                'subtotal'      => $subtotal,
                'vat_total'     => $vatTotal,
                'grand_total'   => $grand,
                'notes'         => $notes,
                'price_list_id' => $dealer['price_list_id'],
                'created_at'    => time(),
            ];

            // Sepet temizleme YOK, stok düşürme YOK — bayi vazgeçerse hiçbir şey değişmemiş olsun
            $redirectUrl = '?page=payment-card&pending=1';
            echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '">';
            echo '<script>window.location.replace(' . json_encode($redirectUrl) . ');</script>';
            echo '</head><body style="font-family:system-ui;padding:40px;text-align:center">';
            echo '<p>Ödeme sayfasına yönlendiriliyorsunuz...</p>';
            echo '<p><a href="' . htmlspecialchars($redirectUrl) . '">Otomatik yönlendirme olmazsa buraya tıklayın</a></p>';
            echo '</body></html>';
            exit;
        }

        // ── HAVALE / DİĞER: mevcut akış (order DB'ye yazılır) ──
        // Otomatik onay mı?
        $autoLimit = (float)setting('order_auto_approve_limit', '0');
        $status    = ($dealer['order_approval'] === 'auto' || ($autoLimit > 0 && $grand <= $autoLimit))
                   ? 'onaylandi' : 'bekliyor';

        // Order numarası üretimi — MAX bazlı + retry loop:
        // Eski yöntem (COUNT+1) silinen siparişlerde veya race condition'da
        // duplicate üretiyordu (1062 SQLSTATE 23000). Şimdi bugünün
        // numerik suffix'lerinden en büyüğünü buluyoruz, +1 ekliyoruz.
        // Yine de aynı anda iki bayinin sipariş vermesi durumuna karşı
        // 10 deneme yapıyoruz, her seferinde +1.
        $orderPrefix = setting('order_prefix', 'SIP') . date('ymd');
        $maxSuffix   = (int)dbVal(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, ?) AS UNSIGNED)), 0)
             FROM b2b_orders
             WHERE order_no LIKE CONCAT(?, '%')",
            [strlen($orderPrefix) + 1, $orderPrefix]
        );
        $orderId = null;
        $orderNo = '';
        for ($try = 0; $try < 10; $try++) {
            $orderNo = $orderPrefix . str_pad($maxSuffix + 1 + $try, 3, '0', STR_PAD_LEFT);
            try {
                $orderId = dbInsertRow('b2b_orders', [
                    'dealer_id'      => $dealer['id'],
                    'order_no'       => $orderNo,
                    'status'         => $status,
                    'payment_status' => 'odenmedi',
                    'payment_method' => $methodChoice,
                    'subtotal'       => $subtotal,
                    'vat_total'      => $vatTotal,
                    'discount_total' => 0,
                    'grand_total'    => $grand,
                    'notes'          => $notes,
                    'price_list_id'  => $dealer['price_list_id'],
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                break; // başarılı
            } catch (\PDOException $e) {
                // 1062 = Duplicate entry → bir sonraki suffix'i dene
                if (strpos($e->getMessage(), 'Duplicate') !== false || $e->getCode() === '23000') {
                    continue;
                }
                throw $e; // başka bir hata ise yukarı fırlat
            }
        }
        if (!$orderId) {
            throw new \RuntimeException(
                'Sipariş numarası üretilemedi (10 deneme sonrası hala duplicate). ' .
                'order_prefix=' . $orderPrefix . ' maxSuffix=' . $maxSuffix
            );
        }

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

        // Sepet temizle
        dbExec("DELETE FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);

        // Paraşüt otomatik fatura (onaylandıysa)
        if ($status === 'onaylandi' && function_exists('parasut')) {
            try { parasut()->syncInvoice($orderId); } catch (\Throwable $e) {}
        }

        auditLog('order_created', 'b2b_orders', $orderId, ['order_no'=>$orderNo, 'method'=>$methodChoice]);
        // Bayiye sipariş alındı / onaylandı mailı (status'e göre uygun şablon)
        sendOrderStatusEmail($orderId, $status === 'onaylandi' ? 'onaylandi' : 'bekliyor');
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Sipariş #$orderNo oluşturuldu."];

        $redirectUrl = '?page=orders&action=detail&id=' . $orderId . '&ordered=1';

        // index.php layout'u zaten output başlattığı için header() yerine
        // çift fallback kullan: JS + meta refresh + manuel link.
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '">';
        echo '<script>window.location.replace(' . json_encode($redirectUrl) . ');</script>';
        echo '</head><body style="font-family:system-ui;padding:40px;text-align:center">';
        echo '<p>Sipariş #' . htmlspecialchars($orderNo) . ' oluşturuldu, yönlendiriliyorsunuz...</p>';
        echo '<p><a href="' . htmlspecialchars($redirectUrl) . '">Otomatik yönlendirme olmazsa buraya tıklayın</a></p>';
        echo '</body></html>';
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
      <?php $bayiPriceMode = setting('price_input_includes_vat', '0') === '1' ? 'gross' : 'net'; ?>
      <thead><tr><th>Ürün</th><th>Birim Fiyat<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">(<?= $bayiPriceMode === 'gross' ? 'KDV Dahil' : 'KDV Hariç' ?>)</span></th><th>Miktar</th><th>Toplam<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">(KDV Dahil)</span></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
      <tr id="row-<?= $it['product_id'] ?>">
        <td>
          <div class="fw-600" style="font-size:13px"><?= h($it['name']) ?></div>
          <div style="font-size:11px;color:var(--text-muted)"><?= h($it['sku']??'') ?> · Stok: <?= $it['stock'] ?> <?= h($it['unit']??'Adet') ?></div>
          <?php if (!$it['is_active']): ?><span class="badge badge-danger">Pasif</span><?php endif; ?>
          <?php if ($it['qty'] > $it['stock']): ?><span class="badge badge-danger">Stok yetersiz</span><?php endif; ?>
        </td>
        <td>
          <?php
          $unitDisplay = $bayiPriceMode === 'gross'
              ? $it['price'] * (1 + $it['vat_rate']/100)
              : $it['price'];
          ?>
          <?= money($unitDisplay) ?>
          <div style="font-size:11px;color:var(--text-muted)"><?= $bayiPriceMode === 'gross' ? 'KDV dahil' : 'KDV hariç · +%'.$it['vat_rate'] ?></div>
        </td>
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
        <td class="fw-600">
          <?= money($it['line_total']) ?>
          <?php
          $rowNet  = $it['price'] * $it['qty'];
          $rowVat  = $rowNet * ($it['vat_rate']/100);
          ?>
          <div style="font-size:10px;color:var(--text-muted);font-weight:400;margin-top:2px">
            <?= money($rowNet) ?> + <?= money($rowVat) ?> KDV
          </div>
        </td>
        <td>
          <button type="button" onclick="removeItem(<?= $it['product_id'] ?>)"
                  title="Bu ürünü sepetten kaldır"
                  style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px;background:#fff;border:1px solid #fecaca;color:#dc2626;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;transition:.15s"
                  onmouseover="this.style.background='#fef2f2';this.style.borderColor='#dc2626'"
                  onmouseout="this.style.background='#fff';this.style.borderColor='#fecaca'">
            🗑 Sil
          </button>
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
      <form method="post" id="checkoutForm" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="checkout" value="1">

        <div class="form-group">
          <label class="form-label">Sipariş Notu</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Opsiyonel not..."></textarea>
        </div>

        <?php if ($cardEnabled || $bankEnabled): ?>
        <div class="form-group">
          <label class="form-label" style="margin-bottom:8px">Ödeme Yöntemi</label>

          <?php if ($bankEnabled): ?>
          <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;margin-bottom:8px;border:2px solid var(--border);border-radius:10px;cursor:pointer;background:#fff" onclick="this.querySelector('input').checked=true">
            <input type="radio" name="payment_method_choice" value="havale_eft" <?= $cardEnabled ? '' : 'checked' ?> required style="margin-top:3px;cursor:pointer">
            <div style="flex:1">
              <div style="font-weight:700;font-size:13px;color:var(--text)">🏦 Havale / EFT</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Banka hesabımıza havale yapın, dekontu yükleyin.</div>
            </div>
          </label>
          <?php endif; ?>

          <?php if ($cardEnabled): ?>
          <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;margin-bottom:8px;border:2px solid var(--border);border-radius:10px;cursor:pointer;background:#fff" onclick="this.querySelector('input').checked=true">
            <input type="radio" name="payment_method_choice" value="kredi_karti" <?= !$bankEnabled ? 'checked' : '' ?> required style="margin-top:3px;cursor:pointer">
            <div style="flex:1">
              <div style="font-weight:700;font-size:13px;color:var(--text)">💳 Kredi Kartı (3D Secure)</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Anında ödeme. Tek çekim veya taksit.</div>
            </div>
          </label>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" style="width:100%;height:44px;font-size:14px">
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

// Radio buton seçilince kart border'ını güncelle (görsel feedback)
document.querySelectorAll('input[type=radio][name=payment_method_choice]').forEach(radio => {
  const updateBorders = () => {
    document.querySelectorAll('input[type=radio][name=payment_method_choice]').forEach(r => {
      const lbl = r.closest('label');
      if (lbl) lbl.style.borderColor = r.checked ? 'var(--primary)' : 'var(--border)';
    });
  };
  radio.addEventListener('change', updateBorders);
  updateBorders(); // initial
});

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
