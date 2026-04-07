<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireDealer();
$db     = Database::getInstance();
$dealer = currentDealer();

$errors      = [];
$successInfo = '';
$successPass = '';

// ─── Profil güncelle ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_info') {
    csrfCheck();

    $contactRaw   = trim($_POST['contact_name']  ?? '');
    $cparts       = explode(' ', $contactRaw, 2);
    $firstName    = $cparts[0] ?? '';
    $lastName     = $cparts[1] ?? '';
    $phone        = trim($_POST['contact_phone'] ?? '');
    $address      = trim($_POST['address']        ?? '');
    $taxOffice    = trim($_POST['tax_office']     ?? '');

    if (!$contactRaw) $errors[] = 'Yetkili adı zorunludur.';
    if (!$phone)      $errors[] = 'Telefon numarası zorunludur.';

    if (empty($errors)) {
        dbExec(
            "UPDATE b2b_dealers
             SET first_name = ?, last_name = ?, phone = ?,
                 address = ?, tax_office = ?, updated_at = NOW()
             WHERE id = ?",
            [$firstName, $lastName, $phone, $address, $taxOffice, $dealer['id']]
        );
        // Session'ı yenile
        $dealer = dbRow("SELECT * FROM b2b_dealers WHERE id = ?", [$dealer['id']]);
        $_SESSION['dealer_name'] = trim(($dealer['first_name']??'').' '.($dealer['last_name']??'')) ?: $dealer['company_name'];
        $successInfo = 'Profil bilgileriniz güncellendi.';
        auditLog('profile_update', 'b2b_dealers', $dealer['id'], ['by' => 'dealer']);
    }
}

// ─── Şifre değiştir ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrfCheck();

    $currentPass = $_POST['current_password'] ?? '';
    $newPass     = $_POST['new_password']     ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (!$currentPass) $errors[] = 'Mevcut şifre zorunludur.';
    if (strlen($newPass) < 8) $errors[] = 'Yeni şifre en az 8 karakter olmalıdır.';
    if ($newPass !== $confirmPass) $errors[] = 'Yeni şifreler eşleşmiyor.';

    if (empty($errors)) {
        // Mevcut şifreyi doğrula
        $row = dbRow("SELECT password FROM b2b_dealers WHERE id = ?", [$dealer['id']]);
        if (!password_verify($currentPass, $row['password'])) {
            $errors[] = 'Mevcut şifre hatalı.';
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            dbExec("UPDATE b2b_dealers SET password = ?, updated_at = NOW() WHERE id = ?", [$hash, $dealer['id']]);
            $successPass = 'Şifreniz başarıyla değiştirildi.';
            auditLog('password_change', 'b2b_dealers', $dealer['id'], []);
        }
    }
}

// ─── Son işlemler (cari) ─────────────────────────────────────────────────────
$recentLedger = dbRows(
    "SELECT * FROM b2b_ledger WHERE dealer_id = ? ORDER BY created_at DESC LIMIT 5",
    [$dealer['id']]
);

// Hesap özeti
$summary = dbRow(
    "SELECT
        COALESCE(SUM(CASE WHEN type='borc'   THEN amount ELSE 0 END),0) AS total_debit,
        COALESCE(SUM(CASE WHEN type='alacak' THEN amount ELSE 0 END),0) AS total_credit
     FROM b2b_ledger WHERE dealer_id = ?",
    [$dealer['id']]
);
$balance = ($summary['total_debit'] ?? 0) - ($summary['total_credit'] ?? 0);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Profilim</title>
</head>
<body>

