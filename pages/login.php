<?php
if (isset($_SESSION['dealer_id'])) { header('Location: ?page=dashboard'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    if (!$email || !$pass) {
        $error = 'E-posta ve sifre zorunludur.';
    } else {
        $dealer = dbRow("SELECT * FROM b2b_dealers WHERE email=? AND is_active=1", [$email]);
        if ($dealer && password_verify($pass, $dealer['password_hash'])) {
            dealerLogin($dealer);
            header('Location: ?page=dashboard'); exit;
        } else {
            $error = 'E-posta veya sifre hatali ya da hesabiniz aktif degil.';
        }
    }
}
$siteName = setting('site_name', 'Le Monde Du Tacos B2B');
$version  = file_exists(dirname(__DIR__).'/version.txt') ? trim(file_get_contents(dirname(__DIR__).'/version.txt')) : '1.0.0';
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bayi Girisi &mdash; <?= htmlspecialchars($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:'Inter',-apple-system,sans-serif;font-size:14px;line-height:1.6}
:root{
  --green:#3A5F0B;--green-d:#2a4508;--green-l:#4e7d10;
  --red:#b24545;--red-d:#8f3535;--red-l:#c95555;
  --cream:#f5f0e8;--cream-d:#ede6d6;
  --ink:#1f2937;--muted:#6b7280;
  --bg-dark:#111a0a;--bg-card:#1a2710;
}

/* ── Layout ── */
.wrap{display:grid;grid-template-columns:55% 45%;min-height:100vh}

/* ══════ SOL — TACOS PANEL ══════ */
.brand-panel{
  background:var(--bg-dark);
  position:relative;overflow:hidden;
  display:flex;flex-direction:column;
  padding:48px;
}

/* Doku: ince grid */
.brand-panel::before{
  content:'';position:absolute;inset:0;
  background-image:
    radial-gradient(circle at 20% 80%, rgba(58,95,11,.35) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(178,69,69,.2) 0%, transparent 45%),
    linear-gradient(rgba(58,95,11,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(58,95,11,.04) 1px, transparent 1px);
  background-size:100% 100%, 100% 100%, 38px 38px, 38px 38px;
  pointer-events:none;
}

/* Logo */
.brand-logo{
  position:relative;z-index:2;
  display:flex;align-items:center;gap:14px;
  margin-bottom:auto;
}
.brand-logo-icon{
  width:46px;height:46px;border-radius:12px;
  background:var(--green);
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 0 0 1px rgba(255,255,255,.1), 0 6px 24px rgba(0,0,0,.4);
}
.brand-logo-txt .name{
  font-family:'Playfair Display',Georgia,serif;
  font-size:1rem;font-weight:700;color:#fff;
  letter-spacing:.01em;line-height:1.2;
}
.brand-logo-txt .sub{font-size:.7rem;color:rgba(255,255,255,.4);letter-spacing:.05em;text-transform:uppercase}

/* Tacos SVG merkez */
.tacos-stage{
  position:relative;z-index:2;
  display:flex;align-items:center;justify-content:center;
  flex:1;
}
.tacos-svg{ width:360px;max-width:90%;filter:drop-shadow(0 20px 60px rgba(0,0,0,.5)) }

/* Tagline */
.tagline{
  position:relative;z-index:2;
  margin-top:auto;
}
.tagline h2{
  font-family:'Playfair Display',Georgia,serif;
  font-size:2.4rem;font-weight:900;
  color:#fff;line-height:1.15;
  letter-spacing:-.02em;margin-bottom:12px;
}
.tagline h2 em{
  font-style:normal;
  color:var(--red-l);
}
.tagline p{
  font-size:.875rem;color:rgba(255,255,255,.45);
  max-width:320px;line-height:1.7;
}

/* Rozetler */
.badges{
  display:flex;gap:10px;margin-top:24px;flex-wrap:wrap;
}
.bdg{
  display:inline-flex;align-items:center;gap:6px;
  border:1px solid rgba(255,255,255,.1);
  border-radius:99px;padding:5px 12px;
  font-size:.72rem;font-weight:600;color:rgba(255,255,255,.6);
  letter-spacing:.03em;
}
.bdg svg{color:var(--green-l);flex-shrink:0}

/* Alt: versiyon */
.brand-ver{
  position:relative;z-index:2;
  margin-top:28px;
  font-size:.68rem;color:rgba(255,255,255,.2);
  display:flex;align-items:center;gap:8px;
}
.brand-ver::before{content:'';flex:1;height:1px;background:rgba(255,255,255,.08)}

/* ══════ SAG — FORM PANEL ══════ */
.form-panel{
  background:var(--cream);
  display:flex;flex-direction:column;
  align-items:center;justify-content:center;
  padding:56px 64px;
  position:relative;overflow:hidden;
}

/* Dekor */
.form-panel::before{
  content:'';position:absolute;top:-120px;right:-120px;
  width:340px;height:340px;border-radius:50%;
  background:radial-gradient(circle, rgba(58,95,11,.08), transparent 70%);
  pointer-events:none;
}
.form-panel::after{
  content:'';position:absolute;bottom:-80px;left:-80px;
  width:240px;height:240px;border-radius:50%;
  background:radial-gradient(circle, rgba(178,69,69,.07), transparent 70%);
  pointer-events:none;
}

.form-inner{width:100%;max-width:380px;position:relative;z-index:1}

/* Baslik */
.form-head{margin-bottom:32px}
.form-head .welcome{
  font-size:.7rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.1em;
  color:var(--green);margin-bottom:8px;
}
.form-head h1{
  font-family:'Playfair Display',Georgia,serif;
  font-size:2rem;font-weight:900;color:var(--ink);
  letter-spacing:-.02em;line-height:1.2;margin-bottom:6px;
}
.form-head p{font-size:.85rem;color:var(--muted)}

/* Alert */
.f-alert{
  display:flex;align-items:flex-start;gap:10px;
  padding:11px 14px;border-radius:10px;
  font-size:.8125rem;margin-bottom:20px;
  animation:sd .2s ease;
}
@keyframes sd{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}
.f-alert.err{background:rgba(178,69,69,.1);border:1px solid rgba(178,69,69,.25);color:#8f3535}
.f-alert.ok {background:rgba(58,95,11,.1);border:1px solid rgba(58,95,11,.25);color:#2a4508}
.f-alert svg{flex-shrink:0;margin-top:1px}

/* Form elemanlar */
.fg{margin-bottom:16px}
.fl{
  display:block;font-size:.78rem;font-weight:600;
  color:var(--ink);margin-bottom:6px;letter-spacing:.01em;
}
.fiw{position:relative}
.fi-ico{
  position:absolute;left:13px;top:50%;transform:translateY(-50%);
  color:#9ca3af;pointer-events:none;transition:color .15s;
}
.fi-inp{
  width:100%;height:48px;
  background:#fff;
  border:1.5px solid #d1d5db;
  border-radius:10px;
  padding:0 14px 0 42px;
  color:var(--ink);font-family:inherit;font-size:.9rem;
  transition:border-color .15s,box-shadow .15s;outline:none;
}
.fi-inp::placeholder{color:#c0bfbc}
.fi-inp:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(58,95,11,.12)}
.fiw:focus-within .fi-ico{color:var(--green)}

.fi-eye{
  position:absolute;right:13px;top:50%;transform:translateY(-50%);
  color:#9ca3af;cursor:pointer;background:none;border:none;
  padding:3px;border-radius:5px;line-height:0;transition:color .15s;
}
.fi-eye:hover{color:var(--ink)}

.frow{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.flink{font-size:.78rem;color:var(--green);text-decoration:none;font-weight:500}
.flink:hover{color:var(--green-d)}

/* Submit */
.btn-sub{
  width:100%;height:50px;
  background:var(--green);color:#fff;border:none;
  border-radius:10px;font-family:inherit;
  font-size:.9375rem;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:8px;
  margin-top:10px;letter-spacing:.01em;
  transition:background .15s,transform .1s,box-shadow .15s;
  box-shadow:0 4px 16px rgba(58,95,11,.35);
}
.btn-sub:hover{background:var(--green-l);box-shadow:0 6px 24px rgba(58,95,11,.4)}
.btn-sub:active{transform:scale(.98)}
.arr{transition:transform .2s}
.btn-sub:hover .arr{transform:translateX(3px)}

/* Ayrac */
.div{
  display:flex;align-items:center;gap:10px;
  margin:22px 0;color:#b0aca4;font-size:.72rem;
}
.div::before,.div::after{content:'';flex:1;height:1px;background:#d8d3ca}

/* Basvuru */
.btn-app{
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%;height:46px;background:transparent;
  border:1.5px solid #d1d5db;border-radius:10px;
  color:var(--muted);font-family:inherit;font-size:.85rem;font-weight:500;
  text-decoration:none;cursor:pointer;
  transition:border-color .15s,color .15s,background .15s;
}
.btn-app:hover{
  border-color:var(--red);color:var(--red-d);
  background:rgba(178,69,69,.05);text-decoration:none;
}

/* Alt bilgi */
.form-foot{
  text-align:center;margin-top:28px;
  font-size:.72rem;color:#a0998e;line-height:1.9;
}
.form-foot a{color:var(--muted);text-decoration:none}
.form-foot a:hover{color:var(--ink)}

/* Mobil */
@media(max-width:860px){
  .wrap{grid-template-columns:1fr}
  .brand-panel{display:none}
  .form-panel{padding:40px 24px;background:#fff}
}
</style>
</head>
<body>
<div class="wrap">

<!-- ══════ SOL PANEL ══════ -->
<aside class="brand-panel">

  <div class="brand-logo">
    <div class="brand-logo-icon">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 11l19-9-9 19-2-8-8-2z"/>
      </svg>
    </div>
    <div class="brand-logo-txt">
      <div class="name"><?= htmlspecialchars($siteName) ?></div>
      <div class="sub">Bayi Portali</div>
    </div>
  </div>

  <!-- TACOS SVG ILLUSTRASYON -->
  <div class="tacos-stage">
    <svg class="tacos-svg" viewBox="0 0 380 320" xmlns="http://www.w3.org/2000/svg">
      <!-- Tabak golge -->
      <ellipse cx="190" cy="295" rx="130" ry="14" fill="rgba(0,0,0,.35)"/>

      <!-- TACO KABUGU (buyuk, arkadaki) -->
      <g transform="translate(190,155)">
        <!-- Dis kabuk yari daire -->
        <path d="M-130,30 Q-125,-80 0,-90 Q125,-80 130,30 Z" fill="#c8832a"/>
        <!-- Ic kabuk golge -->
        <path d="M-120,28 Q-115,-70 0,-80 Q115,-70 120,28 Z" fill="#b87320"/>
        <!-- Kabuk ustu desen cizgiler -->
        <path d="M-80,-40 Q0,-60 80,-40" stroke="rgba(255,255,255,.12)" stroke-width="2" fill="none"/>
        <path d="M-95,-10 Q0,-30 95,-10" stroke="rgba(255,255,255,.1)" stroke-width="2" fill="none"/>
        <path d="M-100,15 Q0,-5 100,15" stroke="rgba(255,255,255,.08)" stroke-width="2" fill="none"/>
        <!-- Kabuk kenari -->
        <path d="M-130,30 Q-128,35 -120,36 Q0,42 120,36 Q128,35 130,30" fill="#e09030"/>
      </g>

      <!-- IC DOLGU KATMANLARI -->
      <!-- Marul (yesil yapraklar) -->
      <g transform="translate(190,158)">
        <ellipse cx="-30" cy="10" rx="48" ry="22" fill="#4a7c20" transform="rotate(-8,-30,10)"/>
        <ellipse cx="30" cy="8" rx="44" ry="20" fill="#5a9228" transform="rotate(6,30,8)"/>
        <ellipse cx="0" cy="5" rx="50" ry="20" fill="#3d6b18"/>
        <!-- Yaprak damarlari -->
        <path d="M-50,5 Q-20,-8 10,5" stroke="rgba(255,255,255,.15)" stroke-width="1.5" fill="none"/>
        <path d="M10,4 Q35,-6 60,4" stroke="rgba(255,255,255,.15)" stroke-width="1.5" fill="none"/>
      </g>

      <!-- Et / Tavuk -->
      <g transform="translate(190,150)">
        <ellipse cx="-10" cy="0" rx="90" ry="18" fill="#8B4513"/>
        <!-- Et doku -->
        <ellipse cx="-25" cy="-3" rx="18" ry="7" fill="#7a3c10"/>
        <ellipse cx="15" cy="-2" rx="22" ry="8" fill="#9b4f18"/>
        <ellipse cx="-5" cy="2" rx="15" ry="6" fill="#7a3c10"/>
        <!-- Izgara cizgileri -->
        <path d="M-70,-5 L-60,10" stroke="rgba(0,0,0,.3)" stroke-width="3" stroke-linecap="round"/>
        <path d="M-50,-8 L-40,7" stroke="rgba(0,0,0,.3)" stroke-width="3" stroke-linecap="round"/>
        <path d="M-30,-10 L-20,5" stroke="rgba(0,0,0,.3)" stroke-width="3" stroke-linecap="round"/>
        <path d="M-10,-10 L0,5" stroke="rgba(0,0,0,.3)" stroke-width="3" stroke-linecap="round"/>
        <path d="M10,-8 L20,7" stroke="rgba(0,0,0,.3)" stroke-width="3" stroke-linecap="round"/>
        <path d="M30,-6 L40,9" stroke="rgba(0,0,0,.3)" stroke-width="3" stroke-linecap="round"/>
      </g>

      <!-- Domates (kirmizi dilimler) -->
      <g transform="translate(190,142)">
        <ellipse cx="-45" cy="-5" rx="16" ry="10" fill="#c0392b" transform="rotate(-15,-45,-5)"/>
        <ellipse cx="40" cy="-8" rx="14" ry="9" fill="#e74c3c" transform="rotate(10,40,-8)"/>
        <ellipse cx="0" cy="-12" rx="18" ry="10" fill="#c0392b"/>
        <!-- Domates ic -->
        <ellipse cx="-45" cy="-5" rx="8" ry="5" fill="#e74c3c" transform="rotate(-15,-45,-5)" opacity=".7"/>
        <ellipse cx="0" cy="-12" rx="9" ry="5" fill="#e74c3c" opacity=".7"/>
      </g>

      <!-- Soguk sos (sari) -->
      <g transform="translate(190,138)">
        <path d="M-60,-10 Q-30,-25 0,-18 Q30,-28 65,-12" stroke="#f1c40f" stroke-width="5" fill="none" stroke-linecap="round"/>
        <path d="M-55,-5 Q-20,-20 20,-15 Q50,-22 70,-8" stroke="#f39c12" stroke-width="3" fill="none" stroke-linecap="round" opacity=".6"/>
      </g>

      <!-- Avokado (yesil noktalari) -->
      <g transform="translate(190,148)">
        <ellipse cx="-60" cy="-15" rx="12" ry="8" fill="#27ae60" transform="rotate(-20,-60,-15)"/>
        <ellipse cx="55" cy="-18" rx="11" ry="7" fill="#2ecc71" transform="rotate(15,55,-18)"/>
        <ellipse cx="-20" cy="-20" rx="10" ry="7" fill="#27ae60"/>
      </g>

      <!-- TACO KABUGU ONDE (on tarafi) -->
      <g transform="translate(190,155)">
        <!-- Sag kabuk yarisi -->
        <path d="M0,-90 Q125,-80 130,30 L0,42 Z" fill="#d4902e" opacity=".85"/>
        <!-- Sol kabuk kenar -->
        <path d="M-130,30 L0,42 Z" fill="none" stroke="#e09030" stroke-width="1" opacity=".5"/>
        <!-- Kabuk golge ic -->
        <path d="M0,-88 Q110,-78 118,28 L0,40 Z" fill="rgba(0,0,0,.08)"/>
      </g>

      <!-- KUCUK DEKORATIF ELEMENTLER -->
      <!-- Biber parcaciklari -->
      <circle cx="125" cy="108" r="5" fill="#c0392b" opacity=".8"/>
      <circle cx="255" cy="105" r="4" fill="#c0392b" opacity=".7"/>
      <circle cx="145" cy="95" r="3" fill="#e74c3c" opacity=".9"/>
      <circle cx="240" cy="98" r="3.5" fill="#e74c3c" opacity=".8"/>
      <circle cx="170" cy="88" r="2.5" fill="#c0392b" opacity=".7"/>

      <!-- Ses dalgalari / lezzet cizgileri -->
      <path d="M80,60 Q95,45 110,60" stroke="rgba(255,255,255,.12)" stroke-width="2.5" fill="none" stroke-linecap="round"/>
      <path d="M75,48 Q95,28 115,48" stroke="rgba(255,255,255,.08)" stroke-width="2" fill="none" stroke-linecap="round"/>

      <path d="M270,62 Q285,47 300,62" stroke="rgba(255,255,255,.12)" stroke-width="2.5" fill="none" stroke-linecap="round"/>
      <path d="M265,50 Q285,30 305,50" stroke="rgba(255,255,255,.08)" stroke-width="2" fill="none" stroke-linecap="round"/>

      <!-- Yildiz dekoru -->
      <g transform="translate(60,50)" opacity=".4">
        <polygon points="0,-8 2,-3 7,-3 3,1 5,6 0,3 -5,6 -3,1 -7,-3 -2,-3" fill="#f1c40f"/>
      </g>
      <g transform="translate(320,55)" opacity=".35">
        <polygon points="0,-6 1.5,-2.5 5.5,-2.5 2.5,1 3.5,5 0,2.5 -3.5,5 -2.5,1 -5.5,-2.5 -1.5,-2.5" fill="#f1c40f"/>
      </g>
      <g transform="translate(190,30)" opacity=".3">
        <polygon points="0,-5 1.5,-2 4,-2 2,0.5 3,4 0,2 -3,4 -2,0.5 -4,-2 -1.5,-2" fill="#f39c12"/>
      </g>

      <!-- Kabuk alt tamamlayici -->
      <path d="M60,185 Q190,200 320,185" stroke="rgba(200,131,42,.3)" stroke-width="2" fill="none"/>
    </svg>
  </div>

  <div class="tagline">
    <h2>Lezzetin<br>bayisi <em>olun.</em></h2>
    <p>Fransiz tacos lezzetini musterilerinize tasimak icin tum araclara tek noktadan erisin.</p>
    <div class="badges">
      <span class="bdg">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
        Siparis Yonetimi
      </span>
      <span class="bdg">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        Cari Hesap
      </span>
      <span class="bdg">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Canli Stok
      </span>
    </div>
  </div>

  <div class="brand-ver">v<?= $version ?> &middot; CODEGA B2B</div>
</aside>

<!-- ══════ SAG PANEL ══════ -->
<main class="form-panel">
  <div class="form-inner">

    <div class="form-head">
      <div class="welcome">Bayi Girisi</div>
      <h1>Hos Geldiniz</h1>
      <p>Hesabiniza giris yaparak siparisleri yonetin.</p>
    </div>

    <?php if ($error): ?>
    <div class="f-alert err">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['applied'])): ?>
    <div class="f-alert ok">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      Basvurunuz alindi, onay surecinde bilgilendirileceksiniz.
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['reset'])): ?>
    <div class="f-alert ok">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      Sifreniz guncellendi, giris yapabilirsiniz.
    </div>
    <?php endif; ?>

    <form method="POST" id="lf">
      <?= csrfField() ?>

      <div class="fg">
        <label class="fl" for="em">E-posta Adresi</label>
        <div class="fiw">
          <span class="fi-ico">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </span>
          <input type="email" id="em" name="email" class="fi-inp"
                 placeholder="bayi@sirket.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 autofocus required autocomplete="username">
        </div>
      </div>

      <div class="fg">
        <div class="frow">
          <label class="fl" for="pw" style="margin-bottom:0">Sifre</label>
          <a href="pages/forgot-password.php" class="flink">Sifremi Unuttum</a>
        </div>
        <div class="fiw">
          <span class="fi-ico">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          </span>
          <input type="password" id="pw" name="password" class="fi-inp"
                 placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                 required autocomplete="current-password">
          <button type="button" class="fi-eye" onclick="tgPw()" title="Goster/Gizle">
            <svg id="ei" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-sub" id="sb">
        <span id="st">Giris Yap</span>
        <svg class="arr" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </button>
    </form>

    <div class="div">veya</div>

    <a href="?page=apply" class="btn-app">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
      Bayilik Basvurusu Yap
    </a>

    <div class="form-foot">
      <?= htmlspecialchars($siteName) ?><br>
      <a href="#">Gizlilik Politikasi</a> &nbsp;&middot;&nbsp; <a href="#">Kullanim Kosullari</a>
    </div>

  </div>
</main>

</div>
<script>
function tgPw(){
  var i=document.getElementById('pw'),e=document.getElementById('ei'),s=i.type==='password';
  i.type=s?'text':'password';
  e.innerHTML=s
    ?'<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
    :'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}
document.getElementById('lf').addEventListener('submit',function(){
  var b=document.getElementById('sb');
  document.getElementById('st').textContent='Giris yapiliyor...';
  b.disabled=true;b.style.opacity='.7';
});
</script>
</body>
</html>
<?php exit; ?>