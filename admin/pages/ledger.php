<?php
// admin/pages/ledger.php — Cari Hesap Yönetimi
requireAdmin();

$dealerId = intval($_GET['dealer_id'] ?? 0);
$page     = max(1, intval($_GET['p'] ?? 1));
$perPage  = 30;
$offset   = ($page-1)*$perPage;

// Manuel cari kayıt
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ($_POST['form_action'] === 'add_entry') {
        $did    = intval($_POST['dealer_id']);
        $type   = $_POST['entry_type'];
        $amount = floatval($_POST['amount']);
        $desc   = trim($_POST['description']);
        $due    = $_POST['due_date'] ?: null;
        if ($did > 0 && $amount > 0 && in_array($type, ['borc','alacak'])) {
            ledgerAdd($did, $type, $amount, $desc, $due, null, 'manuel');
            auditLog('ledger_manual', 'b2b_ledger', 0, ['dealer_id'=>$did,'type'=>$type,'amount'=>$amount]);
            $success = 'Cari kayıt eklendi.';
        } else { $error = 'Hatalı bilgi.'; }
    }
    if ($_POST['form_action'] === 'close_entry') {
        $eid = intval($_POST['entry_id']);
        dbExec("UPDATE b2b_ledger SET is_closed=1, closed_at=NOW() WHERE id=?", [$eid]);
        $success = 'Kayıt kapatıldı.';
    }
}

$dealers = dbRows("SELECT id, company_name FROM b2b_dealers WHERE is_active=1 ORDER BY company_name");

// Seçili bayi
$dealer = null;
if ($dealerId) {
    $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$dealerId]);
    $balance = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealerId]);
    $overdue = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 AND due_date < CURDATE()", [$dealerId]);
    $total   = dbVal("SELECT COUNT(*) FROM b2b_ledger WHERE dealer_id=?", [$dealerId]);
    $entries = dbRows(
        "SELECT * FROM b2b_ledger WHERE dealer_id=? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset",
        [$dealerId]
    );
    $pager = pagination($total, $perPage, $page, "?page=ledger&dealer_id=$dealerId&p=");
} else {
    // Genel bakiye listesi
    $dealers_balance = dbRows(
        "SELECT d.id, d.company_name, d.credit_limit,
            COALESCE(SUM(CASE WHEN l.type='borc' THEN l.amount ELSE -l.amount END),0) AS balance,
            COALESCE(SUM(CASE WHEN l.type='borc' AND l.due_date < CURDATE() AND l.is_closed=0 THEN l.amount ELSE 0 END),0) AS overdue
         FROM b2b_dealers d
         LEFT JOIN b2b_ledger l ON l.dealer_id=d.id AND l.is_closed=0
         WHERE d.is_active=1
         GROUP BY d.id, d.company_name, d.credit_limit
         ORDER BY balance DESC",
        []
    );
}
?>

<div class="page-header">
    <div><h1 class="page-title">Cari Hesap</h1></div>
    <div class="btn-group">
        <?php if ($dealerId): ?>
        <a href="?page=ledger" class="btn btn-ghost">← Tümü</a>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openModal('modal-entry')">＋ Cari Kayıt</button>
    </div>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if (!$dealerId): ?>
<!-- ═══════════════════ GENEL BAKIYE LİSTESİ ═══════════════════ -->
<div class="card">
<table class="table">
    <thead><tr><th>Bayi</th><th class="text-right">Bakiye</th><th class="text-right">Vadesi Geçen</th><th class="text-right">Limit</th><th>Limit Kullanım</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($dealers_balance as $d): ?>
    <?php $pct = $d['credit_limit']>0 ? min(100, round($d['balance']/$d['credit_limit']*100)) : 0; ?>
    <tr>
        <td><a href="?page=ledger&dealer_id=<?= $d['id'] ?>"><?= h($d['company_name']) ?></a></td>
        <td class="text-right font-medium <?= $d['balance']>0?'text-danger':($d['balance']<0?'text-success':'') ?>"><?= money($d['balance']) ?></td>
        <td class="text-right <?= $d['overdue']>0?'text-danger font-bold':'' ?>"><?= $d['overdue']>0?money($d['overdue']):'—' ?></td>
        <td class="text-right"><?= money($d['credit_limit']) ?></td>
        <td>
            <?php if ($d['credit_limit']>0): ?>
            <div class="progress-bar" style="width:120px">
                <div class="progress-fill <?= $pct>80?'danger':($pct>60?'warning':'') ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <small class="text-muted"><?= $pct ?>%</small>
            <?php endif; ?>
        </td>
        <td><a href="?page=ledger&dealer_id=<?= $d['id'] ?>" class="btn btn-xs btn-ghost">Detay →</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php else: ?>
