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
    "SELECT p.*, c.name as cat_name
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
    <a href="?page=stock_scan" class="btn btn-primary" style="background:#c1272d;border-color:#c1272d">📷 QR/Barkod Tara</a>
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
      <button type="submit" class="btn btn-primary btn-sm">Tüm Değişiklikleri Kaydet</button>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Ürün Adı</th>
            <th>Kategori</th>
            <th>Kritik Seviye</th>
            <th>Mevcut Stok</th>
            <th>Yeni Stok</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($products)): ?>
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">Ürün bulunamadı.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $p): ?>
        <?php
        $stockClass = $p['stock'] <= 0 ? 'danger' : ($p['stock'] <= $p['stock_critical'] ? 'warning' : 'success');
        ?>
        <tr>
          <td><code style="font-size:11px"><?= h($p['sku'] ?? '—') ?></code></td>
          <td class="fw-600"><?= h($p['name']) ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= h($p['cat_name'] ?? '—') ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= (int)$p['stock_critical'] ?></td>
          <td>
            <span class="badge badge-<?= $stockClass ?>"><?= (int)$p['stock'] ?></span>
          </td>
          <td>
            <input type="number" name="stock[<?= $p['id'] ?>]"
                   value="<?= (int)$p['stock'] ?>" min="0"
                   class="form-control" style="width:90px;height:34px;padding:4px 8px">
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($products)): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--border)">
      <button type="submit" class="btn btn-primary">Tüm Değişiklikleri Kaydet</button>
    </div>
    <?php endif; ?>
  </div>
</form>
