<?php
/**
 * Admin — Manuel Fatura Yönetimi (Alış / Satış)
 *
 * Senaryo:
 *   • ALIS:  Bayi bize fatura keser (örn. ciro primi karşılığı hizmet faturası)
 *           → Biz borçluyuz → cari hesabına ALACAK eklenir
 *
 *   • SATIS: Biz bayiye sipariş dışı fatura keseriz (örn. üyelik aidatı)
 *           → Bayi borçlu → cari hesabına BORÇ eklenir
 */
requireAdmin();

$success = $_SESSION['flash']['msg'] ?? null;
$flashType = $_SESSION['flash']['type'] ?? 'success';
if (isset($_SESSION['flash'])) unset($_SESSION['flash']);
$error = null;

$action = $_GET['action'] ?? 'list';
$direction = $_GET['direction'] ?? 'all'; // 'ALIS' | 'SATIS' | 'all'
$dealerFilter = (int)($_GET['dealer_id'] ?? 0);

$categories = [
    'ciro_primi' => 'Ciro Primi Faturası',
    'hizmet'     => 'Hizmet Faturası',
    'kira'       => 'Kira Faturası',
    'uyelik'     => 'Üyelik / Aidat',
    'diger'      => 'Diğer',
];

// ─── POST Handler ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    // YENİ FATURA ekle
    if ($act === 'create') {
        $dealerId   = (int)$_POST['dealer_id'];
        $dir        = $_POST['direction'] === 'ALIS' ? 'ALIS' : 'SATIS';
        $invoiceNo  = trim($_POST['invoice_no'] ?? '');
        $invoiceDate= $_POST['invoice_date'] ?? date('Y-m-d');
        $dueDate    = $_POST['due_date'] ?: null;
        $amountNet  = (float)str_replace(',', '.', $_POST['amount_net'] ?? 0);
        $vatRate    = (float)str_replace(',', '.', $_POST['vat_rate'] ?? 0);
        $category   = $_POST['category'] ?? 'diger';
        $desc       = trim($_POST['description'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $relCommId  = !empty($_POST['related_commission_id']) ? (int)$_POST['related_commission_id'] : null;

        if (!$dealerId)                $error = 'Bayi seçimi zorunlu.';
        elseif ($amountNet <= 0)       $error = 'Tutar 0\'dan büyük olmalı.';
        elseif (!$invoiceDate)         $error = 'Fatura tarihi zorunlu.';

        if (!$error) {
            $vatAmount   = round($amountNet * $vatRate / 100, 2);
            $amountGross = round($amountNet + $vatAmount, 2);

            $newId = dbInsertRow('b2b_manual_invoices', [
                'dealer_id'             => $dealerId,
                'direction'             => $dir,
                'invoice_no'            => $invoiceNo ?: null,
                'invoice_date'          => $invoiceDate,
                'due_date'              => $dueDate,
                'amount_net'            => $amountNet,
                'vat_rate'              => $vatRate,
                'vat_amount'            => $vatAmount,
                'amount_gross'          => $amountGross,
                'category'              => $category,
                'description'           => $desc,
                'related_commission_id' => $relCommId,
                'notes'                 => $notes,
                'status'                => 'kayitli',
                'created_by'            => adminId(),
                'created_at'            => date('Y-m-d H:i:s'),
            ]);

            // Cari hesaba yansıt
            // ALIS  → biz borçluyuz → bayinin lehine ALACAK kayıt
            // SATIS → bayi borçlu  → bayinin aleyhine BORC kayıt
            $ledgerType = $dir === 'ALIS' ? 'alacak' : 'borc';
            $ledgerDesc = "Manuel Fatura — " . ($categories[$category] ?? $category);
            if ($invoiceNo) $ledgerDesc .= " (No: $invoiceNo)";
            if ($desc) $ledgerDesc .= " · " . mb_substr($desc, 0, 80);

            $ledgerId = ledgerAdd(
                $dealerId,
                $ledgerType,
                $amountGross,
                $ledgerDesc,
                'manual_invoice',
                $newId,
                $dueDate
            );

            if ($ledgerId) {
                dbExec("UPDATE b2b_manual_invoices SET ledger_id=? WHERE id=?", [$ledgerId, $newId]);
            }

            auditLog('manual_invoice_created', 'b2b_manual_invoices', $newId, [
                'direction' => $dir, 'dealer_id' => $dealerId, 'amount' => $amountGross
            ]);

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => ($dir === 'ALIS' ? '📥 Alış' : '📤 Satış') . ' faturası kaydedildi. Cari hesaba yansıtıldı.'
            ];
            redirect('?page=manual-invoices');
        }
    }

    // DÜZENLE
    if ($act === 'update') {
        $id = (int)$_POST['invoice_id'];
        $invoice = dbRow("SELECT * FROM b2b_manual_invoices WHERE id=?", [$id]);
        if (!$invoice) {
            $error = 'Fatura bulunamadı.';
        } else {
            $amountNet  = (float)str_replace(',', '.', $_POST['amount_net'] ?? 0);
            $vatRate    = (float)str_replace(',', '.', $_POST['vat_rate'] ?? 0);
            $vatAmount  = round($amountNet * $vatRate / 100, 2);
            $amountGross= round($amountNet + $vatAmount, 2);

            dbExec(
                "UPDATE b2b_manual_invoices
                    SET invoice_no=?, invoice_date=?, due_date=?,
                        amount_net=?, vat_rate=?, vat_amount=?, amount_gross=?,
                        category=?, description=?, notes=?
                  WHERE id=?",
                [
                    trim($_POST['invoice_no'] ?? '') ?: null,
                    $_POST['invoice_date'] ?? date('Y-m-d'),
                    $_POST['due_date'] ?: null,
                    $amountNet, $vatRate, $vatAmount, $amountGross,
                    $_POST['category'] ?? 'diger',
                    trim($_POST['description'] ?? ''),
                    trim($_POST['notes'] ?? ''),
                    $id
                ]
            );

            // Cari hesap kaydını da güncelle (varsa)
            if (!empty($invoice['ledger_id'])) {
                $ledgerDesc = "Manuel Fatura — " . ($categories[$_POST['category'] ?? 'diger'] ?? '');
                if (!empty($_POST['invoice_no'])) $ledgerDesc .= " (No: " . trim($_POST['invoice_no']) . ")";
                dbExec(
                    "UPDATE b2b_ledger SET amount=?, description=?, due_date=? WHERE id=?",
                    [$amountGross, $ledgerDesc, $_POST['due_date'] ?: null, $invoice['ledger_id']]
                );
            }

            auditLog('manual_invoice_updated', 'b2b_manual_invoices', $id, ['amount' => $amountGross]);
            $_SESSION['flash'] = ['type'=>'success', 'msg'=>'Fatura güncellendi.'];
            redirect('?page=manual-invoices&action=detail&id=' . $id);
        }
    }

    // İPTAL / SİL — Cari hareketi de geri al
    if ($act === 'cancel') {
        $id = (int)$_POST['invoice_id'];
        $invoice = dbRow("SELECT * FROM b2b_manual_invoices WHERE id=?", [$id]);
        if ($invoice && $invoice['status'] !== 'iptal') {
            // Cari hesaba TERS hareket ekle
            $reverseType = $invoice['direction'] === 'ALIS' ? 'borc' : 'alacak';
            ledgerAdd(
                (int)$invoice['dealer_id'],
                $reverseType,
                (float)$invoice['amount_gross'],
                'İPTAL: Manuel Fatura #' . $id . ' düzeltmesi',
                'manual_invoice_cancel',
                $id
            );

            dbExec("UPDATE b2b_manual_invoices SET status='iptal' WHERE id=?", [$id]);
            auditLog('manual_invoice_cancelled', 'b2b_manual_invoices', $id, []);
            $_SESSION['flash'] = ['type'=>'success', 'msg'=>'Fatura iptal edildi, cari düzeltildi.'];
        }
        redirect('?page=manual-invoices');
    }

    // ÖDENDİ olarak işaretle
    if ($act === 'mark_paid') {
        $id = (int)$_POST['invoice_id'];
        dbExec("UPDATE b2b_manual_invoices SET status='odendi' WHERE id=?", [$id]);
        auditLog('manual_invoice_paid', 'b2b_manual_invoices', $id, []);
        $_SESSION['flash'] = ['type'=>'success', 'msg'=>'Fatura "Ödendi" olarak işaretlendi.'];
        redirect('?page=manual-invoices&action=detail&id=' . $id);
    }
}

