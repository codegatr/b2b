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
            // Fiyat giriş modu: '1' = KDV dahil giriş, '0' = KDV hariç giriş
            settingSave('price_input_includes_vat', isset($_POST['price_input_includes_vat']) ? '1' : '0');
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
                    redirect('?page=settings&tab=general');
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
        // Test mail gönder
        if (!empty($_POST['do_test_smtp'])) {
            $admin = dbRow("SELECT email FROM b2b_admin_users WHERE id=?", [adminId()]);
            $testTo = $admin['email'] ?? '';
            if (!$testTo) {
                $_SESSION['flash_admin'] = ['type'=>'danger','msg'=>'Admin kullanıcısının e-posta adresi yok.'];
            } else {
                $body = mailTemplate('Test E-postası',
                    '<p style="margin:0 0 16px;font-size:14px;color:#374151;line-height:1.7">'.
                    'Bu bir <strong>SMTP konfigürasyon test maili</strong>dir. Bu maili aldıysanız e-posta gönderim ayarlarınız çalışıyor demektir. ✓</p>'.
                    '<p style="margin:0;font-size:12px;color:#9ca3af">Tarih: '.date('d.m.Y H:i').'</p>'
                );
                $ok = sendMail($testTo, '[Test] SMTP Konfigürasyon Testi', $body);
                // Son log kaydını çek (sendMail az önce yazdı)
                $last = dbRow("SELECT * FROM b2b_mail_log ORDER BY id DESC LIMIT 1");
                $note = $last['note'] ?? '';
                if ($ok) {
                    $_SESSION['flash_admin'] = ['type'=>'success','msg'=>"Test maili gönderildi: $testTo · $note"];
                } else {
                    $_SESSION['flash_admin'] = ['type'=>'danger','msg'=>"Mail gönderilemedi · $note"];
                }
            }
            redirect('?page=settings&tab=smtp');
        }
        foreach (['smtp_host','smtp_port','smtp_user','smtp_from_name','smtp_from_email','smtp_secure'] as $f) {
            settingSave($f, trim($_POST[$f] ?? ''));
        }
        if (!empty($_POST['smtp_pass'])) settingSave('smtp_pass', $_POST['smtp_pass']);
        settingClearCache();
        redirect('?page=settings&tab=smtp&saved=1');
    }

    // Banka
    if ($tab === 'bank') {
        // Yapılandırılmış banka kayıtları — admin çoklu IBAN ekleyip kaldırabilir
        $banks = $_POST['banks'] ?? [];
        $clean = [];
        if (is_array($banks)) {
            foreach ($banks as $b) {
                $name   = trim($b['name']    ?? '');
                $iban   = trim($b['iban']    ?? '');
                $holder = trim($b['holder']  ?? '');
                $branch = trim($b['branch']  ?? '');
                $note   = trim($b['note']    ?? '');
                if ($name === '' && $iban === '') continue; // tamamen boşsa atla
                // IBAN normalize: boşlukları temizle, büyük harfe çevir
                $ibanNorm = strtoupper(preg_replace('/\s+/', '', $iban));
                $clean[] = [
                    'name'   => $name,
                    'iban'   => $ibanNorm,
                    'holder' => $holder,
                    'branch' => $branch,
                    'note'   => $note,
                ];
            }
        }
        settingSave('bank_accounts_json', json_encode($clean, JSON_UNESCAPED_UNICODE));
        // Bayi panel'in eski textarea bazlı render'ı için legacy text formatına da yaz
        $legacyLines = [];
        foreach ($clean as $i => $b) {
            $line = $b['name'];
            if ($b['branch'] !== '') $line .= ' — ' . $b['branch'];
            if ($line !== '') $legacyLines[] = $line;
            if ($b['iban']   !== '') $legacyLines[] = $b['iban'];
            if ($b['holder'] !== '') $legacyLines[] = $b['holder'];
            if ($b['note']   !== '') $legacyLines[] = $b['note'];
            if ($i < count($clean) - 1) $legacyLines[] = ''; // kayıtlar arası boş satır
        }
        settingSave('bank_accounts', implode("\n", $legacyLines));
        settingClearCache();
        redirect('?page=settings&tab=bank&saved=1');
    }

    // Paraşüt
    if ($tab === 'parasut') {
        foreach (['parasut_email','parasut_company_id','parasut_sales_account','parasut_bank_account','parasut_client_id','parasut_client_secret'] as $f) {
            settingSave($f, trim($_POST[$f] ?? ''));
        }
        if (!empty($_POST['parasut_password'])) settingSave('parasut_password', $_POST['parasut_password']);
        settingSave('parasut_access_token', '');
        settingSave('parasut_token_expires', '');
        settingSave('parasut_auto_invoice', isset($_POST['parasut_auto_invoice'])?'1':'0');
        settingClearCache();
        // Test butonu ile geldiyse → test URL'sine yönlendir
        if (!empty($_POST['do_test_parasut'])) {
            redirect('?page=settings&tab=parasut&test_parasut=1');
        } else {
            redirect('?page=settings&tab=parasut&saved=1');
        }
    }

    // Rubikpara
    if ($tab === 'rubikpara') {
        settingSave('rubikpara_public_key',  trim($_POST['rubikpara_public_key']  ?? ''));
        settingSave('rubikpara_merchant_no', trim($_POST['rubikpara_merchant_no'] ?? ''));
        settingSave('rubikpara_test_mode',   $_POST['rubikpara_test_mode'] ?? '1');
        if (!empty($_POST['rubikpara_secret_key'])) {
            settingSave('rubikpara_secret_key', trim($_POST['rubikpara_secret_key']));
        }
        // Tek çekim komisyon oranı (admin tarafından bayiye yansıtılır)
        $singleRate = (float) str_replace(',', '.', $_POST['rubikpara_single_rate'] ?? '0');
        $singleRate = max(0, min(100, $singleRate)); // 0-100 arası
        settingSave('rubikpara_single_rate', (string)$singleRate);
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

    // SMS / NETGSM
    if ($tab === 'sms') {
        settingSave('netgsm_enabled', isset($_POST['netgsm_enabled']) ? '1' : '0');
        settingSave('netgsm_user',        trim($_POST['netgsm_user']        ?? ''));
        settingSave('netgsm_header',      trim($_POST['netgsm_header']      ?? ''));
        settingSave('netgsm_admin_phone', trim($_POST['netgsm_admin_phone'] ?? ''));
        if (!empty($_POST['netgsm_pass'])) settingSave('netgsm_pass', $_POST['netgsm_pass']);
        $events = array_values(array_filter($_POST['netgsm_events'] ?? []));
        settingSave('netgsm_events', json_encode($events));
        settingClearCache();
        $success = 'SMS ayarları kaydedildi.';
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
if (!empty($_GET['saved'])) $success = 'Ayarlar kaydedildi.';

// SMS test
$smsTest = null;
if (isset($_GET['test_sms']) && function_exists('smsAdmin')) {
    $smsTest = smsAdmin(setting('site_name','B2B') . ': SMS test mesajı. Sistem çalışıyor!');
}

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
<?php if (!empty($rbTest['sonuclar'])): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3 class="card-title">🔍 Rubikpara Diagnostic</h3></div>
  <div class="card-body" style="padding:0">
    <?php foreach ($rbTest['sonuclar'] as $r): ?>
    <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border)">
      <div style="font-size:18px;flex-shrink:0"><?= $r['ok']?'✅':'❌' ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($r['adim']) ?></div>
        <div style="font-size:12px;color:<?= $r['ok']?'var(--text-muted)':'#dc2626' ?>;word-break:break-all;white-space:pre-wrap;margin-top:2px"><?= htmlspecialchars($r['detay']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
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
    'sms'       => '📱 SMS (NETGSM)',
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

    <!-- Fiyat giriş modu — tüm ürünlere etki eder -->
    <div style="background:linear-gradient(135deg,#fef3c7,#fffbeb);border:1px solid #fcd34d;border-radius:8px;padding:14px 16px;margin-top:16px">
        <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;margin:0">
            <input type="checkbox" name="price_input_includes_vat" value="1"
                   <?= setting('price_input_includes_vat','0')==='1'?'checked':'' ?>
                   style="margin-top:3px;width:18px;height:18px;cursor:pointer">
            <div style="flex:1">
                <div style="font-weight:700;color:#92400e;margin-bottom:3px">💰 Ürün fiyatlarını KDV dahil olarak gir</div>
                <div style="font-size:12px;color:#78350f;line-height:1.5">
                    İşaretli: Ürün ekle/düzenle ekranında girdiğin fiyat <strong>KDV dahil (brüt)</strong> kabul edilir, sistem otomatik olarak net (KDV hariç) hesaplayıp DB'ye kaydeder.<br>
                    İşaretsiz: Girdiğin fiyat <strong>KDV hariç (net)</strong> olur, KDV ayrıca eklenir.<br>
                    <span style="opacity:.75"><strong>Önemli:</strong> Bu sadece giriş tarafını etkiler. DB ve Paraşüt'te fiyatlar her zaman net saklanır. Mevcut ürünlerde bir değişiklik olmaz.</span>
                </div>
            </div>
        </label>
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

<!-- Test Mail Butonu -->
<div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
  <h4 style="margin:0 0 10px;font-size:13px;font-weight:700;color:var(--text)">📧 SMTP Test</h4>
  <p style="margin:0 0 12px;font-size:12px;color:var(--text-muted);line-height:1.6">
    Mevcut SMTP ayarlarıyla kendi adresinize test maili gönderin. Mail gelmiyorsa aşağıdaki log tablosunda hata mesajı görünecektir.
  </p>
  <form method="post" style="margin:0">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="smtp">
    <input type="hidden" name="do_test_smtp" value="1">
    <button type="submit" class="btn" style="background:#0ea5e9;color:#fff;border:none">
      📤 Test Maili Gönder
    </button>
  </form>
</div>

<!-- Son Mail Logları -->
<?php
$logsExist = (int)dbVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='b2b_mail_log'");
if ($logsExist):
    $mailLogs = dbRows("SELECT * FROM b2b_mail_log ORDER BY id DESC LIMIT 10");
?>
<div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
  <h4 style="margin:0 0 12px;font-size:13px;font-weight:700;color:var(--text)">📋 Son Mail Logları</h4>
  <?php if (empty($mailLogs)): ?>
    <p style="margin:0;font-size:12px;color:var(--text-muted);font-style:italic">Henüz log kaydı yok.</p>
  <?php else: ?>
    <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;overflow:hidden">
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
          <tr style="background:#f1f5f9">
            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--text-2);font-weight:700">DURUM</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--text-2);font-weight:700">ALICI</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--text-2);font-weight:700">KONU</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;color:var(--text-2);font-weight:700">NOT</th>
            <th style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text-2);font-weight:700">TARİH</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($mailLogs as $log): ?>
          <tr style="border-top:1px solid var(--border);background:<?= $log['success'] ? '#f0fdf4' : '#fef2f2' ?>">
            <td style="padding:8px 10px;white-space:nowrap"><?= $log['success'] ? '<span style="color:#16a34a;font-weight:700">✓ OK</span>' : '<span style="color:#dc2626;font-weight:700">✗ Hata</span>' ?></td>
            <td style="padding:8px 10px;font-family:ui-monospace,monospace;font-size:11px"><?= h($log['recipient']) ?></td>
            <td style="padding:8px 10px;font-size:11px"><?= h(mb_substr($log['subject'], 0, 50)) ?></td>
            <td style="padding:8px 10px;font-size:11px;color:<?= $log['success'] ? 'var(--text-2)' : '#dc2626' ?>"><?= h($log['note']) ?></td>
            <td style="padding:8px 10px;text-align:right;font-size:11px;color:var(--text-muted);white-space:nowrap"><?= h(date('d.m.Y H:i', strtotime($log['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="margin-top:20px;padding:14px 16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:12px;color:#92400e">
  ⚠️ <strong>migration_012.sql çalıştırılmamış.</strong>
  Mail log tablosu (b2b_mail_log) eksik. Admin → Güncelleme Merkezi'nden güncelleme yaparsanız otomatik oluşturulacak.
</div>
<?php endif; ?>

</div></div>

<?php elseif ($activeTab === 'bank'): ?>
<?php
// JSON varsa onu kullan, yoksa eski text formatından parse et (geriye dönük migration)
$banksJson = setting('bank_accounts_json', '');
$banks     = $banksJson !== '' ? (json_decode($banksJson, true) ?: []) : [];
if (empty($banks)) {
    // Eski 'bank_accounts' text alanını parse etmeye çalış (boş satır = kayıt ayracı)
    $legacy = trim(setting('bank_accounts', ''));
    if ($legacy !== '') {
        $blocks = preg_split('/\n\s*\n/', $legacy);
        foreach ($blocks as $blk) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $blk)), fn($l) => $l !== ''));
            if (!$lines) continue;
            // Heuristik: ilk satır banka adı, IBAN'a benzeyen satırı yakala, hesap sahibi en son metin
            $entry = ['name'=>'','iban'=>'','holder'=>'','branch'=>'','note'=>''];
            $entry['name'] = $lines[0];
            foreach ($lines as $l) {
                $clean = strtoupper(preg_replace('/\s+/', '', $l));
                if (preg_match('/^TR\d{24}$/', $clean)) { $entry['iban'] = $clean; }
            }
            $remaining = array_values(array_filter($lines, function($l) use ($entry) {
                $clean = strtoupper(preg_replace('/\s+/', '', $l));
                return $l !== $entry['name'] && $clean !== $entry['iban'];
            }));
            if (!empty($remaining)) $entry['holder'] = implode(' ', $remaining);
            $banks[] = $entry;
        }
    }
}
if (empty($banks)) $banks = [['name'=>'','iban'=>'','holder'=>'','branch'=>'','note'=>'']]; // boş şablon
?>
<div class="card">
<div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
  <h3 class="card-title">Banka Hesapları</h3>
  <span style="font-size:12px;color:var(--text-muted)">Bayilerin havale bildirim sayfasında görünür</span>
