<?php
/**
 * Admin — Fiyat Listeleri Yönetimi
 * En kritik modül: bayi bazlı fiyatlandırma
 */
$action = $_GET['action'] ?? 'list';
$listId = (int)($_GET['id'] ?? 0);

// ═══════════════════════════════════════════════════════════════════
// EARLY AJAX INTERCEPT — header conflict önleme
// Bu blok, admin/index.php intercept ETMESE BİLE (eski versiyon, vs.)
// save-item ve delete-item action'larında HİÇBİR HTML çıktısı yok.
// jsonResponse() ob_end_clean ile zaten korunuyor, bu ek bir güvence.
// ═══════════════════════════════════════════════════════════════════
if (isPost() && in_array($action, ['save-item', 'delete-item'], true)) {
    // Buffer'ları temizle (admin layout HTML basıldıysa da)
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ob_start();  // yeni temiz buffer
}

// ── Kaydet ───────────────────────────────────────────────────
if (isPost() && $action === 'save-list') {
    csrfCheck();
    $name    = trim($_POST['name'] ?? '');
    $desc    = trim($_POST['description'] ?? '');
    $disc    = (float)str_replace(',','.',($_POST['discount_percent']??'0'));
    // Yeni: price_adjust (tutar bazlı ek/eksilt — boş bırakılırsa NULL)
    $adjustRaw = isset($_POST['price_adjust']) ? trim($_POST['price_adjust']) : '';
    $adjust    = $adjustRaw === '' ? null : (float)str_replace(',','.',$adjustRaw);
    $cur     = $_POST['currency'] ?? 'TRY';
    $isDef   = isset($_POST['is_default']) ? 1 : 0;
    $isAct   = isset($_POST['is_active']) ? 1 : 0;

    if ($isDef) dbExec("UPDATE b2b_price_lists SET is_default=0");

    if ($listId) {
        try {
            dbExec("UPDATE b2b_price_lists SET name=?,description=?,discount_percent=?,price_adjust=?,currency=?,is_default=?,is_active=? WHERE id=?",
                [$name,$desc,$disc,$adjust,$cur,$isDef,$isAct,$listId]);
        } catch (\Throwable $e) {
            // Eski şema (migration_018 koşmamış)
            dbExec("UPDATE b2b_price_lists SET name=?,description=?,discount_percent=?,currency=?,is_default=?,is_active=? WHERE id=?",
                [$name,$desc,$disc,$cur,$isDef,$isAct,$listId]);
        }
        $_SESSION['flash_admin'] = ['type'=>'success','msg'=>'Fiyat listesi güncellendi.'];
    } else {
        try {
            $listId = dbInsert("INSERT INTO b2b_price_lists (name,description,discount_percent,price_adjust,currency,is_default,is_active) VALUES (?,?,?,?,?,?,?)",
                [$name,$desc,$disc,$adjust,$cur,$isDef,$isAct]);
        } catch (\Throwable $e) {
            $listId = dbInsert("INSERT INTO b2b_price_lists (name,description,discount_percent,currency,is_default,is_active) VALUES (?,?,?,?,?,?)",
                [$name,$desc,$disc,$cur,$isDef,$isAct]);
        }
        $_SESSION['flash_admin'] = ['type'=>'success','msg'=>'Fiyat listesi oluşturuldu.'];
    }
    auditLog('price_list_save','b2b_price_lists',$listId);
    header("Location: ?page=price-lists&id=$listId&action=items");
    exit;
}

