<?php
requireDealer();
$dealerId  = $_SESSION['dealer_id'];
$dealer    = getCurrentDealer();
$productId = (int)($_GET['id'] ?? 0);

if (!$productId) { header('Location: ?page=products'); exit; }

$product = dbRow("SELECT p.*, c.name as category_name FROM b2b_products p LEFT JOIN b2b_categories c ON c.id = p.category_id WHERE p.id = ? AND p.is_active = 1", [$productId]);
if (!$product) { header('Location: ?page=products'); exit; }

$dp       = dealerPrice($productId, (int)($dealer['price_list_id'] ?? 0));
$price    = $dp['price'];
$discount = $dp['discount'];
$basePrice = $product['base_price'];
$discount = $basePrice > 0 ? round((1 - $price / $basePrice) * 100) : 0;

// Stok hareketi (son 10)
$logs = dbRows("SELECT sl.*, COALESCE(o.order_no, '-') as order_no FROM b2b_stock_log sl LEFT JOIN b2b_orders o ON o.id = sl.order_id WHERE sl.product_id = ? ORDER BY sl.created_at DESC LIMIT 10", [$productId]);

// Sepetteki miktar
$cartQty = dbExec("SELECT qty FROM b2b_cart WHERE dealer_id=? AND product_id=?", [$dealerId, $productId]);

// Benzer ürünler
$related = dbRows("SELECT p.*, (SELECT price FROM b2b_price_list_items WHERE product_id=p.id AND price_list_id=? LIMIT 1) as list_price FROM b2b_products p WHERE p.category_id = ? AND p.id != ? AND p.is_active=1 LIMIT 4");
?>
<div style="margin-bottom:1rem">
    <a href="?page=products" style="color:var(--text-muted);text-decoration:none;font-size:.875rem;display:inline-flex;align-items:center;gap:.4rem">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Ürünlere Dön
    </a>
</div>

<div class="product-detail-grid">
    <!-- Sol: Görsel -->
    <div class="card" style="padding:2rem;display:flex;align-items:center;justify-content:center;min-height:320px">
        <?php if ($product['image']): ?>
        <img src="/uploads/products/<?= htmlspecialchars($product['image']) ?>"
             alt="<?= htmlspecialchars($product['name']) ?>"
             style="max-width:100%;max-height:340px;object-fit:contain;border-radius:8px">
        <?php else: ?>
        <div style="width:180px;height:180px;background:var(--surface-2);border-radius:16px;display:flex;align-items:center;justify-content:center;color:var(--text-muted)">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sağ: Bilgiler -->
    <div style="display:flex;flex-direction:column;gap:1rem">
        <div class="card" style="padding:1.5rem">
            <?php if ($product['category_name']): ?>
            <div style="font-size:.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">
                <?= htmlspecialchars($product['category_name']) ?>
            </div>
            <?php endif; ?>

            <h1 style="font-size:1.5rem;font-weight:700;margin:0 0 .5rem">
                <?= htmlspecialchars($product['name']) ?>
            </h1>

            <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:1.25rem">
                SKU: <strong><?= htmlspecialchars($product['sku']) ?></strong>
            </div>

            <!-- Fiyat -->
            <div style="margin-bottom:1.5rem">
                <div style="font-size:2rem;font-weight:800;color:var(--primary)">
                    <?= fmtPrice($price) ?> ₺
                </div>
                <?php if ($discount > 0): ?>
                <div style="display:flex;align-items:center;gap:.5rem;margin-top:.25rem">
                    <span style="font-size:1rem;color:var(--text-muted);text-decoration:line-through"><?= fmtPrice($basePrice) ?> ₺</span>
                    <span class="badge badge-success">%<?= $discount ?> İndirim</span>
                </div>
                <?php endif; ?>
                <div style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem">KDV Hariç</div>
            </div>

            <!-- Stok -->
            <div style="margin-bottom:1.5rem">
                <?= stockBadge($product['stock']) ?>
                <span style="color:var(--text-muted);font-size:.875rem;margin-left:.5rem">
                    <?= $product['stock'] ?> adet stokta
                </span>
            </div>

            <!-- Min sipariş -->
            <?php if ($product['min_order_qty'] > 1): ?>
            <div style="background:var(--surface-2);border-radius:8px;padding:.75rem 1rem;font-size:.875rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:.5rem">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Minimum sipariş: <strong><?= $product['min_order_qty'] ?> adet</strong>
            </div>
            <?php endif; ?>

            <!-- Sepet -->
            <?php if ($product['stock'] > 0): ?>
            <div style="display:flex;gap:.75rem;align-items:center">
                <div class="qty-spinner">
                    <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
                    <input type="number" id="qtyInput" class="qty-input" value="<?= max($product['min_order_qty'], $cartQty ?: $product['min_order_qty']) ?>"
                           min="<?= $product['min_order_qty'] ?>" max="<?= $product['stock'] ?>">
                    <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
                </div>
                <button class="btn btn-primary" style="flex:1" onclick="addToCart(<?= $productId ?>)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 001.99 1.61h9.72a2 2 0 001.99-1.61L23 6H6"/></svg>
                    Sepete Ekle
                </button>
            </div>
            <?php else: ?>
            <div class="badge badge-danger" style="font-size:.9rem;padding:.6rem 1rem">Stok Tükendi</div>
            <?php endif; ?>
        </div>

        <!-- Açıklama -->
        <?php if ($product['description']): ?>
        <div class="card" style="padding:1.5rem">
            <h3 style="font-size:.9rem;font-weight:600;margin:0 0 .75rem">Ürün Açıklaması</h3>
            <div style="font-size:.875rem;color:var(--text-muted);line-height:1.7">
                <?= nl2br(htmlspecialchars($product['description'])) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Benzer Ürünler -->
