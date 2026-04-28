<?php
// admin/pages/orders.php — Sipariş Yönetimi
requireAdmin();

$action   = $_GET['action'] ?? 'list';
$id       = intval($_GET['id'] ?? 0);
$dealerId = intval($_GET['dealer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    // ── Sipariş Sil ───────────────────────────────────────────
    if ($act === 'delete_order') {
        $oid = intval($_POST['order_id'] ?? 0);
        $ord = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($ord) {
            // Stok iade — sipariş silinmeden önce kalemlerden iade et
            // (cart.php her sipariş statüsünde stok düşürdüğü için iadeyi
            // statüye bağlamıyoruz; iptal/iade siparişlerde zaten iade
            // edilmişse bir daha iade edilmesin diye check ediyoruz).
            if (!in_array($ord['status'] ?? '', ['iptal', 'iade'], true)) {
                $items = dbRows("SELECT product_id, qty FROM b2b_order_items WHERE order_id=?", [$oid]);
                foreach ($items as $it) {
                    $qty = (int)($it['qty'] ?? 0);
                    if ($qty > 0 && $it['product_id']) {
                        dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?",
                               [$qty, $it['product_id']]);
                    }
                }
            }
            dbExec("DELETE FROM b2b_order_items WHERE order_id=?", [$oid]);
            dbExec("DELETE FROM b2b_orders WHERE id=?", [$oid]);
            // Ledger kayıtlarını da sil
            dbExec("DELETE FROM b2b_ledger WHERE reference_type='order' AND reference_id=?", [$oid]);
            auditLog('order_deleted', 'b2b_orders', $oid, ['order_no' => $ord['order_no']]);
            $_SESSION['flash_admin'] = ['type' => 'success', 'msg' => "#{$ord['order_no']} siparişi, stoklar geri yüklendi ve cari kaydı silindi."];
        }
        redirect('?page=orders');
    }

    // ── İptal onayla ─────────────────────────────────────────
    if ($act === 'approve_cancel') {
        $oid = intval($_POST['order_id'] ?? 0);
        $ord = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($ord && $ord['cancel_requested']) {
            // Cari ledger kapat
            dbExec("UPDATE b2b_ledger SET is_closed=1 WHERE reference_id=? AND reference_type='order'", [$oid]);
            // Stok geri yükle — cart.php tüm sipariş statülerinde stok
            // düşürüyor, dolayısıyla iptal'de de her zaman iade etmeliyiz.
            // Mevcut statü 'iptal' veya 'iade' ise zaten iade edilmiş, atla.
            if (!in_array($ord['status'] ?? '', ['iptal', 'iade'], true)) {
                $items = dbRows("SELECT product_id, qty FROM b2b_order_items WHERE order_id=?", [$oid]);
                foreach ($items as $it) {
                    $qty = (int)($it['qty'] ?? 0);
                    if ($qty > 0 && $it['product_id']) {
                        dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?",
                               [$qty, $it['product_id']]);
                    }
                }
            }
            // Sipariş güncelle
            dbExec("UPDATE b2b_orders SET status='iptal', cancel_requested=0,
                    cancel_reviewed_by=?, cancel_reviewed_at=NOW() WHERE id=?",
                   [adminId(), $oid]);
            auditLog('order_cancelled', 'b2b_orders', $oid, ['by'=>'admin']);
            $success = 'Sipariş iptal edildi, stoklar geri yüklendi.';
        }
        $action = 'detail'; $id = $oid;
    }

    // ── İptal reddet ─────────────────────────────────────────
    if ($act === 'reject_cancel') {
        $oid = intval($_POST['order_id'] ?? 0);
        dbExec("UPDATE b2b_orders SET cancel_requested=0,
                cancel_reviewed_by=?, cancel_reviewed_at=NOW() WHERE id=?",
               [adminId(), $oid]);
        auditLog('cancel_rejected', 'b2b_orders', $oid, []);
        $success = 'İptal talebi reddedildi.';
        $action = 'detail'; $id = $oid;
    }
    $oid = intval($_POST['order_id'] ?? 0);

    // Sipariş Onayla
    if ($act === 'approve') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($order && $order['status'] === 'bekliyor') {
            dbExec("UPDATE b2b_orders SET status='onaylandi', approved_by=?, approved_at=NOW() WHERE id=?", [adminId(), $oid]);
            // Stok düş
            $items = dbRows("SELECT * FROM b2b_order_items WHERE order_id=?", [$oid]);
            foreach ($items as $it) {
                stockUpdate($it['product_id'], -$it['qty'], 'siparis', 'order', $oid);
            }
            // Cari kayıt
            $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$order['dealer_id']]);
            $dueDate = date('Y-m-d', strtotime("+{$dealer['payment_term_days']} days"));
            ledgerAdd($order['dealer_id'], 'borc', (float)$order['grand_total'], "Sipariş: {$order['order_no']}", 'order', $oid, $dueDate);
            // Paraşüt fatura
            try { parasut()->createInvoice($oid); } catch (Exception $e) {}
            // Bildirim
            notifyDealer($order['dealer_id'], 'order', 'Siparişiniz Onaylandı', "#{$order['order_no']} numaralı siparişiniz onaylandı.", '?page=orders&action=detail&id='.$oid);
            auditLog('order_approved', 'b2b_orders', $oid, []);
            $success = 'Sipariş onaylandı.';
        }
        $action = 'detail'; $id = $oid;
    }

    // Sipariş Reddet / İptal
    if ($act === 'cancel') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        $reason = trim($_POST['cancel_reason'] ?? '');
        if ($order && in_array($order['status'], ['bekliyor','onaylandi','hazirlaniyor','kargoda'])) {
            // Cari ledger — her durumda kapat
            dbExec("UPDATE b2b_ledger SET is_closed=1 WHERE reference_id=? AND reference_type='order'", [$oid]);
            // Stok iade — cart.php tüm statülerde stok düşürdüğü için her
            // statüde iade etmeliyiz. Zaten 'iptal'/'iade' olanları hariç tut.
            if (!in_array($order['status'] ?? '', ['iptal','iade'], true)) {
                $items = dbRows("SELECT product_id, qty FROM b2b_order_items WHERE order_id=?", [$oid]);
                foreach ($items as $it) {
                    $_qty = (int)($it['qty'] ?? 0);
                    if ($_qty > 0 && $it['product_id']) {
                        dbExec("UPDATE b2b_products SET stock=stock+? WHERE id=?", [$_qty, $it['product_id']]);
                    }
                }
            }
            dbExec("UPDATE b2b_orders SET status='iptal', cancel_reason=? WHERE id=?", [$reason, $oid]);
            notifyDealer($order['dealer_id'], 'order', 'Sipariş İptal Edildi', "#{$order['order_no']} numaralı siparişiniz iptal edildi." . ($reason ? " Neden: $reason" : ''), '?page=orders&action=detail&id='.$oid);
            $success = 'Sipariş iptal edildi, stoklar geri yüklendi.';
        }
        $action = 'detail'; $id = $oid;
    }

    // Kargo / Durum güncelle
    if ($act === 'update_status') {
        $status = $_POST['new_status'];
        $cargo  = trim($_POST['cargo_company'] ?? '');
        $track  = trim($_POST['tracking_number'] ?? '');
        $allowed = ['hazirlaniyor','kargoda','teslim_edildi'];
        if (in_array($status, $allowed)) {
            dbExec("UPDATE b2b_orders SET status=?, cargo_company=?, tracking_number=? WHERE id=?", [$status, $cargo, $track, $oid]);
            if ($status === 'teslim_edildi') {
                dbExec("UPDATE b2b_orders SET delivered_at=NOW() WHERE id=?", [$oid]);
            }
            $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
            notifyDealer($order['dealer_id'], 'order', 'Sipariş Durumu Güncellendi', "#{$order['order_no']}: " . orderStatusLabel($status, false), '?page=orders&action=detail&id='.$oid);
            $success = 'Durum güncellendi.';
        }
        $action = 'detail'; $id = $oid;
    }

    // Not ekle
    if ($act === 'add_note') {
        $note = trim($_POST['admin_note'] ?? '');
        if ($note) {
            dbExec("UPDATE b2b_orders SET admin_note=? WHERE id=?", [$note, $oid]);
            $success = 'Not kaydedildi.';
        }
        $action = 'detail'; $id = $oid;
    }

    // ── Arşivle / Arşivden Çıkar ──────────────────────────────
    if ($act === 'archive') {
        $oids = array_map('intval', (array)($_POST['order_ids'] ?? [$oid]));
        foreach ($oids as $aid) {
            $o = dbRow("SELECT status FROM b2b_orders WHERE id=?", [$aid]);
            if ($o && in_array($o['status'], ['iptal','teslim_edildi','iade'])) {
                dbExec("UPDATE b2b_orders SET is_archived=1, archived_by=?, archived_at=NOW() WHERE id=?",
                    [adminId(), $aid]);
                auditLog('order_archived', 'b2b_orders', $aid);
            }
        }
        $success = count($oids) === 1 ? 'Sipariş arşivlendi.' : count($oids).' sipariş arşivlendi.';
        $action = 'list';
    }

    if ($act === 'unarchive') {
        $oid = intval($_POST['order_id'] ?? 0);
        dbExec("UPDATE b2b_orders SET is_archived=0, archived_by=NULL, archived_at=NULL WHERE id=?", [$oid]);
        auditLog('order_unarchived', 'b2b_orders', $oid);
        $success = 'Sipariş arşivden çıkarıldı.';
        $action = 'archive_list';
    }

    // ── İptal edilen siparişi yeniden işleme al ───────────────
    if ($act === 'reactivate') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($order && $order['status'] === 'iptal') {
            // Stokta yeterlilil kontrolü
            $items    = dbRows("SELECT * FROM b2b_order_items WHERE order_id=?", [$oid]);
            $stockOk  = true;
            $stockMsg = [];
            foreach ($items as $it) {
                $avail = (int)dbVal("SELECT stock FROM b2b_products WHERE id=?", [$it['product_id']]);
                if ($avail < $it['qty']) {
                    $stockOk = false;
                    $stockMsg[] = "{$it['product_name']}: mevcut {$avail}, gerekli {$it['qty']}";
                }
            }
            if (!$stockOk) {
                $error = 'Yetersiz stok: ' . implode('; ', $stockMsg);
            } else {
                // Siparişi bekliyor'a al, iptal bilgilerini temizle
                dbExec("UPDATE b2b_orders SET status='bekliyor', cancel_reason=NULL,
                        cancel_requested=0, cancel_requested_at=NULL,
                        cancel_reviewed_by=NULL, cancel_reviewed_at=NULL
                        WHERE id=?", [$oid]);
                // Eğer daha önce cari kaydı kapatıldıysa yeniden aç
                dbExec("UPDATE b2b_ledger SET is_closed=0 WHERE reference_id=? AND reference_type='order'", [$oid]);
                notifyDealer($order['dealer_id'], 'order', 'Siparişiniz Yeniden İşleme Alındı',
                    "#{$order['order_no']} numaralı iptal edilmiş siparişiniz yeniden işleme alındı.",
                    '?page=orders&action=detail&id='.$oid);
                auditLog('order_reactivated', 'b2b_orders', $oid, ['by'=>adminId()]);
                $success = 'Sipariş yeniden "Bekliyor" durumuna alındı.';
            }
        }
        $action = 'detail'; $id = $oid;
    }
}

