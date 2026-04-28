<?php
// admin/pages/notifications.php
requireAdmin();
$adminId = $_SESSION['admin_id'];

// ── POST işlemleri (CSRF korumalı, WAF'a takılmaz) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';

    if ($act === 'mark_all_read') {
        dbExec("UPDATE b2b_notifications SET is_read=1 WHERE admin_id=?", [$adminId]);
        $_SESSION['flash_admin'] = ['type'=>'success', 'msg'=>'Tüm bildirimler okundu olarak işaretlendi.'];
        redirect('?page=notifications');
    }

    if ($act === 'delete_all') {
        dbExec("DELETE FROM b2b_notifications WHERE admin_id=?", [$adminId]);
        $_SESSION['flash_admin'] = ['type'=>'success', 'msg'=>'Tüm bildirimler silindi.'];
        redirect('?page=notifications');
    }

    if ($act === 'delete_read') {
        $deleted = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE admin_id=? AND is_read=1", [$adminId]);
        dbExec("DELETE FROM b2b_notifications WHERE admin_id=? AND is_read=1", [$adminId]);
        $_SESSION['flash_admin'] = ['type'=>'success', 'msg'=>"$deleted okunmuş bildirim silindi."];
        redirect('?page=notifications');
    }

    if ($act === 'delete_one') {
        $nid = (int)($_POST['nid'] ?? 0);
        if ($nid > 0) {
            dbExec("DELETE FROM b2b_notifications WHERE id=? AND admin_id=?", [$nid, $adminId]);
        }
        $f = $_POST['filter'] ?? 'all';
        redirect('?page=notifications&filter=' . urlencode($f));
    }
}

// Tek tıkla okundu işaretle (link'e tıklayınca)
if (isset($_GET['read']) && (int)$_GET['read'] > 0) {
    $nid = (int)$_GET['read'];
    dbExec("UPDATE b2b_notifications SET is_read=1 WHERE id=? AND admin_id=?", [$nid, $adminId]);
    // Hedef URL varsa oraya, yoksa listeye
    $target = dbVal("SELECT url FROM b2b_notifications WHERE id=? AND admin_id=?", [$nid, $adminId]);
    if ($target) redirect($target);
    redirect('?page=notifications');
}

$filter = $_GET['filter'] ?? 'all';
$where  = "admin_id=?";
$params = [$adminId];
if ($filter === 'unread')      $where .= " AND is_read=0";
elseif ($filter === 'read')    $where .= " AND is_read=1";

$rows = dbRows(
    "SELECT * FROM b2b_notifications WHERE $where ORDER BY created_at DESC LIMIT 200",
    $params
);

$totalUnread = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE admin_id=? AND is_read=0", [$adminId]);
$totalRead   = (int)dbVal("SELECT COUNT(*) FROM b2b_notifications WHERE admin_id=? AND is_read=1", [$adminId]);
?>
<div class="page-body">

<div class="page-header">
  <div>
    <h1 class="page-title">Bildirimler</h1>
    <p class="page-sub"><?= count($rows) ?> kayıt görüntüleniyor · <?= $totalUnread ?> okunmamış</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($totalUnread > 0): ?>
    <form method="post" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="mark_all_read">
      <button type="submit" class="btn btn-secondary">✓ Tümünü Okundu İşaretle</button>
    </form>
    <?php endif; ?>

    <?php if ($totalRead > 0): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('<?= $totalRead ?> okunmuş bildirim silinecek. Devam edilsin mi?')">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="delete_read">
      <button type="submit" class="btn"
              style="background:#fff;color:#92400e;border:1px solid #fcd34d">
        🧹 Okunanları Temizle (<?= $totalRead ?>)
      </button>
    </form>
    <?php endif; ?>

    <?php if (count($rows)): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Tüm bildirimleri kalıcı olarak silmek istediğinize emin misiniz?')">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="delete_all">
      <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">
        🗑 Tümünü Sil
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Filter -->
<div style="display:flex;gap:8px;margin-bottom:14px">
  <a href="?page=notifications&filter=all" class="btn btn-sm <?= $filter==='all'?'btn-primary':'btn-secondary' ?>">
    Tümü (<?= $totalUnread + $totalRead ?>)
  </a>
  <a href="?page=notifications&filter=unread" class="btn btn-sm <?= $filter==='unread'?'btn-primary':'btn-secondary' ?>">
    Okunmamış (<?= $totalUnread ?>)
  </a>
  <a href="?page=notifications&filter=read" class="btn btn-sm <?= $filter==='read'?'btn-primary':'btn-secondary' ?>">
    Okunmuş (<?= $totalRead ?>)
  </a>
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
    <?php foreach ($rows as $n):
      $title  = (string)($n['title'] ?? '');
      $body   = (string)($n['body']  ?? '');
      $type   = (string)($n['type']  ?? '');
      $isRead = !empty($n['is_read']);
      $icons  = ['order'=>'📦','payment'=>'💰','dealer'=>'🏢','application'=>'📝','ticket'=>'🎫','system'=>'⚙️','stock'=>'📊'];
      $icon   = $icons[$type] ?? '🔔';
    ?>
    <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border);<?= $isRead ? '' : 'background:#fefce8' ?>">
      <a href="?page=notifications&read=<?= (int)$n['id'] ?>"
         style="flex:1;display:flex;align-items:flex-start;gap:12px;text-decoration:none;color:inherit;min-width:0">
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
      </a>
      <form method="post" style="flex:0 0 auto"
            onsubmit="return confirm('Bu bildirimi silmek istediğinize emin misiniz?')">
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
<?php endif; ?>

</div>
