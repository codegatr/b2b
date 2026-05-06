<?php
/**
 * İrsaliye/Sevkiyat Fişi — A4 dikey, 2 nüsha (Müşteri + Bayi kopyası)
 * Tek A4 sayfaya sığar, ortada kesim çizgisi.
 *
 * admin/pages/orders.php?action=detail&id=X&print=irsaliye
 */
$siteName     = setting('site_name', 'B2B Bayi Portalı');
$companyTitle = strtoupper($siteName);
// Logo altındaki metin: domain yerine kurumsal isim
$companySub   = 'Le Monde Du Tacos';

// Logo: settings.php'den yüklenmiş login_image kullanılır
$logoFile = setting('login_image', '');
$logoUrl  = '';
if ($logoFile) {
    // Sunucu içinden file:// path kullanmak yerine HTTP URL — print'te tarayıcı yükler
    $siteUrl = rtrim(setting('site_url', ''), '/');
    $logoUrl = $siteUrl . '/uploads/logo/' . $logoFile;
}

/** Bir nüshayı (yarım sayfa) render eden helper */
function renderCopy(array $order, array $items, string $copyType, array $cfg) {
    $orderDate = date('d.m.Y', strtotime($order['created_at']));
    $customerName = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')) ?: ($order['company_name'] ?? '—');
    $companyName  = $order['company_name'] ?? '';
    $paymentLabel = paymentMethodLabel($order['payment_method'] ?? '');
    ?>
    <div class="copy">
      <!-- Header — kompakt -->
      <div class="copy-header">
        <div class="brand">
          <?php if (!empty($cfg['logoUrl'])): ?>
            <img src="<?= htmlspecialchars($cfg['logoUrl']) ?>" alt="" class="brand-logo">
          <?php else: ?>
            <div class="brand-name"><?= htmlspecialchars($cfg['companyTitle']) ?></div>
          <?php endif; ?>
          <div class="brand-sub"><?= htmlspecialchars($cfg['companySub']) ?></div>
        </div>
        <div class="header-right">
          <div class="copy-label"><?= htmlspecialchars($copyType) ?></div>
          <div class="doc-type">SİPARİŞ / İRSALİYE FİŞİ</div>
        </div>
      </div>

      <!-- Bilgi şeridi — tek satır, dört kolon -->
      <div class="info-strip">
        <div class="info-cell">
          <div class="info-label">SAYIN</div>
          <div class="info-value"><?= htmlspecialchars($customerName) ?></div>
          <?php if ($companyName && $companyName !== $customerName): ?>
          <div class="info-sub"><?= htmlspecialchars($companyName) ?></div>
          <?php endif; ?>
        </div>
        <div class="info-cell">
          <div class="info-label">SİPARİŞ NO</div>
          <div class="info-value mono"><?= htmlspecialchars($order['order_no']) ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">TARİH</div>
          <div class="info-value"><?= $orderDate ?></div>
        </div>
        <div class="info-cell">
          <div class="info-label">ÖDEME</div>
          <div class="info-value"><?= htmlspecialchars($paymentLabel) ?></div>
        </div>
      </div>

      <?php
        // Telefon formatı: '05070051993' → '0 507 005 19 93' (sevkiyat için kolay okuma)
        $rawPhone = preg_replace('/\D+/', '', ($order['mobile'] ?? '') ?: ($order['phone'] ?? ''));
        $fmtPhone = '';
        if (strlen($rawPhone) === 11 && $rawPhone[0] === '0') {
            $fmtPhone = $rawPhone[0] . ' ' . substr($rawPhone,1,3) . ' ' . substr($rawPhone,4,3) . ' ' . substr($rawPhone,7,2) . ' ' . substr($rawPhone,9,2);
        } elseif (strlen($rawPhone) === 10) {
            $fmtPhone = '0 ' . substr($rawPhone,0,3) . ' ' . substr($rawPhone,3,3) . ' ' . substr($rawPhone,6,2) . ' ' . substr($rawPhone,8,2);
        } else {
            $fmtPhone = $rawPhone;
        }

        // Vergi bilgisi
        $vergi = '';
        if (!empty($order['tax_office']) || !empty($order['tax_number'])) {
            $vergi = trim(($order['tax_office'] ?? '') . ' / ' . ($order['tax_number'] ?? ''), ' /');
        }

        // Teslimat adresi: o.shipping_address > d.address fallback
        $deliveryAddress = trim($order['shipping_address'] ?? '') ?: trim($order['address'] ?? '');
      ?>
      <!-- 1. Satır: İrtibat - İlçe - Şehir - Vergi -->
      <?php if ($fmtPhone || !empty($order['district']) || !empty($order['city']) || $vergi): ?>
      <div class="contact-row">
        <?php if ($fmtPhone): ?><span><strong>İrtibat:</strong> <span class="mono"><?= htmlspecialchars($fmtPhone) ?></span></span><?php endif; ?>
        <?php if (!empty($order['district'])): ?><span><strong>İlçe:</strong> <?= htmlspecialchars($order['district']) ?></span><?php endif; ?>
        <?php if (!empty($order['city'])):     ?><span><strong>Şehir:</strong> <?= htmlspecialchars($order['city']) ?></span><?php endif; ?>
        <?php if ($vergi):                      ?><span><strong>Vergi:</strong> <?= htmlspecialchars($vergi) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- 2. Satır: Teslimat Adresi (tam metin) -->
      <?php if (!empty($deliveryAddress)): ?>
      <div class="address-row">
        <strong>Teslimat Adresi:</strong> <?= htmlspecialchars($deliveryAddress) ?>
      </div>
      <?php endif; ?>

      <!-- Kalem Tablosu -->
      <table class="items">
        <thead>
          <tr>
            <th class="col-name">ÜRÜN</th>
            <th class="col-num">SİP. MİK.</th>
            <th class="col-num">TES. MİK.</th>
            <th class="col-num">BİRİM</th>
            <th class="col-num">B. FİYAT</th>
            <th class="col-num">TOPLAM</th>
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

              $shortDelivery = $delivered !== null && $delivered < $qty;
          ?>
          <tr<?= $shortDelivery ? ' class="row-short"' : '' ?>>
            <td class="col-name">
              <span class="item-name"><?= htmlspecialchars($it['product_name']) ?></span><?php if (!empty($it['product_sku'])): ?> <span class="item-sku">· <?= htmlspecialchars($it['product_sku']) ?></span><?php endif; ?>
            </td>
            <td class="col-num"><?= number_format($qty, 0, ',', '.') ?></td>
            <td class="col-num delivered <?= $shortDelivery ? 'short' : '' ?>">
              <?php if ($delivered === null): ?>
                <span class="placeholder">_____</span>
              <?php else: ?>
                <?= number_format($delivered, 0, ',', '.') ?><?php if ($shortDelivery): ?> <span class="short-tag">EKSİK</span><?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="col-num"><?= htmlspecialchars($it['unit'] ?? 'Adet') ?></td>
            <td class="col-num"><?= number_format($unitGross, 2, ',', '.') ?></td>
            <td class="col-num strong"><?= number_format($lineTotal, 2, ',', '.') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="5" class="footer-label">Ara Toplam</td><td class="col-num"><?= number_format($sub, 2, ',', '.') ?></td></tr>
          <tr><td colspan="5" class="footer-label">KDV Toplamı</td><td class="col-num"><?= number_format($vat, 2, ',', '.') ?></td></tr>
          <tr class="grand"><td colspan="5" class="footer-label">GENEL TOPLAM (KDV Dahil)</td><td class="col-num"><?= number_format((float)$order['grand_total'], 2, ',', '.') ?> ₺</td></tr>
        </tfoot>
      </table>

      <?php if (!empty($order['dealer_note']) || !empty($order['admin_note'])): ?>
      <div class="note-box">
        <?php if (!empty($order['dealer_note'])): ?><strong>Bayi Notu:</strong> <?= htmlspecialchars($order['dealer_note']) ?><?php endif; ?>
        <?php if (!empty($order['admin_note'])):  ?> <?= !empty($order['dealer_note']) ? '· ' : '' ?><strong>Not:</strong> <?= htmlspecialchars($order['admin_note']) ?><?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- İmza Alanları — kompakt -->
      <div class="signature-row">
        <div class="signature-box">
          <div class="signature-label">TESLİMAT DURUMU</div>
        </div>
        <div class="signature-box">
          <div class="signature-label">TESLİM ALAN — İmza</div>
        </div>
        <div class="signature-box">
          <div class="signature-label">TESLİM EDEN / KAŞE</div>
        </div>
      </div>

      <div class="footer-note">Eksik teslim için "TES. MİK." sütununa gerçek miktarı yazın ve "TESLİM ALAN" alanını imzalayın.</div>
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
  /* @page margin'i 0 — kenar boşluklarını .page içinde yönetiyoruz.
     Bu sayede tarayıcı kendi varsayılan margin'ini eklemiyor ve
     iki nüsha üst üste binmiyor. */
  @page { size: A4 portrait; margin: 0; }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #111; background: #f5f5f5; }
  body { padding: 8px; }

  /* A4 sayfa — flex column ile iki nüsha + kesim çizgisi */
  .page {
    width: 210mm; height: 297mm; max-width: 100%;
    margin: 0 auto; background: #fff;
    padding: 8mm; display: flex; flex-direction: column; gap: 0;
    box-shadow: 0 0 8px rgba(0,0,0,.08);
    overflow: hidden;
  }

  /* Tek nüsha — flex:1, ikisi sayfayı paylaşır */
  .copy {
    flex: 1 1 0;
    display: flex; flex-direction: column;
    border: 0.5px solid #999; padding: 3.5mm 4.5mm;
    page-break-inside: avoid; min-height: 0;
    overflow: hidden;
  }

  /* Header */
  .copy-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5mm; padding-bottom: 2mm; border-bottom: 1.5px solid #c1272d; }
  .brand-logo { max-height: 12mm; max-width: 50mm; display: block; }
  .brand-name { font-size: 14px; font-weight: 700; color: #c1272d; letter-spacing: .3px; line-height: 1; }
  .brand-sub  { font-size: 8px; color: #666; margin-top: 1mm; }
  .header-right { text-align: right; }
  .copy-label { font-size: 9px; color: #666; }
  .doc-type   { font-size: 11px; font-weight: 700; margin-top: 0.5mm; }

  /* Bilgi şeridi — kompakt 4 sütun */
  .info-strip { display: grid; grid-template-columns: 2fr 1.2fr 1fr 1.2fr; gap: 3mm; padding: 2mm 3mm; background: #f8f8f8; border: 0.5px solid #ddd; margin-bottom: 1.5mm; }
  .info-cell  { min-width: 0; }
  .info-label { font-size: 7px; color: #888; letter-spacing: .3px; }
  .info-value { font-size: 10px; font-weight: 700; line-height: 1.2; word-break: break-word; }
  .info-value.mono { font-family: ui-monospace, 'Courier New', monospace; font-size: 9px; }
  .info-sub   { font-size: 7px; color: #666; margin-top: .3mm; }

  .contact-row { font-size: 8px; color: #555; padding: 1.5mm 0; display: flex; gap: 8px; flex-wrap: wrap; border-bottom: 0.5px dashed #ddd; margin-bottom: 1.5mm; }
  .contact-row strong { color: #333; }
  .contact-row .mono { font-family: ui-monospace, 'Courier New', monospace; font-weight: 600; letter-spacing: .3px; color: #111; }

  /* Teslimat Adresi — vurgulu satır */
  .address-row { font-size: 8.5px; color: #1f2937; padding: 1.5mm 2.5mm; background: #fef3c7; border-left: 2px solid #f59e0b; border-radius: 0 3px 3px 0; margin-bottom: 1.5mm; line-height: 1.35; }
  .address-row strong { color: #92400e; }

  /* Kalem tablosu — kompakt */
  table.items { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 1.5mm; }
  table.items thead th { background: #2c2c2c; color: #fff; padding: 1.5mm; text-align: center; font-weight: 600; font-size: 7.5px; letter-spacing: .3px; line-height: 1.1; }
  table.items thead th.col-name { text-align: left; }
  table.items tbody td { border-bottom: 0.3px solid #eee; padding: 1.2mm 1.5mm; line-height: 1.25; }
  table.items tbody tr:last-child td { border-bottom: 0.5px solid #999; }
  table.items .col-name { text-align: left; }
  table.items .col-num  { text-align: right; white-space: nowrap; }
  table.items .col-num.delivered { text-align: center; min-width: 14mm; }
  table.items .item-name { font-weight: 600; }
  table.items .item-sku  { font-size: 7px; color: #888; }
  table.items .strong    { font-weight: 700; }

  table.items tr.row-short { background: rgba(255, 200, 100, .15); }
  table.items .delivered.short { color: #b91c1c; font-weight: 700; }
  table.items .placeholder { color: #aaa; letter-spacing: 1px; font-size: 9px; }
  table.items .short-tag { font-size: 6.5px; color: #b91c1c; font-weight: 700; padding: 0 2px; border: 0.5px solid #b91c1c; border-radius: 1px; vertical-align: middle; }

  table.items tfoot td { padding: 0.8mm 1.5mm; font-size: 9px; border: none; }
  table.items tfoot .footer-label { text-align: right; color: #555; }
  table.items tfoot .grand td { border-top: 1px solid #2c2c2c; padding-top: 1.5mm; font-weight: 700; font-size: 11px; }
  table.items tfoot .grand .footer-label { color: #2c2c2c; }
  table.items tfoot .grand .col-num { color: #c1272d; }

  /* Not kutusu */
  .note-box { background: #fffbeb; border-left: 2px solid #f59e0b; padding: 1mm 2mm; font-size: 7.5px; margin-bottom: 1.5mm; line-height: 1.3; }

  /* İmza alanları — küçük */
  .signature-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2mm; margin-top: auto; padding-top: 1.5mm; }
  .signature-box { border: 0.5px solid #999; padding: 1mm 2mm; min-height: 11mm; }
  .signature-label { font-size: 7px; color: #888; letter-spacing: .3px; }

  .footer-note { font-size: 6.5px; color: #888; text-align: center; margin-top: 1mm; padding-top: 1mm; border-top: 0.5px dashed #ddd; font-style: italic; }

  /* Kesim çizgisi (iki nüsha arasında) */
  .cut-line { display: flex; align-items: center; gap: 4mm; height: 5mm; padding: 0 4mm; flex-shrink: 0; }
  .cut-line .scissors { font-size: 11px; color: #888; }
  .cut-line .dash { flex: 1; border-top: 1px dashed #888; }
  .cut-line .label { font-size: 7px; color: #888; letter-spacing: .8px; padding: 0 3mm; }

  /* Print kontrolleri (sadece ekranda) */
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
    html, body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
    .page {
      box-shadow: none !important;
      width: 210mm !important;
      height: 297mm !important;
      padding: 8mm !important;
      margin: 0 !important;
      page-break-after: avoid;
      page-break-inside: avoid;
    }
    .print-controls { display: none !important; }
  }
</style>
</head>
<body>

<div class="print-controls">
  <button onclick="window.print()">🖨 Yazdır</button>
  <button class="secondary" onclick="window.close()">Kapat</button>
</div>

<div class="page">

  <?php
  $cfg = [
    'companyTitle' => $companyTitle,
    'companySub'   => $companySub,
    'logoUrl'      => $logoUrl,
  ];
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
  setTimeout(function() {
    if (window.matchMedia && window.matchMedia('(min-width: 600px)').matches) {
      window.print();
    }
  }, 400);
</script>

</body>
</html>
