<?php
// pages/terms.php — Kullanım Koşulları
$siteName    = setting('site_name', 'Le Monde Du Tacos B2B');
$companyName = setting('company_name', 'Le Monde Du Tacos');
$companyAddr = setting('company_address', 'Türkiye');
$adminEmail  = setting('admin_email', 'info@lemondedutacos.com');
$today       = date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kullanım Koşulları — <?= htmlspecialchars($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--green:#ed2939;--green-d:#c41f2e;--ink:#1f2937;--muted:#6b7280;--cream:#f5f0e8;--border:#e5e7eb;--red:#b24545}
html,body{font-family:'Inter',-apple-system,sans-serif;font-size:15px;line-height:1.75;color:var(--ink);background:#fff}
a{color:var(--green);text-decoration:none}
a:hover{text-decoration:underline}
.topbar{background:var(--green);padding:14px 0;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(237,41,57,.3)}
.topbar-inner{max-width:900px;margin:0 auto;padding:0 24px;display:flex;align-items:center;justify-content:space-between}
.topbar-brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:700;font-size:.95rem;text-decoration:none}
.topbar-brand-mark{width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:8px;display:flex;align-items:center;justify-content:center}
.topbar-back{color:rgba(255,255,255,.8);font-size:.82rem;display:flex;align-items:center;gap:5px;text-decoration:none}
.topbar-back:hover{color:#fff;text-decoration:none}
.hero{background:var(--cream);border-bottom:1px solid var(--border);padding:52px 24px}
.hero-inner{max-width:900px;margin:0 auto}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(178,69,69,.1);border:1px solid rgba(178,69,69,.2);color:var(--red);border-radius:99px;padding:4px 12px;font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;margin-bottom:16px}
.hero h1{font-family:'Playfair Display',Georgia,serif;font-size:2.4rem;font-weight:700;color:var(--ink);margin-bottom:10px;letter-spacing:-.02em}
.hero-meta{font-size:.82rem;color:var(--muted)}
.hero-meta strong{color:var(--ink)}
.content{max-width:900px;margin:0 auto;padding:52px 24px 80px}
.layout{display:grid;grid-template-columns:220px 1fr;gap:48px;align-items:start}
.sticky-nav{position:sticky;top:80px}
.nav-list{list-style:none;border-left:2px solid var(--border)}
.nav-list li{margin-bottom:2px}
.nav-list a{display:block;padding:7px 14px;font-size:.82rem;color:var(--muted);border-left:2px solid transparent;margin-left:-2px;transition:color .15s,border-color .15s}
.nav-list a:hover{color:var(--green);border-color:var(--green);text-decoration:none}
.nav-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);padding:0 14px 10px;margin-top:4px}
article h2{font-family:'Playfair Display',Georgia,serif;font-size:1.35rem;font-weight:700;color:var(--ink);margin:44px 0 14px;padding-top:12px;border-top:1px solid var(--border)}
article h2:first-child{margin-top:0;border-top:none}
article h3{font-size:1rem;font-weight:700;color:var(--ink);margin:22px 0 10px}
article p{margin-bottom:14px;color:#374151}
article ul,article ol{margin:10px 0 14px 20px;color:#374151}
article li{margin-bottom:6px}
article strong{color:var(--ink)}
article .highlight{background:rgba(237,41,57,.07);border-left:3px solid var(--green);padding:14px 18px;border-radius:0 8px 8px 0;margin:18px 0;font-size:.9rem}
article .warning{background:rgba(178,69,69,.07);border-left:3px solid var(--red);padding:14px 18px;border-radius:0 8px 8px 0;margin:18px 0;font-size:.9rem}
.page-footer{background:var(--cream);border-top:1px solid var(--border);padding:28px 24px;text-align:center;font-size:.8rem;color:var(--muted)}
.page-footer a{color:var(--green)}
@media(max-width:720px){.layout{grid-template-columns:1fr}.sticky-nav{display:none}.hero h1{font-size:1.8rem}}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <a href="/?page=login" class="topbar-brand">
      <div class="topbar-brand-mark">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>
      </div>
      <?= htmlspecialchars($siteName) ?>
    </a>
    <a href="/?page=login" class="topbar-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Giriş Sayfasına Dön
    </a>
  </div>
</div>

<div class="hero">
  <div class="hero-inner">
    <div class="hero-tag">Yasal Metin</div>
    <h1>Kullanım Koşulları</h1>
    <p class="hero-meta">Son güncelleme: <strong><?= $today ?></strong> &nbsp;·&nbsp; <?= htmlspecialchars($companyName) ?></p>
  </div>
</div>

<div class="content">
  <div class="layout">

    <nav class="sticky-nav">
      <div class="nav-title">İçindekiler</div>
      <ul class="nav-list">
        <li><a href="#taraflar">1. Taraflar ve Kapsam</a></li>
        <li><a href="#kabul">2. Koşulların Kabulü</a></li>
        <li><a href="#hesap">3. Hesap ve Erişim</a></li>
        <li><a href="#siparis">4. Sipariş Süreci</a></li>
        <li><a href="#fiyat">5. Fiyat ve Ödeme</a></li>
        <li><a href="#teslimat">6. Teslimat</a></li>
        <li><a href="#iade">7. İade ve İptal</a></li>
        <li><a href="#yuzukumlululer">8. Yükümlülükler</a></li>
        <li><a href="#sorumluluk">9. Sorumluluk Sınırı</a></li>
        <li><a href="#fikri">10. Fikri Mülkiyet</a></li>
        <li><a href="#gizlilik">11. Gizlilik</a></li>
        <li><a href="#fesih">12. Fesih</a></li>
        <li><a href="#uyusmazlik">13. Uyuşmazlık</a></li>
        <li><a href="#degisiklik">14. Değişiklikler</a></li>
      </ul>
    </nav>

    <article>

      <h2 id="taraflar">1. Taraflar ve Kapsam</h2>
      <p>Bu Kullanım Koşulları ("Koşullar"), <strong><?= htmlspecialchars($companyName) ?></strong> ("Şirket") ile Şirket tarafından yetkili kılınan bayi firmalar ("Bayi") arasında, <?= htmlspecialchars($siteName) ?> platformunun ("Platform") kullanımına ilişkin hak ve yükümlülükleri düzenler.</p>
      <p>Platform; ürün listeleme, sipariş yönetimi, cari hesap takibi, stok görüntüleme ve ödeme bildirimlerini kapsayan, yalnızca yetkili bayilere açık kapalı bir B2B e-ticaret sistemidir.</p>
      <div class="highlight">Platform erişimi, Şirket ile imzalanmış Bayi Sözleşmesi'nin yürürlükte olmasına bağlıdır. Bayi Sözleşmesi ile bu Koşullar arasında çelişki olması halinde Bayi Sözleşmesi esas alınır.</div>

      <h2 id="kabul">2. Koşulların Kabulü</h2>
      <p>Platforma ilk girişle birlikte Bayi, bu Koşulları okuduğunu, anladığını ve tüm hükümleri kayıtsız şartsız kabul ettiğini beyan eder. Koşulları kabul etmeyen Bayi platformu kullanmamalıdır.</p>
      <p>Bayi adına platforma erişen tüm kullanıcılar (yönetici, satış temsilcisi vb.) bu Koşullara uymakla yükümlüdür. Bayi, kendi kullanıcılarının eylemlerinden doğrudan sorumludur.</p>

      <h2 id="hesap">3. Hesap Güvenliği ve Erişim</h2>
      <h3>3.1 Hesap Oluşturma</h3>
      <p>Bayi hesabı yalnızca Şirket tarafından oluşturulur. Bayi, verdiği bilgilerin doğru, güncel ve eksiksiz olmasından sorumludur. Hatalı bilgi nedeniyle doğabilecek zararlar Bayi'ye aittir.</p>
      <h3>3.2 Şifre Güvenliği</h3>
      <ul>
        <li>Şifrenizi güçlü tutun (en az 8 karakter, harf+rakam+sembol).</li>
        <li>Şifrenizi üçüncü şahıslarla paylaşmayın.</li>
        <li>Şifrenizin ele geçirildiğinden şüpheleniyorsanız derhal değiştirin ve Şirketi bilgilendirin.</li>
        <li>Şifrenizden kaynaklanan yetkisiz erişimlerde sorumluluk Bayi'ye aittir.</li>
      </ul>
      <h3>3.3 Hesap Askıya Alma</h3>
      <p>Şirket; güvenlik ihlali, ödemesizlik, bu Koşulların ihlali veya Bayi Sözleşmesi'nin sona ermesi halinde hesabı önceden bildirmeksizin askıya alma veya kapatma hakkını saklı tutar.</p>

      <h2 id="siparis">4. Sipariş Süreci</h2>
      <h3>4.1 Sipariş Oluşturma</h3>
      <p>Siparişler Platform üzerinden elektronik olarak oluşturulur. Sipariş tamamlandığında sistem tarafından otomatik onay e-postası gönderilir. Bu e-posta, siparişin kabul edildiğine değil, alındığına ilişkin bildirimdir.</p>
      <h3>4.2 Sipariş Onayı</h3>
      <p>Şirket, aşağıdaki durumlarda siparişi kısmen veya tamamen iptal etme hakkını saklı tutar:</p>
      <ul>
        <li>Stok yetersizliği</li>
        <li>Bayi'nin kredi limitinin aşılmış olması</li>
        <li>Fiyat bilgisi hatası veya teknik arıza</li>
        <li>Mücbir sebep halleri</li>
      </ul>
      <h3>4.3 Minimum Sipariş</h3>
      <p>Bazı ürünler için minimum sipariş miktarı uygulanabilir. Bu miktar ürün sayfasında belirtilir; minimum miktarın altında sipariş sisteme kaydedilmez.</p>

      <h2 id="fiyat">5. Fiyatlandırma ve Ödeme</h2>
      <h3>5.1 Fiyatlar</h3>
      <p>Platformda görüntülenen fiyatlar Bayi'ye özel fiyat listesine göre belirlenir ve KDV hariç olarak gösterilir. Şirket, fiyatları önceden bildirmeksizin güncelleme hakkını saklı tutar; değişiklikler onaylanmamış siparişleri etkileyebilir.</p>
      <h3>5.2 Ödeme Yöntemleri</h3>
      <ul>
        <li><strong>Açık Hesap:</strong> Bayi sözleşmesinde belirlenen vade ve limit dahilinde</li>
        <li><strong>Havale / EFT:</strong> Dekont Platform üzerinden yüklenerek bildirilir</li>
      </ul>
      <h3>5.3 Gecikme Faizi</h3>
      <p>Vadesinde ödenmeyen tutarlara, Bayi Sözleşmesi'nde belirtilen oranda gecikme faizi uygulanır. Ödeme yapılmadan yeni sipariş oluşturulması Şirket'in inisiyatifindedir.</p>
      <div class="warning"><strong>Önemli:</strong> Vade tarihi geçmiş borçlar, sistem tarafından otomatik olarak işaretlenir ve yeni siparişler engellenebilir.</div>

      <h2 id="teslimat">6. Teslimat</h2>
      <p>Teslimat koşulları (süre, bölge, ücret) Bayi Sözleşmesi ve ürün detay sayfalarında belirtilir. Stok durumu, coğrafi koşullar ve mücbir sebepler teslimat sürelerini etkileyebilir. Teslimat adresindeki değişiklikler en geç sipariş onayına kadar bildirilmelidir.</p>

      <h2 id="iade">7. İade ve İptal</h2>
      <h3>7.1 Sipariş İptali</h3>
      <p>Sipariş, sevk aşamasına geçmeden önce Platform üzerinden iptal talebinde bulunulabilir. Onaylı iptal talebi cari hesaba alacak olarak yansıtılır.</p>
      <h3>7.2 Ürün İadesi</h3>
      <p>İade kabul koşulları:</p>
      <ul>
        <li>Ürün, teslimat tarihinden itibaren en geç <strong>7 iş günü</strong> içinde iade talebinde bulunulmalıdır.</li>
        <li>Ürünün orijinal ambalajında, hasarsız ve kullanılmamış olması gerekir.</li>
        <li>Faturanın ibraz edilmesi zorunludur.</li>
        <li>Nakliye masrafı Bayi'ye aittir (hatalı/hasarlı teslimat halleri hariç).</li>
      </ul>
      <h3>7.3 İade Edilemeyen Durumlar</h3>
      <ul>
        <li>Ambalajı açılmış ve kullanılmış ürünler</li>
        <li>Özel sipariş / üretim ürünleri</li>
        <li>7 günlük süre aşılmış talepler</li>
        <li>Hasarın Bayi'den kaynaklandığı tespit edilen durumlar</li>
      </ul>

      <h2 id="yuzukumlululer">8. Bayi Yükümlülükleri</h2>
      <p>Bayi, Platform'u kullanırken aşağıdakileri kabul ve taahhüt eder:</p>
      <ul>
        <li>Platform'u yalnızca ticari amaçla ve bu Koşullara uygun kullanmak</li>
        <li>Hesap bilgilerini (kullanıcı adı, şifre) üçüncü kişilerle paylaşmamak</li>
        <li>Platform'u otomatik araçlarla (bot, scraper) kullanmamak</li>
        <li>Şirket'in ticari sırlarını, fiyat listelerini ve ticari bilgilerini gizli tutmak</li>
        <li>Rakip firmalara Platform üzerinden elde edilen bilgileri iletmemek</li>
        <li>Platforma zarar verecek her türlü davranıştan kaçınmak</li>
        <li>İletişim bilgileri ve adres değişikliklerini 5 iş günü içinde bildirmek</li>
      </ul>

      <h2 id="sorumluluk">9. Sorumluluk Sınırı</h2>
      <p>Şirket, aşağıdaki durumlarda sorumluluk kabul etmez:</p>
      <ul>
        <li>Bayi'nin şifresini üçüncü kişilerle paylaşması sonucu oluşan zararlar</li>
        <li>İnternet bağlantısı, hosting altyapısı veya üçüncü parti hizmetlerin kesintisi</li>
        <li>Mücbir sebep halleri (doğal afet, pandemi, savaş, grev vb.)</li>
        <li>Bayi'nin yanlış veya eksik bilgi girmesinden kaynaklanan hatalar</li>
        <li>Platform'un geçici bakım / güncelleme süreleri</li>
      </ul>
      <p>Her halükarda Şirket'in sorumluluğu, söz konusu siparişin fatura bedeliyle sınırlıdır.</p>

      <h2 id="fikri">10. Fikri Mülkiyet</h2>
      <p>Platform'un tüm yazılım, tasarım, içerik, logo, grafik ve veritabanı hakları <?= htmlspecialchars($companyName) ?>'a ve/veya lisans sahiplerine aittir. Bayi; bu unsurları kopyalayamaz, yeniden dağıtamaz, değiştiremez veya ticari amaçla kullanamaz.</p>
      <p>Teknik altyapı CODEGA (codega.com.tr) tarafından geliştirilmiştir; yazılım hakları saklıdır.</p>

      <h2 id="gizlilik">11. Gizlilik</h2>
      <p>Kişisel verilerin işlenmesi, 6698 sayılı KVKK ve <a href="/?page=privacy">Gizlilik Politikamız</a> çerçevesinde gerçekleştirilir. Bu Koşulların ayrılmaz bir parçasını oluşturur.</p>

      <h2 id="fesih">12. Fesih</h2>
      <p>Her iki taraf da yazılı bildirimle Bayi hesabını ve bu Koşulların uygulanmasını sona erdirebilir. Fesih tarihinden önce oluşmuş siparişler, borçlar ve alacaklar mevcut koşullar çerçevesinde tasfiye edilir. Fesih halinde Platform erişimi derhal sonlandırılır.</p>

      <h2 id="uyusmazlik">13. Uyuşmazlık Çözümü</h2>
      <p>Bu Koşullardan doğacak her türlü uyuşmazlıkta <strong>Türk Hukuku</strong> uygulanır. Uyuşmazlıkların çözümünde <strong>İstanbul (Çağlayan) Mahkemeleri ve İcra Daireleri</strong> yetkilidir.</p>
      <p>Uyuşmazlıklar öncelikle taraflar arasında müzakere yoluyla çözülmeye çalışılır. Müzakerenin sonuçsuz kalması halinde yasal yollara başvurulabilir.</p>

      <h2 id="degisiklik">14. Değişiklikler</h2>
      <p>Şirket, bu Koşulları herhangi bir zamanda güncelleme hakkını saklı tutar. Önemli değişiklikler Platform üzerinden Bayi'ye bildirilir. Değişiklik tarihinden sonra Platform'un kullanılmaya devam edilmesi, güncel Koşulların kabul edildiği anlamına gelir.</p>
      <div class="highlight">
        Bu Koşullar ile ilgili sorularınız için:<br>
        <strong><?= htmlspecialchars($companyName) ?></strong> — <a href="mailto:<?= htmlspecialchars($adminEmail) ?>"><?= htmlspecialchars($adminEmail) ?></a>
      </div>

    </article>
  </div>
</div>

<div class="page-footer">
  <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?> &mdash; Tüm hakları saklıdır. &nbsp;|&nbsp;
    <a href="/?page=privacy">Gizlilik Politikası</a> &nbsp;|&nbsp;
    <a href="/?page=login">Giriş Yap</a>
  </p>
</div>

</body>
</html>
<?php exit; ?>
