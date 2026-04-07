<?php
// pages/privacy.php — Gizlilik Politikası & KVKK
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
<title>Gizlilik Politikası &amp; KVKK — <?= htmlspecialchars($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--green:#3A5F0B;--green-d:#2a4508;--ink:#1f2937;--muted:#6b7280;--cream:#f5f0e8;--border:#e5e7eb}
html,body{font-family:'Inter',-apple-system,sans-serif;font-size:15px;line-height:1.75;color:var(--ink);background:#fff}
a{color:var(--green);text-decoration:none}
a:hover{text-decoration:underline}

/* Topbar */
.topbar{background:var(--green);padding:14px 0;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(58,95,11,.3)}
.topbar-inner{max-width:900px;margin:0 auto;padding:0 24px;display:flex;align-items:center;justify-content:space-between}
.topbar-brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:700;font-size:.95rem;text-decoration:none}
.topbar-brand-mark{width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:8px;display:flex;align-items:center;justify-content:center}
.topbar-back{color:rgba(255,255,255,.8);font-size:.82rem;display:flex;align-items:center;gap:5px;text-decoration:none}
.topbar-back:hover{color:#fff;text-decoration:none}

/* Hero */
.hero{background:var(--cream);border-bottom:1px solid var(--border);padding:52px 24px}
.hero-inner{max-width:900px;margin:0 auto}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(58,95,11,.1);border:1px solid rgba(58,95,11,.2);color:var(--green);border-radius:99px;padding:4px 12px;font-size:.72rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;margin-bottom:16px}
.hero h1{font-family:'Playfair Display',Georgia,serif;font-size:2.4rem;font-weight:700;color:var(--ink);margin-bottom:10px;letter-spacing:-.02em}
.hero-meta{font-size:.82rem;color:var(--muted)}
.hero-meta strong{color:var(--ink)}

/* İçerik */
.content{max-width:900px;margin:0 auto;padding:52px 24px 80px}

/* Kenar çubuğu nav */
.layout{display:grid;grid-template-columns:220px 1fr;gap:48px;align-items:start}
.sticky-nav{position:sticky;top:80px}
.nav-list{list-style:none;border-left:2px solid var(--border)}
.nav-list li{margin-bottom:2px}
.nav-list a{display:block;padding:7px 14px;font-size:.82rem;color:var(--muted);border-left:2px solid transparent;margin-left:-2px;transition:color .15s,border-color .15s}
.nav-list a:hover{color:var(--green);border-color:var(--green);text-decoration:none}
.nav-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);padding:0 14px 10px;margin-top:4px}

