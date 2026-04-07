<?php
// admin/pages/categories.php — Kategori Yönetimi
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['form_action'] ?? '';
    $id  = intval($_POST['id'] ?? 0);

    if ($act === 'save') {
        $data = [
            'name'       => trim($_POST['name']),
            'slug'       => slugify(trim($_POST['name'])),
            'sort_order' => intval($_POST['sort_order']),
            'is_active'  => isset($_POST['is_active']) ? 1 : 0,
        ];
        if (!empty($_FILES['icon']['name'])) {
            $ic = uploadFile($_FILES['icon'], 'categories', ['png','svg','webp','jpg'], 1);
            if ($ic) $data['icon'] = $ic;
        }
        if ($id) { dbUpdateRow('b2b_categories', $data, 'id', $id); $success = 'Güncellendi.'; }
        else     { dbInsertRow('b2b_categories', $data); $success = 'Kategori eklendi.'; }
    }

    if ($act === 'toggle') {
        $id = intval($_POST['cat_id']);
        $c = dbVal("SELECT is_active FROM b2b_categories WHERE id=?", [$id]);
        dbExec("UPDATE b2b_categories SET is_active=? WHERE id=?", [$c?0:1, $id]);
        header('Location: ?page=categories'); exit;
    }

    if ($act === 'delete') {
        $id = intval($_POST['cat_id']);
        $cnt = dbVal("SELECT COUNT(*) FROM b2b_products WHERE category_id=?", [$id]);
        if ($cnt > 0) { $error = "Bu kategoride $cnt ürün var. Önce ürünleri taşıyın."; }
        else { dbExec("DELETE FROM b2b_categories WHERE id=?", [$id]); $success = 'Silindi.'; }
    }
}

$edit = null;
if (!empty($_GET['edit'])) {
    $edit = dbRow("SELECT * FROM b2b_categories WHERE id=?", [intval($_GET['edit'])]);
}

$cats = dbRows("SELECT c.*, (SELECT COUNT(*) FROM b2b_products p WHERE p.category_id=c.id) AS product_count FROM b2b_categories c ORDER BY c.sort_order, c.name");
?>
<div class="page-header">
    <div><h1 class="page-title">Kategoriler</h1></div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)):   ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="grid grid-cols-3 gap-6">
    <!-- Kategori Listesi -->
    <div class="col-span-2">
    <div class="card">
    <table class="table">
        <thead><tr><th>Kategori</th><th>Ürün Sayısı</th><th>Sıra</th><th>Durum</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
        <tr>
            <td><?= h($c['name']) ?></td>
            <td><?= $c['product_count'] ?></td>
            <td><?= $c['sort_order'] ?></td>
            <td>
                <form method="post" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="form_action" value="toggle">
                    <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="badge badge-<?= $c['is_active']?'green':'gray' ?>" style="border:none;cursor:pointer"><?= $c['is_active']?'Aktif':'Pasif' ?></button>
                </form>
            </td>
            <td class="text-right">
                <a href="?page=categories&edit=<?= $c['id'] ?>" class="btn btn-xs btn-secondary">Düzenle</a>
                <?php if ($c['product_count'] == 0): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Silmek istediğinize emin misiniz?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="form_action" value="delete">
                    <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-danger">Sil</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <!-- Form -->
    <div>
    <div class="card">
        <div class="card-header"><h3><?= $edit ? 'Düzenle: '.h($edit['name']) : 'Yeni Kategori' ?></h3></div>
        <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="save">
            <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
            <div class="form-group">
                <label>Kategori Adı *</label>
                <input type="text" name="name" value="<?= h($edit['name']??'') ?>" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Sıra (küçük = üstte)</label>
                <input type="number" name="sort_order" value="<?= $edit['sort_order']??10 ?>" class="form-control">
            </div>
            <div class="form-group">
                <label>İkon (isteğe bağlı)</label>
                <input type="file" name="icon" class="form-control" accept="image/*,.svg">
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1" <?= ($edit['is_active']??1)?'checked':'' ?>>
                    Aktif
                </label>
            </div>
            <button type="submit" class="btn btn-primary w-full">Kaydet</button>
            <?php if ($edit): ?><a href="?page=categories" class="btn btn-ghost w-full mt-2">İptal</a><?php endif; ?>
        </form>
        </div>
    </div>
    </div>
</div>
