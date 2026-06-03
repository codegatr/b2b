# 🔄 Sistem Güncelleme — Modül Detayı

> Smart Update v5

## 📌 Konsept

Sistem **GitHub Release tabanlı** otomatik güncelleme mekanizmasına sahiptir. Tek tıkla yeni sürümlere geçilebilir, otomatik backup alınır, migration'lar koşulur.

---

## 🔧 Smart Update v5 Akışı

### Adımlar

```
1. Mevcut sürüm kontrol (manifest.json)
2. GitHub API'den son release'i al
3. Sürüm karşılaştır → yenisi varsa göster
4. Kullanıcı "Güncelle" butonuna bas
   ├─ FTP backup alınır (mevcut tüm dosyalar)
   ├─ DB dump alınır
   ├─ Release ZIP indirilir (uploads.github.com)
   ├─ SHA diffing yapılır (sadece değişen dosyalar)
   ├─ Dosyalar güncellenir (config.php hariç tut)
   ├─ migrations/*.sql sırasıyla koşar (idempotent)
   ├─ Cache temizlenir
   └─ Başarı mesajı
```

### Hata Durumunda
- Backup otomatik geri yüklenir
- Hata loglanır
- Kullanıcıya detaylı mesaj

---

## 📦 manifest.json Yapısı

```json
{
  "name": "CODEGA B2B",
  "version": "1.1.91",
  "repo": "codegatr/b2b",
  "min_php": "8.3",
  "min_mariadb": "10.6",
  "phases": [
    {
      "version": "1.0.0",
      "label": "İlk Sürüm",
      "released": "2026-05-01"
    },
    {
      "version": "1.1.91",
      "label": "Ciro Primi Sistemi",
      "released": "2026-06-02"
    }
  ],
  "config_files_protect": ["config.local.php"],
  "migrations_dir": "migrations/",
  "backup_dir": "backups/"
}
```

---

## 📁 inc/version.php

Tek dosya, tüm sürüm bilgilerini tutar:

```php
<?php
const APP_VERSION = '1.1.91';
const APP_RELEASED = '2026-06-02';
const APP_REPO = 'codegatr/b2b';
const APP_MIN_PHP = '8.3';

function getCurrentVersion(): string {
    return APP_VERSION;
}
```

`manifest.json` ile sync olur (single source of truth).

---

## 🗂️ Migration Yapısı

### Klasör
```
migrations/
  ├─ migration_001.sql  (initial schema)
  ├─ migration_002.sql
  ├─ ...
  ├─ migration_025.sql  (kart tahsilat)
  ├─ migration_026.sql  (fatura kesim durumu)
  └─ migration_027.sql  (ciro primi)
```

### Idempotent Pattern
Her migration **birden fazla kez koşabilmeli** (zarar vermemeli):

```sql
-- KOLON EKLEME
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `yeni_alan` VARCHAR(50);

-- TABLO EKLEME
CREATE TABLE IF NOT EXISTS `b2b_yeni` (...);

-- INDEX EKLEME
CREATE INDEX IF NOT EXISTS `idx_x` ON `b2b_orders` (`yeni_alan`);

-- VERİ GÜNCELLEMESİ (koşul ile)
UPDATE `b2b_orders` SET `status`='kesildi'
 WHERE `invoice_no` IS NOT NULL
   AND `status` IS NULL;
```

### Idempotency Flag
```sql
INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_027_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_027_done');
```

Sayfa açılışında migration runner şunu kontrol eder:
```php
foreach (glob('migrations/migration_*.sql') as $file) {
    $name = basename($file, '.sql');
    if (!dbVal("SELECT 1 FROM b2b_settings WHERE skey=?", [$name . '_done'])) {
        runMigration($file);
    }
}
```

---

## 🔒 Korumalı Dosyalar

Update sırasında **dokunulmaz** dosyalar:

- `config.local.php` — DB bilgileri, debug mode
- `uploads/**` — kullanıcı yüklemeleri (logo, dekontlar, vb.)
- `.htaccess` — özel kurallar varsa
- `data/runtime/**` — geçici veri

Bu dosyalar `manifest.json → config_files_protect` array'inde tanımlı.

---

## 🛟 Manuel Güncelleme

Smart Update kullanmak istemiyorsan veya çalışmıyorsa:

### Adım 1: Yedek Al

```bash
# DB dump
mysqldump -u root -p b2b_db > /backups/b2b-$(date +%Y%m%d).sql

# Dosya yedek
cd /var/www/
tar czf /backups/b2b-files-$(date +%Y%m%d).tar.gz b2b/
```

### Adım 2: Yeni Sürümü Çek

#### Yöntem A: Git Pull
```bash
cd /var/www/b2b
git fetch origin
git checkout main
git pull origin main
```

