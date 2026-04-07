<?php
// admin/pages/settings.php — Sistem Ayarları
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $tab = $_POST['tab'] ?? 'general';

    if ($tab === 'general') {
        // ── Önce action türünü belirle ─────────────────────────────
        $isImageUpload = !empty($_FILES['login_image']['name']);
        $isImageRemove = !empty($_POST['remove_login_image']);
        $isGeneralSave = !$isImageUpload && !$isImageRemove;

        // ── Genel ayarlar — yalnızca genel form gönderildiğinde ────
        if ($isGeneralSave) {
            $fields = ['site_name','site_url','order_prefix','currency','timezone','admin_email'];
            foreach ($fields as $f) {
                settingSave($f, trim($_POST[$f] ?? ''));
            }
            settingClearCache();
            $success = 'Genel ayarlar kaydedildi.';
        }

                // ── Logo yükleme ───────────────────────────────────────────
        if ($isImageUpload) {
            $file    = $_FILES['login_image'];
            $maxSize = 5 * 1024 * 1024;
            $allowed = ['image/png','image/jpeg','image/webp','image/svg+xml'];

            // Uzantıdan MIME tespit (tarayıcı tutarsız gönderebilir)
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mimeMap = ['svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
            if (isset($mimeMap[$ext])) $file['type'] = $mimeMap[$ext];

            $uploadErr = '';
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErr = 'PHP yükleme hatası: ' . $file['error'];
            } elseif ($file['size'] > $maxSize && $ext !== 'svg') {
                $uploadErr = 'Görsel 5MB'dan büyük olamaz.';
            } elseif (!in_array($file['type'], $allowed)) {
                $uploadErr = 'Desteklenmeyen format: ' . h($file['type']) . ' (.'. $ext .')';
            } else {
                $fname     = 'login_hero_' . time() . '.' . $ext;
                $uploadDir = dirname(__DIR__) . '/uploads/logo';
                $dest      = $uploadDir . '/' . $fname;

                if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

                if (!is_writable($uploadDir)) {
                    $uploadErr = 'Klasör yazılamıyor: ' . $uploadDir;
                } else {
                    // Eski görseli sil (varsayılan logo hariç)
                    $oldImg = setting('login_image', '');
                    if ($oldImg && $oldImg !== 'login_hero_logo.svg') {
                        $oldPath = $uploadDir . '/' . $oldImg;
                        if (file_exists($oldPath)) @unlink($oldPath);
                    }
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        settingSave('login_image', $fname);
                        settingClearCache();
                        $success = 'Görsel yüklendi: ' . h($fname);
                    } else {
                        $uploadErr = 'Dosya taşınamadı. Dizin: ' . $uploadDir;
                    }
                }
            }
            if ($uploadErr) $error = $uploadErr;
        }

        // ── Logo kaldırma ──────────────────────────────────────────
        if ($isImageRemove) {
            $old_img = setting('login_image', '');
            if ($old_img && $old_img !== 'login_hero_logo.svg' &&
                file_exists(dirname(__DIR__) . '/uploads/logo/' . $old_img)) {
                @unlink(dirname(__DIR__) . '/uploads/logo/' . $old_img);
            }
            settingSave('login_image', '');
            settingClearCache();
            $success = 'Giriş ekranı görseli kaldırıldı.';
        }
    }

    if ($tab === 'smtp') {
        $fields = ['smtp_host','smtp_port','smtp_user','smtp_from_name','smtp_from_email','smtp_secure'];
        foreach ($fields as $f) settingSave($f, trim($_POST[$f] ?? ''));
        if (!empty($_POST['smtp_pass'])) settingSave('smtp_pass', $_POST['smtp_pass']);
        $success = 'E-posta ayarları kaydedildi.';
    }

    if ($tab === 'bank') {
        settingSave('bank_accounts', trim($_POST['bank_accounts'] ?? ''));
        $success = 'Banka hesapları kaydedildi.';
    }

    if ($tab === 'parasut') {
        $fields = ['parasut_email','parasut_company_id','parasut_sales_account','parasut_bank_account'];
        foreach ($fields as $f) settingSave($f, trim($_POST[$f] ?? ''));
        if (!empty($_POST['parasut_password'])) settingSave('parasut_password', $_POST['parasut_password']);
        // Token temizle
        settingSave('parasut_access_token', '');
        settingSave('parasut_token_expires', '');
        $success = 'Paraşüt ayarları kaydedildi. Token sıfırlandı.';
    }

    if ($tab === 'github') {
        settingSave('github_token', trim($_POST['github_token'] ?? ''));
        settingSave('github_repo',  trim($_POST['github_repo'] ?? ''));
        $success = 'GitHub ayarları kaydedildi.';
    }

    if ($tab === 'order') {
        settingSave('order_auto_approve_limit', floatval($_POST['order_auto_approve_limit'] ?? 0));
        settingSave('invoice_footer', trim($_POST['invoice_footer'] ?? ''));
        $success = 'Sipariş ayarları kaydedildi.';
    }
}

