<?php
requireAdmin();

$msg = '';

// ── Bağlantı testi
if (isset($_GET['test'])) {
    try {
        $result = parasut()->testConnection();
        $companies = $result['data'] ?? [];
        if (is_array($companies) && !empty($companies)) {
            $names = [];
            foreach ($companies as $c) {
                $name = $c['attributes']['name'] ?? '?';
                $cid  = $c['id'] ?? '?';
                $names[] = "{$name} (ID: {$cid})";
            }
            $msg = 'success:Bağlantı başarılı. Erişilebilir firmalar: ' . implode(', ', $names);
        } else {
            $msg = 'success:Bağlantı başarılı.';
        }
    } catch (Exception $e) {
        $msg = 'error:' . $e->getMessage();
    }
}

// ── Token cache temizle (yeniden token al)
if (isset($_GET['clear_token'])) {
    settingSave('parasut_token_cache', '');
    $msg = 'success:Token cache temizlendi. Bir sonraki istekte yeni token alınacak.';
}

// ── Tüm ürünleri toplu senkronize et
if (isPost() && ($_POST['action'] ?? '') === 'bulk_sync_products') {
    csrfCheck();
    try {
        $result = parasut()->bulkSyncProducts();
        if ($result['total'] === 0) {
            $msg = 'error:Senkronize edilecek aktif ürün bulunamadı.';
        } else {
            $msg = "success:Toplam {$result['total']} ürün işlendi — Başarılı: {$result['success']}, Hatalı: {$result['fail']}";
        }
    } catch (\Throwable $e) {
        $msg = 'error:Toplu senkron hatası: ' . $e->getMessage();
    }
}

// ── Tüm bayileri toplu senkronize et
if (isPost() && ($_POST['action'] ?? '') === 'bulk_sync_dealers') {
    csrfCheck();
    $dealers = dbRows("SELECT * FROM b2b_dealers WHERE is_active=1");
    $success = 0; $fail = 0;
    foreach ($dealers as $d) {
        try {
            $cid = parasut()->syncDealer($d);
            if ($cid) {
                dbExec("UPDATE b2b_dealers SET parasut_contact_id=? WHERE id=?", [$cid, $d['id']]);
                $success++;
            } else { $fail++; }
        } catch (\Throwable $e) { $fail++; }
    }
    $msg = "success:Toplam " . count($dealers) . " bayi işlendi — Başarılı: $success, Hatalı: $fail";
}

// Paraşüt log
$logs = dbRows("SELECT * FROM b2b_parasut_log ORDER BY created_at DESC LIMIT 30");

