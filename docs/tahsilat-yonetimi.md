# 💳 Tahsilat Yönetimi — Modül Detayı

> Sürüm: v1.1.86+

## 📌 Konsept

Tahsilat modülü, bayilerden gelen ödemelerin kaydı, onayı ve cari hesaba yansıtılması ile ilgilenir.

### Ödeme Yöntemleri

| Yöntem | Kod | Bayi Bildirebilir | Admin Manuel | Özel Alanlar |
|--------|-----|-------------------|--------------|--------------|
| Havale/EFT | `havale_eft` | ✅ | ✅ | Banka adı, dekont no |
| Nakit | `nakit` | ❌ | ✅ | — |
| **Kredi Kartı** | `kredi_karti` | ✅ (CMS) | ✅ | Onay kodu + Nereye çekildi + Kart notu |
| Çek | `cek` | ❌ | ✅ | — |
| Senet | `senet` | ❌ | ✅ | — |

---

## 🔄 İş Akışı

### Bayi Tarafı (Havale/EFT Bildirimi)
```
1. Bayi panelinde "Ödeme Bildir" → form doldurur
2. Tutar, banka, dekont no, açıklama
3. status='bekliyor' olarak kayıt edilir
4. Admin'e bildirim
```

### Admin Tarafı (Onaylama)
```
1. Tahsilat → Bekleyen sekmesi
2. "Onayla" tıkla
3. status='onaylandi'
4. b2b_ledger'a alacak kaydı eklenir
5. Sipariş bağlıysa: payment_status güncellenir
```

### Manuel Tahsilat (Admin Direkt Girişi)
```
1. Tahsilat → + Manuel Tahsilat
2. Bayi seç → siparişler dropdown'a yüklenir
3. Sipariş seç (opsiyonel) → tutar otomatik dolur
4. Yöntem seç → kredi_karti ise ekstra alanlar açılır
5. Kaydet → otomatik onaylı + ledger güncelle
```

---

## 💳 Kredi Kartı Özel Senaryosu

### Sorun
Müşteri (bayi) kartını **bizim POS'umuza** değil, **başka bir tedarikçi POS'una** çekti.

### Çözüm (v1.1.86)
3 alan eklendi:

| Alan | Veri | Görünürlük |
|------|------|------------|
| `card_auth_code` | Slip onay kodu (123456) | **Admin + Bayi** |
| `card_receiver` | Hangi POS (XYZ Tedarikçi) | **Sadece Admin** |
| `card_notes` | Ek not | **Sadece Admin** |

### Admin Görünüm

```
Liste satırı:
─────────────────────────────────────────────
Tarih   Bayi          Tutar    Yöntem
26.05   Ds Group      3.500₺   Kredi Kartı
                              Onay: 123456
                              POS: [XYZ Tedarikçi]   ← sadece admin
─────────────────────────────────────────────
Alt satır (genişletme):
Kart notu: Müşteri tedarikçiye çekti  ← sadece admin
```

### Bayi Görünüm

```
Tarih    Tutar    Yöntem        Banka/Onay Kodu
26.05    3.500₺   kredi_karti   [123456]
                  💳 Slip Onay:
                  123456
```

`card_receiver` ve `card_notes` **hiç render edilmez**.

---

## 🔗 Sipariş İlişkilendirme (v1.1.87)

### Senaryo
Bayi havale seçmiş ama sonradan kartla ödemek istiyor.

### Akış

1. **+ Manuel Tahsilat** aç
2. Bayi seç
3. **Sipariş seç** → bilgi kartı açılır:
```
┌────────────────────────────────────────┐
│ Toplam: 5.000 ₺   Ödenmiş: 1.500 ₺   │
│ Kalan:  3.500 ₺   Durum: Hazırlanıyor │
│ ─────────────────────────────────────  │
│ Siparişteki ödeme yöntemi: [Havale]   │
│ 💡 Yeni yöntem seçince güncellenir    │
└────────────────────────────────────────┘
```
4. **Tutar otomatik** 3.500 ₺ dolu
5. **Ödeme Yöntemi: Kredi Kartı** seç
6. Kart detayları doldur
7. **Kaydet**

### Backend Otomatiği

```php
// 1. Tahsilat kaydı
INSERT INTO b2b_payments (type='kredi_karti', card_auth_code, ...);

// 2. Sipariş ödeme yöntemi DEĞİŞTİYSE güncelle
if ($method !== $oldMethod) {
    UPDATE b2b_orders SET payment_method='kredi_karti' WHERE id=?;
    auditLog('order_payment_method_changed', ['from'=>'havale','to'=>'kredi_karti']);
}

// 3. Sipariş tamamen ödendiyse status güncelle
if ($totalPaid >= $orderTotal) {
    UPDATE b2b_orders SET payment_status='odendi';
}

// 4. Ledger
ledgerAdd($dealerId, 'alacak', $amount, ...);
```

### Başarı Mesajı

