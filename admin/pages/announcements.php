<?php
// admin/pages/announcements.php — Duyuru Yönetimi
requireAdmin();

// Tablo + görsel klasörü garantisi
try {
    db()->exec("CREATE TABLE IF NOT EXISTS `b2b_announcements` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `content` text NOT NULL,
        `image` varchar(255) DEFAULT NULL,
        `type` enum('bilgi','uyari','onemli') DEFAULT 'bilgi',
        `is_active` tinyint(1) DEFAULT 1,
        `starts_at` datetime DEFAULT NULL,
        `ends_at` datetime DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // image kolonu eksikse ekle
    db()->exec("ALTER TABLE `b2b_announcements` ADD COLUMN IF NOT EXISTS `image` varchar(255) DEFAULT NULL AFTER `content`");
} catch (Exception $e) {}

$uploadDir = B2B_ROOT . '/uploads/announcements';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$success = '';
$error   = '';
$action  = $_GET['action'] ?? 'list';
$id      = intval($_GET['id'] ?? 0);

// ── POST Handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';

    if ($act === 'save') {
        $data = [
            'title'      => trim($_POST['title'] ?? ''),
            'content'    => trim($_POST['content'] ?? ''),
            'type'       => in_array($_POST['type']??'', ['bilgi','uyari','onemli']) ? $_POST['type'] : 'bilgi',
            'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            'starts_at'  => $_POST['starts_at'] ?: null,
            'ends_at'    => $_POST['ends_at'] ?: null,
            'created_by' => adminId(),
        ];

        // Görsel yükleme
        if (!empty($_FILES['image']['name'])) {
            $file = $_FILES['image'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowed)) {
                $error = 'Sadece JPG, PNG, GIF, WEBP formatı kabul edilir.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = 'Görsel 5MB\'den küçük olmalı.';
            } else {
                $newName = 'ann_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $newName)) {
                    // Eski görseli sil
                    if ($id) {
                        $old = dbVal("SELECT image FROM b2b_announcements WHERE id=?", [$id]);
                        if ($old && file_exists($uploadDir . '/' . $old)) unlink($uploadDir . '/' . $old);
                    }
                    $data['image'] = $newName;
                } else {
                    $error = 'Görsel yüklenemedi.';
                }
            }
        }

        // Görseli kaldır
        if (!empty($_POST['remove_image']) && $id) {
            $old = dbVal("SELECT image FROM b2b_announcements WHERE id=?", [$id]);
            if ($old && file_exists($uploadDir . '/' . $old)) unlink($uploadDir . '/' . $old);
            $data['image'] = null;
        }

        if (!$error) {
            if (empty($data['title'])) {
                $error = 'Başlık zorunludur.';
            } else {
                if ($id) {
                    dbUpdateRow('b2b_announcements', $data, 'id', $id);
                    $success = 'Duyuru güncellendi.';
                    $action = 'edit';
                } else {
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $id = dbInsertRow('b2b_announcements', $data);
                    $success = 'Duyuru eklendi.';
                    $action = 'list';
                    $id = 0;
                }
            }
        }
    }

    if ($act === 'delete') {
        $did = intval($_POST['ann_id']);
        $old = dbVal("SELECT image FROM b2b_announcements WHERE id=?", [$did]);
        if ($old && file_exists($uploadDir . '/' . $old)) unlink($uploadDir . '/' . $old);
        dbExec("DELETE FROM b2b_announcements WHERE id=?", [$did]);
        $success = 'Duyuru silindi.';
        $action = 'list';
        $id = 0;
    }

    if ($act === 'toggle') {
        $did = intval($_POST['ann_id']);
        dbExec("UPDATE b2b_announcements SET is_active=IF(is_active=1,0,1) WHERE id=?", [$did]);
        header("Location: ?page=announcements"); exit;
    }
}

$announcements = dbRows("SELECT * FROM b2b_announcements ORDER BY created_at DESC");
$editAnn = ($action === 'edit' && $id) ? dbRow("SELECT * FROM b2b_announcements WHERE id=?", [$id]) : null;
if ($action === 'edit' && !$editAnn) { $action = 'new'; $id = 0; }
?>

<div class="page-header">
  <div><h1 class="page-title">Duyuru Yönetimi</h1></div>
  <?php if ($action === 'list'): ?>
  <a href="?page=announcements&action=new" class="btn btn-primary">+ Yeni Duyuru</a>
  <?php else: ?>
  <a href="?page=announcements" class="btn btn-ghost">← Liste</a>
  <?php endif; ?>
