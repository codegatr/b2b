<?php
// admin/pages/settings.php — Sistem Ayarları
requireAdmin();

$success = '';
$error   = '';

// ── POST Handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $tab = $_POST['tab'] ?? 'general';

    // Genel
    if ($tab === 'general') {
        $isUpload = !empty($_FILES['login_image']['name']);
        $isRemove = !empty($_POST['remove_login_image']);

        if (!$isUpload && !$isRemove) {
            foreach (['site_name','site_url','order_prefix','currency','timezone','admin_email'] as $f) {
                settingSave($f, trim($_POST[$f] ?? ''));
            }
            settingClearCache();
            $success = 'Genel ayarlar kaydedildi.';
        }

        if ($isUpload) {
            $file = $_FILES['login_image'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = ['svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
            if (isset($mime[$ext])) $file['type'] = $mime[$ext];
            $allowed = ['image/png','image/jpeg','image/webp','image/svg+xml'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'Yükleme hatası: ' . $file['error'];
            } elseif ($file['size'] > 5*1024*1024 && $ext !== 'svg') {
                $error = 'Görsel 5MB\'dan büyük olamaz.';
            } elseif (!in_array($file['type'], $allowed)) {
                $error = 'Desteklenmeyen format: ' . htmlspecialchars($file['type']);
            } else {
                $fname = 'login_hero_' . time() . '.' . $ext;
                $dir   = B2B_ROOT . '/uploads/logo';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!is_writable($dir)) {
                    $error = 'Klasör yazılamıyor.';
                } elseif (move_uploaded_file($file['tmp_name'], $dir . '/' . $fname)) {
                    $old = setting('login_image', '');
                    if ($old && $old !== 'login_hero_logo.svg' && file_exists($dir.'/'.$old)) @unlink($dir.'/'.$old);
                    settingSave('login_image', $fname);
                    settingClearCache();
                    $_SESSION['flash_admin'] = ['type'=>'success','msg'=>'Görsel yüklendi: '.$fname];
                    header('Location: ?page=settings&tab=general');
                    exit;
                } else {
                    $error = 'Dosya taşınamadı.';
                }
            }
        }

        if ($isRemove) {
            $old = setting('login_image', '');
            $dir = B2B_ROOT . '/uploads/logo';
            if ($old && $old !== 'login_hero_logo.svg' && file_exists($dir.'/'.$old)) @unlink($dir.'/'.$old);
            settingSave('login_image', '');
            settingClearCache();
            $success = 'Görsel kaldırıldı.';
        }
    }

    // E-posta
    if ($tab === 'smtp') {
        foreach (['smtp_host','smtp_port','smtp_user','smtp_from_name','smtp_from_email','smtp_secure'] as $f) {
            settingSave($f, trim($_POST[$f] ?? ''));
        }
        if (!empty($_POST['smtp_pass'])) settingSave('smtp_pass', $_POST['smtp_pass']);
        settingClearCache();
        $success = 'E-posta ayarları kaydedildi.';
    }

    // Banka
    if ($tab === 'bank') {
        settingSave('bank_accounts', trim($_POST['bank_accounts'] ?? ''));
        settingClearCache();
        $success = 'Banka hesapları kaydedildi.';
    }

    // Paraşüt
    if ($tab === 'parasut') {
        foreach (['parasut_email','parasut_company_id','parasut_sales_account','parasut_bank_account'] as $f) {
            settingSave($f, trim($_POST[$f] ?? ''));
        }
        if (!empty($_POST['parasut_password'])) settingSave('parasut_password', $_POST['parasut_password']);
        settingSave('parasut_access_token', '');
        settingSave('parasut_token_expires', '');
        settingSave('parasut_auto_invoice', isset(\$_POST['parasut_auto_invoice'])?'1':'0');
        settingClearCache();
        \$success = 'Paraşüt ayarları kaydedildi.';
    }

    // Rubikpara
    if ($tab === 'rubikpara') {
        settingSave('rubikpara_public_key',  trim($_POST['rubikpara_public_key']  ?? ''));
        settingSave('rubikpara_merchant_no', trim($_POST['rubikpara_merchant_no'] ?? ''));
        settingSave('rubikpara_test_mode',   $_POST['rubikpara_test_mode'] ?? '1');
        if (!empty($_POST['rubikpara_secret_key'])) {
            settingSave('rubikpara_secret_key', trim($_POST['rubikpara_secret_key']));
        }
        settingClearCache();
        $success = 'Rubikpara ayarları kaydedildi.';
    }

    // GitHub
    if ($tab === 'github') {
        settingSave('github_token', trim($_POST['github_token'] ?? ''));
        settingSave('github_repo',  trim($_POST['github_repo']  ?? ''));
        settingClearCache();
        $success = 'GitHub ayarları kaydedildi.';
    }

    // Sipariş
    if ($tab === 'order') {
        settingSave('order_auto_approve_limit', (string)floatval($_POST['order_auto_approve_limit'] ?? 0));
        settingSave('invoice_footer', trim($_POST['invoice_footer'] ?? ''));
        settingClearCache();
        $success = 'Sipariş ayarları kaydedildi.';
    }
}

