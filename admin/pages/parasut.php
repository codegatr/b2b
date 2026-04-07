<?php
requireAdmin();

$msg = '';

// Bağlantı testi
if (isset($_GET['test'])) {
    try {
        $result = parasut()->testConnection();
        $msg = 'success:Bağlantı başarılı — Firma: ' . ($result['data']['attributes']['name'] ?? 'OK');
    } catch (Exception $e) {
        $msg = 'error:' . $e->getMessage();
    }
}

// Paraşüt log
$logs = dbRows("SELECT * FROM b2b_parasut_log ORDER BY created_at DESC LIMIT 30");

// İstatistik
$syncedDealers  = (int)dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE parasut_contact_id IS NOT NULL AND parasut_contact_id != ''");
$syncedInvoices = (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE parasut_invoice_id IS NOT NULL AND parasut_invoice_id != ''");
$syncedPayments = (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE parasut_payment_id IS NOT NULL AND parasut_payment_id != ''");

$hasToken   = !empty(setting('parasut_email')) && !empty(setting('parasut_company_id'));
$tokenExpiry= setting('parasut_token_expires', '');
$tokenValid = $tokenExpiry && strtotime($tokenExpiry) > time();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Paraşüt Entegrasyonu</h1>
    <p class="page-sub">Muhasebe ve fatura senkronizasyonu</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="?page=parasut&test=1" class="btn btn-secondary">Bağlantıyı Test Et</a>
    <a href="?page=settings&tab=parasut" class="btn btn-primary">Ayarları Düzenle</a>
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
      <div class="stat-value"><?= $syncedDealers ?></div>
      <div class="stat-label">Senkronize Bayi</div>
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
    <div class="stat-icon purple">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    </div>
    <div class="stat-info">
      <div class="stat-value"><?= $syncedPayments ?></div>
      <div class="stat-label">Senkronize Ödeme</div>
    </div>
  </div>
</div>

<!-- Ayar Özeti -->
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
        <div class="stat-label">Token Durumu</div>
        <div style="margin-top:2px">
          <?php if ($tokenValid): ?>
          <span class="badge badge-success">Geçerli — <?= date('d.m.Y H:i', strtotime($tokenExpiry)) ?> kadar</span>
          <?php elseif ($tokenExpiry): ?>
          <span class="badge badge-danger">Süresi Dolmuş</span>
          <?php else: ?>
          <span class="badge badge-neutral">Henüz alınmadı</span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <div class="stat-label">Satış Hesabı</div>
        <div style="font-weight:600;margin-top:2px"><?= h(setting('parasut_sales_account') ?: '—') ?></div>
      </div>
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
        <td style="font-size:12px;color:var(--text-muted)"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
        <td><?= h($l['action']) ?></td>
        <td><span class="badge badge-<?= $l['status']==='success'?'success':'danger' ?>"><?= h($l['status']) ?></span></td>
        <td style="font-size:12px;color:var(--text-muted);max-width:400px">
          <?= h(mb_substr($l['response'] ?? '', 0, 100)) ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