</div>

<?php if ($success): ?><div class="alert alert-success" style="margin-bottom:16px"><?= h($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:16px"><?= h($error) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- ── LİSTE ── -->
<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th style="width:40px"></th><th>Başlık</th><th style="width:80px">Tür</th><th style="width:70px">Aktif</th><th style="width:120px">Tarih</th><th style="width:80px"></th></tr>
      </thead>
      <tbody>
      <?php if (empty($announcements)): ?>
      <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">Henüz duyuru yok.</td></tr>
      <?php endif; ?>
      <?php foreach ($announcements as $a):
        $tc = ['bilgi'=>['info','ℹ Bilgi'],'uyari'=>['warning','⚠ Uyarı'],'onemli'=>['danger','🔴 Önemli']][$a['type']??'bilgi'];
      ?>
      <tr>
        <td style="padding:8px 12px">
          <?php if ($a['image']): ?>
          <img src="<?= h(B2B_URL . '/uploads/announcements/' . $a['image']) ?>"
               style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
          <?php else: ?>
          <div style="width:36px;height:36px;background:var(--bg);border-radius:6px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:16px">📢</div>
          <?php endif; ?>
        </td>
        <td>
          <div class="fw-600" style="font-size:13px"><?= h($a['title']) ?></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= h(mb_substr(strip_tags($a['content']),0,60)) ?>...</div>
        </td>
        <td><span class="badge badge-<?= $tc[0] ?>"><?= $tc[1] ?></span></td>
        <td>
          <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="toggle">
            <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
            <button type="submit" style="background:none;border:none;cursor:pointer;padding:0" title="Aktiflik değiştir">
              <?php if ($a['is_active']): ?>
              <span style="color:#16a34a;font-size:18px">●</span>
              <?php else: ?>
              <span style="color:#d1d5db;font-size:18px">○</span>
              <?php endif; ?>
            </button>
          </form>
        </td>
        <td style="font-size:12px;color:var(--text-muted)"><?= date('d.m.Y', strtotime($a['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:6px">
            <a href="?page=announcements&action=edit&id=<?= $a['id'] ?>" class="btn btn-ghost btn-sm">Düzenle</a>
            <form method="post" onsubmit="return confirm('Duyuru silinecek?')">
              <?= csrfField() ?>
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
              <button class="btn btn-ghost btn-sm" style="color:var(--danger)">🗑</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- ── FORM (YENİ / DÜZENLE) ── -->
<?php $isEdit = ($action === 'edit' && $editAnn); ?>
<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">

<!-- Sol: Form -->
<div class="card">
  <div class="card-header"><h3 class="card-title"><?= $isEdit ? 'Duyuruyu Düzenle' : 'Yeni Duyuru' ?></h3></div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="save">

      <div class="form-group">
        <label class="form-label">Başlık <span style="color:var(--danger)">*</span></label>
        <input type="text" name="title" class="form-control" required
               value="<?= h($editAnn['title'] ?? '') ?>" placeholder="Duyuru başlığı...">
      </div>

      <div class="form-group">
        <label class="form-label">İçerik</label>
        <textarea name="content" class="form-control" rows="5"
                  placeholder="Duyuru içeriği..."><?= h($editAnn['content'] ?? '') ?></textarea>
      </div>

      <!-- Görsel Yükleme -->
      <div class="form-group">
        <label class="form-label">Görsel (Kampanya Görseli)</label>
        <?php if ($isEdit && !empty($editAnn['image'])): ?>
        <div style="margin-bottom:10px;position:relative;display:inline-block">
          <img src="<?= h(B2B_URL . '/uploads/announcements/' . $editAnn['image']) ?>"
               style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid var(--border);display:block">
          <label style="display:flex;align-items:center;gap:6px;margin-top:8px;cursor:pointer;font-size:13px;color:var(--danger)">
            <input type="checkbox" name="remove_image" value="1"> Görseli kaldır
          </label>
        </div>
        <?php endif; ?>
        <div style="border:2px dashed var(--border);border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:border-color .2s"
             onclick="document.getElementById('ann-img').click()"
             ondragover="this.style.borderColor='var(--red)'" ondragleave="this.style.borderColor='var(--border)'">
          <input type="file" id="ann-img" name="image" accept="image/*" style="display:none"
                 onchange="previewImage(this)">
          <div id="img-preview-wrap" style="display:none;margin-bottom:10px">
            <img id="img-preview" style="max-height:160px;border-radius:6px;max-width:100%">
          </div>
          <div style="color:var(--text-muted);font-size:13px">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="display:block;margin:0 auto 8px;opacity:.4"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <span>Tıkla veya sürükle — JPG, PNG, GIF, WEBP (maks 5MB)</span>
          </div>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Tür</label>
          <select name="type" class="form-control">
            <?php foreach (['bilgi'=>'ℹ Bilgi','uyari'=>'⚠ Uyarı','onemli'=>'🔴 Önemli'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($editAnn['type']??'bilgi')===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:8px;margin-top:24px">
            <input type="checkbox" name="is_active" value="1" <?= ($editAnn['is_active']??1)?'checked':'' ?>>
            Aktif (bayilerde görünsün)
          </label>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Başlangıç Tarihi</label>
          <input type="datetime-local" name="starts_at" class="form-control"
                 value="<?= $editAnn['starts_at'] ? date('Y-m-d\TH:i', strtotime($editAnn['starts_at'])) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Bitiş Tarihi</label>
          <input type="datetime-local" name="ends_at" class="form-control"
                 value="<?= $editAnn['ends_at'] ? date('Y-m-d\TH:i', strtotime($editAnn['ends_at'])) : '' ?>">
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 Kaydet</button>
        <a href="?page=announcements" class="btn btn-ghost">İptal</a>
      </div>
    </form>
  </div>
</div>

<!-- Sağ: Önizleme -->
<div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">👁 Bayi Görünümü</h3></div>
    <div class="card-body" style="padding:0;overflow:hidden;border-radius:0 0 10px 10px">
      <?php if ($isEdit && !empty($editAnn['image'])): ?>
      <img src="<?= h(B2B_URL . '/uploads/announcements/' . $editAnn['image']) ?>"
           style="width:100%;max-height:200px;object-fit:cover;display:block">
      <?php endif; ?>
      <?php if ($isEdit): ?>
      <?php $tc2 = ['bilgi'=>['#eff6ff','#2563eb','ℹ Bilgi','#1d4ed8'],'uyari'=>['#fffbeb','#d97706','⚠ Uyarı','#92400e'],'onemli'=>['#fef2f2','#dc2626','🔴 Önemli','#991b1b']][$editAnn['type']??'bilgi']; ?>
      <div style="padding:16px 20px">
        <span style="background:<?= $tc2[0] ?>;color:<?= $tc2[3] ?>;border:1px solid <?= $tc2[1] ?>30;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700"><?= $tc2[2] ?></span>
        <div style="font-size:15px;font-weight:700;margin:8px 0 6px;color:#1a1d23"><?= h($editAnn['title']) ?></div>
        <div style="font-size:13px;color:#374151;line-height:1.6"><?= h(mb_substr(strip_tags($editAnn['content']),0,120)) ?>...</div>
      </div>
      <?php else: ?>
      <div style="padding:20px;color:var(--text-muted);font-size:13px;text-align:center">Kaydedince önizleme çıkacak.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($isEdit): ?>
  <div class="card" style="margin-top:12px">
    <div class="card-body" style="font-size:13px">
      <div style="margin-bottom:8px"><span style="color:var(--text-muted)">Durum:</span>
        <span class="badge badge-<?= $editAnn['is_active']?'success':'neutral' ?>"><?= $editAnn['is_active']?'Aktif':'Pasif' ?></span>
      </div>
      <div style="margin-bottom:8px"><span style="color:var(--text-muted)">Oluşturulma:</span> <?= date('d.m.Y H:i', strtotime($editAnn['created_at'])) ?></div>
      <?php if ($editAnn['starts_at']): ?><div style="margin-bottom:8px"><span style="color:var(--text-muted)">Başlangıç:</span> <?= date('d.m.Y', strtotime($editAnn['starts_at'])) ?></div><?php endif; ?>
      <?php if ($editAnn['ends_at']): ?><div><span style="color:var(--text-muted)">Bitiş:</span> <span style="color:#d97706"><?= date('d.m.Y', strtotime($editAnn['ends_at'])) ?></span></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

</div><!-- /grid -->
<?php endif; ?>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('img-preview').src = e.target.result;
            document.getElementById('img-preview-wrap').style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
