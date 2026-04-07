<?php
// pages/products.php — Ürün Kataloğu
requireDealer();
$dealer = currentDealer();

$search  = trim($_GET['q'] ?? '');
$catId   = intval($_GET['cat'] ?? 0);
$perPage = 50;
$curPage = max(1, intval($_GET['p'] ?? 1));
$offset  = ($curPage - 1) * $perPage;

$where  = ['p.is_active=1'];
$params = [];
if ($search) {
    $where[]  = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($catId) { $where[] = 'p.category_id=?'; $params[] = $catId; }

$w        = implode(' AND ', $where);
$total    = (int)dbVal("SELECT COUNT(*) FROM b2b_products p WHERE $w", $params);
$products = dbRows(
    "SELECT p.*, c.name AS cat_name
     FROM b2b_products p
     LEFT JOIN b2b_categories c ON c.id=p.category_id
     WHERE $w ORDER BY p.name LIMIT $perPage OFFSET $offset",
    $params
);

$cartItems = dbRows("SELECT product_id, qty FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);
$cartMap   = array_column($cartItems, 'qty', 'product_id');
$categories = dbRows("SELECT * FROM b2b_categories WHERE is_active=1 ORDER BY sort_order, name");
$pager = pagination($total, $perPage, $curPage,
         "?page=products&q=" . urlencode($search) . "&cat=$catId&p=");

$plId = (int)($dealer['price_list_id'] ?? 0);
?>
<div class="page-body">

<!-- Başlık + Sepet Özeti -->
<div class="page-header">
  <div>
    <h1 class="page-title">Ürün Kataloğu</h1>
    <p class="page-sub"><?= $total ?> ürün listeleniyor</p>
  </div>
  <a href="?page=cart" class="btn btn-primary" style="display:flex;align-items:center;gap:6px">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
    Sepete Git
    <?php $cc = array_sum($cartMap); if ($cc): ?>
    <span style="background:rgba(255,255,255,.25);border-radius:99px;padding:1px 7px;font-size:12px"><?= $cc ?></span>
    <?php endif; ?>
  </a>
</div>

<!-- Filtre -->
<div class="card" style="margin-bottom:16px;padding:12px 16px">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="page" value="products">
    <input type="text" name="q" value="<?= h($search) ?>" class="form-control"
           placeholder="Ürün adı, kodu veya barkod..." style="flex:1;min-width:200px;max-width:360px">
    <select name="cat" class="form-control" style="min-width:160px" onchange="this.form.submit()">
      <option value="">Tüm Kategoriler</option>
      <?php foreach ($categories as $cat): ?>
      <option value="<?= $cat['id'] ?>" <?= $catId==$cat['id']?'selected':'' ?>><?= h($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Ara</button>
    <?php if ($search || $catId): ?>
    <a href="?page=products" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($products)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:.4"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
    <p>Ürün bulunamadı.</p>
  </div>
</div>
<?php else: ?>

<!-- Ürün Tablosu -->
<div class="card" style="overflow:hidden">
<div class="table-wrap">
<table class="table" style="min-width:900px">
<thead>
<tr>
  <th style="width:56px">Resim</th>
  <th style="width:80px">Kod</th>
  <th>Ürün Tanımı</th>
  <th style="width:115px;text-align:right">Liste Fiyatı</th>
  <th style="width:115px;text-align:right">
    <span style="color:var(--success);font-weight:700">İndr. Fiyat</span>
  </th>
  <th style="width:60px;text-align:center">KDV</th>
  <th style="width:60px;text-align:center">Birim</th>
  <th style="width:44px;text-align:center">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
  </th>
  <th style="width:60px;text-align:center">Stok</th>
  <th style="width:110px;text-align:center">Adet</th>
  <th style="width:140px;text-align:center">İşlem</th>
</tr>
</thead>
<tbody>
<?php foreach ($products as $p):
    $dp       = dealerPrice($p['id'], $plId);
    $price    = $dp['price'];         // indirimli fiyat
    $base     = (float)$p['base_price']; // liste fiyatı
    $hasDisc  = $dp['discount'] > 0;
    $inCart   = $cartMap[$p['id']] ?? 0;
    $inStock  = $p['stock'] > 0;
    $lowStock = $inStock && $p['stock'] <= $p['stock_critical'];
?>
<tr id="pr-<?= $p['id'] ?>">

  <!-- Resim -->
  <td style="padding:8px 10px">
    <?php if ($p['image']): ?>
    <a href="?page=product&id=<?= $p['id'] ?>">
      <img src="/uploads/products/<?= h($p['image']) ?>"
           style="width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
    </a>
    <?php else: ?>
    <div style="width:42px;height:42px;border-radius:6px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:18px">📦</div>
    <?php endif; ?>
  </td>

  <!-- Kod -->
  <td>
    <code style="font-size:12px;color:var(--text-2)"><?= h($p['sku'] ?? '—') ?></code>
  </td>

  <!-- Ürün Adı -->
  <td>
    <a href="?page=product&id=<?= $p['id'] ?>" style="font-weight:600;color:var(--text);text-decoration:none;font-size:13px;line-height:1.4">
      <?= h($p['name']) ?>
    </a>
    <?php if ($p['cat_name']): ?>
    <div style="font-size:11px;color:var(--text-muted);margin-top:1px"><?= h($p['cat_name']) ?></div>
    <?php endif; ?>
  </td>

  <!-- Liste Fiyatı -->
  <td style="text-align:right;font-size:13px">
    <span style="<?= $hasDisc?'text-decoration:line-through;color:var(--text-muted)':'' ?>">
      <?= money($base) ?>
    </span>
  </td>

  <!-- İndirimli Fiyat -->
  <td style="text-align:right">
    <span style="font-weight:700;font-size:14px;color:<?= $hasDisc?'var(--success)':'var(--text)' ?>">
      <?= money($price) ?>
    </span>
    <?php if ($hasDisc): ?>
    <div style="font-size:10px;color:var(--success)">%<?= number_format($dp['discount'],0) ?> indirim</div>
    <?php endif; ?>
  </td>

  <!-- KDV -->
  <td style="text-align:center;font-size:12px;color:var(--text-2)">
    %<?= (int)$p['vat_rate'] ?>
  </td>

  <!-- Birim -->
  <td style="text-align:center;font-size:12px;color:var(--text-2)">
    <?= h($p['unit'] ?? 'Adet') ?>
  </td>

  <!-- Sepet ikonu (mini) -->
  <td style="text-align:center">
    <?php if ($inCart): ?>
    <span style="background:var(--red-light);color:var(--red);border-radius:99px;font-size:11px;font-weight:700;padding:2px 7px"><?= $inCart ?></span>
    <?php endif; ?>
  </td>

  <!-- Stok -->
  <td style="text-align:center">
    <?php if (!$inStock): ?>
    <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#ef4444" title="Stok yok"></span>
    <?php elseif ($lowStock): ?>
    <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#f59e0b" title="Kritik stok: <?= $p['stock'] ?>"></span>
    <?php else: ?>
    <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#22c55e" title="Stok: <?= $p['stock'] ?>"></span>
    <?php endif; ?>
  </td>

  <!-- Adet -->
  <td style="text-align:center;padding:8px 6px">
    <?php if ($inStock): ?>
    <div style="display:flex;align-items:center;gap:3px;justify-content:center">
      <button onclick="catalogChange(<?= $p['id'] ?>,-1,<?= $p['min_order_qty'] ?>)"
              style="width:26px;height:30px;border:1px solid var(--border-2);border-radius:5px;background:var(--bg);cursor:pointer;font-size:14px;color:var(--text-2)">−</button>
      <input type="number" id="cqty-<?= $p['id'] ?>"
             value="<?= $inCart ?: $p['min_order_qty'] ?>"
             min="<?= $p['min_order_qty'] ?>"
             <?= $p['max_order_qty'] ? 'max="'.$p['max_order_qty'].'"' : '' ?>
             style="width:48px;height:30px;text-align:center;border:1px solid var(--border-2);border-radius:5px;font-size:13px;font-family:inherit">
      <button onclick="catalogChange(<?= $p['id'] ?>,1,<?= $p['min_order_qty'] ?>)"
              style="width:26px;height:30px;border:1px solid var(--border-2);border-radius:5px;background:var(--bg);cursor:pointer;font-size:14px;color:var(--text-2)">+</button>
    </div>
    <?php else: ?>
    <span style="font-size:12px;color:var(--text-muted)">Stok yok</span>
    <?php endif; ?>
  </td>

  <!-- İşlem -->
  <td style="text-align:center;padding:8px 10px">
    <?php if ($inStock): ?>
    <button onclick="catalogAdd(<?= $p['id'] ?>)"
            id="addbtn-<?= $p['id'] ?>"
            style="display:flex;align-items:center;gap:5px;padding:6px 12px;background:var(--info-bg);border:1px solid var(--info-border);border-radius:6px;color:var(--info);font-weight:600;font-size:12px;cursor:pointer;white-space:nowrap;transition:all .15s;width:100%;justify-content:center">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
      Sepete Ekle
    </button>
    <?php else: ?>
    <span style="font-size:12px;color:var(--text-muted);font-style:italic">Stok Yok</span>
    <?php endif; ?>
  </td>

</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<?php if ($pager): ?><div style="margin-top:16px"><?= $pager ?></div><?php endif; ?>
<?php endif; ?>

</div>

<script>
const csrf = document.querySelector('meta[name=csrf]')?.content || '';

function catalogChange(pid, delta, minQty) {
    const inp = document.getElementById('cqty-' + pid);
    if (!inp) return;
    const cur = parseInt(inp.value) || minQty;
    inp.value = Math.max(minQty, cur + delta);
}

function catalogAdd(pid) {
    const inp  = document.getElementById('cqty-' + pid);
    const qty  = inp ? parseInt(inp.value) || 1 : 1;
    const btn  = document.getElementById('addbtn-' + pid);
    if (btn) { btn.disabled = true; btn.textContent = '...'; }

    fetch('/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=add&product_id=${pid}&qty=${qty}&csrf_token=${encodeURIComponent(csrf)}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            if (btn) {
                btn.style.background = 'var(--success-bg)';
                btn.style.borderColor = 'var(--success-border)';
                btn.style.color = 'var(--success)';
                btn.innerHTML = '✓ Eklendi';
                setTimeout(() => {
                    btn.style.background = '';
                    btn.style.borderColor = '';
                    btn.style.color = '';
                    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg> Sepete Ekle';
                    btn.disabled = false;
                }, 1800);
            }
            // Sepet count badge güncelle
            const badge = document.querySelector('.cart-badge');
            if (badge) badge.textContent = parseInt(badge.textContent||0) + qty;
        } else {
            if (btn) { btn.textContent = d.message || 'Hata'; btn.disabled = false; }
        }
    })
    .catch(() => { if (btn) { btn.textContent = 'Hata'; btn.disabled = false; } });
}
</script>
