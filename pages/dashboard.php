<?php
// pages/dashboard.php — Bayi Dashboard
requireDealer();
$dealer = currentDealer();

// İstatistikler
$totalOrders   = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE dealer_id=?", [$dealer['id']]);
$pendingOrders = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE dealer_id=? AND status='bekliyor'", [$dealer['id']]);
$openBalance   = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0", [$dealer['id']]);
$overdueAmount = dbVal("SELECT COALESCE(SUM(CASE WHEN type='borc' THEN amount ELSE -amount END),0) FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 AND due_date < CURDATE()", [$dealer['id']]);
$cartCount     = dbVal("SELECT COALESCE(SUM(quantity),0) FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);

// Son siparişler
$recentOrders = dbRows(
    "SELECT * FROM b2b_orders WHERE dealer_id=? ORDER BY created_at DESC LIMIT 5",
    [$dealer['id']]
);

// Okunmamış bildirimler
$notifications = dbRows(
    "SELECT * FROM b2b_notifications WHERE dealer_id=? ORDER BY created_at DESC LIMIT 6",
    [$dealer['id']]
);
$unreadCount = dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE dealer_id=? AND is_read=0", [$dealer['id']]);
// Okundu işaretle
dbExec("UPDATE b2b_notifications SET is_read=1 WHERE dealer_id=?", [$dealer['id']]);

// Vadesi yaklaşan (7 gün)
$upcomingDue = dbRows(
    "SELECT * FROM b2b_ledger WHERE dealer_id=? AND is_closed=0 AND type='borc' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY due_date",
    [$dealer['id']]
);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Merhaba, <?= h($dealer['contact_name'] ?: $dealer['company_name']) ?> 👋</h1>
        <p class="page-sub"><?= fmtDate(date('Y-m-d H:i:s')) ?></p>
    </div>
    <a href="?page=products" class="btn btn-primary">🛒 Sipariş Ver</a>
</div>

<!-- Stat Kartları -->
<div class="grid grid-cols-4 gap-4 mb-6">
    <a href="?page=orders" class="stat-card" style="text-decoration:none">
        <div class="stat-label">Toplam Sipariş</div>
        <div class="stat-value"><?= $totalOrders ?></div>
        <?php if ($pendingOrders): ?><div class="stat-sub text-warning"><?= $pendingOrders ?> bekliyor</div><?php endif; ?>
    </a>
    <div class="stat-card <?= $openBalance>0?'stat-card--danger':'' ?>">
        <div class="stat-label">Açık Bakiye</div>
        <div class="stat-value"><?= money($openBalance) ?></div>
        <div class="stat-sub"><?= $openBalance>0?'Borcunuz var':'Temiz hesap' ?></div>
    </div>
    <?php if ($overdueAmount > 0): ?>
    <div class="stat-card stat-card--danger">
        <div class="stat-label">Vadesi Geçen</div>
        <div class="stat-value"><?= money($overdueAmount) ?></div>
        <div class="stat-sub"><a href="?page=account" style="color:inherit">Ekstre →</a></div>
    </div>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-label">Kredi Limiti</div>
        <div class="stat-value"><?= money($dealer['credit_limit']) ?></div>
        <div class="stat-sub">Vade: <?= $dealer['payment_term_days'] ?> gün</div>
    </div>
    <?php endif; ?>
    <a href="?page=cart" class="stat-card" style="text-decoration:none">
        <div class="stat-label">Sepet</div>
        <div class="stat-value"><?= $cartCount ?> ürün</div>
        <?php if ($cartCount): ?><div class="stat-sub text-primary">Siparişi tamamla →</div><?php endif; ?>
    </a>
</div>

<!-- Vade Uyarısı -->
<?php if ($upcomingDue): ?>
<div class="alert alert-warning mb-6">
    <strong>⚠ Yaklaşan Vade Tarihleri:</strong>
    <?php foreach ($upcomingDue as $u): ?>
    <span style="margin-left:12px"><?= h($u['description']) ?> — <?= money($u['amount']) ?> — <?= fmtDate($u['due_date']) ?></span>
    <?php endforeach; ?>
    <a href="?page=account" style="margin-left:12px">Ekstre →</a>
</div>
<?php endif; ?>

<div class="grid grid-cols-2 gap-6">
    <!-- Son Siparişler -->
    <div class="card">
        <div class="card-header">
            <h3>Son Siparişler</h3>
            <a href="?page=orders" class="btn btn-xs btn-ghost">Tümü →</a>
        </div>
        <table class="table">
            <thead><tr><th>Sipariş No</th><th>Tarih</th><th>Tutar</th><th>Durum</th></tr></thead>
            <tbody>
            <?php foreach ($recentOrders as $o): ?>
            <tr>
                <td><a href="?page=orders&action=detail&id=<?= $o['id'] ?>"><?= h($o['order_number']) ?></a></td>
                <td class="text-sm"><?= fmtDate($o['created_at']) ?></td>
                <td><?= money($o['total_amount']) ?></td>
                <td><?= orderStatusLabel($o['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentOrders)): ?>
            <tr><td colspan="4" class="text-muted text-center py-6">Henüz sipariş yok. <a href="?page=products">Ürünleri inceleyin →</a></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Bildirimler -->
    <div class="card">
        <div class="card-header">
            <h3>Bildirimler <?php if ($unreadCount): ?><span class="badge badge-blue"><?= $unreadCount ?></span><?php endif; ?></h3>
            <a href="?page=notifications" class="btn btn-xs btn-ghost">Tümü →</a>
        </div>
        <div style="padding:0">
        <?php foreach ($notifications as $n): ?>
        <div class="notification-item <?= !$n['is_read']?'unread':'' ?>">
            <div class="notification-icon">
                <?= $n['type']==='order'?'📦':($n['type']==='payment'?'💰':($n['type']==='stock'?'📊':'🔔')) ?>
            </div>
            <div>
                <div class="notification-title"><?= h($n['title']) ?></div>
                <div class="notification-body text-sm text-muted"><?= h($n['message']) ?></div>
                <div class="notification-time"><?= fmtDate($n['created_at']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($notifications)): ?>
        <div class="p-6 text-center text-muted">Bildirim yok.</div>
        <?php endif; ?>
        </div>
    </div>
</div>
