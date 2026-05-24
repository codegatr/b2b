# CODEGA B2B — Değişiklik Geçmişi

Tüm sürüm değişikliklerini bu dosyada tutuyoruz. Her güncelleme öncesinde son sürümün notlarına bakıp ne yapıldığını gözden geçiriyoruz.

Format: `## v[X.Y.Z] — Açıklama (YYYY-MM-DD)`
İçerik: ✨ Yeni · 🐛 Düzeltme · 🔧 İyileştirme · ⚠️ Kırıcı · 🔒 Güvenlik · 📦 Paraşüt

---

## v1.1.66 — Paraşüt API V4 Tam Kapsam (2026-05-24)

### 📦 Paraşüt — 11 yeni metod
- `listCategories(type)` — ürün/cari kategorileri (item_categories)
- `listTags()` — etiket yönetimi
- `listSalesOffers()` — satış teklifleri (teklif → sipariş dönüşüm için temel)
- `listShipmentDocuments()` — irsaliye listesi
- `createShipmentDocument()` — sipariş için irsaliye oluştur
- `getProductInventory()` — Paraşüt stok seviyesi (B2B senkronu için)
- `getTrackableJob()` + `waitForJob()` — async iş takibi (e-belge oluşturma vb.)
- `listWebhooks()` + `createWebhook()` + `deleteWebhook()` — Paraşüt → B2B canlı event
- `isEInvoiceUser(vkn)` — VKN e-fatura mükellefi mi (e-arşiv vs e-fatura kararı için)
- `archiveContact()` + `unarchiveContact()` — cari pasifleştirme

### ✨ Yeni
- Paraşüt admin sayfasında **"Paraşüt API V4 Kapsam"** detaylı panel
- 18 endpoint için durum kartları (AKTİF / YENİ) + her birinin metod adı
- apidocs.parasut.com linkine yönlendirme

---

## v1.1.65 — Açık Bakiye Kart Ödeme + CHANGELOG (2026-05-24)

### ✨ Yeni
- **Açık Bakiye Kredi Kartı Ödeme**: Bayi cari hesabından açık borcunu tek tıkla kart ile ödeyebilir (Rubikpara 3D Secure). Önce sadece sipariş bazlı kart ödeme vardı, artık serbest ödeme de var.
- **CHANGELOG.md**: Tüm sürüm geçmişi tek dosyada (bu dosya).

### 🔧 İyileştirme
- `pages/account.php` → açık bakiyesi olan bayi için yeni "💳 Kart ile Öde" butonu
- `pages/payment-card.php` → `?balance=1&amount=X` parametresi ile order_id'siz mod
- Ödeme sonrası ledger'a otomatik 'alacak' kaydı + b2b_payments INSERT

---

## v1.1.64 — Tahsilat Duplicate + Borçlu Cari Engellemesi (2026-05-24)

### ✨ Yeni
- **Tahsilat duplicate tespiti**: Aynı bayi + tutar + sipariş için birden fazla bekleyen tahsilat → sarı arka plan + DUPLICATE rozeti
- **Borçlu Cari Sipariş Engellemesi** (3 seviyeli):
  - 🔴 Vadesi geçmiş borç engellemesi (`block_on_overdue`)
  - 🟡 Kredi limiti engellemesi (`block_over_limit`)
  - ⛔ Herhangi açık borç engellemesi (`block_on_debt`)
- `dealerCreditStatus($dealerId)` helper — bayinin borç durumunu sorgular
- Kişiselleştirilebilir borç engelleme mesajı

### 🔧 İyileştirme
- `admin/pages/payments.php` → yeni "Sipariş" kolonu (order_no link), tutar uyumsuzluğu uyarısı
- `pages/cart.php` → borç engellemesi UI kartı + form disable
- Double-submit JS koruması: submit edildikten sonra buton hemen disable + "⏳ Sipariş oluşturuluyor..."

### 🐛 Düzeltme
- Tahsilat sayfasında colspan 7 → 8 (sipariş kolonu eklendi)

---

## v1.1.63 — Paraşüt İsim Sıralaması + Arama (2026-05-23)

### 📦 Paraşüt
- `listAllContacts/Products` artık `sort=name` ile alfabetik çekiyor (G-01, G-02 vb. eski kayıtlar artık görünür)
- Aktarım sayfasına anlık arama kutusu (cariler + ürünler)
- "Tümünü seç" sadece görünür satırları seçer (filtreden geçenler)

