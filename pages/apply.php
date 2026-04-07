<?php
// pages/apply.php — Bayilik Başvurusu
$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $type     = $_POST['applicant_type'] ?? 'kurumsal';
    $company  = trim($_POST['company_name'] ?? '');
    $contact  = trim($_POST['contact_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $taxNo    = trim($_POST['tax_number'] ?? '');
    $taxOff   = trim($_POST['tax_office'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $city     = trim($_POST['city'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    if (!$company || !$email || !$phone) {
        $error = 'Firma/Ad Soyad, e-posta ve telefon zorunludur.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir e-posta girin.';
    } elseif (dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE email=?", [$email]) > 0) {
        $error = 'Bu e-posta adresiyle zaten bir bayi hesabı mevcut.';
    } elseif (dbVal("SELECT COUNT(*) FROM b2b_applications WHERE email=? AND status='bekliyor'", [$email]) > 0) {
        $error = 'Bu e-posta ile bekleyen bir başvurunuz bulunmakta.';
    } else {
        dbInsertRow('b2b_applications', [
            'applicant_type' => $type,
            'company_name'   => $company,
            'contact_name'   => $contact,
            'email'          => $email,
            'phone'          => $phone,
            'tax_number'     => $taxNo,
            'tax_office'     => $taxOff,
            'address'        => $address,
            'city'           => $city,
            'notes'          => $notes,
            'status'         => 'bekliyor',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        // Admin bildirim
        notifyAdmin('Yeni Bayilik Başvurusu', "$company başvuruda bulundu.", 'application', 0);
        $success = true;
    }
}

$siteName = setting('site_name', 'Le Monde Du Tacos B2B');
$version  = file_exists(dirname(__DIR__).'/version.txt') ? trim(file_get_contents(dirname(__DIR__).'/version.txt')) : '1.0.0';
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bayilik Basvurusu &mdash; <?= htmlspecialchars($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;font-family:'Inter',-apple-system,sans-serif;font-size:14px;line-height:1.6}
:root{
  --green:#3A5F0B;--green-d:#2a4508;--green-l:#4e7d10;
  --red:#b24545;--red-l:#c95555;
  --ink:#1f2937;--muted:#6b7280;
  --bg-dark:#111a0a;--cream:#f5f0e8;--cream-d:#ede6d6;
}

/* Layout */
.wrap{display:grid;grid-template-columns:380px 1fr;min-height:100vh}

/* Sol panel */
.brand{
  background:var(--bg-dark);position:relative;overflow:hidden;
  display:flex;flex-direction:column;padding:44px;
}
.brand::before{
  content:'';position:absolute;inset:0;pointer-events:none;
  background-image:
    radial-gradient(circle at 15% 85%,rgba(58,95,11,.35) 0%,transparent 50%),
    radial-gradient(circle at 85% 15%,rgba(178,69,69,.18) 0%,transparent 45%),
    linear-gradient(rgba(58,95,11,.04) 1px,transparent 1px),
    linear-gradient(90deg,rgba(58,95,11,.04) 1px,transparent 1px);
  background-size:100% 100%,100% 100%,36px 36px,36px 36px;
}
.blogo{position:relative;z-index:2;display:flex;align-items:center;gap:12px;margin-bottom:56px}
.blogo-ic{
  width:42px;height:42px;border-radius:11px;
  background:linear-gradient(135deg,var(--green),var(--green-d));
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 0 0 1px rgba(255,255,255,.1),0 4px 18px rgba(0,0,0,.4)
}
.blogo-n{font-size:.85rem;font-weight:700;color:#fff;line-height:1.2}
.blogo-s{font-size:.65rem;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em}

/* Sol içerik */
.brand-body{position:relative;z-index:2;flex:1;display:flex;flex-direction:column;justify-content:center}
.step-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(178,69,69,.15);border:1px solid rgba(178,69,69,.25);
  color:#c95555;border-radius:99px;padding:4px 12px;
  font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
  margin-bottom:20px;width:fit-content;
}
.brand-body h2{
  font-family:'Playfair Display',Georgia,serif;
  font-size:2rem;font-weight:900;color:#fff;
  line-height:1.2;letter-spacing:-.02em;margin-bottom:14px;
}
.brand-body h2 em{font-style:normal;color:var(--red-l)}
.brand-body p{font-size:.85rem;color:rgba(255,255,255,.42);line-height:1.7;max-width:280px}

/* Adimlar */
.steps{display:flex;flex-direction:column;gap:16px;margin-top:36px}
.step{display:flex;align-items:flex-start;gap:12px}
.step-num{
  width:28px;height:28px;border-radius:50%;
  background:rgba(58,95,11,.3);border:1px solid rgba(58,95,11,.5);
  display:flex;align-items:center;justify-content:center;
  font-size:.72rem;font-weight:700;color:rgba(255,255,255,.7);flex-shrink:0;
}
.step-info{padding-top:4px}
.step-title{font-size:.8rem;font-weight:600;color:rgba(255,255,255,.75);margin-bottom:2px}
.step-desc{font-size:.72rem;color:rgba(255,255,255,.3)}

.brand-foot{
  position:relative;z-index:2;margin-top:36px;
  font-size:.68rem;color:rgba(255,255,255,.18);
  display:flex;align-items:center;gap:7px;
}
.brand-foot::before{content:'';flex:1;height:1px;background:rgba(255,255,255,.07)}

/* Sag panel */
.form-panel{
  background:var(--cream);
  display:flex;flex-direction:column;align-items:center;justify-content:flex-start;
  padding:52px 64px;overflow-y:auto;
}
.form-panel::before{
  content:'';position:fixed;top:-80px;right:-80px;
  width:260px;height:260px;border-radius:50%;
  background:radial-gradient(circle,rgba(58,95,11,.07),transparent 70%);
  pointer-events:none;
}
.fi{width:100%;max-width:480px}

/* Tip secici */
.type-tabs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:28px}
.type-tab{
  border:2px solid #d1d5db;border-radius:12px;
  padding:14px 16px;cursor:pointer;background:#fff;
  transition:border-color .15s,background .15s;text-align:left;
}
.type-tab:hover{border-color:var(--green);background:rgba(58,95,11,.03)}
.type-tab.active{border-color:var(--green);background:rgba(58,95,11,.06)}
.type-tab-icon{font-size:1.4rem;margin-bottom:6px}
.type-tab-title{font-weight:700;font-size:.85rem;color:var(--ink)}
.type-tab-sub{font-size:.72rem;color:var(--muted);margin-top:2px}

/* Form elemanlar */
.fg{margin-bottom:14px}
.fg-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fl{display:block;font-size:.78rem;font-weight:600;color:var(--ink);margin-bottom:5px;letter-spacing:.01em}
.fl span{color:var(--red)}
.fiw{position:relative}
.fi-ico{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;transition:color .15s}
.fi-inp{
  width:100%;height:46px;background:#fff;border:1.5px solid #d1d5db;
  border-radius:10px;padding:0 14px 0 42px;color:var(--ink);
  font-family:inherit;font-size:.875rem;outline:none;
  transition:border-color .15s,box-shadow .15s;
}
.fi-inp::placeholder{color:#c0bfbc}
.fi-inp:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(58,95,11,.12)}
.fiw:focus-within .fi-ico{color:var(--green)}
.fi-inp.no-icon{padding-left:14px}
textarea.fi-inp{height:88px;padding-top:12px;padding-left:14px;resize:vertical}

/* Section baslik */
.sec-title{
  font-size:.7rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.08em;color:var(--green);margin:22px 0 14px;
  display:flex;align-items:center;gap:8px;
}
.sec-title::after{content:'';flex:1;height:1px;background:rgba(58,95,11,.2)}

/* Alert */
.f-alert{
  display:flex;align-items:flex-start;gap:10px;
  padding:12px 14px;border-radius:10px;font-size:.8125rem;
  margin-bottom:20px;animation:sd .2s ease;
}
@keyframes sd{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}
.f-alert.err{background:rgba(178,69,69,.1);border:1px solid rgba(178,69,69,.25);color:#8f3535}
.f-alert.ok{background:rgba(58,95,11,.1);border:1px solid rgba(58,95,11,.25);color:#2a4508}
.f-alert svg{flex-shrink:0;margin-top:1px}

/* Submit */
.btn-sub{
  width:100%;height:50px;background:var(--green);color:#fff;border:none;
  border-radius:10px;font-family:inherit;font-size:.9375rem;font-weight:700;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
  margin-top:14px;letter-spacing:.01em;
  transition:background .15s,transform .1s,box-shadow .15s;
  box-shadow:0 4px 16px rgba(58,95,11,.35);
}
.btn-sub:hover{background:var(--green-l);box-shadow:0 6px 24px rgba(58,95,11,.4)}
.btn-sub:active{transform:scale(.98)}
.arr{transition:transform .2s}
.btn-sub:hover .arr{transform:translateX(3px)}

/* Giris linki */
.back-link{
  display:flex;align-items:center;gap:6px;margin-bottom:28px;
  color:var(--muted);text-decoration:none;font-size:.8125rem;
  transition:color .15s;
}
.back-link:hover{color:var(--ink);text-decoration:none}

.form-head{margin-bottom:24px}
.form-head .wel{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--green);margin-bottom:7px}
.form-head h1{font-family:'Playfair Display',Georgia,serif;font-size:1.75rem;font-weight:900;color:var(--ink);letter-spacing:-.02em;margin-bottom:5px}
.form-head p{font-size:.82rem;color:var(--muted)}

/* Basarili ekran */
.success-wrap{text-align:center;padding:40px 20px}
.success-icon{
  width:72px;height:72px;background:rgba(58,95,11,.12);border:2px solid rgba(58,95,11,.25);
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;color:var(--green);
}
.success-wrap h2{font-family:'Playfair Display',Georgia,serif;font-size:1.6rem;font-weight:900;color:var(--ink);margin-bottom:10px}
.success-wrap p{font-size:.875rem;color:var(--muted);line-height:1.7;max-width:320px;margin:0 auto 28px}
.btn-back{
  display:inline-flex;align-items:center;gap:8px;
  height:48px;padding:0 28px;background:var(--green);color:#fff;border:none;
  border-radius:10px;font-family:inherit;font-size:.9rem;font-weight:600;
  text-decoration:none;cursor:pointer;
  box-shadow:0 4px 16px rgba(58,95,11,.3);transition:background .15s;
}
.btn-back:hover{background:var(--green-l);text-decoration:none}

@media(max-width:860px){
  .wrap{grid-template-columns:1fr}
  .brand{display:none}
  .form-panel{padding:36px 20px;background:#fff}
  .fg-row{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">

<!-- SOL PANEL -->
<aside class="brand">
  <div class="blogo">
    <div class="blogo-ic">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>
    </div>
    <div><div class="blogo-n"><?= htmlspecialchars($siteName) ?></div><div class="blogo-s">Bayi Portali</div></div>
  </div>

  <div class="brand-body">
    <div class="step-badge">Yeni Basvuru</div>
    <h2>Bayimiz<br>olun, <em>birlikte<br>buyuyelim.</em></h2>
    <p>Fransa'nin efsanevi lezzetini musterilerinize sunma firsatini kacirmayin.</p>

    <div class="steps">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-info">
          <div class="step-title">Formu doldurun</div>
          <div class="step-desc">Firma bilgilerinizi girin</div>
        </div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-info">
          <div class="step-title">Inceleme sureci</div>
          <div class="step-desc">Ekibimiz 1-2 is gununde inceler</div>
        </div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-info">
          <div class="step-title">Hesabiniz aktif</div>
          <div class="step-desc">E-posta ile sifreniz iletilir</div>
        </div>
      </div>
    </div>
  </div>

  <div class="brand-foot">v<?= $version ?> &middot; CODEGA B2B</div>
</aside>

<!-- SAG PANEL -->
<main class="form-panel">
  <div class="fi">

    <a href="pages/login.php" class="back-link">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Girise Don
    </a>

    <?php if ($success): ?>
    <div class="success-wrap">
      <div class="success-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h2>Basvurunuz Alindi!</h2>
      <p>Ekibimiz basvurunuzu inceleyecek ve en kisa surede size donecek. Lutfen e-postanizi kontrol edin.</p>
      <a href="pages/login.php" class="btn-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Giris Sayfasina Don
      </a>
    </div>

    <?php else: ?>

    <div class="form-head">
      <div class="wel">Bayilik Basvurusu</div>
      <h1>Formu Doldurun</h1>
      <p>Tum alanlar dakikalar icinde tamamlanabilir.</p>
    </div>

    <?php if ($error): ?>
    <div class="f-alert err">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Basvuru tipi -->
    <div class="type-tabs">
      <button type="button" class="type-tab active" id="tab-kurumsal" onclick="setType('kurumsal')">
        <div class="type-tab-icon">🏢</div>
        <div class="type-tab-title">Kurumsal</div>
        <div class="type-tab-sub">Sirket / Limited / A.S.</div>
      </button>
      <button type="button" class="type-tab" id="tab-bireysel" onclick="setType('bireysel')">
        <div class="type-tab-icon">👤</div>
        <div class="type-tab-title">Bireysel</div>
        <div class="type-tab-sub">Sahis isletmesi</div>
      </button>
    </div>

    <form method="POST" id="applyForm">
      <?= csrfField() ?>
      <input type="hidden" name="applicant_type" id="applicant_type" value="kurumsal">

      <!-- Firma Bilgileri -->
      <div class="sec-title">Firma Bilgileri</div>

      <div class="fg">
        <label class="fl" for="cn">Firma Unvani / Ad Soyad <span>*</span></label>
        <div class="fiw">
          <span class="fi-ico"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
          <input type="text" id="cn" name="company_name" class="fi-inp" placeholder="ABC Gida Ltd. Sti." required
                 value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
        </div>
      </div>

      <div class="fg-row">
        <div class="fg">
          <label class="fl" for="ctc">Yetkili / Ilgili Kisi</label>
          <div class="fiw">
            <span class="fi-ico"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
            <input type="text" id="ctc" name="contact_name" class="fi-inp" placeholder="Ad Soyad"
                   value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
          </div>
        </div>
        <div class="fg">
          <label class="fl" for="city">Sehir</label>
          <div class="fiw">
            <span class="fi-ico"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
            <input type="text" id="city" name="city" class="fi-inp" placeholder="Istanbul"
                   value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Iletisim -->
      <div class="sec-title">Iletisim</div>

      <div class="fg-row">
        <div class="fg">
          <label class="fl" for="em">E-posta <span>*</span></label>
          <div class="fiw">
            <span class="fi-ico"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
            <input type="email" id="em" name="email" class="fi-inp" placeholder="info@firma.com" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
        </div>
        <div class="fg">
          <label class="fl" for="ph">Telefon <span>*</span></label>
          <div class="fiw">
            <span class="fi-ico"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.68A2 2 0 012 .94h3a2 2 0 012 1.72 12.8 12.8 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 8.14a16 16 0 006.29 6.29l1.56-1.16a2 2 0 012.11-.45 12.8 12.8 0 002.81.7A2 2 0 0122 15.4v1.52z"/></svg></span>
            <input type="tel" id="ph" name="phone" class="fi-inp" placeholder="05XX XXX XX XX" required
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Vergi (sadece kurumsal) -->
      <div id="tax-section">
        <div class="sec-title">Vergi Bilgileri</div>
        <div class="fg-row">
          <div class="fg">
            <label class="fl" for="tn">Vergi No</label>
            <input type="text" id="tn" name="tax_number" class="fi-inp no-icon" placeholder="1234567890"
                   value="<?= htmlspecialchars($_POST['tax_number'] ?? '') ?>">
          </div>
          <div class="fg">
            <label class="fl" for="to">Vergi Dairesi</label>
            <input type="text" id="to" name="tax_office" class="fi-inp no-icon" placeholder="Kadikoy V.D."
                   value="<?= htmlspecialchars($_POST['tax_office'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Adres & Not -->
      <div class="sec-title">Ek Bilgiler</div>

      <div class="fg">
        <label class="fl" for="adr">Adres</label>
        <input type="text" id="adr" name="address" class="fi-inp no-icon" placeholder="Mahalle, sokak, bina no..."
               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
      </div>

      <div class="fg">
        <label class="fl" for="notes">Not / Mesaj</label>
        <textarea id="notes" name="notes" class="fi-inp" placeholder="Eklemek istediginiz varsa..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="btn-sub">
        Basvuruyu Gonder
        <svg class="arr" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </button>
    </form>

    <?php endif; ?>
  </div>
</main>

</div>
<script>
function setType(t) {
  document.getElementById('applicant_type').value = t;
  document.getElementById('tab-kurumsal').classList.toggle('active', t === 'kurumsal');
  document.getElementById('tab-bireysel').classList.toggle('active', t === 'bireysel');
  document.getElementById('tax-section').style.display = t === 'kurumsal' ? '' : 'none';
}
// Sayfa yuklendiginde POST tipi koru
(function(){
  var v = '<?= htmlspecialchars($_POST['applicant_type'] ?? 'kurumsal') ?>';
  if (v && v !== 'kurumsal') setType(v);
})();
document.getElementById('applyForm')?.addEventListener('submit', function(){
  var b = this.querySelector('.btn-sub');
  b.textContent = 'Gonderiliyor...';
  b.disabled = true;
});
</script>
<div style="text-align:center;margin-top:24px;font-size:.72rem;color:#a0998e;line-height:1.9">
  <a href="pages/privacy.php" style="color:#6b7280;text-decoration:none">Gizlilik &amp; KVKK</a>
  &nbsp;&middot;&nbsp;
  <a href="pages/terms.php" style="color:#6b7280;text-decoration:none">Kullanım Koşulları</a>
</div>
</body>
</html>
<?php exit; ?>