</div>
<div class="card-body">
<form method="post" id="bankForm">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="bank">

    <div id="bankList">
      <?php foreach ($banks as $i => $b): ?>
      <div class="bank-row" style="position:relative;background:#fafbfc;border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="background:var(--red);color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px">
              HESAP <span class="bank-num"><?= $i + 1 ?></span>
            </span>
            <strong class="bank-preview-name" style="font-size:13px;color:var(--text)"><?= h($b['name'] ?: 'Yeni hesap') ?></strong>
          </div>
          <button type="button" class="btn-bank-remove" style="background:transparent;border:none;color:#dc2626;cursor:pointer;font-size:13px;padding:4px 8px"
                  onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='transparent'">
            🗑 Kaldır
          </button>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:12px">Banka Adı *</label>
            <input type="text" name="banks[<?= $i ?>][name]" class="form-control bank-input-name"
                   value="<?= h($b['name'] ?? '') ?>" placeholder="Yapı Kredi Bankası" required>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:12px">Şube (Opsiyonel)</label>
            <input type="text" name="banks[<?= $i ?>][branch]" class="form-control"
                   value="<?= h($b['branch'] ?? '') ?>" placeholder="Konya Merkez">
          </div>
          <div class="form-group" style="margin-bottom:0;grid-column:span 2">
            <label class="form-label" style="font-size:12px">IBAN *</label>
            <input type="text" name="banks[<?= $i ?>][iban]" class="form-control bank-iban"
                   value="<?= h($b['iban'] ?? '') ?>"
                   placeholder="TR00 0000 0000 0000 0000 0000 00"
                   style="font-family:ui-monospace,'SF Mono',Consolas,monospace;letter-spacing:.5px"
                   pattern="^[Tt][Rr][\s\d]{24,32}$"
                   title="IBAN 'TR' ile başlamalı ve 24 hane içermelidir.">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:12px">Hesap Sahibi *</label>
            <input type="text" name="banks[<?= $i ?>][holder]" class="form-control"
                   value="<?= h($b['holder'] ?? '') ?>" placeholder="Le Monde Du Tacos Ltd. Şti." required>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:12px">Açıklama (Opsiyonel)</label>
            <input type="text" name="banks[<?= $i ?>][note]" class="form-control"
                   value="<?= h($b['note'] ?? '') ?>" placeholder="TL hesabı / EUR hesabı / vs.">
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
      <button type="button" id="bankAddBtn" class="btn btn-secondary"
              style="background:#fff;border:1.5px dashed var(--border-2);color:var(--text-2)">
        ➕ Yeni Banka Hesabı Ekle
      </button>
      <button type="submit" class="btn btn-primary" style="margin-left:auto">💾 Kaydet</button>
    </div>
