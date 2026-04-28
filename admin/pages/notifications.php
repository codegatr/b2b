<?php
// admin/pages/notifications.php
requireAdmin();
$adminId = $_SESSION['admin_id'];

// Tümünü okundu işaretle
if (isset($_GET['mark_all'])) {
    dbExec("UPDATE b2b_notifications SET is_read=1 WHERE admin_id=?", [$adminId]);
    redirect('?page=notifications');
}

// Tekil okundu
if (isset($_GET['read']) && intval($_GET['read'])) {
    dbExec("UPDATE b2b_notifications SET is_read=1 WHERE id=? AND admin_id=?",
           [intval($_GET['read']), $adminId]);
}

// Tümünü sil
if (isset($_GET['delete_all'])) {
    csrfCheck();
    dbExec("DELETE FROM b2b_notifications WHERE admin_id=?", [$adminId]);
    redirect('?page=notifications');
}

$filter = $_GET['filter'] ?? 'all';
$where  = "admin_id=?";
$params = [$adminId];
if ($filter === 'unread') {
    $where .= " AND is_read=0";
} elseif ($filter === 'read') {
    $where .= " AND is_read=1";
}

$rows = dbRows(
    "SELECT * FROM b2b_notifications WHERE $where ORDER BY created_at DESC LIMIT 200",
    $params
);

$totalUnread = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE admin_id=? AND is_read=0", [$adminId]);
?>
<div class="page-body">

<div class="page-header">
  <div>
    <h1 class="page-title">Bildirimler</h1>
    <p class="page-sub"><?= count($rows) ?> kayıt · <?= $totalUnread ?> okunmamış</p>
  </div>
  <div style="display:flex;gap:8px">
    <?php if ($totalUnread > 0): ?>
    <a href="?page=notifications&mark_all=1" class="btn btn-secondary">Tümünü Okundu İşaretle</a>
    <?php endif; ?>
    <?php if (count($rows)): ?>
    <form method="get" style="display:inline" onsubmit="return confirm('Tüm bildirimleri silmek istediğinize emin misiniz?')">
      <input type="hidden" name="page" value="notifications">
      <input type="hidden" name="delete_all" value="1">
      <?= csrfField() ?>
      <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">
        🗑 Tümünü Sil
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Filter -->
<div style="display:flex;gap:8px;margin-bottom:14px">
  <a href="?page=notifications&filter=all" class="btn btn-sm <?= $filter==='all'?'btn-primary':'btn-secondary' ?>">Tümü</a>
  <a href="?page=notifications&filter=unread" class="btn btn-sm <?= $filter==='unread'?'btn-primary':'btn-secondary' ?>">Okunmamış (<?= $totalUnread ?>)</a>
  <a href="?page=notifications&filter=read" class="btn btn-sm <?= $filter==='read'?'btn-primary':'btn-secondary' ?>">Okunmuş</a>
</div>

<?php if (empty($rows)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:48px 24px;color:var(--text-muted)">
    <div style="font-size:36px;margin-bottom:8px">🔔</div>
    <p style="margin:0">Hiç bildirim yok.</p>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-body" style="padding:0">
    <?php foreach ($rows as $n): ?>
    <?php
    $url   = !empty($n['url']) ? $n['url'] : '?page=notifications&read=' . (int)$n['id'];
    $title = (string)($n['title'] ?? '');
    $body  = (string)($n['body']  ?? '');
    $type  = (string)($n['type']  ?? '');
    $isRead = !empty($n['is_read']);
    $icons = ['order'=>'📦','payment'=>'💰','dealer'=>'🏢','application'=>'📝','ticket'=>'🎫','system'=>'⚙️','stock'=>'📊'];
    $icon  = $icons[$type] ?? '🔔';
    ?>
    <a href="<?= h($url) ?>"
       style="display:block;padding:14px 18px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;<?= $isRead ? '' : 'background:#fefce8' ?>">
      <div style="display:flex;align-items:flex-start;gap:12px">
        <div style="font-size:20px;flex:0 0 auto;margin-top:1px"><?= $icon ?></div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
            <strong style="font-size:13px;color:var(--text)"><?= h($title) ?></strong>
            <?php if (!$isRead): ?><span style="width:6px;height:6px;background:var(--red);border-radius:50%"></span><?php endif; ?>
          </div>
          <?php if ($body !== ''): ?>
          <div style="font-size:12px;color:var(--text-2);margin-bottom:4px"><?= h($body) ?></div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--text-muted)"><?= fmtDateTime($n['created_at'] ?? '') ?></div>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</div>
