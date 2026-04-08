<?php
// pages/orders.php — Bayi Siparişleri
requireDealer();
$dealer = currentDealer();

$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);

// İptal talebi POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_request') {
    csrfCheck();
    $oid    = intval($_POST['order_id'] ?? 0);
    $reason = trim($_POST['cancel_reason'] ?? '');
    $ord    = dbRow("SELECT * FROM b2b_orders WHERE id=? AND dealer_id=?", [$oid, $dealer['id']]);
    if ($ord && in_array($ord['status'], ['bekliyor','onaylandi']) && $reason) {
        dbExec(
            "UPDATE b2b_orders SET cancel_requested=1, cancel_reason=?, cancel_requested_at=NOW() WHERE id=?",
            [$reason, $oid]
        );
        // Admin bildirim
        notifyAdmin('order_cancel', 'Sipariş İptal Talebi',
            $dealer['company_name']." siparişi (#".$ord['order_no'].") iptal etmek istiyor.",
            '?page=orders&action=detail&id='.$oid);
        auditLog('cancel_requested', 'b2b_orders', $oid, ['reason'=>$reason]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'İptal talebiniz yöneticiye iletildi.'];
    }
    header('Location: ?page=orders&action=detail&id='.$oid);
    exit;
}

// Sipariş detay
$order = null;
if ($action === 'detail' && $id) {
    $order = dbRow("SELECT * FROM b2b_orders WHERE id=? AND dealer_id=?", [$id, $dealer['id']]);
    if (!$order) { $action = 'list'; $id = 0; }
}

if ($action === 'detail' && $order) {
    $orderItems = dbRows(
        "SELECT oi.*, p.image FROM b2b_order_items oi LEFT JOIN b2b_products p ON p.id=oi.product_id WHERE oi.order_id=?",
        [$id]
    );
    $payments = dbRows("SELECT * FROM b2b_payments WHERE order_id=? ORDER BY created_at DESC", [$id]);
}

// Liste
if ($action === 'list') {
    $status  = $_GET['status'] ?? '';
    $perPage = 15;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['dealer_id=?']; $params = [$dealer['id']];
    if ($status) { $where[] = 'status=?'; $params[] = $status; }

    $w = implode(' AND ', $where);
    $total  = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE $w", $params);
    $orders = dbRows("SELECT * FROM b2b_orders WHERE $w ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", $params);
    $pager  = pagination($total, $perPage, $page, "?page=orders&status=$status&p=");
}
?>

<?php if ($action === 'list'): ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Siparişlerim</h1>
        <p class="page-sub">Toplam <?= $total ?> sipariş</p>
    </div>
    <a href="?page=products" class="btn btn-primary">＋ Yeni Sipariş</a>
</div>

<?php if (isset($_GET['placed'])): ?>
<div class="alert alert-success mb-4">✅ Siparişiniz alındı! Durumu aşağıdan takip edebilirsiniz.</div>
<?php endif; ?>

<!-- Durum Sekmeleri -->
<div class="tab-bar mb-4">
    <?php foreach ([''=> 'Tümü','bekliyor'=>'Bekleyen','onaylandi'=>'Onaylanan','kargoda'=>'Kargoda','teslim_edildi'=>'Teslim','iptal'=>'İptal'] as $v=>$l): ?>
    <a href="?page=orders&status=<?= $v ?>" class="tab-item <?= $status===$v?'active':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
<table class="table">
    <thead><tr><th>Sipariş No</th><th>Tarih</th><th>Tutar</th><th>Ürün</th><th>Durum</th><th>Ödeme</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
    <tr>
        <td class="font-medium"><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_no']) ?></a></td>
        <td class="text-sm"><?= fmtDate($o['created_at']) ?></td>
        <td><?= money($o['grand_total']) ?></td>
        <td class="text-sm text-muted"><?= dbVal("SELECT COUNT(*) FROM b2b_order_items WHERE order_id=?",[$o['id']]) ?> kalem</td>
        <td><?= orderStatusLabel($o['status']) ?></td>
        <td>
            <span class="badge badge-<?= $o['payment_status']==='odendi'?'green':($o['payment_status']==='bekliyor'?'yellow':'blue') ?>">
                <?= $o['payment_status']==='odendi'?'Ödendi':($o['payment_status']==='kismi'?'Kısmen':'Bekliyor') ?>
            </span>
        </td>
        <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-xs btn-ghost">Detay →</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?><tr><td colspan="7" class="text-center text-muted py-8">Sipariş bulunamadı.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= $pager ?>

<?php elseif ($action === 'detail' && $order): ?>

