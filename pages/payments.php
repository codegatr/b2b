<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireDealer();
$db     = Database::getInstance();
$dealer = currentDealer();

$errors  = [];
$success = '';

// ─── Bildirim gönder ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify') {
    csrfCheck();

    $amount      = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
    $paymentDate = trim($_POST['payment_date'] ?? '');
    $method      = trim($_POST['method'] ?? 'havale');
    $description = trim($_POST['description'] ?? '');
    $bankAcc     = (int)($_POST['bank_account_id'] ?? 0);

    if ($amount <= 0)       $errors[] = 'Tutar geçerli olmalıdır.';
    if (!$paymentDate)      $errors[] = 'Ödeme tarihi zorunludur.';
    if (strlen($description) > 500) $errors[] = 'Açıklama en fazla 500 karakter olabilir.';

    // Dekont dosyası
    $receiptPath = '';
    if (!empty($_FILES['receipt']['name'])) {
        $file     = $_FILES['receipt'];
        $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $maxSize  = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowed)) {
            $errors[] = 'Dekont formatı geçersiz (JPG, PNG, WEBP veya PDF olmalı).';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'Dekont dosyası en fazla 5MB olabilir.';
        } else {
            $uploadDir = __DIR__ . '/../uploads/receipts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext         = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename    = 'receipt_' . $dealer['id'] . '_' . time() . '.' . strtolower($ext);
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $receiptPath = 'uploads/receipts/' . $filename;
            } else {
                $errors[] = 'Dosya yüklenemedi, lütfen tekrar deneyin.';
            }
        }
    }

    if (empty($errors)) {
        $db->query(
            "INSERT INTO b2b_payments
             (dealer_id, amount, payment_date, method, description, receipt_path,
              bank_account_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
            [
                $dealer['id'], $amount, $paymentDate, $method,
                $description, $receiptPath,
                $bankAcc > 0 ? $bankAcc : null,
            ]
        );

        // Admin bildir
        notifyAdmin(
            'payment_notification',
            $dealer['company_name'] . ' ödeme bildirimi gönderdi: ' . money($amount) . ' TL',
            '/admin/?page=payments'
        );

        $success = 'Ödeme bildiriminiz alındı. Admin onayından sonra cari hesabınıza yansıyacaktır.';
    }
}

// ─── Banka hesapları ─────────────────────────────────────────────────────────
$bankAccounts = [];
$bankInfo = $db->fetch("SELECT value FROM b2b_settings WHERE `key` = 'bank_accounts'");
if ($bankInfo) {
    $bankAccounts = json_decode($bankInfo['value'], true) ?? [];
}

// ─── Önceki bildirimler ───────────────────────────────────────────────────────
$payments = $db->fetchAll(
    "SELECT * FROM b2b_payments WHERE dealer_id = ? ORDER BY created_at DESC LIMIT 20",
    [$dealer['id']]
);

