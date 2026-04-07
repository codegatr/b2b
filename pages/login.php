<?php
// pages/login.php — Bayi Girişi
if (isset($_SESSION['dealer_id'])) {
    header('Location: ?page=dashboard'); exit;
}

$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');

    if (!$email || !$pass) {
        $error = 'E-posta ve şifre zorunludur.';
    } else {
        $dealer = dbRow("SELECT * FROM b2b_dealers WHERE email=? AND is_active=1", [$email]);
        if ($dealer && password_verify($pass, $dealer['password_hash'])) {
            dealerLogin($dealer);
            header('Location: ?page=dashboard'); exit;
        } else {
            $error = 'E-posta veya şifre hatalı, ya da hesabınız aktif değil.';
        }
    }
}

$siteName = setting('site_name', 'B2B Portal');
$version  = file_exists(dirname(__DIR__).'/version.txt') ? trim(file_get_contents(dirname(__DIR__).'/version.txt')) : '1.0.0';

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Giriş &mdash; <?= htmlspecialchars($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0d0f17;--surface:#13161f;--elevated:#1a1e2e;
  --border:#2a2f45;--text:#e2e8f0;--muted:#8892aa;--faint:#4a5270;
  --primary:#6366f1;--primary-h:#5254cc;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:'Inter',-apple-system,sans-serif;font-size:14px;background:var(--bg);color:var(--text);line-height:1.6}

/* Genel layout */
.login-wrap{display:grid;grid-template-columns:1fr 1fr;min-height:100vh}

/* Sol panel */
.login-brand{
  position:relative;background:var(--surface);border-right:1px solid var(--border);
  display:flex;flex-direction:column;padding:48px;overflow:hidden
}
.login-brand::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background-image:linear-gradient(rgba(99,102,241,.06) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(99,102,241,.06) 1px,transparent 1px);
  background-size:40px 40px
}
.login-brand::after{
  content:'';position:absolute;top:-160px;left:-160px;width:600px;height:600px;
  background:radial-gradient(ellipse at center,rgba(99,102,241,.18) 0%,transparent 65%);
  pointer-events:none
}

/* Logo */
.brand-logo{display:flex;align-items:center;gap:12px;margin-bottom:64px;position:relative;z-index:1}
.brand-logo-mark{
  width:40px;height:40px;background:var(--primary);border-radius:10px;
  display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;color:#fff;
  box-shadow:0 0 0 1px rgba(99,102,241,.4),0 4px 20px rgba(99,102,241,.3);flex-shrink:0
}
.brand-logo-text{font-weight:700;font-size:16px;letter-spacing:-.01em}
.brand-logo-sub{font-size:11px;color:var(--faint);margin-top:1px}

/* Hero */
.brand-hero{position:relative;z-index:1;margin-bottom:auto}
.brand-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);
  color:var(--primary);border-radius:99px;padding:4px 12px;
  font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-bottom:24px
}
.brand-badge::before{
  content:'';width:6px;height:6px;background:var(--primary);border-radius:50%;
  animation:blink 2s infinite
}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}