// Detay yükle
$order = null;
if ($action === 'detail' && $id) {
    $order = dbRow(
        "SELECT o.*, d.company_name,
                COALESCE(NULLIF(d.company_name,''), CONCAT(TRIM(d.first_name),' ',TRIM(d.last_name))) AS contact_name,
                d.email AS dealer_email, d.phone AS dealer_phone,
                d.address, d.city, d.tax_number, d.payment_term_days
         FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE o.id=?",
        [$id]
    );
    if (!$order) { $action = 'list'; $id = 0; }
}
$orderItems = [];
if ($order) {
    $orderItems = dbRows(
        "SELECT oi.id, oi.order_id, oi.product_id, oi.product_name,
                oi.product_sku AS sku, oi.qty, oi.unit_price,
                oi.vat_rate, oi.discount_percent, oi.line_total,
                COALESCE(p.unit, 'adet') AS unit
         FROM b2b_order_items oi
         LEFT JOIN b2b_products p ON p.id=oi.product_id
         WHERE oi.order_id=?",
        [$order['id']]
    );
}

// Liste
if ($action === 'list') {
    $search  = trim($_GET['q'] ?? '');
    $status  = $_GET['status'] ?? '';
    $perPage = 25;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['1=1', 'o.is_archived=0']; $params = [];
    if ($search) {
        $where[] = '(o.order_no LIKE ? OR d.company_name LIKE ?)';
        $s = "%$search%"; $params[] = $s; $params[] = $s;
    }
    if ($status)   { $where[] = 'o.status=?'; $params[] = $status; }
    if ($dealerId) { $where[] = 'o.dealer_id=?'; $params[] = $dealerId; }

    $w = implode(' AND ', $where);
    $total  = dbVal("SELECT COUNT(*) FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w", $params);
    $orders = dbRows(
        "SELECT o.*, d.company_name FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset",
        $params
    );
    $pager  = pagination($total, $perPage, $page, "?page=orders&q=".urlencode($search)."&status=$status&dealer_id=$dealerId&p=");
    $pendingCount  = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE status='bekliyor' AND is_archived=0", []);
    $archiveCount  = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE is_archived=1", []);
    $archivableCount = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE is_archived=0 AND status IN('iptal','teslim_edildi','iade')", []);
}

