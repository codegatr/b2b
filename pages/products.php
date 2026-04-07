<?php
// pages/products.php — Ürün Kataloğu (Bayi)
requireDealer();
$dealer = currentDealer();

$search  = trim($_GET['q'] ?? '');
$catId   = intval($_GET['cat'] ?? 0);
$perPage = 24;
$page    = max(1, intval($_GET['p'] ?? 1));
$offset  = ($page-1)*$perPage;

$where = ['p.is_active=1']; $params = [];
if ($search) { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $s="%$search%"; $params[]=$s; $params[]=$s; }
if ($catId)  { $where[] = 'p.category_id=?'; $params[] = $catId; }

$w     = implode(' AND ', $where);
$total = dbVal("SELECT COUNT(*) FROM b2b_products p WHERE $w", $params);
$products = dbRows(
    "SELECT p.*, c.name AS cat_name FROM b2b_products p LEFT JOIN b2b_categories c ON c.id=p.category_id WHERE $w ORDER BY p.name LIMIT $perPage OFFSET $offset",
    $params
);

// Sepet adeti (hızlı kontrol için)
$cartItems = dbRows("SELECT product_id, qty FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);
$cartMap   = array_column($cartItems, 'qty', 'product_id');

$categories = dbRows("SELECT * FROM b2b_categories WHERE is_active=1 ORDER BY sort_order, name");
$pager = pagination($total, $perPage, $page, "?page=products&q=".urlencode($search)."&cat=$catId&p=");
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Ürün Kataloğu</h1>
        <p class="page-sub"><?= $total ?> ürün</p>
    </div>
    <a href="?page=cart" class="btn btn-primary">
        🛒 Sepet
        <?php $cc = array_sum($cartMap); if ($cc): ?><span class="badge badge-white ml-1"><?= $cc ?></span><?php endif; ?>
    </a>
</div>

<!-- Filtreler -->
<div class="filter-bar card mb-4">
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="products">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Ürün adı veya kod…" class="form-control" style="max-width:260px">
        <select name="cat" class="form-control" style="max-width:200px" onchange="this.form.submit()">
            <option value="">Tüm Kategoriler</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $catId==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Ara</button>
        <?php if ($search || $catId): ?><a href="?page=products" class="btn btn-ghost">Temizle</a><?php endif; ?>
    </form>
</div>

<!-- Ürün Grid -->
<div class="product-grid">
<?php foreach ($products as $p):
    $dp        = dealerPrice($p['id'], (int)($dealer['price_list_id'] ?? 0));
    $price     = $dp['price'];
    $discount  = $dp['discount'];
    $inCart    = $cartMap[$p['id']] ?? 0;
    $inStock   = $p['stock'] > 0;
    $lowStock  = $p['stock'] > 0 && $p['stock'] <= $p['stock_critical'];
?>
<div class="product-card" data-product-id="<?= $p['id'] ?>">
    <?php if ($p['image']): ?>
    <div class="product-img">
        <img src="/uploads/products/<?= h($p['image']) ?>" alt="<?= h($p['name']) ?>" loading="lazy">
    </div>
    <?php else: ?>
    <div class="product-img product-img--empty">📦</div>
    <?php endif; ?>

    <div class="product-body">
        <div class="product-cat text-muted text-xs"><?= h($p['cat_name'] ?? '') ?></div>
        <div class="product-name font-medium mt-1">
            <a href="?page=product&id=<?= $p['id'] ?>"><?= h($p['name']) ?></a>
        </div>
        <?php if ($p['sku']): ?>
        <div class="product-sku text-muted text-xs mono"><?= h($p['sku']) ?></div>
        <?php endif; ?>

        <div class="product-price mt-2"><?= money($price) ?> <span class="text-muted text-xs">/ <?= h($p['unit']) ?></span></div>

        <div class="product-stock mt-1">
            <?= stockBadge($p['stock'], $p['stock_critical']) ?>
            <?php if ($lowStock): ?><span class="text-warning text-xs"> Son <?= $p['stock'] ?> <?= h($p['unit']) ?></span><?php endif; ?>
            <?php if ($p['min_order_qty'] > 1): ?><span class="text-muted text-xs"> · Min: <?= $p['min_order_qty'] ?></span><?php endif; ?>
        </div>
    </div>

    <div class="product-footer">
        <?php if ($inStock): ?>
        <div class="qty-control" id="qty-ctrl-<?= $p['id'] ?>" style="<?= $inCart?'':'display:none' ?>">
            <button class="qty-btn" onclick="Cart.change(<?= $p['id'] ?>, -1)">−</button>
            <input type="number" class="qty-input" id="qty-<?= $p['id'] ?>" value="<?= $inCart ?>" min="<?= $p['min_order_qty'] ?>" step="1"
                   onchange="Cart.setQty(<?= $p['id'] ?>, this.value)">
            <button class="qty-btn" onclick="Cart.change(<?= $p['id'] ?>, 1)">+</button>
        </div>
        <button class="btn btn-primary btn-sm w-full" id="add-btn-<?= $p['id'] ?>"
                style="<?= $inCart?'display:none':'' ?>"
                onclick="Cart.add(<?= $p['id'] ?>, <?= $p['min_order_qty'] ?>)">
            Sepete Ekle
        </button>
        <?php else: ?>
        <button class="btn btn-ghost btn-sm w-full" disabled>Stok Yok</button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($products)): ?>
<div style="grid-column:1/-1;text-align:center;padding:48px 0;color:var(--text-muted)">
    <?= $search ? "\"".h($search)."\" için ürün bulunamadı." : 'Ürün bulunmuyor.' ?>
</div>
<?php endif; ?>
</div>

<?= $pager ?>
