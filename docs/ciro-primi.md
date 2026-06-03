# 💰 Ciro Primi (Aylık Komisyon) — Modül Detayı

> Sürüm: v1.1.91+

## 📌 Konsept

Ciro Primi, bayilerinize aylık alış tutarlarına göre yüzde olarak verdiğiniz komisyon/iskonto sistemidir.

### Hesaplama Formülü

```
Prim Tutarı = Toplam Aylık Alış × (Oran / 100)
```

Eğer **min eşik** tanımlıysa:
```
ALIŞ < MIN_AMOUNT → atlanır (prim hesaplanmaz)
ALIŞ ≥ MIN_AMOUNT → prim hesaplanır
```

### Örnek Senaryolar

#### Senaryo 1: Standart
- Bayi A: %2.5 oran, eşik yok
- Mayıs'ta: 80.000 ₺ alış
- Prim: 80.000 × 2.5 / 100 = **2.000 ₺**

#### Senaryo 2: Eşik Altı
- Bayi B: %3 oran, eşik 50.000 ₺
- Mayıs'ta: 30.000 ₺ alış (eşik altı)
- Prim: **0 ₺ (atlanır)**

#### Senaryo 3: Eşik Üstü
- Bayi C: %2 oran, eşik 100.000 ₺
- Mayıs'ta: 150.000 ₺ alış
- Prim: 150.000 × 2 / 100 = **3.000 ₺**

---

## 🛠️ Veritabanı Şeması

### `b2b_dealers` (yeni alanlar)

| Kolon | Tip | Açıklama |
|-------|-----|----------|
| `commission_rate` | DECIMAL(5,2) | Yüzde (örn: 2.50) |
| `commission_min_amount` | DECIMAL(14,2) | Min eşik tutarı |
| `commission_notes` | TEXT | Özel notlar |

### `b2b_dealer_commissions` (yeni tablo)

| Kolon | Tip | Açıklama |
|-------|-----|----------|
| `id` | INT | PK |
| `dealer_id` | INT | FK → b2b_dealers |
| `period_year` | INT | Yıl (2026) |
| `period_month` | INT | Ay (1-12) |
| `total_purchases` | DECIMAL(14,2) | O ay toplam alış |
| `order_count` | INT | O ay sipariş adedi |
| `commission_rate` | DECIMAL(5,2) | Hesaplama anındaki oran (dondurulur) |
| `min_amount` | DECIMAL(14,2) | Hesaplama anındaki eşik |
| `commission_amount` | DECIMAL(14,2) | Hesaplanan prim |
| `status` | VARCHAR(16) | 'taslak' \| 'yansitildi' \| 'iptal' |
| `applied_at` | DATETIME | Yansıtılma tarihi |
| `applied_by` | INT | Yansıtan admin ID |
| `ledger_id` | INT | b2b_ledger ref ID |
| `notes` | TEXT | İşlem notu |
| `calculated_at` | DATETIME | Hesaplama tarihi |
| `calculated_by` | INT | Hesaplayan admin ID |

### Unique Key
```sql
UNIQUE KEY (dealer_id, period_year, period_month)
```
Bir bayi için aynı ay/yıl kombinasyonunda **sadece 1 kayıt** olabilir.

---

## 🔄 Yaşam Döngüsü

```
┌──────────────────┐
│  Bayi Tanımı     │  commission_rate = 2.5
│  (Bayi Kartı)    │  commission_min_amount = 10000
└────────┬─────────┘
         │
         │ Ay sonunda
         ▼
┌──────────────────┐
│  Hesaplama       │  🧮 Hesapla butonuna basıldı
│  (status=taslak) │  Tüm bayiler için kayıt oluştur
└────────┬─────────┘
         │
         │ Onaylama
         ▼
┌──────────────────┐
│  Yansıtma        │  ✓ Yansıt butonuna basıldı
│  (status=yansit) │  b2b_ledger'a alacak kaydı
└────────┬─────────┘
         │
         │ Hata durumunda
         ▼
┌──────────────────┐
│  İptal           │  ✕ İptal butonuna basıldı
│  (status=iptal)  │  b2b_ledger'a TERS hareket
└──────────────────┘
```

---

## 📊 SQL Sorguları

### Bir bayinin tüm primleri
```sql
SELECT period_year, period_month, total_purchases,
       commission_rate, commission_amount, status, applied_at
  FROM b2b_dealer_commissions
 WHERE dealer_id = 5
 ORDER BY period_year DESC, period_month DESC;
```

### Bu ay yansıtılan toplam prim
```sql
SELECT SUM(commission_amount) AS total
  FROM b2b_dealer_commissions
 WHERE period_year = YEAR(NOW())
   AND period_month = MONTH(NOW())
   AND status = 'yansitildi';
```