$statusLabels = [
    'pending'  => ['Bekliyor',  'badge-warning'],
    'approved' => ['Onaylandı', 'badge-success'],
    'rejected' => ['Reddedildi','badge-danger'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ödeme Bildirimi</title>
</head>
<body>
<?php
// Bu sayfa index.php router'ından include edilir — layout hazır
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Ödeme Bildirimi</h1>
        <p class="page-subtitle">Havale / EFT ödemelerinizi bildirin ve dekont yükleyin</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?>
        <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Banka Hesapları -->
<?php if (!empty($bankAccounts)): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <h3 class="card-title">Banka Hesaplarımız</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;">
            <?php foreach ($bankAccounts as $idx => $bank): ?>
            <div style="border:1px solid var(--border);border-radius:8px;padding:1rem;background:var(--bg-secondary);">
                <div style="font-weight:600;color:var(--text-primary);margin-bottom:.5rem;">
                    <?= htmlspecialchars($bank['bank_name'] ?? '') ?>
                </div>
                <div style="font-size:.875rem;color:var(--text-secondary);line-height:1.8;">
                    <div><strong>Hesap Adı:</strong> <?= htmlspecialchars($bank['account_name'] ?? '') ?></div>
                    <div><strong>IBAN:</strong> <?= htmlspecialchars($bank['iban'] ?? '') ?></div>
                    <?php if (!empty($bank['branch'])): ?>
                    <div><strong>Şube:</strong> <?= htmlspecialchars($bank['branch']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bildirim Formu -->
<div class="card" style="margin-bottom:2rem;">
    <div class="card-header">
        <h3 class="card-title">Yeni Ödeme Bildirimi</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="notify">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Ödeme Tutarı (TL) *</label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="1"
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required
                           placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Ödeme Tarihi *</label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Ödeme Yöntemi</label>
                    <select name="method" class="form-control form-select">
                        <option value="havale" <?= ($_POST['method'] ?? '') === 'havale' ? 'selected' : '' ?>>Havale</option>
                        <option value="eft"    <?= ($_POST['method'] ?? '') === 'eft'    ? 'selected' : '' ?>>EFT</option>
                        <option value="nakit"  <?= ($_POST['method'] ?? '') === 'nakit'  ? 'selected' : '' ?>>Nakit</option>
                        <option value="diger"  <?= ($_POST['method'] ?? '') === 'diger'  ? 'selected' : '' ?>>Diğer</option>
                    </select>
                </div>
                <?php if (!empty($bankAccounts)): ?>
                <div class="form-group">
                    <label class="form-label">Yatırılan Hesap</label>
                    <select name="bank_account_id" class="form-control form-select">
                        <option value="">Seçiniz</option>
                        <?php foreach ($bankAccounts as $idx => $bank): ?>
                        <option value="<?= $idx ?>"><?= htmlspecialchars($bank['bank_name'] . ' – ' . $bank['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Açıklama / Not</label>
                <textarea name="description" class="form-control" rows="2"
                          placeholder="Havale referans no, not vb."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Dekont (JPG, PNG, WEBP veya PDF – max 5MB)</label>
                <div style="border:2px dashed var(--border);border-radius:8px;padding:2rem;text-align:center;cursor:pointer;transition:border-color .2s;"
                     id="dropZone"
                     onclick="document.getElementById('receiptInput').click()">
                    <div style="font-size:2rem;margin-bottom:.5rem;">📎</div>
                    <div style="color:var(--text-secondary);font-size:.875rem;" id="dropLabel">
                        Dosyayı buraya sürükleyin veya tıklayın
                    </div>
                </div>
                <input type="file" id="receiptInput" name="receipt"
                       accept=".jpg,.jpeg,.png,.webp,.pdf" style="display:none">
            </div>

            <button type="submit" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                Bildirimi Gönder
            </button>
        </form>
    </div>
</div>

<!-- Geçmiş Bildirimler -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Geçmiş Bildirimler</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Tutar</th>
                    <th>Yöntem</th>
                    <th>Açıklama</th>
                    <th>Dekont</th>
                    <th>Durum</th>
                    <th>Admin Notu</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem;">Henüz ödeme bildirimi yok</td></tr>
                <?php else: ?>
                <?php foreach ($payments as $p): ?>
                <?php
                    $sl    = $statusLabels[$p['status']] ?? ['Bilinmiyor','badge-secondary'];
                    $mLabel = match($p['method']) {
                        'havale' => 'Havale',
                        'eft'    => 'EFT',
                        'nakit'  => 'Nakit',
                        default  => 'Diğer',
                    };
                ?>
                <tr>
                    <td><?= fmtDate($p['created_at']) ?></td>
                    <td style="font-weight:600;color:var(--color-success);">+<?= money($p['amount']) ?> TL</td>
                    <td><?= $mLabel ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($p['description'] ?? '-') ?></td>
                    <td>
                        <?php if ($p['receipt_path']): ?>
                        <a href="/<?= htmlspecialchars($p['receipt_path']) ?>" target="_blank" class="btn btn-sm btn-outline">
                            Görüntüle
                        </a>
                        <?php else: ?>
                        <span style="color:var(--text-muted);">-</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $sl[1] ?>"><?= $sl[0] ?></span></td>
                    <td style="color:var(--text-secondary);font-size:.875rem;">
                        <?= htmlspecialchars($p['admin_note'] ?? '-') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const dropZone = document.getElementById('dropZone');
const input    = document.getElementById('receiptInput');
const label    = document.getElementById('dropLabel');

input.addEventListener('change', () => {
    const f = input.files[0];
    if (f) {
        label.textContent = f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
        dropZone.style.borderColor = 'var(--color-primary)';
    }
});

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--color-primary)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--border)'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    const files = e.dataTransfer.files;
    if (files.length) {
        input.files = files;
        label.textContent = files[0].name;
        dropZone.style.borderColor = 'var(--color-primary)';
    }
});
</script>
</body>
</html>