<?php if (!empty($related)): ?>
<div style="margin-top:2rem">
    <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem">Benzer Ürünler</h2>
    <div class="product-grid">
        <?php foreach ($related as $r):
            $rdp = dealerPrice($r['id'], (int)($dealer['price_list_id'] ?? 0));
                $rPrice = $rdp['price'];
        ?>
        <a href="?page=product&id=<?= $r['id'] ?>" class="product-card" style="text-decoration:none;color:inherit">
            <div class="product-img">
                <?php if ($r['image']): ?>
                <img src="/uploads/products/<?= htmlspecialchars($r['image']) ?>" alt="">
                <?php else: ?>
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="opacity:.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <?php endif; ?>
            </div>
            <div class="product-info">
                <div class="product-name"><?= htmlspecialchars($r['name']) ?></div>
                <div class="product-sku"><?= htmlspecialchars($r['sku']) ?></div>
                <div class="product-price"><?= fmtPrice($rPrice) ?> ₺</div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<style>
.product-detail-grid {
    display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start
}
@media(max-width:768px) {
    .product-detail-grid { grid-template-columns:1fr }
}
.qty-spinner { display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden }
.qty-btn { background:var(--surface-2);border:none;width:36px;height:40px;cursor:pointer;font-size:1.1rem;color:var(--text);transition:background .15s }
.qty-btn:hover { background:var(--border) }
.qty-input { width:52px;height:40px;text-align:center;border:none;border-left:1px solid var(--border);border-right:1px solid var(--border);background:transparent;color:var(--text);font-size:.9rem;font-family:inherit }
.qty-input::-webkit-outer-spin-button,.qty-input::-webkit-inner-spin-button { -webkit-appearance:none }
</style>

<script>
function changeQty(delta) {
    const inp = document.getElementById('qtyInput');
    const min = parseInt(inp.min) || 1;
    const max = parseInt(inp.max) || 9999;
    inp.value = Math.min(max, Math.max(min, parseInt(inp.value || min) + delta));
}

function addToCart(productId) {
    const qty = parseInt(document.getElementById('qtyInput').value);
    fetch('/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'add', product_id: productId, qty})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.B2B?.toast(data.message || 'Sepete eklendi', 'success');
            window.B2B?.updateCartCount(data.cart_count);
        } else {
            window.B2B?.toast(data.message || 'Hata oluştu', 'error');
        }
    });
}
</script>