### Hiç hesaplanmamış bayiler (geçen ay için)
```sql
SELECT d.id, d.company_name
  FROM b2b_dealers d
 WHERE d.is_active = 1
   AND d.commission_rate > 0
   AND NOT EXISTS (
     SELECT 1 FROM b2b_dealer_commissions c
      WHERE c.dealer_id = d.id
        AND c.period_year = YEAR(NOW() - INTERVAL 1 MONTH)
        AND c.period_month = MONTH(NOW() - INTERVAL 1 MONTH)
   );
```

---

## 🔐 İzin & Güvenlik

- Yalnızca **admin** rolü ciro primi hesaplayabilir/yansıtabilir
- Bayiler kendi primlerini cari hesap dökümünde görür (b2b_ledger üzerinden)
- Her işlem **audit log**'a kaydedilir:
  - `commission_calculated`
  - `commission_applied`
  - `commission_apply_all`
  - `commission_cancelled`

---

## 🖨️ PDF Çıktı

### URL
```
?page=commissions&year=2026&month=5&print=1
```

### Print-only CSS

```css
@media print {
  .no-print, .sidebar, .topbar { display: none !important; }
  body { background: #fff; margin: 0; padding: 0 }
  .print-page { padding: 20mm }
}
```

### İçerik
1. **Başlık**: Şirket adı + dönem
2. **Meta**: Rapor tarihi + toplam kayıt
3. **Tablo**: # · bayi · adedi · alış · oran · prim · durum
4. **Toplam Satırı**: Sarı arka plan, toplam alış + toplam prim
5. **Footer**: Sayfa numarası + otomatik oluşturuldu notu

---

## 🚨 Dikkat Edilecek Hususlar

### 1. Tekrar Hesaplama Güvenliği
**🧮 Hesapla** butonuna tekrar bastığında:
- ✅ **Taslak** primler güncellenir (yeni sipariş eklendiyse)
- ❌ **Yansıtılmış** primler **DEĞİŞMEZ** (önemli)

### 2. İptal Sonrası Cari Düzeltme
İptal halinde otomatik TERS hareket eklenir:

| İlk Hareket | İptal Hareketi |
|-------------|----------------|
| Alacak: +2.000 ₺ | Borç: 2.000 ₺ |
| **Cari net etki:** 0 ₺ |

### 3. Sipariş İptali ile Etkileşim
Eğer bir sipariş iptal edildiyse, o sipariş alış toplamına **eklenmez**:

```sql
WHERE status NOT IN ('iptal','iade')
```

Eğer prim yansıtıldıktan sonra sipariş iptal edilirse:
- Prim otomatik düşmez
- Manuel iptal etmen veya farkı tahsil etmen gerekir

### 4. Min Eşik Değişikliği
Hesaplama anında eşik **dondurulur**. Sonradan bayi kartında değiştirsen bile mevcut kayıtları etkilemez.

### 5. Oran Değişikliği
Aynı şekilde oran da dondurulur. Sonradan değişiklik **gelecek ayları** etkiler.

---

## 🛟 Sorun Giderme

### Liste boş geliyor
**Olası sebepler:**
1. Hiç bayide `commission_rate > 0` yok
2. Seçili ayda sipariş yok
3. Tüm bayiler eşik altı

**Çözüm:** Bayiler sayfasında oran tanımlarını kontrol et.

### Yansıt butonu çalışmıyor
**Sebep:** `ledgerAdd` fonksiyonu hata vermiş olabilir.

**Çözüm:**
- Audit log'a bak: `commission_applied` kayıt var mı?
- `b2b_ledger` tablosunu manuel kontrol et
- Yansıtma başarısızsa status `taslak` kalır

### Cari hesapta görünmüyor
**Kontrol:** `b2b_dealer_commissions.ledger_id` dolu mu?
```sql
SELECT c.id, c.commission_amount, c.ledger_id, l.amount, l.type
  FROM b2b_dealer_commissions c
  LEFT JOIN b2b_ledger l ON l.id = c.ledger_id
 WHERE c.id = X;
```

### PDF düzgün çıkmıyor
**Çözüm:**
1. Browser print önizlemesinde kontrol et
2. CSS @media print düzgün mü?
3. Sayfa kenarlık ayarı: A4, kenarlık dar

---

## 📈 Gelecek Geliştirmeler (Roadmap)

- [ ] Otomatik aylık hesaplama (cron)
- [ ] Excel/CSV export
- [ ] Bayi başına yıllık özet sayfası
- [ ] Bayi panelinde "Bu ay prim tahmini" widget'ı
- [ ] Çoklu dönem karşılaştırma (Q1 vs Q2)
- [ ] Email bildirimi (yansıtma sonrası bayiye)

---

*Modül: Ciro Primi · Sürüm: v1.1.91 · Son güncelleme: 2026-06-02*