$activeTab = $_GET['tab'] ?? 'general';

// Paraşüt test
$parasutTest = null;
if (isset($_GET['test_parasut'])) {
    try {
        $result = parasut()->testConnection();
        $parasutTest = ['ok' => true, 'msg' => 'Bağlantı başarılı: ' . ($result['data']['attributes']['name'] ?? 'OK')];
    } catch (Exception $e) {
        $parasutTest = ['ok' => false, 'msg' => $e->getMessage()];
    }
}
?>

<div class="page-header">
    <div><h1 class="page-title">Sistem Ayarları</h1></div>
</div>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($parasutTest): ?>
<div class="alert alert-<?= $parasutTest['ok']?'success':'error' ?>"><?= h($parasutTest['msg']) ?></div>
<?php endif; ?>

<div class="settings-layout">
<!-- Sekme Nav -->
<div class="settings-nav">
    <?php $tabs = ['general'=>'⚙️ Genel','smtp'=>'📧 E-posta','bank'=>'🏦 Banka Hesapları','parasut'=>'🔗 Paraşüt','github'=>'🐙 GitHub / Güncelleme','order'=>'📦 Sipariş']; ?>
    <?php foreach ($tabs as $k=>$v): ?>
    <a href="?page=settings&tab=<?= $k ?>" class="settings-nav-item <?= $activeTab===$k?'active':'' ?>"><?= $v ?></a>
    <?php endforeach; ?>
</div>

<!-- Sekme İçerik -->
<div class="settings-body">