// İstatistik
$totalDealers   = (int)dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE is_active=1");
$syncedDealers  = (int)dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE parasut_contact_id IS NOT NULL AND parasut_contact_id != ''");
$syncedInvoices = (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE parasut_invoice_id IS NOT NULL AND parasut_invoice_id != ''");
$syncedPayments = (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE parasut_payment_id IS NOT NULL AND parasut_payment_id != ''");
$totalProducts  = (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1");
$syncedProducts = (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE parasut_product_id IS NOT NULL AND parasut_product_id != ''");

$hasToken    = !empty(setting('parasut_email')) && !empty(setting('parasut_company_id')) && !empty(setting('parasut_client_id'));
$tokenCache  = setting('parasut_token_cache', '');
$tokenExpiry = null;
if ($tokenCache && strpos($tokenCache, '|') !== false) {
    $parts = explode('|', $tokenCache);
    if (count($parts) === 2) $tokenExpiry = (int)$parts[1];
}
$tokenValid = $tokenExpiry && $tokenExpiry > time();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Paraşüt Entegrasyonu</h1>
    <p class="page-sub">Muhasebe ve fatura senkronizasyonu</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?page=parasut&test=1" class="btn btn-secondary">🔌 Bağlantıyı Test Et</a>
    <?php if ($tokenCache): ?>
    <a href="?page=parasut&clear_token=1" class="btn btn-secondary" onclick="return confirm('Token cache temizlensin mi? Bir sonraki istekte yeniden alınır.');">🔄 Token Yenile</a>
    <?php endif; ?>
    <a href="?page=settings&tab=parasut" class="btn btn-primary">⚙ Ayarları Düzenle</a>
  </div>
</div>

<?php if ($msg): [$t,$m] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $t==='error'?'danger':'success' ?>"><?= h($m) ?></div>
<?php endif; ?>

<!-- Durum Kartları -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card">
    <div class="stat-icon <?= $hasToken?'green':'red' ?>">
      <?php if ($hasToken): ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
      <?php else: ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <?php endif; ?>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $hasToken ? 'Aktif' : 'Eksik' ?></div>
      <div class="stat-label">Paraşüt Hesabı</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedDealers ?> / <?= $totalDealers ?></div>
      <div class="stat-label">Senkronize Bayi</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedProducts ?> / <?= $totalProducts ?></div>
      <div class="stat-label">Senkronize Ürün</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedInvoices ?></div>
      <div class="stat-label">Oluşturulan Fatura</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedPayments ?></div>
      <div class="stat-label">Senkronize Ödeme</div>
    </div>
  </div>
</div>

<!-- Ayar Özeti / Bağlantı Yok Uyarısı -->
<?php if (!$hasToken): ?>
<div class="alert alert-warning">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  Paraşüt entegrasyonu henüz yapılandırılmamış.
  <a href="?page=settings&tab=parasut">Ayarları tamamlayın →</a>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><h3 class="card-title">Mevcut Ayarlar</h3></div>
  <div class="card-body">
    <div class="form-grid-2">
      <div>
        <div class="stat-label">E-posta</div>
        <div style="font-weight:600;margin-top:2px"><?= h(setting('parasut_email')) ?></div>
      </div>
      <div>
        <div class="stat-label">Firma ID</div>
        <div style="font-weight:600;margin-top:2px"><?= h(setting('parasut_company_id')) ?></div>
      </div>
      <div>
        <div class="stat-label">Client ID</div>
        <div style="font-weight:600;margin-top:2px;font-family:monospace;font-size:11px;word-break:break-all"><?= h(substr(setting('parasut_client_id'), 0, 20) . '…') ?></div>
      </div>
      <div>
        <div class="stat-label">Token Durumu</div>
        <div style="margin-top:2px">
          <?php if ($tokenValid): ?>
          <span class="badge badge-success">Geçerli — <?= date('d.m.Y H:i', $tokenExpiry) ?> kadar</span>
          <?php elseif ($tokenCache): ?>
          <span class="badge badge-danger">Süresi Dolmuş</span>
          <?php else: ?>
          <span class="badge badge-neutral">Henüz alınmadı</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Toplu Senkronizasyon -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><h3 class="card-title">⚙ Toplu Senkronizasyon</h3></div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px">
      Mevcut bayi ve ürünleri Paraşüt'e tek tıkla aktarın. Önceden senkronize edilenler güncellenmez (her bayi/ürün için Paraşüt ID kaydedilir, bir daha çağrılmaz).
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <form method="post" onsubmit="return confirm('Toplam <?= $totalDealers - $syncedDealers ?> bayi Paraşüt\'e gönderilecek.\n\nDevam edilsin mi?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bulk_sync_dealers">
        <button type="submit" class="btn btn-primary"<?= ($totalDealers - $syncedDealers) <= 0 ? ' disabled' : '' ?>>
          👥 Bayileri Senkronize Et (<?= $totalDealers - $syncedDealers ?>)
        </button>
      </form>
      <form method="post" onsubmit="return confirm('Toplam <?= $totalProducts ?> ürün Paraşüt\'e gönderilecek (mevcut olanlar güncellenir).\n\nDevam edilsin mi?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bulk_sync_products">
        <button type="submit" class="btn btn-primary"<?= $totalProducts <= 0 ? ' disabled' : '' ?>>
          📦 Ürünleri Senkronize Et (<?= $totalProducts ?>)
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- İşlem Logu -->
<div class="card">
  <div class="card-header"><h3 class="card-title">İşlem Geçmişi (Son 30)</h3></div>
  <?php if (empty($logs)): ?>
  <div class="card-body" style="text-align:center;color:var(--text-muted);padding:32px">
    Henüz işlem kaydı yok.
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>Tarih</th><th>İşlem</th><th>Durum</th><th>Detay (Response)</th></tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
        <td><?= h($l['action']) ?></td>
        <td><span class="badge badge-<?= $l['status']==='success'?'success':'danger' ?>"><?= h($l['status']) ?></span></td>
        <td style="font-size:11px;color:var(--text-muted);max-width:500px;font-family:monospace">
          <?= h(mb_substr($l['response'] ?? '', 0, 200)) ?><?= mb_strlen($l['response'] ?? '') > 200 ? '…' : '' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