if ($action === 'archive_list') {
    $page   = max(1, intval($_GET['p'] ?? 1));
    $perPage = 25;
    $offset  = ($page-1)*$perPage;
    $search  = trim($_GET['q'] ?? '');
    $where = ['o.is_archived=1']; $params = [];
    if ($search) { $where[]='(o.order_no LIKE ? OR d.company_name LIKE ?)'; $s="%$search%"; $params[]=$s; $params[]=$s; }
    $w = implode(' AND ',$where);
    $total        = dbVal("SELECT COUNT(*) FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w",$params);
    $archivedOrders = dbRows("SELECT o.*,d.company_name FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE $w ORDER BY o.archived_at DESC LIMIT $perPage OFFSET $offset",$params);
    $pager = pagination($total,$perPage,$page,"?page=orders&action=archive_list&q=".urlencode($search)."&p=");
}

$statuses = ['bekliyor','onaylandi','hazirlaniyor','kargoda','teslim_edildi','iptal','iade'];
?>

<?php if ($action === 'list'): ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Siparişler<?php if ($pendingCount): ?> <span class="badge badge-yellow"><?= $pendingCount ?> bekliyor</span><?php endif; ?></h1>
    <p class="page-sub">Toplam <?= $total ?? 0 ?> sipariş</p>
  </div>
  <a href="?page=orders&action=archive_list" class="btn btn-ghost">
    🗄 Arşiv<?php if ($archiveCount): ?> <span class="badge badge-gray"><?= $archiveCount ?></span><?php endif; ?>
  </a>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<!-- Filtre -->