$activeTab = $_GET['tab'] ?? 'general';

// Paraşüt test
$parasutTest = null;
if (isset($_GET['test_parasut'])) {
    try {
        $r = parasut()->testConnection();
        $parasutTest = ['ok'=>true, 'msg'=>'Bağlantı başarılı: '.($r['data']['attributes']['name']??'OK')];
    } catch (Exception $e) {
        $parasutTest = ['ok'=>false, 'msg'=>$e->getMessage()];
    }
}

// Rubikpara test
$rbTest = null;
if (isset($_GET['test_rb']) && function_exists('rubikpara')) {
    $rbTest = rubikpara()->baglantiTest();
}
?>

<div class="page-header">
  <div><h1 class="page-title">Sistem Ayarları</h1></div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($parasutTest): ?>
<div class="alert alert-<?= $parasutTest['ok']?'success':'danger' ?>"><?= htmlspecialchars($parasutTest['msg']) ?></div>
<?php endif; ?>
<?php if ($rbTest): ?>
<div class="alert alert-<?= $rbTest['ok']?'success':'danger' ?>"><?= htmlspecialchars($rbTest['message']) ?></div>
<?php endif; ?>

<div class="settings-layout">

<!-- Sekme Nav -->
<div class="settings-nav">
<?php
$tabs = [
    'general'   => '⚙️ Genel',
    'smtp'      => '📧 E-posta',
    'bank'      => '🏦 Banka',
    'parasut'   => '🔗 Paraşüt',
    'rubikpara' => '💳 Rubikpara',
    'github'    => '🐙 GitHub',
    'order'     => '📦 Sipariş',
];
foreach ($tabs as $k => $v):
?>
<a href="?page=settings&tab=<?= $k ?>" class="settings-nav-item <?= $activeTab===$k?'active':'' ?>"><?= $v ?></a>
<?php endforeach; ?>
</div>

<!-- İçerik -->
<div class="settings-body">