```
Manuel tahsilat kaydedildi · SIP260525001 sipariş ödeme durumu
güncellendi · ödeme yöntemi: havale → kredi_karti
```

---

## 📊 Veritabanı Şeması

### `b2b_payments`

| Kolon | Tip | Açıklama |
|-------|-----|----------|
| `id` | INT | PK |
| `dealer_id` | INT | FK |
| `order_id` | INT NULL | FK (opsiyonel) |
| `amount` | DECIMAL(14,2) | Tutar |
| `type` | VARCHAR(20) | Ödeme yöntemi |
| `payment_date` | DATE | Ödeme tarihi |
| `bank_name` | VARCHAR | Banka (havale için) |
| `transaction_ref` | VARCHAR | Dekont/referans |
| `dealer_note` | TEXT | Bayi notu |
| `admin_note` | TEXT | Admin red sebebi |
| `card_auth_code` | VARCHAR(64) | **YENİ** Slip onay |
| `card_receiver` | VARCHAR(255) | **YENİ** POS |
| `card_notes` | TEXT | **YENİ** Kart notu |
| `status` | VARCHAR(20) | bekliyor/onaylandi/reddedildi |
| `approved_by` | INT | Admin ID |
| `approved_at` | DATETIME | Onay zamanı |
| `parasut_payment_id` | INT | Paraşüt entegre |
| `created_at` | DATETIME | Kayıt zamanı |

### `b2b_ledger`

```sql
INSERT INTO b2b_ledger (dealer_id, type, amount, description, ref_type, ref_id)
VALUES (5, 'alacak', 3500, 'Manuel tahsilat - kredi kartı', 'payment', 678);
```

---

## 🎨 UI Detayları

### Modal Davranışı (v1.1.90)

```css
.modal {
  max-height: calc(100vh - 32px);
  display: flex;
  flex-direction: column;
}
.modal-header { flex: 0 0 auto; border-bottom: 1px solid var(--border); }
.modal-body   { flex: 1 1 auto; overflow-y: auto; min-height: 0; }
.modal-footer { flex: 0 0 auto; border-top: 1px solid var(--border); }

@media (max-height: 800px) {  /* 13" laptop */
  .modal { max-height: calc(100vh - 16px); }
}
```

**Sonuç:** Buttonlar her zaman alta sticky kalır, body scrolllanır.

### Kredi Kartı Detayları Toggle

```javascript
function toggleCardFields(type) {
    const box = document.getElementById('manual-card-fields');
    box.style.display = (type === 'kredi_karti') ? 'block' : 'none';
}
```

Ödeme yöntemi dropdown'unda `onchange="toggleCardFields(this.value)"`.

---

## 🔍 İzleme / Audit

### Audit Log Eylemleri

| Eylem | Ne Zaman |
|-------|----------|
| `payment_approve` | Bayi bildirimi onaylandı |
| `payment_reject` | Bayi bildirimi reddedildi |
| `payment_manual` | Manuel tahsilat girildi |
| `payment_delete` | Tahsilat silindi |
| `order_payment_method_changed` | Sipariş yöntemi değişti |

### Sorgu Örnekleri

#### Bir bayinin kredi kartı tahsilatları
```sql
SELECT id, amount, card_auth_code, card_receiver, card_notes, payment_date
  FROM b2b_payments
 WHERE dealer_id = 5
   AND type = 'kredi_karti'
   AND status = 'onaylandi'
 ORDER BY payment_date DESC;
```

#### Bu ay tedarikçi POS'a çekilenler
```sql
SELECT p.*, d.company_name
  FROM b2b_payments p
  JOIN b2b_dealers d ON d.id = p.dealer_id
 WHERE p.type = 'kredi_karti'
   AND p.card_receiver IS NOT NULL
   AND p.card_receiver != ''
   AND MONTH(p.payment_date) = MONTH(NOW())
 ORDER BY p.payment_date DESC;
```

---

## 🛟 Sorun Giderme

### "Tahsilat onaylandı ama ledger'da gözükmüyor"
**Çözüm:** Eski bug'lardan kalmış olabilir.
- Tahsilat → "Eksik ledger kayıtlarını yeniden oluştur" butonu (action=resync_ledger)
- Audit log'da `payment_approve` var mı kontrol et

### "Bayi panelinde tahsilatı göremiyor"
**Olası sebep:** `dealer_id` yanlış kayıt.
**Çözüm:** Tahsilat detayında bayi adı doğru mu kontrol et.

### "Kart detayları kayboldu"
**Sebep:** Form `kredi_karti` seçili değilken submit edildi.
**Çözüm:** Yeni tahsilat oluştur, yöntem KREDİ KARTI sabit olduğundan emin ol.

### "Sipariş ödeme durumu güncellenmedi"
**Kontrol:**
1. `order_id` dolu mu?
2. `amount` doğru mu?
3. Audit log'da `order_payment_method_changed` var mı?

---

*Modül: Tahsilat · Sürüm: v1.1.87+ · Son güncelleme: 2026-06-02*