<?php if ($activeTab === 'general'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">Genel Ayarlar</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="general">
    <div class="form-grid-2">
        <div class="form-group">
            <label>Site Adı</label>
            <input type="text" name="site_name" value="<?= h(setting('site_name')) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Site URL</label>
            <input type="text" name="site_url" value="<?= h(setting('site_url')) ?>" class="form-control" placeholder="https://b2b.sirketiniz.com">
        </div>
        <div class="form-group">
            <label>Sipariş Ön Eki</label>
            <input type="text" name="order_prefix" value="<?= h(setting('order_prefix','SIP')) ?>" class="form-control" placeholder="SIP">
        </div>
        <div class="form-group">
            <label>Para Birimi</label>
            <select name="currency" class="form-control">
                <?php foreach (['TRY'=>'₺ Türk Lirası','USD'=>'$ Dolar','EUR'=>'€ Euro'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= setting('currency','TRY')===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Saat Dilimi</label>
            <select name="timezone" class="form-control">
                <?php foreach (['Europe/Istanbul','UTC','Europe/London'] as $tz): ?>
                <option value="<?= $tz ?>" <?= setting('timezone','Europe/Istanbul')===$tz?'selected':'' ?>><?= $tz ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Admin E-postası (bildirimler)</label>
            <input type="email" name="admin_email" value="<?= h(setting('admin_email')) ?>" class="form-control">
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>

<!-- Login Gorseli -->
<div style="border-top:1px solid var(--border);margin-top:1.5rem;padding-top:1.5rem">
    <h4 style="font-size:.9rem;font-weight:600;margin-bottom:1rem">Giris Ekrani Gorseli (Sol Panel)</h4>
    <div style="display:flex;align-items:flex-start;gap:1.5rem;flex-wrap:wrap">
        <div style="flex-shrink:0">
            <?php $li = setting('login_image',''); ?>
            <?php if ($li && file_exists(dirname(__DIR__).'/uploads/logo/'.$li)): ?>
            <div style="position:relative;display:inline-block">
                <img src="/uploads/logo/<?= h($li) ?>" alt="Login Gorseli"
                     style="width:160px;height:160px;object-fit:cover;border-radius:12px;border:1px solid var(--border)">
                <form method="post" style="position:absolute;top:6px;right:6px">
                    <?= csrfField() ?>
                    <input type="hidden" name="tab" value="general">
                    <input type="hidden" name="remove_login_image" value="1">
                    <button type="submit" title="Kaldir"
                        style="background:rgba(239,68,68,.85);border:none;border-radius:6px;padding:4px 6px;cursor:pointer;color:#fff;line-height:0">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div style="width:160px;height:160px;border:2px dashed var(--border);border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:var(--text-muted);font-size:.78rem;text-align:center">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                Gorsel Yok
            </div>
            <?php endif; ?>
        </div>
        <div style="flex:1;min-width:220px">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="tab" value="general">
                <input type="hidden" name="upload_login_image" value="1">
                <div class="form-group">
                    <label class="form-label">Yeni Gorsel Yukle</label>
                    <input type="file" name="login_image" class="form-control" accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg">
                    <small style="color:var(--text-muted);font-size:.78rem">PNG, JPG, WEBP, <strong>SVG</strong> &mdash; Maks 5MB. &bull; SVG tercih edilir (sonsuz çözünürlük). &bull; Önerilen oran: dikey 4:5.</small>
                </div>
                <button type="submit" class="btn btn-secondary">Gorseli Yukle</button>
            </form>
        </div>
    </div>
</div>
</div>
</div>

<?php elseif ($activeTab === 'smtp'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">E-posta (SMTP) Ayarları</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="smtp">
    <div class="form-grid-2">
        <div class="form-group">
            <label>SMTP Sunucu</label>
            <input type="text" name="smtp_host" value="<?= h(setting('smtp_host')) ?>" class="form-control" placeholder="smtp.gmail.com">
        </div>
        <div class="form-group">
            <label>Port</label>
            <input type="number" name="smtp_port" value="<?= h(setting('smtp_port','587')) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Kullanıcı Adı</label>
            <input type="text" name="smtp_user" value="<?= h(setting('smtp_user')) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Şifre</label>
            <input type="password" name="smtp_pass" class="form-control" placeholder="Değiştirmek için girin">
        </div>
        <div class="form-group">
            <label>Gönderen Adı</label>
            <input type="text" name="smtp_from_name" value="<?= h(setting('smtp_from_name')) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Gönderen E-posta</label>
            <input type="email" name="smtp_from_email" value="<?= h(setting('smtp_from_email')) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Şifreleme</label>
            <select name="smtp_secure" class="form-control">
                <option value="tls" <?= setting('smtp_secure','tls')==='tls'?'selected':'' ?>>TLS (587)</option>
                <option value="ssl" <?= setting('smtp_secure')==='ssl'?'selected':'' ?>>SSL (465)</option>
                <option value=""    <?= setting('smtp_secure')===''?'selected':'' ?>>Yok</option>
            </select>
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>
</div>
</div>

<?php elseif ($activeTab === 'bank'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">Banka Hesapları</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="bank">
    <div class="form-group">
        <label>Banka Hesapları (Bayilere gösterilecek)</label>
        <textarea name="bank_accounts" class="form-control" rows="8" placeholder="Her satıra bir hesap:
Ziraat Bankası — TR12 0001 0234 5678 9012 3456 78 — CODEGA A.Ş.
Garanti Bankası — TR98 0006 2000 1234 0006 2991 26 — CODEGA A.Ş."><?= h(setting('bank_accounts')) ?></textarea>
        <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Bu bilgiler bayi ödeme sayfasında görünür.</p>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>
</div>
</div>

<?php elseif ($activeTab === 'parasut'): ?>
<div class="card">
<div class="card-header">
    <h3>Paraşüt Entegrasyonu</h3>
    <a href="?page=settings&tab=parasut&test_parasut=1" class="btn btn-xs btn-secondary">Bağlantıyı Test Et</a>
</div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="parasut">
    <div class="alert alert-info mb-4">
        Paraşüt API v4 kullanılmaktadır. Şirket ID''nizi ve e-posta/şifrenizi Paraşüt hesabınızdan alabilirsiniz.
    </div>
    <div class="form-grid-2">
        <div class="form-group">
            <label>E-posta (Giriş)</label>
            <input type="email" name="parasut_email" value="<?= h(setting('parasut_email')) ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Şifre</label>
            <input type="password" name="parasut_password" class="form-control" placeholder="Değiştirmek için girin">
        </div>
        <div class="form-group">
            <label>Şirket ID</label>
            <input type="text" name="parasut_company_id" value="<?= h(setting('parasut_company_id')) ?>" class="form-control" placeholder="UUID">
        </div>
        <div class="form-group">
            <label>Satış Hesabı Kodu</label>
            <input type="text" name="parasut_sales_account" value="<?= h(setting('parasut_sales_account','600')) ?>" class="form-control" placeholder="600">
        </div>
        <div class="form-group">
            <label>Banka Hesabı ID (Paraşüt'ten)</label>
            <input type="text" name="parasut_bank_account" value="<?= h(setting('parasut_bank_account')) ?>" class="form-control">
        </div>
    </div>
    <?php if (setting('parasut_access_token')): ?>
    <div class="alert alert-success">✓ Token aktif — <?= fmtDate(setting('parasut_token_expires')) ?> tarihine kadar geçerli</div>
    <?php endif; ?>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet & Token Sıfırla</button></div>
</form>
</div>
</div>

<?php elseif ($activeTab === 'github'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">GitHub Güncelleme Sistemi</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="github">
    <div class="form-grid-2">
        <div class="form-group">
            <label>GitHub Token (Personal Access Token)</label>
            <input type="password" name="github_token" value="<?= h(setting('github_token')) ?>" class="form-control" placeholder="ghp_...">
            <p style="font-size:12px;color:var(--text-muted);margin-top:4px">GitHub → Settings → Developer settings → Personal access tokens → repo:read permission</p>
        </div>
        <div class="form-group">
            <label>Repo (user/repo)</label>
            <input type="text" name="github_repo" value="<?= h(setting('github_repo','codegatr/b2b')) ?>" class="form-control">
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>
<?php
$_ver = updater()->getCurrentVersion();
$_sha = updater()->getInstalledSha();
?>
<div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-top:16px">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px">Sürüm Bilgisi</div>
    <div style="font-size:13px;color:var(--text-2)">
        Mevcut sürüm: <strong><?= h($_ver) ?></strong>
        <?php if ($_sha): ?> &nbsp;·&nbsp; Commit: <code style="font-size:11px"><?= h(substr($_sha,0,7)) ?></code><?php endif; ?>
    </div>
    <div style="margin-top:10px">
        <a href="?page=update" class="btn btn-secondary btn-sm">Güncelleme Merkezi →</a>
    </div>
</div>
</div>
</div>

<?php elseif ($activeTab === 'order'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">Sipariş Ayarları</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="order">
    <div class="form-group">
        <label>Otomatik Onay Limiti (₺)</label>
        <input type="number" step="0.01" name="order_auto_approve_limit" value="<?= h(setting('order_auto_approve_limit','0')) ?>" class="form-control">
        <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Bayi sipariş onayı "Otomatik" ise bu limite kadar olan siparişler otomatik onaylanır. 0 = tüm siparişler otomatik.</p>
    </div>
    <div class="form-group">
        <label>Fatura Alt Notu</label>
        <textarea name="invoice_footer" class="form-control" rows="3"><?= h(setting('invoice_footer')) ?></textarea>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>
</div>
</div>
<?php endif; ?>

</div><!-- settings-body -->
</div><!-- settings-layout -->