<div class="card" style="padding:12px 16px;margin-bottom:16px">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="page" value="orders">
    <input type="text" name="q" value="<?= h($search ?? '') ?>" class="form-control" placeholder="Sipariş no veya bayi..." style="flex:1;min-width:180px;max-width:280px">
    <select name="status" class="form-control" style="min-width:140px" onchange="this.form.submit()">
      <option value="">Tüm Durumlar</option>
      <?php foreach (['bekliyor'=>'Bekleyen','onaylandi'=>'Onaylanan','hazirlaniyor'=>'Hazırlanan','kargoda'=>'Kargoda','teslim_edildi'=>'Teslim','iptal'=>'İptal'] as $v=>$l): ?>
      <option value="<?= $v ?>" <?= ($status??'')===$v?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary">Ara</button>
    <?php if (!empty($search) || !empty($status)): ?><a href="?page=orders" class="btn btn-ghost">Temizle</a><?php endif; ?>
  </form>
</div>

<!-- Tablo -->
<div class="card">
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Sipariş No</th><th>Bayi</th><th>Tarih</th><th style="text-align:right">Tutar</th><th>Durum</th><th>Ödeme</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
  <tr style="<?= !empty($o['cancel_requested'])?'background:rgba(245,158,11,.05)':'' ?>">
    <td class="fw-600"><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_no']) ?></a></td>
    <td><?= h($o['company_name']) ?></td>
    <td style="font-size:12px;color:var(--text-muted)"><?= fmtDate($o['created_at']) ?></td>
    <td style="text-align:right;font-weight:600"><?= money($o['grand_total']) ?></td>
    <td>
      <?= orderStatusLabel($o['status']) ?>
      <?php if (!empty($o['cancel_requested'])): ?>
      <div style="font-size:10px;background:#fffbeb;color:#d97706;border:1px solid #fed7aa;border-radius:4px;padding:1px 6px;margin-top:3px;width:fit-content">⏳ İptal Talebi</div>
      <?php endif; ?>
    </td>
    <td>
      <?php $ps = $o['payment_status'] ?? 'odenmedi';
      $pstyle = match($ps) { 'odendi'=>'success', 'kismi_odeme'=>'warning', default=>'neutral' };
      $plabel = match($ps) { 'odendi'=>'Ödendi', 'kismi_odeme'=>'Kısmen', default=>'Bekliyor' };
      ?>
      <span class="badge badge-<?= $pstyle ?>"><?= $plabel ?></span>
    </td>
    <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">Detay →</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($orders)): ?><tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">Sipariş bulunamadı.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</div>