</form>
</div></div>

<script>
(function(){
  const list = document.getElementById('bankList');
  const addBtn = document.getElementById('bankAddBtn');

  function renumber() {
    list.querySelectorAll('.bank-row').forEach((row, i) => {
      row.querySelector('.bank-num').textContent = i + 1;
      row.querySelectorAll('input').forEach(inp => {
        inp.name = inp.name.replace(/banks\[\d+\]/, 'banks[' + i + ']');
      });
    });
  }

  function attachHandlers(row) {
    row.querySelector('.btn-bank-remove').addEventListener('click', () => {
      if (list.children.length === 1) {
        alert('En az bir banka hesabı kalmalı. Boş kalmaması için Kaldır yerine alanları temizleyebilirsiniz.');
        return;
      }
      if (confirm('Bu banka hesabını silmek istediğinize emin misiniz?')) {
        row.remove();
        renumber();
      }
    });
    const nameInp = row.querySelector('.bank-input-name');
    const preview = row.querySelector('.bank-preview-name');
    nameInp.addEventListener('input', () => {
      preview.textContent = nameInp.value || 'Yeni hesap';
    });
    // IBAN auto-format: 4'lü gruplar halinde boşluk
    const iban = row.querySelector('.bank-iban');
    iban.addEventListener('input', e => {
      let v = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 26);
      e.target.value = v.replace(/(.{4})/g, '$1 ').trim();
    });
  }

  // Mevcut satırlar için handler
  list.querySelectorAll('.bank-row').forEach(attachHandlers);

  // Yeni satır ekleme
  addBtn.addEventListener('click', () => {
    const tpl = list.children[0].cloneNode(true);
    tpl.querySelectorAll('input').forEach(i => i.value = '');
    tpl.querySelector('.bank-preview-name').textContent = 'Yeni hesap';
    list.appendChild(tpl);
    renumber();
    attachHandlers(tpl);
    tpl.querySelector('.bank-input-name').focus();
  });
})();
</script>

