<?php
// pages/apply.php — Bayilik Başvurusu
$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $type     = $_POST['applicant_type'] ?? 'kurumsal';
    $company  = trim($_POST['company_name'] ?? '');
    $contact  = trim($_POST['contact_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $taxNo    = trim($_POST['tax_number'] ?? '');
    $taxOff   = trim($_POST['tax_office'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $city     = trim($_POST['city'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    if (!$company || !$email || !$phone) {
        $error = 'Firma/Ad Soyad, e-posta ve telefon zorunludur.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir e-posta girin.';
    } elseif (dbVal("SELECT COUNT(*) FROM b2b_dealers WHERE email=?", [$email]) > 0) {
        $error = 'Bu e-posta adresiyle zaten bir bayi hesabı mevcut.';
    } elseif (dbVal("SELECT COUNT(*) FROM b2b_applications WHERE email=? AND status='bekliyor'", [$email]) > 0) {
        $error = 'Bu e-posta ile bekleyen bir başvurunuz bulunmakta.';
    } else {
        dbInsertRow('b2b_applications', [
            'applicant_type' => $type,
            'company_name'   => $company,
            'contact_name'   => $contact,
            'email'          => $email,
            'phone'          => $phone,
            'tax_number'     => $taxNo,
            'tax_office'     => $taxOff,
            'address'        => $address,
            'city'           => $city,
            'notes'          => $notes,
            'status'         => 'bekliyor',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        // Admin bildirim
        notifyAdmin('Yeni Bayilik Başvurusu', "$company başvuruda bulundu.", 'application', 0);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bayilik Başvurusu — <?= h(setting('site_name','B2B Portal')) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
<style>
body { background:var(--bg-secondary); display:flex; align-items:center; justify-content:center; min-height:100vh; padding:24px; }
.apply-box { width:100%; max-width:580px; }
.apply-header { text-align:center; margin-bottom:28px; }
.apply-header h1 { font-size:22px; font-weight:700; }
.apply-header p { color:var(--text-muted); margin-top:6px; }
.apply-card { background:var(--bg); border:1px solid var(--border); border-radius:16px; padding:32px; }
.type-selector { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:24px; }
.type-option { border:2px solid var(--border); border-radius:10px; padding:16px; cursor:pointer; text-align:center; transition:all .2s; }
.type-option:has(input:checked) { border-color:var(--primary); background:rgba(99,102,241,.06); }
.type-option input { display:none; }
.type-option .type-icon { font-size:28px; margin-bottom:6px; }
.type-option .type-label { font-weight:600; font-size:14px; }
.type-option .type-sub { font-size:12px; color:var(--text-muted); margin-top:2px; }
</style>
</head>
<body>
<div class="apply-box">
    <div class="apply-header">
        <div style="font-size:36px;margin-bottom:8px">🤝</div>
        <h1>Bayilik Başvurusu</h1>
        <p><?= h(setting('site_name','B2B Portal')) ?> bayi ağına katılın</p>
    </div>

    <?php if ($success): ?>
    <div class="apply-card" style="text-align:center;padding:48px 32px">
        <div style="font-size:64px;margin-bottom:16px">✅</div>
        <h2 style="font-size:20px;font-weight:700;margin-bottom:8px">Başvurunuz Alındı!</h2>
        <p style="color:var(--text-muted);margin-bottom:24px">En kısa sürede değerlendirip sizinle iletişime geçeceğiz.</p>
        <a href="?page=login" class="btn btn-primary">Giriş Sayfasına Dön</a>
    </div>
    <?php else: ?>
    <div class="apply-card">
        <?php if ($error): ?>
        <div class="alert alert-error mb-4"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" id="apply-form">
            <?= csrfField() ?>

            <!-- Tür Seçimi -->
            <div class="form-group">
                <label>Başvuru Türü</label>
                <div class="type-selector">
                    <label class="type-option">
                        <input type="radio" name="applicant_type" value="kurumsal" checked onchange="toggleType(this.value)">
                        <div class="type-icon">🏢</div>
                        <div class="type-label">Kurumsal</div>
                        <div class="type-sub">Şirket / İşletme</div>
                    </label>
                    <label class="type-option">
                        <input type="radio" name="applicant_type" value="bireysel" onchange="toggleType(this.value)">
                        <div class="type-icon">👤</div>
                        <div class="type-label">Bireysel</div>
                        <div class="type-sub">Şahıs / Esnaf</div>
                    </label>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group" id="field-company">
                    <label>Firma Adı *</label>
                    <input type="text" name="company_name" class="form-control" required placeholder="Şirket / ticaret unvanı">
                </div>
                <div class="form-group">
                    <label>Yetkili / İletişim Kişisi</label>
                    <input type="text" name="contact_name" class="form-control" placeholder="Ad Soyad">
                </div>
                <div class="form-group">
                    <label>E-posta *</label>
                    <input type="email" name="email" class="form-control" required placeholder="info@firma.com">
                </div>
                <div class="form-group">
                    <label>Telefon *</label>
                    <input type="tel" name="phone" class="form-control" required placeholder="0(5XX) XXX XX XX">
                </div>
            </div>

            <!-- Kurumsal alanlar -->
            <div id="corporate-fields">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Vergi No</label>
                        <input type="text" name="tax_number" class="form-control" placeholder="1234567890">
                    </div>
                    <div class="form-group">
                        <label>Vergi Dairesi</label>
                        <input type="text" name="tax_office" class="form-control">
                    </div>
                </div>
            </div>

            <!-- Bireysel alanlar -->
            <div id="individual-fields" style="display:none">
                <div class="form-group">
                    <label>TC Kimlik No</label>
                    <input type="text" name="tc_number" class="form-control" placeholder="11 hane">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Adres</label>
                    <input type="text" name="address" class="form-control">
                </div>
                <div class="form-group">
                    <label>Şehir</label>
                    <input type="text" name="city" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>Ek Bilgi / Notlar</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Sektörünüz, satış hacminiz, beklentileriniz…"></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-full">Başvuruyu Gönder</button>
        </form>
    </div>

    <div style="text-align:center;margin-top:16px;font-size:13px;color:var(--text-muted)">
        Zaten bayimiz misiniz? <a href="?page=login" style="color:var(--primary)">Giriş Yapın</a>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleType(type) {
    const corp = document.getElementById('corporate-fields');
    const ind  = document.getElementById('individual-fields');
    const comp = document.getElementById('field-company');
    const compLabel = comp.querySelector('label');
    if (type === 'bireysel') {
        corp.style.display = 'none';
        ind.style.display  = 'block';
        compLabel.textContent = 'Ad Soyad *';
    } else {
        corp.style.display = 'block';
        ind.style.display  = 'none';
        compLabel.textContent = 'Firma Adı *';
    }
}
</script>
</body>
</html>
<?php exit; ?>
