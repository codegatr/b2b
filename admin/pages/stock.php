<?php
// admin/pages/stock.php — Stok Yönetimi + Paraşüt Sync
requireAdmin();

$success = '';
$error   = '';
$syncResult = null;

// Paraşüt'ten stok al
if (isset($_GET['parasut_sync'])) {
    $syncResult = parasutSyncStock();
    if (empty($syncResult['errors'])) {
        $success = "Paraşüt stok sync tamamlandı. {$syncResult['synced']} ürün güncellendi.";
    } else {
        $error = implode(', ', $syncResult['errors']);
    }
}

// Manuel stok güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'update_stock') {
    csrfCheck();
    $productId = intval($_POST['product_id'] ?? 0);
    $newStock  = intval($_POST['stock'] ?? 0);
    $note      = trim($_POST['note'] ?? '');

    if ($productId) {
        $old = dbVal("SELECT stock FROM b2b_products WHERE id=?", [$productId]);
        dbExec("UPDATE b2b_products SET stock=?, updated_at=NOW() WHERE id=?", [$newStock, $productId]);
        auditLog('stock_update', 'b2b_products', $productId, ['old'=>$old,'new'=>$newStock,'note'=>$note]);
        $success = 'Stok güncellendi.';
    }
}

// Toplu stok güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'bulk_update') {
    csrfCheck();
    $updates = $_POST['stock'] ?? [];
    $count   = 0;
    foreach ($updates as $pid => $qty) {
        $pid = intval($pid);
        $qty = intval($qty);
        if ($pid > 0) {
            dbExec("UPDATE b2b_products SET stock=?, updated_at=NOW() WHERE id=?", [$qty, $pid]);
            $count++;
        }
    }
    $success = "$count ürün stoku güncellendi.";
}

// Filtreler
$filter  = $_GET['filter'] ?? 'all'; // all | low | none
$search  = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
$where[] = 'p.is_active=1';  // her zaman sadece aktif ürünler
if ($filter === 'ok')   { $where[] = 'p.stock > p.stock_critical'; }
elseif ($filter === 'low')  { $where[] = 'p.stock > 0 AND p.stock <= p.stock_critical'; }
elseif ($filter === 'none') { $where[] = 'p.stock <= 0'; }
if ($search) { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $s="%$search%"; $params[]=$s; $params[]=$s; }

$products = dbRows(
    "SELECT p.*, c.name as cat_name,
            -- Son 30 gün satılan adet
            (SELECT COALESCE(SUM(oi.qty), 0)
             FROM b2b_order_items oi
             JOIN b2b_orders o ON o.id=oi.order_id
             WHERE oi.product_id=p.id
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND o.status NOT IN ('iptal','iade')
            ) AS sold_30d,
            -- Son 30 gün ortalama günlük satış
            (SELECT COALESCE(SUM(oi.qty), 0) / 30
             FROM b2b_order_items oi
             JOIN b2b_orders o ON o.id=oi.order_id
             WHERE oi.product_id=p.id
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND o.status NOT IN ('iptal','iade')
            ) AS daily_avg
     FROM b2b_products p
     LEFT JOIN b2b_categories c ON c.id=p.category_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY p.stock ASC, p.name",
    $params
);

$stats = [
    'total'    => dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1"),
    'low'      => dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1 AND stock <= stock_critical AND stock > 0"),
    'none'     => dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1 AND stock <= 0"),
    'ok'       => dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1 AND stock > stock_critical"),
];

$parasutEnabled = !empty(setting('parasut_email')) && !empty(setting('parasut_company_id'));
?>
<div class="page-header">
  <div><h1 class="page-title">Stok Yönetimi</h1></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?page=stock-scan" class="btn btn-primary" style="background:#c1272d;border-color:#c1272d">📷 QR/Barkod Tara</a>
    <?php if ($parasutEnabled): ?>
    <a href="?page=stock&parasut_sync=1"
       onclick="return confirm('Paraşüt\'ten stok bilgisi çekilecek. Devam edilsin mi?')"
       class="btn btn-secondary">
      🔗 Paraşüt\'ten Stok Al
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if (!$parasutEnabled): ?>
<div class="alert alert-warning" style="margin-bottom:20px">
  <strong>Paraşüt entegrasyonu aktif değil.</strong>
  Otomatik stok senkronizasyonu için
  <a href="?page=settings&tab=parasut">Paraşüt ayarlarını</a> yapılandırın.
