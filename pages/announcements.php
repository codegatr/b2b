<?php
// Duyurular sayfası - bayi görünümü
$anns = dbRows(
    "SELECT * FROM b2b_announcements
     WHERE is_active=1
       AND (starts_at IS NULL OR starts_at <= NOW())
       AND (ends_at   IS NULL OR ends_at   >= NOW())
     ORDER BY type DESC, created_at DESC"
);

$typeLabel = ['bilgi'=>'Bilgi','uyari'=>'Uyarı','onemli'=>'Önemli'];
$typeBg    = ['bilgi'=>'#eff6ff','uyari'=>'#fffbeb','onemli'=>'#fff1f2'];
$typeBrd   = ['bilgi'=>'#bfdbfe','uyari'=>'#fde68a','onemli'=>'#fecdd3'];
$typeTxt   = ['bilgi'=>'#1d4ed8','uyari'=>'#92400e','onemli'=>'#be123c'];
$typeIcon  = ['bilgi'=>'ℹ️','uyari'=>'⚠️','onemli'=>'📢'];
?>

<div class="page-header" style="margin-bottom:28px">
  <div>
    <h1 class="page-title">Duyurular</h1>
    <p style="color:var(--muted);font-size:14px;margin-top:4px">
      <?= count($anns) ?> aktif duyuru
    </p>
  </div>
</div>

<?php if (empty($anns)): ?>
<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:64px 32px;text-align:center">
  <div style="font-size:48px;margin-bottom:12px">📭</div>
  <div style="font-size:16px;font-weight:600;color:var(--text);margin-bottom:6px">Duyuru bulunmuyor</div>
  <div style="color:var(--muted);font-size:14px">Şu an aktif duyuru yok.</div>
</div>

<?php else: ?>
<div style="display:flex;flex-direction:column;gap:16px">
<?php foreach ($anns as $ann):
  $t    = $ann['type'] ?? 'bilgi';
  $bg   = $typeBg[$t]  ?? '#f9fafb';
  $brd  = $typeBrd[$t] ?? '#e5e7eb';
  $txt  = $typeTxt[$t] ?? '#374151';
  $icon = $typeIcon[$t] ?? 'ℹ️';
  $lbl  = $typeLabel[$t] ?? 'Bilgi';
  $hasImg = !empty($ann['image']) && file_exists(dirname(__DIR__).'/uploads/announcements/'.$ann['image']);
?>
<div style="background:#fff;border:1px solid <?= $brd ?>;border-radius:12px;overflow:hidden;border-left:4px solid <?= $txt ?>">
  <?php if ($hasImg): ?>
  <div style="width:100%;max-height:320px;overflow:hidden;border-bottom:1px solid <?= $brd ?>">
    <img src="<?= h(BASE_URL.'uploads/announcements/'.$ann['image']) ?>"
         alt="<?= h($ann['title']) ?>"
         style="width:100%;max-height:320px;object-fit:cover;display:block">
  </div>
  <?php endif; ?>
  <div style="padding:20px 24px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <span style="background:<?= $bg ?>;color:<?= $txt ?>;border:1px solid <?= $brd ?>;border-radius:99px;font-size:11px;font-weight:700;padding:3px 10px;letter-spacing:.4px">
        <?= $icon ?> <?= $lbl ?>
      </span>
      <span style="color:var(--muted);font-size:12px">
        <?= date('d.m.Y', strtotime($ann['created_at'])) ?>
      </span>
      <?php if ($ann['ends_at']): ?>
      <span style="color:var(--muted);font-size:12px">
        · <?= date('d.m.Y', strtotime($ann['ends_at'])) ?> tarihine kadar
      </span>
      <?php endif; ?>
    </div>
    <h2 style="font-size:18px;font-weight:700;color:var(--text);margin-bottom:10px;line-height:1.3">
      <?= h($ann['title']) ?>
    </h2>
    <div style="color:var(--muted);font-size:14px;line-height:1.7;white-space:pre-line">
      <?= nl2br(h($ann['content'])) ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
