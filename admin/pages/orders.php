<?php
// admin/pages/orders.php — Sipariş Yönetimi
requireAdmin();

$action   = $_GET['action'] ?? 'list';
$id       = intval($_GET['id'] ?? 0);
$dealerId = intval($_GET['dealer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';
    $oid = intval($_POST['order_id'] ?? 0);

    // Sipariş Onayla
    if ($act === 'approve') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        if ($order && $order['status'] === 'bekliyor') {
            dbExec("UPDATE b2b_orders SET status='onaylandi', approved_by=?, approved_at=NOW() WHERE id=?", [adminId(), $oid]);
            // Stok düş
            $items = dbRows("SELECT * FROM b2b_order_items WHERE order_id=?", [$oid]);
            foreach ($items as $it) {
                stockUpdate($it['product_id'], -$it['quantity'], 'siparis', $oid);
            }
            // Cari kayıt
            $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$order['dealer_id']]);
            $dueDate = date('Y-m-d', strtotime("+{$dealer['payment_term_days']} days"));
            ledgerAdd($order['dealer_id'], 'borc', $order['grand_total'], "Sipariş: {$order['order_number']}", $dueDate, $oid, 'order');
            // Paraşüt fatura
            try { parasut()->createInvoice($oid); } catch (Exception $e) {}
            // Bildirim
            notifyDealer($order['dealer_id'], 'Siparişiniz Onaylandı', "#{$order['order_number']} numaralı siparişiniz onaylandı.", 'order', $oid);
            auditLog('order_approved', 'b2b_orders', $oid, []);
            $success = 'Sipariş onaylandı.';
        }
        $action = 'detail'; $id = $oid;
    }

    // Sipariş Reddet / İptal
    if ($act === 'cancel') {
        $order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [$oid]);
        $reason = trim($_POST['cancel_reason'] ?? '');
        if ($order && in_array($order['status'], ['bekliyor','onaylandi'])) {
            dbExec("UPDATE b2b_orders SET status='iptal', cancel_reason=? WHERE id=?", [$reason, $oid]);
            // Stok iade (onaylandıysa)
            if ($order['status'] === 'onaylandi') {
                $items = dbRows("SELECT * FROM b2b_order_items WHERE order_id=?", [$oid]);
                foreach ($items as $it) { stockUpdate($it['product_id'], $it['quantity'], 'iade', $oid); }
                // Cari iptal
                dbExec("UPDATE b2b_ledger SET is_closed=1 WHERE ref_id=? AND ref_type='order'", [$oid]);
            }
            notifyDealer($order['dealer_id'], 'Sipariş İptal Edildi', "#{$order['order_number']} numaralı siparişiniz iptal edildi." . ($reason ? " Neden: $reason" : ''), 'order', $oid);
            $success = 'Sipariş iptal edildi.';
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
            notifyDealer($order['dealer_id'], 'Sipariş Durumu Güncellendi', "#{$order['order_number']}: " . orderStatusLabel($status, false), 'order', $oid);
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
}

// Detay yükle
$order = null;
if ($action === 'detail' && $id) {
    $order = dbRow(
        "SELECT o.*, d.company_name, d.contact_name, d.email AS dealer_email, d.phone AS dealer_phone,
                d.address, d.city, d.tax_number, d.payment_term_days
         FROM b2b_orders o JOIN b2b_dealers d ON d.id=o.dealer_id WHERE o.id=?",
        [$id]
    );
    if (!$order) { $action = 'list'; $id = 0; }
}
if ($order) {
    $orderItems = dbRows(
        "SELECT oi.*, p.sku, p.unit FROM b2b_order_items oi LEFT JOIN b2b_products p ON p.id=oi.product_id WHERE oi.order_id=?",
        [$id]
    );
}