// ─── Veriler ───
$allDealers = dbRows("SELECT id, company_name FROM b2b_dealers WHERE is_active=1 ORDER BY company_name");

// ─── DETAIL: Tek fatura ───
if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $invoice = dbRow(
        "SELECT mi.*, d.company_name, d.tax_office, d.tax_number, d.first_name, d.last_name
           FROM b2b_manual_invoices mi
           LEFT JOIN b2b_dealers d ON d.id = mi.dealer_id
          WHERE mi.id = ?",
        [$id]
    );
    if (!$invoice) { redirect('?page=manual-invoices'); }

    $isAlis = $invoice['direction'] === 'ALIS';
?>
<div class="page-header">
  <div>
    <h1 class="page-title">
      <?= $isAlis ? '📥 Alış Faturası' : '📤 Satış Faturası' ?>
      <?php if ($invoice['invoice_no']): ?>
        <span style="font-family:monospace;font-size:18px;color:var(--text-muted);font-weight:500"><?= h($invoice['invoice_no']) ?></span>
      <?php endif; ?>
    </h1>
    <p class="page-sub">
      <?= h($invoice['company_name']) ?> · <?= date('d.m.Y', strtotime($invoice['invoice_date'])) ?>
    </p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="?page=manual-invoices" class="btn btn-ghost">← Liste</a>
    <?php if ($invoice['status'] !== 'iptal'): ?>
      <?php if ($invoice['status'] !== 'odendi'): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Fatura Ödendi olarak işaretlensin mi?');">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="mark_paid">
        <input type="hidden" name="invoice_id" value="<?= $id ?>">
        <button type="submit" class="btn btn-success">✓ Ödendi İşaretle</button>
      </form>
      <?php endif; ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Fatura iptal edilecek ve cari hareket TERS yansıtılacak. Devam?');">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="cancel">
        <input type="hidden" name="invoice_id" value="<?= $id ?>">
        <button type="submit" class="btn" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5">✕ İptal Et</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-<?= $flashType ?>"><?= $success ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">

  <!-- Bayi & Fatura -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Bayi & Fatura Bilgileri</h3></div>
    <div class="card-body">
      <table class="table" style="margin:0">
        <tr><td style="color:var(--text-muted);width:40%">Bayi</td><td><strong><?= h($invoice['company_name']) ?></strong></td></tr>
        <tr><td style="color:var(--text-muted)">Vergi Dairesi</td><td><?= h($invoice['tax_office'] ?: '—') ?></td></tr>
        <tr><td style="color:var(--text-muted)">Vergi No</td><td style="font-family:monospace"><?= h($invoice['tax_number'] ?: '—') ?></td></tr>
        <tr><td style="color:var(--text-muted)">Yön</td><td>
          <?php if ($isAlis): ?>
            <span class="badge" style="background:#fef3c7;color:#92400e">📥 ALIŞ (bayi bize kesti)</span>
          <?php else: ?>
            <span class="badge" style="background:#dbeafe;color:#1e40af">📤 SATIŞ (biz bayiye kestik)</span>
          <?php endif; ?>
        </td></tr>
        <tr><td style="color:var(--text-muted)">Fatura Tarihi</td><td><?= date('d.m.Y', strtotime($invoice['invoice_date'])) ?></td></tr>
        <tr><td style="color:var(--text-muted)">Vade Tarihi</td><td><?= $invoice['due_date'] ? date('d.m.Y', strtotime($invoice['due_date'])) : '—' ?></td></tr>
        <tr><td style="color:var(--text-muted)">Kategori</td><td><?= h($categories[$invoice['category']] ?? $invoice['category']) ?></td></tr>
        <tr><td style="color:var(--text-muted)">Durum</td><td>
          <?php
            $statusBadge = match($invoice['status']) {
              'kayitli' => '<span class="badge" style="background:#dbeafe;color:#1e40af">📝 Kayıtlı</span>',
              'odendi'  => '<span class="badge" style="background:#dcfce7;color:#15803d">✓ Ödendi</span>',
              'iptal'   => '<span class="badge" style="background:#fee2e2;color:#b91c1c">✕ İptal</span>',
              default   => '<span class="badge">—</span>',
            };
            echo $statusBadge;
          ?>
        </td></tr>
      </table>
    </div>
  </div>

  <!-- Tutarlar -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Tutarlar</h3></div>
    <div class="card-body">
      <table class="table" style="margin:0">
        <tr><td style="color:var(--text-muted);width:50%">KDV Hariç (Net)</td><td style="text-align:right;font-weight:600"><?= number_format((float)$invoice['amount_net'], 2, ',', '.') ?> ₺</td></tr>
        <tr><td style="color:var(--text-muted)">KDV Oranı</td><td style="text-align:right">%<?= number_format((float)$invoice['vat_rate'], 2, ',', '.') ?></td></tr>
        <tr><td style="color:var(--text-muted)">KDV Tutarı</td><td style="text-align:right"><?= number_format((float)$invoice['vat_amount'], 2, ',', '.') ?> ₺</td></tr>
        <tr style="background:#f8fafc">
          <td style="font-weight:700;font-size:14px">TOPLAM</td>
          <td style="text-align:right;font-weight:700;font-size:18px;color:<?= $isAlis ? '#15803d' : '#dc2626' ?>"><?= number_format((float)$invoice['amount_gross'], 2, ',', '.') ?> ₺</td>
        </tr>
      </table>
    </div>
  </div>
