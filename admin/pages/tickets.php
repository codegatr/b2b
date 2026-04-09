<?php
// admin/pages/tickets.php — Destek Talepleri (Admin)
requireAdmin();

$success = '';
$error   = '';
$id      = intval($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? 'reply';

    // Admin adına yeni talep aç
    if ($act === 'create') {
        $dealerId = intval($_POST['dealer_id'] ?? 0);
        $subject  = trim($_POST['subject'] ?? '');
        $message  = trim($_POST['message'] ?? '');
        $priority = in_array($_POST['priority']??'', ['dusuk','normal','yuksek']) ? $_POST['priority'] : 'normal';
        if (!$dealerId || !$subject || !$message) {
            $error = 'Bayi, konu ve mesaj zorunludur.';
        } else {
            $newId = dbInsertRow('b2b_tickets', [
                'dealer_id'  => $dealerId,
                'subject'    => $subject,
                'message'    => $message,
                'priority'   => $priority,
                'status'     => 'acik',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            auditLog('ticket_created_by_admin', 'b2b_tickets', $newId, ['dealer_id'=>$dealerId]);
            header("Location: ?page=tickets&id=$newId&created=1"); exit;
        }
    }

    // Yanıt gönder
    if ($act === 'reply' && $id) {
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

$allDealers = dbRows("SELECT id, company_name, first_name, last_name FROM b2b_dealers WHERE is_active=1 ORDER BY company_name");
?>
<div class="page-header">
  <div><h1 class="page-title">Destek Talepleri</h1></div>
  <?php if (!$id): ?>
  <button class="btn btn-primary" onclick="document.getElementById('modal-new-ticket').style.display='flex'">
    ＋ Yeni Talep Aç
  </button>
  <?php endif; ?>
</div>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Talep başarıyla oluşturuldu.</div><?php endif; ?>

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
      <?php if ($ticket['admin_reply']): ?>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin-bottom:16px">
        <div style="font-size:12px;color:var(--success);margin-bottom:6px">
          Admin Yanıtı · <?= fmtDateTime($ticket['replied_at']) ?>
        </div>
        <div style="font-size:13px;line-height:1.6"><?= nl2br(h($ticket['admin_reply'])) ?></div>
      </div>
      <?php endif; ?>
      <!-- Yanıt Formu -->
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="reply">
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
      <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Bayi:</span> <strong><?= h($ticket['company_name'] ?: trim($ticket['first_name'].' '.$ticket['last_name'])) ?></strong></div>
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

<!-- Modal: Yeni Talep Aç -->
<div id="modal-new-ticket" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:520px;width:100%">
    <div class="modal-header">
      <h3>Bayi Adına Destek Talebi Aç</h3>
    </div>
    <div class="modal-body">
      <form method="post" id="form-new-ticket">
        <?= csrfField() ?>
        <input type="hidden" name="form_action" value="create">
        <div class="form-group">
          <label class="form-label">Bayi <span style="color:var(--danger)">*</span></label>
          <select name="dealer_id" class="form-control" required>
            <option value="">Bayi seçin…</option>
            <?php foreach ($allDealers as $d): ?>
            <option value="<?= $d['id'] ?>"><?= h($d['company_name'] ?: trim($d['first_name'].' '.$d['last_name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Konu <span style="color:var(--danger)">*</span></label>
          <input type="text" name="subject" class="form-control" required placeholder="Destek talebi konusu…">
        </div>
        <div class="form-group">
          <label class="form-label">Öncelik</label>
          <select name="priority" class="form-control">
            <option value="dusuk">Düşük</option>
            <option value="normal" selected>Normal</option>
            <option value="yuksek">Yüksek</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Mesaj <span style="color:var(--danger)">*</span></label>
          <textarea name="message" class="form-control" rows="4" required placeholder="Talebin detaylarını girin…"></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-new-ticket').style.display='none'">Vazgeç</button>
      <button class="btn btn-primary" type="submit" form="form-new-ticket">Talebi Oluştur</button>
    </div>
  </div>
</div>


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
