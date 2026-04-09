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

            $subject  = $siteName . ' - Sifre Sifirlama';
            $logoFile = setting('login_image', '');
            $logoHtml = $logoFile
                ? '<img src="' . $siteUrl . '/uploads/logo/' . $logoFile . '" alt="' . htmlspecialchars($siteName) . '" style="height:72px;max-width:220px;object-fit:contain;display:block;margin:0 auto">'
                : '<span style="font-size:26px;font-weight:800;color:#ffffff;letter-spacing:-0.5px">' . htmlspecialchars($siteName) . '</span>';

            $html = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>'
                . '<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif">'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:40px 16px">'
                . '<tr><td align="center">'
                . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">'

                /* ── Header ── */
                . '<tr><td style="background:#1e3a5f;border-radius:12px 12px 0 0;padding:32px 40px;text-align:center;border-bottom:4px solid #dc2626">'
                . $logoHtml
                . '<div style="margin-top:14px;font-size:11px;font-weight:600;letter-spacing:2px;color:rgba(255,255,255,.5);text-transform:uppercase">Bayi Portali</div>'
                . '</td></tr>'

                /* ── Body ── */
                . '<tr><td style="background:#ffffff;padding:44px 52px">'
                . '<h2 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1a1d23">'
                . mb_convert_encoding('&#350;ifre S&#305;f&#305;rlama', 'UTF-8', 'HTML-ENTITIES')
                . '</h2>'
                . '<div style="width:40px;height:3px;background:#dc2626;border-radius:2px;margin-bottom:24px"></div>'
                . '<p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.8">'
                . 'Merhaba,<br><br>'
                . mb_convert_encoding('&#350;ifre s&#305;f&#305;rlama talebinizi ald&#305;k. A&#351;a&#287;&#305;daki butona t&#305;klayarak yeni &#351;ifrenizi belirleyebilirsiniz.', 'UTF-8', 'HTML-ENTITIES')
                . '</p>'
                . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:8px 0 32px">'
                . '<a href="' . $resetLink . '" style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;padding:16px 44px;border-radius:8px;letter-spacing:0.3px">'
                . mb_convert_encoding('&#350;ifremi S&#305;f&#305;rla', 'UTF-8', 'HTML-ENTITIES')
                . '</a></td></tr></table>'
                . '<p style="margin:0 0 6px;font-size:13px;color:#6b7280">'
                . mb_convert_encoding('Butona t&#305;klayam&#305;yorsan&#305;z a&#351;a&#287;&#305;daki ba&#287;lant&#305;y&#305; taray&#305;c&#305;n&#305;za yap&#305;&#351;t&#305;r&#305;n:', 'UTF-8', 'HTML-ENTITIES')
                . '</p>'
                . '<p style="margin:0 0 28px;font-size:12px;word-break:break-all"><a href="' . $resetLink . '" style="color:#2563eb">' . $resetLink . '</a></p>'
                . '<div style="background:#fef9f0;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:14px 18px">'
                . '<p style="margin:0;font-size:13px;color:#92400e">'
                . mb_convert_encoding('&#9888;&#65039; Bu ba&#287;lant&#305; <strong>1 saat</strong> ge&#231;erlidir. Bu talebi siz yapmad&#305;ysan&#305;z bu e-postay&#305; dikkate almay&#305;n.', 'UTF-8', 'HTML-ENTITIES')
                . '</p></div>'
                . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:32px 0">'
                . '<p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.6">'
                . mb_convert_encoding('Bu e-posta <strong>' . htmlspecialchars($siteName) . '</strong> B2B Bayi Portal&#305; taraf&#305;ndan otomatik olarak g&#246;nderilmi&#351;tir.', 'UTF-8', 'HTML-ENTITIES')
                . '</p>'
                . '</td></tr>'

                /* ── Footer ── */
                . '<tr><td style="background:#f8fafc;border-radius:0 0 12px 12px;padding:20px 40px;text-align:center;border-top:1px solid #e5e7eb">'
                . '<p style="margin:0;font-size:12px;color:#6b7280">&copy; ' . date('Y') . ' ' . htmlspecialchars($siteName) . '</p>'
                . '</td></tr>'

                . '</table></td></tr></table></body></html>';

            $headers = implode("\r\n", [
                'From: ' . $siteName . ' <' . $from . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
            ]);
            @mail($email, $subject, $html, $headers);
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