<?php if (!empty($pager)): ?><div style="margin-top:16px"><?= $pager ?></div><?php endif; ?>

<?php elseif ($action === 'archive_list'): ?>
<div class="page-header">
  <div><h1 class="page-title">Arşiv</h1></div>
  <a href="?page=orders" class="btn btn-ghost">← Aktif Siparişler</a>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<div class="card">
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Sipariş No</th><th>Bayi</th><th>Tarih</th><th style="text-align:right">Tutar</th><th>Durum</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($archivedOrders ?? [] as $o): ?>
  <tr>
    <td class="fw-600"><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_no']) ?></a></td>
    <td><?= h($o['company_name']) ?></td>
    <td style="font-size:12px;color:var(--text-muted)"><?= fmtDate($o['created_at']) ?></td>
    <td style="text-align:right;font-weight:600"><?= money($o['grand_total']) ?></td>
    <td><?= orderStatusLabel($o['status']) ?></td>
    <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">Detay →</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($archivedOrders)): ?><tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">Arşiv boş.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</div>

<?php elseif ($action === 'detail' && $order): ?>

<?php if (!empty($success)): ?>
<?php $isApprove = str_contains($success, 'onaylandı'); ?>
<div style="background:<?= $isApprove ? 'linear-gradient(135deg,#f0fdf4,#dcfce7)' : 'linear-gradient(135deg,#eff6ff,#dbeafe)' ?>;border:1px solid <?= $isApprove ? '#86efac' : '#93c5fd' ?>;border-radius:10px;padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px">
  <span style="font-size:28px"><?= $isApprove ? '✅' : 'ℹ️' ?></span>
  <div style="flex:1;font-size:14px;font-weight:600;color:<?= $isApprove ? '#15803d' : '#1d4ed8' ?>"><?= h($success) ?></div>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger" style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>