// ── Ürün fiyatı kaydet ────────────────────────────────────────
if (isPost() && $action === 'save-item') {
    csrfCheck();
    $productId = (int)($_POST['product_id']??0);

    // Helper: boş string veya tanımsızsa null, yoksa float/int
    $parseFloat = function($v) {
        if (!isset($v) || trim($v) === '') return null;
        return (float)str_replace(',','.', trim($v));
    };
    $parseInt = function($v) {
        if (!isset($v) || trim($v) === '') return null;
        return (int)trim($v);
    };

    // Input'lar KDV DAHİL olarak gelir, DB'ye NET yazılır
    $priceInc  = $parseFloat($_POST['price'] ?? '');
    $disc      = $parseFloat($_POST['discount_percent'] ?? '');
    $adjustInc = $parseFloat($_POST['price_adjust'] ?? '');
    $minQty    = $parseInt($_POST['min_order_qty'] ?? '');

    if (!$listId || !$productId) {
        jsonResponse(['ok'=>false,'msg'=>'Eksik parametre.']);
    }

    // Ürünün KDV oranını oku ve KDV Dahil → NET dönüşümü yap
    $productInfo = dbRow("SELECT vat_rate FROM b2b_products WHERE id=?", [$productId]);
    $vatRate = (float)($productInfo['vat_rate'] ?? 20);
    $vatM    = 1 + $vatRate / 100;

    $price  = $priceInc !== null  ? round($priceInc / $vatM, 4)  : null;
    $adjust = $adjustInc !== null ? round($adjustInc / $vatM, 4) : null;

    // Tüm alanlar boş ise mevcut override kaydını sil (liste kuralına döner)
    $allEmpty = ($price === null) && ($disc === null) && ($adjust === null) && ($minQty === null);

    if ($allEmpty) {
        dbExec("DELETE FROM b2b_price_list_items WHERE price_list_id=? AND product_id=?", [$listId,$productId]);
        jsonResponse(['ok'=>true,'msg'=>'Özel fiyat silindi (liste kuralına döndü).']);
    }

    // Sabit fiyat alanı boşsa 0 olarak gönder (DB DECIMAL NOT NULL olabilir)
    $priceVal = $price ?? 0;

    try {
        dbExec("INSERT INTO b2b_price_list_items (price_list_id,product_id,price,discount_percent,price_adjust,min_order_qty)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE price=VALUES(price),discount_percent=VALUES(discount_percent),price_adjust=VALUES(price_adjust),min_order_qty=VALUES(min_order_qty)",
            [$listId,$productId,$priceVal,$disc,$adjust,$minQty]);
    } catch (\Throwable $e) {
        // Eski şema (migration_020 koşmamış) — price_adjust olmadan
        dbExec("INSERT INTO b2b_price_list_items (price_list_id,product_id,price,discount_percent,min_order_qty)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE price=VALUES(price),discount_percent=VALUES(discount_percent),min_order_qty=VALUES(min_order_qty)",
            [$listId,$productId,$priceVal,$disc,$minQty]);
    }
    jsonResponse(['ok'=>true,'msg'=>'Kaydedildi.']);
}

// ── Ürün fiyatını sil ─────────────────────────────────────────
if (isPost() && $action === 'delete-item') {
    csrfCheck();
    $itemId = (int)($_POST['item_id']??0);
    dbExec("DELETE FROM b2b_price_list_items WHERE id=? AND price_list_id=?", [$itemId,$listId]);
    jsonResponse(['ok'=>true]);
}

// ── Listeyi sil ───────────────────────────────────────────────
if (isPost() && $action === 'delete-list') {
    csrfCheck();
    dbExec("DELETE FROM b2b_price_list_items WHERE price_list_id=?", [$listId]);
    dbExec("DELETE FROM b2b_price_lists WHERE id=?", [$listId]);
    $_SESSION['flash_admin'] = ['type'=>'success','msg'=>'Fiyat listesi silindi.'];
    redirect('?page=price-lists');
}

// ── Bayiye toplu atama ────────────────────────────────────────
if (isPost() && $action === 'assign-dealers') {
    csrfCheck();
    $dealerIds = $_POST['dealer_ids'] ?? [];
    // Bu listeyi seçili bayilere ata
    if ($dealerIds) {
        $in = implode(',', array_map('intval', $dealerIds));
        dbExec("UPDATE b2b_dealers SET price_list_id=$listId WHERE id IN($in)");
        $_SESSION['flash_admin'] = ['type'=>'success','msg'=>count($dealerIds).' bayiye atandı.'];
    }
    header("Location: ?page=price-lists&id=$listId&action=dealers");
    exit;
}

// ── Toplu fiyat yükleme (CSV) ─────────────────────────────────
if (isPost() && $action === 'import-csv' && isset($_FILES['csv'])) {
    csrfCheck();
    $file = $_FILES['csv'];
    if ($file['type'] === 'text/csv' || str_ends_with($file['name'],'.csv')) {
        $handle = fopen($file['tmp_name'],'r');
        $imported = 0;
        $errors = [];
        $row = 0;
        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            $row++;
            if ($row === 1) continue; // Header
            [$sku, $price, $discount] = array_pad($line, 3, '');
            $sku   = trim($sku);
            $price = (float)str_replace(',','.',$price);
            $disc  = $discount !== '' ? (float)str_replace(',','.',$discount) : null;
            $product = dbRow("SELECT id FROM b2b_products WHERE sku=?", [$sku]);
            if (!$product) { $errors[] = "Satır $row: SKU '$sku' bulunamadı"; continue; }
            dbExec("INSERT INTO b2b_price_list_items (price_list_id,product_id,price,discount_percent)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE price=VALUES(price),discount_percent=VALUES(discount_percent)",
                [$listId, $product['id'], $price, $disc]);
            $imported++;
        }
        fclose($handle);
        $_SESSION['flash_admin'] = ['type'=>'success','msg'=>"$imported ürün fiyatı içe aktarıldı.".($errors?" ".count($errors)." hata.":'')];
    }
    header("Location: ?page=price-lists&id=$listId&action=items");
    exit;
}

