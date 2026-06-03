# 🛒 Sipariş Yönetimi — Modül Detayı

> Sürüm: v1.1.88+

## 📌 Konsept

Sipariş modülü, bayilerden gelen siparişlerin alımı, onaylanması, hazırlanması, kargo ve teslim süreçlerini yönetir. Ayrıca **fatura kesim takibi** ve **arşivleme** desteği vardır.

---

## 🔄 Sipariş Yaşam Döngüsü

```
[Sipariş Alındı] (bekliyor)
        │
        ▼ Onayla
[Onaylandı] (onaylandi)
        │
        ▼ Hazırlığa Al
[Hazırlanıyor] (hazirlaniyor)
        │
        ▼ Teslimata Çıkar
[Teslimata Çıktı] (kargoda)
        │
        ▼ Teslim Et
[Teslim Edildi] (teslim_edildi)
        │
        ▼ Arşivle
[ARŞİV]
```

### Yan Durumlar
- **İptal** (iptal) — herhangi bir aşamada iptal
- **İade** (iade) — teslim sonrası iade

---

## 📊 Filtre Sistemi (v1.1.88)

### Status Filtreleri
```
Tümü 25 | Sipariş Alındı 3 | Onaylandı 5 | Hazırlanıyor 8
       | Teslimata Çıktı 2 | Teslim Edildi 5 | İptal 2
```

### Billing (Fatura) Filtreleri
```
| 📑 Fatura Edilmiş 15 | 📝 Fatura Bekliyor 10
```

Dikey ayraç ile ayrılmış. Aynı sayfada hem **status** hem **billing** filtresi uygulanabilir.

### URL Parametreleri

```
?page=orders                              → Tümü
?page=orders&status=bekliyor              → Sadece bekleyen
?page=orders&billing=beklemede            → Faturasızlar
?page=orders&q=fours                      → Arama
?page=orders&status=hazirlaniyor&billing=kesildi → Kombine
```

---

## 📑 Fatura Numarası Sistemi (v1.1.75)

### Veritabanı

```sql
ALTER TABLE b2b_orders ADD COLUMN:
  invoice_no              VARCHAR(100)
  invoice_no_source       VARCHAR(20)    -- 'parasut' | 'manual'
  invoice_no_updated_at   DATETIME
  invoice_no_updated_by   INT
```

### Kaynaklar (Source)

| Kaynak | Anlam | Görsel |
|--------|-------|--------|
| `parasut` | Paraşüt API'sinden geldi | 📄 Paraşüt (mor badge) |
| `manual` | Admin manuel girdi | ✏️ Manuel (mavi badge) |

### Manuel Set Akışı

```php
POST: form_action=set_invoice_no
POST: order_id=42
POST: invoice_no=TG02026000000448

// Backend
UPDATE b2b_orders
   SET invoice_no=?,
       invoice_no_source='manual',
       invoice_no_updated_at=NOW(),
       invoice_no_updated_by=?
 WHERE id=?;
```

### Otomatik Set (Paraşüt)

```php
$resp = parasut()->createInvoice(...);
$invoiceNo = $resp['attributes']['invoice_no'];

UPDATE b2b_orders
   SET invoice_no=?,
       invoice_no_source='parasut',
       parasut_invoice_id=?,
       parasut_invoice_status='draft',
       parasut_synced_at=NOW()
 WHERE id=?;
```

---

## 📑 Fatura Kesim Durumu (v1.1.88)

### Konsept
Faturayı keseyim/kesmedim takibi. invoice_no ayrı bir konu, kesim durumu ayrı.

### Veritabanı (migration_026)

```sql
ALTER TABLE b2b_orders ADD COLUMN:
  invoice_billing_status      VARCHAR(16) DEFAULT 'beklemede'
  invoice_billing_updated_at  DATETIME
  invoice_billing_updated_by  INT
```

### Değerler

| Değer | Anlam | Görsel |
|-------|-------|--------|
| `beklemede` | Faturası kesilmedi | 📝 Bekliyor (sarı) |
| `kesildi` | Faturası kesildi | 📑 Fatura Kesildi (yeşil) |

### Migration Otomatiği

```sql
-- Mevcut fatura no'su olan siparişler otomatik 'kesildi' işaretlenir
UPDATE b2b_orders
   SET invoice_billing_status = 'kesildi'
 WHERE invoice_no IS NOT NULL AND invoice_no != '';
```

### Toggle UI

Liste ve detay sayfalarında:
```
📝 Bekliyor (sarı pill, tıkla → kesildi)
📑 Fatura Kesildi (yeşil pill, tıkla → beklemede)
```

### POST Handler

```php
if ($act === 'set_invoice_billing_status') {
    $oid = intval($_POST['order_id']);
    $newStatus = $_POST['billing_status']; // 'beklemede' | 'kesildi'

    UPDATE b2b_orders
       SET invoice_billing_status = ?,
           invoice_billing_updated_at = NOW(),
           invoice_billing_updated_by = ?
     WHERE id = ?;

    auditLog('invoice_billing_status_set', ...);

    // return_to URL ile aynı sayfaya geri dön (filtre korunur)
    redirect($_POST['return_to']);
}
```

---

## 📦 Arşiv Sistemi (v1.1.89)

### Konsept
Tamamlanmış (teslim, iptal, iade) siparişler ana listeyi karıştırmaması için arşive taşınır. Faturalama takibi arşivde de devam eder.

### Arşivleme

