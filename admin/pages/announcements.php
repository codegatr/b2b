<?php
// admin/pages/announcements.php — Duyuru Yönetimi
requireAdmin();

// Tablo garantisi
try {
    db()->exec("CREATE TABLE IF NOT EXISTS `b2b_announcements` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `content` text NOT NULL,
        `type` enum('bilgi','uyari','onemli') DEFAULT 'bilgi',
        `is_active` tinyint(1) DEFAULT 1,
        `starts_at` datetime DEFAULT NULL,
        `ends_at` datetime DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$success = '';
$action  = $_GET['action'] ?? 'list';
$id      = intval($_GET['id'] ?? 0);

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
        if (empty($data['title'])) { $error = 'Başlık zorunludur.'; }
        else {
            if ($id) {
                dbUpdateRow('b2b_announcements', $data, 'id', $id);
                $success = 'Duyuru güncellendi.';
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                dbInsertRow('b2b_announcements', $data);
                $success = 'Duyuru eklendi.';
                $action = 'list';
                $id = 0;
            }
        }
    }

    if ($act === 'delete') {
        dbExec("DELETE FROM b2b_announcements WHERE id=?", [intval($_POST['ann_id'])]);
        $success = 'Duyuru silindi.';
        $action = 'list';
    }

    if ($act === 'toggle') {
        $ann = dbRow("SELECT is_active FROM b2b_announcements WHERE id=?", [intval($_POST['ann_id'])]);
        if ($ann) dbExec("UPDATE b2b_announcements SET is_active=? WHERE id=?", [1 - $ann['is_active'], intval($_POST['ann_id'])]);
        $action = 'list';
    }
}

$announcements = dbRows("SELECT * FROM b2b_announcements ORDER BY created_at DESC");
$editAnn = ($id && $action === 'edit') ? dbRow("SELECT * FROM b2b_announcements WHERE id=?", [$id]) : null;
?>
<div class="page-header">
  <div><h1 class="page-title">Duyuru Yönetimi</h1></div>
  <a href="?page=announcements&action=add" class="btn btn-primary">+ Yeni Duyuru</a>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if ($action === 'add' || $editAnn): ?>
<!-- Form -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <h3 class="card-title"><?= $editAnn ? 'Duyuruyu Düzenle' : 'Yeni Duyuru' ?></h3>
    <a href="?page=announcements" class="btn btn-ghost btn-sm">← Liste</a>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="form_action" value="save">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Başlık *</label>
          <input type="text" name="title" class="form-control" required value="<?= h($editAnn['title'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Tür</label>
          <select name="type" class="form-control">
            <?php foreach (['bilgi'=>'ℹ️ Bilgi','uyari'=>'⚠️ Uyarı','onemli'=>'🔴 Önemli'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editAnn['type']??'bilgi')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Başlangıç (opsiyonel)</label>
          <input type="datetime-local" name="starts_at" class="form-control"
                 value="<?= $editAnn && $editAnn['starts_at'] ? date('Y-m-d\TH:i', strtotime($editAnn['starts_at'])) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Bitiş (opsiyonel)</label>
          <input type="datetime-local" name="ends_at" class="form-control"
                 value="<?= $editAnn && $editAnn['ends_at'] ? date('Y-m-d\TH:i', strtotime($editAnn['ends_at'])) : '' ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">İçerik *</label>
        <textarea name="content" class="form-control" rows="4" required><?= h($editAnn['content'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
          <input type="checkbox" name="is_active" value="1" <?= ($editAnn['is_active'] ?? 1) ? 'checked' : '' ?>>
          Aktif (bayilere göster)
        </label>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <a href="?page=announcements" class="btn btn-secondary">İptal</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Liste -->
<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Başlık</th><th>Tür</th><th>Tarih Aralığı</th><th>Durum</th><th>İşlem</th></tr></thead>
      <tbody>
      <?php if (empty($announcements)): ?>
      <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted)">Henüz duyuru yok.</td></tr>
      <?php endif; ?>
      <?php foreach ($announcements as $a): ?>
      <?php $tbadge = ['bilgi'=>'info','uyari'=>'warning','onemli'=>'danger'][$a['type']]; ?>
      <tr>
        <td class="fw-600"><?= h($a['title']) ?></td>
        <td><span class="badge badge-<?= $tbadge ?>"><?= ['bilgi'=>'Bilgi','uyari'=>'Uyarı','onemli'=>'Önemli'][$a['type']] ?></span></td>
        <td style="font-size:12px;color:var(--text-muted)">
          <?= $a['starts_at'] ? date('d.m.Y', strtotime($a['starts_at'])) : '—' ?>
          → <?= $a['ends_at'] ? date('d.m.Y', strtotime($a['ends_at'])) : 'Süresiz' ?>
        </td>
        <td>
          <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="toggle">
            <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
            <button class="badge badge-<?= $a['is_active']?'success':'neutral' ?>" style="border:none;cursor:pointer">
              <?= $a['is_active'] ? 'Aktif' : 'Pasif' ?>
            </button>
          </form>
        </td>
        <td>
          <a href="?page=announcements&action=edit&id=<?= $a['id'] ?>" class="btn btn-ghost btn-sm">Düzenle</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Silinsin mi?')">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="delete">
            <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
            <button class="btn btn-ghost btn-sm" style="color:var(--danger)">Sil</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