---

## v1.1.62 — Paraşüt Syntax Fix (2026-05-23)

### 🐛 Düzeltme
- `admin/pages/parasut-mapping.php` satır 383'te fazla `}` kalmıştı (PHP parse hatası verdi)

---

## v1.1.61 — Arşivli Kayıtlar + Meta (2026-05-23)

### 📦 Paraşüt
- `listAllContactsWithMeta` / `listAllProductsWithMeta` — `meta.total_count` döndürür
- **Aktif + Arşivli** kayıtları iki ayrı çağrıyla çek + birleştir
- UI: "11/11 aktif + 56/56 arşivli" detay gösterimi
- Orphan listede "ARŞİVLİ" rozeti

---

## v1.1.60 — Sipariş Saat Penceresi + Paraşüt Seçerek Aktarım (2026-05-22)

### ✨ Yeni
- **Sipariş Saat Penceresi**: 10:00-17:00 gibi aralık + gün seçimi (Pzt-Cum default), bayilerin sipariş saatlerini kısıtla
- `orderWindowStatus()` helper
- Bayi dashboard'ında durum bantı (yeşil/kırmızı)
- Sepet butonu kapalı saatte disable
- **Paraşüt'ten Seçerek Aktarım**: orphan cariler ve ürünler için checkbox + "Tümünü seç" + "Seçilenleri Aktar"
- POST handlers: `import-contacts` (b2b_dealers INSERT), `import-products` (b2b_products INSERT)

---

## v1.1.59 — Paraşüt page_size + Endpoint Tanı (2026-05-22)

### 🐛 Düzeltme — KRİTİK
- **page[size] = 100 → 25** (Paraşüt API V4 hard limit). Önceden 422 hata veriyordu ve veri boş geliyordu.
- maxPages 10 → 40 (1000 kayıt destek)

### ✨ Yeni
- **🩺 Endpoint Tanı** (`?page=parasut&diag=1`): 4 endpoint'i ayrı ayrı test eder (/me, /contacts, /products, /accounts) + ham yanıt + yorumlama kılavuzu

---

## v1.1.58 — Eşleme Görsel İyileştirme (2026-05-22)

### ✨ Yeni
- API durumu uyarı kartı (sayfa başında): 3 senaryo (boş/kayıp/sağlıklı)
- 3-renkli satır: yeşil eşleşmiş, kırmızı kayıp ID, sarı bağlanmamış
- Kayıp ID dropdown'a "⚠️ KAYIP ID" option (selected, kırmızı)
- Eşleşmiş bayileri tablonun üstüne sırala
- String cast düzeltmesi (strict equality bug)

---

## v1.1.57 — moneyInc() Helper (2026-05-21)

### ✨ Yeni
- `moneyInc($net, $vatRate)` — DB'de net saklanan tutarları KDV Dahil formatlar
- Stok Yönetimi "Baz Fiyat" KDV Dahil + her satırda KDV oranı etiketi
- Paraşüt Eşleme "Ürünler" sekmesi baz fiyat KDV Dahil

---

## v1.1.56 — Stok SQL Fix (2026-05-21)

### 🐛 Düzeltme
- `admin/pages/stock.php` SQL hata: `b2b_order_items.quantity` → `qty` (doğru kolon adı)

---

## v1.1.55 — Stok Yönetimi Görsel Zenginleşme (2026-05-21)

### ✨ Yeni
- Stok tablosuna ürün resmi (48x48), kategori rozet, baz fiyat, stok progress bar, son 30 gün satış istatistikleri
- "~N gün kalır" bitiş tahmini (kritik altı için turuncu)

### 🐛 Düzeltme
- `admin/pages/parasut-mapping.php` SQL hata: `b2b_dealers.firm_name` → `company_name` (doğru kolon)
- COALESCE(company_name, first_name+last_name) → `display_name`

---

## v1.1.54 — Paraşüt isEnabled() (2026-05-20)

### 🔧 İyileştirme
- Paraşüt entegrasyonu credentials dolu ise otomatik aktif (eski `parasut_enabled` flag bağımlılığı kaldırıldı)

---

## v1.1.53 — Ledger Resync (2026-05-20)

### ✨ Yeni
- Tahsilat sayfasına "🔧 Ledger Eksik (N) - Düzelt" butonu
- Onaylı ama ledger'a yazılmamış tahsilatları toplu otomatik düzeltir

