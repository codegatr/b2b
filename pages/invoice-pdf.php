<?php
/**
 * Fatura PDF Stream — Bayi + Admin
 *
 * Akış:
 *   1. Login kontrol (bayi veya admin)
 *   2. Order ID parametresi
 *   3. Yetki: admin tüm siparişlere, bayi sadece kendi siparişine
 *   4. invoice_pdf_path varsa dosyayı stream et
 *
 * URL: ?page=invoice-pdf&order_id=42
 */

// Page-level access guard
$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    http_response_code(400);
    die('Geçersiz istek.');
}

$order = dbRow(
    "SELECT id, order_no, dealer_id, invoice_no, invoice_pdf_path
       FROM b2b_orders WHERE id=?",
    [$orderId]
);

if (!$order) {
    http_response_code(404);
    die('Sipariş bulunamadı.');
}

// ─── Yetki Kontrolü ───
$isAdmin  = function_exists('isAdminLoggedIn') && isAdminLoggedIn();
$isDealer = function_exists('isDealerLoggedIn') && isDealerLoggedIn();
$allowed  = false;

if ($isAdmin) {
    $allowed = true;
} elseif ($isDealer) {
    $dealer = currentDealer();
    if ($dealer && (int)$dealer['id'] === (int)$order['dealer_id']) {
        $allowed = true;
    }
}

if (!$allowed) {
    http_response_code(403);
    die('Bu faturaya erişim yetkiniz yok.');
}

// ─── PDF var mı? ───
if (empty($order['invoice_pdf_path'])) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="tr"><head><meta charset="utf-8"><title>Fatura Bulunamadı</title>
    <style>
      body { font-family: -apple-system, sans-serif; background:#f8fafc; padding:40px; text-align:center }
      .box { max-width:480px; margin:60px auto; background:#fff; padding:30px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.06) }
      h1 { color:#dc2626; font-size:20px; margin:0 0 12px }
      p { color:#475569; line-height:1.6 }
      a { display:inline-block;margin-top:14px;color:#dc2626;text-decoration:none;font-weight:600 }
    </style>
    </head><body>
    <div class="box">
      <div style="font-size:48px">📄</div>
      <h1>Fatura PDF'i Henüz Yüklenmedi</h1>
      <p>Sipariş <strong><?= h($order['order_no']) ?></strong> için fatura PDF'i sisteme henüz yüklenmemiş.<br>
      <?php if ($order['invoice_no']): ?>
      Fatura No: <strong><?= h($order['invoice_no']) ?></strong><br>
      <?php endif; ?>
      Lütfen tedarikçinizle iletişime geçin.</p>
      <a href="javascript:history.back()">← Geri Dön</a>
    </div>
    </body></html>
    <?php
    exit;
}

// ─── Dosya yolu ───
$relPath  = ltrim($order['invoice_pdf_path'], '/');
$fullPath = B2B_ROOT . '/' . $relPath;

if (!file_exists($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    die('Fatura dosyası bulunamadı veya erişilemez.');
}

// ─── Stream PDF ───
$filename = 'Fatura-' . $order['order_no'];
if (!empty($order['invoice_no'])) {
    $filename .= '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $order['invoice_no']);
}
$filename .= '.pdf';

// Audit log (bayi indirdi)
if ($isDealer) {
    try {
        auditLog('invoice_pdf_downloaded_by_dealer', 'b2b_orders', (int)$order['id'], [
            'dealer_id' => $order['dealer_id'],
        ]);
    } catch (\Throwable $e) {}
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($fullPath);
exit;