.brand-title{font-size:2.25rem;font-weight:800;line-height:1.2;letter-spacing:-.03em;margin-bottom:16px}
.brand-title span{
  background:linear-gradient(135deg,#818cf8,#6366f1 40%,#a78bfa);
  -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent
}
.brand-desc{font-size:.9375rem;color:var(--muted);line-height:1.7;max-width:360px}

/* Onizleme kartı */
.brand-preview{
  position:relative;z-index:1;margin-top:40px;
  background:var(--elevated);border:1px solid var(--border);border-radius:14px;
  padding:16px 18px;display:flex;align-items:center;gap:14px
}
.preview-avatar{
  width:38px;height:38px;border-radius:10px;
  background:linear-gradient(135deg,#818cf8,#6366f1);
  display:flex;align-items:center;justify-content:center;
  font-weight:700;font-size:14px;color:#fff;flex-shrink:0
}
.preview-info{flex:1;min-width:0}
.preview-name{font-weight:600;font-size:.8125rem}
.preview-sub{font-size:.75rem;color:var(--muted)}
.preview-badge{
  background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.2);
  border-radius:6px;padding:3px 8px;font-size:.7rem;font-weight:600
}

/* Ozellikler */
.brand-features{position:relative;z-index:1;margin-top:40px;display:flex;flex-direction:column;gap:14px}
.feature-item{display:flex;align-items:center;gap:12px;font-size:.8125rem;color:var(--muted)}
.feature-icon{
  width:30px;height:30px;background:var(--elevated);border:1px solid var(--border);
  border-radius:8px;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;color:var(--primary)
}

/* Versiyon */
.brand-footer{
  position:relative;z-index:1;margin-top:40px;font-size:.75rem;color:var(--faint);
  display:flex;align-items:center;gap:8px
}
.brand-footer::before{content:'';flex:1;height:1px;background:var(--border)}

/* Sag panel */
.login-form-wrap{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:48px 56px;position:relative
}
.login-form-wrap::before{
  content:'';position:absolute;bottom:-200px;right:-200px;width:500px;height:500px;
  background:radial-gradient(ellipse at center,rgba(99,102,241,.08) 0%,transparent 65%);
  pointer-events:none
}
.login-form-inner{width:100%;max-width:400px;position:relative;z-index:1}

/* Baslik */
.form-heading{margin-bottom:32px}
.form-heading h2{font-size:1.625rem;font-weight:800;letter-spacing:-.02em;margin-bottom:6px}
.form-heading p{font-size:.875rem;color:var(--muted)}

/* Alert */
.login-alert{
  display:flex;align-items:flex-start;gap:10px;padding:12px 14px;
  border-radius:10px;font-size:.8125rem;margin-bottom:20px;
  animation:slideDown .2s ease
}
@keyframes slideDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.login-alert.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#fca5a5}
.login-alert.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:#86efac}
.login-alert svg{flex-shrink:0;margin-top:1px}

/* Form */
.f-group{margin-bottom:16px}
.f-label{display:block;font-size:.8125rem;font-weight:500;color:var(--muted);margin-bottom:6px;letter-spacing:.01em}
.f-input-wrap{position:relative}
.f-icon{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--faint);pointer-events:none;transition:color .15s
}
.f-input{
  width:100%;height:46px;background:var(--surface);border:1px solid var(--border);
  border-radius:10px;padding:0 14px 0 42px;color:var(--text);
  font-family:inherit;font-size:.9rem;transition:border-color .15s,box-shadow .15s;
  outline:none;appearance:none
}
.f-input::placeholder{color:var(--faint)}
.f-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.f-input-wrap:focus-within .f-icon{color:var(--primary)}

.f-icon-right{
  position:absolute;right:14px;top:50%;transform:translateY(-50%);
  color:var(--faint);cursor:pointer;background:none;border:none;
  padding:4px;border-radius:6px;transition:color .15s;line-height:0
}
.f-icon-right:hover{color:var(--muted)}

.f-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.f-forgot{font-size:.8rem;color:var(--muted);text-decoration:none;transition:color .15s}
.f-forgot:hover{color:var(--text)}

/* Submit */
.btn-submit{
  width:100%;height:48px;background:var(--primary);color:#fff;border:none;
  border-radius:10px;font-family:inherit;font-size:.9375rem;font-weight:600;
  cursor:pointer;transition:background .15s,transform .1s,box-shadow .15s;
  display:flex;align-items:center;justify-content:center;gap:8px;
  margin-top:8px;letter-spacing:.01em;box-shadow:0 4px 16px rgba(99,102,241,.35)
}
.btn-submit:hover{background:var(--primary-h);box-shadow:0 6px 24px rgba(99,102,241,.45)}
.btn-submit:active{transform:scale(.98)}
.btn-arrow{transition:transform .2s}
.btn-submit:hover .btn-arrow{transform:translateX(3px)}

/* Ayraç */
.divider{display:flex;align-items:center;gap:12px;margin:24px 0;color:var(--faint);font-size:.75rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}

/* Başvuru */
.btn-apply{
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%;height:46px;background:transparent;border:1px solid var(--border);
  border-radius:10px;color:var(--muted);font-family:inherit;font-size:.875rem;
  font-weight:500;text-decoration:none;cursor:pointer;
  transition:border-color .15s,color .15s,background .15s
}
.btn-apply:hover{border-color:var(--primary);color:var(--text);background:rgba(99,102,241,.06);text-decoration:none}

/* Alt bilgi */
.form-footer{text-align:center;margin-top:32px;font-size:.75rem;color:var(--faint);line-height:1.8}
.form-footer a{color:var(--muted);text-decoration:none;transition:color .15s}
.form-footer a:hover{color:var(--text)}