</div>
<?php endif; ?>

<!-- Özet -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card" onclick="location='?page=stock'" style="cursor:pointer">
    <div class="stat-icon teal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg></div>
    <div class="stat-info"><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">Toplam Ürün</div></div>
  </div>
  <div class="stat-card" onclick="location='?page=stock&filter=ok'" style="cursor:pointer">
    <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
    <div class="stat-info"><div class="stat-value"><?= $stats['ok'] ?></div><div class="stat-label">Stok Yeterli</div></div>
  </div>
  <div class="stat-card" onclick="location='?page=stock&filter=low'" style="cursor:pointer">
    <div class="stat-icon amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
    <div class="stat-info"><div class="stat-value"><?= $stats['low'] ?></div><div class="stat-label">Kritik Stok</div></div>
  </div>
  <div class="stat-card" onclick="location='?page=stock&filter=none'" style="cursor:pointer">
    <div class="stat-icon red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
    <div class="stat-info"><div class="stat-value"><?= $stats['none'] ?></div><div class="stat-label">Stok Tükendi</div></div>
  </div>
</div>

<!-- Filtre + Arama -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <div class="tab-bar" style="margin-bottom:0">
    <?php foreach (['all'=>'Tümü','ok'=>'Yeterli','low'=>'Kritik','none'=>'Tükendi'] as $k=>$v): ?>
    <a href="?page=stock&filter=<?= $k ?><?= $search?"&q=".urlencode($search):'' ?>" class="tab-item <?= $filter===$k?'active':'' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" style="display:flex;gap:6px;flex:1;min-width:200px">
    <input type="hidden" name="page" value="stock">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Ürün adı veya SKU...">
    <button type="submit" class="btn btn-secondary">Ara</button>
  </form>
</div>

