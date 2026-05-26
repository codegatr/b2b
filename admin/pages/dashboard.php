<?php
/**
 * Admin Dashboard
 */
$stats = [
    'dealers'       => (int)dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE is_active=1"),
    'orders_today'  => (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE DATE(created_at)=CURDATE()"),
    'pending_orders'=> (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE status='bekliyor'"),
    'pending_pay'   => (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='bekliyor'"),
    'revenue_today' => (float)dbVal("SELECT COALESCE(SUM(grand_total),0) FROM b2b_orders WHERE DATE(created_at)=CURDATE() AND status NOT IN('iptal','iade')"),
    'revenue_month' => (float)dbVal("SELECT COALESCE(SUM(grand_total),0) FROM b2b_orders WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND status NOT IN('iptal','iade')"),
    'products'      => (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1"),
    'low_stock'     => (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE stock <= stock_critical AND stock > 0 AND is_active=1"),
    'no_stock'      => (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE stock <= 0 AND is_active=1"),
    'applications'  => (int)dbVal("SELECT COUNT(*) FROM b2b_applications WHERE status='bekliyor'"),
];

$recentOrders = dbRows(
    "SELECT o.*, d.company_name, d.first_name, d.last_name, d.type
     FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id
     ORDER BY o.created_at DESC LIMIT 8"
);

$recentPayments = dbRows(
    "SELECT p.*, d.company_name, d.first_name, d.last_name
     FROM b2b_payments p JOIN b2b_dealers d ON d.id=p.dealer_id
     WHERE p.status='bekliyor'
     ORDER BY p.created_at DESC LIMIT 5"
);

$topProducts = dbRows(
    "SELECT
        oi.product_id,
        oi.product_name,
        oi.product_sku,
        COALESCE(p.unit, 'adet') AS unit,
        SUM(oi.qty) AS total_qty,
        SUM(oi.line_total) AS total_amount,
        COUNT(DISTINCT oi.order_id) AS order_count
     FROM b2b_order_items oi
     JOIN b2b_orders o ON o.id=oi.order_id
     LEFT JOIN b2b_products p ON p.id=oi.product_id
     WHERE o.status NOT IN ('iptal','iade')
       AND o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
     GROUP BY oi.product_id, oi.product_name, oi.product_sku, p.unit
     ORDER BY total_qty DESC, total_amount DESC
     LIMIT 8"
);
?>
<div class="page-body">

<!-- Selamlama Header -->
<?php
$adminName = trim(($admin['full_name'] ?? '') ?: ($admin['username'] ?? 'Yönetici'));
$hour = (int)date('H');
$greeting = $hour < 6 ? 'İyi geceler' : ($hour < 12 ? 'Günaydın' : ($hour < 18 ? 'İyi günler' : 'İyi akşamlar'));
$trMonths = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$trDays   = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
$trDate   = date('j') . ' ' . $trMonths[(int)date('n')-1] . ' ' . date('Y') . ', ' . $trDays[(int)date('w')];
?>
<div class="page-header" style="margin-bottom:14px">
  <div style="min-width:0;flex:1">
    <h1 class="page-title" style="margin:0;line-height:1.25;word-wrap:break-word">
      <?= h($greeting) ?>, <?= h($adminName) ?> 👋
    </h1>
    <p class="page-sub" style="margin:4px 0 0;line-height:1.4;word-wrap:break-word">
      <?= h($trDate) ?>
      <span class="hide-on-narrow"> · <span style="color:var(--text-muted)">İşletmenizi yönetin</span></span>
    </p>
  </div>
</div>

<!-- İstatistikler -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['dealers'] ?></div>
      <div class="stat-label">Aktif Bayi</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['pending_orders'] ?></div>
      <div class="stat-label">Bekleyen Sipariş</div>
      <?php if ($stats['orders_today']): ?><div class="stat-change up">+<?= $stats['orders_today'] ?> bugün</div><?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div class="stat-info">
      <div class="stat-value"><?= money($stats['revenue_today']) ?></div>
      <div class="stat-label">Bugün Yapılan Ciro</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div class="stat-info">
      <div class="stat-value"><?= money($stats['revenue_month']) ?></div>
      <div class="stat-label">Bu Ay Ciro</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['pending_pay'] ?></div>
      <div class="stat-label">Bekleyen Ödeme</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon <?= $stats['no_stock'] > 0 ? 'red' : ($stats['low_stock'] > 0 ? 'amber' : 'green') ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg></div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['products'] ?></div>
      <div class="stat-label">Aktif Ürün</div>
      <?php if ($stats['no_stock'] > 0): ?><div class="stat-change down"><?= $stats['no_stock'] ?> ürün stok bitti</div><?php endif; ?>
      <?php if ($stats['low_stock'] > 0): ?><div class="stat-change down" style="color:var(--warning)"><?= $stats['low_stock'] ?> kritik stok</div><?php endif; ?>
    </div>
  </div>
  <?php if ($stats['applications'] > 0): ?>
  <div class="stat-card">
    <div class="stat-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['applications'] ?></div>
      <div class="stat-label">Bekleyen Başvuru</div>
      <div class="stat-change"><a href="?page=applications">İncele →</a></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="dashboard-main-grid" style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">

<!-- Son Siparişler -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Son Siparişler</h3>
    <a href="?page=orders" class="btn btn-secondary btn-sm">Tümünü Gör</a>
  </div>
  <div class="table-wrap">
    <table class="table table-mobile-cards">
      <thead>
        <tr>
          <th>Sipariş No</th><th>Bayi</th><th>Tutar</th><th>Durum</th><th>Tarih</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentOrders as $o):
          $dn = $o['type']==='kurumsal' ? $o['company_name'] : trim($o['first_name'].' '.$o['last_name']);
        ?>
        <tr>
          <td class="fw-600" data-label="No"><?= h($o['order_no']) ?></td>
          <td data-label="Bayi"><?= h($dn) ?></td>
          <td data-label="Tutar"><?= money((float)$o['grand_total']) ?></td>
          <td data-label="Durum"><?= orderStatusLabel($o['status']) ?></td>
          <td class="text-muted fs-12" data-label="Tarih"><?= fmtDateTime($o['created_at']) ?></td>
          <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm" style="display:block;text-align:center">Detay →</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentOrders)): ?>
        <tr><td colspan="6" class="text-center text-muted" style="padding:24px">Henüz sipariş yok</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Bekleyen Ödemeler -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Bekleyen Ödemeler</h3>
    <a href="?page=payments" class="btn btn-secondary btn-sm">Tümü</a>
  </div>
  <div class="card-body" style="padding:0">
    <?php foreach ($recentPayments as $p):
      $dn = $p['company_name'] ?: trim($p['first_name'].' '.$p['last_name']);
    ?>
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px">
      <div style="flex:1;min-width:0">
        <div class="fw-600 fs-13"><?= h($dn) ?></div>
        <div class="text-muted fs-12"><?= h(strtoupper($p['type'])) ?> · <?= fmtDate($p['payment_date']) ?></div>
      </div>
      <div style="text-align:right">
        <div class="fw-600" style="color:var(--success)"><?= money((float)$p['amount']) ?></div>
        <a href="?page=payments&status=bekliyor" class="btn btn-success btn-sm" style="margin-top:4px">İncele</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($recentPayments)): ?>
    <div class="empty-state" style="padding:24px"><p>Bekleyen ödeme yok</p></div>
    <?php endif; ?>
  </div>
</div>

</div>

<!-- En Cok Satilan Urunler -->
<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div>
      <h3 class="card-title">En Çok Satılan Ürünler</h3>
      <div class="text-muted fs-12" style="margin-top:2px">Bu ay iptal/iade hariç siparişlere göre</div>
    </div>
    <a href="?page=reports" class="btn btn-secondary btn-sm">Raporlar</a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th style="width:42px">#</th>
          <th>Ürün</th>
          <th>SKU</th>
          <th style="text-align:right">Satış</th>
          <th style="text-align:right">Sipariş</th>
          <th style="text-align:right">Tutar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topProducts as $i => $p): ?>
        <tr>
          <td class="text-muted fs-12"><?= $i + 1 ?></td>
          <td class="fw-600"><?= h($p['product_name']) ?></td>
          <td class="text-muted fs-12"><?= h($p['product_sku'] ?: '—') ?></td>
          <td style="text-align:right;font-weight:700"><?= number_format((float)$p['total_qty'], 0, ',', '.') ?> <?= h($p['unit']) ?></td>
          <td style="text-align:right"><?= (int)$p['order_count'] ?></td>
          <td style="text-align:right;color:var(--success);font-weight:700"><?= money((float)$p['total_amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($topProducts)): ?>
        <tr><td colspan="6" class="text-center text-muted" style="padding:24px">Bu ay satış verisi yok</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Stok Uyarıları -->
<?php $lowStockProducts = dbRows(
    "SELECT name, sku, stock, stock_critical FROM b2b_products
     WHERE stock <= stock_critical AND is_active=1
     ORDER BY stock ASC LIMIT 5"
);
if ($lowStockProducts): ?>
<div class="card" style="margin-top:20px">
  <div class="card-header">
    <h2>⚠ Kritik Stok Uyarıları</h2>
    <a href="?page=stock" class="btn btn-secondary btn-sm">Stok Yönetimi</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Ürün</th><th>SKU</th><th>Mevcut Stok</th><th>Kritik Seviye</th><th>Durum</th></tr></thead>
      <tbody>
        <?php foreach ($lowStockProducts as $p): ?>
        <tr>
          <td class="fw-600"><?= h($p['name']) ?></td>
          <td class="text-muted"><?= h($p['sku']??'—') ?></td>
          <td><strong style="color:<?= $p['stock']<=0?'var(--danger)':'var(--warning)' ?>"><?= $p['stock'] ?></strong></td>
          <td><?= $p['stock_critical'] ?></td>
          <td><?= stockBadge((int)$p['stock'], (int)$p['stock_critical']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div>
