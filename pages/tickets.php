<?php
// pages/tickets.php — Destek Talepleri
requireDealer();
$dealer = currentDealer();

$success = '';
$error   = '';

// Yeni destek talebi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'new_ticket') {
    csrfCheck();
    $subject  = trim($_POST['subject'] ?? '');
    $message  = trim($_POST['message'] ?? '');
    $priority = in_array($_POST['priority'] ?? '', ['dusuk','normal','yuksek']) ? $_POST['priority'] : 'normal';

    if (!$subject) { $error = 'Konu zorunludur.'; }
    elseif (!$message) { $error = 'Mesaj zorunludur.'; }
    else {
        dbInsertRow('b2b_tickets', [
            'dealer_id'  => $dealer['id'],
            'subject'    => $subject,
            'message'    => $message,
            'priority'   => $priority,
            'status'     => 'acik',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $success = 'Destek talebiniz iletildi. En kısa sürede yanıt vereceğiz.';
    }
}

// Talepler
$tickets = dbRows(
    "SELECT * FROM b2b_tickets WHERE dealer_id=? ORDER BY created_at DESC",
    [$dealer['id']]
);
?>
<div class="page-body">
<div class="page-header">
  <div>
    <h1 class="page-title">Destek Talepleri</h1>
    <p class="page-sub">Sorularınız ve talepleriniz için bizimle iletişime geçin</p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('newTicketForm').classList.toggle('hidden')">
    + Yeni Talep
  </button>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<!-- Yeni Talep Formu -->
<div id="newTicketForm" class="card hidden" style="margin-bottom:20px">
  <div class="card-header"><h3 class="card-title">Yeni Destek Talebi</h3></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="new_ticket">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Konu *</label>
          <input type="text" name="subject" class="form-control" required placeholder="Talebinizin konusu">
        </div>
        <div class="form-group">
          <label class="form-label">Öncelik</label>
          <select name="priority" class="form-control">
            <option value="dusuk">Düşük</option>
            <option value="normal" selected>Normal</option>
            <option value="yuksek">Yüksek</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Mesaj *</label>
        <textarea name="message" class="form-control" rows="5" required placeholder="Sorununuzu veya talebinizi detaylı açıklayın..."></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Gönder</button>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('newTicketForm').classList.add('hidden')">İptal</button>
      </div>
    </form>
  </div>
</div>

<!-- Talep Listesi -->
<div class="card">
  <div class="card-header"><h3 class="card-title">Taleplerim (<?= count($tickets) ?>)</h3></div>
  <?php if (empty($tickets)): ?>
  <div class="card-body" style="text-align:center;color:var(--text-muted);padding:40px">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:.4"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
    <p>Henüz destek talebiniz yok.</p>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Tarih</th><th>Konu</th><th>Öncelik</th><th>Durum</th><th>Yanıt</th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
      <tr>
        <td style="font-size:12px;color:var(--text-muted)"><?= fmtDateTime($t['created_at']) ?></td>
        <td class="fw-600"><?= h($t['subject']) ?></td>
        <td>
          <?php $pbadge = match($t['priority']) { 'yuksek'=>'danger','dusuk'=>'neutral',default=>'info' }; ?>
          <span class="badge badge-<?= $pbadge ?>"><?= ['dusuk'=>'Düşük','normal'=>'Normal','yuksek'=>'Yüksek'][$t['priority']] ?></span>
        </td>
        <td>
          <?php $sbadge = match($t['status']) { 'kapali'=>'neutral','bekliyor'=>'warning',default=>'success' }; ?>
          <span class="badge badge-<?= $sbadge ?>"><?= ['acik'=>'Açık','bekliyor'=>'Bekliyor','kapali'=>'Kapalı'][$t['status']] ?></span>
        </td>
        <td>
          <?php if ($t['admin_reply']): ?>
          <details>
            <summary style="cursor:pointer;font-size:12px;color:var(--red)">Yanıtı Gör</summary>
            <div style="margin-top:8px;padding:10px;background:var(--bg);border-radius:6px;font-size:13px"><?= nl2br(h($t['admin_reply'])) ?></div>
          </details>
          <?php else: ?>
          <span style="font-size:12px;color:var(--text-muted)">Bekleniyor</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</div>
