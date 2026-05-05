<?php
// pages/products.php — Ürün Kataloğu
requireDealer();
$dealer = currentDealer();

$search    = trim($_GET['q'] ?? '');
$catId     = intval($_GET['cat'] ?? 0);
$stockFilt = $_GET['stock'] ?? 'all'; // all | instock | outstock
$perPage   = 50;
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
if ($stockFilt === 'instock')  { $where[] = 'p.stock > 0'; }
if ($stockFilt === 'outstock') { $where[] = 'p.stock <= 0'; }

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
         "?page=products&q=" . urlencode($search) . "&cat=$catId&stock=$stockFilt&p=");

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
<div class="card products-filter" style="margin-bottom:16px;padding:12px 16px">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="page" value="products">
    <input type="text" name="q" value="<?= h($search) ?>" class="form-control products-search"
           placeholder="🔍 Ürün adı, kodu veya barkod..." style="flex:1;min-width:200px">
    <select name="cat" class="form-control products-cat" style="min-width:160px" onchange="this.form.submit()">
      <option value="">Tüm Kategoriler</option>
      <?php foreach ($categories as $cat): ?>
      <option value="<?= $cat['id'] ?>" <?= $catId==$cat['id']?'selected':'' ?>><?= h($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Ara</button>
    <?php if ($search || $catId || $stockFilt !== 'all'): ?>
    <a href="?page=products" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
  </form>
  <style>
    @media (max-width: 768px) {
      .products-filter form { gap: 8px; }
      .products-search { flex: 1 1 100% !important; max-width: 100% !important; }
      .products-cat    { flex: 1 1 100% !important; }
      .products-filter .btn { flex: 1; }
    }
  </style>

  <!-- Stok Filtresi -->
  <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
    <?php
    $sfBase = '?page=products' . ($search?"&q=".urlencode($search):'') . ($catId?"&cat=$catId":'');
    $sfOpts = ['all'=>'Tümü','instock'=>'✓ Stokta Var','outstock'=>'✗ Stok Yok'];
    $sfColors = ['all'=>'','instock'=>'color:var(--success)','outstock'=>'color:var(--danger)'];
    foreach ($sfOpts as $k => $v):
        $active = $stockFilt === $k;
    ?>
    <a href="<?= $sfBase ?>&stock=<?= $k ?>"
       style="padding:5px 14px;border-radius:99px;font-size:12px;font-weight:600;text-decoration:none;border:1.5px solid;transition:all .15s;
              <?= $active
                ? ($k==='instock'?'background:var(--success);border-color:var(--success);color:#fff'
                  :($k==='outstock'?'background:var(--danger);border-color:var(--danger);color:#fff'
                  :'background:var(--text);border-color:var(--text);color:#fff'))
                : 'background:var(--surface);border-color:var(--border-2);'.($sfColors[$k]) ?>">
      <?= $v ?>
      <?php if ($k === 'instock'):  $n=(int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1 AND stock>0".($catId?" AND category_id=$catId":'').("")); echo "<span style='opacity:.7;margin-left:3px'>($n)</span>"; endif; ?>
      <?php if ($k === 'outstock'): $n=(int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE is_active=1 AND stock<=0".($catId?" AND category_id=$catId":'')); echo "<span style='opacity:.7;margin-left:3px'>($n)</span>"; endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
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
<!-- Desktop / Tablet — Tablo görünümü -->
<div class="card products-table-view" style="overflow:hidden">
<div class="table-wrap">
<table class="table" style="min-width:900px">
<thead>
<tr>
  <th style="width:56px">Resim</th>
  <th style="width:80px">Kod</th>
  <th>Ürün Tanımı</th>
  <th style="width:130px">Kısa Açıklama</th>
  <th style="width:115px;text-align:right">Liste Fiyatı</th>
  <th style="width:115px;text-align:right">
    <span style="color:var(--success);font-weight:700">İndr. Fiyat</span>
  </th>
  <th style="width:60px;text-align:center">KDV</th>
  <th style="width:60px;text-align:center">Birim</th>
  <th style="width:44px;text-align:center">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
  </th>
  <th style="width:70px;text-align:center">Stok</th>
  <th style="width:110px;text-align:center">Adet</th>
  <th style="width:140px;text-align:center">İşlem</th>
</tr>
</thead>
<tbody>
<?php foreach ($products as $p):
    $dp       = dealerPrice($p['id'], $plId);
    $price    = $dp['price'];         // indirimli net fiyat
    $base     = (float)$p['base_price']; // liste net fiyatı
    $vat      = (float)$p['vat_rate'];
    $vatMul   = 1 + ($vat / 100);
    // KDV her zaman dahil göster — sistem genel kuralı
    $basePrice  = $base * $vatMul;
    $finalPrice = $price * $vatMul;
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

  <!-- Kısa Açıklama -->
  <td style="font-size:12px;color:var(--text-2);max-width:160px">
    <?php
    $desc = $p['short_description'] ?? $p['description'] ?? '';
    // İlk satır veya max 80 karakter
    $firstLine = trim(explode("
", strip_tags($desc))[0]);
    echo h(mb_strlen($firstLine) > 80 ? mb_substr($firstLine, 0, 78).'…' : $firstLine);
    ?>
  </td>

  <!-- Liste Fiyatı -->
  <td style="text-align:right;font-size:13px">
    <span style="<?= $hasDisc?'text-decoration:line-through;color:var(--text-muted)':'' ?>">
      <?= money($basePrice) ?>
    </span>
  </td>

  <!-- İndirimli Fiyat -->
  <td style="text-align:right">
    <span style="font-weight:700;font-size:14px;color:<?= $hasDisc?'var(--success)':'var(--text)' ?>">
      <?= money($finalPrice) ?>
    </span>
    <div style="font-size:10px;color:var(--text-muted);margin-top:1px">KDV Dahil</div>
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
    <div style="display:flex;flex-direction:column;align-items:center;gap:2px">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444"></span>
      <span style="font-size:10px;color:#ef4444;font-weight:600">Tükendi</span>
    </div>
    <?php elseif ($lowStock): ?>
    <div style="display:flex;flex-direction:column;align-items:center;gap:2px">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f59e0b"></span>
      <span style="font-size:11px;font-weight:700;color:#d97706"><?= $p['stock'] ?></span>
      <span style="font-size:10px;color:#f59e0b">Son stok</span>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;align-items:center;gap:2px">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e"></span>
      <span style="font-size:11px;font-weight:600;color:var(--text-2)"><?= $p['stock'] ?></span>
    </div>
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
             max="<?= $p['max_order_qty'] ? min($p['max_order_qty'], $p['stock']) : $p['stock'] ?>"
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

<!-- MOBİL — Kart Görünümü (sadece ≤768px'de görünür) -->
<div class="products-card-view">
<?php foreach ($products as $p):
    $dp       = dealerPrice($p['id'], $plId);
    $price    = $dp['price'];
    $base     = (float)$p['base_price'];
    $vat      = (float)$p['vat_rate'];
    $vatMul   = 1 + ($vat / 100);
    $basePrice  = $base * $vatMul;
    $finalPrice = $price * $vatMul;
    $hasDisc  = $dp['discount'] > 0;
    $inCart   = $cartMap[$p['id']] ?? 0;
    $inStock  = $p['stock'] > 0;
    $lowStock = $inStock && $p['stock'] <= $p['stock_critical'];
?>
<div class="product-card-mobile" id="prm-<?= $p['id'] ?>" style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px">

  <!-- Üst kısım: resim + bilgi -->
  <div style="display:flex;gap:12px;margin-bottom:12px">
    <a href="?page=product&id=<?= $p['id'] ?>" style="flex:0 0 auto">
      <?php if ($p['image']): ?>
      <img src="/uploads/products/<?= h($p['image']) ?>" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
      <?php else: ?>
      <div style="width:72px;height:72px;border-radius:8px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:28px">📦</div>
      <?php endif; ?>
    </a>

    <div style="flex:1;min-width:0">
      <a href="?page=product&id=<?= $p['id'] ?>" style="font-weight:700;color:var(--text);text-decoration:none;font-size:14px;line-height:1.35;display:block">
        <?= h($p['name']) ?>
      </a>
      <?php if ($p['cat_name']): ?>
      <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= h($p['cat_name']) ?></div>
      <?php endif; ?>
      <div style="display:flex;align-items:center;gap:8px;margin-top:5px;flex-wrap:wrap">
        <code style="font-size:10px;color:var(--text-2);background:var(--bg);padding:2px 6px;border-radius:4px"><?= h($p['sku'] ?? '—') ?></code>
        <?php if (!$inStock): ?>
          <span style="background:#fee2e2;color:#dc2626;font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px">● Tükendi</span>
        <?php elseif ($lowStock): ?>
          <span style="background:#fef3c7;color:#d97706;font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px">● <?= $p['stock'] ?> adet kaldı</span>
        <?php else: ?>
          <span style="background:#dcfce7;color:#16a34a;font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px">● Stokta (<?= $p['stock'] ?>)</span>
        <?php endif; ?>
        <?php if ($inCart): ?>
          <span style="background:var(--red-light,#fef2f2);color:var(--red);font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px">🛒 <?= $inCart ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Fiyat satırı -->
  <div style="display:flex;align-items:flex-end;justify-content:space-between;padding:10px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:12px">
    <div>
      <?php if ($hasDisc): ?>
        <div style="font-size:11px;color:var(--text-muted);text-decoration:line-through"><?= money($basePrice) ?></div>
        <div style="font-size:18px;font-weight:800;color:var(--success);line-height:1">
          <?= money($finalPrice) ?>
          <span style="font-size:10px;background:var(--success);color:#fff;padding:1px 6px;border-radius:4px;font-weight:700;margin-left:4px;vertical-align:middle">%<?= number_format($dp['discount'],0) ?></span>
        </div>
      <?php else: ?>
        <div style="font-size:18px;font-weight:800;color:var(--text);line-height:1"><?= money($finalPrice) ?></div>
      <?php endif; ?>
      <div style="font-size:10px;color:var(--text-muted);margin-top:3px">
        KDV Dahil · <?= h($p['unit'] ?? 'Adet') ?>
      </div>
    </div>
  </div>

  <!-- Adet seçim + Sepete Ekle (TOUCH FRIENDLY) -->
  <?php if ($inStock): ?>
  <div style="display:flex;align-items:center;gap:10px">
    <!-- Adet kontrolü -->
    <div style="display:flex;align-items:center;gap:0;flex:0 0 auto;border:1px solid var(--border-2);border-radius:8px;overflow:hidden;background:#fff">
      <button onclick="catalogChange(<?= $p['id'] ?>,-1,<?= $p['min_order_qty'] ?>)"
              style="width:42px;height:44px;border:none;background:var(--bg);cursor:pointer;font-size:20px;color:var(--text-2);font-weight:600">−</button>
      <input type="number" id="cqty-m-<?= $p['id'] ?>"
             value="<?= $inCart ?: $p['min_order_qty'] ?>"
             min="<?= $p['min_order_qty'] ?>"
             max="<?= $p['max_order_qty'] ? min($p['max_order_qty'], $p['stock']) : $p['stock'] ?>"
             style="width:54px;height:44px;text-align:center;border:none;border-left:1px solid var(--border-2);border-right:1px solid var(--border-2);font-size:16px;font-family:inherit;font-weight:700;background:#fff;-moz-appearance:textfield"
             oninput="document.getElementById('cqty-'+<?= $p['id'] ?>) && (document.getElementById('cqty-'+<?= $p['id'] ?>).value = this.value)">
      <button onclick="catalogChange(<?= $p['id'] ?>,1,<?= $p['min_order_qty'] ?>)"
              style="width:42px;height:44px;border:none;background:var(--bg);cursor:pointer;font-size:20px;color:var(--text-2);font-weight:600">+</button>
    </div>

    <!-- Sepete Ekle -->
    <button onclick="catalogAdd(<?= $p['id'] ?>, true)"
            id="addbtn-m-<?= $p['id'] ?>"
            style="flex:1;display:flex;align-items:center;justify-content:center;gap:7px;height:44px;background:var(--red);border:none;border-radius:8px;color:#fff;font-weight:700;font-size:14px;cursor:pointer;transition:all .15s;box-shadow:0 1px 3px rgba(237,41,57,.25)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
      Sepete Ekle
    </button>
  </div>
  <?php else: ?>
  <button disabled style="width:100%;height:44px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text-muted);font-weight:600;font-size:13px;cursor:not-allowed">
    Stokta Yok
  </button>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<style>
  /* Bayağı önemli — desktop'ta kart görünümü gizle, mobilde tabloyu gizle */
  .products-card-view { display: none; }
  @media (max-width: 768px) {
    .products-table-view { display: none !important; }
    .products-card-view { display: block; }
  }
</style>

<?php if ($pager): ?><div style="margin-top:16px"><?= $pager ?></div><?php endif; ?>
<?php endif; ?>

</div>

<script>
const csrf = document.querySelector('meta[name=csrf]')?.content || '';

function catalogChange(pid, delta, minQty) {
    // Hem desktop hem mobile input'unu güncelle (görünür olan değişir, ikisi senkron kalır)
    [`cqty-${pid}`, `cqty-m-${pid}`].forEach(id => {
        const inp = document.getElementById(id);
        if (!inp) return;
        const cur = parseInt(inp.value) || minQty;
        const maxVal = parseInt(inp.max) || 9999;
        inp.value = Math.min(maxVal, Math.max(minQty, cur + delta));
    });
}

function catalogAdd(pid, isMobile = false) {
    // Görünür olan input'tan miktarı oku (mobile veya desktop)
    const inpDesktop = document.getElementById('cqty-' + pid);
    const inpMobile  = document.getElementById('cqty-m-' + pid);
    const inp = isMobile ? (inpMobile || inpDesktop) : (inpDesktop || inpMobile);
    const qty = inp ? (parseInt(inp.value) || 1) : 1;

    // İki butondan biri (görünür olan) loading state'e geçer
    const btnDesktop = document.getElementById('addbtn-' + pid);
    const btnMobile  = document.getElementById('addbtn-m-' + pid);
    const btn = isMobile ? btnMobile : btnDesktop;
    if (btn) { btn.disabled = true; const _orig = btn.innerHTML; btn.dataset.orig = _orig; btn.innerHTML = '...'; }

    fetch('/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=add&product_id=${pid}&qty=${qty}&csrf_token=${encodeURIComponent(csrf)}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            if (btn) {
                if (isMobile) {
                    btn.style.background = 'var(--success, #16a34a)';
                    btn.innerHTML = '✓ Eklendi';
                } else {
                    btn.style.background = 'var(--success-bg)';
                    btn.style.borderColor = 'var(--success-border)';
                    btn.style.color = 'var(--success)';
                    btn.innerHTML = '✓ Eklendi';
                }
                setTimeout(() => {
                    btn.style.background = '';
                    btn.style.borderColor = '';
                    btn.style.color = '';
                    btn.innerHTML = btn.dataset.orig || (isMobile
                        ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg> Sepete Ekle'
                        : '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg> Sepete Ekle');
                    btn.disabled = false;
                }, 1800);
            }
            const badge = document.querySelector('.cart-badge');
            if (badge) badge.textContent = parseInt(badge.textContent||0) + qty;
        } else {
            if (btn) { btn.textContent = d.message || 'Hata'; btn.disabled = false; }
        }
    })
    .catch(() => { if (btn) { btn.textContent = 'Hata'; btn.disabled = false; } });
}
</script>