// ── Görünüm ───────────────────────────────────────────────────
if ($action === 'list'): ?>
<div class="page-body">
<div class="card-header" style="margin-bottom:16px;padding:0">
  <h2 style="font-size:16px;flex:1">Fiyat Listeleri</h2>
  <a href="?page=price-lists&action=edit" class="btn btn-primary">+ Yeni Liste</a>
</div>

<?php
$lists = dbRows("SELECT pl.*, COUNT(pli.id) as item_count,
                 (SELECT COUNT(*) FROM b2b_dealers d WHERE d.price_list_id=pl.id) as dealer_count
                 FROM b2b_price_lists pl
                 LEFT JOIN b2b_price_list_items pli ON pli.price_list_id=pl.id
                 GROUP BY pl.id ORDER BY pl.is_default DESC, pl.name");
?>
<div class="card">
<div class="table-wrap">
<table>
<thead><tr>
  <th>Liste Adı</th><th>Para Birimi</th><th>Fiyat Kuralı</th><th>Ürün Sayısı</th><th>Bayi Sayısı</th><th>Durum</th><th></th>
</tr></thead>
<tbody>
<?php foreach ($lists as $l): ?>
<tr>
  <td>
    <div class="d-flex align-center gap-8">
      <strong><?= h($l['name']) ?></strong>
      <?php if ($l['is_default']): ?><span class="badge badge-primary">Varsayılan</span><?php endif; ?>
    </div>
    <?php if ($l['description']): ?><div class="text-muted fs-12"><?= h($l['description']) ?></div><?php endif; ?>
  </td>
  <td><?= h($l['currency']) ?></td>
  <td>
    <?php
    $disc   = (float)$l['discount_percent'];
    $adj    = $l['price_adjust'] ?? null;
    if ($disc > 0) {
        echo '<span class="badge" style="background:#fef2f2;color:#b91c1c">−%' . number_format($disc, 2, ',', '.') . '</span>';
    } elseif ($adj !== null && (float)$adj != 0) {
        $sign  = (float)$adj >= 0 ? '+' : '';
        $color = (float)$adj >= 0 ? '#0369a1' : '#15803d';
        $bg    = (float)$adj >= 0 ? '#eff6ff' : '#f0fdf4';
        echo '<span class="badge" style="background:' . $bg . ';color:' . $color . '">Baz ' . $sign . number_format((float)$adj, 2, ',', '.') . ' ₺</span>';
    } else {
        echo '<span class="text-muted">Standart</span>';
    }
    ?>
  </td>
  <td><?= $l['item_count'] ?> ürün</td>
  <td><?= $l['dealer_count'] ?> bayi</td>
  <td><?= $l['is_active'] ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Pasif</span>' ?></td>
  <td>
    <div class="btn-group">
      <a href="?page=price-lists&id=<?= $l['id'] ?>&action=items" class="btn btn-secondary btn-sm">Fiyatlar</a>
      <a href="?page=price-lists&id=<?= $l['id'] ?>&action=dealers" class="btn btn-secondary btn-sm">Bayiler</a>
      <a href="?page=price-lists&id=<?= $l['id'] ?>&action=edit" class="btn btn-secondary btn-sm">Düzenle</a>
    </div>
  </td>
</tr>
<?php endforeach; ?>
<?php if (empty($lists)): ?><tr><td colspan="7" class="text-center text-muted" style="padding:32px">Henüz fiyat listesi yok</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php elseif ($action === 'edit'): ?>
<?php $list = $listId ? dbRow("SELECT * FROM b2b_price_lists WHERE id=?", [$listId]) : null; ?>
<div class="page-body">
<div class="card" style="max-width:560px">
  <div class="card-header">
    <h2><?= $list ? 'Liste Düzenle' : 'Yeni Fiyat Listesi' ?></h2>
  </div>
  <div class="card-body">
    <form method="post" action="?page=price-lists&id=<?= $listId ?>&action=save-list">
      <?= csrfField() ?>
      <div class="form-group">
        <label class="form-label">Liste Adı *</label>
        <input name="name" class="form-control" value="<?= h($list['name']??'') ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <input name="description" class="form-control" value="<?= h($list['description']??'') ?>">
      </div>
      <div class="form-row col-2">
        <div class="form-group">
          <label class="form-label">Global İskonto (%)</label>
          <input name="discount_percent" type="number" step="0.01" min="0" max="100" class="form-control" value="<?= $list['discount_percent']??'0' ?>">
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Standart fiyata YÜZDE indirim (örn: %10)</div>
        </div>
        <div class="form-group">
          <label class="form-label">Tutar Ek/İndirim (₺)</label>
          <input name="price_adjust" type="number" step="0.01" class="form-control" value="<?= isset($list['price_adjust']) && $list['price_adjust']!==null ? h($list['price_adjust']) : '' ?>" placeholder="örn: 5 veya -2.50">
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
            Standart fiyat <strong>+ bu tutar</strong> uygulanır. <strong>Boş = uygulanmaz.</strong><br>
            Pozitif = zam (+5 ₺), negatif = indirim (-2.50 ₺).
          </div>
        </div>
      </div>
      <div class="form-row col-2">
        <div class="form-group">
          <label class="form-label">Para Birimi</label>
          <select name="currency" class="form-control">
            <?php foreach (['TRY','USD','EUR'] as $c): ?>
            <option value="<?= $c ?>" <?= ($list['currency']??'TRY')===$c?'selected':'' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px">
          <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:4px">💡 ÖNCELİK</div>
          <div style="font-size:11px;color:#78350f;line-height:1.5">
            Yüzde ve Tutar <strong>ikisi de</strong> dolu ise <strong>Yüzde</strong> uygulanır.
            Ürün-bazlı override (varsa) liste genelini geçersiz kılar.
          </div>
        </div>
      </div>
      <div class="form-row col-2">
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
            <label class="toggle"><input type="checkbox" name="is_default" <?= ($list['is_default']??0)?'checked':'' ?>><span class="toggle-track"></span></label>
            Varsayılan Liste
          </label>
        </div>
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
            <label class="toggle"><input type="checkbox" name="is_active" <?= ($list['is_active']??1)?'checked':'' ?>><span class="toggle-track"></span></label>
            Aktif
          </label>
        </div>
      </div>
      <div class="btn-group">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <a href="?page=price-lists" class="btn btn-secondary">İptal</a>
      </div>
    </form>
  </div>
</div>
</div>

<?php elseif ($action === 'items'): ?>
<?php
$list = dbRow("SELECT * FROM b2b_price_lists WHERE id=?", [$listId]);
if (!$list) { redirect('?page=price-lists'); }

$search = trim($_GET['q']??'');

// TÜM aktif ürünleri çek; bu listede override edilmişse pli.* gelir, yoksa NULL'lar gelir.
// Defansif: price_adjust kolonu yoksa try-catch ile eski şemaya düşer.
try {
    $allRows = dbRows(
        "SELECT p.id AS product_id, p.name, p.sku, p.base_price, p.stock, p.unit, p.vat_rate,
                pli.id AS item_id, pli.price, pli.discount_percent, pli.price_adjust, pli.min_order_qty
         FROM b2b_products p
         LEFT JOIN b2b_price_list_items pli ON pli.product_id=p.id AND pli.price_list_id=?
         WHERE p.is_active=1
         " . ($search ? " AND (p.name LIKE ? OR p.sku LIKE ?)" : "") . "
         ORDER BY p.name",
        $search ? [$listId, "%$search%", "%$search%"] : [$listId]
    );
} catch (\Throwable $e) {
    // Eski şema (price_adjust kolonu yok)
    $allRows = dbRows(
        "SELECT p.id AS product_id, p.name, p.sku, p.base_price, p.stock, p.unit, p.vat_rate,
                pli.id AS item_id, pli.price, pli.discount_percent, pli.min_order_qty
         FROM b2b_products p
         LEFT JOIN b2b_price_list_items pli ON pli.product_id=p.id AND pli.price_list_id=?
         WHERE p.is_active=1
         " . ($search ? " AND (p.name LIKE ? OR p.sku LIKE ?)" : "") . "
         ORDER BY p.name",
        $search ? [$listId, "%$search%", "%$search%"] : [$listId]
    );
    foreach ($allRows as &$row) $row['price_adjust'] = null;
    unset($row);
}

// Listede override edilmiş ürün sayısı (yeşil özet kutusu için)
$overrideCount = 0;
foreach ($allRows as $r) {
    if ($r['item_id']) $overrideCount++;
}

// Liste seviyesi kuralları (header + tablo render için gerekli)
$globalDisc   = (float)($list['discount_percent'] ?? 0);
$globalAdjust = $list['price_adjust'] ?? null;
$adjustValue  = $globalAdjust;  // eski isim — daha aşağıda kullanılıyor
$hasAnyRule   = $globalDisc > 0 || ($globalAdjust !== null && (float)$globalAdjust != 0);

// Eski $items uyumluluğu (CSV export gibi yerler kullanıyor olabilir)
$items = array_filter($allRows, fn($r) => !empty($r['item_id']));

$products = dbRows(
    "SELECT id, name, sku, base_price, unit FROM b2b_products WHERE is_active=1 ORDER BY name"
);
?>
<div class="page-body">
<div class="card-header" style="padding:0;margin-bottom:16px">
  <div>
    <h2 style="font-size:16px"><?= h($list['name']) ?> — Ürün Fiyatları</h2>
    <div class="text-muted fs-12">
      <?php if ($globalDisc > 0): ?>Liste kuralı: <strong style="color:#b91c1c">−%<?= number_format($globalDisc, 2, ',', '.') ?></strong>
      <?php elseif ($globalAdjust !== null && (float)$globalAdjust != 0): ?>Liste kuralı: <strong style="color:#0369a1">Baz <?= (float)$globalAdjust >= 0 ? '+' : '' ?><?= number_format((float)$globalAdjust, 2, ',', '.') ?> ₺</strong>
      <?php else: ?>Liste kuralı: <span style="color:var(--text-muted)">Standart fiyat</span><?php endif; ?>
    </div>
  </div>
  <div class="btn-group">
    <a href="?page=price-lists&id=<?= $listId ?>&action=edit" class="btn btn-secondary btn-sm">⚙ Liste Kuralı Düzenle</a>
  </div>
</div>

<!-- Aktif Fiyatlandırma Kuralı Özeti — kullanıcı liste ayarlarının ne yaptığını anlasın -->
<?php if ($hasAnyRule): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:flex-start">
  <span style="font-size:22px;line-height:1">✓</span>
  <div style="flex:1">
    <div style="font-weight:700;color:#15803d;font-size:13px;margin-bottom:4px">Aktif Liste Kuralı</div>
    <div style="font-size:12.5px;color:#166534;line-height:1.6">
      Bu listeye atanan bayilere
      <?php if ($globalDisc > 0): ?>
        <strong>standart fiyat üzerinden %<?= number_format($globalDisc, 2, ',', '.') ?> indirim</strong> uygulanır.
      <?php elseif ($adjustValue !== null && (float)$adjustValue != 0): ?>
        <strong>standart fiyat <?= (float)$adjustValue >= 0 ? '+' : '' ?><?= number_format((float)$adjustValue, 2, ',', '.') ?> ₺</strong>
        <?= (float)$adjustValue >= 0 ? 'ek olarak' : 'indirim olarak' ?> uygulanır.
      <?php endif; ?>
      <br>
      <span style="font-size:11.5px;color:#15803d">Tabloda <strong>ürün-bazlı</strong> değer girersen, o ürün için bu liste kuralı geçersiz olur (önceliği: Sabit > İskonto% > Tutar± > Liste kuralı).</span>
    </div>
  </div>
</div>
<?php else: ?>
<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:flex-start">
  <span style="font-size:22px;line-height:1">ℹ️</span>
  <div style="flex:1">
    <div style="font-weight:700;color:#1e40af;font-size:13px;margin-bottom:4px">Listede genel kural yok</div>
    <div style="font-size:12.5px;color:#1e3a8a;line-height:1.6">
      Bayiler standart fiyat görür — ürüne özel fiyat girmediğin sürece.
      Toplu indirim/zam için <a href="?page=price-lists&id=<?= $listId ?>&action=edit" style="color:#1d4ed8;font-weight:700">⚙ Liste Kuralı Düzenle</a> butonundan
      <strong>Global İskonto (%)</strong> veya <strong>Tutar Ek/İndirim (₺)</strong> ayarlayabilirsin.
    </div>
  </div>
</div>
<?php endif; ?>

<div class="search-wrap" style="margin-bottom:12px">
  <input type="text" id="price-search" class="form-control" placeholder="🔍 Ürün adı veya SKU ile ara…" value="<?= h($search) ?>"
         style="font-size:14px;padding:10px 14px">
</div>

<div class="card">
<div class="table-wrap">
<table>
<thead><tr>
  <th>Ürün</th>
  <th>SKU</th>
  <th>Baz Fiyat<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">KDV Dahil</span></th>
  <th style="width:130px">Sabit Fiyat (₺)<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">KDV Dahil · Override</span></th>
  <th style="width:90px">İskonto (%)</th>
  <th style="width:120px">Tutar ± (₺)<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">KDV Dahil · +5 / -2.50</span></th>
  <th style="width:80px">Min. Adet</th>
  <th style="width:140px">Bayinin Göreceği<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">KDV Dahil</span></th>
  <th style="width:90px"></th>
</tr></thead>
<tbody>
<?php
foreach ($allRows as $row):
    $pid     = (int)$row['product_id'];
    $base    = (float)$row['base_price'];
    $vatRate = (float)($row['vat_rate'] ?? 20);
    $vatM    = 1 + $vatRate / 100;  // KDV çarpanı (örn: 1.20)
    $itemId  = $row['item_id'];

    // DB'de net saklı — KDV Dahil değerleri hesapla
    $fixedPrice    = $row['price'] !== null ? (float)$row['price'] : null;
    $itemDisc      = $row['discount_percent'];
    $itemAdjust    = $row['price_adjust'] ?? null;

    // Input alanlarında gösterilecek KDV Dahil değerler
    $fixedPriceInc  = ($fixedPrice !== null && $fixedPrice > 0) ? $fixedPrice * $vatM : null;
    $itemAdjustInc  = $itemAdjust !== null ? (float)$itemAdjust * $vatM : null;

    // Bayinin göreceği NET fiyatı hesapla — dealerPrice() önceliği ile
    if ($fixedPrice !== null && $fixedPrice > 0) {
        $netPrice = $fixedPrice;
        $netLabel = 'Sabit';
        $netColor = '#7c3aed';
    } elseif ($itemDisc !== null) {
        $netPrice = $base * (1 - (float)$itemDisc / 100);
        $netLabel = 'Ürün %';
        $netColor = '#b91c1c';
    } elseif ($itemAdjust !== null) {
        $netPrice = $base + (float)$itemAdjust;
        $netLabel = 'Ürün ±';
        $netColor = (float)$itemAdjust >= 0 ? '#0369a1' : '#15803d';
    } elseif ($globalDisc > 0) {
        $netPrice = $base * (1 - $globalDisc / 100);
        $netLabel = 'Liste %';
        $netColor = '#b91c1c';
    } elseif ($globalAdjust !== null && (float)$globalAdjust != 0) {
        $netPrice = $base + (float)$globalAdjust;
        $netLabel = 'Liste ±';
        $netColor = (float)$globalAdjust >= 0 ? '#0369a1' : '#15803d';
    } else {
        $netPrice = $base;
        $netLabel = 'Standart';
        $netColor = 'var(--text-muted)';
    }
    if ($netPrice < 0) $netPrice = 0;

    // Gösterimde KDV Dahil
    $baseInc     = $base * $vatM;
    $netPriceInc = $netPrice * $vatM;
?>
<tr data-product-id="<?= $pid ?>" data-vat-mult="<?= $vatM ?>" style="<?= $itemId ? '' : 'background:#fafafa' ?>">
  <td class="fw-600" style="min-width:200px"><?= h($row['name']) ?>
    <?php if ($itemId): ?><span style="font-size:10px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;margin-left:6px;font-weight:600">ÖZEL</span><?php endif; ?>
  </td>
  <td class="text-muted fs-12"><?= h($row['sku']??'—') ?></td>
  <td class="fs-12">
    <?= money($baseInc) ?>
    <div style="font-size:10px;color:var(--text-muted)">%<?= number_format($vatRate, 0) ?> KDV</div>
  </td>
  <td>
    <input type="number" step="0.01" min="0" class="form-control pli-input"
           data-product-id="<?= $pid ?>" data-field="price"
           value="<?= $fixedPriceInc !== null ? number_format($fixedPriceInc, 2, '.', '') : '' ?>"
           placeholder="Boş"
           style="padding:6px 8px;font-size:12px;height:32px;min-height:32px">
  </td>
  <td>
    <input type="number" step="0.01" min="0" max="100" class="form-control pli-input"
           data-product-id="<?= $pid ?>" data-field="discount_percent"
           value="<?= $itemDisc !== null ? number_format((float)$itemDisc, 2, '.', '') : '' ?>"
           placeholder="Boş"
           style="padding:6px 8px;font-size:12px;height:32px;min-height:32px">
  </td>
  <td>
    <input type="number" step="0.01" class="form-control pli-input"
           data-product-id="<?= $pid ?>" data-field="price_adjust"
           value="<?= $itemAdjustInc !== null ? number_format($itemAdjustInc, 2, '.', '') : '' ?>"
           placeholder="Boş"
           style="padding:6px 8px;font-size:12px;height:32px;min-height:32px">
  </td>
  <td>
    <input type="number" step="1" min="1" class="form-control pli-input"
           data-product-id="<?= $pid ?>" data-field="min_order_qty"
           value="<?= $row['min_order_qty'] !== null ? (int)$row['min_order_qty'] : '' ?>"
           placeholder="1"
           style="padding:6px 8px;font-size:12px;height:32px;min-height:32px">
  </td>
  <td>
    <div style="font-weight:700;font-size:13px;color:<?= $netColor ?>"><?= money($netPriceInc) ?></div>
    <div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px"><?= $netLabel ?></div>
  </td>
  <td>
    <button class="btn btn-sm pli-save-btn" data-product-id="<?= $pid ?>"
            style="background:#10b981;color:#fff;border:none;padding:6px 10px;font-size:12px;font-weight:600;width:100%">
      💾 Kaydet
    </button>
    <?php if ($itemId): ?>
    <button class="btn btn-sm pli-delete-btn" data-item-id="<?= $itemId ?>"
            style="background:transparent;color:#dc2626;border:none;padding:4px;font-size:11px;width:100%;margin-top:4px"
            title="Bu ürünün özel fiyatını sil (standart liste kuralına döner)">
      🗑 Özel fiyatı sil
    </button>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (empty($allRows)): ?>
<tr><td colspan="9" class="text-center text-muted" style="padding:32px">
  <?php if ($search): ?>
  "<?= h($search) ?>" araması için ürün bulunamadı.
  <?php else: ?>
  Sistemde hiç aktif ürün yok. <a href="?page=products&action=add">Ürün ekle →</a>
  <?php endif; ?>
</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<div class="card-footer" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <span class="text-muted fs-12">Toplam <?= count($allRows) ?> ürün · <?= $overrideCount ?> ürünün özel fiyatı tanımlı</span>
  <a href="?page=price-lists&id=<?= $listId ?>&action=export-csv" class="btn btn-secondary btn-sm" style="margin-left:auto">📥 CSV İndir</a>
  <button class="btn btn-secondary btn-sm" data-modal-open="modal-import">📤 CSV İçe Aktar</button>
</div>
</div>

<!-- Alt navigasyon -->
<div style="display:flex;gap:12px;margin-top:16px">
  <a href="?page=price-lists" class="btn btn-secondary btn-sm">← Tüm Listeler</a>
  <a href="?page=price-lists&id=<?= $listId ?>&action=dealers" class="btn btn-secondary btn-sm">Bayi Atamaları →</a>
</div>
</div>

<!-- Inline kaydetme + silme JS -->
<script>
(function() {
    const listId = <?= (int)$listId ?>;
    const csrfToken = <?= json_encode(csrfToken()) ?>;

    // Kaydet butonları
    document.querySelectorAll('.pli-save-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const pid = this.dataset.productId;
            const row = this.closest('tr');
            const inputs = row.querySelectorAll('.pli-input');

            const data = new FormData();
            data.append('csrf_token', csrfToken);
            data.append('product_id', pid);
            inputs.forEach(i => data.append(i.dataset.field, i.value));

            const oldText = this.innerHTML;
            this.innerHTML = '⏳';
            this.disabled = true;

            try {
                const res = await fetch(`?page=price-lists&id=${listId}&action=save-item`, {
                    method: 'POST',
                    body: data,
                });
                const json = await res.json();
                if (json.ok) {
                    this.innerHTML = '✓ Kaydedildi';
                    this.style.background = '#16a34a';
                    setTimeout(() => location.reload(), 600);
                } else {
                    throw new Error(json.msg || 'Kaydetme hatası');
                }
            } catch (e) {
                this.innerHTML = '✗ Hata';
                this.style.background = '#dc2626';
                alert('Kaydetme hatası: ' + e.message);
                setTimeout(() => {
                    this.innerHTML = oldText;
                    this.style.background = '#10b981';
                    this.disabled = false;
                }, 2000);
            }
        });
    });

    // Sil butonları
    document.querySelectorAll('.pli-delete-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('Bu ürünün özel fiyatı silinecek ve liste kuralına dönecek. Devam edilsin mi?')) return;
            const itemId = this.dataset.itemId;
            const data = new FormData();
            data.append('csrf_token', csrfToken);
            data.append('item_id', itemId);
            try {
                await fetch(`?page=price-lists&id=${listId}&action=delete-item`, { method:'POST', body:data });
                location.reload();
            } catch(e) { alert('Silme hatası: ' + e.message); }
        });
    });

    // Arama kutusu canlı (basit form submit)
    const searchBox = document.getElementById('price-search');
    if (searchBox) {
        let timer;
        searchBox.addEventListener('input', function() {
            clearTimeout(timer);
            timer = setTimeout(() => {
                const url = new URL(window.location.href);
                if (this.value.trim()) url.searchParams.set('q', this.value.trim());
                else url.searchParams.delete('q');
                window.location.href = url.toString();
            }, 500);
        });
    }
})();
</script>

