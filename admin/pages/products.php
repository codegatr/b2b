<?php
// admin/pages/products.php — Ürün Yönetimi
requireAdmin();

$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);

$categories = dbRows("SELECT id, name FROM b2b_categories WHERE is_active=1 ORDER BY sort_order, name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save') {
        // Fiyat girişi: form'dan gelen fiyat KDV dahil mi yoksa hariç mi?
        // Sistem ayarı 'price_input_includes_vat' bu seçimi belirler.
        // DB'ye HER ZAMAN net (KDV hariç) yazılır — Paraşüt ve raporlama uyumlu.
        $vatRate = floatval($_POST['vat_rate'] ?? $_POST['tax_rate'] ?? 20);
        $rawPrice = floatval($_POST['base_price'] ?? 0);
        // Form içindeki seçimi kullan, yoksa default 'gross' (sistem geneli KDV Dahil kuralı)
        $priceMode = $_POST['price_mode'] ?? 'gross';
        $priceMode = ($priceMode === 'gross' || $priceMode === '1') ? 'gross' : 'net';
        // base_price kolonu DECIMAL(12,2) — 2 ondalığa yuvarlamak ZORUNLU
        $netPrice = $priceMode === 'gross'
            ? round($rawPrice / (1 + $vatRate / 100), 2)
            : round($rawPrice, 2);

        $data = [
            'category_id'       => intval($_POST['category_id'] ?? 0) ?: null,
            'name'              => trim($_POST['name'] ?? ''),
            'sku'               => trim($_POST['sku'] ?? ''),
            'barcode'           => trim($_POST['barcode'] ?? '') ?: null,
            'description'       => trim($_POST['description'] ?? ''),
            'short_description' => substr(trim($_POST['short_description'] ?? ''), 0, 100),
            'base_price'        => $netPrice,
            'unit'              => trim($_POST['unit'] ?? '') ?: 'adet',
            'min_order_qty'     => intval($_POST['min_order_qty'] ?? 1) ?: 1,
            'max_order_qty'     => intval($_POST['max_order_qty'] ?? 0) ?: null,
            'stock'             => intval($_POST['stock'] ?? 0),
            'stock_critical'    => intval($_POST['stock_critical'] ?? 5),
            'vat_rate'          => $vatRate,
            'parasut_product_id'=> trim($_POST['parasut_product_id'] ?? ''),
            'is_active'         => isset($_POST['is_active']) ? 1 : 0,
            'is_featured'       => isset($_POST['is_featured']) ? 1 : 0,
        ];

        if (empty($data['name'])) { $error = 'Ürün adı zorunludur.'; }
        else {
            try {
                // Defansif: is_featured ve barcode kolonu eski DB'de yoksa data array'inden çıkar
                try {
                    dbVal("SELECT is_featured FROM b2b_products LIMIT 1");
                } catch (\Throwable $e) {
                    unset($data['is_featured']);
                }
                try {
                    dbVal("SELECT barcode FROM b2b_products LIMIT 1");
                } catch (\Throwable $e) {
                    unset($data['barcode']);
                }
                // Resim yükleme
                if (!empty($_FILES['image']['name'])) {
                    $uploadDir = B2B_ROOT . '/uploads/products';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $file = $_FILES['image'];
                    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
                    if (isset($mime[$ext])) $file['type'] = $mime[$ext];
                    $img = uploadFile($file, $uploadDir, ['image/jpeg','image/png','image/webp']);
                    if ($img) $data['image'] = $img;
                }
                if ($id) {
                    $affected = dbUpdateRow('b2b_products', $data, 'id', $id);
                    // Stok değişimi log
                    $old = dbRow("SELECT stock FROM b2b_products WHERE id=?", [$id]);
                    if ($old && $old['stock'] != $data['stock']) {
                        $diff = $data['stock'] - $old['stock'];
                        try { dbExec("INSERT INTO b2b_stock_log (product_id, change_type, qty_before, qty_change, qty_after, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())",
                            [$id, $diff>0?'giris':'cikis', $old['stock'], abs($diff), $data['stock'], 'Admin düzenlemesi', adminId()]); } catch (\Throwable $e) {}
                    }
                    auditLog('product_updated', 'b2b_products', $id, ['name'=>$data['name']]);
                    $success = 'Ürün güncellendi.';
                } else {
                    $data['slug'] = preg_replace('/[^a-z0-9]+/','-',strtolower($data['name'])).'-'.time();
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $newId = dbInsertRow('b2b_products', $data);
                    auditLog('product_created', 'b2b_products', $newId, ['name'=>$data['name']]);
                    $success = 'Ürün eklendi.';
                    $action = 'list';
                    $id = 0;
                }
            } catch (\Throwable $e) {
                error_log('product save error: ' . $e->getMessage());
                $error = 'Ürün kaydedilemedi: ' . $e->getMessage();
            }
        }
    }

    if ($act === 'stock_adjust') {
        $pid    = intval($_POST['product_id']);
        $type   = $_POST['change_type'];
        $qty    = abs(intval($_POST['qty'] ?? $_POST['quantity'] ?? 0));
        $note   = trim($_POST['note']);
        if ($qty > 0) {
            // Güncelleme öncesi stok miktarını al
            $p = dbRow("SELECT * FROM b2b_products WHERE id=?", [$pid]);
            $qtyBefore = $p ? (int)$p['stock'] : 0;
            if ($type === 'giris') {
                dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?", [$qty, $pid]);
                $qtyAfter = $qtyBefore + $qty;
            } else {
                dbExec("UPDATE b2b_products SET stock=GREATEST(0,stock-?) WHERE id=?", [$qty, $pid]);
                $qtyAfter = max(0, $qtyBefore - $qty);
            }
            try { dbExec("INSERT INTO b2b_stock_log (product_id, change_type, qty_before, qty_change, qty_after, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())",
                [$pid, $type, $qtyBefore, $qty, $qtyAfter, $note, adminId()]); } catch (Exception $e) {}
            // Kritik stok kontrolü
            $p = dbRow("SELECT * FROM b2b_products WHERE id=?", [$pid]);
            if ($p && $p['stock'] <= $p['stock_critical']) {
                notifyAdmin('stock_critical', 'Kritik Stok', "'{$p['name']}' ürünü kritik stok seviyesine düştü: {$p['stock']} {$p['unit']}", '?page=products&action=detail&id='.$pid);
            }
            $success = 'Stok güncellendi.';
        }
        $action = 'detail'; $id = $pid;
    }

    if ($act === 'toggle') {
        $pid = intval($_POST['product_id']);
        $cur = dbVal("SELECT is_active FROM b2b_products WHERE id=?", [$pid]);
        dbExec("UPDATE b2b_products SET is_active=? WHERE id=?", [$cur?0:1, $pid]);
        redirect('?page=products');
    }

    // ── Ürün Sil ───────────────────────────────────────────────
    if ($act === 'delete_product') {
        $pid = intval($_POST['product_id'] ?? 0);
        if ($pid) {
            $prod = dbRow("SELECT id, name, image FROM b2b_products WHERE id=?", [$pid]);
            if (!$prod) {
                $error = 'Ürün bulunamadı.';
            } else {
                // Geçmiş siparişlerde kullanıldıysa silme — pasif yapmaya yönlendir.
                $usedInOrders = (int)dbVal("SELECT COUNT(*) FROM b2b_order_items WHERE product_id=?", [$pid]);
                if ($usedInOrders > 0) {
                    $error = "Bu ürün $usedInOrders sipariş kaleminde kullanıldığı için silinemez. " .
                             "Sipariş geçmişinin bütünlüğü için kayıt korunur. " .
                             "Bayilere göstermek istemiyorsanız 'Pasif' yapabilirsiniz.";
                } else {
                    try {
                        // Bağlı veriyi de temizle (geçmiş sipariş yoksa güvenli)
                        try { dbExec("DELETE FROM b2b_stock_log WHERE product_id=?", [$pid]); } catch (\Throwable $e) {}
                        try { dbExec("DELETE FROM b2b_price_list_items WHERE product_id=?", [$pid]); } catch (\Throwable $e) {}
                        try { dbExec("DELETE FROM b2b_cart_items WHERE product_id=?", [$pid]); } catch (\Throwable $e) {}
                        dbExec("DELETE FROM b2b_products WHERE id=?", [$pid]);

                        // Resim dosyası varsa sil
                        if (!empty($prod['image'])) {
                            $imgPath = B2B_ROOT . '/uploads/products/' . $prod['image'];
                            if (file_exists($imgPath)) @unlink($imgPath);
                        }
                        auditLog('product_deleted', 'b2b_products', $pid, ['name' => $prod['name']]);
                        $_SESSION['flash_success'] = 'Ürün kalıcı olarak silindi: ' . $prod['name'];
                    } catch (\Throwable $e) {
                        $error = 'Ürün silinemedi: ' . $e->getMessage();
                    }
                }
            }
            if (empty($error)) redirect('?page=products');
            // Hata varsa $action='list' ile aşağıda render edilecek
            $action = 'list'; $id = 0;
        }
    }
}

