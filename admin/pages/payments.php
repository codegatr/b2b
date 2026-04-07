<?php
// admin/pages/payments.php — Tahsilat Yönetimi
requireAdmin();

$action   = $_GET['action'] ?? 'list';
$id       = intval($_GET['id'] ?? 0);
$dealerId = intval($_GET['dealer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';
    $pid = intval($_POST['payment_id'] ?? 0);

    if ($act === 'approve') {
        $p = dbRow("SELECT * FROM b2b_payments WHERE id=?", [$pid]);
        if ($p && $p['status'] === 'bekliyor') {
            dbExec("UPDATE b2b_payments SET status='onaylandi', approved_by=?, approved_at=NOW() WHERE id=?", [adminId(), $pid]);
            // Cari alacak yaz
            ledgerAdd($p['dealer_id'], 'alacak', $p['amount'], "Ödeme onaylandı: " . h($p['payment_method']), null, $pid, 'payment');
            // Sipariş ödeme durumu güncelle
            if ($p['order_id']) {
                $orderBalance = dbVal(
                    "SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0",
                    [$p['dealer_id']]
                );
                if ($orderBalance <= 0) {
                    dbExec("UPDATE b2b_orders SET payment_status='odendi' WHERE id=?", [$p['order_id']]);
                } else {
                    dbExec("UPDATE b2b_orders SET payment_status='kismi_odeme' WHERE id=?", [$p['order_id']]);
                }
            }
            // Paraşüt ödeme
            try { parasut()->createPayment($pid); } catch (Exception $e) {}
            notifyDealer($p['dealer_id'], 'Ödemeniz Onaylandı', money($p['amount']).' tutarındaki ödemeniz sisteme işlendi.', 'payment', $pid);
            auditLog('payment_approved', 'b2b_payments', $pid, ['amount'=>$p['amount']]);
            $success = 'Ödeme onaylandı ve cari hesaba işlendi.';
        }
        $action = 'list';
    }

    if ($act === 'reject') {
        $reason = trim($_POST['reject_reason'] ?? '');
        $p = dbRow("SELECT * FROM b2b_payments WHERE id=?", [$pid]);
        if ($p && $p['status'] === 'bekliyor') {
            dbExec("UPDATE b2b_payments SET status='reddedildi', reject_reason=? WHERE id=?", [$reason, $pid]);
            notifyDealer($p['dealer_id'], 'Ödemeniz Reddedildi', 'Ödemeniz reddedildi.' . ($reason ? " Neden: $reason" : ''), 'payment', $pid);
            $success = 'Ödeme reddedildi.';
        }
        $action = 'list';
    }

    // Manuel tahsilat girişi
    if ($act === 'manual') {
        $did    = intval($_POST['dealer_id']);
        $amount = floatval($_POST['amount']);
        $method = $_POST['payment_method'] ?? 'nakit';
        $note   = trim($_POST['note']);
        $date   = $_POST['payment_date'] ?: date('Y-m-d');
        if ($did > 0 && $amount > 0) {
            $newId = dbInsertRow('b2b_payments', [
                'dealer_id'      => $did,
                'amount'         => $amount,
                'payment_method' => $method,
                'payment_date'   => $date,
                'note'           => $note,
                'status'         => 'onaylandi',
                'approved_by'    => adminId(),
                'approved_at'    => date('Y-m-d H:i:s'),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            ledgerAdd($did, 'alacak', $amount, "Manuel tahsilat: $note", null, $newId, 'payment');
            auditLog('payment_manual', 'b2b_payments', $newId, ['dealer_id'=>$did,'amount'=>$amount]);
            $success = 'Manuel tahsilat kaydedildi.';
        } else { $error = 'Bayi ve tutar zorunludur.'; }
    }
}

// Liste
if ($action === 'list') {
    $status  = $_GET['status'] ?? 'bekliyor';
    $perPage = 25;
    $page    = max(1, intval($_GET['p'] ?? 1));
    $offset  = ($page-1)*$perPage;

    $where = ['1=1']; $params = [];
    if ($status)   { $where[] = 'p.status=?'; $params[] = $status; }
    if ($dealerId) { $where[] = 'p.dealer_id=?'; $params[] = $dealerId; }

    $w = implode(' AND ', $where);
    $total    = dbVal("SELECT COUNT(*) FROM b2b_payments p WHERE $w", $params);
    $payments = dbRows(
        "SELECT p.*, d.company_name FROM b2b_payments p JOIN b2b_dealers d ON d.id=p.dealer_id WHERE $w ORDER BY p.created_at DESC LIMIT $perPage OFFSET $offset",
        $params
    );
    $pager = pagination($total, $perPage, $page, "?page=payments&status=$status&dealer_id=$dealerId&p=");
    $pendingSum = dbVal("SELECT COALESCE(SUM(amount),0) FROM b2b_payments WHERE status='bekliyor'", []);
    $dealers    = dbRows("SELECT id, company_name FROM b2b_dealers WHERE is_active=1 ORDER BY company_name");
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Tahsilat Yönetimi</h1>
        <?php if (!empty($pendingSum) && $pendingSum > 0): ?>
        <p class="page-sub text-warning"><?= money($pendingSum) ?> onay bekliyor</p>
        <?php endif; ?>
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-manual')">＋ Manuel Tahsilat</button>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<!-- Durum filtreleri -->
<?php
$_counts = [
    'bekliyor'    => (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='bekliyor'"),
    'onaylandi'   => (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='onaylandi'"),
    'reddedildi'  => (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='reddedildi'"),
    ''            => (int)dbVal("SELECT COUNT(*) FROM b2b_payments"),
];
$_tabs = ['bekliyor'=>'Bekleyen','onaylandi'=>'Onaylanan','reddedildi'=>'Reddedilen',''=>'Tümü'];
?>
<div class="tab-bar">
    <?php foreach ($_tabs as $val=>$label): ?>
    <a href="?page=payments&status=<?= $val ?>" class="tab-item <?= $status===$val?'active':'' ?>">
        <?= $label ?>
        <?php if ($_counts[$val] > 0): ?>
        <span class="tab-count <?= $val==='bekliyor'?'warn':'' ?>"><?= $_counts[$val] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="card">
<table class="table">
    <thead><tr><th>Tarih</th><th>Bayi</th><th>Tutar</th><th>Yöntem</th><th>Dekont</th><th>Durum</th><th>İşlem</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
    <tr>
        <td><?= fmtDate($p['created_at']) ?></td>
        <td><a href="?page=dealers&action=detail&id=<?= $p['dealer_id'] ?>"><?= h($p['company_name']) ?></a></td>
        <td class="font-medium text-success"><?= money($p['amount']) ?></td>
        <td><?= h($p['payment_method']) ?></td>
        <td>
            <?php if ($p['receipt_file']): ?>
            <a href="<?= h($p['receipt_file']) ?>" target="_blank" class="btn btn-xs btn-ghost">📄 Dekont</a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td>
            <span class="badge badge-<?= $p['status']==='onaylandi'?'green':($p['status']==='bekliyor'?'yellow':'red') ?>">
                <?= h($p['status']) ?>
            </span>
        </td>
        <td>
            <?php if ($p['status'] === 'bekliyor'): ?>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="approve">
                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                <button class="btn btn-xs btn-success">✓ Onayla</button>
            </form>
            <button class="btn btn-xs btn-danger" onclick="rejectPayment(<?= $p['id'] ?>)">✕ Reddet</button>
            <?php else: ?>
            <span class="text-muted text-sm"><?= $p['approved_at'] ? fmtDate($p['approved_at']) : '' ?></span>
            <?php endif; ?>
        </td>
    </tr>
    <?php if ($p['note']): ?>
    <tr class="row-sub">
        <td colspan="7" class="text-sm text-muted pl-6">Not: <?= h($p['note']) ?> <?= $p['reject_reason'] ? '| Red: '.h($p['reject_reason']) : '' ?></td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if (empty($payments)): ?><tr><td colspan="7" class="text-center text-muted py-8">Kayıt yok.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= $pager ?>

<!-- Modal: Manuel Tahsilat -->
<div id="modal-manual" class="modal-overlay" style="display:none">
<div class="modal">
    <div class="modal-header"><h3>Manuel Tahsilat Girişi</h3></div>
    <div class="modal-body">
        <form method="post" id="form-manual">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="manual">
            <div class="form-group">
                <label>Bayi *</label>
                <select name="dealer_id" class="form-control" required>
                    <option value="">— Bayi Seç —</option>
                    <?php foreach ($dealers ?? [] as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $dealerId==$d['id']?'selected':'' ?>><?= h($d['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Tutar (₺) *</label>
                <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Ödeme Yöntemi</label>
                <select name="payment_method" class="form-control">
                    <option value="havale">Havale/EFT</option>
                    <option value="nakit">Nakit</option>
                    <option value="kredi_karti">Kredi Kartı</option>
                    <option value="cek">Çek</option>
                    <option value="senet">Senet</option>
                </select>
            </div>
            <div class="form-group">
                <label>Ödeme Tarihi</label>
                <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" class="form-control">
            </div>
            <div class="form-group">
                <label>Not</label>
                <input type="text" name="note" class="form-control" placeholder="Açıklama…">
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-manual')">İptal</button>
        <button class="btn btn-primary" type="submit" form="form-manual">Kaydet</button>
    </div>
</div>
</div>

<!-- Modal: Reddet -->
<div id="modal-reject" class="modal-overlay" style="display:none">
<div class="modal">
    <div class="modal-header"><h3>Ödemeyi Reddet</h3></div>
    <div class="modal-body">
        <form method="post" id="form-reject">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="reject">
            <input type="hidden" name="payment_id" id="reject-payment-id" value="">
            <div class="form-group">
                <label>Red Nedeni</label>
                <textarea name="reject_reason" class="form-control" rows="3" placeholder="Bayi bilgilendirilecektir…"></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-reject')">Vazgeç</button>
        <button class="btn btn-danger" type="submit" form="form-reject">Reddet</button>
    </div>
</div>
</div>

<script>
function rejectPayment(id) {
    document.getElementById('reject-payment-id').value = id;
    openModal('modal-reject');
}
</script>