<!-- Modal: Ürün Fiyat Ekle/Düzenle -->
<div class="modal-overlay" id="modal-add-price">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-price-title">Ürün Fiyatı Ekle</h3>
      <button class="btn btn-ghost btn-icon" data-modal-close>✕</button>
    </div>
    <div class="modal-body">
      <form id="form-price-item" method="post" action="?page=price-lists&id=<?= $listId ?>&action=save-item">
        <?= csrfField() ?>
        <input type="hidden" name="item_id" id="price-item-id">
        <div class="form-group">
          <label class="form-label">Ürün *</label>
          <select name="product_id" id="price-product-id" class="form-control" required>
            <option value="">-- Ürün Seç --</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>" data-base="<?= $p['base_price'] ?>">
              <?= h($p['name']) ?> <?= $p['sku'] ? '('.$p['sku'].')' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Baz Fiyat (KDV Hariç) *</label>
          <div class="input-group">
            <input type="number" name="price" id="price-amount" step="0.01" min="0" class="form-control" required>
            <span class="input-addon">₺</span>
          </div>
        </div>
        <div class="form-row col-2">
          <div class="form-group">
            <label class="form-label">Ek İskonto (%)</label>
            <input type="number" name="discount_percent" id="price-discount" step="0.01" min="0" max="100" class="form-control" placeholder="Boş = liste iskontosu">
          </div>
          <div class="form-group">
            <label class="form-label">Min. Sipariş Adedi</label>
            <input type="number" name="min_order_qty" id="price-minqty" min="1" class="form-control" placeholder="Boş = ürün ayarı">
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" data-modal-close>İptal</button>
      <button class="btn btn-primary" onclick="document.getElementById('form-price-item').submit()">Kaydet</button>
    </div>
  </div>