</div>

<?php if ($invoice['description'] || $invoice['notes']): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><h3 class="card-title">Açıklama & Notlar</h3></div>
  <div class="card-body">
    <?php if ($invoice['description']): ?>
      <div style="margin-bottom:8px"><strong>Açıklama:</strong> <?= nl2br(h($invoice['description'])) ?></div>
    <?php endif; ?>
    <?php if ($invoice['notes']): ?>
      <div><strong>Not:</strong> <?= nl2br(h($invoice['notes'])) ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
  // İlişkili ciro primi
  if (!empty($invoice['related_commission_id'])):
    $comm = dbRow("SELECT * FROM b2b_dealer_commissions WHERE id=?", [(int)$invoice['related_commission_id']]);
    if ($comm):
?>
<div class="card" style="margin-bottom:14px;border-left:4px solid #f59e0b">
  <div class="card-body" style="display:flex;align-items:center;gap:14px">
    <div style="font-size:32px">💰</div>
    <div style="flex:1">
      <div style="font-weight:700">Bu fatura ciro primi karşılığında kesilmiştir</div>
      <div style="font-size:12px;color:var(--text-muted)">
        Dönem: <?= $comm['period_month'] ?>/<?= $comm['period_year'] ?> ·
        Prim: <?= number_format((float)$comm['commission_amount'], 2, ',', '.') ?> ₺ ·
        Durum: <?= $comm['status'] ?>
      </div>
    </div>
    <a href="?page=commissions&year=<?= $comm['period_year'] ?>&month=<?= $comm['period_month'] ?>" class="btn btn-ghost btn-sm">İlgili Primi Gör →</a>
  </div>
