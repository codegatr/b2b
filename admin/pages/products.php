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
        $data = [
            'category_id'       => intval($_POST['category_id']) ?: null,
            'name'              => trim($_POST['name']),
            'sku'               => trim($_POST['sku']),
            'description'       => trim($_POST['description']),
            'base_price'        => floatval($_POST['base_price']),
            'unit'              => trim($_POST['unit']) ?: 'adet',
            'min_order_qty'     => intval($_POST['min_order_qty']) ?: 1,
            'max_order_qty'     => intval($_POST['max_order_qty']) ?: null,
            'stock'             => intval($_POST['stock']),
            'stock_critical'    => intval($_POST['stock_critical']),
            'vat_rate'          => floatval($_POST['vat_rate'] ?? $_POST['tax_rate'] ?? 18),
            'parasut_product_id'=> trim($_POST['parasut_product_id']),
            'is_active'         => isset($_POST['is_active']) ? 1 : 0,
        ];

        if (empty($data['name'])) { $error = 'Ürün adı zorunludur.'; }
        else {
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
                dbUpdateRow('b2b_products', $data, 'id', $id);
                // Stok değişimi log
                $old = dbRow("SELECT stock FROM b2b_products WHERE id=?", [$id]);
                if ($old && $old['stock'] != $data['stock']) {
                    $diff = $data['stock'] - $old['stock'];
                    try { dbExec("INSERT INTO b2b_stock_log (product_id, change_type, quantity, note, created_by, created_at) VALUES (?,?,?,?,?,NOW())",
                        [$id, $diff>0?'giris':'cikis', abs($diff), 'Admin düzenlemesi', adminId()]); } catch (Exception $e) {}
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
        }
    }

    if ($act === 'stock_adjust') {
        $pid    = intval($_POST['product_id']);
        $type   = $_POST['change_type'];
        $qty    = abs(intval($_POST['quantity']));
        $note   = trim($_POST['note']);
        if ($qty > 0) {
            if ($type === 'giris') {
                dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?", [$qty, $pid]);
            } else {
                dbExec("UPDATE b2b_products SET stock=GREATEST(0,stock-?) WHERE id=?", [$qty, $pid]);
            }
            try { dbExec("INSERT INTO b2b_stock_log (product_id, change_type, quantity, note, created_by, created_at) VALUES (?,?,?,?,?,NOW())",
                [$pid, $type, $qty, $note, adminId()]); } catch (Exception $e) {}
            // Kritik stok kontrolü
            $p = dbRow("SELECT * FROM b2b_products WHERE id=?", [$pid]);
            if ($p && $p['stock'] <= $p['stock_critical']) {
                notifyAdmin('Kritik Stok', "'{$p['name']}' ürünü kritik stok seviyesine düştü: {$p['stock']} {$p['unit']}", 'stock', $pid);
            }
            $success = 'Stok güncellendi.';
        }
        $action = 'detail'; $id = $pid;
    }

    if ($act === 'toggle') {
        $pid = intval($_POST['product_id']);
        $cur = dbVal("SELECT is_active FROM b2b_products WHERE id=?", [$pid]);
        dbExec("UPDATE b2b_products SET is_active=? WHERE id=?", [$cur?0:1, $pid]);
        header('Location: ?page=products'); exit;
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
    $perPage = 25;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['1=1']; $params = [];
    if ($search) { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $s="%$search%"; $params[]=$s; $params[]=$s; }
    if ($catId)  { $where[] = 'p.category_id=?'; $params[] = $catId; }
    if ($stock === 'critical') { $where[] = 'p.stock <= p.stock_critical AND p.stock > 0'; }
    if ($stock === 'zero')     { $where[] = 'p.stock = 0'; }

    $w = implode(' AND ',$where);
    $total    = dbVal("SELECT COUNT(*) FROM b2b_products p WHERE $w", $params);
    $products = dbRows(
        "SELECT p.*, c.name AS cat_name FROM b2b_products p LEFT JOIN b2b_categories c ON c.id=p.category_id WHERE $w ORDER BY p.name LIMIT $perPage OFFSET $offset",
        $params
    );
    $pager = pagination($total, $perPage, $page, "?page=products&q=".urlencode($search)."&cat=$catId&stock=$stock&p=");
}
?>

<?php if ($action === 'list'): ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Ürünler</h1>
        <p class="page-sub">Toplam <?= $total ?> ürün</p>
    </div>
    <div class="btn-group">
        <a href="?page=categories" class="btn btn-ghost">Kategoriler</a>
        <a href="?page=products&action=add" class="btn btn-primary">＋ Yeni Ürün</a>
    </div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<div class="filter-bar card mb-4">
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="products">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Ürün adı veya SKU…" class="form-control" style="max-width:240px">
        <select name="cat" class="form-control" style="max-width:180px">
            <option value="">Tüm Kategoriler</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $catId==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="stock" class="form-control" style="max-width:160px">
            <option value="">Tüm Stok</option>
            <option value="critical" <?= $stock==='critical'?'selected':'' ?>>Kritik Stok</option>
            <option value="zero"     <?= $stock==='zero'?'selected':'' ?>>Stok Yok</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filtrele</button>
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
        </td>
        <td class="mono text-sm"><?= h($p['sku']) ?></td>
        <td><?= h($p['cat_name'] ?? '—') ?></td>
        <td><?= money($p['base_price']) ?></td>
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
    </div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="grid grid-cols-4 gap-4 mb-6">
    <div class="stat-card"><div class="stat-label">Taban Fiyat</div><div class="stat-value"><?= money($product['base_price']) ?></div></div>
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
                <input type="number" name="quantity" min="1" value="1" class="form-control" style="max-width:100px">
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
            <td><?= $sl['quantity'] ?> <?= h($product['unit']) ?></td>
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
        <div class="form-group">
            <label>Taban Fiyat (₺) *</label>
            <input type="number" step="0.01" name="base_price" value="<?= $product['base_price']??0 ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>KDV Oranı (%)</label>
            <select name="tax_rate" class="form-control">
                <?php foreach ([0,1,8,10,18,20] as $t): ?>
                <option value="<?= $t ?>" <?= ($product['vat_rate']??$product['tax_rate']??18)==$t?'selected':'' ?>>%<?= $t ?></option>
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
        <div class="mb-2"><img src="<?= h($product['image']) ?>" style="height:80px;border-radius:6px"></div>
        <?php endif; ?>
        <input type="file" name="image" class="form-control" accept="image/*">
    </div>

    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" name="is_active" value="1" <?= ($product['is_active']??1)?'checked':'' ?>>
            Ürün aktif (bayilere görünür)
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 Kaydet</button>
        <a href="?page=products<?= $id?"&action=detail&id=$id":'' ?>" class="btn btn-ghost">İptal</a>
    </div>
</form>
</div>
</div>
<?php endif; ?>
