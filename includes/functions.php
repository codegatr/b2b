<?php
/**
 * CODEGA B2B — Genel Yardımcı Fonksiyonlar
 */

// ──────────────────────────────────────────────────────────────
// FİYAT & STOK
// ──────────────────────────────────────────────────────────────

/**
 * Bayiye özel fiyatı getir
 * Öncelik: price_list_items > price_list iskonto > base_price
 */
function dealerPrice(int $productId, int $priceListId): array {
    // Fiyat listesi item'ı var mı?
    $item = dbRow(
        "SELECT pli.price, pli.discount_percent, pl.discount_percent as list_discount
         FROM b2b_price_list_items pli
         JOIN b2b_price_lists pl ON pl.id=pli.price_list_id
         WHERE pli.price_list_id=? AND pli.product_id=?",
        [$priceListId, $productId]
    );

    $product = dbRow("SELECT base_price, vat_rate, min_order_qty FROM b2b_products WHERE id=?", [$productId]);
    if (!$product) return ['price'=>0,'vat_rate'=>18,'discount'=>0,'min_qty'=>1];

    if ($item) {
        $price = (float)$item['price'];
        $discount = $item['discount_percent'] !== null
            ? (float)$item['discount_percent']
            : (float)$item['list_discount'];
        if ($discount > 0) {
            $price = $price * (1 - $discount / 100);
        }
    } else {
        // Fiyat listesi yok — base_price + liste iskontosu
        $pl = dbRow("SELECT discount_percent FROM b2b_price_lists WHERE id=?", [$priceListId]);
        $listDiscount = $pl ? (float)$pl['discount_percent'] : 0;
        $price = (float)$product['base_price'] * (1 - $listDiscount / 100);
    }

    return [
        'price'    => round($price, 2),
        'vat_rate' => (float)$product['vat_rate'],
        'discount' => $item['discount_percent'] ?? ($item['list_discount'] ?? 0),
        'min_qty'  => $item['min_order_qty'] ?? $product['min_order_qty'] ?? 1,
    ];
}

/** Fiyat + KDV formatı */
function fmtPrice(float $amount, string $currency = 'TRY'): string {
    return number_format($amount, 2, ',', '.') . ' ' . $currency;
}

/** Stok durumu etiketi */
function stockBadge(int $stock, int $critical): string {
    if ($stock <= 0) return '<span class="badge badge-danger">Stok Yok</span>';
    if ($stock <= $critical) return '<span class="badge badge-warning">Kritik</span>';
    return '<span class="badge badge-success">Stokta</span>';
}

// ──────────────────────────────────────────────────────────────
// SİPARİŞ
// ──────────────────────────────────────────────────────────────

function generateOrderNo(): string {
    $prefix = setting('order_prefix', 'SIP');
    $date   = date('Ymd');
    $last   = dbVal("SELECT MAX(id) FROM b2b_orders") ?? 0;
    return $prefix . $date . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
}

function orderStatusLabel(string $status): string {
    return match($status) {
        'bekliyor'       => '<span class="badge badge-warning">Bekliyor</span>',
        'onaylandi'      => '<span class="badge badge-info">Onaylandı</span>',
        'hazirlaniyor'   => '<span class="badge badge-info">Hazırlanıyor</span>',
        'kargoda'        => '<span class="badge badge-primary">Kargoda</span>',
        'teslim_edildi'  => '<span class="badge badge-success">Teslim Edildi</span>',
        'iptal'          => '<span class="badge badge-danger">İptal</span>',
        'iade'           => '<span class="badge badge-secondary">İade</span>',
        default          => '<span class="badge">' . htmlspecialchars($status) . '</span>',
    };
}

function paymentStatusLabel(string $status): string {
    return match($status) {
        'odenmedi' => '<span class="badge badge-danger">Ödenmedi</span>',
        'kismi'    => '<span class="badge badge-warning">Kısmen Ödendi</span>',
        'odendi'   => '<span class="badge badge-success">Ödendi</span>',
        default    => '<span class="badge">' . htmlspecialchars($status) . '</span>',
    };
}

// ──────────────────────────────────────────────────────────────
// CARİ HESAP
// ──────────────────────────────────────────────────────────────

/** Bayi bakiyesi: pozitif = alacaklı, negatif = borçlu */
function dealerBalance(int $dealerId): float {
    $alacak = (float)dbVal("SELECT COALESCE(SUM(amount),0) FROM b2b_ledger WHERE dealer_id=? AND type='alacak'", [$dealerId]);
    $borc   = (float)dbVal("SELECT COALESCE(SUM(amount),0) FROM b2b_ledger WHERE dealer_id=? AND type='borc'", [$dealerId]);
    return $alacak - $borc;
}

/** Vadesi geçmiş borç */
function overdueAmount(int $dealerId): float {
    return (float)dbVal(
        "SELECT COALESCE(SUM(amount),0) FROM b2b_ledger
         WHERE dealer_id=? AND type='borc' AND is_closed=0 AND due_date < CURDATE()",
        [$dealerId]
    );
}

/** Cari hesap kaydı ekle */
function ledgerAdd(int $dealerId, string $type, float $amount, string $desc, string $refType = 'manual', int $refId = 0, ?string $dueDate = null, int $createdBy = 0): int {
    return dbInsert(
        "INSERT INTO b2b_ledger (dealer_id,type,amount,description,reference_type,reference_id,due_date,created_by,created_at)
         VALUES (?,?,?,?,?,?,?,?,NOW())",
        [$dealerId, $type, $amount, $desc, $refType, $refId, $dueDate, $createdBy]
    );
}

