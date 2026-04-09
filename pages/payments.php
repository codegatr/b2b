<?php
// pages/payments.php — Bayi Ödemeleri
requireDealer();
$dealer = currentDealer();

$error   = '';
$success = '';

// Yeni ödeme bildirimi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'new_payment') {
    csrfCheck();
    $orderId  = intval($_POST['order_id'] ?? 0);
    $amount   = floatval($_POST['amount'] ?? 0);
    $method   = trim($_POST['type'] ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    $ref      = trim($_POST['transaction_ref'] ?? '');
    $note     = trim($_POST['dealer_note'] ?? '');

    if ($amount <= 0) {
        $error = 'Tutar 0\'dan büyük olmalıdır.';
    } elseif (!$method) {
        $error = 'Ödeme yöntemi seçiniz.';
    } else {
        $payId = dbInsertRow('b2b_payments', [
            'dealer_id'       => $dealer['id'],
            'order_id'        => $orderId ?: null,
            'amount'          => $amount,
            'type'            => $method,
            'status'          => 'bekliyor',
            'payment_date'    => date('Y-m-d'),
            'bank_name'       => $bankName,
            'transaction_ref' => $ref,
            'dealer_note'     => $note,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        auditLog('payment_created', 'b2b_payments', $payId, []);
        $success = 'Ödeme bildirimi alındı. Admin onayından sonra hesabınıza işlenecektir.';
    }
}

// Bekleyen siparişler (ödeme bağlama için)
$openOrders = dbRows(
    "SELECT id, order_no, grand_total FROM b2b_orders
     WHERE dealer_id=? AND payment_status IN ('odenmedi','kismi')
     ORDER BY created_at DESC LIMIT 20",
    [$dealer['id']]
);

// Geçmiş ödemeler
$payments = dbRows(
    "SELECT * FROM b2b_payments WHERE dealer_id=? ORDER BY created_at DESC LIMIT 50",
    [$dealer['id']]
);

// Banka hesapları
$bankAccounts = setting('bank_accounts', '');
?>
<div class="page-body">
<div class="page-header">
  <div><h1 class="page-title">Ödemelerim</h1></div>
  <button class="btn btn-primary" onclick="document.getElementById('newPayForm').classList.toggle('hidden')">
    + Ödeme Bildir
  </button>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<!-- Yeni Ödeme Formu -->
<div id="newPayForm" class="card hidden" style="margin-bottom:20px">
  <div class="card-header"><h3 class="card-title">Ödeme Bildirimi</h3></div>
  <div class="card-body">
    <?php if ($bankAccounts): ?>
    <div class="alert alert-info" style="margin-bottom:16px">
      <strong>Banka Hesapları:</strong><br>
      <pre style="margin:6px 0 0;font-size:12px;white-space:pre-wrap"><?= h($bankAccounts) ?></pre>
    </div>
    <?php endif; ?>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="new_payment">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Ödeme Yöntemi</label>
          <select name="type" class="form-control" required>
            <option value="">Seçiniz</option>
            <option value="havale">Havale / EFT</option>
            <option value="kredi_karti">Kredi Kartı</option>
            <option value="nakit">Nakit</option>
            <option value="cek">Çek</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Tutar (₺)</label>
          <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00">
        </div>
        <div class="form-group">
          <label class="form-label">İlgili Sipariş (opsiyonel)</label>
          <select name="order_id" class="form-control">
            <option value="">Sipariş seçiniz</option>
            <?php foreach ($openOrders as $o): ?>
            <option value="<?= $o['id'] ?>"><?= h($o['order_no']) ?> — <?= money((float)$o['grand_total']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Banka Adı</label>
          <input type="text" name="bank_name" class="form-control" placeholder="Ziraat, Yapı Kredi...">
        </div>
        <div class="form-group">
          <label class="form-label">Dekont / Referans No</label>
          <input type="text" name="transaction_ref" class="form-control" placeholder="İşlem numarası">
        </div>
        <div class="form-group">
          <label class="form-label">Not</label>
          <input type="text" name="dealer_note" class="form-control" placeholder="Ek açıklama">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Ödemeyi Bildir</button>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('newPayForm').classList.add('hidden')">İptal</button>
      </div>
    </form>
  </div>
</div>

<!-- Ödeme Geçmişi -->
<div class="card">
  <div class="card-header"><h3 class="card-title">Ödeme Geçmişi</h3></div>
  <?php if (empty($payments)): ?>
  <div class="card-body" style="text-align:center;color:var(--text-muted);padding:40px">Henüz ödeme kaydı yok.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Tarih</th><th>Tutar</th><th>Yöntem</th><th>Banka</th><th>Referans</th><th>Durum</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted)"><?= fmtDateTime($p['created_at']) ?></td>
        <td class="fw-600"><?= money((float)$p['amount']) ?></td>
        <td><?= h($p['type'] ?? '') ?></td>
        <td><?= h($p['bank_name'] ?? '—') ?></td>
        <td style="font-size:12px"><?= h($p['transaction_ref'] ?? '—') ?></td>
        <td>
          <?php
          $st = $p['status'] ?? 'bekliyor';
          $badge = match($st) {
            'onaylandi'   => 'success',
            'reddedildi'  => 'danger',
            default       => 'warning',
          };
          $label = match($st) {
            'onaylandi'  => 'Onaylandı',
            'reddedildi' => 'Reddedildi',
            default      => 'Bekliyor',
          };
          ?>
          <span class="badge badge-<?= $badge ?>"><?= $label ?></span>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</div>
