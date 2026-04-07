<?php
// pages/profile.php — Bayi Profili
requireDealer();
$dealer = currentDealer();

$success = '';
$errors  = [];

// Profil güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_info') {
    csrfCheck();
    $raw    = trim($_POST['contact_name'] ?? '');
    $parts  = explode(' ', $raw, 2);
    $fName  = $parts[0] ?? '';
    $lName  = $parts[1] ?? '';
    $phone  = trim($_POST['phone'] ?? '');
    $addr   = trim($_POST['address'] ?? '');
    $city   = trim($_POST['city'] ?? '');
    $taxOff = trim($_POST['tax_office'] ?? '');

    if (!$raw) $errors[] = 'Ad Soyad zorunludur.';
    if (!$phone) $errors[] = 'Telefon zorunludur.';

    if (empty($errors)) {
        dbExec(
            "UPDATE b2b_dealers SET first_name=?, last_name=?, phone=?, address=?, city=?, tax_office=?, updated_at=NOW() WHERE id=?",
            [$fName, $lName, $phone, $addr, $city, $taxOff, $dealer['id']]
        );
        $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id=?", [$dealer['id']]);
        $_SESSION['dealer_name'] = trim(($dealer['first_name']??'').' '.($dealer['last_name']??'')) ?: ($dealer['company_name']??'');
        $success = 'Profil güncellendi.';
        auditLog('profile_update', 'b2b_dealers', $dealer['id'], []);
    }
}

// Şifre değiştir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrfCheck();
    $cur  = $_POST['current_password'] ?? '';
    $new  = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';

    $row = dbRow("SELECT password FROM b2b_dealers WHERE id=?", [$dealer['id']]);
    if (!$cur) $errors[] = 'Mevcut şifre zorunludur.';
    elseif (!password_verify($cur, $row['password'])) $errors[] = 'Mevcut şifre hatalı.';
    if (strlen($new) < 8) $errors[] = 'Yeni şifre en az 8 karakter olmalı.';
    if ($new !== $conf) $errors[] = 'Şifreler uyuşmuyor.';

    if (empty($errors)) {
        dbExec("UPDATE b2b_dealers SET password=?, updated_at=NOW() WHERE id=?",
               [password_hash($new, PASSWORD_DEFAULT), $dealer['id']]);
        $success = 'Şifre değiştirildi.';
        auditLog('password_change', 'b2b_dealers', $dealer['id'], []);
    }
}

$contactName = trim(($dealer['first_name']??'').' '.($dealer['last_name']??''));
?>
<div class="page-body">
<div class="page-header">
  <div><h1 class="page-title">Profilim</h1></div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

<!-- Profil Bilgileri -->
<div class="card">
  <div class="card-header"><h3 class="card-title">Profil Bilgileri</h3></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_info">
      <div class="form-group">
        <label class="form-label">Firma Adı</label>
        <input type="text" class="form-control" value="<?= h($dealer['company_name']??'') ?>" disabled style="background:var(--bg)">
        <p style="font-size:12px;color:var(--text-muted);margin-top:3px">Firma adı değişikliği için yöneticinize başvurun.</p>
      </div>
      <div class="form-group">
        <label class="form-label">Ad Soyad (Yetkili)</label>
        <input type="text" name="contact_name" class="form-control" value="<?= h($contactName) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">E-posta</label>
        <input type="email" class="form-control" value="<?= h($dealer['email']??'') ?>" disabled style="background:var(--bg)">
      </div>
      <div class="form-group">
        <label class="form-label">Telefon</label>
        <input type="tel" name="phone" class="form-control" value="<?= h($dealer['phone']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Adres</label>
        <textarea name="address" class="form-control" rows="2"><?= h($dealer['address']??'') ?></textarea>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Şehir</label>
          <input type="text" name="city" class="form-control" value="<?= h($dealer['city']??'') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Vergi Dairesi</label>
          <input type="text" name="tax_office" class="form-control" value="<?= h($dealer['tax_office']??'') ?>">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Kaydet</button>
      </div>
    </form>
  </div>
</div>

<!-- Şifre Değiştir -->
<div class="card">
  <div class="card-header"><h3 class="card-title">Şifre Değiştir</h3></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label class="form-label">Mevcut Şifre</label>
        <input type="password" name="current_password" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Yeni Şifre</label>
        <input type="password" name="new_password" class="form-control" required minlength="8">
      </div>
      <div class="form-group">
        <label class="form-label">Yeni Şifre (Tekrar)</label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Şifreyi Değiştir</button>
      </div>
    </form>
  </div>
</div>

</div><!-- /grid -->
</div>
