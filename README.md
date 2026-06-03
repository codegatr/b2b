# 🍽️ CODEGA B2B — Bayi Sipariş & Cari Yönetim Sistemi

[![Version](https://img.shields.io/badge/version-1.1.91-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg)](https://www.php.net/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.6+-003545.svg)](https://mariadb.org/)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)]()

Le Monde Du Tacos için geliştirilmiş, kurumsal B2B bayi sipariş ve cari hesap yönetim sistemidir. Paraşüt entegrasyonu, fatura takibi, çoklu ödeme yöntemleri ve aylık ciro primi hesaplaması destekler.

🌐 **Canlı:** [bayi.lemondedutacos.com](https://bayi.lemondedutacos.com)

---

## 📑 İçindekiler

- [Özellikler](#-özellikler)
- [Teknoloji Stack'i](#-teknoloji-stacki)
- [Kurulum](#-kurulum)
- [Güncelleme](#-güncelleme)
- [Modüller](#-modüller)
- [Dökümantasyon](#-dökümantasyon)
- [Geliştirme](#-geliştirme)

---

## ✨ Özellikler

### 🛒 Sipariş Yönetimi
- Sipariş alma, onaylama, hazırlama, kargo, teslim takibi
- 6 durumlu workflow + iptal/iade
- Fatura numarası takibi (manuel + Paraşüt otomatik)
- **Fatura Kesim Durumu**: "Kesildi" / "Bekliyor" toggle + filtreleme
- Arşivleme + 3 ayrı filtre (Tümü/Edilmiş/Bekliyor)
- Sipariş bazlı PDF çıktı

### 💳 Tahsilat Yönetimi
- 5 ödeme yöntemi: Havale/EFT, Nakit, **Kredi Kartı**, Çek, Senet
- **Kart Tahsilat Detayları**:
  - Slip onay kodu (bayi de görür)
  - Nereye çekildi (POS/tedarikçi — SADECE admin)
  - Ek kart notu (SADECE admin)
- Manuel tahsilat ile **siparişin ödeme yöntemini değiştirme** (havale → kart)
- Otomatik cari hesap güncellemesi
- Bekleyen/Onaylanan filtre tabları

### 👥 Bayi Yönetimi
- Detaylı bayi profili (firma, vergi, iletişim, adres)
- Kredi limiti + vade gün ayarı
- Sipariş onay tipi (manuel/otomatik)
- 4 ödeme yöntemi seçimi
- **Ciro Primi Oranı** tanımlama
- Bayi başvuru sistemi

### 💰 Ciro Primi (Aylık Komisyon)
- Bayi başına özel oran (%) + min eşik tutarı
- Aylık otomatik hesaplama (taslak yansıtma akışı)
- Cari hesaba ledger entegrasyonu
- İptal halinde TERS hareket otomatik
- **Dashboard'da uyarı** (geçen ay hesaplanmadıysa)
- PDF / Print çıktı

### 💵 Fiyat Listeleri
- Çoklu fiyat listesi (Standart, VIP, Toptan)
- Bayi başına atama
- Ürün ekleme/kopyalama/silme
- Excel benzeri toplu fiyat güncellemesi

### 📦 Stok & Ürün Yönetimi
- Çok kategorili ürün hiyerarşisi
- Stok takibi + minimum seviye uyarısı
- KDV oranı tanımı (0, 1, 10, 20)
- SKU + barkod desteği
- Birim çevirme (koli → adet)

### 🔗 Paraşüt Entegrasyonu (V4 API)
- **Cache mimari**: 1144 ürün + cariler DB'de tutulur, AJAX arama
- **SAFE sync**: DELETE yasak, UPSERT + stale tracking
- **Pagination rescue**: Rate limit'e takılınca 5sn bekle + 3x retry
- Cari kart eşleme (inline arama componenti)
- Ürün eşleme (inline arama componenti)
- Otomatik fatura kesme
- Tahsilat-fatura ilişkilendirme
- Stok senkronizasyonu
- **Cron destek**: dakika başı arka plan sync

### 📊 Raporlar & Cari
- Cari hesap dökümü
- Alacak/borç takibi
- Sipariş bazlı bakiye
- Excel/PDF çıktı

### 🔧 Smart Update v5
- GitHub Release tabanlı güncelleme
- Otomatik backup (FTP)
- Idempotent migration runner
- File-level SHA diffing
- Tek tıkla yeni sürüm

### 🎨 UI/UX
- Mobile-first responsive
- 13" laptop ekran optimizasyonu
- Sticky modal footer
- Tek panelde her şey (Single Page App hissi)
- Klavye kısayolları (eşleme arama)

---

## 🛠️ Teknoloji Stack'i

| Katman | Teknoloji |
|---|---|
| **Backend** | PHP 8.3 (procedural, framework-free) |
| **DB** | MariaDB 10.6+ (PDO + emulate_prepares=false) |
| **Hosting** | DirectAdmin + LiteSpeed (shared) |
| **Frontend** | Vanilla CSS + Vanilla JS (Bootstrap/Tailwind YOK) |
| **API** | Paraşüt V4, Custom REST |
| **Update** | GitHub Release + Smart Update v5 |
| **Versioning** | manifest.json + inc/version.php (single source) |

### Mimari Felsefe

```
✓ Framework yok (PSR-4/MVC yok)
✓ Composer minimal (Paraşüt SDK için)
✓ Vanilla JS (jQuery yok)
✓ Sade ve hızlı — shared hosting için optimize
✓ Idempotent migrations (INFORMATION_SCHEMA korumalı)
✓ GitHub Release deployment
```

---

## 🚀 Kurulum

### Ön Gereksinimler
- PHP 8.3+
- MariaDB 10.6+ (MySQL 8 de uyumlu)
- DirectAdmin/cPanel veya benzer (shared hosting destekler)
- HTTPS sertifikası (Let's Encrypt)
- En az 256 MB PHP memory
- Composer (Paraşüt SDK için)

### Adımlar

```bash
# 1. Repo'yu klonla veya zip indir
git clone https://github.com/codegatr/b2b.git
cd b2b

# 2. Composer bağımlılıkları
composer install --no-dev --optimize-autoloader

# 3. Config dosyası
cp config.local.example.php config.local.php
nano config.local.php
# DB bilgilerini gir, $debug = false
```

```php
// config.local.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'b2b_db');
define('DB_USER', 'kullanici');
define('DB_PASS', 'sifre');
define('SITE_URL', 'https://bayi.example.com');
```

```bash
# 4. DB oluştur
mysql -u root -p
> CREATE DATABASE b2b_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
> exit;

mysql -u root -p b2b_db < install/install.sql

# 5. Migration'ları koştur (opsiyonel - sayfa açılışında otomatik koşar)
php install/migrate.php

# 6. Admin kullanıcı oluştur
php install/create_admin.php
# email + şifre + isim sorar
```

### İlk Yapılandırma

1. `https://bayi.example.com/admin/` adresine git
2. Admin email + şifre ile giriş yap
3. **Ayarlar → Genel**: Site adı, logo, e-posta gönderici
4. **Ayarlar → Paraşüt**: API anahtarları (opsiyonel)
5. **Bayiler**: İlk bayileri ekle veya başvurularını onayla
6. **Ürünler**: Kategori + ürün ağacını oluştur
7. **Fiyat Listeleri**: En az 1 fiyat listesi tanımla

---

## 🔄 Güncelleme

### Smart Update v5 (Önerilen)

1. **Admin → Ayarlar → Güncelleme Merkezi**
2. **"Güncellemeleri Kontrol Et"** butonuna bas
3. Yeni sürüm varsa **"Güncelle"** tıkla
4. Sistem otomatik:
   - FTP backup alır
   - GitHub'dan ZIP indirir
   - Dosyaları günceller (SHA diffing)
   - Yeni migration'ları koşar
   - Cache temizler

### Manuel Yükseltme

```bash
# 1. Yedek al
mysqldump -u root -p b2b_db > backup-$(date +%Y%m%d).sql
zip -r files-backup-$(date +%Y%m%d).zip /var/www/b2b/

# 2. Yeni sürümü çek
cd /var/www/b2b
git fetch && git pull origin main

# 3. config.local.php DOKUNMA (override edilmez)

# 4. Migration'lar otomatik koşar (sayfa açılınca)
```

---

## 📁 Modüller

### Admin Paneli (`/admin`)
```
dashboard.php          → Ana panel + uyarılar
orders.php             → Sipariş yönetimi
payments.php           → Tahsilat yönetimi
dealers.php            → Bayi yönetimi
applications.php       → Bayi başvuruları
products.php           → Ürün yönetimi
categories.php         → Kategori ağacı
price-lists.php        → Fiyat listeleri
commissions.php        → Ciro primi (yeni!)
parasut-mapping.php    → Paraşüt eşleme + AJAX arama
ledger.php             → Cari hesap dökümü
reports.php            → Raporlar
settings.php           → Sistem ayarları + Update Center
```

### Bayi Paneli (`/`)
```
home.php              → Ürün katalog
cart.php              → Sepet
checkout.php          → Sipariş onay
orders.php            → Sipariş geçmişi
payments.php          → Ödeme bildirim + geçmiş
payment-card.php      → Kartla ödeme (Paraşüt CMS)
account.php           → Hesap detayı + bakiye
```

### Veritabanı Tabloları (özet)
```
b2b_admins            b2b_dealers
b2b_orders            b2b_order_items
b2b_payments          b2b_ledger
b2b_products          b2b_categories
b2b_price_lists       b2b_price_list_items
b2b_dealer_commissions (yeni!)
b2b_parasut_cache     b2b_audit_logs
b2b_settings          b2b_applications
```

---

## 📖 Dökümantasyon

### Ana Belgeler
- **[KULLANIM_KILAVUZU.md](KULLANIM_KILAVUZU.md)** — Detaylı admin kullanım rehberi
- **[CHANGELOG.md](CHANGELOG.md)** — Sürüm geçmişi

### Modül Belgeleri
- [docs/bayi-yonetimi.md](docs/bayi-yonetimi.md) — Bayi kayıt, düzenleme, başvurular
- [docs/siparis-yonetimi.md](docs/siparis-yonetimi.md) — Sipariş akışı, fatura, arşiv
- [docs/tahsilat-yonetimi.md](docs/tahsilat-yonetimi.md) — Manuel tahsilat, kredi kartı detayları
- [docs/parasut-entegrasyonu.md](docs/parasut-entegrasyonu.md) — API kurulum, cache, eşleme
- [docs/ciro-primi.md](docs/ciro-primi.md) — Aylık komisyon hesabı + PDF
- [docs/guncelleme.md](docs/guncelleme.md) — Smart Update + manuel

---

## 💻 Geliştirme

### Kod Standartları
- PHP 8.3+ syntax (typed properties, enums, readonly)
- 4 boşluk indentation
- Snake_case fonksiyon adları
- camelCase değişken adları
- Procedural — Class kullanma (Paraşüt SDK hariç)
- `db()`, `dbRow()`, `dbVal()`, `dbExec()`, `dbRows()`, `dbInsertRow()` helper'ları

### Database Helper Pattern
```php
// SORGU
$rows = dbRows("SELECT * FROM b2b_orders WHERE status=?", ['bekliyor']);

// TEK SATIR
$order = dbRow("SELECT * FROM b2b_orders WHERE id=?", [42]);

// TEK DEĞER
$count = dbVal("SELECT COUNT(*) FROM b2b_orders WHERE is_archived=1");

// INSERT (assoc array)
$newId = dbInsertRow('b2b_payments', [
    'dealer_id' => 5,
    'amount'    => 1500.00,
    'type'      => 'havale_eft',
]);

// UPDATE / DELETE
dbExec("UPDATE b2b_orders SET status=? WHERE id=?", ['onaylandi', 42]);
```

### Migration Yazma Kuralları
```sql
-- migrations/migration_XXX.sql

-- 1. Tablo ekleme: IF NOT EXISTS kullan
CREATE TABLE IF NOT EXISTS `b2b_yeni` (...);

-- 2. Kolon ekleme: IF NOT EXISTS kullan
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `yeni_alan` VARCHAR(50);

-- 3. Index ekleme: IF NOT EXISTS kullan
CREATE INDEX IF NOT EXISTS `idx_x` ON `b2b_orders` (`yeni_alan`);

-- 4. Sonunda flag (idempotency)
INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_XXX_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_XXX_done');
```

### Yeni Sayfa Ekleme
```php
// 1. admin/pages/your_page.php oluştur
<?php
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    // POST handling
}

// View
?>
<div class="page-header"><h1 class="page-title">Sayfa Başlığı</h1></div>
<div class="card">...</div>
```

```php
// 2. admin/index.php router'a ekle (zaten dinamik tarar)

// 3. Sidebar menüsü
<a href="?page=your_page" class="nav-item <?= $page==='your_page'?'active':'' ?>">
  Sayfa Adı
</a>
```

### Versiyon Bump
```bash
# manifest.json otomatik artar (Smart Update v5 release oluştururken)
# Manuel:
python3 -c "
import json
with open('manifest.json') as f: d = json.load(f)
parts = d['version'].split('.')
parts[-1] = str(int(parts[-1]) + 1)
d['version'] = '.'.join(parts)
with open('manifest.json','w') as f: json.dump(d, f, indent=2, ensure_ascii=False)
"
```

---

## 📞 Destek & İletişim

**Geliştirici:** CODEGA — Yunus Aksoy
**Müşteri:** Le Monde Du Tacos
**Repo:** [codegatr/b2b](https://github.com/codegatr/b2b)

### Sorun Bildirimi
GitHub Issues üzerinden:
1. Sürüm numarası (Admin → footer)
2. Sayfa URL'i
3. Hata ekran görüntüsü
4. Browser console log (F12)

---

## 📜 Lisans

Proprietary — CODEGA / Le Monde Du Tacos
Bu kod özel mülkiyettir, dağıtım veya kopyalama yasaktır.

---

## 🙏 Teşekkürler

- **Paraşüt** — V4 API
- **DirectAdmin** + **LiteSpeed** — hosting
- Sadece **Pure PHP + Vanilla JS** ile yapıldı. Framework yok.

---

*Son güncelleme: v1.1.91 — 2026-06-02*