$product = null;
if (in_array($action, ['edit','detail']) && $id) {
    $product = dbRow("SELECT p.*, c.name AS cat_name FROM b2b_products p LEFT JOIN b2b_categories c ON c.id=p.category_id WHERE p.id=?", [$id]);
    if (!$product) { $action = 'list'; $id = 0; }
}

if ($action === 'list') {
    $search  = trim($_GET['q'] ?? '');
    $catId   = intval($_GET['cat'] ?? 0);
    $stock   = $_GET['stock'] ?? '';
    $active  = $_GET['active'] ?? '';
    $perPage = 25;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['1=1']; $params = [];
    if ($search) { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)'; $s="%$search%"; $params[]=$s; $params[]=$s; $params[]=$s; }
    if ($catId)  { $where[] = 'p.category_id=?'; $params[] = $catId; }
    if ($stock === 'critical') { $where[] = 'p.stock <= p.stock_critical AND p.stock > 0'; }
    elseif ($stock === 'zero') { $where[] = 'p.stock = 0'; }
    elseif ($stock === 'ok')   { $where[] = 'p.stock > p.stock_critical'; }
    if ($active === '1')       { $where[] = 'p.is_active=1'; }
    elseif ($active === '0')   { $where[] = 'p.is_active=0'; }

    $w = implode(' AND ',$where);
    $total    = dbVal("SELECT COUNT(*) FROM b2b_products p WHERE $w", $params);
    $products = dbRows(
        "SELECT p.*, c.name AS cat_name FROM b2b_products p LEFT JOIN b2b_categories c ON c.id=p.category_id WHERE $w ORDER BY p.name LIMIT $perPage OFFSET $offset",
        $params
    );
    $pager = pagination($total, $perPage, $page, "?page=products&q=".urlencode($search)."&cat=$catId&stock=$stock&active=$active&p=");

    // Stok özet sayıları
    $stockCounts = [
        'all'      => (int)dbVal("SELECT COUNT(*) FROM b2b_products"),
        'ok'       => (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE stock > stock_critical"),
        'critical' => (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE stock <= stock_critical AND stock > 0"),
        'zero'     => (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE stock = 0"),
    ];
}
?>

<?php if ($action === 'list'): ?>
<?php
// Session flash mesajını oku (delete sonrası redirect ile gelir)
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Ürünler</h1>
        <p class="page-sub">Toplam <?= $total ?> ürün gösteriliyor</p>
    </div>
    <div class="btn-group">
        <a href="?page=categories" class="btn btn-ghost">Kategoriler</a>
        <a href="?page=products&action=add" class="btn btn-primary">＋ Yeni Ürün</a>
    </div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<!-- Stok Hızlı Filtreler -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <?php
    $stockFilters = [
        ''         => ['label'=>'Tümü',          'count'=>$stockCounts['all'],      'color'=>'var(--text-2)',   'bg'=>'var(--bg)'],
        'ok'       => ['label'=>'Stokta',         'count'=>$stockCounts['ok'],       'color'=>'var(--success)', 'bg'=>'#f0fdf4'],
        'critical' => ['label'=>'Kritik Stok',    'count'=>$stockCounts['critical'], 'color'=>'var(--warning)', 'bg'=>'#fffbeb'],
        'zero'     => ['label'=>'Stok Yok',       'count'=>$stockCounts['zero'],     'color'=>'var(--danger)',  'bg'=>'#fef2f2'],
    ];
    foreach ($stockFilters as $sv => $sf):
        $isActive = $stock === $sv;
        $href = "?page=products&q=".urlencode($search)."&cat=$catId&stock=$sv&active=$active";
    ?>
    <a href="<?= $href ?>" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;border:1px solid <?= $isActive?$sf['color']:'var(--border)' ?>;background:<?= $isActive?$sf['bg']:'#fff' ?>;color:<?= $sf['color'] ?>">
        <?= h($sf['label']) ?>
        <span style="background:<?= $sf['color'] ?>;color:#fff;border-radius:99px;font-size:10px;font-weight:700;padding:1px 6px;min-width:18px;text-align:center"><?= $sf['count'] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Kategori Hızlı Filtreler -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
    <a href="?page=products&q=<?= urlencode($search) ?>&cat=0&stock=<?= $stock ?>&active=<?= $active ?>"
       style="display:inline-flex;align-items:center;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:500;text-decoration:none;border:1px solid <?= $catId===0?'var(--red)':'var(--border)' ?>;background:<?= $catId===0?'var(--red)':'#fff' ?>;color:<?= $catId===0?'#fff':'var(--text-2)' ?>">
        Tümü
    </a>
    <?php foreach ($categories as $c): ?>
    <?php $cnt = (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE category_id=?",[$c['id']]); ?>
    <a href="?page=products&q=<?= urlencode($search) ?>&cat=<?= $c['id'] ?>&stock=<?= $stock ?>&active=<?= $active ?>"
       style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:500;text-decoration:none;border:1px solid <?= $catId==$c['id']?'var(--red)':'var(--border)' ?>;background:<?= $catId==$c['id']?'var(--red)':'#fff' ?>;color:<?= $catId==$c['id']?'#fff':'var(--text-2)' ?>">
        <?= h($c['name']) ?>
        <span style="font-size:10px;opacity:.75"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Arama + Aktif Filtresi -->
<div class="filter-bar card mb-4">
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="products">
        <input type="hidden" name="cat" value="<?= $catId ?>">
        <input type="hidden" name="stock" value="<?= h($stock) ?>">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Ürün adı, SKU veya barkod…" class="form-control" style="max-width:280px">
        <select name="active" class="form-control" style="max-width:140px">
            <option value="">Tüm Durum</option>
            <option value="1" <?= $active==='1'?'selected':'' ?>>Aktif</option>
            <option value="0" <?= $active==='0'?'selected':'' ?>>Pasif</option>
        </select>
        <button type="submit" class="btn btn-secondary">Ara</button>
        <a href="?page=products" class="btn btn-ghost">Temizle</a>
    </form>
</div>

<div class="card">
<table class="table">
    <thead>
        <tr><th>Ürün</th><th>SKU</th><th>Kategori</th><th>Fiyat</th><th>Stok</th><th>Min.Sipariş</th><th>Durum</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($products as $p): ?>
    <tr>
        <td>
            <?php if ($p['image']): ?><img src="/uploads/products/<?= h($p['image']) ?>" style="width:32px;height:32px;object-fit:cover;border-radius:4px;margin-right:8px;vertical-align:middle"><?php endif; ?>
            <a href="?page=products&action=detail&id=<?= $p['id'] ?>"><?= h($p['name']) ?></a>
            <?php if (!empty($p['is_featured'])): ?><span title="Kampanyalı ürün — bayi dashboard slider'ında görünür" style="display:inline-block;margin-left:6px;font-size:10px;background:#fef2f2;color:#c1272d;padding:1px 6px;border-radius:99px;font-weight:700;border:1px solid #fecaca">🔥 KAMPANYA</span><?php endif; ?>
        </td>
        <td class="mono text-sm"><?= h($p['sku']) ?></td>
        <td><?= h($p['cat_name'] ?? '—') ?></td>
        <td>
            <?php
            $listVat  = (float)$p['vat_rate'];
            $netP     = (float)$p['base_price'];
            $grossP   = $netP * (1 + $listVat/100);
            ?>
            <strong><?= money($grossP) ?></strong>
            <div style="font-size:10px;color:var(--text-muted)">KDV Dahil · Net: <?= money($netP) ?></div>
        </td>
        <td><?= stockBadge($p['stock'], $p['stock_critical']) ?> <?= $p['stock'] ?> <?= h($p['unit']) ?></td>
        <td><?= $p['min_order_qty'] ?> <?= h($p['unit']) ?></td>
        <td>
            <form method="post" enctype="multipart/form-data" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="toggle">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <button type="submit" class="badge badge-<?= $p['is_active']?'green':'gray' ?>" style="border:none;cursor:pointer"><?= $p['is_active']?'Aktif':'Pasif' ?></button>
            </form>
        </td>
        <td class="text-right">
            <a href="?page=products&action=detail&id=<?= $p['id'] ?>" class="btn btn-xs btn-ghost">Detay</a>
            <a href="?page=products&action=edit&id=<?= $p['id'] ?>"   class="btn btn-xs btn-secondary">Düzenle</a>
            <form method="post" style="display:inline" onsubmit="return confirm('&#34;<?= h(addslashes($p['name'])) ?>&#34; ürününü kalıcı olarak silmek istediğinize emin misiniz?\n\nBu işlem geri alınamaz. Eğer ürün geçmiş siparişlerde kullanıldıysa silme işlemi reddedilir.');">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="delete_product">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-xs" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-weight:600" title="Sil">🗑</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($products)): ?><tr><td colspan="8" class="text-center text-muted py-8">Ürün bulunamadı.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= $pager ?>

<?php elseif ($action === 'detail' && $product): ?>
<?php
$stockLog = dbRows("SELECT sl.*, COALESCE(a.name, 'Sistem') AS created_by_name FROM b2b_stock_log sl LEFT JOIN b2b_admin_users a ON a.id=sl.created_by WHERE sl.product_id=? ORDER BY sl.created_at DESC LIMIT 20", [$id]);
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($product['name']) ?></h1>
        <p class="page-sub">SKU: <?= h($product['sku']) ?></p>
    </div>
    <div class="btn-group">
        <a href="?page=products" class="btn btn-ghost">← Geri</a>
        <a href="?page=products&action=edit&id=<?= $id ?>" class="btn btn-secondary">Düzenle</a>
        <form method="post" style="display:inline" onsubmit="return confirm('&#34;<?= h(addslashes($product['name'])) ?>&#34; ürününü kalıcı olarak silmek istediğinize emin misiniz?\n\nBu işlem geri alınamaz. Eğer ürün geçmiş siparişlerde kullanıldıysa silme işlemi reddedilir.');">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="delete_product">
            <input type="hidden" name="product_id" value="<?= $id ?>">
            <button type="submit" class="btn" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-weight:600">🗑 Sil</button>
        </form>
    </div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="grid grid-cols-4 gap-4 mb-6">
    <?php
    $dNet   = (float)$product['base_price'];
    $dVat   = (float)$product['vat_rate'];
    $dGross = $dNet * (1 + $dVat/100);
    ?>
    <div class="stat-card">
        <div class="stat-label">Taban Fiyat</div>
        <div class="stat-value"><?= money($dGross) ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">KDV Dahil · Net: <?= money($dNet) ?></div>
    </div>
    <div class="stat-card"><div class="stat-label">Stok</div><div class="stat-value"><?= stockBadge($product['stock'],$product['stock_critical']) ?> <?= $product['stock'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Kritik Stok</div><div class="stat-value"><?= $product['stock_critical'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Min. Sipariş</div><div class="stat-value"><?= $product['min_order_qty'] ?> <?= h($product['unit']) ?></div></div>
</div>

<!-- Stok Hareketi Ekle -->
<div class="card mb-6">
    <div class="card-header"><h3>Stok Hareketi Ekle</h3></div>
    <div class="card-body">
        <form method="post" class="form-inline-grid">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="stock_adjust">
            <input type="hidden" name="product_id" value="<?= $id ?>">
            <div class="form-group">
                <label>İşlem</label>
                <select name="change_type" class="form-control">
                    <option value="giris">Stok Girişi</option>
                    <option value="cikis">Stok Çıkışı</option>
                    <option value="sayim">Sayım Düzeltme</option>
                    <option value="iade">İade</option>
                </select>
            </div>
            <div class="form-group">
                <label>Miktar</label>
                <input type="number" name="qty" min="1" value="1" class="form-control" style="max-width:100px">
            </div>
            <div class="form-group" style="flex:1">
                <label>Not</label>
                <input type="text" name="note" placeholder="Açıklama…" class="form-control">
            </div>
            <div class="form-group" style="align-self:flex-end">
                <button type="submit" class="btn btn-primary">Kaydet</button>
            </div>
        </form>
    </div>
</div>

<!-- Stok Geçmişi -->
<div class="card">
    <div class="card-header"><h3>Stok Geçmişi</h3></div>
    <table class="table">
        <thead><tr><th>Tarih</th><th>İşlem</th><th>Miktar</th><th>Not</th><th>Yapan</th></tr></thead>
        <tbody>
        <?php foreach ($stockLog as $sl): ?>
        <tr>
            <td><?= fmtDate($sl['created_at']) ?></td>
            <td><span class="badge badge-<?= $sl['change_type']==='giris'||$sl['change_type']==='iade'?'green':'red' ?>"><?= h($sl['change_type']) ?></span></td>
            <td><?= $sl['qty_change'] ?> <?= h($product['unit']) ?></td>
            <td><?= h($sl['note']) ?></td>
            <td><?= h($sl['created_by_name']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($stockLog)): ?><tr><td colspan="5" class="text-muted text-center">Kayıt yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php else: ?>
<!-- ═══════════════════ FORM ═══════════════════ -->
<div class="page-header">
    <div><h1 class="page-title"><?= $action==='add'?'Yeni Ürün':'Ürün Düzenle' ?></h1></div>
    <a href="?page=products<?= $id?"&action=detail&id=$id":'' ?>" class="btn btn-ghost">← Geri</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="alert alert-success" style="font-size:13px;line-height:1.5"><?= h($success) ?></div><?php endif; ?>

<div class="card">
<div class="card-body">
<form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="form_action" value="save">

    <div class="form-grid-2">
        <div class="form-group">
            <label>Ürün Adı *</label>
            <input type="text" name="name" value="<?= h($product['name']??'') ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>SKU (Stok Kodu)</label>
            <input type="text" name="sku" value="<?= h($product['sku']??'') ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>
                Barkod (QR/EAN)
                <span style="font-size:11px;color:var(--text-muted);font-weight:400">— tarama için kullanılır</span>
            </label>
            <div style="display:flex;gap:6px">
                <input type="text" name="barcode" id="prod_barcode" value="<?= h($product['barcode']??'') ?>"
                       class="form-control" style="flex:1;font-family:ui-monospace,monospace"
                       placeholder="EAN-13, UPC, manuel kod vb.">
                <button type="button" class="btn btn-ghost btn-sm" onclick="generateBarcode()" title="Otomatik kod üret"
                        style="white-space:nowrap;font-size:12px">⚙ Otomatik</button>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                Boş bırakılırsa SKU veya ID ile tarama yapılır.
            </div>
        </div>
        <script>
        function generateBarcode() {
            // Basit kod: BBC + tarih + random 4 hane (toplam ~14 karakter, EAN benzeri uzunluk)
            const dt = new Date();
            const yy = String(dt.getFullYear()).slice(-2);
            const mm = String(dt.getMonth()+1).padStart(2, '0');
            const dd = String(dt.getDate()).padStart(2, '0');
            const rnd = String(Math.floor(Math.random()*10000)).padStart(4, '0');
            const skuFragment = (document.querySelector('input[name="sku"]').value || 'P').replace(/\W+/g,'').slice(0,4).toUpperCase().padEnd(4, '0');
            document.getElementById('prod_barcode').value = `${skuFragment}${yy}${mm}${dd}${rnd}`;
        }
        </script>
        <div class="form-group">
            <label>Kategori</label>
            <select name="category_id" class="form-control">
                <option value="">— Kategori Seç —</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($product['category_id']??'')==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Birim</label>
            <select name="unit" class="form-control">
                <?php foreach (['adet','kg','ton','litre','metre','m2','m3','kutu','paket'] as $u): ?>
                <option value="<?= $u ?>" <?= ($product['unit']??'adet')===$u?'selected':'' ?>><?= $u ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        // Form aç: HER ZAMAN 'KDV Dahil' modunda açılsın (sistem geneli kuralı).
        // Kullanıcı son submit'te değiştirdiyse o korunur.
        $editingMode = $_POST['price_mode'] ?? 'gross';
        if (!in_array($editingMode, ['gross','net'], true)) $editingMode = 'gross';
        $netStored = (float)($product['base_price'] ?? 0);
        $vatStored = (float)($product['vat_rate'] ?? 20);
        $displayPrice = $editingMode === 'gross'
            ? round($netStored * (1 + $vatStored / 100), 2)
            : $netStored;
        ?>
        <div class="form-group" style="grid-column: span 2">
            <label>Taban Fiyat (₺) *</label>
            <div style="display:flex;gap:8px;align-items:stretch">
                <input type="number" step="0.01" name="base_price" id="prod_price"
                       value="<?= $displayPrice ?>" class="form-control"
                       style="flex:1;font-weight:700;font-size:15px" required
                       oninput="recalcPriceBreakdown()">
                <select name="price_mode" id="price_mode" class="form-control"
                        style="flex:0 0 auto;width:auto;min-width:140px;font-weight:700;<?= $editingMode==='gross' ? 'background:#dcfce7;color:#166534;border-color:#86efac' : '' ?>"
                        onchange="onPriceModeChange()">
                    <option value="gross" <?= $editingMode==='gross'?'selected':'' ?>>KDV Dahil</option>
                    <option value="net"   <?= $editingMode==='net'?'selected':'' ?>>KDV Hariç</option>
                </select>
            </div>
            <!-- Canlı önizleme — bayinin göreceği detay -->
            <div id="price_breakdown" style="margin-top:10px;padding:10px 12px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border:1px solid var(--border);border-radius:8px;font-size:12px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <span style="color:var(--text-2)">KDV Hariç (Net):</span>
                    <strong id="pb_net" style="color:var(--text);font-family:ui-monospace,monospace">—</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <span style="color:var(--text-2)">KDV (<span id="pb_vat_rate">20</span>%):</span>
                    <strong id="pb_vat" style="color:#d97706;font-family:ui-monospace,monospace">—</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding-top:6px;border-top:1px dashed var(--border)">
                    <span style="color:var(--text);font-weight:600">KDV Dahil (Brüt):</span>
                    <strong id="pb_gross" style="color:var(--success,#16a34a);font-family:ui-monospace,monospace;font-size:14px">—</strong>
                </div>
                <div style="margin-top:6px;font-size:10px;color:var(--text-muted);font-style:italic">
                    💡 Bayi tarafında "KDV dahil" fiyat görünür. DB'ye her zaman <strong>net</strong> kayıt edilir (Paraşüt uyumlu).
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>KDV Oranı (%)</label>
            <select name="vat_rate" id="prod_vat_rate" class="form-control" onchange="recalcPriceBreakdown()">
                <?php foreach ([0,1,8,10,18,20] as $t): ?>
                <option value="<?= $t ?>" <?= ((int)($product['vat_rate']??20))===$t?'selected':'' ?>>%<?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Mevcut Stok</label>
            <input type="number" name="stock" value="<?= $product['stock']??0 ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Kritik Stok Seviyesi</label>
            <input type="number" name="stock_critical" value="<?= $product['stock_critical']??5 ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Minimum Sipariş Miktarı</label>
            <input type="number" name="min_order_qty" value="<?= $product['min_order_qty']??1 ?>" class="form-control" min="1">
        </div>
        <div class="form-group">
            <label>Maksimum Sipariş Miktarı</label>
            <input type="number" name="max_order_qty" value="<?= $product['max_order_qty']??'' ?>" class="form-control" min="0" placeholder="Sınırsız için boş bırak">
        </div>
        <div class="form-group">
            <label>Paraşüt Ürün ID</label>
            <input type="text" name="parasut_product_id" value="<?= h($product['parasut_product_id']??'') ?>" class="form-control" placeholder="UUID">
        </div>
    </div>

    <div class="form-group">
        <label>Açıklama</label>
        <textarea name="description" class="form-control" rows="4"><?= h($product['description']??'') ?></textarea>
    </div>

    <div class="form-group">
        <label>Ürün Görseli</label>
        <?php if (!empty($product['image'])): ?>
        <div class="mb-2"><img src="/uploads/products/<?= h($product['image']) ?>" style="height:80px;border-radius:6px;border:1px solid var(--border)"></div>
        <?php endif; ?>
        <input type="file" name="image" class="form-control" accept="image/*">
    </div>

    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= ($product['is_active']??1)?'checked':'' ?>>
            Ürün aktif (bayilere görünür)
        </label>
    </div>

    <div class="form-group" style="background:linear-gradient(135deg,#fff5f5,#fef2f2);border:1px solid #fecaca;border-radius:8px;padding:10px 14px">
        <label class="checkbox-label" style="margin:0;display:flex;align-items:center;gap:10px;cursor:pointer">
            <input type="checkbox" name="is_featured" value="1" <?= !empty($product['is_featured'])?'checked':'' ?> style="width:18px;height:18px;cursor:pointer">
            <div>
                <div style="font-weight:700;color:#991b1b">🔥 Kampanyalı Ürün</div>
                <div style="font-size:11px;color:#7f1d1d;margin-top:2px">İşaretliyse bayi dashboard'undaki "Kampanyalı Ürünler" slider'ında gösterilir.</div>
            </div>
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 Kaydet</button>
        <a href="?page=products<?= $id?"&action=detail&id=$id":'' ?>" class="btn btn-ghost">İptal</a>
    </div>
</form>

<script>
// ── Fiyat KDV Hesaplama (Canlı Önizleme) ───────────────────────
function fmtTL(v) {
    return new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(v) + ' ₺';
}
function recalcPriceBreakdown() {
    const priceInp = document.getElementById('prod_price');
    const vatSel   = document.getElementById('prod_vat_rate');
    const modeSel  = document.getElementById('price_mode');
    if (!priceInp || !vatSel || !modeSel) return;
    const raw  = parseFloat(priceInp.value) || 0;
    const vat  = parseFloat(vatSel.value) || 0;
    const mode = modeSel.value;
    let net, vatAmt, gross;
    if (mode === 'gross') {
        // Form değeri KDV dahil (brüt) — netten ayır
        gross  = raw;
        net    = vat > 0 ? raw / (1 + vat/100) : raw;
        vatAmt = gross - net;
    } else {
        // Form değeri KDV hariç (net)
        net    = raw;
        vatAmt = net * (vat/100);
        gross  = net + vatAmt;
    }
    document.getElementById('pb_net').textContent      = fmtTL(net);
    document.getElementById('pb_vat').textContent      = fmtTL(vatAmt);
    document.getElementById('pb_gross').textContent    = fmtTL(gross);
    document.getElementById('pb_vat_rate').textContent = vat;
}
function onPriceModeChange() {
    // Mod değiştirildiğinde girilen değeri DOĞRU şekilde dönüştür
    // (kullanıcı zaten "100" yazmışsa, KDV hariç → KDV dahil geçince "120" olmalı)
    const priceInp = document.getElementById('prod_price');
    const vatSel   = document.getElementById('prod_vat_rate');
    const modeSel  = document.getElementById('price_mode');
    const cur      = parseFloat(priceInp.value) || 0;
    const vat      = parseFloat(vatSel.value) || 0;
    const newMode  = modeSel.value;
    // Önceki mod tersi olduğu için ona göre çevir
    if (newMode === 'gross' && cur > 0) {
        // Önceden net, şimdi gross
        priceInp.value = (cur * (1 + vat/100)).toFixed(2);
    } else if (newMode === 'net' && cur > 0) {
        // Önceden gross, şimdi net
        priceInp.value = vat > 0 ? (cur / (1 + vat/100)).toFixed(2) : cur.toFixed(2);
    }
    recalcPriceBreakdown();
}
// İlk yüklemede çalıştır
document.addEventListener('DOMContentLoaded', recalcPriceBreakdown);
recalcPriceBreakdown();
</script>
</div>
</div>
<?php endif; ?>
