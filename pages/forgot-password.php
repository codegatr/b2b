<?php
// Standalone sayfa — index.php dışında çalışır
session_start();
require_once __DIR__ . '/../includes/db.php';

$step    = 'request'; // request | sent | reset | done
$error   = '';
$success = '';
$token   = $_GET['token'] ?? '';

// Token varsa reset adımı
if ($token) {
    $step = 'reset';
    $row  = $pdo->prepare("SELECT * FROM b2b_dealers WHERE reset_token=? AND reset_expires > NOW() AND is_active=1");
    $row->execute([$token]);
    $dealer = $row->fetch();
    if (!$dealer) {
        $step  = 'request';
        $error = 'Bu bağlantı geçersiz veya süresi dolmuş.';
        $token = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Adım 1: E-posta gönder
    if (isset($_POST['email'])) {
        $email = trim($_POST['email']);
        $d = $pdo->prepare("SELECT * FROM b2b_dealers WHERE email=? AND is_active=1");
        $d->execute([$email]);
        $d = $d->fetch();
        // Güvenlik: her zaman aynı mesajı göster
        if ($d) {
            $tok     = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $pdo->prepare("UPDATE b2b_dealers SET reset_token=?, reset_expires=? WHERE id=?")
                ->execute([$tok, $expires, $d['id']]);

            // E-posta gönder
            $settings = [];
            $rows = $pdo->query("SELECT skey, svalue FROM b2b_settings")->fetchAll();
            foreach ($rows as $r) $settings[$r['skey']] = $r['svalue'];

            $siteName   = $settings['site_name'] ?? 'B2B Portal';
            $siteUrl    = rtrim($settings['site_url'] ?? 'http://localhost', '/');
            $resetLink  = $siteUrl . '/pages/forgot-password.php?token=' . $tok;

            $subject = $siteName . ' - Şifre Sıfırlama';
            $body    = "Merhaba {$d['company_name']},\n\nŞifrenizi sıfırlamak için aşağıdaki bağlantıya tıklayın:\n\n{$resetLink}\n\nBu bağlantı 1 saat geçerlidir.\n\nİyi çalışmalar,\n{$siteName}";

            $headers = "From: {$settings['smtp_from_email']}\r\nContent-Type: text/plain; charset=UTF-8";
            @mail($d['email'], $subject, $body, $headers);
        }
        $step = 'sent';
    }

    // Adım 2: Yeni şifre kaydet
    if (isset($_POST['new_password']) && $token) {
        $pass  = $_POST['new_password'];
        $pass2 = $_POST['new_password2'];
        if (strlen($pass) < 6) {
            $error = 'Şifre en az 6 karakter olmalı.';
        } elseif ($pass !== $pass2) {
            $error = 'Şifreler eşleşmiyor.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE b2b_dealers SET password=?, reset_token=NULL, reset_expires=NULL WHERE reset_token=?")
                ->execute([$hash, $token]);
            $step = 'done';
        }
    }
}

// DB'de reset_token kolonu yoksa ekle (güvenlik)
try {
    $pdo->query("SELECT reset_token FROM b2b_dealers LIMIT 1");
} catch (\Exception $e) {
    $pdo->query("ALTER TABLE b2b_dealers ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_expires DATETIME NULL");
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Şifre Sıfırla</title>
<link rel="stylesheet" href="/assets/css/main.css">
<style>
body { background:var(--bg);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem }
.auth-box { width:100%;max-width:420px }
.auth-logo { text-align:center;margin-bottom:2rem }
.auth-logo span { font-size:1.5rem;font-weight:800;color:var(--primary) }
</style>
</head>
<body>
<div class="auth-box">
    <div class="auth-logo">
        <span>🔐 Şifre Sıfırla</span>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'sent'): ?>
    <div class="card" style="padding:2rem;text-align:center">
        <div style="font-size:3rem;margin-bottom:1rem">📧</div>
        <h2 style="margin-bottom:.5rem">E-posta Gönderildi</h2>
        <p style="color:var(--text-muted);font-size:.9rem">
            Kayıtlı e-posta adresinize şifre sıfırlama bağlantısı gönderdik.
            Gelen kutunuzu kontrol edin.
        </p>
        <a href="/pages/login.php" class="btn btn-secondary" style="margin-top:1.5rem">Girişe Dön</a>
    </div>

    <?php elseif ($step === 'reset'): ?>
    <div class="card" style="padding:2rem">
        <h2 style="margin-bottom:1.5rem;font-size:1.25rem">Yeni Şifre Belirle</h2>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Yeni Şifre</label>
                <input type="password" name="new_password" class="form-control" required minlength="6" placeholder="En az 6 karakter">
            </div>
            <div class="form-group">
                <label class="form-label">Şifre Tekrar</label>
                <input type="password" name="new_password2" class="form-control" required placeholder="Tekrar girin">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Şifremi Güncelle</button>
        </form>
    </div>

    <?php elseif ($step === 'done'): ?>
    <div class="card" style="padding:2rem;text-align:center">
        <div style="font-size:3rem;margin-bottom:1rem">✅</div>
        <h2 style="margin-bottom:.5rem">Şifre Güncellendi</h2>
        <p style="color:var(--text-muted);font-size:.9rem">
            Yeni şifrenizle giriş yapabilirsiniz.
        </p>
        <a href="/pages/login.php" class="btn btn-primary" style="margin-top:1.5rem">Giriş Yap</a>
    </div>

    <?php else: ?>
    <div class="card" style="padding:2rem">
        <h2 style="margin-bottom:.5rem;font-size:1.25rem">Şifremi Unuttum</h2>
        <p style="color:var(--text-muted);font-size:.875rem;margin-bottom:1.5rem">
            Kayıtlı e-posta adresinizi girin, sıfırlama bağlantısı gönderelim.
        </p>
        <form method="POST">
            <div class="form-group">
                <label class="form-label">E-posta Adresi</label>
                <input type="email" name="email" class="form-control" required placeholder="bayi@firma.com" autofocus>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Sıfırlama Bağlantısı Gönder</button>
        </form>
        <div style="text-align:center;margin-top:1.25rem">
            <a href="/pages/login.php" style="color:var(--text-muted);font-size:.875rem">← Girişe Dön</a>
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