</div>

<!-- Modal: CSV Import -->
<div class="modal-overlay" id="modal-import">
  <div class="modal">
    <div class="modal-header">
      <h3>CSV ile Toplu Fiyat Yükleme</h3>
      <button class="btn btn-ghost btn-icon" data-modal-close>✕</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info">
        CSV formatı: <code>SKU;Fiyat;İskonto(%)</code><br>
        Örnek: <code>URN001;150.00;10</code><br>
        Separator: noktalı virgül (;) · İlk satır header olarak atlanır
      </div>
      <form method="post" enctype="multipart/form-data" action="?page=price-lists&id=<?= $listId ?>&action=import-csv">
        <?= csrfField() ?>
        <div class="form-group">
          <div class="file-drop" onclick="this.querySelector('input').click()">
            <div>📄 CSV dosyasını seçin</div>
            <input type="file" name="csv" accept=".csv,text/csv">
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Yükle ve İçe Aktar</button>
      </form>
    </div>
  </div>
</div>

<script>
// Ürün seçildiğinde baz fiyatı otomatik doldur
document.getElementById('price-product-id')?.addEventListener('change', function() {
  const base = this.options[this.selectedIndex]?.dataset.base;
  if (base) document.getElementById('price-amount').value = parseFloat(base).toFixed(2);
});

