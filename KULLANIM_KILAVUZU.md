# 📘 CODEGA B2B — Yönetici Kullanım Kılavuzu

> **Sürüm:** 1.1.91 · **Hedef Kitle:** Le Monde Du Tacos yöneticileri ve operatörleri

Bu kılavuz, sistemde günlük olarak yapacağınız işlemleri adım adım anlatır. Hangi düğmeye basacağınızdan, hangi alanları nasıl dolduracağınıza kadar her detay bu belgede.

---

## 📑 İçindekiler

1. [Hızlı Başlangıç](#-hızlı-başlangıç)
2. [Dashboard](#-dashboard)
3. [Bayi Yönetimi](#-bayi-yönetimi)
4. [Sipariş Yönetimi](#-sipariş-yönetimi)
5. [Fatura İşlemleri](#-fatura-i̇şlemleri)
6. [Tahsilat Yönetimi](#-tahsilat-yönetimi)
7. [Kredi Kartı ile Tahsilat](#-kredi-kartı-i̇le-tahsilat)
8. [Ciro Primi (Aylık Komisyon)](#-ciro-primi-aylık-komisyon)
9. [Paraşüt Entegrasyonu](#-paraşüt-entegrasyonu)
10. [Fiyat Listeleri](#-fiyat-listeleri)
11. [Ürün & Stok](#-ürün--stok)
12. [Sistem Güncellemesi](#-sistem-güncellemesi)
13. [Sorun Giderme & SSS](#-sorun-giderme--sss)

---

## 🚀 Hızlı Başlangıç

### Sisteme Giriş

1. Tarayıcıda **https://bayi.lemondedutacos.com/admin/** adresine git
2. Email + şifrenizi gir
3. Sol üstte "B2" logosu = Admin Paneli aktif

### Ana Ekran Yapısı

```
┌────────────────────────────────────────────────────┐
│ [LOGO] B2B Admin Paneli    [Bayi Portalı] [Bell]  │
├──────────┬─────────────────────────────────────────┤
│ GENEL    │                                         │
│ Dashboard│         ANA İÇERİK                      │
│ Raporlar │         (seçilen sayfa)                 │
│          │                                         │
│ SATIŞ    │                                         │
│ Siparişler                                         │
│ Tahsilat │                                         │
│ Cari Hsp │                                         │
│          │                                         │
│ BAYİLER  │                                         │
│ Bayiler  │                                         │
│ Başvurular                                         │
│ Fiyat L. │                                         │
│ Ciro Pr. │                                         │
│          │                                         │
│ ÜRÜNLER  │                                         │
│ Ürünler  │                                         │
│ Kategori │                                         │
│ Stok     │                                         │
└──────────┴─────────────────────────────────────────┘
```

### Mobil Erişim

Mobil tarayıcıda sol menü hamburger ikona dönüşür. Alt çubukta hızlı erişim butonları vardır.

---

## 🏠 Dashboard

Ana panel, günlük durumu özetler.

### Üst Uyarı Kartları

Üstte zaman zaman renkli uyarı kartları çıkar:

| Renk | Anlam |
|------|-------|
| 🟡 **SARI** | Geçen ayın ciro primleri hesaplanmadı |
| 🔵 **MAVİ** | Taslak ciro primleri yansıtılmayı bekliyor |
| 🟠 **TURUNCU** | Bekleyen sipariş onayı var |
| 🔴 **KIRMIZI** | Stok düşük ürünler var |

### İstatistik Kartları

- **Aktif Bayi** — sisteme kayıtlı, durumu aktif bayiler
- **Bekleyen Sipariş** — onay bekleyen yeni siparişler
- **Aylık Ciro** — bu ay toplam satış
- **Bekleyen Tahsilat** — onay bekleyen ödeme bildirimleri

### Hızlı Aksiyonlar

- "Yeni Sipariş" → admin tarafından bayi adına sipariş açar
- "Manuel Tahsilat" → ödeme kaydı girer
- "Bayi Ekle" → yeni bayi formu

---

## 👥 Bayi Yönetimi

### Bayi Listesi

**Sol menü → Bayiler**

Listede:
- Firma adı
- Yetkili kişi
- Kredi limiti
- Bakiye (alacak/borç)
- Son sipariş tarihi
- Durum (aktif/pasif)

### Yeni Bayi Ekleme

1. **Bayiler → Yeni Bayi Ekle**
2. Formu doldur (zorunlu alanlar `*` ile işaretli):

#### Genel Bilgiler
- **Firma Adı** *
- **Vergi Dairesi** + **Vergi No / TC** *
- **Yetkili Kişi** *
- **Email** * (giriş için kullanılır)
- **Telefon** *
- **Adres** *

#### Hesap & Ödeme
- **Kredi Limiti (₺)** — bu tutara kadar borçlanabilir
- **Vade (gün)** — fatura tarihinden itibaren ödeme süresi
- **Sipariş Onayı** — Manuel veya Otomatik
- **Ödeme Yöntemleri** — havale, kart, çek, senet (çoklu seçim)

#### 💰 Ciro Primi (Aylık Komisyon) — YENİ
- **Prim Oranı (%)** — örn: 2.50 demek %2.5
- **Min. Alış Tutarı (₺)** — bu tutarın altındaki aylarda prim hesaplanmaz
- **Prim Notu** — sözleşme detayı vb.

3. **Kaydet** → sistem otomatik şifre oluşturur ve email'e gönderir

### Bayi Düzenleme

1. Bayi listesinde **"Düzenle"** linkine tıkla
2. İstediğin alanları değiştir
3. **Kaydet**

### Bayi Pasifleştirme

Sil DEĞİL, **pasifleştirme** yap:
1. Bayi düzenleme sayfasında **"Durum: Pasif"** seç
2. Bayi giriş yapamaz ama geçmiş veriler korunur

### Bayi Başvuruları

Web sitesinde "Bayi Olmak İstiyorum" formundan gelen başvurular:

1. **Sol menü → Başvurular**
2. Bekleyen başvurular badge'de gözükür
3. Başvuru detayında:
   - **"Onayla"** → otomatik bayi kaydı oluşturulur + email gönderilir
   - **"Reddet"** → red sebebi yaz, başvuran bilgilendirilir

---

## 🛒 Sipariş Yönetimi

### Sipariş Akışı

```
[Sipariş Alındı] → [Onaylandı] → [Hazırlanıyor] → [Teslimata Çıktı] → [Teslim Edildi]
                                                                              ↓
                                                                          [Arşiv]
```

Her aşamada **iptal** veya **iade** edilebilir.

### Sipariş Listesi

**Sol menü → Siparişler**

#### Filtreleme Tabları (Üst)
```
[Tümü 25] [Sipariş Alındı 3] [Onaylandı 5] [Hazırlanıyor 8]
[Teslimata Çıktı 2] [Teslim Edildi 5] [İptal 2]
|  [📑 Fatura Edilmiş 15] [📝 Fatura Bekliyor 10]
```

Sayıların yanındaki rozetler **anlık güncel** sayımı gösterir.

#### Arama
Üstte arama kutusuna **sipariş no** veya **bayi adı** yazıp filtreleyebilirsin.

### Sipariş Detayı

Bir siparişe tıklayınca açılan detay sayfası:

#### Üst Bilgi
- Sipariş No
- Tarih + saat
- Bayi adı + iletişim
- Toplam tutar
- Durum (dropdown — değiştirilebilir)

#### Ürün Tablosu
- Ürün adı + kodu
- Adet + birim
- Birim fiyat
- KDV
- Toplam

#### Fatura Numarası Kartı
- Mevcut fatura no (varsa)
- Kaynak (📄 Paraşüt veya ✏️ Manuel)
- **Fatura Kesim Durumu Toggle**:
  - 📑 Fatura Kesildi (yeşil)
  - 📝 Fatura Bekliyor (sarı)
  - Tek tıkla durum değişir
- Manuel düzenleme alanı

#### Ödeme Bilgileri
- Ödeme yöntemi (havale, kart, vb.)
- Ödeme durumu (ödenmedi, kısmi, ödendi)
- Yapılan ödemeler listesi

#### Aksiyon Butonları
- **Faturayı Kes (Paraşüt)** → Paraşüt API ile otomatik fatura
- **PDF İndir** → sipariş çıktısı
- **İptal Et** → sebep belirt
- **Arşive Taşı** → liste'den kaldırır

### Durum Değiştirme

Detay sayfasında **durum dropdown**'undan yeni durum seç → otomatik kaydedilir.

**Önemli:** Durum değişikliği audit log'a kaydedilir, geri izlenebilir.

### Arşiv Yönetimi

Tamamlanmış siparişler arşive taşınır:

1. **Siparişler → 📦 Arşiv** (sağ üst köşede)
2. Aynı filtre tabları arşivde de çalışır:
   - **Tümü**
   - **📑 Fatura Edilmiş**
   - **📝 Fatura Bekliyor**
3. Her satırda **Fatura No altında toggle butonu** — durum değiştir
4. **"📤 Arşivden Çıkar"** butonu → tekrar aktif listeye

#### Senaryo: Faturasız Tamamlanmış Sipariş
1. Sipariş tamamlandı → arşive taşıdın
2. Sonradan faturasını kesmen lazım
3. **Arşiv → 📝 Fatura Bekliyor** filtresine git
4. Faturayı kestiğinde **📝 Bekliyor** butonuna bas → **📑 Fatura Kesildi** olur
5. Filtre sayısı azalır, takipte kalır

---

## 📑 Fatura İşlemleri

### Fatura Numarası Atama

#### Otomatik (Paraşüt API)
1. Sipariş detayında **"Faturayı Kes (Paraşüt)"** butonuna bas
2. Paraşüt API ile fatura oluşturulur
3. Fatura no otomatik gelir + invoice_no_source = 'parasut'

#### Manuel
1. Sipariş detayında **Fatura No** alanına yaz (örn: TG02026000000448)
2. **Kaydet** → invoice_no_source = 'manual'

### Fatura Kesim Durumu

**3 durum mevcut:**

| Durum | Anlam | Görsel |
|-------|-------|--------|
| **beklemede** | Faturası kesilmedi | 📝 Bekliyor (sarı) |
| **kesildi** | Faturası kesildi | 📑 Fatura Kesildi (yeşil) |
| (NULL) | Yeni sistem girdi | beklemede sayılır |

#### Toggle Butonu

Liste ve detay sayfalarında **tek tıkla** durum değişir:

```
📝 Bekliyor  →  📑 Fatura Kesildi  →  📝 Bekliyor (tıklarsan)
```

Her değişim **audit log**'a kaydedilir (kim ne zaman yaptı).

### Migration Otomatiği

Sistemde mevcut fatura no'su olan tüm siparişler otomatik olarak **'kesildi'** işaretlenir (v1.1.88+).

---

## 💳 Tahsilat Yönetimi

### Tahsilat Listesi

**Sol menü → Tahsilat**

#### Üst Tablar
- **Bekleyen** — bayi bildirdi, admin onayı bekleniyor
- **Onaylanan** — onaylanmış tahsilatlar

#### Liste Sütunları
- Tarih
- Bayi
- Dekont/Onay Bilgisi
- Tutar
- Yöntem
- Durum (Bekliyor / Onaylandı / Reddedildi)

### Tahsilat Onaylama (Bayi Bildirimini)

Bayi havale yapıp bildirim girdiğinde:

1. **Tahsilat → Bekleyen** sekmesi
2. İlgili kaydı bul
3. **"Onayla"** → cari hesaba alacak yazılır
4. Veya **"Reddet"** → sebep yaz, bayi bilgilendirilir

### Manuel Tahsilat Girişi

Kendi başına bir tahsilat girmek istediğinde (örn: nakit aldın, kart çektin):

1. **Tahsilat → + Manuel Tahsilat** butonu
2. Modal açılır

#### Form Alanları

##### Bayi Seçimi
- Dropdown'dan bayi seç
- Seçince **otomatik açık siparişler** dropdown'a yüklenir

##### Hangi Siparişe? (opsiyonel)
- "Genel tahsilat" → siparişe bağlı değil, cari hesaba direkt
- Veya bir sipariş seç

**Sipariş seçince ne olur?**
```
┌────────────────────────────────────────┐
│ Toplam: 5.000 ₺   Ödenmiş: 1.500 ₺   │
│ Kalan:  3.500 ₺   Durum: Hazırlanıyor │
│ ─────────────────────────────────────  │
│ Siparişteki ödeme yöntemi: [Havale]   │
│ 💡 Yeni yöntem seçince güncellenir    │
└────────────────────────────────────────┘
```
- **Tutar otomatik kalan bakiye ile doldurulur**
- Mevcut ödeme yöntemi gösterilir

##### Tutar (₺) *
- Manuel yaz veya sipariş seçince otomatik gel

##### Ödeme Yöntemi
- Havale/EFT
- Nakit
- **Kredi Kartı** (özel akış — aşağıda detay)
- Çek
- Senet

##### Ödeme Tarihi
- Default: bugün
- Geriye dönük tarih girebilirsin

##### Not
- Açıklama (opsiyonel)

3. **Kaydet** → otomatik:
   - INSERT b2b_payments
   - Sipariş bağlıysa: payment_status güncellenir (odendi/kismi)
   - **Sipariş ödeme yöntemi değiştirildiyse**: UPDATE b2b_orders SET payment_method
   - b2b_ledger'a alacak kaydı
   - Audit log

#### Başarı Mesajı Örneği
```
Manuel tahsilat kaydedildi · SIP260525001 sipariş ödeme durumu
güncellendi · ödeme yöntemi: havale → kredi_karti
```

---

## 💳 Kredi Kartı ile Tahsilat

### Özel Senaryo: Müşteri Kartını Tedarikçiye Çekme

Müşteri (bayi) kartını sizin değil, **başka bir POS'a** (örn: tedarikçi) çekildiğinde:

1. Manuel Tahsilat formunda **Ödeme Yöntemi: Kredi Kartı** seç
2. Otomatik **💳 Kredi Kartı Detayları** paneli açılır:

```
┌─ 💳 Kredi Kartı Detayları ──────────────┐
│ Onay Kodu * (slip üzerindeki kod)       │
│ [123456              ] ← BAYİ DE GÖRÜR  │
│                                          │
│ Nereye Çekildi (SADECE admin)           │
│ [XYZ Tedarikçi POS   ] ← BAYİ GÖRMEZ   │
│                                          │
│ Ek Kart Notu (SADECE admin)             │
│ [Müşteri tedarikçiye  ]                 │
│ [çekti, mutabık olduk ]                 │
└──────────────────────────────────────────┘
```

3. Onay Kodu **ZORUNLU** alan
4. "Nereye Çekildi" ve "Ek Not" bayilere kesinlikle **gizli** kalır

### Bayi Panelinde Görünüm

Bayi giriş yapıp tahsilat geçmişine baktığında:

```
Tarih      Tutar    Yöntem        Banka/Onay Kodu
26.05      3.500₺   kredi_karti   [123456]        ← sadece bu
                    💳 Slip Onay:
                    123456
```

"Nereye çekildi" ve "ek not" **kesinlikle görünmez**.

### Admin Listesinde Görünüm

```
Tarih   Bayi          Tutar    Yöntem          Durum
26.05   Ds Group      3.500₺   Kredi Kartı     ✓ Onaylandı
                              Onay: 123456
                              POS: [XYZ Tedarikçi]
        Kart notu: Müşteri tedarikçiye çekti
```

Admin **tüm detayları** görür: onay + POS + not.

---

## 💰 Ciro Primi (Aylık Komisyon)

### Genel Mantık

Bayilere yaptıkları aylık alış tutarı üzerinden yüzde olarak prim hesaplanır:

```
Prim = Toplam Aylık Alış × (Oran / 100)
```

Örnek:
- Bayi A: %2.5 oran, Mayıs'ta 100.000 ₺ alış → 2.500 ₺ prim
- Cari hesabına 2.500 ₺ **alacak** olarak yansıtılır

### Bayi Başına Tanımlama

1. **Bayiler → bayiyi düzenle**
2. Sarı **💰 Ciro Primi** panelinde:
   - **Prim Oranı (%)**: örn 2.50
   - **Min. Alış Tutarı**: bu tutar altında prim hesaplanmaz (opsiyonel)
   - **Prim Notu**: sözleşme detayı (opsiyonel)
3. **Kaydet**

### Aylık Hesaplama

#### Otomatik Uyarı

Her ay başında (özellikle 1-5'i arası) dashboard'da **SARI uyarı** çıkar:

```
┌──────────────────────────────────────────────────┐
│ 💰 Mayıs 2026 Ciro Primleri Hesaplanmadı        │
│ 25 bayi için ciro primi oranı tanımlı.          │
│ Geçen ayın aylık primlerini hesaplayıp cari     │
│ hesaplara yansıtmayı unutmayın.                 │
│                                  [🧮 Şimdi Hesapla]│
└──────────────────────────────────────────────────┘
```

#### Hesaplama Akışı

1. **Ciro Primleri** menüsüne git (sol menü → Bayiler → Ciro Primleri)
2. **Dönem**: Mayıs / 2026 seç
3. **🧮 Hesapla** butonuna bas
4. Onay diyaloğu → "Devam"
5. Sistem otomatik:
   - Her aktif bayi için (commission_rate > 0)
   - Mayıs'ta verilen siparişlerin toplamını çıkarır
   - Eğer min eşiğin üstündeyse prim hesaplar
   - **'taslak'** olarak kaydeder

#### Liste Görünümü

```
Bayi          Sip  Alış         Oran    Prim        Durum
─────────────────────────────────────────────────────────────
Fours Pillars  12  85,500₺      %2.5    2,137,50₺   📝 Taslak
Deniz Berk      8  42,300₺      %3.0    1,269,00₺   📝 Taslak
ABC Gıda        3   8,200₺      %2.0    —           [eşik altı]
```

### Tek Tek Yansıtma

Bir satırda **"✓ Yansıt"** butonuna bas:
- Onay diyaloğu
- b2b_ledger'a "alacak" hareket yazılır
- Açıklama: "2026 Mayıs Ciro Primi (%2.5) — alış: 85.500,00 ₺"
- Status → "yansitildi" (yeşil rozet)

### Toplu Yansıtma

Tüm taslakları tek seferde yansıtmak için:

1. Üstte **"✓ Tümünü Yansıt (X)"** butonu (X = taslak sayısı)
2. Onay → bekle
3. Hepsi yansıtılır

### İptal Etme

Yanlış yansıtılmış primi iptal etmek için:

1. Yansıtılmış satırda **"✕ İptal"** butonuna bas
2. Onay diyaloğu
3. Sistem otomatik:
   - **TERS hareket** ekler (borç kaydı, prim tutarı kadar)
   - Status → "iptal"
   - Cari hesap dengelenir

### PDF Raporu

1. Üstte **"🖨 PDF / Yazdır"** linkine bas
2. Yeni sekme açılır — print-only görünüm
3. **Yazdır** butonu veya CTRL+P
4. PDF'e kaydet veya yazıcıya gönder

#### PDF İçeriği
- Le Monde Du Tacos logo + başlık
- Dönem (Mayıs 2026)
- Rapor tarihi + toplam kayıt
- Tablo: bayi · sipariş adedi · alış · oran · prim · durum
- En altta toplam satırı

### Tekrar Hesaplama

Aynı dönem için tekrar **🧮 Hesapla** yapabilirsin:
- **Yansıtılmamış (taslak)** primler güncellenir (yeni sipariş eklenmiş olabilir)
- **Yansıtılmış** primler korunur, değişmez

### Min Eşik Mantığı

Bayi tanımında **min_amount = 10.000** ise:
- 8.000 ₺ alış → prim hesaplanmaz (atlanır)
- 15.000 ₺ alış → 15.000 × oran prim hesaplanır

### Audit Log

Her işlem kaydedilir:
- `commission_calculated` — toplu hesaplama
- `commission_applied` — tek yansıtma
- `commission_apply_all` — toplu yansıtma
- `commission_cancelled` — iptal + ters hareket

---

## 🔗 Paraşüt Entegrasyonu

### Yapılandırma

1. **Ayarlar → Paraşüt sekmesi**
2. Paraşüt API anahtarlarını gir:
   - Client ID
   - Client Secret
   - Username + Password
   - Company ID
3. **Bağlantıyı Test Et** → yeşil ✓ görmelisin

### Cache Mantığı

Paraşüt'teki ürünler ve cariler **yerel cache**'de tutulur (b2b_parasut_cache tablosu). Bu sayede:
- Eşleme sayfası anında açılır
- 1144 ürünlü dropdown yerine **AJAX arama**
- Rate limit'e takılmaz

### Cache Senkronizasyonu

#### Manuel
1. **Ayarlar → Paraşüt → 🔄 Senkronize Et**
2. Bekle (30-90 sn)
3. Yanıt:
   - `total: 1144` (toplam ürün)
   - `active: 1100, archived: 44`
   - `duration: 45 sn`

#### Otomatik (Cron)
1. **Ayarlar → Paraşüt → Cron URL**: tokenli URL'yi kopyala
2. Sunucuda cron tab'e ekle:
```bash
*/15 * * * * curl -s "https://bayi.example.com/cron/parasut-sync.php?token=XXX"
```

### Eşleme

#### Sayfa: Paraşüt → Eşleme

**2 sekme:** Bayiler · Ürünler

#### İnline Arama Componenti

Her satırda **arama kutusu** vardır:

```
┌─────────────────────────────────────┐
│ 🔎 Paraşüt ürün ara (en az 2 kar...)│  💾
└─────────────────────────────────────┘
```

1. **Min 2 karakter** yaz
2. **200ms** sonra otomatik arama
3. Sonuçlar dropdown'da:
```
Cheddar Sos  [SOS-001]
  📁 SOS GRUBU · ID: 69831265 · 45,50 ₺

Cheddar Premium  [SOS-002]
  📁 SOS GRUBU · ID: 69831266 · 52,00 ₺
```

#### Klavye Kısayolları

| Tuş | İşlev |
|-----|-------|
| `↑` `↓` | Sonuçlar arası gez |
| `Enter` | Aktif sonucu seç |
| `Esc` | Dropdown'u kapat |

#### Görsel Durumlar

| Durum | Görsel |
|-------|--------|
| Bağlı değil | Normal beyaz input |
| Eşleşmiş | Yeşil border + background + ✕ butonu |
| Kayıp ID (Paraşüt'ten silinmiş) | Kırmızı border + ⚠️ uyarı |

### Stok Senkronizasyonu

Eşlemeleri tamamladıktan sonra:
1. **Ayarlar → Paraşüt → Stok Senkronize Et**
2. Sistem her B2B ürünü için:
   - Paraşüt'ten gerçek stok çeker
   - b2b_products.stock_quantity günceller
3. Sonuç: "X ürünün stoğu güncellendi"

### Otomatik Fatura Kesme

Sipariş detayında **"Faturayı Kes (Paraşüt)"** butonu:
- Sipariş satırları Paraşüt fatura'sına dönüşür
- Bayi paraşüt cari'siyle eşleştirilir
- Otomatik fatura no + e-arşiv

### Tahsilat-Fatura İlişkilendirme

Onaylanan tahsilat otomatik Paraşüt fatura'sına bağlanır (eğer order_id varsa).

---

## 💵 Fiyat Listeleri

### Yapı

Sistem **çoklu fiyat listesi** destekler:
- Standart Fiyatlar
- VIP Fiyatları
- Toptan Fiyatları
- vb.

Her bayi **bir fiyat listesine bağlıdır**.

### Liste Oluşturma

1. **Fiyat Listeleri → + Yeni Liste**
2. Liste adı: "Toptan Fiyatları 2026"
3. **Kaydet**

### Ürün Ekleme

1. Liste detayında **+ Ürün Ekle**
2. Ürün seç + fiyatını gir
3. **Kaydet**

### Toplu İşlem

- **Kopyala** → mevcut listenin kopyasını al, yeni isimle kaydet
- **Sil** → liste boşsa direkt, dolu ise onay

### Bayiye Atama

**Bayi düzenleme** sayfasında "Fiyat Listesi" dropdown'undan seç.

---

## 📦 Ürün & Stok

### Ürün Hiyerarşisi

```
Ana Kategori (Ambalaj Grubu)
└─ Alt Kategori (Peçeteler)
   ├─ Ürün (A-10 Servis Peçete)
   └─ Ürün (A-12 Endüstriyel Peçete)
```

### Ürün Ekleme

1. **Ürünler → + Yeni Ürün**
2. Form:
   - Ad, Kod (SKU), Barkod
   - Kategori (dropdown)
   - Açıklama
   - KDV oranı (0, 1, 10, 20)
   - Birim (adet, koli, kg, vb.)
   - Stok miktarı
   - Min stok seviyesi (uyarı için)
3. **Kaydet**

### Stok Yönetimi

**Sol menü → Stok**

#### Liste
- Ürün
- Mevcut stok
- Min seviye
- Son hareket
- Durum (Stokta / Az Stok / Yok)

#### Stok Güncelleme
- Manuel: Ürün detayında "Stok Hareket"
- Otomatik: Paraşüt sync

### Düşük Stok Uyarısı

Dashboard'da kırmızı uyarı kartı çıkar.

---

## 🔄 Sistem Güncellemesi

### Smart Update v5 (Otomatik)

1. **Ayarlar → Güncelleme Merkezi**
2. Mevcut sürüm + son sürüm karşılaştırması
3. **"Güncellemeleri Kontrol Et"** butonu
4. Yeni sürüm varsa:
   - Changelog gözükür
   - **"Güncelle"** butonu

#### Güncelleme Süreci

```
1. 🔒 FTP backup alınıyor...        (5-10 sn)
2. 📥 GitHub'dan ZIP indiriliyor... (10-20 sn)
3. 🔍 SHA diffing yapılıyor...      (3-5 sn)
4. 📂 Dosyalar güncelleniyor...     (5-10 sn)
5. 🛠️ Migration'lar koşturuluyor... (2-5 sn)
6. 🧹 Cache temizleniyor...         (1-2 sn)
7. ✓ Tamamlandı!
```

#### Hata Durumunda

Eğer güncelleme başarısız olursa:
- Backup otomatik geri yüklenir
- Hata mesajı detaylı gösterilir
- "Yeniden Dene" butonu

### Manuel Yedek

Update'ten önce ekstra güvenlik için:

1. **Ayarlar → Yedekleme → Veritabanı Yedeği İndir**
2. SQL dosyası indirilir
3. Saklayın

---

## 🐛 Sorun Giderme & SSS

### "Paraşüt'te Toplam: 0" görünüyor

**Sebep:** Cache senkronu eksik veya başarısız.

**Çözüm:**
1. Ayarlar → Paraşüt → Bağlantıyı test et
2. Yeşil ✓ ise → 🔄 Senkronize Et
3. 30-60 sn bekle
4. Yeni toplam: ~1144 olmalı

### "Şüpheli sync" hatası

**Sebep:** Paraşüt rate limit'e takılmış, yeni sync eskisinin %50'sinden az dönmüş.

**Çözüm:** 5 dakika bekleyip tekrar dene. Hâlâ olmuyorsa **Cron** aktif et — gece bağımsız sync yapar.

### Bayi giriş yapamıyor

**Kontrol:**
1. Bayi düzenleme → Durum: **Aktif** mi?
2. Email doğru mu?
3. Şifre sıfırla:
   - Bayi düzenleme → "Şifre Sıfırla" → yeni şifre email'e gönderilir

### Sipariş onayı gözükmüyor

**Sebep:** Bayi sipariş onay tipi "Otomatik" olabilir.

**Kontrol:**
- Bayi düzenleme → Sipariş Onayı: "Manuel Onay" yap

### Faturayı Kes butonu çalışmıyor

**Kontrol:**
1. Bayi Paraşüt cari'siyle eşleştirilmiş mi? (Paraşüt → Eşleme → Bayiler)
2. Sipariş ürünleri Paraşüt ürünleri ile eşleşmiş mi?
3. Paraşüt API çalışıyor mu?

### Modal butonu görünmüyor (13" ekran)

**Çözüm:** v1.1.90+ ile otomatik fix gelir. Cache temizle (CTRL+Shift+R).

### Ciro Primi sayfası boş

**Kontrol:**
1. En az 1 bayide `commission_rate > 0` tanımlı mı?
2. Seçili dönemde (ay/yıl) bu bayilerin siparişi var mı?
3. **🧮 Hesapla** butonuna bastın mı?

### Yanlış prim yansıttım, nasıl geri alırım?

**Çözüm:** Yansıtılmış satırda **"✕ İptal"** butonu:
- Cari hesaba TERS hareket (borç) eklenir
- Status → "iptal"
- Sayfa: Cari Hesap → tutarın geri çekildiğini gör

### Stok yanlış gösteriyor

**Olası sebepler:**
1. Manuel hareket yapılmadı
2. Paraşüt sync eski
3. Sipariş iptal edildi ama stok düşülmedi

**Çözüm:**
1. Stok → ürün detayı → "Stok Hareketleri" — son işlemlere bak
2. Paraşüt sync yenile
3. Yanlışsa manuel düzelt

---

## 📞 İletişim

### Teknik Destek
- **Geliştirici:** CODEGA (Yunus Aksoy)
- **Email:** info@codega.com.tr
- **Repo:** [github.com/codegatr/b2b](https://github.com/codegatr/b2b)

### Sorun Bildirimi (Issues)

GitHub Issues açarken:
1. **Sürüm numarası** (Admin paneli sol alt — v1.1.91)
2. **Sayfa URL'i** (örn: ?page=orders&action=detail&id=42)
3. **Adımlar** (ne yaptın, ne bekledin, ne oldu)
4. **Ekran görüntüsü**
5. **Browser console** (F12 → Console tabı)

---

*Bu kılavuz v1.1.91 sürümüne göre hazırlanmıştır. Son güncelleme: 2026-06-02*
