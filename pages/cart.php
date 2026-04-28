<?php
// pages/cart.php — Sepet
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
            'payment_method' => $methodChoice,
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

        // Kredi kartı seçildiyse direkt ödeme sayfasına, aksi halde sipariş detayına
        if ($methodChoice === 'kredi_karti') {
            header('Location: ?page=payment-card&order_id=' . $orderId);
        } else {
            header('Location: ?page=orders&action=detail&id=' . $orderId . '&ordered=1');
        }
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
      <form method="post" id="checkoutForm" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="payment_method_choice" id="paymentMethodChoice" value="">
        <input type="hidden" name="checkout" value="1">
        <div class="form-group">
          <label class="form-label">Sipariş Notu</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Opsiyonel not..."></textarea>
        </div>
        <button type="submit" id="checkoutBtn" class="btn btn-primary" style="width:100%;height:44px;font-size:14px">
          Siparişi Tamamla →
        </button>
      </form>
    </div>
  </div>
</div>

</div><!-- /grid -->

<!-- ── Ödeme Yöntemi Seçim Modalı ───────────────────────────────── -->
<?php if ($cardEnabled || $bankEnabled): ?>
<?php $bankAccounts = setting('bank_accounts', ''); ?>
<div id="payMethodModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:14px;max-width:560px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow:auto">

    <!-- ─── VIEW 1: Yöntem seç ────────────────────────────────────── -->
    <div id="payMethodView1" <?= ($cardEnabled && $bankEnabled) ? '' : 'style="display:none"' ?>>
      <h3 style="margin:0 0 6px;font-size:18px;font-weight:700">Ödeme Yöntemi Seçin</h3>
      <p style="margin:0 0 18px;font-size:13px;color:var(--text-muted)">
        Sipariş tutarı: <strong style="color:var(--red)"><?= money($grand) ?></strong>
      </p>

      <?php if ($bankEnabled): ?>
      <button type="button" data-method="havale_eft" class="payMethodOpt" style="display:block;width:100%;text-align:left;padding:14px 16px;margin-bottom:10px;background:#fff;border:2px solid var(--border);border-radius:10px;cursor:pointer;transition:.15s">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="font-size:24px">🏦</div>
          <div style="flex:1">
            <div style="font-weight:700;font-size:14px">Havale / EFT</div>
            <div style="font-size:12px;color:var(--text-muted)">Banka hesabımıza havale yapın, sonra dekont yükleyin.</div>
          </div>
        </div>
      </button>
      <?php endif; ?>

      <?php if ($cardEnabled): ?>
      <button type="button" data-method="kredi_karti" class="payMethodOpt" style="display:block;width:100%;text-align:left;padding:14px 16px;margin-bottom:10px;background:#fff;border:2px solid var(--border);border-radius:10px;cursor:pointer;transition:.15s">
        <div style="display:flex;align-items:center;gap:12px">
          <div style="font-size:24px">💳</div>
          <div style="flex:1">
            <div style="font-weight:700;font-size:14px">Kredi Kartı (3D Secure)</div>
            <div style="font-size:12px;color:var(--text-muted)">Anında ödeme. Tek çekim veya taksit seçenekleri.</div>
          </div>
        </div>
      </button>
      <?php endif; ?>

      <button type="button" id="payMethodCancel" style="display:block;width:100%;padding:10px;margin-top:8px;background:transparent;border:none;color:var(--text-muted);font-size:13px;cursor:pointer">
        İptal
      </button>
    </div>

    <!-- ─── VIEW 2: Havale hesap bilgileri ────────────────────────── -->
    <?php if ($bankEnabled): ?>
    <div id="payMethodView2" style="display:none">
      <h3 style="margin:0 0 6px;font-size:18px;font-weight:700">Banka Hesap Bilgileri</h3>
      <p style="margin:0 0 16px;font-size:13px;color:var(--text-muted)">
        Sipariş tutarı: <strong style="color:var(--red)"><?= money($grand) ?></strong>
        — Aşağıdaki hesap(lar)dan ödemenizi yapın, sonra <strong>Ödemelerim</strong> sayfasından dekont yükleyin.
      </p>

      <?php if (trim($bankAccounts) !== ''): ?>
      <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px;font-family:ui-monospace,'SF Mono',Consolas,monospace;font-size:13px;line-height:1.7;white-space:pre-wrap;color:var(--text);max-height:280px;overflow:auto"><?= h($bankAccounts) ?></div>
      <?php else: ?>
      <div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:10px;padding:12px;margin-bottom:14px;font-size:13px;color:#92400e">
        ⚠️ Banka hesap bilgileri henüz tanımlanmamış. Yönetici ile iletişime geçin.
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:8px">
        <button type="button" id="payMethodBack" style="flex:0 0 auto;padding:11px 16px;background:#fff;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;color:var(--text-muted)">
          ← Geri
        </button>
        <button type="button" id="payMethodConfirmBank" class="btn btn-primary" style="flex:1;height:42px;font-size:13px;font-weight:700">
          Hesap Bilgilerini Aldım, Siparişi Oluştur
        </button>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<?php endif; ?>
