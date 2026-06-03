<?php
/**
 * Fatura PDF Stream — Bayi + Admin
 *
 * Öncelik sırası:
 *   1. Paraşüt'ten kesilmişse (parasut_invoice_id var) → Paraşüt'ten taze PDF URL'i çek + 302 redirect
 *   2. Cache edilmiş URL varsa (parasut_invoice_pdf_url) → eski URL'le redirect (fallback)
 *   3. Admin manuel yüklediyse (invoice_pdf_path) → dosyayı stream et
 *   4. Hiçbiri yoksa → güzel hata sayfası
 *
 * URL: ?page=invoice-pdf&order_id=42
 */

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    http_response_code(400);
    die('Geçersiz istek.');
}

$order = dbRow(
    "SELECT id, order_no, dealer_id, invoice_no,
            parasut_invoice_id, parasut_invoice_pdf_url, invoice_pdf_path
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

// Audit log helper
$logDownload = function() use ($isDealer, $order) {
    if ($isDealer) {
        try {
            auditLog('invoice_pdf_downloaded_by_dealer', 'b2b_orders', (int)$order['id'], [
                'dealer_id' => $order['dealer_id'],
            ]);
        } catch (\Throwable $e) {}
    }
};

// ─── 1. ÖNCELİK: Paraşüt'ten taze PDF URL ───
if (!empty($order['parasut_invoice_id']) && function_exists('parasut')) {
    try {
        $pdfUrl = parasut()->getInvoicePdfUrl((string)$order['parasut_invoice_id']);
        if (!empty($pdfUrl)) {
            // URL'i veritabanına cache et (sonraki istekler için)
            try {
                dbExec(
                    "UPDATE b2b_orders SET parasut_invoice_pdf_url=?, parasut_synced_at=NOW() WHERE id=?",
                    [$pdfUrl, $orderId]
                );
            } catch (\Throwable $e) {}

            $logDownload();
            // Paraşüt URL'i geçici signed URL'dir (birkaç dakika geçerli)
            // 302 ile yönlendir, browser direkt Paraşüt'ten PDF'i alır
            header('Location: ' . $pdfUrl, true, 302);
            exit;
        }
    } catch (\Throwable $e) {
        // Paraşüt API hatası - fallback'lere geç
    }
}

// ─── 2. FALLBACK: Cache edilmiş eski URL (Paraşüt geçici offline ise) ───
if (!empty($order['parasut_invoice_pdf_url'])) {
    $logDownload();
    header('Location: ' . $order['parasut_invoice_pdf_url'], true, 302);
    exit;
}

// ─── 3. FALLBACK: Admin manuel yüklediği PDF ───
if (!empty($order['invoice_pdf_path'])) {
    $relPath  = ltrim($order['invoice_pdf_path'], '/');
    $fullPath = B2B_ROOT . '/' . $relPath;
    if (file_exists($fullPath) && is_readable($fullPath)) {
        $filename = 'Fatura-' . $order['order_no'];
        if (!empty($order['invoice_no'])) {
            $filename .= '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $order['invoice_no']);
        }
        $filename .= '.pdf';

        $logDownload();

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($fullPath);
        exit;
    }
}

// ─── 4. PDF YOK: Friendly hata sayfası ───
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="tr"><head><meta charset="utf-8"><title>Fatura PDF Bulunamadı</title>
<style>
  body { font-family: -apple-system, sans-serif; background:#f8fafc; padding:40px; text-align:center; margin:0 }
  .box { max-width:520px; margin:60px auto; background:#fff; padding:30px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.06) }
  h1 { color:#dc2626; font-size:20px; margin:0 0 12px }
  p { color:#475569; line-height:1.6; margin:8px 0 }
  .info { background:#fef3c7; color:#92400e; padding:10px 14px; border-radius:6px; font-size:13px; margin-top:14px; text-align:left }
  a { display:inline-block;margin-top:14px;color:#dc2626;text-decoration:none;font-weight:600;padding:8px 18px;border:1px solid #dc2626;border-radius:6px }
  a:hover { background:#dc2626; color:#fff }
</style>
</head><body>
<div class="box">
  <div style="font-size:48px">📄</div>
  <h1>Fatura PDF'i Henüz Hazır Değil</h1>
  <p>Sipariş <strong><?= h($order['order_no']) ?></strong> için fatura PDF'i sistem üzerinden henüz erişilebilir değil.</p>
  <?php if (!empty($order['invoice_no'])): ?>
  <div class="info">
    <strong>Fatura No:</strong> <?= h($order['invoice_no']) ?><br>
    <?php if (empty($order['parasut_invoice_id'])): ?>
    Bu fatura sisteme manuel olarak girilmiş. Paraşüt'le ilişkilendirilmemiş.
    <?php else: ?>
    Paraşüt'ten PDF alınamadı. Geçici bir bağlantı sorunu olabilir, lütfen birkaç dakika sonra tekrar deneyin.
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <a href="javascript:history.back()">← Geri Dön</a>
</div>
</body></html>
