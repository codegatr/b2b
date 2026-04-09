<?php
// pages/announcements.php — Bayi Duyurular Sayfası
$anns = dbRows(
    "SELECT * FROM b2b_announcements WHERE is_active=1
     AND (starts_at IS NULL OR starts_at <= NOW())
     AND (ends_at IS NULL OR ends_at >= NOW())
     ORDER BY type DESC, created_at DESC"
);

$typeLabel = ['bilgi'=>'Bilgi','uyari'=>'Uyarı','onemli'=>'Önemli'];
$typeBg    = ['bilgi'=>'#eff6ff','uyari'=>'#fffbeb','onemli'=>'#fef2f2'];
$typeBdr   = ['bilgi'=>'#bfdbfe','uyari'=>'#fde68a','onemli'=>'#fecaca'];
$typeTag   = ['bilgi'=>'#1d4ed8','uyari'=>'#92400e','onemli'=>'#991b1b'];
$typeIcon  = ['bilgi'=>'ℹ️','uyari'=>'⚠️','onemli'=>'🔴'];
?>
<div class="page-header">
  <div>
    <div class="page-title">Duyurular</div>
    <div class="page-subtitle"><?= count($anns) ?> aktif duyuru</div>
  </div>
</div>

<?php if (empty($anns)): ?>
<div class="empty-state">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
    <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73"/>
  </svg>
  Şu an aktif bir duyuru yok.
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:16px">
  <?php foreach ($anns as $a):
    $t  = $a['type'] ?? 'bilgi';
    $bg = $typeBg[$t]  ?? '#eff6ff';
    $bd = $typeBdr[$t] ?? '#bfdbfe';
    $tc = $typeTag[$t] ?? '#1d4ed8';
    $ic = $typeIcon[$t]?? 'ℹ️';
    $lbl= $typeLabel[$t]?? 'Bilgi';
    $hasImg = !empty($a['image']) && file_exists(B2B_ROOT . '/uploads/announcements/' . $a['image']);
  ?>
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06)">
    <?php if ($hasImg): ?>
    <div style="width:100%;max-height:320px;overflow:hidden;border-bottom:1px solid #f3f4f6">
      <img src="<?= h(BASE_URL) ?>/uploads/announcements/<?= h($a['image']) ?>"
           alt="<?= h($a['title']) ?>"
           style="width:100%;height:100%;object-fit:cover;display:block">
    </div>
    <?php endif; ?>
    <div style="padding:20px 24px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <span style="background:<?= $bg ?>;color:<?= $tc ?>;border:1px solid <?= $bd ?>;border-radius:6px;font-size:11px;font-weight:700;padding:3px 10px;letter-spacing:.3px">
          <?= $ic ?> <?= $lbl ?>
        </span>
        <span style="font-size:12px;color:#9ca3af;margin-left:auto">
          <?= date('d.m.Y', strtotime($a['created_at'])) ?>
        </span>
      </div>
      <div style="font-size:17px;font-weight:700;color:#111;margin-bottom:8px;line-height:1.3">
        <?= h($a['title']) ?>
      </div>
      <div style="font-size:14px;color:#4b5563;line-height:1.7">
        <?= nl2br(h($a['content'])) ?>
      </div>
      <?php if ($a['ends_at']): ?>
      <div style="margin-top:14px;font-size:11px;color:#9ca3af;border-top:1px solid #f3f4f6;padding-top:10px">
        🕐 Bitiş: <?= date('d.m.Y', strtotime($a['ends_at'])) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