</div>

<script>
const csrfToken = document.querySelector('meta[name=csrf]')?.content || '';

// ── Ödeme yöntemi seçim akışı ──────────────────────────────────
const checkoutForm   = document.getElementById('checkoutForm');
const checkoutBtn    = document.getElementById('checkoutBtn');
const payMethodInput = document.getElementById('paymentMethodChoice');
const payMethodModal = document.getElementById('payMethodModal');
const view1          = document.getElementById('payMethodView1');
const view2          = document.getElementById('payMethodView2');
const CARD_ENABLED   = <?= $cardEnabled ? 'true' : 'false' ?>;
const BANK_ENABLED   = <?= $bankEnabled ? 'true' : 'false' ?>;

function openModal() {
  if (!payMethodModal) return;
  payMethodModal.style.display = 'flex';
  if (CARD_ENABLED && BANK_ENABLED) {
    if (view1) view1.style.display = 'block';
    if (view2) view2.style.display = 'none';
  } else if (BANK_ENABLED && view2) {
    if (view1) view1.style.display = 'none';
    view2.style.display = 'block';
  }
}
function closeModal() { if (payMethodModal) payMethodModal.style.display = 'none'; }

function submitWithMethod(method) {
  if (payMethodInput) payMethodInput.value = method;
  if (checkoutForm) checkoutForm.submit(); // submit event'i atlar, native POST gerçekleşir
}

// Form submit'i intercept et — yöntem seçilmediyse modal göster
checkoutForm?.addEventListener('submit', e => {
  // payMethodInput zaten doluysa (modal'dan submitWithMethod çağrıldı) devam et
  if (payMethodInput && payMethodInput.value) return;

  if (CARD_ENABLED && BANK_ENABLED) {
    e.preventDefault();
    openModal();
  } else if (CARD_ENABLED && !BANK_ENABLED) {
    payMethodInput.value = 'kredi_karti'; // direkt 3DS akışına gidecek
  } else if (BANK_ENABLED && !CARD_ENABLED) {
    e.preventDefault();
    openModal(); // hesap bilgileri view'ı
  }
  // Hiçbiri yoksa fallback PHP tarafında 'acik_hesap' olur
});

// View1 — yöntem seçim butonları
document.querySelectorAll('.payMethodOpt').forEach(btn => {
  btn.addEventListener('mouseenter', () => btn.style.borderColor = 'var(--primary)');
  btn.addEventListener('mouseleave', () => btn.style.borderColor = 'var(--border)');
  btn.addEventListener('click', () => {
    const m = btn.dataset.method;
    if (m === 'havale_eft' && view2) {
      // Hesap bilgileri view'ına geç
      if (view1) view1.style.display = 'none';
      view2.style.display = 'block';
    } else {
      submitWithMethod(m);
    }
  });
});

// View2 — Geri ve Onayla butonları
document.getElementById('payMethodBack')?.addEventListener('click', () => {
  if (CARD_ENABLED && BANK_ENABLED && view1) {
    view2.style.display = 'none';
    view1.style.display = 'block';
  } else {
    closeModal();
  }
});
document.getElementById('payMethodConfirmBank')?.addEventListener('click', () => {
  submitWithMethod('havale_eft');
});

// Genel kapatma
document.getElementById('payMethodCancel')?.addEventListener('click', closeModal);
payMethodModal?.addEventListener('click', e => {
  if (e.target === payMethodModal) closeModal();
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