/* Mobil */
@media(max-width:900px){
  .login-wrap{grid-template-columns:1fr}
  .login-brand{display:none}
  .login-form-wrap{padding:40px 24px}
}
</style>
</head>
<body>
<div class="login-wrap">

  <!-- SOL PANEL -->
  <aside class="login-brand">
    <div class="brand-logo">
      <div class="brand-logo-mark">B</div>
      <div>
        <div class="brand-logo-text"><?= htmlspecialchars($siteName) ?></div>
        <div class="brand-logo-sub">Bayi Yonetim Sistemi</div>
      </div>
    </div>

    <div class="brand-hero">
      <div class="brand-badge">Bayi Portali</div>
      <h1 class="brand-title">Bayilik<br><span>tek merkezden</span><br>yonetilir.</h1>
      <p class="brand-desc">Siparisleri verin, stoku takip edin, cari hesabinizi anlik goruntuleyip yonetin.</p>
    </div>

    <div class="brand-preview">
      <div class="preview-avatar">A</div>
      <div class="preview-info">
        <div class="preview-name">Aksoy Ticaret A.S.</div>
        <div class="preview-sub">Siparis #SIP-2025-0482 onaylandi</div>
      </div>
      <div class="preview-badge">Onaylandi</div>
    </div>

    <div class="brand-features">
      <div class="feature-item">
        <div class="feature-icon">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        </div>
        Gercek zamanli siparis ve stok takibi
      </div>
      <div class="feature-item">
        <div class="feature-icon">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        </div>
        Bayiye ozel fiyat listeleri
      </div>
      <div class="feature-item">
        <div class="feature-icon">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        Cari hesap ve ekstre goruntulemesi
      </div>
    </div>

    <div class="brand-footer">v<?= $version ?>&nbsp;&middot;&nbsp;CODEGA B2B</div>
  </aside>

  <!-- SAG PANEL -->
  <main class="login-form-wrap">
    <div class="login-form-inner">
      <div class="form-heading">
        <h2>Hos Geldiniz</h2>
        <p>Bayi hesabiniza giris yapin.</p>
      </div>

      <?php if ($error): ?>
      <div class="login-alert error">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <?php if (isset($_GET['applied'])): ?>
      <div class="login-alert success">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Basvurunuz alindi. Onay surecinde bilgilendirileceksiniz.
      </div>
      <?php endif; ?>

      <?php if (isset($_GET['reset'])): ?>
      <div class="login-alert success">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Sifreniz guncellendi. Giris yapabilirsiniz.
      </div>
      <?php endif; ?>

      <form method="POST" id="loginForm">
        <?= csrfField() ?>

        <div class="f-group">
          <label class="f-label" for="email">E-posta Adresi</label>
          <div class="f-input-wrap">
            <span class="f-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </span>
            <input type="email" id="email" name="email" class="f-input"
                   placeholder="bayi@sirket.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   autofocus required autocomplete="username">
          </div>
        </div>

        <div class="f-group">
          <div class="f-row">
            <label class="f-label" for="password" style="margin-bottom:0">Sifre</label>
            <a href="pages/forgot-password.php" class="f-forgot">Sifremi Unuttum</a>
          </div>
          <div class="f-input-wrap">
            <span class="f-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            </span>
            <input type="password" id="password" name="password" class="f-input"
                   placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                   required autocomplete="current-password">
            <button type="button" class="f-icon-right" onclick="togglePass()" title="Goster/Gizle">
              <svg id="eyeIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">
          <span id="btnText">Giris Yap</span>
          <svg class="btn-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>

      <div class="divider">veya</div>

      <a href="?page=apply" class="btn-apply">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
        Bayilik Basvurusu Yap
      </a>

      <div class="form-footer">
        <?= htmlspecialchars($siteName) ?> &middot; Bayi Portali<br>
        <a href="#">Gizlilik Politikasi</a> &nbsp;&middot;&nbsp; <a href="#">Kullanim Kosullari</a>
      </div>
    </div>
  </main>

</div>

<script>
function togglePass() {
  const inp = document.getElementById('password');
  const ico = document.getElementById('eyeIcon');
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.innerHTML = show
    ? '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}

document.getElementById('loginForm').addEventListener('submit', function() {
  const btn = document.getElementById('submitBtn');
  document.getElementById('btnText').textContent = 'Giris yapiliyor...';
  btn.disabled = true;
  btn.style.opacity = '.75';
});
</script>
</body>
</html>
<?php exit; ?>