<!-- Sipariş Başlık -->
<div class="page-header">
  <div>
    <h1 class="page-title" style="font-size:22px"><?= h($order['order_no']) ?></h1>
    <div style="display:flex;align-items:center;gap:10px;margin-top:4px;flex-wrap:wrap">
      <span style="font-size:13px;color:var(--text-muted)"><?= fmtDate($order['created_at']) ?></span>
      <?= orderStatusLabel($order['status']) ?>
      <?php
      $pstyle = match($order['payment_status'] ?? '') {
        'odendi'       => 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0',
        'kismi'  => 'background:#fffbeb;color:#d97706;border:1px solid #fed7aa',
        default        => 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca',
      };
      $plabel = match($order['payment_status'] ?? '') {
        'odendi'      => '✓ Ödendi',
        'kismi' => '◑ Kısmen',
        default       => '⏳ Ödenmedi',
      };
      ?>
      <span style="<?= $pstyle ?>;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600"><?= $plabel ?></span>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?page=orders" class="btn btn-secondary">← Geri</a>
    <?php if (($order['payment_status'] ?? '') !== 'odendi' && in_array($order['status'] ?? '', ['onaylandi','hazirlaniyor','kargoda'])): ?>
    <a href="?page=payments" class="btn btn-primary">💳 Ödeme Bildir</a>
    <?php endif; ?>
  </div>
</div>

<!-- Durum Adımları -->
<?php
$steps  = ['bekliyor','onaylandi','hazirlaniyor','kargoda','teslim_edildi'];
$labels = ['Sipariş Alındı','Onaylandı','Hazırlanıyor','Kargoya Verildi','Teslim Edildi'];
$icons  = ['🕐','✅','📦','🚚','🏠'];
$curIdx = array_search($order['status'] ?? '', $steps);
?>

<?php if (!in_array($order['status'] ?? '', ['iptal','iade'])): ?>
<div class="card" style="margin-bottom:20px;overflow:visible">
  <div class="card-body" style="padding:24px 20px">
    <div style="display:flex;align-items:flex-start;position:relative">
      <!-- Bağlantı çizgisi -->
      <div style="position:absolute;top:20px;left:22px;right:22px;height:2px;background:var(--border);z-index:0"></div>
      <?php foreach ($steps as $i => $step):
        $done    = $curIdx !== false && $i <= $curIdx;
        $current = $i === $curIdx;
      ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;position:relative;z-index:1;min-width:0">
        <!-- Daire -->
        <div style="
          width:42px;height:42px;border-radius:50%;
          display:flex;align-items:center;justify-content:center;
          font-size:<?= $done?'16':'14' ?>px;
          border:2px solid <?= $done?'var(--success)':'var(--border)' ?>;
          background:<?= $current?'var(--success)':($done?'var(--success-bg)':'var(--surface)') ?>;
          color:<?= $current?'#fff':($done?'var(--success)':'var(--text-muted)') ?>;
          font-weight:700;
          box-shadow:<?= $current?'0 0 0 4px rgba(22,163,74,.15)':'' ?>;
        ">
          <?php if ($done && !$current): ?>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
          <?php else: ?>
          <?= $current ? $icons[$i] : ($i+1) ?>
          <?php endif; ?>
        </div>
        <!-- Etiket -->
        <div style="font-size:11px;text-align:center;line-height:1.3;font-weight:<?= $current?'700':'500' ?>;color:<?= $current?'var(--text)':($done?'var(--text-2)':'var(--text-muted)') ?>;padding:0 2px">
          <?= $labels[$i] ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php elseif ($order['status'] === 'iptal'): ?>
<div class="alert alert-danger" style="margin-bottom:20px">❌ Bu sipariş iptal edildi.</div>
<?php elseif ($order['status'] === 'iade'): ?>
<div class="alert alert-warning" style="margin-bottom:20px">↩️ Bu sipariş iade sürecinde.</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">

