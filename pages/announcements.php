<?php
// pages/announcements.php — Bayi Duyurular Sayfası
requireDealer();

$dealer = currentDealer();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Duyurular</h1>
    <p class="page-sub">Güncel kampanya ve bilgilendirmeler</p>
  </div>
</div>

<?php if (empty($announcements)): ?>
<div style="text-align:center;padding:80px 20px;color:var(--text-muted)">
  <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="opacity:.25;margin-bottom:16px;display:block;margin-left:auto;margin-right:auto">
    <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>
  </svg>
  <div style="font-size:16px;font-weight:600;margin-bottom:6px">Şu an aktif duyuru yok</div>
  <div style="font-size:13px">Yeni duyurular burada görünecek.</div>
</div>

<?php else: ?>
<div style="display:flex;flex-direction:column;gap:16px">
  <?php foreach ($announcements as $ann):
    $colors = [
      'bilgi'   => ['border'=>'#2563eb','bg'=>'#eff6ff','icon'=>'ℹ','label'=>'Bilgi','lc'=>'#1d4ed8'],
      'uyari'   => ['border'=>'#d97706','bg'=>'#fffbeb','icon'=>'⚠','label'=>'Uyarı','lc'=>'#92400e'],
      'onemli'  => ['border'=>'#dc2626','bg'=>'#fef2f2','icon'=>'🔴','label'=>'Önemli','lc'=>'#991b1b'],
    ];
    $co = $colors[$ann['type'] ?? 'bilgi'];
  ?>
  <div style="background:#fff;border-radius:12px;border:1px solid #e4e6ea;border-left:4px solid <?= $co['border'] ?>;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)">

    <?php if (!empty($ann['image'])): ?>
    <div style="width:100%;max-height:320px;overflow:hidden">
      <img src="<?= h(B2B_URL . '/uploads/announcements/' . $ann['image']) ?>"
           alt="<?= h($ann['title']) ?>"
           style="width:100%;height:100%;object-fit:cover;display:block">
    </div>
    <?php endif; ?>

    <div style="padding:20px 24px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px">
          <span style="background:<?= $co['bg'] ?>;color:<?= $co['lc'] ?>;border:1px solid <?= $co['border'] ?>30;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:700"><?= $co['icon'] ?> <?= $co['label'] ?></span>
          <h2 style="font-size:17px;font-weight:700;color:#1a1d23;margin:0"><?= h($ann['title']) ?></h2>
        </div>
        <span style="font-size:12px;color:var(--text-muted);white-space:nowrap">
          <?= date('d.m.Y', strtotime($ann['created_at'])) ?>
          <?php if ($ann['ends_at']): ?>
          · <span style="color:#d97706">Son: <?= date('d.m.Y', strtotime($ann['ends_at'])) ?></span>
          <?php endif; ?>
        </span>
      </div>
      <div style="font-size:14px;line-height:1.7;color:#374151"><?= nl2br(h($ann['content'])) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