<?php if ($activeTab === 'general'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">Genel Ayarlar</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="general">
    <div class="form-grid-2">
        <div class="form-group"><label class="form-label">Site Adı</label>
            <input type="text" name="site_name" value="<?= htmlspecialchars(setting('site_name')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Site URL</label>
            <input type="text" name="site_url" value="<?= htmlspecialchars(setting('site_url')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Sipariş Ön Eki</label>
            <input type="text" name="order_prefix" value="<?= htmlspecialchars(setting('order_prefix','SIP')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Para Birimi</label>
            <select name="currency" class="form-control">
                <?php foreach (['TRY'=>'₺ Türk Lirası','USD'=>'$ Dolar','EUR'=>'€ Euro'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= setting('currency','TRY')===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="form-group"><label class="form-label">Saat Dilimi</label>
            <select name="timezone" class="form-control">
                <?php foreach (['Europe/Istanbul','UTC','Europe/London'] as $tz): ?>
                <option value="<?= $tz ?>" <?= setting('timezone','Europe/Istanbul')===$tz?'selected':'' ?>><?= $tz ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="form-group"><label class="form-label">Admin E-postası</label>
            <input type="email" name="admin_email" value="<?= htmlspecialchars(setting('admin_email')) ?>" class="form-control"></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>

<!-- Giriş Görseli -->
<div style="border-top:1px solid var(--border);margin-top:24px;padding-top:24px">
<h4 style="font-size:14px;font-weight:600;margin-bottom:16px">Giriş Ekranı Görseli</h4>
<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
    <div>
    <?php
$li    = setting('login_image','');
$liPath = $li ? B2B_ROOT.'/uploads/logo/'.$li : '';
// Dosya yoksa DB kaydını temizle
if ($li && !file_exists($liPath)) {
    settingSave('login_image', '');
    settingClearCache();
    $li = '';
    $liPath = '';
}
?>
    <?php if ($li && $liPath): ?>
        <div style="position:relative;display:inline-block">
            <img src="/uploads/logo/<?= htmlspecialchars($li) ?>"
                 style="width:140px;height:140px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:var(--bg)"
                 onerror="this.parentElement.innerHTML='<div style=\'width:140px;height:140px;border:2px dashed #e4e6ea;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#9aa5b4\'>Dosya bulunamadı</div>'">
            <form method="post" style="position:absolute;top:6px;right:6px">
                <?= csrfField() ?>
                <input type="hidden" name="tab" value="general">
                <input type="hidden" name="remove_login_image" value="1">
                <button style="background:rgba(220,38,38,.85);border:none;border-radius:5px;padding:4px 6px;cursor:pointer;color:#fff;line-height:0" title="Kaldır">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </form>
        </div>
    <?php else: ?>
        <div style="width:140px;height:140px;border:2px dashed var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:12px">Görsel Yok</div>
    <?php endif; ?>
    </div>

    <!-- Upload formu -->
    <div style="flex:1;min-width:220px">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="tab" value="general">
            <div class="form-group">
                <label class="form-label">Yeni Görsel Yükle</label>
                <input type="file" name="login_image" class="form-control"
                       accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg">
                <p style="font-size:12px;color:var(--text-muted);margin-top:4px">
                    PNG, JPG, WEBP, <strong>SVG</strong> — Maks 5MB.
                    SVG tercih edilir (sonsuz çözünürlük).
                </p>
            </div>
            <button type="submit" class="btn btn-secondary">Görseli Yükle</button>
        </form>
    </div>

</div><!-- /flex -->
</div><!-- /Giriş Görseli -->
</div></div>

<?php elseif ($activeTab === 'smtp'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">E-posta (SMTP) Ayarları</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="smtp">
    <div class="form-grid-2">
        <div class="form-group"><label class="form-label">SMTP Sunucu</label>
            <input type="text" name="smtp_host" value="<?= htmlspecialchars(setting('smtp_host')) ?>" class="form-control" placeholder="smtp.gmail.com"></div>
        <div class="form-group"><label class="form-label">Port</label>
            <input type="number" name="smtp_port" value="<?= htmlspecialchars(setting('smtp_port','587')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Kullanıcı Adı</label>
            <input type="text" name="smtp_user" value="<?= htmlspecialchars(setting('smtp_user')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Şifre</label>
            <input type="password" name="smtp_pass" class="form-control" placeholder="Değiştirmek için girin"></div>
        <div class="form-group"><label class="form-label">Gönderen Ad</label>
            <input type="text" name="smtp_from_name" value="<?= htmlspecialchars(setting('smtp_from_name')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Gönderen E-posta</label>
            <input type="email" name="smtp_from_email" value="<?= htmlspecialchars(setting('smtp_from_email')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Güvenlik</label>
            <select name="smtp_secure" class="form-control">
                <?php foreach (['tls'=>'TLS','ssl'=>'SSL',''=>'Yok'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= setting('smtp_secure','tls')===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>
</div></div>

<?php elseif ($activeTab === 'bank'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">Banka Hesapları</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="bank">
    <div class="form-group">
        <label class="form-label">Banka Hesap Bilgileri (her satır bir hesap)</label>
        <textarea name="bank_accounts" class="form-control" rows="8"><?= htmlspecialchars(setting('bank_accounts')) ?></textarea>
        <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Örnek: Ziraat Bankası — TR00 0000 0000 0000 0000 00</p>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>
</div></div>

<?php elseif ($activeTab === 'parasut'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">Paraşüt Entegrasyonu</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="parasut">
    <div class="form-grid-2">
        <div class="form-group"><label class="form-label">E-posta</label>
            <input type="email" name="parasut_email" value="<?= htmlspecialchars(setting('parasut_email')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Şifre</label>
            <input type="password" name="parasut_password" class="form-control" placeholder="Değiştirmek için girin"></div>
        <div class="form-group"><label class="form-label">Firma ID</label>
            <input type="text" name="parasut_company_id" value="<?= htmlspecialchars(setting('parasut_company_id')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Satış Hesabı</label>
            <input type="text" name="parasut_sales_account" value="<?= htmlspecialchars(setting('parasut_sales_account')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Banka Hesabı</label>
            <input type="text" name="parasut_bank_account" value="<?= htmlspecialchars(setting('parasut_bank_account')) ?>" class="form-control"></div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <a href="?page=settings&tab=parasut&test_parasut=1" class="btn btn-secondary">Bağlantıyı Test Et</a>
    </div>
</form>

<!-- Stok Senkronizasyonu -->
<div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px">
<h4 style="font-size:14px;font-weight:600;margin-bottom:12px">📦 Stok Senkronizasyonu</h4>
<div class="form-grid-2">
  <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px">⬇️ Paraşüt'ten Stok Al</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
      Paraşüt'teki ürün stok miktarlarını B2B sistemine çeker.
      SKU eşleşmesi ile otomatik güncelleme yapılır.
    </p>
    <a href="?page=stock&parasut_sync=1"
       onclick="return confirm('Paraşüt'ten stok çekilecek. Devam?')"
       class="btn btn-secondary btn-sm">Şimdi Senkronize Et</a>
    <p style="font-size:11px;color:var(--text-muted);margin-top:8px">
      Detaylı stok yönetimi için: <a href="?page=stock">Stok Sayfası →</a>
    </p>
  </div>
  <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:16px">
    <div style="font-weight:600;font-size:13px;margin-bottom:6px">⬆️ Sipariş Faturası Gönder</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
      Onaylanan siparişler için Paraşüt'te otomatik satış faturası oluşturulur.
      Fatura oluşturulunca stok Paraşüt tarafında otomatik düşer.
    </p>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="tab" value="parasut">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="parasut_auto_invoice" value="1"
               <?= setting('parasut_auto_invoice','0')==='1'?'checked':'' ?>>
        Sipariş onaylanınca otomatik fatura oluştur
      </label>
      <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:10px">Kaydet</button>
    </form>
  </div>
</div>
</div>

</div></div>

<?php elseif ($activeTab === 'rubikpara'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">💳 Rubikpara (PF Gateway)</h3></div>
<div class="card-body">
<?php if (!function_exists('rubikpara')): ?>
<div class="alert alert-warning">Rubikpara modülü henüz yüklenmemiş. Güncelleme Merkezi'nden güncelleyin.</div>
<?php else: ?>
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="rubikpara">
    <div class="form-grid-2">
        <div class="form-group"><label class="form-label">Public Key</label>
            <input type="text" name="rubikpara_public_key" value="<?= htmlspecialchars(setting('rubikpara_public_key')) ?>" class="form-control" placeholder="your-public-key"></div>
        <div class="form-group"><label class="form-label">Merchant Number</label>
            <input type="text" name="rubikpara_merchant_no" value="<?= htmlspecialchars(setting('rubikpara_merchant_no')) ?>" class="form-control" placeholder="000001"></div>
        <div class="form-group"><label class="form-label">Secret Key (Base64)</label>
            <input type="password" name="rubikpara_secret_key" value="<?= htmlspecialchars(setting('rubikpara_secret_key')) ?>" class="form-control">
            <p style="font-size:12px;color:var(--text-muted);margin-top:4px">İmza oluşturmada kullanılır, API isteğinde gönderilmez.</p></div>
        <div class="form-group"><label class="form-label">Ortam</label>
            <select name="rubikpara_test_mode" class="form-control">
                <option value="1" <?= setting('rubikpara_test_mode','1')==='1'?'selected':'' ?>>Test (testpfapi.rubikpara.com)</option>
                <option value="0" <?= setting('rubikpara_test_mode','1')==='0'?'selected':'' ?>>Canlı (pfapi.rubikpara.com)</option>
            </select></div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <?php if (function_exists('rubikpara') && rubikpara()->ayarliMi()): ?>
        <a href="?page=settings&tab=rubikpara&test_rb=1" class="btn btn-secondary">Test Et</a>
        <?php endif; ?>
        <a href="https://developer.rubikpara.com" target="_blank" class="btn btn-ghost">📖 Döküman</a>
    </div>
</form>
<?php if (function_exists('rubikpara') && rubikpara()->ayarliMi()): ?>
<div class="alert alert-success" style="margin-top:16px">✅ Rubikpara aktif — bayi sipariş ekranında "Kart ile Öde" görünür.</div>
<?php endif; ?>
<?php endif; ?>
</div></div>

<?php elseif ($activeTab === 'github'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">🐙 GitHub / Güncelleme</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="github">
    <div class="form-grid-2">
        <div class="form-group"><label class="form-label">GitHub Token (opsiyonel)</label>
            <input type="password" name="github_token" value="<?= htmlspecialchars(setting('github_token')) ?>" class="form-control" placeholder="ghp_..."></div>
        <div class="form-group"><label class="form-label">Repo (owner/name)</label>
            <input type="text" name="github_repo" value="<?= htmlspecialchars(setting('github_repo','codegatr/b2b')) ?>" class="form-control"></div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <a href="?page=update" class="btn btn-secondary">Güncelleme Merkezi →</a>
    </div>
</form>
<div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-top:16px">
    <div style="font-size:13px;color:var(--text-2)">
        Mevcut sürüm: <strong><?= htmlspecialchars(updater()->getCurrentVersion()) ?></strong>
        <?php $sha = updater()->getInstalledSha(); if ($sha): ?>
        &nbsp;·&nbsp; Commit: <code style="font-size:11px"><?= htmlspecialchars(substr($sha,0,7)) ?></code>
        <?php endif; ?>
    </div>
</div>
</div></div>

<?php elseif ($activeTab === 'order'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">📦 Sipariş Ayarları</h3></div>
<div class="card-body">
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="order">
    <div class="form-group">
        <label class="form-label">Otomatik Onay Limiti (₺)</label>
        <input type="number" step="0.01" name="order_auto_approve_limit" value="<?= htmlspecialchars(setting('order_auto_approve_limit','0')) ?>" class="form-control">
        <p style="font-size:12px;color:var(--text-muted);margin-top:4px">0 = tüm siparişler otomatik onaylanır.</p>
    </div>
    <div class="form-group">
        <label class="form-label">Fatura Alt Notu</label>
        <textarea name="invoice_footer" class="form-control" rows="3"><?= htmlspecialchars(setting('invoice_footer')) ?></textarea>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Kaydet</button></div>
</form>
</div></div>

<?php endif; ?>

</div><!-- settings-body -->
</div><!-- settings-layout -->
