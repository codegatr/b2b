# 🔗 Paraşüt Entegrasyonu — Modül Detayı

> Sürüm: v1.1.85+ (cache + inline AJAX arama)

## 📌 Konsept

Sistem, [Paraşüt](https://parasut.com) V4 API ile entegre çalışır. Şu işlemleri otomatikleştirir:

- Fatura kesme
- Müşteri kartı eşleme
- Ürün eşleme
- Stok senkronizasyonu
- Tahsilat ilişkilendirme

---

## 🔑 Kurulum

### 1. Paraşüt Hesap Yapılandırması

1. Paraşüt → Ayarlar → Geliştirici → **API Erişim**
2. Yeni uygulama oluştur
3. **Client ID** + **Client Secret** al
4. Hesap kullanıcı adı + şifre + company_id

### 2. Sistem Yapılandırması

**Admin → Ayarlar → Paraşüt sekmesi**

```
Client ID:        XXXXXXXXXXXXXX
Client Secret:    YYYYYYYYYYYYYY
Username:         email@firma.com
Password:         ********
Company ID:       123456
```

### 3. Test

**"Bağlantıyı Test Et"** butonuna bas. Yeşil ✓ görmelisin:

```
✓ Paraşüt bağlantısı başarılı
  Şirket: Le Monde Du Tacos Tic. Ltd. Şti.
  Plan: Pro
  Aktif ürün: 1144
  Cari kart: 87
```

---

## 💾 Cache Mimari (v1.1.83+)

### Problem
Paraşüt'te 1144 ürün var. Eşleme sayfasında dropdown'a tek tek option olarak basmak:
- Sayfa yüklenmesi 5-10 saniye sürer
- Browser yavaşlar
- Aramak için Ctrl+F manuel

### Çözüm: DB Cache

Tüm Paraşüt verileri **b2b_parasut_cache** tablosunda tutulur.

#### Tablo Yapısı
```sql
CREATE TABLE b2b_parasut_cache (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('products','contacts'),       -- ürün veya cari
  parasut_id VARCHAR(50),                 -- Paraşüt'teki ID
  name VARCHAR(255),
  code VARCHAR(128),
  category_name VARCHAR(128),
  vat_rate DECIMAL(5,2),
  list_price DECIMAL(14,2),
  archived TINYINT(1),
  raw_data LONGTEXT,                      -- ham JSON
  synced_at DATETIME,
  UNIQUE KEY (kind, parasut_id)
);
```

### Cache Yönetimi

#### Manuel Senkron
**Ayarlar → Paraşüt → 🔄 Senkronize Et**

```php
// 2 aşama: aktif + arşivli
$active   = parasut()->listAllProductsWithMeta(200);
$archived = parasut()->listAllProductsWithMeta(200, ['archived' => 'true']);
```

#### Cron Senkron
**Ayarlar → Paraşüt → ⏰ Otomatik Senkronizasyon**

1. Token üret
2. Cron URL kopyala: `https://bayi.example.com/cron/parasut-sync.php?token=XXX`
3. Sunucuda cron tab'e ekle:
```bash
# Her 15 dakikada bir
*/15 * * * * curl -s "https://bayi.example.com/cron/parasut-sync.php?token=XXX"

# Veya saatte bir
0 * * * * curl -s "https://bayi.example.com/cron/parasut-sync.php?token=XXX"

# Gece 02:00 (lite mode)
0 2 * * * curl -s "https://bayi.example.com/cron/parasut-sync.php?token=XXX"
```

#### Cron Yanıt Örneği
```json
{
  "success": true,
  "products": {
    "success": true,
    "total": 1144,
    "active": 1100,
    "archived": 44,
    "duration": 67.5,
    "stale": 0,
    "parasut_total_active": 1144,
    "rescued_active": 3
  },
  "contacts": {
    "success": true,
    "total": 87,
    "duration": 8.2
  },
  "duration": 75.7,
  "time": "2026-06-02T02:00:15+03:00"
}
```

---

## 🛡️ SAFE Sync (v1.1.83)

### Eski Tehlikeli Mantık
```php
// DELETE + INSERT (TEHLİKELİ!)
dbExec("DELETE FROM b2b_parasut_cache WHERE kind='products'");
// Sonra INSERT...
```

**Sorun:** Paraşüt rate limit'e takılırsa 0 döner, DELETE çalışır ama INSERT eksik → tüm eşlemeler kaybolur.

### Yeni Güvenli Mantık
```php
// UPSERT — DELETE YOK
INSERT INTO b2b_parasut_cache ... ON DUPLICATE KEY UPDATE ..., synced_at = NOW();

// Stale tracking
$stale = COUNT(*) WHERE synced_at < $syncTime;
// Silinmez, sadece "bu sync'te dönmedi" diye sayılır
```

### Şüpheli Sync Koruması
```php
if ($existingTotal > 200 && $newCount < $existingTotal * 0.5) {
    // %50'den fazla düşüş = şüpheli, cache koru
    return ['error' => 'Şüpheli sync: X ürün döndü, eskiden Y vardı'];
}
```

---

## 🔄 Pagination Rescue (v1.1.84)

### Problem
Paraşüt rate limit her 10 sayfada bir devreye giriyor. cnt=0 dönüyor → "if cnt===0 break" bug'ı tetikleniyor → 250 üründe duruyor.

### Çözüm: 5sn Bekle + Retry
```php
if ($cnt === 0 && $totalPages > 0 && $p < $totalPages) {
    for ($retry = 1; $retry <= 3; $retry++) {
        sleep(5);  // 5 saniye sabırlı bekleme
        $res = $this->listProducts($p, 25, ...);
        if (count($res['data']) > 0) {
            $rescued++;
            break;  // Kurtarıldı!
        }
    }
}
```

### Throttle
- Sayfalar arası: **250ms** (eskiden 100ms)
- Toplam 46 sayfa için: ~11.5 sn throttle

### Diagnostic
Cron yanıtına eklenir:
- `parasut_total_active`: Paraşüt'ün dediği toplam
- `rescued_active`: Kaç sayfa rescue ile kurtarıldı
- `warning`: Eksik kaldıysa detay

---

## 🔍 Inline AJAX Arama (v1.1.85)

### Eski (Dropdown)
```html
<select name="parasut_id">
  <option value="123">Cheddar Sos (ID: 123)</option>
  ... <!-- 1144 option -->
</select>
```
Browser yavaş, arama zor.

### Yeni (Inline Search)
```html
<input type="text" placeholder="🔎 Paraşüt ürün ara...">
<div class="suggestions"><!-- AJAX doldurur --></div>
```

### AJAX Endpoint
```
GET ?page=parasut-mapping&ajax=search&kind=products&q=cheddar&limit=30

Response:
{
  "success": true,
  "items": [
    {
      "id": "69831265",
      "name": "Cheddar Sos",
      "code": "SOS-001",
      "category": "SOS GRUBU",
      "vat_rate": 10.0,
      "price": 45.50,
      "archived": false,
      "label": "Cheddar Sos"
    }
  ]
}
```

### JS Davranışı

```javascript
// Debounce 200ms
input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    const q = input.value.trim();
    if (q.length < 2) return;
    debounceTimer = setTimeout(() => doSearch(q), 200);
});

// Klavye desteği
input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') ...
    if (e.key === 'ArrowUp')   ...
    if (e.key === 'Enter')     activeItem.click();
    if (e.key === 'Escape')    closeSuggestions();
});
```

### Görsel Durumlar

| Durum | Border | Background |
|-------|--------|------------|
| Bağlı değil | Yok | — |
| Eşleşmiş | Yeşil (2px) | Hafif yeşil |
| Kayıp ID | Kırmızı (2px) | Hafif kırmızı |

---

## 🔗 Eşleme

### Sayfa: Paraşüt → Eşleme

**Sol menü** → Paraşüt → Eşleme

### Sekmeler

#### 1. Bayiler (Contacts)
B2B bayileri ↔ Paraşüt müşteri kartları

```
B2B Bayi         | Paraşüt Müşteri Eşlemesi    | Aksiyon
─────────────────────────────────────────────────────────
Fours Pillars    | 🔎 Paraşüt cari ara...      | + Oluştur
Deniz Berk       | ✓ Deniz Berk Ltd Şti       | (eşleşmiş)
```

#### 2. Ürünler (Products)
B2B ürünleri ↔ Paraşüt ürünleri

```
B2B Ürün          | Paraşüt Stok Eşlemesi       | Aksiyon
─────────────────────────────────────────────────────────
A-14 Adisyon Rulo | 🔎 Paraşüt ürün ara...     | + Oluştur
Cheddar Sos       | ✓ Cheddar Sos [SOS-001]    | (eşleşmiş)
```

### Eşleme Akışı

1. Sayfayı aç → her satırda arama input'u
2. Bir satırın input'una tıkla
3. **"cheddar"** yaz → 200ms sonra suggestions açılır
4. **↓** ile gez → **Enter** ile seç
5. Veya tıkla → otomatik seç + yeşil
6. **💾** butonuna bas → DB'ye kaydet:
```sql
UPDATE b2b_products
   SET parasut_product_id = '69831265'
 WHERE id = 42;
```

### Otomatik Eşleme

**🤖 Otomatik Eşleştir** butonu (henüz eşleşmemiş ürünler için):
- SKU bazında eşleştirir
- Yüzde 100 uyumlu kodları otomatik bağlar
- "Sadece henüz eşleşmemiş ürünleri etkiler"

---

## 📦 Stok Senkronizasyonu

### Akış (2 Aşama)

```
1. Cache yenile (UPSERT)
   ├─ Paraşüt'ten tüm ürünleri çek
   └─ b2b_parasut_cache'e yaz

2. Her B2B ürünü için:
   ├─ parasut_product_id var mı? → varsa
   ├─ getProductInventory(parasut_id) → gerçek stok
   ├─ 100ms throttle (rate limit)
   └─ UPDATE b2b_products SET stock_quantity = X
```

### Çağrı
**Ayarlar → Paraşüt → Stok Senkronize Et**

### Sonuç
```
✓ 234 ürünün stoğu güncellendi
× 12 ürün eşleşmediği için atlandı
× 3 ürün için Paraşüt'te kayıt bulunamadı
```

---

## 📄 Fatura Kesme

### Akış

1. Sipariş detayında **"Faturayı Kes (Paraşüt)"** butonu
2. Sistem yapar:
```php
// 1. Bayi Paraşüt cari'sine bağlı mı?
if (!$dealer['parasut_contact_id']) {
    error('Bayi Paraşüt cari'siyle eşleştirilmemiş');
}

// 2. Tüm sipariş ürünleri eşleşmiş mi?
foreach ($items as $item) {
    if (!$item['parasut_product_id']) {
        error('Ürün eşleşmemiş: ' . $item['name']);
    }
}

// 3. Fatura oluştur
$invoice = parasut()->createInvoice([
    'contact_id' => $dealer['parasut_contact_id'],
    'issue_date' => date('Y-m-d'),
    'due_date'   => date('Y-m-d', strtotime("+{$dealer['payment_term_days']} days")),
    'items'      => array_map(fn($item) => [
        'product_id' => $item['parasut_product_id'],
        'quantity'   => $item['quantity'],
        'unit_price' => $item['unit_price'],
        'vat_rate'   => $item['vat_rate'],
    ], $items)
]);

// 4. Sipariş güncelle
UPDATE b2b_orders SET
    parasut_invoice_id = $invoice['id'],
    invoice_no = $invoice['attributes']['invoice_no'],
    invoice_no_source = 'parasut',
    parasut_invoice_status = 'draft'
WHERE id = $orderId;
```

---

## 💳 Tahsilat İlişkilendirme

Onaylanan tahsilat otomatik Paraşüt fatura'sına bağlanır:

```php
// payment onaylandı
if ($order_id && $order['parasut_invoice_id']) {
    $payment = parasut()->createPayment([
        'invoice_id'   => $order['parasut_invoice_id'],
        'date'         => $payment['payment_date'],
        'amount'       => $payment['amount'],
        'description'  => 'B2B Tahsilat #' . $payment['id']
    ]);

    UPDATE b2b_payments SET parasut_payment_id = ? WHERE id = ?;
}
```

---

## 🛟 Sorun Giderme

### "Paraşüt'te Toplam: 0"
**Sebep:** Cache senkron başarısız.
**Çözüm:**
1. Bağlantı testi yap
2. Manuel sync dene
3. Cron loglarına bak

### "Şüpheli sync"
**Sebep:** Rate limit, yeni sync eskinin %50'sinden az.
**Çözüm:** 5-10 dk bekle, tekrar dene. Cache otomatik korunur.

### "X aktif + Y arşivli, biz Z çektik"
**Sebep:** Pagination rescue yetersiz kaldı.
**Çözüm:**
- maxPages'i artır (varsayılan 200)
- Throttle'ı artır (250ms → 500ms)
- Veya birkaç saat sonra tekrar dene

### Eşleme dropdown'da sonuç yok
**Sebep:** Cache boş veya arama parametresi eşleşmiyor.
**Çözüm:**
1. Cache stats kontrol: total > 0 olmalı
2. Daha kısa keyword dene
3. Türkçe karakterleri Latin'e çevir (Çağrı → cagri)

### Fatura kesilmiyor
**Kontrol Listesi:**
1. Bayi Paraşüt cari ile eşleşmiş mi?
2. Tüm sipariş ürünleri Paraşüt ürünleri ile eşleşmiş mi?
3. Paraşüt API token'i geçerli mi?
4. Şirket limiti dolmuş olabilir mi?

---

## 📊 Rate Limit Bilgisi

Paraşüt V4 API rate limit:
- **600 istek / saat**
- 25 ürün / sayfa = 24 sayfa/saat = 600 ürün/saat
- Bizim sync'imiz ~46 sayfa × 2 (aktif+arşiv) = 92 sayfa
- **250ms throttle** ile saatte 14.400 istek mümkün (kâğıt üzerinde)
- Pratikte daha agresif sınırlama olabilir → rescue mekanizması

---

## 🔧 Geliştirici Notları

### `includes/parasut.php` Yapısı

```php
function parasut(): Parasut { ... }                          // Singleton

class Parasut {
    public function listProducts($page, $perPage, $filter, $sort);
    public function listAllProductsWithMeta($maxPages, $filter, $sort);
    public function listAllContactsWithMeta($maxPages, $filter, $sort);
    public function createInvoice($data);
    public function createPayment($data);
    public function getProductInventory($productId);
    public function testConnection();
}

function parasut_cache_sync_products(): array { ... }
function parasut_cache_sync_contacts(): array { ... }
function parasut_cache_get_products($query, $exactSearch): array { ... }
function parasut_cache_get_contacts($query): array { ... }
function parasut_cache_stats(): array { ... }
```

### Helper'lar Hep Cache-First

```php
// ÖNCE cache'e bak, yoksa API'ye git
$product = parasut_cache_get_product_by_id($parasut_id);
if (!$product) {
    $product = parasut()->getProduct($parasut_id);
    parasut_cache_save_product($product);
}
```

---

*Modül: Paraşüt Entegrasyonu · Sürüm: v1.1.85+ · Son güncelleme: 2026-06-02*