function editPriceItem(id, productId, name, price, discount, minQty) {
  document.getElementById('modal-price-title').textContent = 'Ürün Fiyatı Düzenle: ' + name;
  document.getElementById('price-item-id').value = id;
  document.getElementById('price-product-id').value = productId;
  document.getElementById('price-amount').value = price;
  document.getElementById('price-discount').value = discount !== null ? discount : '';
  document.getElementById('price-minqty').value = minQty !== null ? minQty : '';
  openModal('modal-add-price');
}

function deletePriceItem(id) {
  confirmAction('Bu fiyat kaydını silmek istediğinize emin misiniz?', async () => {
    const r = await apiPost('?page=price-lists&id=<?= $listId ?>&action=delete-item', { item_id: id });
    if (r.ok) location.reload();
  });
}
</script>

<?php elseif ($action === 'dealers'): ?>
<?php
$list    = dbRow("SELECT * FROM b2b_price_lists WHERE id=?", [$listId]);
$dealers = dbRows("SELECT id,company_name,first_name,last_name,type,price_list_id FROM b2b_dealers WHERE is_active=1 ORDER BY company_name,first_name");
?>
<div class="page-body">
<div class="card-header" style="padding:0;margin-bottom:16px">
  <h2 style="font-size:16px"><?= h($list['name']??'') ?> — Bayi Atamaları</h2>
  <a href="?page=price-lists&id=<?= $listId ?>&action=items" class="btn btn-secondary btn-sm">← Fiyatlar</a>
