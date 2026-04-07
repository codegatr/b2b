<?php
requireAdmin();

$msg = '';
$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// Sil
if ($action === 'delete' && $editId) {
    if ($editId === $_SESSION['admin_id']) {
        $msg = 'error:Kendinizi silemezsiniz.';
    } else {
        dbExec("DELETE FROM b2b_admin_users WHERE id=?", [$editId]);
        auditLog('admin_delete', 'b2b_admin_users', $editId, ['deleted_by' => $_SESSION['admin_id']]);
        header('Location: ?page=admins&msg=deleted');
        exit;
    }
}

// Şifre sıfırla
if ($action === 'reset_pass' && $editId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $newPass = $_POST['new_password'] ?? '';
    if (strlen($newPass) < 6) {
        $msg = 'error:Şifre en az 6 karakter olmalı.';
    } else {
        dbExec("UPDATE b2b_admin_users SET password=? WHERE id=?", [password_hash($newPass, PASSWORD_DEFAULT), $editId]);
        auditLog('admin_reset_pass', 'b2b_admin_users', $editId);
        $msg = 'success:Şifre güncellendi.';
        $action = 'list';
    }
}

// Kaydet (ekle / güncelle)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrfCheck();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role  = $_POST['role'] ?? 'admin';
    $pass  = $_POST['password'] ?? '';

    if (!$name || !$email) {
        $msg = 'error:Ad ve e-posta zorunludur.';
    } else {
        if ($editId) {
            // Güncelle
            $sql = "UPDATE b2b_admin_users SET name=?, email=?, role=?";
            $params = [$name, $email, $role];
            if ($pass) {
                if (strlen($pass) < 6) { $msg = 'error:Şifre en az 6 karakter.'; goto render; }
                $sql .= ", password=?";
                $params[] = password_hash($pass, PASSWORD_DEFAULT);
            }
            $sql .= " WHERE id=?";
            $params[] = $editId;
            dbExec($sql, $params);
            auditLog('admin_update', 'b2b_admin_users', $editId);
            $msg = 'success:Admin güncellendi.';
        } else {
            // Ekle
            if (!$pass || strlen($pass) < 6) { $msg = 'error:Şifre en az 6 karakter.'; goto render; }
            if (dbVal("SELECT id FROM b2b_admin_users WHERE email=?", [$email])) {
                $msg = 'error:Bu e-posta zaten kayıtlı.'; goto render;
            }
            dbExec("INSERT INTO b2b_admin_users (name, email, password, role) VALUES (?,?,?,?)", [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
            auditLog('admin_create', 'b2b_admin_users', db()->lastInsertId());
            $msg = 'success:Admin eklendi.';
        }
        $editId = 0;
        $action = 'list';
    }
}

render:

// URL mesajı
if (isset($_GET['msg'])) {
    $msgs = ['deleted' => 'success:Admin silindi.'];
    $msg = $msgs[$_GET['msg']] ?? $msg;
}

// Düzenleme
$editRow = null;
if ($action === 'edit' && $editId) {
    $editRow = dbRow("SELECT * FROM b2b_admin_users WHERE id=?", [$editId]);
    if (!$editRow) { $action = 'list'; }
}

// Liste
$admins = dbRows("SELECT * FROM b2b_admin_users ORDER BY id ASC");
?>

<?php if ($msg): [$type, $text] = explode(':', $msg, 2); ?>
<div class="alert alert-<?= $type === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:1rem"><?= htmlspecialchars($text) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Admin Kullanıcıları</h1>
        <p class="page-sub"><?= count($admins) ?> admin tanımlı</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('addForm').classList.toggle('hidden')">
        + Yeni Admin
    </button>
</div>

<!-- Ekle / Düzenle Formu -->
<div id="addForm" class="card <?= ($action === 'edit' || $msg) ? '' : 'hidden' ?>" style="margin-bottom:1.5rem;padding:1.5rem">
    <h3 style="margin:0 0 1.25rem;font-size:1rem"><?= $editRow ? 'Admin Düzenle' : 'Yeni Admin Ekle' ?></h3>
    <form method="POST" action="?page=admins<?= $editRow ? '&action=edit&id='.$editRow['id'] : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="save" value="1">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div class="form-group">
                <label class="form-label">Ad Soyad *</label>
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($editRow['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">E-posta *</label>
                <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($editRow['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Şifre <?= $editRow ? '(boş bırakılırsa değişmez)' : '*' ?></label>
                <input type="password" name="password" class="form-control" <?= $editRow ? '' : 'required' ?> placeholder="En az 6 karakter">
            </div>
            <div class="form-group">
                <label class="form-label">Rol</label>
                <select name="role" class="form-control">
                    <option value="superadmin" <?= ($editRow['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>Süper Admin</option>
                    <option value="admin"      <?= ($editRow['role'] ?? 'admin') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="viewer"     <?= ($editRow['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>İzleyici</option>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:.75rem;margin-top:.5rem">
            <button type="submit" class="btn btn-primary"><?= $editRow ? 'Güncelle' : 'Ekle' ?></button>
            <a href="?page=admins" class="btn btn-secondary">İptal</a>
        </div>
    </form>
</div>

<!-- Tablo -->
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Ad Soyad</th>
                <th>E-posta</th>
                <th>Rol</th>
                <th>Son Giriş</th>
                <th style="text-align:right">İşlem</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($admins as $a):
                $roleBadge = match($a['role']) {
                    'superadmin' => '<span class="badge badge-danger">Süper Admin</span>',
                    'viewer'     => '<span class="badge">İzleyici</span>',
                    default      => '<span class="badge badge-primary">Admin</span>',
                };
                $isSelf = $a['id'] === $_SESSION['admin_id'];
            ?>
            <tr>
                <td><?= $a['id'] ?></td>
                <td>
                    <div style="font-weight:600"><?= htmlspecialchars($a['name']) ?></div>
                    <?php if ($isSelf): ?><span style="font-size:.75rem;color:var(--text-muted)">(Siz)</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($a['email']) ?></td>
                <td><?= $roleBadge ?></td>
                <td style="color:var(--text-muted);font-size:.85rem">
                    <?= $a['last_login'] ? fmtDate($a['last_login']) : '-' ?>
                </td>
                <td style="text-align:right">
                    <div style="display:flex;gap:.5rem;justify-content:flex-end">
                        <a href="?page=admins&action=edit&id=<?= $a['id'] ?>" class="btn btn-sm btn-secondary">Düzenle</a>
                        <button class="btn btn-sm btn-secondary"
                            onclick="document.getElementById('rp<?= $a['id'] ?>').classList.toggle('hidden')">
                            Şifre
                        </button>
                        <?php if (!$isSelf): ?>
                        <a href="?page=admins&action=delete&id=<?= $a['id'] ?>"
                           class="btn btn-sm btn-danger"
                           onclick="return confirm('Bu admini silmek istediğinize emin misiniz?')">Sil</a>
                        <?php endif; ?>
                    </div>
                    <!-- Şifre Sıfırla Mini Form -->
                    <div id="rp<?= $a['id'] ?>" class="hidden" style="margin-top:.5rem">
                        <form method="POST" action="?page=admins&action=reset_pass&id=<?= $a['id'] ?>"
                              style="display:flex;gap:.5rem;justify-content:flex-end">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="password" name="new_password" class="form-control" style="width:160px;height:34px;font-size:.85rem" placeholder="Yeni şifre">
                            <button type="submit" class="btn btn-sm btn-primary">Kaydet</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