<?php elseif ($activeTab === 'parasut'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">Paraşüt Entegrasyonu</h3></div>
<div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:12px 20px;font-size:13px;color:#92400e;line-height:1.6">
  ⚠️ <strong>Client ID ve Client Secret</strong> Paraşüt tarafından verilmesi gereken bilgilerdir.<br>
  <strong>destek@parasut.com</strong> adresine şu e-postayı gönderin:<br>
  <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;margin-top:8px;font-family:monospace;font-size:12px;color:#78350f;white-space:pre-wrap">Merhaba,

Sistemimizi Paraşüt ile entegre etmek istiyoruz.
API için gerekli Client ID ve Client Secret bilgilerinin
tarafımıza iletilmesini rica ederim.

Paraşüt hesap e-postası: info@lemondedutacos.com

Saygılarımla</div>
  <span style="color:#a16207;font-size:12px;margin-top:6px;display:block">💡 Firma ID: Paraşüt panelindeki URL'den → uygulama.parasut.com/<strong>493289</strong>/ (zaten doğru girilmiş)</span>
</div>
<div class="card-body">
<form method="post" id="parasut-form">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="parasut">
    <div class="form-grid-2">
        <div class="form-group"><label class="form-label">E-posta</label>
            <input type="email" name="parasut_email" value="<?= htmlspecialchars(setting('parasut_email')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Şifre</label>
            <input type="password" name="parasut_password" class="form-control" placeholder="Değiştirmek için girin"></div>
        <div class="form-group"><label class="form-label">Client ID</label>
            <input type="text" name="parasut_client_id" value="<?= htmlspecialchars(setting('parasut_client_id')) ?>" class="form-control" placeholder="Paraşüt OAuth2 Client ID"></div>
        <div class="form-group"><label class="form-label">Client Secret</label>
            <input type="text" name="parasut_client_secret" value="<?= htmlspecialchars(setting('parasut_client_secret')) ?>" class="form-control" placeholder="Paraşüt OAuth2 Client Secret"></div>
        <div class="form-group"><label class="form-label">Firma ID</label>
            <input type="text" name="parasut_company_id" value="<?= htmlspecialchars(setting('parasut_company_id')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Satış Hesabı</label>
            <input type="text" name="parasut_sales_account" value="<?= htmlspecialchars(setting('parasut_sales_account')) ?>" class="form-control"></div>
        <div class="form-group"><label class="form-label">Banka Hesabı</label>
            <input type="text" name="parasut_bank_account" value="<?= htmlspecialchars(setting('parasut_bank_account')) ?>" class="form-control"></div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Kaydet</button>
        <button type="submit" name="do_test_parasut" value="1" class="btn btn-secondary">Bağlantıyı Test Et</button>
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
                <option value="0" <?= setting('rubikpara_test_mode','1')==='0'?'selected':'' ?>>Canlı (prodpfapi.rubikpara.com)</option>
            </select></div>
    </div>

    <hr style="margin:20px 0;border:none;border-top:1px solid var(--border)">

    <div class="form-grid-2">
        <div class="form-group">
            <label class="form-label">Tek Çekim Komisyon Oranı (%)</label>
            <input type="number" step="0.01" min="0" max="100"
                   name="rubikpara_single_rate"
                   value="<?= htmlspecialchars(setting('rubikpara_single_rate', '0')) ?>"
                   class="form-control" placeholder="0.00">
            <p style="font-size:12px;color:var(--text-muted);margin-top:4px">
                Bayi tek çekim seçtiğinde bu oran sipariş tutarına eklenir ve karttan tahsil edilir.
                Örn: <strong>2.00</strong> girersen 100 TL siparişten 102 TL çekilir, 2 TL bayinin sırtında kalır.
                Taksitli ödemelerde Rubikpara'nın bankadan döndürdüğü oran kullanılır (bu alan tek çekim için).
                <strong>0</strong> = komisyon yok.
            </p>
        </div>
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

<?php elseif ($activeTab === 'sms'): ?>
<div class="card">
<div class="card-header"><h3 class="card-title">📱 NETGSM SMS Bildirimleri</h3></div>
<div class="card-body">

<?php if ($smsTest): ?>
<div class="alert alert-<?= $smsTest['ok']?'success':'danger' ?>"><?= htmlspecialchars($smsTest['message']) ?></div>
<?php endif; ?>

<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="tab" value="sms">

  <div class="form-group">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;font-weight:600">
      <input type="checkbox" name="netgsm_enabled" value="1" <?= setting('netgsm_enabled','0')==='1'?'checked':'' ?>>
      SMS Bildirimlerini Aktifleştir
    </label>
    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">
      Aktif olduğunda aşağıdaki olaylarda belirtilen numaraya SMS gönderilir.
    </p>
  </div>

  <div class="form-grid-2" style="margin-top:16px">
    <div class="form-group">
      <label class="form-label">NETGSM Kullanıcı Kodu</label>
      <input type="text" name="netgsm_user" value="<?= htmlspecialchars(setting('netgsm_user')) ?>"
             class="form-control" placeholder="8501234567">
    </div>
    <div class="form-group">
      <label class="form-label">NETGSM Şifre</label>
      <input type="password" name="netgsm_pass" class="form-control" placeholder="Değiştirmek için girin">
    </div>
    <div class="form-group">
      <label class="form-label">Mesaj Başlığı (Header)</label>
      <input type="text" name="netgsm_header" value="<?= htmlspecialchars(setting('netgsm_header')) ?>"
             class="form-control" placeholder="FIRMADINIZ" maxlength="11">
      <p style="font-size:11px;color:var(--text-muted);margin-top:3px">Maks 11 karakter, NETGSM panelinde onaylanmış başlık.</p>
    </div>
    <div class="form-group">
      <label class="form-label">Yönetici Telefon Numarası</label>
      <input type="text" name="netgsm_admin_phone" value="<?= htmlspecialchars(setting('netgsm_admin_phone')) ?>"
             class="form-control" placeholder="05XXXXXXXXX">
      <p style="font-size:11px;color:var(--text-muted);margin-top:3px">Bildirimlerin gönderileceği numara.</p>
    </div>
  </div>

  <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px">
    <h4 style="font-size:13px;font-weight:600;margin-bottom:12px">SMS Gönderilecek Olaylar</h4>
    <?php
    $activeEvents = json_decode(setting('netgsm_events','[]'), true) ?: [];
    $eventList = [
        'new_order'            => ['🛒', 'Yeni Sipariş',          'Bayi sipariş verdiğinde'],
        'order_cancel_request' => ['❌', 'İptal Talebi',          'Bayi sipariş iptali istediğinde'],
        'new_payment'          => ['💳', 'Ödeme Bildirimi',       'Bayi ödeme bildirdiğinde'],
        'new_dealer'           => ['👤', 'Yeni Bayi Başvurusu',   'Yeni bayilik başvurusu geldiğinde'],
        'new_ticket'           => ['🎫', 'Destek Talebi',         'Yeni destek talebi açıldığında'],
        'low_stock'            => ['📦', 'Kritik Stok Uyarısı',   'Ürün kritik stok seviyesine düştüğünde'],
    ];
    foreach ($eventList as $key => [$icon, $label, $desc]):
    ?>
    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 0;border-bottom:1px solid var(--border)">
      <input type="checkbox" name="netgsm_events[]" value="<?= $key ?>"
             <?= in_array($key, $activeEvents)?'checked':'' ?> style="margin-top:2px">
      <div>
        <div style="font-size:13px;font-weight:500"><?= $icon ?> <?= $label ?></div>
        <div style="font-size:12px;color:var(--text-muted)"><?= $desc ?></div>
      </div>
    </label>
    <?php endforeach; ?>
  </div>

  <div class="form-actions" style="margin-top:16px">
    <button type="submit" class="btn btn-primary">Kaydet</button>
    <?php if (setting('netgsm_enabled','0')==='1'): ?>
    <a href="?page=settings&tab=sms&test_sms=1" class="btn btn-secondary">📱 Test SMS Gönder</a>
    <?php endif; ?>
    <a href="https://www.netgsm.com.tr/api/" target="_blank" class="btn btn-ghost">📖 API Dokümantasyonu</a>
  </div>
</form>

<!-- Son SMS Logları -->
<?php
try {
    $smsLogs = dbRows("SELECT * FROM b2b_sms_log ORDER BY created_at DESC LIMIT 10");
} catch (Exception $e) { $smsLogs = []; }
?>
<?php if (!empty($smsLogs)): ?>
<div style="border-top:1px solid var(--border);margin-top:24px;padding-top:24px">
  <h4 style="font-size:13px;font-weight:600;margin-bottom:12px">Son SMS Logları</h4>
  <table class="table" style="font-size:12px">
    <thead><tr><th>Tarih</th><th>Numara</th><th>Mesaj</th><th>Durum</th></tr></thead>
    <tbody>
    <?php foreach ($smsLogs as $sl): ?>
    <tr>
      <td style="color:var(--text-muted)"><?= date('d.m.Y H:i', strtotime($sl['created_at'])) ?></td>
      <td><?= htmlspecialchars($sl['phone']) ?></td>
      <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($sl['message']) ?></td>
      <td><span class="badge badge-<?= $sl['status']==='success'?'success':'danger' ?>"><?= $sl['status']==='success'?'Gönderildi':'Hata' ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

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