<!-- Sipariş Detayı Başlık -->
<div class="page-header">
  <div>
    <h1 class="page-title"><?= h($order['order_no']) ?></h1>
    <p class="page-sub"><?= h($order['company_name']) ?> — <?= fmtDate($order['created_at']) ?></p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?page=orders" class="btn btn-ghost">← Geri</a>
    <?php if ($order['status'] === 'bekliyor'): ?>
    <button class="btn btn-success" onclick="openModal('modal-approve')">✓ Onayla</button>
    <button class="btn btn-danger" onclick="openModal('modal-cancel')">✕ İptal</button>
    <?php elseif (in_array($order['status'], ['onaylandi','hazirlaniyor'])): ?>
    <button class="btn btn-secondary" onclick="openModal('modal-status')">Durumu Güncelle</button>
    <button class="btn btn-danger" onclick="openModal('modal-cancel')">✕ İptal</button>
    <?php elseif ($order['status'] === 'iptal'): ?>
    <button class="btn btn-warning" onclick="openModal('modal-reactivate')" style="background:#f59e0b;border-color:#f59e0b;color:#fff">🔄 Yeniden İşleme Al</button>
    <?php endif; ?>
    <button class="btn btn-danger" onclick="openModal('modal-delete-order')">🗑 Sil</button>
  </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success" style="margin-bottom:16px"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"  style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>

<!-- İptal Talebi Paneli -->
<?php if (!empty($order['cancel_requested']) && $order['status'] !== 'iptal'): ?>
<div style="background:#fffbeb;border:2px solid #f59e0b;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
  <div>
    <div style="font-weight:700;font-size:15px;color:#92400e;margin-bottom:6px">⚠️ Bayi Sipariş İptali Talep Etti</div>
    <div style="font-size:13px;color:#78350f"><strong>Sebep:</strong> <?= h($order['cancel_reason'] ?? '—') ?></div>
    <?php if ($order['cancel_requested_at'] ?? ''): ?>
    <div style="font-size:12px;color:#a16207;margin-top:4px">Talep tarihi: <?= date('d.m.Y H:i', strtotime($order['cancel_requested_at'])) ?></div>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-shrink:0">
    <form method="post"><<?= csrfField() ?><input type="hidden" name="form_action" value="approve_cancel"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-danger" onclick="return confirm('İptal edilecek, stoklar geri yüklenecek. Onaylıyor musunuz?')">✓ İptali Onayla</button>
    </form>
    <form method="post"><?= csrfField() ?><input type="hidden" name="form_action" value="reject_cancel"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-secondary">✗ Reddet</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Özet -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Durum</div><div class="stat-value" style="font-size:16px"><?= orderStatusLabel($order['status']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Toplam Tutar</div><div class="stat-value"><?= money($order['grand_total']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Vade Tarihi</div><div class="stat-value" style="font-size:16px"><?= $order['due_date'] ? fmtDate($order['due_date']) : '—' ?></div></div>
</div>

<!-- 2 kolon -->
<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start">

