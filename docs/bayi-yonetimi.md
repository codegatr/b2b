# 👥 Bayi Yönetimi — Modül Detayı

## 📌 Konsept

B2B sisteminin temeli — her müşteri bir "bayi" olarak kayıtlıdır. Bayilerin:
- Şirket bilgileri
- Yetkili kişileri
- Vade & kredi limiti
- Ödeme yöntemleri
- Fiyat listesi atamaları
- **Ciro primi oranı**

yönetilir.

---

## 📊 Veritabanı Şeması (`b2b_dealers`)

| Kolon | Tip | Açıklama |
|-------|-----|----------|
| `id` | INT | PK |
| `company_name` | VARCHAR(200) | Firma adı |
| `tax_office` | VARCHAR(100) | Vergi dairesi |
| `tax_number` | VARCHAR(20) | VKN/TCKN |
| `first_name` | VARCHAR(100) | Yetkili ad |
| `last_name` | VARCHAR(100) | Yetkili soyad |
| `email` | VARCHAR(150) | UNIQUE (giriş için) |
| `phone` | VARCHAR(30) | Telefon |
| `address` | TEXT | Adres |
| `city` | VARCHAR(50) | Şehir |
| `password` | VARCHAR(255) | Bcrypt hash |
| `credit_limit` | DECIMAL(14,2) | Açık hesap limiti |
| `payment_term_days` | INT | Vade gün |
| `payment_methods` | VARCHAR(200) | Virgülle ayrılmış |
| `order_approval` | VARCHAR(10) | 'manual' \| 'auto' |
| `price_list_id` | INT | FK → price_lists |
| `parasut_contact_id` | VARCHAR(50) | Paraşüt eşleme |
| `commission_rate` | DECIMAL(5,2) | **YENİ** Aylık prim % |
| `commission_min_amount` | DECIMAL(14,2) | **YENİ** Min eşik |
| `commission_notes` | TEXT | **YENİ** Prim notu |
| `is_active` | TINYINT | Aktif/Pasif |
| `created_at` | DATETIME | Kayıt |
| `last_login_at` | DATETIME | Son giriş |

---

## 📋 Bayi Başvuru Süreci

### Web Form
Web sitesinde "Bayi Olmak İstiyorum" formundan gelen başvurular `b2b_applications` tablosuna düşer.

### Admin Onayı

**Admin → Başvurular**

```
Tarih      Firma            Yetkili      Şehir    Durum
─────────────────────────────────────────────────────────
03.06      ABC Gıda Ltd.    Ali Veli     İstan.   ⏳ Beklemede
02.06      XYZ Restoran     Ayşe Y.      Ankara   ⏳ Beklemede
```

#### Onaylama
1. **"İncele"** tıkla
2. Form bilgileri görünür
3. **"Bayi Olarak Onayla"** tıkla
4. Sistem otomatik:
   - `b2b_dealers` tablosuna ekler
   - Rastgele şifre üretir
   - Hoş geldin emaili gönderir
   - Başvuru status='onaylandi'

#### Reddetme
1. **"Reddet"** tıkla
2. Sebep yaz (zorunlu)
3. Başvurana email gider
4. Başvuru status='reddedildi'

---

## ✏️ Bayi Düzenleme Sayfası

### Bölümler

#### 1. Genel Bilgiler
- Firma adı, vergi dairesi/no
- Yetkili kişi (ad/soyad)
- Email, telefon, adres

#### 2. Hesap & Ödeme
- **Fiyat Listesi** dropdown
- Kredi Limiti
- Vade (gün)
- Sipariş Onayı (Manuel/Otomatik)

#### 3. Ödeme Yöntemleri (Multi-Select)
- Havale/EFT
- Kredi Kartı (CMS aktif)
- Çek
- Senet

#### 4. 💰 Ciro Primi (Aylık Komisyon)
```
┌─ 💰 Ciro Primi ──────────────────────────────┐
│                          Geçmiş primler → │
│                                              │
│ Prim Oranı (%)        Min. Alış Tutarı (₺) │
│ [2.50]                [10000.00]            │
│                                              │
│ Prim Notu (admin)                           │
│ [6 ay özel anlaşma, Q1 sonu...]             │
└──────────────────────────────────────────────┘
```

#### 5. Durum
- Aktif / Pasif toggle

---

## 🔐 Şifre Yönetimi

### İlk Şifre Oluşturma
Sistem 12 karakter rastgele şifre üretir:
```php
$password = bin2hex(random_bytes(6));  // örn: a3f9b2e1c8d4
```

### Şifre Sıfırlama
**Bayi düzenleme → "Şifre Sıfırla"** butonu:
1. Yeni rastgele şifre oluşur
2. Bayi emailine gönderilir
3. `b2b_audit_logs` → `password_reset` kayıt

### Bayi Kendi Şifresini Değiştirme
Bayi panelinde **Hesabım → Şifre Değiştir**:
- Mevcut şifre + yeni şifre (2 kez)
- Min 8 karakter

---

## 🔗 Paraşüt Eşleme

**Paraşüt → Eşleme → Bayiler** sekmesinde her bayi için:
- Henüz eşlenmemiş → arama input'undan Paraşüt müşteri seç
- Eşlenmiş → yeşil + ✕ ile kaldırma
- Kayıp ID → kırmızı + yeniden eşle

`parasut_contact_id` alanı dolu olunca:
- Fatura kesme aktif
- Tahsilat otomatik ilişkilendirme aktif

---

## 📈 Bayi Performans Takibi

### Liste Sıralama
- En çok sipariş veren
- En çok ödeme yapan
- En yüksek bakiye (borç)
- En düşük bakiye (alacak — biz borçluyuz)

### Bayi Detay Sayfası

**Bayiler → bayiyi tıkla → Detay**

#### Üst Şerit
- Firma + iletişim
- Toplam alış (lifetime)
- Bu ay alış
- Mevcut bakiye

#### Sekmeler
- **Siparişler** — tüm geçmiş
- **Ödemeler** — tüm tahsilatlar
- **Cari Hesap** — ledger dökümü
- **Fiyat Listesi** — atanmış liste
- **Ciro Primleri** — yıllık özet

---

## 🛟 Sorun Giderme

### Bayi giriş yapamıyor
**Kontrol:**
1. `is_active = 1` mi?
2. Email doğru girilmiş mi?
3. Şifre sıfırlat
4. Browser cookie temizle (bayi tarafı)

### Bayi sipariş veremiyor
**Olası sebepler:**
- Kredi limiti aşılmış
- Fiyat listesi atanmamış
- Ödeme yöntemleri seçilmemiş

**Kontrol:**
```sql
SELECT credit_limit, price_list_id, payment_methods, is_active
  FROM b2b_dealers
 WHERE email = 'bayi@example.com';
```

### Otomatik onay çalışmıyor
**Kontrol:**
- Bayi `order_approval = 'auto'` mu?
- Sipariş başarıyla kayıt oldu mu? (status='bekliyor' kaldıysa router sorunu var)

---

*Modül: Bayi Yönetimi · Sürüm: v1.1.91+ · Son güncelleme: 2026-06-02*