```sql
UPDATE b2b_orders
   SET is_archived = 1,
       archived_at = NOW(),
       archived_by = ?
 WHERE id = ?;
```

### Arşiv Sayfası

URL: `?page=orders&action=archive_list`

#### Yapı
```
🔎 Arama
└─ Tümü | 📑 Fatura Edilmiş | 📝 Bekliyor (3 filtre tabı)
   └─ Tablo
      ├─ Sipariş No
      ├─ Bayi
      ├─ Tarih
      ├─ Tutar
      ├─ Durum (teslim, iptal, iade)
      ├─ Fatura No + Billing Toggle  ← yeni
      ├─ Arşivlenme Tarihi
      └─ Aksiyonlar (Detay, Arşivden Çıkar)
```

#### URL Akışı

```
?page=orders&action=archive_list                  → Tümü
?page=orders&action=archive_list&billing=beklemede  → Faturasız
?page=orders&action=archive_list&q=fours&billing=kesildi → Arama + Filtre
```

### Senaryo: Faturasız Arşiv

1. Sipariş teslim edildi (faturasız)
2. Admin arşive taşıdı (status=teslim_edildi, billing=beklemede)
3. Hafta sonu admin: "Bu hafta faturalanmamış arşivlerim var mı?"
4. **Arşiv → 📝 Fatura Bekliyor** filtresi
5. Liste açılır, **kaç sipariş bekliyor** rozette
6. Tek tek faturasını kes (Paraşüt veya manuel)
7. Toggle butonuna bas → 📑 Fatura Kesildi
8. Filtre sayısı 1 azalır

---

## 🛠️ Sipariş Detay Sayfası

### Bölümler

1. **Üst Şerit**
   - Sipariş No + tarih
   - Bayi + iletişim
   - Toplam + ödeme durumu
   - Status dropdown (değiştirilebilir)

2. **Sol Panel**
   - Ürün tablosu
   - Adetler + fiyatlar
   - KDV breakdown
   - Toplam

3. **Sağ Panel**
   - Bayi bilgileri (özet)
   - Teslimat adresi
   - Sipariş notları

4. **Fatura Numarası Kartı**
   ```
   ┌──────────────────────────────────────────┐
   │ Fatura Numarası                          │
   │ ┌─────────────────────┐                  │
   │ │ TG02026000000448    │ 📄 Paraşüt       │
   │ └─────────────────────┘ 02.06.2026 19:02 │
   │                                          │
   │ ┌──────────────────────────────────────┐ │
   │ │ 📑 Fatura Kesildi  [↩ Bekliyor]    │ │
   │ └──────────────────────────────────────┘ │
   │                                          │
   │ [_____________________] [💾 Kaydet]      │
   └──────────────────────────────────────────┘
   ```

5. **Ödeme Bilgileri**
   - Ödeme yöntemi
   - Ödeme durumu (odenmedi/kismi/odendi)
   - Yapılan ödemeler tablosu

6. **Aksiyon Butonları**
   - Faturayı Kes (Paraşüt)
   - PDF İndir
   - İptal Et
   - Arşive Taşı

---

## 🔍 Sorgular

### Faturalanmamış arşiv siparişleri
```sql
SELECT COUNT(*) FROM b2b_orders
 WHERE is_archived = 1
   AND (invoice_billing_status = 'beklemede' OR invoice_billing_status IS NULL);
```

### Bu hafta faturalananlar
```sql
SELECT o.order_no, o.invoice_no, o.invoice_billing_updated_at
  FROM b2b_orders o
 WHERE o.invoice_billing_status = 'kesildi'
   AND o.invoice_billing_updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
 ORDER BY o.invoice_billing_updated_at DESC;
```

### Status + billing kombinasyon
```sql
-- Hazırlanan ama faturasız siparişler
SELECT * FROM b2b_orders
 WHERE status = 'hazirlaniyor'
   AND (invoice_billing_status = 'beklemede' OR invoice_billing_status IS NULL)
   AND is_archived = 0;
```

---

## 📨 Audit Log

| Eylem | Trigger |
|-------|---------|
| `order_status_changed` | Status dropdown değişikliği |
| `invoice_no_set` | Fatura no manuel girildi |
| `invoice_billing_status_set` | Billing toggle |
| `order_archived` | Arşive taşındı |
| `order_unarchived` | Arşivden çıkarıldı |
| `order_cancelled` | İptal edildi |
| `order_payment_method_changed` | Manuel tahsilat ile yöntem değişti |

---

## 🎯 İş Akışı Şablonları

### Standart Akış (Otomatik Onay)
```
1. Bayi sipariş verir
2. Sistem otomatik onaylar (Otomatik Onay tipi bayi ise)
3. Admin "Hazırlandı" yapar
4. Admin "Teslimata Çıktı" yapar
5. Admin "Teslim Edildi" yapar
6. Faturayı kes → 📑 Fatura Kesildi
7. Arşive taşı
```

### Manuel Onay Gereken Akış
```
1. Bayi sipariş verir → bekliyor
2. Admin inceler → Onayla / İptal
3. Onaylanmışsa: hazırla → teslim → faturalama → arşiv
```

### Acil İptal Akışı
```
1. Sipariş herhangi bir aşamadayken admin iptal eder
2. Sebep girer (audit log)
3. Stok geri verilir (eğer rezerve edildiyse)
4. Bayiye email gider
5. status='iptal'
```

---

*Modül: Sipariş Yönetimi · Sürüm: v1.1.88+ · Son güncelleme: 2026-06-02*
