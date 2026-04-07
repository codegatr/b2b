<?php
// pages/apply.php — Bayilik Başvurusu
$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $type     = $_POST['applicant_type'] ?? 'kurumsal';
    $company  = trim($_POST['company_name'] ?? '');
    $contact  = trim($_POST['contact_name'] ?? '');
    $cparts   = explode(' ', $contact, 2);
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
            'type'          => $type,
            'company_name'   => $company,
            'first_name'     => $cparts[0] ?? $contact,
            'last_name'      => $cparts[1] ?? '',
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-family:'Inter',-apple-system,sans-serif;font-size:14px;line-height:1.6;background:#f0ede6;color:#1f2937}
body{min-height:100vh;display:flex;flex-direction:column}
:root{--g:#ed2939;--gd:#c41f2e;--gl:#f04050;--r:#b24545;--ink:#1f2937;--muted:#6b7280;--border:#e2ddd5;--white:#fff}
a{color:var(--g);text-decoration:none}
a:hover{text-decoration:underline}

/* Navbar */
.nav{background:var(--g);height:54px;display:flex;align-items:center;padding:0 32px;gap:16px;box-shadow:0 2px 10px rgba(237,41,57,.25);flex-shrink:0}
.nav-brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:700;font-size:.88rem;text-decoration:none}
.nav-brand-ic{width:30px;height:30px;background:rgba(255,255,255,.18);border-radius:7px;display:flex;align-items:center;justify-content:center}
.nav-sp{flex:1}
.nav-lnk{color:rgba(255,255,255,.7);font-size:.78rem;text-decoration:none;transition:color .15s}
.nav-lnk:hover{color:#fff}
.nav-btn{height:32px;padding:0 14px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:6px;color:#fff;font-size:.78rem;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:5px;transition:background .15s}
.nav-btn:hover{background:rgba(255,255,255,.25);text-decoration:none}

/* Ana grid */
.main{flex:1;display:grid;grid-template-columns:260px 1fr;max-width:980px;width:100%;margin:0 auto;padding:28px 20px;gap:24px;align-items:start}

/* Sol */
.side{background:#1a1213;border-radius:14px;padding:24px 20px;color:#fff;box-shadow:0 6px 24px rgba(237,41,57,.28);overflow:hidden;position:relative}
.side::before{content:'';position:absolute;top:-50px;right:-50px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none}
.sdg{display:inline-flex;align-items:center;gap:4px;background:rgba(237,41,57,.25);border:1px solid rgba(237,41,57,.35);color:#f5a0a0;border-radius:99px;padding:2px 9px;font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-bottom:12px}
.side h2{font-family:'Playfair Display',Georgia,serif;font-size:1.3rem;font-weight:900;line-height:1.25;margin-bottom:8px;letter-spacing:-.01em}
.side h2 em{font-style:normal;color:#f5a0a0}
.side p{font-size:.77rem;color:rgba(255,255,255,.5);line-height:1.6;margin-bottom:16px}
.sdiv{height:1px;background:rgba(255,255,255,.1);margin:14px 0}
.ssteps{display:flex;flex-direction:column;gap:11px}
.sstep{display:flex;align-items:center;gap:9px}
.sn{width:22px;height:22px;border-radius:50%;flex-shrink:0;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700}
.st{font-size:.76rem;font-weight:600;color:rgba(255,255,255,.82)}
.ss{font-size:.68rem;color:rgba(255,255,255,.35);margin-top:1px}
.sfoot{margin-top:18px;font-size:.64rem;color:rgba(255,255,255,.22)}

/* Sag form kart */
.card{background:var(--white);border-radius:14px;padding:24px 24px;box-shadow:0 2px 12px rgba(0,0,0,.07);border:1px solid var(--border)}
.ch{margin-bottom:18px;padding-bottom:15px;border-bottom:1px solid var(--border)}
.cw{font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--g);margin-bottom:4px}
.ch h1{font-family:'Playfair Display',Georgia,serif;font-size:1.35rem;font-weight:900;color:var(--ink);letter-spacing:-.01em}
.ch p{font-size:.78rem;color:var(--muted);margin-top:2px}

/* Tip */
.trow{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
.tbtn{border:1.5px solid var(--border);border-radius:9px;padding:10px 12px;background:var(--white);cursor:pointer;display:flex;align-items:center;gap:9px;transition:border-color .15s,background .15s}
.tbtn:hover{border-color:var(--g)}
.tbtn.on{border-color:var(--g);background:rgba(237,41,57,.04)}
.tic{font-size:1.1rem;flex-shrink:0}
.tt{font-weight:700;font-size:.8rem;color:var(--ink)}
.ts{font-size:.68rem;color:var(--muted)}

/* Section */
.sc{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--g);display:flex;align-items:center;gap:7px;margin:15px 0 10px}
.sc::after{content:'';flex:1;height:1px;background:rgba(237,41,57,.16)}

/* Fields */
.fg{margin-bottom:10px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:9px}
.fl{display:block;font-size:.72rem;font-weight:600;color:var(--ink);margin-bottom:3px}
.fl span{color:var(--r)}
.fw{position:relative}
.fi{width:100%;height:40px;background:var(--white);border:1.5px solid var(--border);border-radius:7px;padding:0 11px 0 36px;color:var(--ink);font-family:inherit;font-size:.83rem;outline:none;transition:border-color .15s,box-shadow .15s}
.fi.ni{padding-left:11px}
.fi::placeholder{color:#c0bfbc}
.fi:focus{border-color:var(--g);box-shadow:0 0 0 3px rgba(237,41,57,.09)}
textarea.fi{height:64px;padding-top:9px;padding-left:11px;resize:none}
.fico{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;pointer-events:none}

/* Alert */
.al{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border-radius:7px;font-size:.78rem;margin-bottom:14px;animation:sd .2s ease}
@keyframes sd{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
.al.err{background:rgba(237,41,57,.08);border:1px solid rgba(237,41,57,.2);color:#7a2e2e}
.al svg{flex-shrink:0;margin-top:1px}

/* Footer */
.cf{display:flex;align-items:center;justify-content:space-between;margin-top:16px;padding-top:14px;border-top:1px solid var(--border);gap:10px;flex-wrap:wrap}
.cn{font-size:.7rem;color:var(--muted);line-height:1.6}
.cn a{color:var(--g)}
.bsub{height:42px;padding:0 24px;background:var(--g);color:#fff;border:none;border-radius:7px;font-family:inherit;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;box-shadow:0 3px 12px rgba(237,41,57,.28);transition:background .15s,transform .1s;white-space:nowrap}
.bsub:hover{background:var(--gl)}
.bsub:active{transform:scale(.98)}
.arr{transition:transform .2s}
.bsub:hover .arr{transform:translateX(3px)}

/* Basari */
.succ{text-align:center;padding:28px 16px}
.sic{width:58px;height:58px;background:rgba(237,41,57,.1);border:2px solid rgba(237,41,57,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;color:var(--g)}
.succ h2{font-family:'Playfair Display',Georgia,serif;font-size:1.35rem;font-weight:900;color:var(--ink);margin-bottom:7px}
.succ p{font-size:.82rem;color:var(--muted);line-height:1.65;max-width:300px;margin:0 auto 20px}
.bgo{display:inline-flex;align-items:center;gap:6px;height:42px;padding:0 22px;background:var(--g);color:#fff;border-radius:7px;font-weight:600;font-size:.85rem;text-decoration:none;box-shadow:0 3px 10px rgba(237,41,57,.27)}
.bgo:hover{background:var(--gl);text-decoration:none}

@media(max-width:800px){.main{grid-template-columns:1fr;padding:14px}.side{position:static}.g3{grid-template-columns:1fr 1fr}.nav{padding:0 14px}}
@media(max-width:500px){.g2,.g3,.trow{grid-template-columns:1fr}.card{padding:18px 14px}}
</style>
</head>
<body>

<nav class="nav">
  <a href="/?page=login" class="nav-brand">
    <div class="nav-brand-ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg></div>
    <?= htmlspecialchars($siteName) ?>
  </a>
  <div class="nav-sp"></div>
  <a href="/?page=privacy" class="nav-lnk">Gizlilik &amp; KVKK</a>
  <a href="/?page=login" class="nav-btn">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
    Bayi Girisi
  </a>
</nav>

<div class="main">
  <aside class="side">
    <div class="sdg">Yeni Basvuru</div>
    <h2>Bayimiz olun, birlikte <em>buyuyelim.</em></h2>
    <p>Fransa'nin efsanevi lezzetini musterilerinize sunma firsatini kacirmayin.</p>
    <div class="sdiv"></div>
    <div class="ssteps">
      <div class="sstep"><div class="sn">1</div><div><div class="st">Formu doldurun</div><div class="ss">Firma bilgilerini girin</div></div></div>
      <div class="sstep"><div class="sn">2</div><div><div class="st">Inceleme sureci</div><div class="ss">1-2 is gununde incelenir</div></div></div>
      <div class="sstep"><div class="sn">3</div><div><div class="st">Hesabiniz aktif</div><div class="ss">E-posta ile sifreniz iletilir</div></div></div>
    </div>
    <div class="sfoot">v<?= $version ?> &middot; CODEGA B2B</div>
  </aside>

  <div class="card">
    <?php if ($success): ?>
    <div class="succ">
      <div class="sic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>
      <h2>Basvurunuz Alindi!</h2>
      <p>Ekibimiz basvurunuzu inceleyecek ve en kisa surede bilgi verecek.</p>
      <a href="/?page=login" class="bgo">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Girise Don
      </a>
    </div>
    <?php else: ?>
    <div class="ch">
      <div class="cw">Bayilik Basvurusu</div>
      <h1>Basvuru Formu</h1>
      <p>Tum alanlar birkac dakika icinde tamamlanabilir.</p>
    </div>

    <?php if ($error): ?>
    <div class="al err">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="trow">
      <button type="button" class="tbtn on" id="tb-k" onclick="sT('kurumsal')">
        <span class="tic">&#127970;</span>
        <div><div class="tt">Kurumsal</div><div class="ts">Sirket / Ltd. / A.S.</div></div>
      </button>
      <button type="button" class="tbtn" id="tb-b" onclick="sT('bireysel')">
        <span class="tic">&#128100;</span>
        <div><div class="tt">Bireysel</div><div class="ts">Sahis isletmesi</div></div>
      </button>
    </div>

    <form method="POST" id="af">
      <?= csrfField() ?>
      <input type="hidden" name="applicant_type" id="at" value="kurumsal">

      <div class="sc">Firma Bilgileri</div>
      <div class="g2">
        <div class="fg">
          <label class="fl">Firma / Ad Soyad <span>*</span></label>
          <div class="fw">
            <span class="fico"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></span>
            <input type="text" name="company_name" class="fi" placeholder="ABC Gida Ltd. Sti." required value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
          </div>
        </div>
        <div class="fg">
          <label class="fl">Yetkili Kisi</label>
          <div class="fw">
            <span class="fico"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 00-8 0v2"/></svg></span>
            <input type="text" name="contact_name" class="fi" placeholder="Ad Soyad" value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="sc">Iletisim</div>
      <div class="g3">
        <div class="fg">
          <label class="fl">E-posta <span>*</span></label>
          <div class="fw">
            <span class="fico"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></span>
            <input type="email" name="email" class="fi" placeholder="info@firma.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
        </div>
        <div class="fg">
          <label class="fl">Telefon <span>*</span></label>
          <div class="fw">
            <span class="fico"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-5.99-5.99 19.79 19.79 0 01-3.07-8.68A2 2 0 012 .94h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 8.14a16 16 0 006.29 6.29l1.56-1.16a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg></span>
            <input type="tel" name="phone" class="fi" placeholder="05XX XXX XX XX" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>
        <div class="fg">
          <label class="fl">Sehir</label>
          <div class="fw">
            <span class="fico"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
            <input type="text" name="city" class="fi" placeholder="Istanbul" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div id="tsec">
        <div class="sc">Vergi Bilgileri</div>
        <div class="g2">
          <div class="fg">
            <label class="fl">Vergi Numarasi</label>
            <input type="text" name="tax_number" class="fi ni" placeholder="1234567890" value="<?= htmlspecialchars($_POST['tax_number'] ?? '') ?>">
          </div>
          <div class="fg">
            <label class="fl">Vergi Dairesi</label>
            <input type="text" name="tax_office" class="fi ni" placeholder="Kadikoy V.D." value="<?= htmlspecialchars($_POST['tax_office'] ?? '') ?>">
          </div>
        </div>
      </div>

      <div class="sc">Diger</div>
      <div class="g2">
        <div class="fg">
          <label class="fl">Adres</label>
          <input type="text" name="address" class="fi ni" placeholder="Mahalle, sokak..." value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
        </div>
        <div class="fg">
          <label class="fl">Not / Mesaj</label>
          <textarea name="notes" class="fi"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="cf">
        <div class="cn">
          Gonderek <a href="/?page=terms">Kullanim Kosullari</a> ve<br>
          <a href="/?page=privacy">Gizlilik Politikasi</a>'ni kabul etmis olursunuz.
        </div>
        <button type="submit" class="bsub">
          Basvuruyu Gonder
          <svg class="arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
function sT(t) {
  document.getElementById('at').value = t;
  document.getElementById('tb-k').classList.toggle('on', t==='kurumsal');
  document.getElementById('tb-b').classList.toggle('on', t==='bireysel');
  document.getElementById('tsec').style.display = t==='kurumsal' ? '' : 'none';
}
(function(){var v='<?= htmlspecialchars($_POST["applicant_type"] ?? "kurumsal") ?>';if(v==='bireysel')sT('bireysel');})();
document.getElementById('af')?.addEventListener('submit',function(){
  var b=this.querySelector('.bsub');b.disabled=true;b.textContent='Gonderiliyor...';
});
</script>
</body>
</html>
<?php exit; ?>