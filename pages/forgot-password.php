<?php
/**
 * Şifremi Unuttum — Bayi & Admin
 */

$step  = 'request';
$error = '';
$token = trim($_GET['token'] ?? '');

// Token varsa reset adımı
if ($token) {
    $row = dbRow(
        "SELECT id, 'dealer' AS utype FROM b2b_dealers
          WHERE reset_token=? AND reset_expires > NOW() AND is_active=1
         UNION
         SELECT id, 'admin' AS utype FROM b2b_admin_users
          WHERE reset_token=? AND reset_expires > NOW() AND is_active=1
         LIMIT 1",
        [$token, $token]
    );
    $step = $row ? 'reset' : 'request';
    if (!$row) $error = 'Bu bağlantı geçersiz veya süresi dolmuş.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Adım 1 — e-posta gönder
    if (isset($_POST['email'])) {
        $email = trim($_POST['email']);
        $found = dbRow("SELECT id,'dealer' AS utype FROM b2b_dealers    WHERE email=? AND is_active=1", [$email])
              ?? dbRow("SELECT id,'admin'  AS utype FROM b2b_admin_users WHERE email=? AND is_active=1", [$email]);

        if ($found) {
            $tok     = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $table   = $found['utype'] === 'admin' ? 'b2b_admin_users' : 'b2b_dealers';
            dbExec("UPDATE `$table` SET reset_token=?, reset_expires=? WHERE id=?",
                   [$tok, $expires, $found['id']]);

            $siteName  = setting('site_name', 'B2B Portal');
            $siteUrl   = rtrim(setting('site_url', ''), '/');
            $resetLink = $siteUrl . '/?page=forgot-password&token=' . $tok;
            $from      = setting('smtp_from_email', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

            $subject = $siteName . ' — Şifre Sıfırlama';
            $body    = "Merhaba,\n\nŞifrenizi sıfırlamak için:\n\n$resetLink\n\nBu bağlantı 1 saat geçerlidir.\n\n$siteName";
            @mail($email, $subject, $body, "From: $from\r\nContent-Type: text/plain; charset=UTF-8");
        }
        $step = 'sent';
    }

    // Adım 2 — yeni şifre kaydet
    if (isset($_POST['new_password']) && $token) {
        $pass  = $_POST['new_password']  ?? '';
        $pass2 = $_POST['new_password2'] ?? '';
        if (strlen($pass) < 6) {
            $error = 'Şifre en az 6 karakter olmalı.';
            $step  = 'reset';
        } elseif ($pass !== $pass2) {
            $error = 'Şifreler eşleşmiyor.';
            $step  = 'reset';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $dr = dbRow("SELECT id FROM b2b_dealers     WHERE reset_token=?", [$token]);
            $ar = dbRow("SELECT id FROM b2b_admin_users WHERE reset_token=?", [$token]);
            if ($dr) dbExec("UPDATE b2b_dealers     SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?", [$hash, $dr['id']]);
            if ($ar) dbExec("UPDATE b2b_admin_users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?", [$hash, $ar['id']]);
            $step = 'done';
        }
    }
}

$siteName = setting('site_name', 'B2B Portal');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Şifre Sıfırla — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:'Inter',-apple-system,sans-serif;font-size:14px;background:#f4f5f7;color:#1a1d2e}
.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.box{width:100%;max-width:420px}
.logo{text-align:center;margin-bottom:28px}
.logo-mark{width:48px;height:48px;background:linear-gradient(135deg,#ed2939,#c41f2e);border-radius:12px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#fff}
.logo h1{font-size:18px;font-weight:700;color:#1a1d2e}
.logo p{color:#6b7280;font-size:13px;margin-top:4px}
.card{background:#fff;border:1px solid #e4e6ea;border-radius:12px;padding:28px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:13px;font-weight:500;margin-bottom:6px;color:#374151}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;outline:none;transition:border-color .15s}
.form-control:focus{border-color:#ed2939;box-shadow:0 0 0 3px rgba(237,41,57,.1)}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:500;font-family:inherit;cursor:pointer;text-decoration:none;border:1px solid transparent;transition:all .15s;width:100%}
.btn-primary{background:#ed2939;color:#fff;border-color:#ed2939}
.btn-primary:hover{background:#c41f2e}
.btn-ghost{background:transparent;color:#6b7280;border-color:#e4e6ea;margin-top:10px}
.btn-ghost:hover{background:#f4f5f7}
.alert{padding:12px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;border:1px solid transparent}
.alert-danger{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
.icon{font-size:40px;text-align:center;margin-bottom:16px}
.success-text{text-align:center;color:#374151}
.success-text h2{font-size:18px;font-weight:700;margin-bottom:8px}
.success-text p{color:#6b7280;font-size:13px;line-height:1.6}
.back{text-align:center;margin-top:16px;font-size:13px}
.back a{color:#ed2939;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
<div class="box">
  <div class="logo">
    <div class="logo-mark">B2</div>
    <h1><?= h($siteName) ?></h1>
    <p>Şifre Sıfırlama</p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($step === 'sent'): ?>
  <div class="card">
    <div class="icon">📧</div>
    <div class="success-text">
      <h2>E-posta Gönderildi</h2>
      <p>Kayıtlı e-posta adresinize şifre sıfırlama bağlantısı gönderdik. Gelen kutunuzu kontrol edin.</p>
    </div>
    <a href="/?page=login" class="btn btn-ghost" style="margin-top:20px">← Girişe Dön</a>
  </div>

  <?php elseif ($step === 'reset'): ?>
  <div class="card">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:20px">Yeni Şifre Belirle</h2>
    <form method="POST" action="?page=forgot-password&token=<?= h($token) ?>">
      <?= csrfField() ?>
      <div class="form-group">
        <label class="form-label">Yeni Şifre</label>
        <input type="password" name="new_password" class="form-control" required minlength="6" placeholder="En az 6 karakter" autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Şifre Tekrar</label>
        <input type="password" name="new_password2" class="form-control" required placeholder="Tekrar girin">
      </div>
      <button type="submit" class="btn btn-primary">Şifremi Güncelle</button>
    </form>
  </div>

  <?php elseif ($step === 'done'): ?>
  <div class="card">
    <div class="icon">✅</div>
    <div class="success-text">
      <h2>Şifre Güncellendi</h2>
      <p>Yeni şifrenizle giriş yapabilirsiniz.</p>
    </div>
    <a href="/?page=login" class="btn btn-primary" style="margin-top:20px">Giriş Yap</a>
  </div>

  <?php else: ?>
  <div class="card">
    <h2 style="font-size:16px;font-weight:700;margin-bottom:6px">Şifremi Unuttum</h2>
    <p style="color:#6b7280;font-size:13px;margin-bottom:20px">
      Kayıtlı e-posta adresinizi girin, sıfırlama bağlantısı gönderelim.
    </p>
    <form method="POST">
      <?= csrfField() ?>
      <div class="form-group">
        <label class="form-label">E-posta Adresi</label>
        <input type="email" name="email" class="form-control" required placeholder="bayi@firma.com" autofocus>
      </div>
      <button type="submit" class="btn btn-primary">Sıfırlama Bağlantısı Gönder</button>
    </form>
  </div>
  <div class="back"><a href="/?page=login">← Girişe Dön</a></div>
  <?php endif; ?>

</div>
</div>
</body>
</html>