<!-- Toplu Güncelleme Tablosu -->
<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="form_action" value="bulk_update">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Stok Listesi (<?= count($products) ?>)</h3>
      <button type="submit" class="btn btn-primary btn-sm">💾 Tüm Değişiklikleri Kaydet</button>
    </div>
    <div class="table-wrap">
      <table class="table" style="font-size:13px">
        <thead>
          <tr>
            <th style="width:60px">Ürün</th>
            <th>İsim / SKU</th>
            <th style="width:120px">Kategori</th>
            <th style="width:110px">Baz Fiyat<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">KDV Dahil</span></th>
            <th style="width:80px">Kritik</th>
            <th style="width:180px">Stok Durumu</th>
            <th style="width:110px">Son 30 Gün<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">Satış / Gün</span></th>
            <th style="width:120px">Yeni Stok</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
        <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted)">Ürün bulunamadı.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $p):
            $stockNum  = (int)$p['stock'];
            $critical  = (int)$p['stock_critical'];
            $sold30d   = (int)($p['sold_30d'] ?? 0);
            $dailyAvg  = (float)($p['daily_avg'] ?? 0);

            if ($stockNum <= 0) { $stockClass='danger'; $barColor='#dc2626'; $bgColor='#fee2e2'; $statusText='Stoksuz'; }
            elseif ($stockNum <= $critical) { $stockClass='warning'; $barColor='#ea580c'; $bgColor='#ffedd5'; $statusText='Kritik'; }
            else { $stockClass='success'; $barColor='#16a34a'; $bgColor='#dcfce7'; $statusText='Yeterli'; }

            // Stok bar - 0..(critical*4) aralığını göster, ↑'sı %100
            $barMax = max($critical * 4, 1);
            $barPct = min(100, ($stockNum / $barMax) * 100);

            // Tahmini bitiş süresi
            $daysLeft = $dailyAvg > 0 ? $stockNum / $dailyAvg : null;
        ?>
        <tr>
          <!-- Resim -->
          <td>
            <?php if (!empty($p['image'])): ?>
              <img src="/uploads/products/<?= h($p['image']) ?>"
                   alt="<?= h($p['name']) ?>"
                   style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block">
            <?php else: ?>
              <div style="width:48px;height:48px;border-radius:8px;background:linear-gradient(135deg,#e5e7eb,#f3f4f6);display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:20px">📦</div>
            <?php endif; ?>
          </td>

          <!-- İsim + SKU -->
          <td>
            <div style="font-weight:600"><?= h($p['name']) ?></div>
            <?php if (!empty($p['sku'])): ?>
              <code style="font-size:10.5px;color:var(--text-muted);background:#f3f4f6;padding:1px 6px;border-radius:3px"><?= h($p['sku']) ?></code>
            <?php endif; ?>
          </td>

          <!-- Kategori -->
          <td>
            <?php if (!empty($p['cat_name'])): ?>
              <span class="badge" style="background:#ede9fe;color:#6b21a8;font-size:10px;font-weight:600"><?= h($p['cat_name']) ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:11px">—</span>
            <?php endif; ?>
          </td>

          <!-- Baz Fiyat (KDV Dahil) -->
          <td style="font-weight:600;font-size:12px">
            <?= moneyInc((float)$p['base_price'], $p['vat_rate'] ?? 20) ?>
            <div style="font-size:9px;color:var(--text-muted);font-weight:400">%<?= number_format((float)($p['vat_rate'] ?? 20), 0) ?> KDV</div>
          </td>

          <!-- Kritik Seviye -->
          <td style="text-align:center;font-size:12px;color:var(--text-muted)">
            <?= $critical ?>
          </td>

          <!-- Stok Durumu (bar + badge) -->
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <span class="badge" style="background:<?= $bgColor ?>;color:<?= $barColor ?>;font-size:11px;font-weight:700;min-width:48px;text-align:center"><?= $stockNum ?></span>
              <div style="flex:1;height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden">
                <div style="height:100%;width:<?= $barPct ?>%;background:<?= $barColor ?>;transition:width 0.3s"></div>
              </div>
            </div>
            <div style="font-size:10px;color:<?= $barColor ?>;font-weight:600;margin-top:3px;text-transform:uppercase;letter-spacing:.3px"><?= $statusText ?></div>
          </td>

          <!-- Satış istatistik -->
          <td>
            <?php if ($sold30d > 0): ?>
              <div style="font-weight:600;font-size:12px"><?= $sold30d ?> adet</div>
              <div style="font-size:10px;color:var(--text-muted)">
                ~<?= number_format($dailyAvg, 1, ',', '.') ?> / gün
                <?php if ($daysLeft !== null && $daysLeft < 14): ?>
                <br><span style="color:#ea580c;font-weight:600">~<?= round($daysLeft) ?> gün kalır</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:11px">—</span>
            <?php endif; ?>
          </td>

          <!-- Yeni Stok input -->
          <td>
            <input type="number" name="stock[<?= $p['id'] ?>]"
                   value="<?= $stockNum ?>" min="0"
                   class="form-control" style="width:100%;height:36px;padding:4px 8px;font-weight:600;text-align:center;border:2px solid #e5e7eb;font-size:13px">
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($products)): ?>
    <div style="padding:14px 18px;border-top:1px solid var(--border);background:#fafafa;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <div style="flex:1;font-size:12px;color:var(--text-muted)">
        💡 Değişiklik yaptığın input'lar otomatik kaydedilmez. Tüm değişiklikleri yapıp <strong>"Tüm Değişiklikleri Kaydet"</strong> butonuna basın.
      </div>
      <button type="submit" class="btn btn-primary">💾 Tüm Değişiklikleri Kaydet</button>
    </div>
    <?php endif; ?>
  </div>
</form>