#### Yöntem B: ZIP İndirme
```bash
cd /tmp
wget https://github.com/codegatr/b2b/releases/download/v1.1.91/b2b-v1.1.91.zip
unzip b2b-v1.1.91.zip -d /tmp/b2b-new/

# config.local.php koru
cp /var/www/b2b/config.local.php /tmp/save/

# Yeni dosyaları kopyala
rsync -av --exclude='config.local.php' --exclude='uploads/' \
      /tmp/b2b-new/ /var/www/b2b/

# config'i geri koy
cp /tmp/save/config.local.php /var/www/b2b/
```

### Adım 3: Migration'lar

```bash
# Manuel migration runner
cd /var/www/b2b
php install/migrate.php

# Veya sadece sayfayı aç → otomatik koşar
curl https://bayi.example.com/admin/
```

### Adım 4: Cache Temizle

```bash
rm -rf /var/www/b2b/cache/*
# Browser'da CTRL+Shift+R
```

---

## 📊 Update Center UI

**Admin → Ayarlar → Güncelleme Merkezi**

### Mevcut Sürüm Kartı
```
┌────────────────────────────────────────┐
│ Mevcut Sürüm                           │
│ v1.1.91 — 2026-06-02                  │
│ "Ciro Primi (Aylık Komisyon) Sistemi" │
│                                        │
│ Son kontrol: 02.06.2026 18:30          │
│ [🔄 Güncellemeleri Kontrol Et]        │
└────────────────────────────────────────┘
```

### Yeni Sürüm Bildirimi (varsa)
```
┌────────────────────────────────────────┐
│ 🎉 Yeni Sürüm Mevcut!                  │
│ v1.1.92 → Yenilikler                  │
│                                        │
│ • Otomatik aylık prim hesaplama       │
│ • Excel export                         │
│                                        │
│ [📋 Detayları Gör]  [⬇️ Güncelle]      │
└────────────────────────────────────────┘
```

### Güncelleme Süreci (Progress)
```
🔒 FTP Backup alınıyor...        ✓ (8.2 sn)
📥 ZIP indiriliyor...             ✓ (15.5 sn)
🔍 SHA diff yapılıyor...          ✓ (3.1 sn)
📂 Dosyalar güncelleniyor...      ✓ (12.4 sn)
🛠️ Migrations koşturuluyor...    ✓ (2.3 sn)
🧹 Cache temizleniyor...          ✓ (1.0 sn)
─────────────────────────────────
✓ Güncelleme tamamlandı! (42.5 sn)
v1.1.91 → v1.1.92
```

---

## 🐛 Sorun Giderme

### "Yeni sürüm yok"
**Kontrol:**
- GitHub API rate limit aşılmış olabilir
- 1 saat sonra tekrar dene
- Manuel: `https://api.github.com/repos/codegatr/b2b/releases/latest`

### "Migration başarısız"
**Olası sebepler:**
- DB user'a ALTER yetki yok
- Tablo lock'lanmış (bekleyen sorgu)
- Disk dolu

**Çözüm:**
```bash
# Hata mesajı detayını gör
tail -100 /var/log/b2b-error.log

# Manuel koştur
mysql -u root -p b2b_db < migrations/migration_027.sql
```

### "Dosya güncellenemiyor"
**Sebep:** Dosya izinleri
**Çözüm:**
```bash
chown -R apache:apache /var/www/b2b/
chmod -R 755 /var/www/b2b/
```

### Geri Alma (Rollback)

Backup otomatik alınır:
```bash
# Backup klasörü
ls -lh /var/www/b2b/backups/

# Restore
cd /var/www/b2b
tar xzf backups/b2b-files-20260602.tar.gz
mysql -u root -p b2b_db < backups/b2b-20260602.sql
```

---

## 🔑 GitHub PAT Token

Smart Update için **Personal Access Token** kullanılır:

### Token Yenileme (30 günde bir)

1. GitHub → Settings → Developer settings → PAT
2. **Generate new token (classic)**
3. Scopes: `repo` (private repo için)
4. Expiration: 30 days
5. Token'i kopyala → **Ayarlar → Smart Update → Token** alanına yapıştır
6. Test et

### Otomatik Hatırlatma

Token süresi dolmadan 5 gün önce admin'e bildirim gider.

---

## 📜 Sürüm Numaralandırma

Semantic Versioning (semver) benzeri:

```
MAJOR.MINOR.PATCH
```

- **MAJOR**: Breaking change (DB sıfırla gibi)
- **MINOR**: Yeni özellik (bu sürüm için)
- **PATCH**: Bug fix, küçük iyileştirme

Şu an: **1.1.91** = (MAJOR=1) . (MINOR=1) . (PATCH=91)

### Bump Stratejisi
Bir özellik tek commit'le bile yapılsa PATCH artar. Aktif geliştirme dönemlerinde MINOR çok yavaş artar.

---

*Modül: Smart Update v5 · Son güncelleme: 2026-06-02*