// Liste
if ($action === 'list') {
    $search  = trim($_GET['q'] ?? '');
    $status  = $_GET['status'] ?? '';
    $perPage = 25;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['1=1']; $params = [];
    if ($search) {
        $where[] = '(o.order_number LIKE ? OR d.company_name LIKE ?)';
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

    // Bekleyen sipariş sayısı
    $pendingCount = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE status='bekliyor'", []);
}

$statuses = ['bekliyor','onaylandi','hazirlaniyor','kargoda','teslim_edildi','iptal','iade'];
?>

<?php if ($action === 'list'): ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Siparişler <?php if ($pendingCount): ?><span class="badge badge-yellow"><?= $pendingCount ?> bekliyor</span><?php endif; ?></h1>
        <p class="page-sub">Toplam <?= $total ?> sipariş</p>
    </div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<!-- Hızlı durum filtreleri -->
<div class="filter-bar card mb-4">
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="orders">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Sipariş no veya firma…" class="form-control" style="max-width:240px">
        <select name="status" class="form-control" style="max-width:180px">
            <option value="">Tüm Durumlar</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filtrele</button>
        <a href="?page=orders" class="btn btn-ghost">Temizle</a>
    </form>
</div>

<div class="card">
<table class="table">
    <thead><tr><th>Sipariş No</th><th>Bayi</th><th>Tarih</th><th>Tutar</th><th>Durum</th><th>Ödeme</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
    <tr class="<?= $o['status']==='bekliyor'?'row-highlight':'' ?>">
        <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="font-medium"><?= h($o['order_number']) ?></a></td>
        <td><?= h($o['company_name']) ?></td>
        <td><?= fmtDate($o['created_at']) ?></td>
        <td class="font-medium"><?= money($o['grand_total']) ?></td>
        <td><?= orderStatusLabel($o['status']) ?></td>
        <td><span class="badge badge-<?= $o['payment_status']==='odendi'?'green':($o['payment_status']==='bekliyor'?'yellow':'blue') ?>"><?= h($o['payment_status']) ?></span></td>
        <td class="text-right"><a href="?page=orders&action=detail&id=<?= $o['id'] ?>" class="btn btn-xs btn-secondary">Detay →</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?><tr><td colspan="7" class="text-center text-muted py-8">Sipariş bulunamadı.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= $pager ?>

<?php elseif ($action === 'detail' && $order): ?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($order['order_number']) ?></h1>
        <p class="page-sub"><?= h($order['company_name']) ?> — <?= fmtDate($order['created_at']) ?></p>
    </div>
    <div class="btn-group">
        <a href="?page=orders" class="btn btn-ghost">← Geri</a>
        <?php if ($order['status'] === 'bekliyor'): ?>
        <button class="btn btn-success" onclick="openModal('modal-approve')">✓ Onayla</button>
        <button class="btn btn-danger" onclick="openModal('modal-cancel')">✕ İptal</button>
        <?php elseif ($order['status'] === 'onaylandi'): ?>
        <button class="btn btn-secondary" onclick="openModal('modal-status')">Durumu Güncelle</button>
        <button class="btn btn-danger" onclick="openModal('modal-cancel')">✕ İptal</button>
        <?php elseif (in_array($order['status'], ['hazirlaniyor'])): ?>
        <button class="btn btn-secondary" onclick="openModal('modal-status')">Durumu Güncelle</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="stat-card"><div class="stat-label">Durum</div><div class="stat-value"><?= orderStatusLabel($order['status']) ?></div></div>
    <div class="stat-card"><div class="stat-label">Toplam</div><div class="stat-value"><?= money($order['grand_total']) ?></div></div>
    <div class="stat-card"><div class="stat-label">Vade Tarihi</div><div class="stat-value"><?= $order['due_date'] ? fmtDate($order['due_date']) : '—' ?></div></div>
</div>

<div class="grid grid-cols-2 gap-6 mb-6">
    <!-- Bayi Bilgisi -->
    <div class="card">
        <div class="card-header"><h3>Bayi Bilgisi</h3></div>
        <div class="card-body">
            <dl class="info-list">
                <dt>Firma</dt><dd><?= h($order['company_name']) ?></dd>
                <dt>Bayi</dt><dd><?= h($order['dealer_company'] ?? $order['dealer_id']) ?></dd>
                <dt>E-posta</dt><dd><?= h($order['dealer_email']) ?></dd>
                <dt>Telefon</dt><dd><?= h($order['dealer_phone']) ?></dd>
                <dt>Adres</dt><dd><?= h($order['address']) ?>, <?= h($order['city']) ?></dd>
                <dt>Vergi No</dt><dd><?= h($order['tax_number']) ?></dd>
            </dl>
        </div>
    </div>
    <!-- Sipariş Bilgisi -->
    <div class="card">
        <div class="card-header"><h3>Sipariş Bilgisi</h3></div>
        <div class="card-body">
            <dl class="info-list">
                <dt>Sipariş Notu</dt><dd><?= h($order['notes']) ?: '—' ?></dd>
                <dt>Paraşüt Fatura</dt><dd><?= h($order['parasut_invoice_id']) ?: 'Oluşturulmadı' ?></dd>
                <?php if ($order['cargo_company']): ?>
                <dt>Kargo</dt><dd><?= h($order['cargo_company']) ?></dd>
                <dt>Takip No</dt><dd><?= h($order['tracking_number']) ?></dd>
                <?php endif; ?>
                <?php if ($order['cancel_reason']): ?>
                <dt>İptal Nedeni</dt><dd class="text-danger"><?= h($order['cancel_reason']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>
</div>

<!-- Ürünler -->
<div class="card mb-6">
    <div class="card-header"><h3>Sipariş Kalemleri</h3></div>
    <table class="table">
        <thead><tr><th>Ürün</th><th>SKU</th><th>Birim Fiyat</th><th>Miktar</th><th>KDV</th><th>Toplam</th></tr></thead>
        <tbody>
        <?php $subtotal=0; $tax=0; foreach ($orderItems as $it):
            $lineTotal = $it['unit_price'] * $it['quantity'];
            $lineTax   = $lineTotal * ($it['tax_rate']/100);
            $subtotal += $lineTotal; $tax += $lineTax;
        ?>
        <tr>
            <td><?= h($it['product_name']) ?></td>
            <td class="mono text-sm"><?= h($it['sku']) ?></td>
            <td><?= money($it['unit_price']) ?></td>
            <td><?= $it['quantity'] ?> <?= h($it['unit']) ?></td>
            <td>%<?= $it['tax_rate'] ?></td>
            <td class="font-medium"><?= money($lineTotal) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="table-footer">
            <td colspan="5" class="text-right">Ara Toplam</td><td><?= money($subtotal) ?></td>
        </tr>
        <tr class="table-footer">
            <td colspan="5" class="text-right">KDV</td><td><?= money($tax) ?></td>
        </tr>
        <tr class="table-footer font-bold">
            <td colspan="5" class="text-right">Genel Toplam</td><td><?= money($order['grand_total']) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<!-- Admin Notu -->
<div class="card mb-6">
    <div class="card-header"><h3>Admin Notu</h3></div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="add_note">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <div class="form-group">
                <textarea name="admin_note" class="form-control" rows="3" placeholder="Dahili not (bayi göremez)…"><?= h($order['admin_note']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-secondary">Notu Kaydet</button>
        </form>
    </div>
</div>

<!-- Modal: Onayla -->
<div id="modal-approve" class="modal-overlay" style="display:none">
<div class="modal">
    <div class="modal-header"><h3>Siparişi Onayla</h3></div>
    <div class="modal-body">
        <p><?= h($order['order_number']) ?> numaralı siparişi onaylamak istediğinize emin misiniz?</p>
        <p class="text-sm text-muted mt-2">Onaylanınca stok düşülecek ve bayi bilgilendirilecektir.</p>
    </div>
    <div class="modal-footer">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="approve">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <button class="btn btn-ghost" type="button" onclick="closeModal('modal-approve')">Vazgeç</button>
            <button class="btn btn-success" type="submit">Evet, Onayla</button>
        </form>
    </div>
</div>
</div>

<!-- Modal: İptal -->
<div id="modal-cancel" class="modal-overlay" style="display:none">
<div class="modal">
    <div class="modal-header"><h3>Siparişi İptal Et</h3></div>
    <div class="modal-body">
        <form method="post" id="form-cancel">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="cancel">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <div class="form-group">
                <label>İptal Nedeni (isteğe bağlı)</label>
                <textarea name="cancel_reason" class="form-control" rows="3"></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-cancel')">Vazgeç</button>
        <button class="btn btn-danger" type="submit" form="form-cancel">İptal Et</button>
    </div>
</div>
</div>

<!-- Modal: Durum Güncelle -->
<div id="modal-status" class="modal-overlay" style="display:none">
<div class="modal">
    <div class="modal-header"><h3>Durum Güncelle</h3></div>
    <div class="modal-body">
        <form method="post" id="form-status">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="update_status">
            <input type="hidden" name="order_id" value="<?= $id ?>">
            <div class="form-group">
                <label>Yeni Durum</label>
                <select name="new_status" class="form-control">
                    <option value="hazirlaniyor">Hazırlanıyor</option>
                    <option value="kargoda">Kargoya Verildi</option>
                    <option value="teslim_edildi">Teslim Edildi</option>
                </select>
            </div>
            <div class="form-group">
                <label>Kargo Firması</label>
                <input type="text" name="cargo_company" class="form-control" value="<?= h($order['cargo_company']) ?>" placeholder="MNG, Aras, Yurtiçi…">
            </div>
            <div class="form-group">
                <label>Takip Numarası</label>
                <input type="text" name="tracking_number" class="form-control" value="<?= h($order['tracking_number']) ?>">
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-status')">Vazgeç</button>
        <button class="btn btn-primary" type="submit" form="form-status">Güncelle</button>
    </div>
</div>
</div>

<?php endif; ?>
