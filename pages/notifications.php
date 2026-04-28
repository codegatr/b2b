<?php
// pages/notifications.php
requireDealer();
$dealerId = $_SESSION['dealer_id'];

// ── POST işlemleri (CSRF korumalı) ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';

    if ($act === 'mark_all_read') {
        dbExec("UPDATE b2b_notifications SET is_read=1 WHERE dealer_id=?", [$dealerId]);
        redirect('?page=notifications');
    }
    if ($act === 'delete_all') {
        dbExec("DELETE FROM b2b_notifications WHERE dealer_id=?", [$dealerId]);
        redirect('?page=notifications');
    }
    if ($act === 'delete_read') {
        dbExec("DELETE FROM b2b_notifications WHERE dealer_id=? AND is_read=1", [$dealerId]);
        redirect('?page=notifications');
    }
    if ($act === 'delete_one') {
        $nid = (int)($_POST['nid'] ?? 0);
        if ($nid > 0) {
            dbExec("DELETE FROM b2b_notifications WHERE id=? AND dealer_id=?", [$nid, $dealerId]);
        }
        redirect('?page=notifications&filter=' . urlencode($_POST['filter'] ?? 'all'));
    }
}

// Eski GET tabanlı işlemler (backwards compat)
if (isset($_GET['mark_all'])) {
    dbExec("UPDATE b2b_notifications SET is_read=1 WHERE dealer_id=?", [$dealerId]);
    redirect('?page=notifications');
}
if (isset($_GET['read']) && intval($_GET['read'])) {
    dbExec("UPDATE b2b_notifications SET is_read=1 WHERE id=? AND dealer_id=?",
           [intval($_GET['read']), $dealerId]);
    $target = dbVal("SELECT url FROM b2b_notifications WHERE id=? AND dealer_id=?",
                     [intval($_GET['read']), $dealerId]);
    if ($target) redirect($target);
    redirect('?page=notifications');
}

$filter  = $_GET['filter'] ?? 'all';
$where   = "dealer_id=?";
$params  = [$dealerId];
if ($filter === 'unread')       { $where .= " AND is_read=0"; }
elseif ($filter === 'read')     { $where .= " AND is_read=1"; }
elseif ($filter !== 'all')      { $where .= " AND type=?"; $params[] = $filter; }

$total       = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE $where", $params);
$perPage     = 20;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

$notifications = dbRows(
    "SELECT * FROM b2b_notifications WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);

$unreadCount = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE dealer_id=? AND is_read=0", [$dealerId]);
$readCount   = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE dealer_id=? AND is_read=1", [$dealerId]);
?>
<div class="page-body">
<div class="page-header">
  <div>
    <h1 class="page-title">Bildirimler</h1>
    <p class="page-sub"><?= $unreadCount ?> okunmamış · <?= $readCount ?> okunmuş</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($unreadCount > 0): ?>
    <form method="post" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="mark_all_read">
      <button type="submit" class="btn btn-secondary">✓ Tümünü Okundu İşaretle</button>
    </form>
    <?php endif; ?>
    <?php if ($readCount > 0): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('<?= $readCount ?> okunmuş bildirim silinecek.')">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="delete_read">
      <button type="submit" class="btn"
              style="background:#fff;color:#92400e;border:1px solid #fcd34d">
        🧹 Okunanları Temizle
      </button>
    </form>
    <?php endif; ?>
    <?php if ($total > 0): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Tüm bildirimler silinecek. Devam edilsin mi?')">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="delete_all">
      <button type="submit" class="btn"
              style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">
        🗑 Tümünü Sil
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Filtreler -->
<div class="tab-bar">
  <?php foreach (['all'=>'Tümü','unread'=>'Okunmamış','order'=>'Sipariş','payment'=>'Ödeme','stock'=>'Stok'] as $k=>$v): ?>
  <a href="?page=notifications&filter=<?= $k ?>" class="tab-item <?= $filter===$k?'active':'' ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:.4"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
    <p>Bildirim bulunamadı.</p>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="notif-list">
  <?php foreach ($notifications as $n): ?>
  <?php
  $icon = match($n['type'] ?? '') {
    'order'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>',
    'payment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'stock'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
    default   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
  };
  $iconBg = match($n['type'] ?? '') { 'order'=>'blue','payment'=>'green','stock'=>'amber',default=>'neutral' };
  $link = $n['url'] ?? '';
  ?>
  <div class="notif-item" style="display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);<?= empty($n['is_read']) ? 'background:#fefce8' : '' ?>">
    <a href="<?= $link ? h($link) : '?page=notifications&read='.(int)$n['id'] ?>"
       style="flex:1;display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;min-width:0">
      <div class="notif-icon stat-icon <?= $iconBg ?>"><?= $icon ?></div>
      <div class="notif-content" style="flex:1;min-width:0">
        <div class="notif-title"><?= h($n['title']) ?></div>
        <?php if ($n['body']): ?>
        <div class="notif-body"><?= h($n['body']) ?></div>
        <?php endif; ?>
        <div class="notif-time"><?= fmtDateTime($n['created_at']) ?></div>
      </div>
    </a>
    <form method="post" style="flex:0 0 auto"
          onsubmit="event.stopPropagation(); return confirm('Bu bildirimi silmek istediğinize emin misiniz?')">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="delete_one">
      <input type="hidden" name="nid" value="<?= (int)$n['id'] ?>">
      <input type="hidden" name="filter" value="<?= h($filter) ?>">
      <button type="submit" title="Bu bildirimi sil"
              style="background:transparent;border:none;color:#9ca3af;cursor:pointer;font-size:14px;padding:6px 10px;border-radius:6px;transition:.15s"
              onmouseover="this.style.background='#fef2f2';this.style.color='#dc2626'"
              onmouseout="this.style.background='transparent';this.style.color='#9ca3af'">
        🗑
      </button>
    </form>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<?php if ($total > $perPage): ?>
<div class="pagination" style="margin-top:16px">
  <?php for ($p=1; $p<=ceil($total/$perPage); $p++): ?>
  <a href="?page=notifications&filter=<?= $filter ?>&p=<?= $p ?>" class="<?= $p===$currentPage?'active':'' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
