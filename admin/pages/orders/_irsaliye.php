<?php
/**
 * İrsaliye/Sevkiyat Fişi — A4 dikey, 2 nüsha (Müşteri + Bayi kopyası)
 * admin/pages/orders.php?action=detail&id=X&print=irsaliye
 *
 * Beklenen değişkenler:
 *   $order  — b2b_orders + bayi bilgileri (JOIN ile)
 *   $items  — sipariş kalemleri (b2b_order_items)
 *
 * Tarayıcı print dialog'u otomatik açılır.
 */
$siteName  = setting('site_name', 'B2B Bayi Portalı');
$siteLogo  = '';  // İleride logo dosya yolu
$companyTitle = 'LE MONDE DU TACOS';
$companySub   = 'bayi.lemondedutacos.com';

$orderDate = date('d.m.Y', strtotime($order['created_at']));
$customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: ($order['company_name'] ?? '—');
$companyName  = $order['company_name'] ?? '';

$paymentLabel = paymentMethodLabel($order['payment_method'] ?? '');

/** Bir nüshayı (yarım sayfa) render eden helper */
function renderCopy(array $order, array $items, string $copyType, array $cfg) {
    $orderDate = date('d.m.Y', strtotime($order['created_at']));
    $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: ($order['company_name'] ?? '—');
    $companyName  = $order['company_name'] ?? '';
    $paymentLabel = paymentMethodLabel($order['payment_method'] ?? '');
    ?>
    <div class="copy">
      <!-- Header -->
      <div class="copy-header">
        <div class="brand">
          <div class="brand-name"><?= htmlspecialchars($cfg['companyTitle']) ?></div>
          <div class="brand-sub"><?= htmlspecialchars($cfg['companySub']) ?></div>
        </div>
        <div class="header-right">
          <div class="copy-label"><?= htmlspecialchars($copyType) ?></div>
          <div class="doc-type">SİPARİŞ / İRSALİYE FİŞİ</div>
        </div>
      </div>

      <!-- Müşteri ve Sipariş Bilgileri -->
      <div class="info-block">
        <div class="info-grid">
          <div class="info-cell">
            <div class="info-label">SAYIN</div>
            <div class="info-value"><?= htmlspecialchars($customerName) ?></div>
            <?php if ($companyName && $companyName !== $customerName): ?>
            <div class="info-sub"><?= htmlspecialchars($companyName) ?></div>
            <?php endif; ?>
          </div>
          <div class="info-cell">
            <div class="info-label">SİPARİŞ NO</div>
            <div class="info-value"><?= htmlspecialchars($order['order_no']) ?></div>
          </div>
          <div class="info-cell">
            <div class="info-label">SİPARİŞ TARİHİ</div>
            <div class="info-value"><?= $orderDate ?></div>
          </div>
          <div class="info-cell">
            <div class="info-label">ÖDEME YÖNTEMİ</div>
            <div class="info-value"><?= htmlspecialchars($paymentLabel) ?></div>
          </div>
        </div>
        <?php if (!empty($order['phone']) || !empty($order['city'])): ?>
        <div class="contact-row">
          <?php if (!empty($order['phone'])): ?><span><strong>İrtibat:</strong> <?= htmlspecialchars($order['phone']) ?></span><?php endif; ?>
          <?php if (!empty($order['city'])):  ?><span><strong>Şehir:</strong> <?= htmlspecialchars($order['city']) ?></span><?php endif; ?>
          <?php if (!empty($order['tax_office']) || !empty($order['tax_number'])): ?>
          <span><strong>Vergi:</strong> <?= htmlspecialchars($order['tax_office'] ?? '') ?> / <?= htmlspecialchars($order['tax_number'] ?? '') ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Kalem Tablosu -->
      <table class="items">
        <thead>
          <tr>
            <th class="col-name">ÜRÜN</th>
            <th class="col-num">SİPARİŞ MİKTARI</th>
            <th class="col-num">TESLİM MİKTARI</th>
            <th class="col-num">BİRİM</th>
            <th class="col-num">B. FİYAT<br><span class="th-sub">(KDV Dahil)</span></th>
            <th class="col-num">TOPLAM<br><span class="th-sub">(KDV Dahil)</span></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $sub = 0; $vat = 0;
          foreach ($items as $it):
              $qty       = (float)($it['qty'] ?? 0);
              $delivered = isset($it['delivered_qty']) && $it['delivered_qty'] !== null ? (float)$it['delivered_qty'] : null;
              $unitNet   = (float)($it['unit_price'] ?? 0);
              $vatRate   = (float)($it['vat_rate'] ?? 0);
              $unitGross = $unitNet * (1 + $vatRate/100);
              $lineNet   = $unitNet * $qty;
              $lineVat   = $lineNet * ($vatRate/100);
              $lineTotal = $lineNet + $lineVat;
              $sub += $lineNet; $vat += $lineVat;

              // Eksik teslim flag
              $shortDelivery = $delivered !== null && $delivered < $qty;
          ?>
          <tr<?= $shortDelivery ? ' class="row-short"' : '' ?>>
            <td class="col-name">
              <div class="item-name"><?= htmlspecialchars($it['product_name']) ?></div>
              <?php if (!empty($it['product_sku'])): ?><div class="item-sku">SKU: <?= htmlspecialchars($it['product_sku']) ?></div><?php endif; ?>
            </td>
            <td class="col-num"><?= number_format($qty, 0, ',', '.') ?></td>
            <td class="col-num delivered <?= $shortDelivery ? 'short' : '' ?>">
              <?php if ($delivered === null): ?>
                <span class="placeholder">_____</span>
              <?php else: ?>
                <?= number_format($delivered, 0, ',', '.') ?>
                <?php if ($shortDelivery): ?><div class="short-note">EKSİK</div><?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="col-num"><?= htmlspecialchars($it['unit'] ?? 'Adet') ?></td>
            <td class="col-num"><?= number_format($unitGross, 2, ',', '.') ?> ₺</td>
            <td class="col-num strong"><?= number_format($lineTotal, 2, ',', '.') ?> ₺</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="5" class="footer-label">Ara Toplam</td><td class="col-num"><?= number_format($sub, 2, ',', '.') ?> ₺</td></tr>
          <tr><td colspan="5" class="footer-label">KDV Toplamı</td><td class="col-num"><?= number_format($vat, 2, ',', '.') ?> ₺</td></tr>
          <tr class="grand"><td colspan="5" class="footer-label">GENEL TOPLAM</td><td class="col-num"><?= number_format((float)$order['grand_total'], 2, ',', '.') ?> ₺</td></tr>
        </tfoot>
      </table>

      <!-- Not / Açıklama -->
      <?php if (!empty($order['dealer_note']) || !empty($order['admin_note'])): ?>
      <div class="note-box">
        <?php if (!empty($order['dealer_note'])): ?><div><strong>Bayi Notu:</strong> <?= htmlspecialchars($order['dealer_note']) ?></div><?php endif; ?>
        <?php if (!empty($order['admin_note'])):  ?><div><strong>Not:</strong> <?= htmlspecialchars($order['admin_note']) ?></div><?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- İmza Alanları -->
      <div class="signature-row">
        <div class="signature-box">
          <div class="signature-label">TESLİMAT DURUMU</div>
        </div>
        <div class="signature-box">
          <div class="signature-label">TESLİM ALAN</div>
        </div>
        <div class="signature-box">
          <div class="signature-label">TESLİM EDEN / KAŞE</div>
        </div>
      </div>

      <div class="footer-note">
        Eksik teslim edilen ürünler için "TESLİM MİKTARI" sütununa gerçek miktarı yazınız ve "TESLİM ALAN" alanını imzalayınız.
      </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>İrsaliye <?= htmlspecialchars($order['order_no']) ?></title>
<style>
  @page { size: A4 portrait; margin: 8mm; }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #111; background: #f5f5f5; }
  body { padding: 12px; }

  /* A4 sayfa */
  .page {
    width: 210mm; min-height: 297mm; max-width: 100%;
    margin: 0 auto; background: #fff;
    padding: 8mm; display: flex; flex-direction: column; gap: 4mm;
    box-shadow: 0 0 8px rgba(0,0,0,.08);
  }

  /* Tek nüsha */
  .copy {
    flex: 1; display: flex; flex-direction: column;
    border: 1px solid #999; padding: 6mm 7mm;
    page-break-inside: avoid;
  }

  /* Header */
  .copy-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4mm; padding-bottom: 3mm; border-bottom: 2px solid #c1272d; }
  .brand-name { font-size: 18px; font-weight: 700; color: #c1272d; letter-spacing: .5px; }
  .brand-sub  { font-size: 10px; color: #666; margin-top: 1mm; }
  .header-right { text-align: right; }
  .copy-label { font-size: 11px; color: #666; }
  .doc-type { font-size: 13px; font-weight: 700; margin-top: 1mm; }

  /* Bilgi blok */
  .info-block { background: #f8f8f8; border: 0.5px solid #ddd; padding: 3mm 4mm; margin-bottom: 3mm; }
  .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4mm; }
  .info-label { font-size: 9px; color: #888; letter-spacing: .3px; margin-bottom: 1mm; }
  .info-value { font-size: 11px; font-weight: 600; }
  .info-sub   { font-size: 9px; color: #666; margin-top: .5mm; }
  .contact-row { font-size: 9px; color: #555; margin-top: 2mm; padding-top: 2mm; border-top: 0.5px dashed #ccc; display: flex; gap: 12px; flex-wrap: wrap; }
  .contact-row strong { color: #333; }

  /* Kalem tablosu */
  table.items { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 3mm; }
  table.items thead th { background: #2c2c2c; color: #fff; padding: 2.5mm 2mm; text-align: center; font-weight: 600; font-size: 9px; letter-spacing: .3px; }
  table.items thead th.col-name { text-align: left; }
  table.items thead th .th-sub { font-weight: 400; font-size: 8px; opacity: .8; }
  table.items tbody td { border-bottom: 0.5px solid #eee; padding: 2mm; }
  table.items tbody tr:last-child td { border-bottom: 1px solid #999; }
  table.items .col-name { text-align: left; }
  table.items .col-num  { text-align: right; white-space: nowrap; }
  table.items .col-num.delivered { text-align: center; min-width: 18mm; }
  table.items .item-name { font-weight: 600; }
  table.items .item-sku  { font-size: 8px; color: #888; margin-top: .5mm; }
  table.items .strong    { font-weight: 700; }

  /* Eksik teslim vurgu */
  table.items tr.row-short { background: rgba(255, 200, 100, .12); }
  table.items .delivered.short { color: #b91c1c; font-weight: 700; }
  table.items .placeholder { color: #999; letter-spacing: 2px; }
  table.items .short-note { font-size: 7px; color: #b91c1c; font-weight: 700; margin-top: .5mm; }

  /* Footer satırları */
  table.items tfoot td { padding: 1.5mm 2mm; font-size: 10px; border: none; }
  table.items tfoot .footer-label { text-align: right; color: #555; }
  table.items tfoot .grand td { border-top: 1.5px solid #2c2c2c; padding-top: 2mm; font-weight: 700; font-size: 12px; }
  table.items tfoot .grand .footer-label { color: #2c2c2c; }
  table.items tfoot .grand .col-num { color: #c1272d; }

  /* Not kutusu */
  .note-box { background: #fffbeb; border-left: 3px solid #f59e0b; padding: 2mm 3mm; font-size: 9px; margin-bottom: 3mm; }
  .note-box div { margin: .5mm 0; }

  /* İmza alanları */
  .signature-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 3mm; margin-top: auto; padding-top: 3mm; }
  .signature-box { border: 0.5px solid #999; padding: 2mm 3mm; min-height: 18mm; display: flex; flex-direction: column; justify-content: flex-start; }
  .signature-label { font-size: 9px; color: #888; letter-spacing: .3px; margin-bottom: 2mm; }

  .footer-note { font-size: 8px; color: #888; text-align: center; margin-top: 2mm; padding-top: 2mm; border-top: 0.5px dashed #ccc; font-style: italic; }

  /* Kesim çizgisi (iki nüsha arasında) */
  .cut-line { display: flex; align-items: center; gap: 6mm; height: 8mm; padding: 0 4mm; }
  .cut-line .scissors { font-size: 14px; color: #888; }
  .cut-line .dash { flex: 1; border-top: 1.5px dashed #888; }
  .cut-line .label { font-size: 9px; color: #888; letter-spacing: 1px; padding: 0 4mm; }

  /* Print butonları (sadece ekranda) */
  .print-controls {
    position: fixed; top: 12px; right: 12px; z-index: 9999;
    display: flex; gap: 8px;
  }
  .print-controls button {
    background: #c1272d; color: #fff; border: none; padding: 10px 16px;
    border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
  }
  .print-controls button.secondary { background: #fff; color: #333; border: 1px solid #ccc; }
  .print-controls button:hover { opacity: .9; }

  @media print {
    body { background: #fff; padding: 0; }
    .page { box-shadow: none; padding: 0; min-height: auto; }
    .print-controls { display: none !important; }
  }
</style>
</head>
<body>

<!-- Ekran kontrolleri -->
<div class="print-controls">
  <button onclick="window.print()">🖨 Yazdır</button>
  <button class="secondary" onclick="window.close()">Kapat</button>
</div>

<div class="page">

  <?php
  $cfg = ['companyTitle' => $companyTitle, 'companySub' => $companySub];
  // 1. Nüsha — Müşteri Kopyası
  renderCopy($order, $items, 'MÜŞTERİ KOPYASI', $cfg);
  ?>

  <!-- Kesim çizgisi -->
  <div class="cut-line">
    <span class="scissors">✂</span>
    <span class="dash"></span>
    <span class="label">KESİM ÇİZGİSİ</span>
    <span class="dash"></span>
  </div>

  <?php
  // 2. Nüsha — Bayi/Firma Kopyası
  renderCopy($order, $items, 'BAYİ KOPYASI', $cfg);
  ?>

</div>

<script>
  // Sayfa yüklenince otomatik print dialog (kullanıcı tek tıkla yazdırır)
  // İptal edebilir, ekrandaki "Yazdır" butonuyla tekrar açabilir.
  setTimeout(function() {
    if (window.matchMedia && window.matchMedia('(min-width: 600px)').matches) {
      // Mobilde otomatik açma; desktop'ta otomatik aç
      window.print();
    }
  }, 400);
</script>

</body>
</html>
