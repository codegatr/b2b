<?php
// admin/pages/tickets.php — Destek Talepleri (Admin)
requireAdmin();

$success = '';
$id      = intval($_GET['id'] ?? 0);

// Yanıt gönder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    csrfCheck();
    $reply  = trim($_POST['admin_reply'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['acik','bekliyor','kapali']) ? $_POST['status'] : 'bekliyor';
    if ($reply) {
        dbExec(
            "UPDATE b2b_tickets SET admin_reply=?, status=?, replied_at=NOW(), replied_by=? WHERE id=?",
            [$reply, $status, adminId(), $id]
        );
        $success = 'Yanıt gönderildi.';
    }
}

if ($id) {
    $ticket = dbRow("SELECT t.*, d.company_name, d.first_name, d.last_name, d.email FROM b2b_tickets t JOIN b2b_dealers d ON d.id=t.dealer_id WHERE t.id=?", [$id]);
    if (!$ticket) { $id = 0; }
}

$tickets = dbRows(
    "SELECT t.*, d.company_name, d.first_name, d.last_name
     FROM b2b_tickets t JOIN b2b_dealers d ON d.id=t.dealer_id
     ORDER BY FIELD(t.status,'acik','bekliyor','kapali'), t.created_at DESC"
);

$counts = [
    'acik'     => dbVal("SELECT COUNT(*) FROM b2b_tickets WHERE status='acik'"),
    'bekliyor' => dbVal("SELECT COUNT(*) FROM b2b_tickets WHERE status='bekliyor'"),
    'kapali'   => dbVal("SELECT COUNT(*) FROM b2b_tickets WHERE status='kapali'"),
];
?>
<div class="page-header">
  <div><h1 class="page-title">Destek Talepleri</h1></div>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<?php if ($id && $ticket): ?>
<!-- Talep Detayı -->
<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><?= h($ticket['subject']) ?></h3>
      <a href="?page=tickets" class="btn btn-ghost btn-sm">← Liste</a>
    </div>
    <div class="card-body">
      <!-- Mesaj -->
      <div style="background:var(--bg);border-radius:8px;padding:14px;margin-bottom:16px">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
          <?= h($ticket['company_name'] ?: trim($ticket['first_name'].' '.$ticket['last_name'])) ?>
          · <?= fmtDateTime($ticket['created_at']) ?>
        </div>
        <div style="font-size:13px;line-height:1.6"><?= nl2br(h($ticket['message'])) ?></div>
      </div>
      <!-- Yanıt Formu -->
      <form method="post">
        <?= csrfField() ?>
        <div class="form-group">
          <label class="form-label">Yanıtınız</label>
          <textarea name="admin_reply" class="form-control" rows="5" required><?= h($ticket['admin_reply'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Durum</label>
          <select name="status" class="form-control">
            <?php foreach (['acik'=>'Açık','bekliyor'=>'Bekliyor','kapali'=>'Kapalı'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $ticket['status']===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Yanıtla &amp; Güncelle</button>
        </div>
      </form>
    </div>
  </div>
  <!-- Talep Bilgisi -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">Talep Bilgisi</h3></div>
    <div class="card-body" style="font-size:13px">
      <?php $pmap = ['dusuk'=>['neutral','Düşük'],'normal'=>['info','Normal'],'yuksek'=>['danger','Yüksek']]; [$pb,$pl] = $pmap[$ticket['priority']??'normal']; ?>
      <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Öncelik:</span> <span class="badge badge-<?= $pb ?>"><?= $pl ?></span></div>
      <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Tarih:</span> <?= fmtDateTime($ticket['created_at']) ?></div>
      <div style="margin-bottom:10px"><span style="color:var(--text-muted)">E-posta:</span> <a href="mailto:<?= h($ticket['email']) ?>"><?= h($ticket['email']) ?></a></div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Talep Listesi -->
<div style="display:flex;gap:8px;margin-bottom:16px">
  <?php foreach (['acik'=>['success','Açık'],'bekliyor'=>['warning','Bekliyor'],'kapali'=>['neutral','Kapalı']] as $k=>[$b,$l]): ?>
  <div class="stat-card" style="flex:1;padding:12px 16px">
    <div class="stat-value" style="font-size:22px"><?= $counts[$k] ?></div>
    <div class="stat-label"><span class="badge badge-<?= $b ?>"><?= $l ?></span></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Tarih</th><th>Bayi</th><th>Konu</th><th>Öncelik</th><th>Durum</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($tickets)): ?>
      <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">Henüz talep yok.</td></tr>
      <?php endif; ?>
      <?php foreach ($tickets as $t): ?>
      <?php
      $dn = $t['company_name'] ?: trim($t['first_name'].' '.$t['last_name']);
      $sbadge = match($t['status']) { 'kapali'=>'neutral','bekliyor'=>'warning',default=>'success' };
      $slabel = ['acik'=>'Açık','bekliyor'=>'Bekliyor','kapali'=>'Kapalı'][$t['status']];
      ?>
      <tr style="<?= $t['status']==='acik'&&!$t['admin_reply']?'background:rgba(237,41,57,.03)':'' ?>">
        <td style="font-size:12px;color:var(--text-muted)"><?= fmtDateTime($t['created_at']) ?></td>
        <td class="fw-600"><?= h($dn) ?></td>
        <td><?= h($t['subject']) ?><?php if (!$t['admin_reply'] && $t['status']==='acik'): ?> <span style="font-size:10px;background:var(--red);color:#fff;border-radius:3px;padding:1px 5px;margin-left:4px">YENİ</span><?php endif; ?></td>
        <td><?php $pmap=['dusuk'=>'neutral','normal'=>'info','yuksek'=>'danger']; ?><span class="badge badge-<?= $pmap[$t['priority']??'normal'] ?>"><?= ['dusuk'=>'Düşük','normal'=>'Normal','yuksek'=>'Yüksek'][$t['priority']??'normal'] ?></span></td>
        <td><span class="badge badge-<?= $sbadge ?>"><?= $slabel ?></span></td>
        <td><a href="?page=tickets&id=<?= $t['id'] ?>" class="btn btn-ghost btn-sm">Yanıtla</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