<!-- Sol -->
<div>
  <!-- Bayi + Sipariş Bilgisi -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Bayi Bilgisi</h3></div>
      <div class="card-body" style="font-size:13px">
        <?php $d = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$order['dealer_id']]); ?>
        <div class="fw-600"><?= h($d['company_name'] ?? '') ?></div>
        <div style="color:var(--text-muted);margin-top:4px"><?= h($d['email'] ?? '') ?></div>
        <div><?= h($d['phone'] ?? '') ?></div>
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted)"><?= h(($d['address']??'').', '.($d['city']??'')) ?></div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Sipariş Bilgisi</h3></div>
      <div class="card-body" style="font-size:13px">
        <div style="margin-bottom:6px"><span style="color:var(--text-muted)">No:</span> <strong><?= h($order['order_no']) ?></strong></div>
        <div style="margin-bottom:6px"><span style="color:var(--text-muted)">Ödeme:</span> <?= $order['payment_method']==='acik_hesap'?'Açık Hesap':h($order['payment_method']??'') ?></div>
        <?php if ($order['notes'] ?? ''): ?><div style="font-size:12px;color:var(--text-muted);margin-top:6px"><?= nl2br(h($order['notes'])) ?></div><?php endif; ?>
        <?php if ($order['cancel_reason'] ?? ''): ?><div style="margin-top:6px;color:var(--danger);font-size:12px">İptal: <?= h($order['cancel_reason']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sipariş Kalemleri -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3 class="card-title">Sipariş Kalemleri</h3></div>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Ürün</th><th style="text-align:center">Adet</th><th style="text-align:right">Birim</th><th style="text-align:right">KDV%</th><th style="text-align:right">Toplam</th></tr></thead>
      <tbody>
      <?php $sub=0; $vat=0; foreach ($orderItems as $it):
        $qty    = (int)($it['qty'] ?? $it['quantity'] ?? 0);
        $unit   = (float)($it['unit_price'] ?? 0);
        $vatr   = (float)($it['vat_rate'] ?? $it['tax_rate'] ?? 0);
        $lineNet= $unit * $qty;
        $lineTax= $lineNet * ($vatr/100);
        $sub += $lineNet; $vat += $lineTax;
      ?>
      <tr>
        <td><div class="fw-600" style="font-size:13px"><?= h($it['product_name']) ?></div><?php if ($it['product_sku']??''): ?><div style="font-size:11px;color:var(--text-muted)"><?= h($it['product_sku']) ?></div><?php endif; ?></td>
        <td style="text-align:center;font-weight:600"><?= $qty ?></td>
        <td style="text-align:right;font-size:13px"><?= money($unit) ?></td>
        <td style="text-align:right;font-size:12px;color:var(--text-muted)">%<?= (int)$vatr ?></td>
        <td style="text-align:right;font-weight:700"><?= money($lineNet+$lineTax) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--bg)"><td colspan="4" style="text-align:right;padding:8px 16px;font-size:13px;color:var(--text-2)">Ara Toplam</td><td style="text-align:right;padding:8px 16px"><?= money($sub) ?></td></tr>
        <tr style="background:var(--bg)"><td colspan="4" style="text-align:right;padding:6px 16px;font-size:13px;color:var(--text-2)">KDV</td><td style="text-align:right;padding:6px 16px;font-size:13px"><?= money($vat) ?></td></tr>
        <tr style="background:var(--bg);border-top:2px solid var(--border)"><td colspan="4" style="text-align:right;font-weight:700;font-size:15px;padding:12px 16px">Genel Toplam</td><td style="text-align:right;font-weight:800;font-size:16px;color:var(--red);padding:12px 16px"><?= money((float)$order['grand_total']) ?></td></tr>
      </tfoot>
    </table>
    </div>
  </div>

  <!-- Admin Notu -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Admin Notu</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="add_note">
        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
        <textarea name="admin_note" class="form-control" rows="3" placeholder="Not ekle..."><?= h($order['admin_note'] ?? '') ?></textarea>
        <div style="margin-top:8px"><button class="btn btn-secondary btn-sm">Notu Kaydet</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Sağ: Kargo + Durum -->
<div>
  <?php if (!empty($order['cargo_company'])): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header"><h3 class="card-title">🚚 Kargo</h3></div>
    <div class="card-body" style="font-size:13px">
      <div class="fw-600"><?= h($order['cargo_company']) ?></div>
      <?php if ($order['tracking_number']??''): ?><div style="margin-top:4px;color:var(--text-muted)">Takip: <strong style="color:var(--text)"><?= h($order['tracking_number']) ?></strong></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Ödeme Geçmişi -->
  <?php $pmts = dbRows("SELECT * FROM b2b_payments WHERE order_id=? ORDER BY created_at DESC", [$order['id']]); ?>
  <?php if (!empty($pmts)): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header"><h3 class="card-title">Ödemeler</h3></div>
    <div class="card-body" style="padding:0">
      <?php foreach ($pmts as $pay): $ps=$pay['status']??'bekliyor'; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border)">
        <div><div style="font-size:13px;font-weight:600"><?= money((float)$pay['amount']) ?></div><div style="font-size:11px;color:var(--text-muted)"><?= fmtDate($pay['created_at']) ?></div></div>
        <span class="badge badge-<?= $ps==='onaylandi'?'success':($ps==='reddedildi'?'danger':'warning') ?>"><?= ['onaylandi'=>'Onaylandı','reddedildi'=>'Reddedildi','bekliyor'=>'Bekliyor'][$ps]??$ps ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