<!-- Sol: Sipariş Kalemleri -->
<div>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3 class="card-title">Sipariş Kalemleri</h3></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Ürün</th>
            <th style="text-align:center">Adet</th>
            <th style="text-align:right">Birim Fiyat</th>
            <th style="text-align:right">KDV</th>
            <th style="text-align:right">Toplam</th>
          </tr>
        </thead>
        <tbody>
        <?php $sub=0; $taxTotal=0;
        foreach ($orderItems as $it):
            $qty       = (int)($it['qty'] ?? $it['quantity'] ?? 0);
            $unitPrice = (float)($it['unit_price'] ?? 0);
            $vatRate   = (float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0);
            $lineNet   = $unitPrice * $qty;
            $lineTax   = $lineNet * ($vatRate / 100);
            $lineTotal = $lineNet + $lineTax;
            $sub += $lineNet; $taxTotal += $lineTax;
        ?>
        <tr>
          <td>
            <div class="fw-600" style="font-size:13px"><?= h($it['product_name']) ?></div>
            <?php if ($it['product_sku'] ?? ''): ?>
            <div style="font-size:11px;color:var(--text-muted)">SKU: <?= h($it['product_sku']) ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <span style="font-weight:600"><?= $qty ?></span>
          </td>
          <td style="text-align:right;font-size:13px"><?= money($unitPrice) ?></td>
          <td style="text-align:right;font-size:12px;color:var(--text-muted)">%<?= (int)$vatRate ?></td>
          <td style="text-align:right;font-weight:700"><?= money($lineTotal) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--bg)">
            <td colspan="4" style="text-align:right;font-size:13px;color:var(--text-2);padding:10px 16px">Ara Toplam</td>
            <td style="text-align:right;padding:10px 16px"><?= money($sub) ?></td>
          </tr>
          <tr style="background:var(--bg)">
            <td colspan="4" style="text-align:right;font-size:13px;color:var(--text-2);padding:6px 16px">KDV Toplamı</td>
            <td style="text-align:right;padding:6px 16px;font-size:13px"><?= money($taxTotal) ?></td>
          </tr>
          <tr style="background:var(--bg);border-top:2px solid var(--border)">
            <td colspan="4" style="text-align:right;font-weight:700;font-size:15px;padding:12px 16px">Genel Toplam</td>
            <td style="text-align:right;font-weight:800;font-size:16px;color:var(--red);padding:12px 16px"><?= money((float)$order['grand_total']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php if ($order['notes'] ?? ''): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Sipariş Notu</h3></div>
    <div class="card-body" style="font-size:13px;color:var(--text-2)"><?= nl2br(h($order['notes'])) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- Sağ: Özet Bilgiler -->
<div style="display:flex;flex-direction:column;gap:16px">

  <!-- Sipariş Özeti -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Sipariş Bilgileri</h3></div>
    <div class="card-body" style="font-size:13px">
      <?php $fields = [
        'Sipariş No'    => $order['order_no'],
        'Tarih'         => fmtDate($order['created_at']),
        'Ödeme Yöntemi' => $order['payment_method'] === 'acik_hesap' ? 'Açık Hesap' : h($order['payment_method'] ?? '—'),
      ]; ?>
      <?php foreach ($fields as $k=>$v): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border)">
        <span style="color:var(--text-muted)"><?= $k ?></span>
        <span class="fw-600"><?= $v ?></span>
      </div>
      <?php endforeach; ?>
      <?php if ($order['due_date'] ?? ''): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border)">
        <span style="color:var(--text-muted)">Ödeme Vadesi</span>
        <span class="fw-600 <?= ($order['due_date'] < date('Y-m-d') && ($order['payment_status']??'') !== 'odendi') ? 'text-danger' : '' ?>"><?= fmtDate($order['due_date']) ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Kargo Bilgisi -->
  <?php if ($order['cargo_company'] ?? ''): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">🚚 Kargo</h3></div>
    <div class="card-body" style="font-size:13px">
      <div class="fw-600"><?= h($order['cargo_company']) ?></div>
      <?php if ($order['tracking_number'] ?? ''): ?>
      <div style="margin-top:6px;color:var(--text-muted)">Takip No: <strong style="color:var(--text)"><?= h($order['tracking_number']) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Ödemeler -->
  <?php if (!empty($payments)): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Ödemeler</h3></div>
    <div class="card-body" style="padding:0">
      <?php foreach ($payments as $pay): ?>
      <?php $pst = $pay['status'] ?? 'bekliyor'; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-size:13px;font-weight:600"><?= money((float)$pay['amount']) ?></div>
          <div style="font-size:11px;color:var(--text-muted)"><?= fmtDate($pay['created_at']) ?> · <?= h($pay['type'] ?? '') ?></div>
        </div>
        <span class="badge badge-<?= $pst==='onaylandi'?'success':($pst==='reddedildi'?'danger':'warning') ?>">
          <?= ['onaylandi'=>'Onaylandı','reddedildi'=>'Reddedildi','bekliyor'=>'Bekliyor'][$pst] ?? $pst ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Admin Notu -->
  <?php if ($order['admin_note'] ?? ''): ?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">📋 Yönetici Notu</h3></div>
    <div class="card-body" style="font-size:13px"><?= nl2br(h($order['admin_note'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- İptal Talebi -->
  <?php
  $cancelable = in_array($order['status'] ?? '', ['bekliyor','onaylandi']);
  $cancelReq  = (bool)($order['cancel_requested'] ?? false);
  ?>
  <?php if ($cancelReq): ?>
  <div class="alert alert-warning" style="margin:0">
    <div style="font-weight:600;margin-bottom:4px">⏳ İptal Talebi Bekliyor</div>
    <div style="font-size:12px">Talebiniz yönetici onayında. Neden: <?= h($order['cancel_reason'] ?? '—') ?></div>
  </div>
  <?php elseif ($cancelable): ?>
  <div class="card" style="border-color:var(--danger-border)">
    <div class="card-header" style="background:var(--danger-bg)">
      <h3 class="card-title" style="color:var(--danger)">⚠️ Sipariş İptali</h3>
    </div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
        İptal talebiniz yönetici onayına gönderilecek. Onaylanmadan sipariş iptal edilmez.
      </p>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cancel_request">
        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
        <div class="form-group">
          <label class="form-label">İptal Sebebi *</label>
          <textarea name="cancel_reason" class="form-control" rows="2"
                    placeholder="Lütfen iptal sebebinizi yazın..." required></textarea>
        </div>
        <button type="submit" class="btn btn-danger" style="width:100%"
                onclick="return confirm('Sipariş iptali talep edilecek. Onaylanmadan iptal gerçekleşmez. Devam edilsin mi?')">
          İptal Talebi Gönder
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /sağ -->
</div><!-- /grid -->

<?php endif; ?>
</div>
