<?php
requireDealer();
$dealerId = $_SESSION['dealer_id'];

// Tümünü okundu işaretle
if (isset($_GET['mark_all'])) {
    dbExec("UPDATE b2b_notifications SET is_read=1 WHERE dealer_id=?", [$dealerId]);
    header('Location: ?page=notifications');
    exit;
}

// Filtre
$filter = $_GET['filter'] ?? 'all'; // all, unread, order, payment, stock
$where = "dealer_id=?";
$params = [$dealerId];
if ($filter === 'unread') { $where .= " AND is_read=0"; }
elseif ($filter !== 'all') { $where .= " AND type=?"; $params[] = $filter; }

$total = dbExec("SELECT COUNT(*) FROM b2b_notifications WHERE $where");

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$stmt = dbRows("SELECT * FROM b2b_notifications WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");

// Okunmamış sayısı
$unreadCount = dbExec("SELECT COUNT(*) FROM b2b_notifications WHERE dealer_id=? AND is_read=0", [$dealerId]);
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Bildirimler</h1>
        <p class="page-sub"><?= $unreadCount ?> okunmamış bildirim</p>
    </div>
    <?php if ($unreadCount > 0): ?>
    <a href="?page=notifications&mark_all=1" class="btn btn-secondary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        Tümünü Okundu İşaretle
    </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body" style="padding:0">
        <!-- Filtreler -->
        <div style="display:flex;gap:.5rem;padding:1rem 1.25rem;border-bottom:1px solid var(--border);flex-wrap:wrap">
            <?php
            $filters = [
                'all'     => 'Tümü',
                'unread'  => 'Okunmamış',
                'order'   => 'Siparişler',
                'payment' => 'Ödemeler',
                'stock'   => 'Stok',
            ];
            foreach ($filters as $val => $label):
                $active = $filter === $val ? 'btn-primary' : 'btn-secondary';
            ?>
            <a href="?page=notifications&filter=<?= $val ?>" class="btn btn-sm <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($notifications)): ?>
        <div style="padding:3rem;text-align:center;color:var(--text-muted)">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:1rem;opacity:.4"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <p>Bildirim bulunamadı.</p>
        </div>
        <?php else: ?>
        <div class="notif-list">
            <?php foreach ($notifications as $n):
                $icon = match($n['type']) {
                    'order'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>',
                    'payment' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                    'stock'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
                    default   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
                };
                $color = match($n['type']) {
                    'order'   => '#6366f1',
                    'payment' => '#10b981',
                    'stock'   => '#f59e0b',
                    default   => '#64748b',
                };
                $unreadStyle = $n['is_read'] ? '' : 'background:rgba(99,102,241,.04)';
            ?>
            <div class="notif-item" style="<?= $unreadStyle ?>" data-id="<?= $n['id'] ?>">
                <div class="notif-icon" style="background:<?= $color ?>20;color:<?= $color ?>">
                    <?= $icon ?>
                </div>
                <div class="notif-content">
                    <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                    <div class="notif-body"><?= htmlspecialchars($n['message']) ?></div>
                    <div class="notif-time"><?= fmtDate($n['created_at']) ?></div>
                </div>
                <?php if (!$n['is_read']): ?>
                <div class="notif-dot"></div>
                <?php endif; ?>
                <?php if ($n['link']): ?>
                <a href="<?= htmlspecialchars($n['link']) ?>" class="notif-action">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?= pagination($totalCount, $perPage, $page, '?page=notifications&filter='.$filter.'&p=') ?>

<style>
.notif-list { display:flex;flex-direction:column }
.notif-item {
    display:flex;align-items:flex-start;gap:1rem;padding:1rem 1.25rem;
    border-bottom:1px solid var(--border);transition:background .15s;
}
.notif-item:last-child { border-bottom:none }
.notif-item:hover { background:var(--surface-2) }
.notif-icon {
    width:40px;height:40px;border-radius:10px;display:flex;
    align-items:center;justify-content:center;flex-shrink:0;margin-top:.1rem
}
.notif-content { flex:1;min-width:0 }
.notif-title { font-weight:600;font-size:.9rem;margin-bottom:.2rem }
.notif-body { font-size:.85rem;color:var(--text-muted);line-height:1.5 }
.notif-time { font-size:.78rem;color:var(--text-muted);margin-top:.3rem }
.notif-dot {
    width:8px;height:8px;background:#6366f1;border-radius:50%;
    flex-shrink:0;margin-top:.4rem
}
.notif-action {
    color:var(--text-muted);display:flex;align-items:center;
    padding:.25rem;border-radius:6px;transition:color .15s
}
.notif-action:hover { color:var(--text) }
</style>

<script>
// Bildirime tıklayınca okundu işaretle
document.querySelectorAll('.notif-item').forEach(el => {
    el.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch('/api/notifications.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'read', id})
        });
        this.style.background = '';
        const dot = this.querySelector('.notif-dot');
        if (dot) dot.remove();
    });
});
</script>