</div>
<?php endif; endif; ?>

<?php return; } // end detail ?>

<?php
// ─── NEW / EDIT FORM ───
if ($action === 'new' || $action === 'edit') {
    $editing = $action === 'edit';
    $invoice = null;
    if ($editing) {
        $id = (int)($_GET['id'] ?? 0);
        $invoice = dbRow("SELECT * FROM b2b_manual_invoices WHERE id=?", [$id]);
        if (!$invoice) { redirect('?page=manual-invoices'); }
    }
    $defaultDir = $_GET['direction'] ?? ($invoice['direction'] ?? 'ALIS');
    $relCommId  = (int)($_GET['commission_id'] ?? ($invoice['related_commission_id'] ?? 0));
    $relComm    = $relCommId ? dbRow("SELECT * FROM b2b_dealer_commissions WHERE id=?", [$relCommId]) : null;
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= $editing ? '✏️ Fatura Düzenle' : '➕ Yeni Manuel Fatura' ?></h1>
    <p class="page-sub">Sipariş dışı manuel fatura kaydı (alış veya satış)</p>
  </div>
  <a href="?page=manual-invoices" class="btn btn-ghost">← Liste</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if ($relComm): ?>
<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px 16px;margin-bottom:14px">
  <strong>💰 Ciro Primi Karşılığı:</strong>
  Dönem <?= $relComm['period_month'] ?>/<?= $relComm['period_year'] ?> ·
  Prim Tutarı: <strong><?= number_format((float)$relComm['commission_amount'], 2, ',', '.') ?> ₺</strong>
</div>
<?php endif; ?>

<form method="post" class="card">
  <?= csrfField() ?>
  <input type="hidden" name="form_action" value="<?= $editing ? 'update' : 'create' ?>">
  <?php if ($editing): ?><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>"><?php endif; ?>
  <?php if ($relCommId && !$editing): ?><input type="hidden" name="related_commission_id" value="<?= $relCommId ?>"><?php endif; ?>

  <div class="card-body">
    <!-- Yön (sadece yeni eklerken) -->
    <?php if (!$editing): ?>
    <div class="form-group">
      <label>Fatura Yönü *</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <label style="cursor:pointer;border:2px solid <?= $defaultDir==='ALIS' ? '#f59e0b' : '#e2e8f0' ?>;background:<?= $defaultDir==='ALIS' ? '#fef3c7' : '#fff' ?>;border-radius:8px;padding:12px;display:flex;align-items:center;gap:10px">
          <input type="radio" name="direction" value="ALIS" <?= $defaultDir==='ALIS' ? 'checked' : '' ?>>
          <div>
            <div style="font-weight:700;color:#92400e">📥 ALIŞ Faturası</div>
            <div style="font-size:11px;color:var(--text-muted)">Bayi bize fatura kesti (örn: prim karşılığı)</div>
            <div style="font-size:11px;color:#15803d;margin-top:2px">→ Cariye ALACAK eklenecek</div>
          </div>
        </label>
        <label style="cursor:pointer;border:2px solid <?= $defaultDir==='SATIS' ? '#1e40af' : '#e2e8f0' ?>;background:<?= $defaultDir==='SATIS' ? '#dbeafe' : '#fff' ?>;border-radius:8px;padding:12px;display:flex;align-items:center;gap:10px">
          <input type="radio" name="direction" value="SATIS" <?= $defaultDir==='SATIS' ? 'checked' : '' ?>>
          <div>
            <div style="font-weight:700;color:#1e40af">📤 SATIŞ Faturası</div>
            <div style="font-size:11px;color:var(--text-muted)">Biz bayiye fatura kestik (sipariş dışı)</div>
            <div style="font-size:11px;color:#dc2626;margin-top:2px">→ Cariye BORÇ eklenecek</div>
          </div>
        </label>
      </div>
    </div>
    <?php endif; ?>

    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label>Bayi *</label>
        <select name="dealer_id" class="form-control" required <?= $editing ? 'disabled' : '' ?>>
          <option value="">— Bayi seç —</option>
          <?php foreach ($allDealers as $d):
            $sel = ($editing && $invoice['dealer_id'] == $d['id']) || (isset($_GET['dealer_id']) && $_GET['dealer_id'] == $d['id']);
          ?>
            <option value="<?= (int)$d['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= h($d['company_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($editing): ?><input type="hidden" name="dealer_id" value="<?= (int)$invoice['dealer_id'] ?>"><?php endif; ?>
      </div>

      <div class="form-group">
        <label>Kategori</label>
        <select name="category" class="form-control">
          <?php foreach ($categories as $ck => $cl):
            $sel = ($editing && $invoice['category'] === $ck) || (!$editing && $relCommId && $ck === 'ciro_primi');
          ?>
            <option value="<?= $ck ?>" <?= $sel ? 'selected' : '' ?>><?= h($cl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-grid form-grid-3">
      <div class="form-group">
        <label>Fatura No</label>
        <input type="text" name="invoice_no" value="<?= h($invoice['invoice_no'] ?? '') ?>" class="form-control" placeholder="örn: A-2026-0042" style="font-family:monospace">
      </div>
      <div class="form-group">
        <label>Fatura Tarihi *</label>
        <input type="date" name="invoice_date" value="<?= h($invoice['invoice_date'] ?? date('Y-m-d')) ?>" class="form-control" required>
      </div>
      <div class="form-group">
        <label>Vade Tarihi</label>
        <input type="date" name="due_date" value="<?= h($invoice['due_date'] ?? '') ?>" class="form-control">
      </div>
    </div>

    <div class="form-grid form-grid-3">
      <div class="form-group">
        <label>KDV Hariç Tutar (₺) *</label>
        <input type="number" step="0.01" min="0.01" name="amount_net"
               value="<?= $editing ? $invoice['amount_net'] : ($relComm ? $relComm['commission_amount'] : '') ?>"
               class="form-control" required
               oninput="updateTotals()" id="mi-net">
      </div>
      <div class="form-group">
        <label>KDV Oranı (%)</label>
        <select name="vat_rate" class="form-control" oninput="updateTotals()" id="mi-vat">
          <?php
            $defaultVat = $editing ? (float)$invoice['vat_rate'] : 18.00;
            foreach ([0, 1, 10, 18, 20] as $v):
          ?>
            <option value="<?= $v ?>" <?= $defaultVat == $v ? 'selected' : '' ?>>%<?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>KDV Dahil (Hesaplanan)</label>
        <input type="text" id="mi-gross" class="form-control" readonly style="background:#f0fdf4;font-weight:700;color:#15803d">
      </div>
    </div>

    <div class="form-group">
      <label>Açıklama</label>
      <textarea name="description" class="form-control" rows="2" placeholder="örn: 2026 Mayıs ciro primi karşılığı düzenlenmiştir."><?= h($invoice['description'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label>Notlar (Admin)</label>
      <textarea name="notes" class="form-control" rows="2" placeholder="İç notlar"><?= h($invoice['notes'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="card-footer" style="padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px">
    <a href="?page=manual-invoices" class="btn btn-ghost">İptal</a>
    <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Güncelle' : '➕ Faturayı Kaydet' ?></button>
  </div>
</form>

<script>
function updateTotals() {
  const net  = parseFloat(document.getElementById('mi-net').value) || 0;
  const vat  = parseFloat(document.getElementById('mi-vat').value) || 0;
  const vatAmount = net * vat / 100;
  const gross = net + vatAmount;
  document.getElementById('mi-gross').value = gross.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ₺';
}
updateTotals();
</script>
<?php return; } ?>

<?php
// ─── LIST ───
$where = ['1=1']; $params = [];
if ($direction !== 'all') { $where[] = 'mi.direction=?'; $params[] = $direction; }
if ($dealerFilter > 0) { $where[] = 'mi.dealer_id=?'; $params[] = $dealerFilter; }
$w = implode(' AND ', $where);

$invoices = dbRows(
    "SELECT mi.*, d.company_name
       FROM b2b_manual_invoices mi
       LEFT JOIN b2b_dealers d ON d.id = mi.dealer_id
      WHERE $w
      ORDER BY mi.invoice_date DESC, mi.id DESC
      LIMIT 200",
    $params
);

$counts = dbRow(
    "SELECT
       COUNT(*) AS total,
       SUM(CASE WHEN direction='ALIS' THEN 1 ELSE 0 END) AS alis_cnt,
       SUM(CASE WHEN direction='SATIS' THEN 1 ELSE 0 END) AS satis_cnt,
       COALESCE(SUM(CASE WHEN direction='ALIS' AND status!='iptal' THEN amount_gross ELSE 0 END),0) AS alis_total,
       COALESCE(SUM(CASE WHEN direction='SATIS' AND status!='iptal' THEN amount_gross ELSE 0 END),0) AS satis_total
     FROM b2b_manual_invoices"
);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">📋 Manuel Faturalar</h1>
    <p class="page-sub">Sipariş dışı alış ve satış faturaları</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="?page=manual-invoices&action=new&direction=ALIS" class="btn" style="background:#f59e0b;border-color:#f59e0b;color:#fff">📥 Yeni Alış Faturası</a>
    <a href="?page=manual-invoices&action=new&direction=SATIS" class="btn btn-primary">📤 Yeni Satış Faturası</a>
  </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-<?= $flashType ?>"><?= $success ?></div><?php endif; ?>

<!-- Stat Kartları -->
<div class="stats-grid" style="margin-bottom:14px">
  <div class="stat-card">
    <div class="stat-icon amber">📥</div>
    <div class="stat-info">
      <div class="stat-value"><?= (int)$counts['alis_cnt'] ?></div>
      <div class="stat-label">Alış Faturası</div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= number_format((float)$counts['alis_total'],2,',','.') ?> ₺</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">📤</div>
    <div class="stat-info">
      <div class="stat-value"><?= (int)$counts['satis_cnt'] ?></div>
      <div class="stat-label">Satış Faturası</div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= number_format((float)$counts['satis_total'],2,',','.') ?> ₺</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">📊</div>
    <div class="stat-info">
      <div class="stat-value"><?= (int)$counts['total'] ?></div>
      <div class="stat-label">Toplam Kayıt</div>
    </div>
  </div>
</div>

<!-- Filtre -->
<div class="card" style="margin-bottom:14px">
  <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1">
      <input type="hidden" name="page" value="manual-invoices">
      <select name="direction" class="form-control" style="width:auto" onchange="this.form.submit()">
        <option value="all" <?= $direction==='all' ? 'selected' : '' ?>>Tüm Yönler</option>
        <option value="ALIS" <?= $direction==='ALIS' ? 'selected' : '' ?>>📥 Sadece Alış</option>
        <option value="SATIS" <?= $direction==='SATIS' ? 'selected' : '' ?>>📤 Sadece Satış</option>
      </select>
      <select name="dealer_id" class="form-control" style="width:auto" onchange="this.form.submit()">
        <option value="0">— Tüm Bayiler —</option>
        <?php foreach ($allDealers as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $dealerFilter == $d['id'] ? 'selected' : '' ?>><?= h($d['company_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Bayi</th>
          <th>Yön</th>
          <th>Kategori</th>
          <th>Fatura No</th>
          <th style="text-align:right">Tutar</th>
          <th>Durum</th>
          <th>İşlem</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($invoices as $inv):
        $isAlis = $inv['direction'] === 'ALIS';
        $rowOpacity = $inv['status'] === 'iptal' ? 'opacity:.5' : '';
      ?>
        <tr style="<?= $rowOpacity ?>">
          <td style="font-size:12px"><?= date('d.m.Y', strtotime($inv['invoice_date'])) ?></td>
          <td><?= h($inv['company_name']) ?></td>
          <td>
            <?php if ($isAlis): ?>
              <span class="badge" style="background:#fef3c7;color:#92400e;font-size:10px">📥 ALIŞ</span>
            <?php else: ?>
              <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:10px">📤 SATIŞ</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px"><?= h($categories[$inv['category']] ?? $inv['category']) ?></td>
          <td style="font-family:monospace;font-size:12px"><?= h($inv['invoice_no'] ?: '—') ?></td>
          <td style="text-align:right;font-weight:600;color:<?= $isAlis ? '#15803d' : '#dc2626' ?>">
            <?= number_format((float)$inv['amount_gross'], 2, ',', '.') ?> ₺
          </td>
          <td>
            <?php
              echo match($inv['status']) {
                'kayitli' => '<span class="badge" style="background:#dbeafe;color:#1e40af;font-size:10px">📝 Kayıtlı</span>',
                'odendi'  => '<span class="badge" style="background:#dcfce7;color:#15803d;font-size:10px">✓ Ödendi</span>',
                'iptal'   => '<span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:10px">✕ İptal</span>',
                default   => '',
              };
            ?>
          </td>
          <td style="white-space:nowrap">
            <a href="?page=manual-invoices&action=detail&id=<?= (int)$inv['id'] ?>" class="btn btn-ghost btn-sm">Detay →</a>
            <?php if ($inv['status'] !== 'iptal'): ?>
            <a href="?page=manual-invoices&action=edit&id=<?= (int)$inv['id'] ?>" class="btn btn-ghost btn-sm" title="Düzenle">✏️</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($invoices)): ?>
        <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)">
          Henüz manuel fatura kaydı yok.
          <br><br>
          <a href="?page=manual-invoices&action=new&direction=ALIS" class="btn" style="background:#f59e0b;color:#fff">📥 Yeni Alış Faturası</a>
          <a href="?page=manual-invoices&action=new&direction=SATIS" class="btn btn-primary">📤 Yeni Satış Faturası</a>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
