<?php
// pages/account.php — Cari Hesap / Ekstre
requireDealer();
$dealer = currentDealer();

$page    = max(1, intval($_GET['p'] ?? 1));
$perPage = 20;
$offset  = ($page-1)*$perPage;

$total   = dbVal("SELECT COUNT(*) FROM b2b_ledger WHERE dealer_id=?", [$dealer['id']]);
$entries = dbRows("SELECT * FROM b2b_ledger WHERE dealer_id=? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", [$dealer['id']]);
$pager   = pagination($total, $perPage, $page, "?page=account&p=");

$balance  = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealer['id']]);
$overdue  = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 AND due_date < CURDATE()", [$dealer['id']]);
$payments = dbVal("SELECT COALESCE(SUM(amount),0) FROM b2b_payments WHERE dealer_id=? AND status='onaylandi'", [$dealer['id']]);
?>

<?php
// Kart ödemesi etkin mi? settings'ten kontrol
$cardEnabled = setting('rubikpara_enabled') === '1';
?>

<div class="page-header">
    <div><h1 class="page-title">Cari Hesabım / Ekstre</h1></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($cardEnabled && $balance > 0): ?>
        <a href="?page=payment-card&balance=1&amount=<?= number_format((float)$balance, 2, '.', '') ?>"
           class="btn btn-primary"
           style="background:#16a34a;border-color:#16a34a">
            💳 Kart ile Tüm Borcu Öde (<?= money($balance) ?>)
        </a>
        <?php endif; ?>
        <a href="?page=payments&action=new" class="btn btn-secondary">🏦 Havale Bildir</a>
    </div>
</div>

<?php if ($cardEnabled && $balance > 0): ?>
<!-- Açık bakiyeyi serbest tutar ile kart ödeme -->
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 14px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="font-size:18px">💳</div>
    <div style="flex:1;font-size:12px;color:#166534">
        <strong>Kredi kartı ile anında ödeme</strong> · Bakiyenizin tamamını veya bir kısmını kart ile ödeyebilirsiniz.
    </div>
    <form method="get" action="?" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="page" value="payment-card">
        <input type="hidden" name="balance" value="1">
        <input type="number" name="amount" step="0.01" min="0.01" max="<?= number_format((float)$balance, 2, '.', '') ?>"
               value="<?= number_format((float)$balance, 2, '.', '') ?>"
               class="form-control" style="width:140px;height:36px;font-weight:600;text-align:right" required>
        <span style="font-size:13px;color:#166534">₺</span>
        <button type="submit" class="btn btn-sm" style="background:#16a34a;color:#fff;border:none;height:36px">
            Ödeme Yap →
        </button>
    </form>
</div>
<?php endif; ?>

<div class="grid grid-cols-4 gap-4 mb-6">
    <div class="stat-card <?= $balance>0?'stat-card--danger':'' ?>">
        <div class="stat-label">Açık Bakiye</div>
        <div class="stat-value"><?= money($balance) ?></div>
        <div class="stat-sub"><?= $balance>0?'Borcunuz var':($balance<0?'Fazla ödeme':'Temiz') ?></div>
    </div>
    <div class="stat-card <?= $overdue>0?'stat-card--danger':'' ?>">
        <div class="stat-label">Vadesi Geçen</div>
        <div class="stat-value"><?= money($overdue) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Kredi Limiti</div>
        <div class="stat-value"><?= money($dealer['credit_limit']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Toplam Ödeme</div>
        <div class="stat-value"><?= money($payments) ?></div>
    </div>
</div>

<?php if ($overdue > 0): ?>
<div class="alert alert-danger mb-6">
    ⚠️ <strong><?= money($overdue) ?></strong> tutarında vadesi geçmiş borcunuz bulunmaktadır.
    Lütfen en kısa sürede ödeme yapınız.
    <a href="?page=payments&action=new" class="btn btn-sm btn-white ml-4">Ödeme Bildir</a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Hesap Hareketleri</h3>
        <span class="text-sm text-muted">Son <?= $total ?> kayıt</span>
    </div>
    <table class="table">
        <thead>
            <tr>
                <th>Tarih</th>
                <th>Açıklama</th>
                <th class="text-right">Borç</th>
                <th class="text-right">Alacak</th>
                <th>Vade</th>
                <th>Durum</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e):
            $isOverdue = !$e['is_closed'] && $e['due_date'] && $e['due_date'] < date('Y-m-d') && $e['type']==='borc';
        ?>
        <tr class="<?= $isOverdue?'row-overdue':'' ?>">
            <td class="text-sm"><?= fmtDate($e['created_at']) ?></td>
            <td><?= h($e['description']) ?></td>
            <td class="text-right <?= $e['type']==='borc'?'text-danger font-medium':'' ?>">
                <?= $e['type']==='borc' ? money($e['amount']) : '' ?>
            </td>
            <td class="text-right <?= $e['type']==='alacak'?'text-success font-medium':'' ?>">
                <?= $e['type']==='alacak' ? money($e['amount']) : '' ?>
            </td>
            <td class="text-sm <?= $isOverdue?'text-danger font-bold':'' ?>">
                <?= $e['due_date'] ? fmtDate($e['due_date']) : '—' ?>
                <?= $isOverdue ? ' ⚠' : '' ?>
            </td>
            <td>
                <?php if ($e['is_closed']): ?>
                <span class="badge badge-gray">Kapalı</span>
                <?php elseif ($isOverdue): ?>
                <span class="badge badge-red">Gecikmiş</span>
                <?php else: ?>
                <span class="badge badge-green">Açık</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?>
        <tr><td colspan="6" class="text-center text-muted py-8">Henüz cari hareket yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= $pager ?>