<div class="page-header">
    <div>
        <h1 class="page-title">Profilim</h1>
        <p class="page-subtitle">Hesap bilgilerinizi ve şifrenizi yönetin</p>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

    <!-- Sol: Hesap Özeti -->
    <div>
        <!-- Kimlik Kartı -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-body" style="text-align:center;padding:2rem;">
                <div style="width:80px;height:80px;border-radius:50%;background:var(--color-primary);display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;color:#fff;margin:0 auto 1rem;">
                    <?= mb_strtoupper(mb_substr($dealer['company_name'], 0, 1)) ?>
                </div>
                <div style="font-size:1.125rem;font-weight:600;color:var(--text-primary);margin-bottom:.25rem;">
                    <?= htmlspecialchars($dealer['company_name']) ?>
                </div>
                <div style="font-size:.875rem;color:var(--text-secondary);margin-bottom:.5rem;">
                    <?= htmlspecialchars($dealer['contact_email']) ?>
                </div>
                <?php
                $typeLabel = match($dealer['type'] ?? 'kurumsal') {
                    'kurumsal' => ['Kurumsal', 'badge-primary'],
                    default    => ['Bireysel', 'badge-secondary'],
                };
                $statusLabel = match($dealer['status'] ?? 'active') {
                    'active'   => ['Aktif',    'badge-success'],
                    'passive'  => ['Pasif',    'badge-warning'],
                    default    => ['Askıya Alındı','badge-danger'],
                };
                ?>
                <div style="display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap;">
                    <span class="badge <?= $typeLabel[1] ?>"><?= $typeLabel[0] ?></span>
                    <span class="badge <?= $statusLabel[1] ?>"><?= $statusLabel[0] ?></span>
                </div>
            </div>
        </div>

        <!-- Finansal Özet -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><h3 class="card-title">Finansal Özet</h3></div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;text-align:center;">
                    <div>
                        <div style="font-size:1.25rem;font-weight:700;color:var(--color-danger);">
                            <?= money($summary['total_debit']) ?>
                        </div>
                        <div style="font-size:.75rem;color:var(--text-muted);">Toplam Borç</div>
                    </div>
                    <div>
                        <div style="font-size:1.25rem;font-weight:700;color:var(--color-success);">
                            <?= money($summary['total_credit']) ?>
                        </div>
                        <div style="font-size:.75rem;color:var(--text-muted);">Toplam Alacak</div>
                    </div>
                    <div>
                        <div style="font-size:1.25rem;font-weight:700;color:<?= $balance > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
                            <?= money(abs($balance)) ?>
                        </div>
                        <div style="font-size:.75rem;color:var(--text-muted);"><?= $balance > 0 ? 'Borcunuz' : 'Alacağınız' ?></div>
                    </div>
                </div>
                <hr style="border-color:var(--border);margin:1rem 0;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;font-size:.875rem;">
                    <div>
                        <span style="color:var(--text-muted);">Kredi Limiti:</span>
                        <strong style="color:var(--text-primary);"><?= money($dealer['credit_limit'] ?? 0) ?> TL</strong>
                    </div>
                    <div>
                        <span style="color:var(--text-muted);">Vade:</span>
                        <strong style="color:var(--text-primary);"><?= (int)($dealer['payment_term_days'] ?? 0) ?> gün</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Son İşlemler -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Son Cari İşlemler</h3>
                <a href="?page=account" class="btn btn-sm btn-outline">Tümü</a>
            </div>
            <div class="card-body" style="padding:0;">
                <table class="table">
                    <tbody>
                        <?php foreach ($recentLedger as $l): ?>
                        <tr>
                            <td style="font-size:.8rem;color:var(--text-muted);"><?= fmtDate($l['created_at']) ?></td>
                            <td style="font-size:.875rem;"><?= htmlspecialchars($l['description']) ?></td>
                            <td style="font-weight:600;text-align:right;
                                color:<?= $l['type'] === 'borc' ? 'var(--color-danger)' : 'var(--color-success)' ?>">
                                <?= $l['type'] === 'borc' ? '-' : '+' ?><?= money($l['amount']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sağ: Formlar -->
    <div>

        <!-- Firma Bilgileri -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><h3 class="card-title">Firma Bilgileri</h3></div>
            <div class="card-body">
                <?php if ($successInfo): ?>
                <div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($successInfo) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_info">

                    <div class="form-group">
                        <label class="form-label">Firma Adı</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($dealer['company_name']) ?>" disabled
                               style="background:var(--bg-secondary);color:var(--text-muted);">
                        <small style="color:var(--text-muted);">Firma adını değiştirmek için admin ile iletişime geçin.</small>
                    </div>

                    <?php if (($dealer['type'] ?? '') === 'kurumsal'): ?>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group">
                            <label class="form-label">Vergi No</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($dealer['tax_number'] ?? '') ?>" disabled
                                   style="background:var(--bg-secondary);color:var(--text-muted);">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Vergi Dairesi</label>
                            <input type="text" name="tax_office" class="form-control"
                                   value="<?= htmlspecialchars($dealer['tax_office'] ?? '') ?>">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group">
                            <label class="form-label">Yetkili Ad Soyad *</label>
                            <input type="text" name="contact_name" class="form-control" required
                                   value="<?= htmlspecialchars(trim(($dealer['first_name']??'').' '.($dealer['last_name']??''))) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Telefon *</label>
                            <input type="tel" name="contact_phone" class="form-control" required
                                   value="<?= htmlspecialchars($dealer['contact_phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">E-posta</label>
                        <input type="email" name="contact_email" class="form-control"
                               value="<?= htmlspecialchars($dealer['contact_email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Adres</label>
                        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($dealer['address'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Bilgileri Güncelle</button>
                </form>
            </div>
        </div>

        <!-- Şifre Değiştir -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Şifre Değiştir</h3></div>
            <div class="card-body">
                <?php if ($successPass): ?>
                <div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($successPass) ?></div>
                <?php endif; ?>
                <form method="POST" id="passForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group">
                        <label class="form-label">Mevcut Şifre *</label>
                        <div style="position:relative;">
                            <input type="password" name="current_password" class="form-control" id="cp" required autocomplete="current-password">
                            <button type="button" class="pass-toggle" onclick="togglePass('cp',this)">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Yeni Şifre * (min. 8 karakter)</label>
                        <div style="position:relative;">
                            <input type="password" name="new_password" class="form-control" id="np" required minlength="8" autocomplete="new-password" oninput="checkStrength(this.value)">
                            <button type="button" class="pass-toggle" onclick="togglePass('np',this)">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                        <div style="height:4px;background:var(--border);border-radius:2px;margin-top:.5rem;overflow:hidden;">
                            <div id="strengthBar" style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s;"></div>
                        </div>
                        <div id="strengthLabel" style="font-size:.75rem;color:var(--text-muted);margin-top:.25rem;"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Yeni Şifre Tekrar *</label>
                        <div style="position:relative;">
                            <input type="password" name="confirm_password" class="form-control" id="cnp" required autocomplete="new-password">
                            <button type="button" class="pass-toggle" onclick="togglePass('cnp',this)">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Şifreyi Değiştir</button>
                </form>
            </div>
        </div>

    </div>
</div>

<style>
.pass-toggle {
    position:absolute;right:10px;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px;
}
.pass-toggle:hover { color:var(--text-primary); }
</style>

<script>
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
}

function checkStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'20%', c:'#ef4444', t:'Çok Zayıf'},
        {w:'40%', c:'#f97316', t:'Zayıf'},
        {w:'60%', c:'#eab308', t:'Orta'},
        {w:'80%', c:'#3b82f6', t:'Güçlü'},
        {w:'100%',c:'#22c55e', t:'Çok Güçlü'},
    ];
    const lvl = levels[Math.min(score, 4)];
    bar.style.width      = val.length ? lvl.w : '0';
    bar.style.background = lvl.c;
    label.textContent    = val.length ? lvl.t : '';
    label.style.color    = lvl.c;
}
</script>

</body>
</html>
