<?php
/**
 * CODEGA B2B — Kurulum Sihirbazı
 * Bu dosya kurulum tamamlandıktan sonra sunucudan silinmelidir.
 */
define('B2B_INSTALL', true);
session_start();

$step = (int)($_GET['step'] ?? 1);
$errors = [];
$success = '';

// Zaten kuruluysa engelle
if (file_exists(dirname(__DIR__) . '/config.php') && $step < 5) {
    $cfg = require dirname(__DIR__) . '/config.php';
    if (!empty($cfg['installed'])) {
        die('<div style="font-family:sans-serif;padding:40px;color:#333">
            <h2>Sistem zaten kurulu!</h2>
            <p>Yeniden kurulum için <code>config.php</code> dosyasındaki <code>installed</code> değerini <code>false</code> yapın.</p>
            <a href="../admin/">Admin Paneline Git →</a>
        </div>');
    }
}

// ADIM 2 — DB bağlantı testi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $db_host = trim($_POST['db_host'] ?? '');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    $db_port = (int)($_POST['db_port'] ?? 3306);
    try {
        $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $_SESSION['install_db'] = compact('db_host','db_name','db_user','db_pass','db_port');
        header('Location: ?step=3');
        exit;
    } catch (PDOException $e) {
        $errors[] = 'Veritabanı bağlantısı başarısız: ' . htmlspecialchars($e->getMessage());
    }
}

// ADIM 3 — Tabloları oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    if (empty($_SESSION['install_db'])) { header('Location: ?step=2'); exit; }
    $d = $_SESSION['install_db'];
    try {
        $pdo = new PDO("mysql:host={$d['db_host']};port={$d['db_port']};dbname={$d['db_name']};charset=utf8mb4",
            $d['db_user'], $d['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sql = file_get_contents(__DIR__ . '/install.sql');
        // Her statement'ı ayrı çalıştır
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            if ($stmt) $pdo->exec($stmt . ';');
        }
        $success = 'Tablolar başarıyla oluşturuldu.';
        header('Location: ?step=4');
        exit;
    } catch (PDOException $e) {
        $errors[] = 'SQL hatası: ' . htmlspecialchars($e->getMessage());
    }
}