### 🐛 Düzeltme
- `migration_022.sql` `b2b_settings` kolon adları: `setting_key` → `skey`, `sval`, `sgroup`

---

## v1.1.52 — Paraşüt Kapsamlı Altyapı (2026-05-20)

### 📦 Paraşüt — 10+ yeni metod
- `checkVKN()` — Paraşüt'ten VKN sorgulama
- `createEArchive()`, `createEInvoice()` — E-Belge oluşturma
- `fullInvoiceFlow()` — sipariş → satış faturası → e-belge tek akış
- `getInvoicePdfUrl()`, `getInvoiceStatus()`, `cancelInvoice()`, `recoverInvoice()`
- `listInvoicesByContact()` — cari bazlı fatura listesi
- `listAccounts()` — banka/kasa hesapları
- `getContactBalance()`, `syncAllBalances()`
- `debitContact()`, `creditContact()` — manuel cari hareket
- `createPayment(account_id)` parametresi

### ✨ Yeni
- `migration_022.sql`: b2b_orders → parasut_einvoice_id, type, pdf_url, status, synced_at; b2b_dealers → parasut_balance, balance_updated, tax_office
- Settings: tahsilat hesabı dropdown, 3 toggle, senaryo radio
- Admin sipariş detayında "Paraşüt Faturası" kartı + 4 aksiyon
- Bayi panel sipariş header'da "📄 Faturayı İndir" buton

---

## v1.1.51 — Eşleme Sayfası (2026-05-19)

### ✨ Yeni
- `admin/pages/parasut-mapping.php` (yeni dosya)
- 2 sekme: Bayiler (Cariler) + Ürünler (Stoklar)
- Otomatik eşleştir (VKN/email/isim ile) + manuel dropdown
- 5 stat kartı + "Sadece Paraşüt'te Var" details bölümü

---

## v1.1.50 — Fiyat Listesi Sil (2026-05-19)

### ✨ Yeni
- Fiyat Listesi Sil aksiyonu
- `is_default` koruma (varsayılan silinmez)
- Atanmış bayileri varsayılana taşı, detaylı confirm dialog

---

## v1.1.49 — Kopyala JS Bug Fix (2026-05-19)

### 🐛 Düzeltme
- Inline onsubmit json_encode çift tırnak sorunu → class+data-attribute+addEventListener pattern

---

## v1.1.48 — Fiyat Listesi Kopyala (2026-05-19)

### ✨ Yeni
- `copy-list` action: INSERT...SELECT items + `isDefault=0` zorla
- Bayilerin atamasıyla aynı fiyatları başka liste olarak çoğalt

---

## v1.1.47 — header() Conflict Fix (2026-05-18)

### 🐛 Düzeltme
- 3 yerde `header('Location:')` → `redirect()` (save-list, assign, import-csv)
- "Headers already sent" hatası kalkıyor

---

## v1.1.46 — Standalone AJAX Dosyası (2026-05-18)

### ✨ Yeni
- `admin/ajax-price-list-item.php` — admin/index.php intercept'ten ayrı, güvenilir
- 3 başarısız denemeden sonra header conflict kesin çözümü

---

## v1.1.43 → 1.1.45 — Diagnostic + ob_clean (2026-05-18)

### 🐛 Düzeltme
- `testConnection /me` parse fix
- `admin/index.php` save-item intercept (yetmedi)
- `jsonResponse` ob_clean + early intercept (yetmedi → 1.1.46'da standalone dosya gerekti)

---

## v1.0.0 — İlk Yayın

İlk B2B sistemin yayına alınması. PHP 8.3 + MariaDB + DirectAdmin/LiteSpeed üzerinde, Smart Update v5 ile GitHub Releases dağıtımı.

---

## Sonraki Plan (Roadmap)

- 📦 Paraşüt: `listCategories`, `listShipmentDocuments` (irsaliye), `listSalesOffers` (teklif), `getInventory` (stok seviyesi senkronu), webhook
- ✨ Bayi düzenleme sayfasında `credit_limit` alanı (admin)
- ✨ Bayi listesinde "🔒 borç engelli" rozeti
- ✨ Toplu kayıp eşleşme sıfırlama butonu (parasut-mapping)
- 🔒 Stok hareketi otomatik audit (Paraşüt event sync)