// ──────────────────────────────────────────────────────────────
// STOK
// ──────────────────────────────────────────────────────────────

function stockUpdate(int $productId, int $change, string $changeType, string $refType = '', int $refId = 0, string $note = '', int $createdBy = 0): void {
    $product = dbRow("SELECT stock FROM b2b_products WHERE id=?", [$productId]);
    if (!$product) return;
    $before = (int)$product['stock'];
    $after  = $before + $change;
    dbExec("UPDATE b2b_products SET stock=?, updated_at=NOW() WHERE id=?", [$after, $productId]);
    dbInsert(
        "INSERT INTO b2b_stock_log (product_id,change_type,qty_before,qty_change,qty_after,reference_type,reference_id,note,created_by,created_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())",
        [$productId, $changeType, $before, $change, $after, $refType, $refId, $note, $createdBy]
    );
    // Kritik stok kontrolü
    $p = dbRow("SELECT name, stock_critical FROM b2b_products WHERE id=?", [$productId]);
    if ($p && $after <= $p['stock_critical'] && $after > 0) {
        notifyAdmin('stock_critical', "⚠ Kritik Stok: {$p['name']}", "Stok seviyesi $after adede düştü.");
    } elseif ($after <= 0) {
        notifyAdmin('stock_empty', "🔴 Stok Bitti: {$p['name']}", "Ürün stoğu tükendi.");
    }
}

// ──────────────────────────────────────────────────────────────
// BİLDİRİMLER
// ──────────────────────────────────────────────────────────────

function notifyDealer(int $dealerId, string $type, string $title, string $body = '', string $url = ''): void {
    dbInsert("INSERT INTO b2b_notifications (dealer_id,type,title,body,url,created_at) VALUES (?,?,?,?,?,NOW())",
        [$dealerId, $type, $title, $body, $url]);
}

function notifyAdmin(string $type, string $title, string $body = '', string $url = ''): void {
    // Tüm aktif adminlere
    $admins = dbRows("SELECT id FROM b2b_admin_users WHERE is_active=1");
    foreach ($admins as $a) {
        dbInsert("INSERT INTO b2b_notifications (admin_id,type,title,body,url,created_at) VALUES (?,?,?,?,?,NOW())",
            [$a['id'], $type, $title, $body, $url]);
    }
}

function unreadNotifCount(string $who = 'dealer'): int {
    if ($who === 'dealer' && isDealer()) {
        return (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE dealer_id=? AND is_read=0", [$_SESSION['dealer_id']]);
    }
    if ($who === 'admin' && isAdmin()) {
        return (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE admin_id=? AND is_read=0", [$_SESSION['admin_id']]);
    }
    return 0;
}

// ──────────────────────────────────────────────────────────────
// GENEL
// ──────────────────────────────────────────────────────────────

function slugify(string $text): string {
    $tr = ['ş'=>'s','ı'=>'i','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c',
           'Ş'=>'s','İ'=>'i','Ğ'=>'g','Ü'=>'u','Ö'=>'o','Ç'=>'c'];
    $text = strtr($text, $tr);
    $text = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($text));
    return trim($text, '-');
}

function h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function isAjax(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function uploadFile(array $file, string $destDir, array $allowedTypes = ['image/jpeg','image/png','image/webp']): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if (!in_array($file['type'], $allowedTypes)) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null; // 10MB limit

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = bin2hex(random_bytes(12)) . '.' . strtolower($ext);
    $dest = rtrim($destDir, '/') . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return $name;
}

/** Audit log */
function auditLog(string $action, string $table = '', int $recordId = 0, array $old = [], array $new = []): void {
    $userType = isAdmin() ? 'admin' : 'dealer';
    $userId   = isAdmin() ? ($_SESSION['admin_id'] ?? 0) : ($_SESSION['dealer_id'] ?? 0);
    dbInsert(
        "INSERT INTO b2b_audit_log (user_type,user_id,action,table_name,record_id,old_values,new_values,ip,created_at)
         VALUES (?,?,?,?,?,?,?,?,NOW())",
        [$userType, $userId, $action, $table, $recordId,
         $old ? json_encode($old) : null,
         $new  ? json_encode($new)  : null,
         clientIp()]
    );
}

/** Vade tarihi hesapla (sipariş tarihine göre) */
function calcDueDate(int $termDays): string {
    return date('Y-m-d', strtotime("+$termDays days"));
}

/** Para formatla */
function money(float $amount): string {
    return number_format($amount, 2, ',', '.') . ' ₺';
}

/** Tarih formatla */
function fmtDate(string $date): string {
    if (!$date || $date === '0000-00-00') return '—';
    return date('d.m.Y', strtotime($date));
}

function fmtDateTime(string $dt): string {
    if (!$dt) return '—';
    return date('d.m.Y H:i', strtotime($dt));
}

/** Pagination */
function pagination(int $total, int $perPage, int $currentPage, string $baseUrl): string {
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return '';
    $html = '<div class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= "<a href=\"{$baseUrl}&pg={$i}\" class=\"page-btn{$active}\">$i</a>";
    }
    return $html . '</div>';
}
