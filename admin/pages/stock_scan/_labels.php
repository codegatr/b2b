<?php
/**
 * QR Stok Etiketleri — A4 dikey, grid (3 sütun × 8 satır = 24 etiket/sayfa)
 * admin/pages/stock_scan.php?action=labels
 *
 * Beklenen değişkenler:
 *   $products — ürün listesi (id, name, sku, barcode, unit, stock, cat_name)
 */
$siteName = setting('site_name', 'B2B Bayi Portalı');

// Her ürün için bir QR payload üret. Format: ürünün barkodu varsa o, yoksa SKU, yoksa ID
function qrPayload(array $p): string {
    if (!empty($p['barcode'])) return $p['barcode'];
    if (!empty($p['sku']))     return $p['sku'];
    return (string)$p['id'];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>QR Etiketleri — <?= count($products) ?> ürün</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  @page { size: A4 portrait; margin: 8mm; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #111; background: #f5f5f5; }
  body { padding: 12px; }

  .doc { width: 210mm; max-width: 100%; margin: 0 auto; background: #fff; padding: 6mm; box-shadow: 0 0 8px rgba(0,0,0,.08); }

  /* Print kontrolleri */
  .controls { position: fixed; top: 12px; right: 12px; z-index: 9999; display: flex; gap: 8px; }
  .controls button, .controls a { background: #c1272d; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
  .controls a.secondary { background: #fff; color: #333; border: 1px solid #ccc; }

  /* Etiket grid: 3 sütun x N satır */
  .labels {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 3mm;
  }
  .label {
    border: 0.5px solid #999;
    border-radius: 2mm;
    padding: 2.5mm 2mm;
    text-align: center;
    page-break-inside: avoid;
    background: #fff;
    aspect-ratio: 1/1.05;  /* yaklaşık kare, hafif uzun */
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 1mm;
  }
  .label .qr {
    width: 32mm; height: 32mm;
    flex-shrink: 0;
  }
  .label .qr canvas, .label .qr img {
    width: 100% !important; height: 100% !important;
  }
  .label .name {
    font-size: 8.5px; font-weight: 700; line-height: 1.2;
    overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    width: 100%;
  }
  .label .meta {
    font-size: 7px; color: #555; font-family: ui-monospace, 'Courier New', monospace;
    line-height: 1.3;
  }
  .label .barcode-text {
    font-size: 6.5px; color: #888; font-family: ui-monospace, monospace;
    letter-spacing: .3px;
    word-break: break-all;
  }

  .header {
    margin-bottom: 5mm; padding-bottom: 3mm;
    border-bottom: 1.5px solid #c1272d;
    display: flex; justify-content: space-between; align-items: center;
  }
  .header h1 { font-size: 13px; margin: 0; color: #c1272d; }
  .header .date { font-size: 10px; color: #888; }

  @media print {
    body { background: #fff; padding: 0; }
    .doc { box-shadow: none; padding: 0; }
    .controls { display: none !important; }
  }
</style>
</head>
<body>

<div class="controls">
  <button onclick="window.print()">🖨 Yazdır</button>
  <a href="?page=stock_scan" class="secondary">← Tarama</a>
</div>

<div class="doc">
  <div class="header">
    <h1><?= htmlspecialchars($siteName) ?> — QR Stok Etiketleri</h1>
    <div class="date"><?= count($products) ?> ürün · <?= date('d.m.Y') ?></div>
  </div>

  <?php if (empty($products)): ?>
    <p style="text-align:center;color:#888;padding:40px">Aktif ürün bulunamadı.</p>
  <?php else: ?>
  <div class="labels">
    <?php foreach ($products as $p):
      $payload = qrPayload($p);
    ?>
    <div class="label">
      <div class="qr" data-payload="<?= htmlspecialchars($payload, ENT_QUOTES) ?>"></div>
      <div class="name"><?= htmlspecialchars($p['name']) ?></div>
      <div class="meta">
        <?= htmlspecialchars($p['sku'] ?? '') ?>
        <?php if (!empty($p['unit'])): ?> · <?= htmlspecialchars($p['unit']) ?><?php endif; ?>
      </div>
      <?php if (!empty($p['barcode']) && $p['barcode'] !== $p['sku']): ?>
      <div class="barcode-text"><?= htmlspecialchars($p['barcode']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
// Tüm .qr div'lerini QR koda dönüştür
document.querySelectorAll('.qr').forEach(div => {
  const payload = div.dataset.payload;
  if (!payload) return;
  new QRCode(div, {
    text: payload,
    width: 120,   // CSS ile küçültülür, yüksek çözünürlük baskı kalitesi için
    height: 120,
    correctLevel: QRCode.CorrectLevel.M
  });
});

// Sayfa yüklendiğinde otomatik print dialog
window.addEventListener('load', () => {
  setTimeout(() => {
    if (window.matchMedia && window.matchMedia('(min-width: 600px)').matches) {
      window.print();
    }
  }, 800);  // QR'ların render olması için biraz daha bekle
});
</script>

</body>
</html>