</div><!-- /grid -->

<!-- Modaller -->
<div id="modal-approve" class="modal-overlay">
<div class="modal">
  <div class="modal-header">✓ Siparişi Onayla</div>
  <div class="modal-body"><p><strong><?= h($order['order_no']) ?></strong> onaylanacak ve stok düşülecek.</p></div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-approve')">Vazgeç</button>
    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="form_action" value="approve"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-success">✓ Onayla</button>
    </form>
  </div>
</div>
</div>

<div id="modal-cancel" class="modal-overlay">
<div class="modal">
  <div class="modal-header">✕ Siparişi İptal Et</div>
  <div class="modal-body">
    <form method="post" id="form-cancel"><?= csrfField() ?><input type="hidden" name="form_action" value="cancel"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <div class="form-group"><label class="form-label">İptal Sebebi</label><textarea name="cancel_reason" class="form-control" rows="3"></textarea></div>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-cancel')">Vazgeç</button>
    <button class="btn btn-danger" form="form-cancel" type="submit">İptal Et</button>
  </div>
</div>
</div>

<div id="modal-status" class="modal-overlay">
<div class="modal">
  <div class="modal-header">Durumu Güncelle</div>
  <div class="modal-body">
    <form method="post" id="form-status"><?= csrfField() ?><input type="hidden" name="form_action" value="update_status"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <div class="form-group"><label class="form-label">Yeni Durum</label>
        <select name="new_status" class="form-control">
          <?php foreach (['hazirlaniyor'=>'Hazırlanıyor','kargoda'=>'Kargoya Verildi','teslim_edildi'=>'Teslim Edildi'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($order['status']??'')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Kargo Firması</label><input type="text" name="cargo_company" class="form-control" value="<?= h($order['cargo_company']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Takip No</label><input type="text" name="tracking_number" class="form-control" value="<?= h($order['tracking_number']??'') ?>"></div>
    </form>
  </div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-status')">Vazgeç</button>
    <button class="btn btn-primary" form="form-status" type="submit">Güncelle</button>
  </div>
</div>
</div>

<div id="modal-reactivate" class="modal-overlay">
<div class="modal">
  <div class="modal-header">🔄 Yeniden İşleme Al</div>
  <div class="modal-body"><p><?= h($order['order_no']) ?> "Bekliyor" durumuna alınacak.</p></div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-reactivate')">Vazgeç</button>
    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="form_action" value="reactivate"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-primary" style="background:#f59e0b;border-color:#f59e0b">🔄 Yeniden İşleme Al</button>
    </form>
  </div>
</div>
</div>

<div id="modal-delete-order" class="modal-overlay">
<div class="modal">
  <div class="modal-header">🗑 Siparişi Sil</div>
  <div class="modal-body"><p><strong><?= h($order['order_no']) ?></strong> kalıcı olarak silinecek. Bu işlem geri alınamaz.</p></div>
  <div class="modal-footer">
    <button class="btn btn-ghost" onclick="closeModal('modal-delete-order')">Vazgeç</button>
    <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="form_action" value="delete_order"><input type="hidden" name="order_id" value="<?= $order['id'] ?>">
      <button class="btn btn-danger">Evet, Sil</button>
    </form>
  </div>
</div>
</div>

<?php endif; ?>