<!-- ═══════════════════ BAYİ CARİ DETAY ═══════════════════ -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="stat-card <?= $balance>0?'stat-card--danger':'' ?>">
        <div class="stat-label">Açık Bakiye</div>
        <div class="stat-value"><?= money($balance) ?></div>
        <div class="stat-sub"><?= $balance>0?'Borçlu':'Alacaklı' ?></div>
    </div>
    <div class="stat-card <?= $overdue>0?'stat-card--warning':'' ?>">
        <div class="stat-label">Vadesi Geçen</div>
        <div class="stat-value"><?= money($overdue) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Kredi Limiti</div>
        <div class="stat-value"><?= money($dealer['credit_limit']) ?></div>
        <div class="stat-sub">Vade: <?= $dealer['payment_term_days'] ?> gün</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><?= h($dealer['company_name']) ?> — Cari Hareketler</h3>
    </div>
    <table class="table">
        <thead><tr><th>Tarih</th><th>Açıklama</th><th class="text-right">Borç</th><th class="text-right">Alacak</th><th>Vade</th><th>Durum</th><th></th></tr></thead>
        <tbody>
        <?php $running = 0; foreach ($entries as $e):
            $running += ($e['type']==='borc' ? $e['amount'] : -$e['amount']);
            $isOverdue = $e['due_date'] && $e['due_date'] < date('Y-m-d') && !$e['is_closed'];
        ?>
        <tr class="<?= $isOverdue?'row-overdue':'' ?>">
            <td><?= fmtDate($e['created_at']) ?></td>
            <td><?= h($e['description']) ?></td>
            <td class="text-right <?= $e['type']==='borc'?'text-danger':'' ?>"><?= $e['type']==='borc'?money($e['amount']):'' ?></td>
            <td class="text-right <?= $e['type']==='alacak'?'text-success':'' ?>"><?= $e['type']==='alacak'?money($e['amount']):'' ?></td>
            <td class="<?= $isOverdue?'text-danger font-bold':'' ?>"><?= $e['due_date'] ? fmtDate($e['due_date']) : '—' ?></td>
            <td>
                <?php if ($e['is_closed']): ?>
                <span class="badge badge-gray">Kapalı</span>
                <?php elseif ($isOverdue): ?>
                <span class="badge badge-red">Vadesi Geçti</span>
                <?php else: ?>
                <span class="badge badge-green">Açık</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$e['is_closed']): ?>
                <form method="post" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="form_action" value="close_entry">
                    <input type="hidden" name="entry_id" value="<?= $e['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-ghost" title="Kapat">✓</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?><tr><td colspan="7" class="text-muted text-center py-6">Hareket yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?= $pager ?>
<?php endif; ?>

<!-- Modal: Cari Kayıt Ekle -->
<div id="modal-entry" class="modal-overlay">
<div class="modal">
    <div class="modal-header"><h3>Cari Kayıt Ekle</h3></div>
    <div class="modal-body">
        <form method="post" id="form-entry">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="add_entry">
            <div class="form-group">
                <label>Bayi *</label>
                <select name="dealer_id" class="form-control" required>
                    <option value="">— Seç —</option>
                    <?php foreach ($dealers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $dealerId==$d['id']?'selected':'' ?>><?= h($d['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Tür</label>
                <select name="entry_type" class="form-control">
                    <option value="borc">Borç</option>
                    <option value="alacak">Alacak</option>
                </select>
            </div>
            <div class="form-group">
                <label>Tutar (₺) *</label>
                <input type="number" step="0.01" name="amount" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Açıklama *</label>
                <input type="text" name="description" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Vade Tarihi</label>
                <input type="date" name="due_date" class="form-control">
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" onclick="closeModal('modal-entry')">İptal</button>
        <button class="btn btn-primary" type="submit" form="form-entry">Ekle</button>
    </div>
</div>
</div>