// ADIM 4 — Admin kullanıcı + config oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 4) {
    if (empty($_SESSION['install_db'])) { header('Location: ?step=2'); exit; }
    $admin_name  = trim($_POST['admin_name'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    $site_name   = trim($_POST['site_name'] ?? 'B2B Bayi Portalı');
    $site_url    = rtrim(trim($_POST['site_url'] ?? ''), '/');

    if (!$admin_name) $errors[] = 'Ad Soyad gerekli.';
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Geçersiz e-posta.';
    if (strlen($admin_pass) < 8) $errors[] = 'Şifre en az 8 karakter olmalı.';
    if ($admin_pass !== $admin_pass2) $errors[] = 'Şifreler uyuşmuyor.';

    if (empty($errors)) {
        $d = $_SESSION['install_db'];
        try {
            $pdo = new PDO("mysql:host={$d['db_host']};port={$d['db_port']};dbname={$d['db_name']};charset=utf8mb4",
                $d['db_user'], $d['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Admin kullanıcı ekle
            $hash = password_hash($admin_pass, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO b2b_admin_users (name, email, password, role) VALUES (?,?,?,'superadmin')")
                ->execute([$admin_name, $admin_email, $hash]);

            // Site adı ve email güncelle
            $pdo->prepare("UPDATE b2b_settings SET sval=? WHERE skey='site_name'")->execute([$site_name]);
            $pdo->prepare("UPDATE b2b_settings SET sval=? WHERE skey='admin_email'")->execute([$admin_email]);

            // Varsayılan fiyat listesi
            $pdo->exec("INSERT INTO b2b_price_lists (name, is_default, is_active) VALUES ('Standart Liste', 1, 1)");

            // config.php yaz
            $cfg = generateConfig($d, $site_url);
            file_put_contents(dirname(__DIR__) . '/config.php', $cfg);

            header('Location: ?step=5');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Hata: ' . htmlspecialchars($e->getMessage());
        }
    }
}

function generateConfig($d, $site_url) {
    $secret = bin2hex(random_bytes(32));
    $host = addslashes($d['db_host']);
    $name = addslashes($d['db_name']);
    $user = addslashes($d['db_user']);
    $pass = addslashes($d['db_pass']);
    $port = (int)$d['db_port'];
    $url  = addslashes($site_url);
    return <<<PHP
<?php
return [
    'installed'  => true,
    'version'    => '1.0.0',
    'app_name'   => 'CODEGA B2B',
    'site_url'   => '$url',
    'secret_key' => '$secret',
    'db' => [
        'host'    => '$host',
        'port'    => $port,
        'name'    => '$name',
        'user'    => '$user',
        'pass'    => '$pass',
        'charset' => 'utf8mb4',
    ],
    'paths' => [
        'uploads' => __DIR__ . '/uploads',
        'root'    => __DIR__,
    ],
    'debug' => false,
];
PHP;
}

// PHP gereksinimleri kontrol
function checkRequirements(): array {
    $checks = [];
    $checks[] = ['label'=>'PHP 8.3+', 'ok'=>version_compare(PHP_VERSION,'8.3.0','>='), 'val'=>PHP_VERSION];
    $checks[] = ['label'=>'PDO MySQL', 'ok'=>extension_loaded('pdo_mysql'), 'val'=>extension_loaded('pdo_mysql')?'Aktif':'Eksik'];
    $checks[] = ['label'=>'cURL', 'ok'=>extension_loaded('curl'), 'val'=>extension_loaded('curl')?'Aktif':'Eksik'];
    $checks[] = ['label'=>'zip', 'ok'=>extension_loaded('zip'), 'val'=>extension_loaded('zip')?'Aktif':'Eksik'];
    $checks[] = ['label'=>'mbstring', 'ok'=>extension_loaded('mbstring'), 'val'=>extension_loaded('mbstring')?'Aktif':'Eksik'];
    $checks[] = ['label'=>'json', 'ok'=>extension_loaded('json'), 'val'=>extension_loaded('json')?'Aktif':'Eksik'];
    $checks[] = ['label'=>'Yazma izni (root)', 'ok'=>is_writable(dirname(__DIR__)), 'val'=>is_writable(dirname(__DIR__))?'Yazılabilir':'Yazılamaz'];
    $checks[] = ['label'=>'Yazma izni (uploads)', 'ok'=>is_writable(dirname(__DIR__).'/uploads'), 'val'=>is_writable(dirname(__DIR__).'/uploads')?'Yazılabilir':'Yazılamaz'];
    return $checks;
}

$allOk = !in_array(false, array_column(checkRequirements(), 'ok'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CODEGA B2B — Kurulum</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f1117;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#1a1d27;border:1px solid #2d3148;border-radius:16px;width:100%;max-width:600px;overflow:hidden}
.header{background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:32px;text-align:center}
.header h1{font-size:24px;font-weight:700;color:#fff}
.header p{color:rgba(255,255,255,.75);font-size:14px;margin-top:6px}
.steps{display:flex;padding:20px 32px;gap:0;background:#13151f;border-bottom:1px solid #2d3148}
.step-item{flex:1;text-align:center;position:relative;font-size:12px;color:#64748b}
.step-item::after{content:'';position:absolute;top:12px;left:50%;right:-50%;height:2px;background:#2d3148;z-index:0}
.step-item:last-child::after{display:none}
.step-circle{width:24px;height:24px;border-radius:50%;border:2px solid #2d3148;background:#13151f;margin:0 auto 6px;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
.step-item.done .step-circle{background:#22c55e;border-color:#22c55e;color:#fff}
.step-item.active .step-circle{background:#6366f1;border-color:#6366f1;color:#fff}
.step-item.done,.step-item.active{color:#e2e8f0}
.body{padding:32px}
.form-group{margin-bottom:20px}
.form-group label{display:block;font-size:13px;font-weight:500;color:#94a3b8;margin-bottom:8px}
.form-group input{width:100%;background:#0f1117;border:1px solid #2d3148;border-radius:8px;padding:10px 14px;color:#e2e8f0;font-size:14px;outline:none;transition:border .2s}
.form-group input:focus{border-color:#6366f1}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 24px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;width:100%}
.btn-primary{background:#6366f1;color:#fff}
.btn-primary:hover{background:#5254cc}
.alert{padding:12px 16px;border-radius:8px;font-size:14px;margin-bottom:20px}
.alert-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac}
.check-list{list-style:none;display:flex;flex-direction:column;gap:10px}
.check-item{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#0f1117;border-radius:8px;border:1px solid #2d3148;font-size:13px}
.check-item .badge{padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600}
.badge-ok{background:rgba(34,197,94,.15);color:#86efac}
.badge-err{background:rgba(239,68,68,.15);color:#fca5a5}
.info-box{background:#13151f;border:1px solid #2d3148;border-radius:8px;padding:16px;font-size:13px;color:#94a3b8;margin-bottom:20px;line-height:1.7}
h2{font-size:18px;font-weight:600;color:#e2e8f0;margin-bottom:8px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
.success-icon{font-size:48px;text-align:center;margin-bottom:16px}
.warn{color:#fbbf24;font-size:12px;margin-top:6px}
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h1>🔧 CODEGA B2B Kurulum</h1>
    <p>Bayi Portalı kurulum sihirbazı</p>
  </div>

  <?php
  $stepLabels = ['Kontrol','Veritabanı','Tablolar','Admin','Tamamlandı'];
  echo '<div class="steps">';
  for ($i=1; $i<=5; $i++) {
    $cls = $i < $step ? 'done' : ($i === $step ? 'active' : '');
    $icon = $i < $step ? '✓' : $i;
    echo "<div class='step-item $cls'><div class='step-circle'>$icon</div>".$stepLabels[$i-1]."</div>";
  }
  echo '</div>';
  ?>

  <div class="body">
    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger"><?= $e ?></div>
    <?php endforeach; ?>

    <?php if ($step === 1): ?>
      <h2>Sistem Gereksinimleri</h2>
      <p class="sub">Kuruluma başlamadan önce sunucunuz kontrol ediliyor.</p>
      <ul class="check-list">
        <?php foreach (checkRequirements() as $c): ?>
          <li class="check-item">
            <span><?= $c['label'] ?></span>
            <span class="badge <?= $c['ok'] ? 'badge-ok' : 'badge-err' ?>"><?= $c['val'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$allOk): ?>
        <p class="warn" style="margin-top:16px">⚠ Bazı gereksinimler karşılanmıyor. Lütfen hosting sağlayıcınızla iletişime geçin.</p>
      <?php else: ?>
        <p style="color:#86efac;font-size:13px;margin:16px 0">✓ Tüm gereksinimler karşılanıyor.</p>
        <a href="?step=2"><button class="btn btn-primary" style="margin-top:8px">Devam Et →</button></a>
      <?php endif; ?>

    <?php elseif ($step === 2): ?>
      <h2>Veritabanı Bağlantısı</h2>
      <p class="sub">MySQL/MariaDB bağlantı bilgilerinizi girin.</p>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <label>Sunucu (Host)</label>
            <input name="db_host" value="<?= htmlspecialchars($_SESSION['install_db']['db_host'] ?? 'localhost') ?>" required>
          </div>
          <div class="form-group">
            <label>Port</label>
            <input name="db_port" type="number" value="<?= (int)($_SESSION['install_db']['db_port'] ?? 3306) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Veritabanı Adı</label>
          <input name="db_name" value="<?= htmlspecialchars($_SESSION['install_db']['db_name'] ?? '') ?>" required placeholder="b2b_portal">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Kullanıcı Adı</label>
            <input name="db_user" value="<?= htmlspecialchars($_SESSION['install_db']['db_user'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label>Şifre</label>
            <input type="password" name="db_pass">
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Bağlantıyı Test Et →</button>
      </form>

    <?php elseif ($step === 3): ?>
      <h2>Tablolar Oluşturuluyor</h2>
      <p class="sub">Veritabanı şeması uygulanacak. 20+ tablo oluşturulacak.</p>
      <div class="info-box">
        Tüm tablolar <strong>b2b_</strong> ön ekiyle oluşturulacaktır. Mevcut veriler etkilenmez.
      </div>
      <form method="post">
        <button type="submit" class="btn btn-primary">Tabloları Oluştur →</button>
      </form>

    <?php elseif ($step === 4): ?>
      <h2>Admin Hesabı</h2>
      <p class="sub">Süper yönetici hesabı ve site ayarlarını belirleyin.</p>
      <form method="post">
        <div class="form-group">
          <label>Site Adı</label>
          <input name="site_name" value="B2B Bayi Portalı" required>
        </div>
        <div class="form-group">
          <label>Site URL (https://...)</label>
          <input name="site_url" type="url" placeholder="https://b2b.sirket.com" required>
        </div>
        <div class="form-group">
          <label>Admin Ad Soyad</label>
          <input name="admin_name" required>
        </div>
        <div class="form-group">
          <label>Admin E-posta</label>
          <input type="email" name="admin_email" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Şifre (min. 8 karakter)</label>
            <input type="password" name="admin_pass" minlength="8" required>
          </div>
          <div class="form-group">
            <label>Şifre Tekrar</label>
            <input type="password" name="admin_pass2" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Kurulumu Tamamla →</button>
      </form>

    <?php elseif ($step === 5): ?>
      <div class="success-icon">🎉</div>
      <h2 style="text-align:center">Kurulum Tamamlandı!</h2>
      <p class="sub" style="text-align:center">CODEGA B2B başarıyla kuruldu.</p>
      <div class="alert alert-success" style="margin-top:16px">
        <strong>Güvenlik Uyarısı:</strong> Lütfen <code>install/</code> klasörünü sunucudan silin veya .htaccess ile erişimi engelleyin.
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px">
        <a href="../"><button class="btn btn-primary">Bayi Portalına Git →</button></a>
        <a href="../admin/"><button class="btn" style="background:#2d3148;color:#e2e8f0">Admin Paneline Git →</button></a>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