/* Makale */
article h2{font-family:'Playfair Display',Georgia,serif;font-size:1.35rem;font-weight:700;color:var(--ink);margin:44px 0 14px;padding-top:12px;border-top:1px solid var(--border)}
article h2:first-child{margin-top:0;border-top:none}
article h3{font-size:1rem;font-weight:700;color:var(--ink);margin:24px 0 10px}
article p{margin-bottom:14px;color:#374151}
article ul,article ol{margin:10px 0 14px 20px;color:#374151}
article li{margin-bottom:6px}
article strong{color:var(--ink)}
article .highlight{background:rgba(58,95,11,.07);border-left:3px solid var(--green);padding:14px 18px;border-radius:0 8px 8px 0;margin:18px 0;font-size:.9rem}
article table{width:100%;border-collapse:collapse;font-size:.87rem;margin:16px 0}
article table th{background:var(--cream);padding:10px 14px;text-align:left;font-weight:600;border:1px solid var(--border)}
article table td{padding:9px 14px;border:1px solid var(--border);color:#374151;vertical-align:top}

/* Footer */
.page-footer{background:var(--cream);border-top:1px solid var(--border);padding:28px 24px;text-align:center;font-size:.8rem;color:var(--muted)}
.page-footer a{color:var(--green)}

@media(max-width:720px){
  .layout{grid-template-columns:1fr}
  .sticky-nav{display:none}
  .hero h1{font-size:1.8rem}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-inner">
    <a href="pages/login.php" class="topbar-brand">
      <div class="topbar-brand-mark">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>
      </div>
      <?= htmlspecialchars($siteName) ?>
    </a>
    <a href="pages/login.php" class="topbar-back">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
      Giriş Sayfasına Dön
    </a>
  </div>
</div>

<div class="hero">
  <div class="hero-inner">
    <div class="hero-tag">KVKK &amp; Gizlilik</div>
    <h1>Gizlilik Politikası</h1>
    <p class="hero-meta">Son güncelleme: <strong><?= $today ?></strong> &nbsp;·&nbsp; <?= htmlspecialchars($companyName) ?></p>
  </div>
</div>

<div class="content">
  <div class="layout">

    <!-- Navigasyon -->
    <nav class="sticky-nav">
      <div class="nav-title">İçindekiler</div>
      <ul class="nav-list">
        <li><a href="#veri-sorumlusu">1. Veri Sorumlusu</a></li>
        <li><a href="#toplanan-veriler">2. Toplanan Veriler</a></li>
        <li><a href="#isleme-amaci">3. İşleme Amacı</a></li>
        <li><a href="#hukuki-dayanak">4. Hukuki Dayanak</a></li>
        <li><a href="#aktarim">5. Veri Aktarımı</a></li>
        <li><a href="#saklama">6. Saklama Süresi</a></li>
        <li><a href="#haklariniz">7. Haklarınız</a></li>
        <li><a href="#cerezler">8. Çerezler</a></li>
        <li><a href="#guvenlik">9. Güvenlik</a></li>
        <li><a href="#iletisim">10. İletişim</a></li>
      </ul>
    </nav>

    <!-- İçerik -->
    <article>

      <h2 id="veri-sorumlusu">1. Veri Sorumlusu</h2>
      <p>Bu Gizlilik Politikası, 6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") ve ilgili mevzuat kapsamında <strong><?= htmlspecialchars($companyName) ?></strong> ("Şirket", "Biz") tarafından hazırlanmıştır.</p>
      <div class="highlight">
        <strong>Veri Sorumlusu:</strong> <?= htmlspecialchars($companyName) ?><br>
        <strong>Adres:</strong> <?= htmlspecialchars($companyAddr) ?><br>
        <strong>E-posta:</strong> <?= htmlspecialchars($adminEmail) ?>
      </div>
      <p>Bu platform, şirketin yetkili bayileri tarafından kullanılmak üzere tasarlanmış kapalı bir B2B (işletmeden işletmeye) sistemidir. Kamuya açık bir hizmet değildir.</p>

      <h2 id="toplanan-veriler">2. Toplanan Kişisel Veriler</h2>
      <p>Bayi portalı üzerinden aşağıdaki kişisel veriler toplanmaktadır:</p>

      <table>
        <thead><tr><th>Veri Kategorisi</th><th>Veriler</th><th>Kaynak</th></tr></thead>
        <tbody>
          <tr><td><strong>Kimlik</strong></td><td>Ad, soyad, yetkili kişi adı</td><td>Üyelik formu / admin tanımı</td></tr>
          <tr><td><strong>İletişim</strong></td><td>E-posta, telefon, adres, şehir</td><td>Üyelik formu / admin tanımı</td></tr>
          <tr><td><strong>Finansal</strong></td><td>Vergi numarası, vergi dairesi, cari bakiye, sipariş tutarları</td><td>Form + ERP entegrasyonu</td></tr>
          <tr><td><strong>İşlem</strong></td><td>Sipariş geçmişi, ödeme kayıtları, dekont bilgileri</td><td>Platform kullanımı</td></tr>
          <tr><td><strong>Teknik</strong></td><td>Oturum bilgileri, işlem logları, IP adresi</td><td>Otomatik (sunucu kaydı)</td></tr>
        </tbody>
      </table>

      <h2 id="isleme-amaci">3. Kişisel Verilerin İşlenme Amacı</h2>
      <p>Kişisel verileriniz aşağıdaki amaçlarla işlenmektedir:</p>
      <ul>
        <li>Bayi hesabının oluşturulması, yönetilmesi ve doğrulanması</li>
        <li>Sipariş alma, işleme ve takibi</li>
        <li>Fatura, irsaliye ve ödeme belgelerinin düzenlenmesi</li>
        <li>Cari hesap ve ekstre yönetimi</li>
        <li>Stok ve ürün bilgilerinin iletilmesi</li>
        <li>Ödeme bildirimlerinin alınması ve onaylanması</li>
        <li>Yasal yükümlülüklerin yerine getirilmesi (VUK, TTK, KDV Kanunu)</li>
        <li>Sistem güvenliğinin sağlanması ve yetkisiz erişimin önlenmesi</li>
        <li>İş sürekliliği ve teknik destek</li>
      </ul>

      <h2 id="hukuki-dayanak">4. Hukuki Dayanak</h2>
      <p>Kişisel verileriniz KVKK Madde 5 ve 6 kapsamında aşağıdaki hukuki dayanaklara göre işlenmektedir:</p>
      <ul>
        <li><strong>Sözleşmenin kurulması veya ifası (Md. 5/2-c):</strong> Bayi sözleşmesi, sipariş ve teslimat süreçleri</li>
        <li><strong>Hukuki yükümlülük (Md. 5/2-ç):</strong> Vergi, muhasebe ve ticaret hukuku yükümlülükleri</li>
        <li><strong>Meşru menfaat (Md. 5/2-f):</strong> Sistem güvenliği, hata takibi, iş sürekliliği</li>
        <li><strong>Açık rıza (Md. 5/1):</strong> Yukarıdakilerin dışında kalan durumlarda (pazarlama, analiz)</li>
      </ul>

      <h2 id="aktarim">5. Kişisel Verilerin Aktarımı</h2>
      <p>Verileriniz aşağıdaki taraflara, yalnızca gerekli ölçüde aktarılabilmektedir:</p>
      <ul>
        <li><strong>Paraşüt Yazılım A.Ş.:</strong> Fatura ve muhasebe entegrasyonu (işleme dayalı aktarım)</li>
        <li><strong>Barındırma/Hosting sağlayıcısı:</strong> Sunucu ve veri depolama hizmetleri</li>
        <li><strong>Kargo ve lojistik firmaları:</strong> Teslimat süreçleri için zorunlu bilgiler</li>
        <li><strong>Yetkili kamu kurum ve kuruluşları:</strong> Yasal zorunluluk kapsamında (mahkeme, vergi dairesi, SGK vb.)</li>
      </ul>
      <p>Verileriniz üçüncü ülkelere aktarılmamaktadır. Yurt içi aktarımlar KVKK'nın 8. maddesi çerçevesinde gerçekleştirilir.</p>

      <h2 id="saklama">6. Saklama Süresi</h2>
      <table>
        <thead><tr><th>Veri Türü</th><th>Saklama Süresi</th><th>Dayanak</th></tr></thead>
        <tbody>
          <tr><td>Sipariş ve fatura kayıtları</td><td>10 yıl</td><td>VUK Md. 253, TTK Md. 82</td></tr>
          <tr><td>Ödeme ve tahsilat belgeleri</td><td>10 yıl</td><td>VUK Md. 253</td></tr>
          <tr><td>Bayi hesap bilgileri</td><td>Sözleşme süresi + 10 yıl</td><td>TTK Md. 82</td></tr>
          <tr><td>Sistem ve işlem logları</td><td>2 yıl</td><td>Meşru menfaat</td></tr>
          <tr><td>Bayilik başvuruları (red)</td><td>6 ay</td><td>Meşru menfaat</td></tr>
        </tbody>
      </table>
      <p>Saklama süresi dolan veriler güvenli yöntemlerle silinir, yok edilir veya anonim hale getirilir.</p>

      <h2 id="haklariniz">7. KVKK Kapsamındaki Haklarınız</h2>
      <p>KVKK'nın 11. maddesi uyarınca veri sahibi olarak aşağıdaki haklara sahipsiniz:</p>
      <ul>
        <li>Kişisel verilerinizin işlenip işlenmediğini <strong>öğrenme</strong></li>
        <li>Kişisel verileriniz işlenmişse buna ilişkin <strong>bilgi talep etme</strong></li>
        <li>Kişisel verilerinizin işlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını <strong>öğrenme</strong></li>
        <li>Yurt içinde veya yurt dışında kişisel verilerinizin aktarıldığı üçüncü kişileri <strong>bilme</strong></li>
        <li>Kişisel verilerinizin eksik veya yanlış işlenmiş olması hâlinde bunların <strong>düzeltilmesini isteme</strong></li>
        <li>Yasal koşulların oluşması hâlinde kişisel verilerinizin <strong>silinmesini veya yok edilmesini isteme</strong></li>
        <li>İşlemenin yalnızca otomatik sistemler vasıtasıyla gerçekleştirilmesi hâlinde ortaya çıkabilecek aleyhte sonuca <strong>itiraz etme</strong></li>
        <li>Kişisel verilerinizin kanuna aykırı olarak işlenmesi sebebiyle zarara uğraması hâlinde <strong>zararın giderilmesini talep etme</strong></li>
      </ul>
      <div class="highlight">
        Haklarınızı kullanmak için <a href="mailto:<?= htmlspecialchars($adminEmail) ?>"><?= htmlspecialchars($adminEmail) ?></a> adresine yazılı olarak başvurabilirsiniz. Başvurular 30 gün içinde yanıtlanır. Yanıt beklentinizi karşılamıyorsa <strong>Kişisel Verileri Koruma Kurumu (KVKK)</strong>'na şikâyette bulunabilirsiniz.
      </div>

      <h2 id="cerezler">8. Çerezler (Session)</h2>
      <p>Bu platform yalnızca oturum yönetimi için zorunlu PHP session çerezi kullanır. İzleme, profilleme veya pazarlama amacıyla herhangi bir üçüncü taraf çerezi veya analitik aracı kullanılmamaktadır.</p>
      <ul>
        <li><strong>PHPSESSID:</strong> Kullanıcı oturumunu tanımlar, tarayıcı kapanınca silinir (session cookie)</li>
      </ul>
      <p>Çerez ayarlarını tarayıcınızdan yönetebilirsiniz; ancak oturum çerezini devre dışı bırakmak platformun çalışmasını engelleyecektir.</p>

      <h2 id="guvenlik">9. Güvenlik Önlemleri</h2>
      <p>Kişisel verilerinizi korumak için aşağıdaki teknik ve idari tedbirler alınmıştır:</p>
      <ul>
        <li>HTTPS ile şifreli iletişim</li>
        <li>Şifreler bcrypt algoritması ile hash'lenerek saklanır (asla düz metin)</li>
        <li>CSRF token koruması tüm form işlemlerinde aktiftir</li>
        <li>Oturum güvenliği: session fixation ve hijacking önlemleri</li>
        <li>Yetkisiz dosya yükleme engeli (.htaccess ile PHP çalıştırma yasağı)</li>
        <li>Tüm kritik işlemler audit log'a kaydedilir</li>
        <li>Güncelleme sistemi: yalnızca yetkili GitHub deposundan ZIP alınır</li>
      </ul>

      <h2 id="iletisim">10. İletişim ve Değişiklikler</h2>
      <p>Gizlilik politikamıza ilişkin sorularınız için:</p>
      <div class="highlight">
        <strong><?= htmlspecialchars($companyName) ?></strong><br>
        <?= htmlspecialchars($companyAddr) ?><br>
        E-posta: <a href="mailto:<?= htmlspecialchars($adminEmail) ?>"><?= htmlspecialchars($adminEmail) ?></a>
      </div>
      <p>Bu politika, yasal değişiklikler veya platform güncellemeleri doğrultusunda revize edilebilir. Önemli değişiklikler bayi hesabınıza bildirim olarak iletilir. Politikanın güncel halini bu sayfadan takip edebilirsiniz.</p>

    </article>
  </div>
</div>

<div class="page-footer">
  <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?> &mdash; Tüm hakları saklıdır. &nbsp;|&nbsp;
    <a href="?page=terms">Kullanım Koşulları</a> &nbsp;|&nbsp;
    <a href="pages/login.php">Giriş Yap</a>
  </p>
</div>

</body>
</html>
<?php exit; ?>