</div>
<div class="card">
  <div class="card-header">
    <h2>Bu listeye bayi ata</h2>
    <span class="text-muted fs-12">Seçilen bayilere bu fiyat listesi atanır</span>
  </div>
  <div class="card-body">
    <form method="post" action="?page=price-lists&id=<?= $listId ?>&action=assign-dealers">
      <?= csrfField() ?>
      <div style="max-height:380px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:8px">
        <?php foreach ($dealers as $d):
          $name = $d['type']==='kurumsal' ? $d['company_name'] : trim($d['first_name'].' '.$d['last_name']);
          $checked = $d['price_list_id']==$listId ? 'checked' : '';
        ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:8px;border-radius:6px;<?= $checked ? 'background:var(--accent-light)' : '' ?>">
          <input type="checkbox" name="dealer_ids[]" value="<?= $d['id'] ?>" <?= $checked ?>>
          <span style="flex:1"><?= h($name) ?></span>
          <?php if ($d['price_list_id'] && $d['price_list_id']!=$listId): ?>
            <?php $otherList = dbRow("SELECT name FROM b2b_price_lists WHERE id=?",[$d['price_list_id']]); ?>
            <span class="badge badge-warning" style="font-size:10px">Şu an: <?= h($otherList['name']??'?') ?></span>
          <?php elseif ($checked): ?>
            <span class="badge badge-primary" style="font-size:10px">Bu liste</span>
          <?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="btn-group" style="margin-top:16px">
        <button type="submit" class="btn btn-primary">Atamaları Kaydet</button>
      </div>
    </form>
  </div>
</div>
</div>

<?php endif; ?>
