<?php
/**
 * CODEGA B2B — Admin Paneli Router
 */
define('B2B_ROOT', dirname(__DIR__));

if (!file_exists(B2B_ROOT . '/config.php')) {
    header('Location: ../install/');
    exit;
}
$cfg = require B2B_ROOT . '/config.php';
if (!($cfg['installed'] ?? false)) {
    header('Location: ../install/');
    exit;
}

define('B2B_URL', rtrim($cfg['site_url'], '/'));
define('B2B_DEBUG', $cfg['debug'] ?? false);

require B2B_ROOT . '/includes/db.php';
require B2B_ROOT . '/includes/auth.php';
require B2B_ROOT . '/includes/functions.php';
require B2B_ROOT . '/includes/parasut.php';
require B2B_ROOT . '/includes/updater.php';
if (file_exists(B2B_ROOT . '/includes/rubikpara.php')) require B2B_ROOT . '/includes/rubikpara.php';

b2b_session_start();

$page = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['page'] ?? 'dashboard'));

// Public: sadece login
if ($page !== 'login') {
    requireAdmin();
}

$siteName  = setting('site_name', 'B2B Bayi Portalı');
$admin     = isAdmin() ? currentAdmin() : null;
$unread    = isAdmin() ? unreadNotifCount('admin') : 0;

// Güncelleme kontrolü (saatte bir)
// Guncelleme kontrolü (saatte 1)
$hasUpdate  = false;
$lastCheck  = (int)setting('update_last_check', '0');
if (isAdmin() && (time() - $lastCheck) > 3600) {
    try {
        $commit = updater()->getLatestCommit();
        if ($commit) {
            settingSave('update_last_check',  (string)time());
            settingSave('update_latest_sha',  $commit['sha']);
        }
    } catch (Exception) {}
}
$latestSha = setting('update_latest_sha', '');
$hasUpdate = $latestSha && $latestSha !== updater()->getInstalledSha();

$pageTitle = match($page) {
    'dashboard'    => 'Dashboard',
    'dealers'      => 'Bayi Yönetimi',
    'dealer'       => 'Bayi Detayı',
    'products'     => 'Ürün Yönetimi',
    'product'      => 'Ürün Detayı',
    'categories'   => 'Kategoriler',
    'price-lists'  => 'Fiyat Listeleri',
    'price-list'   => 'Fiyat Listesi',
    'orders'       => 'Sipariş Yönetimi',
    'order'        => 'Sipariş Detayı',
    'payments'     => 'Tahsilat Yönetimi',
    'ledger'       => 'Cari Hesap',
    'applications' => 'Bayilik Başvuruları',
    'stock'        => 'Stok Yönetimi',
    'parasut'      => 'Paraşüt Entegrasyonu',
    'update'       => 'Güncelleme Merkezi',
    'settings'     => 'Sistem Ayarları',
    'admins'       => 'Admin Kullanıcılar',
    'reports'      => 'Raporlar',
    'login'        => 'Admin Girişi',
    default        => ucfirst($page),
};

function renderAdminPage(string $page, array $vars = []): void {
    extract($vars);
    $file = B2B_ROOT . '/admin/pages/' . $page . '.php';
    if (file_exists($file)) {
        require $file;
    } else {
        echo '<div class="page-body"><div class="empty-state"><div class="empty-icon">🔍</div><p>Sayfa bulunamadı: <code>' . htmlspecialchars($page) . '</code></p></div></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrfToken() ?>">
<title><?= h($pageTitle) ?> — Admin | <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= B2B_URL ?>/assets/css/main.css?v=<?= $cfg['version'] ?>">
<style>
/* Admin özel stiller */
.sidebar-logo .logo-mark{background:linear-gradient(135deg,#ed2939,#c41f2e)}
.admin-version{font-size:10px;color:var(--text-muted);padding:4px 12px 8px}
</style>
</head>
<body>

<?php if ($page === 'login'): ?>
  <?php
  // Admin login işlemi
  $loginError = '';
  if (isPost()) {
      csrfCheck();
      if (adminLogin(trim($_POST['email']??''), $_POST['password']??'')) {
          header('Location: ?page=dashboard');
          exit;
      }
      $loginError = 'E-posta veya şifre hatalı.';
  }
  ?>
  <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px">
    <div style="width:100%;max-width:360px">
      <div style="text-align:center;margin-bottom:32px">
        <div style="width:48px;height:48px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:12px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#fff">B2</div>
        <h1 style="font-size:20px;font-weight:700;color:#1a1d2e"><?= h($siteName) ?></h1>
        <p style="color:#4a5568;font-size:13px;margin-top:4px">Admin Paneli</p>
      </div>
      <div class="card">
        <div class="card-body">
          <?php if ($loginError): ?><div class="alert alert-danger"><?= h($loginError) ?></div><?php endif; ?>
          <form method="post">
            <?= csrfField() ?>
            <div class="form-group">
              <label class="form-label">E-posta</label>
              <input type="email" name="email" class="form-control" autofocus required>
            </div>
            <div class="form-group">
              <label class="form-label">Şifre</label>
              <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Giriş Yap</button>
          </form>
        </div>
      </div>
      <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--text-muted)">
        <a href="<?= B2B_URL ?>/">← Bayi Portalına Git</a>
      </p>
    </div>
  </div>

<?php else: ?>

<div class="layout">
  <!-- ── Admin Sidebar ── -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-mark">B2</div>
      <div>
        <div class="logo-text"><?= h($siteName) ?></div>
        <div class="logo-sub">Admin Paneli</div>
      </div>
    </div>
    <div class="admin-version">v<?= h($cfg['version']) ?><?php if ($hasUpdate): ?> <span style="color:var(--warning)">● Güncelleme</span><?php endif; ?></div>

    <nav class="sidebar-nav">
      <div class="nav-section">
        <div class="nav-section-label">Genel</div>
        <a href="?page=dashboard" class="nav-item <?= $page==='dashboard'?'active':'' ?>">📊 Dashboard</a>
        <a href="?page=reports" class="nav-item <?= $page==='reports'?'active':'' ?>">📈 Raporlar</a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Satış</div>
        <a href="?page=orders" class="nav-item <?= $page==='orders'||$page==='order'?'active':'' ?>">📦 Siparişler</a>
        <a href="?page=payments" class="nav-item <?= $page==='payments'?'active':'' ?>">💳 Tahsilat</a>
        <a href="?page=ledger" class="nav-item <?= $page==='ledger'?'active':'' ?>">📒 Cari Hesap</a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Bayiler</div>
        <a href="?page=dealers" class="nav-item <?= $page==='dealers'||$page==='dealer'?'active':'' ?>">🏪 Bayiler</a>
        <a href="?page=applications" class="nav-item <?= $page==='applications'?'active':'' ?>">
          📋 Başvurular
          <?php $pendingApps = (int)dbVal("SELECT COUNT(*) FROM b2b_applications WHERE status='bekliyor'");
          if ($pendingApps): ?><span class="badge-count"><?= $pendingApps ?></span><?php endif; ?>
        </a>
        <a href="?page=price-lists" class="nav-item <?= str_starts_with($page,'price')?'active':'' ?>">💰 Fiyat Listeleri</a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Ürünler</div>
        <a href="?page=products" class="nav-item <?= $page==='products'||$page==='product'?'active':'' ?>">🛍 Ürünler</a>
        <a href="?page=categories" class="nav-item <?= $page==='categories'?'active':'' ?>">📁 Kategoriler</a>
        <a href="?page=stock" class="nav-item <?= $page==='stock'?'active':'' ?>">📊 Stok</a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Sistem</div>
        <a href="?page=parasut" class="nav-item <?= $page==='parasut'?'active':'' ?>">🔗 Paraşüt</a>
        <a href="?page=update" class="nav-item <?= $page==='update'?'active':'' ?>">
          🔄 Güncelleme
          <?php if ($hasUpdate): ?><span class="notif-dot" style="position:relative;display:inline-block;width:7px;height:7px;background:var(--warning);border-radius:50%;margin-left:4px"></span><?php endif; ?>
        </a>
        <a href="?page=admins" class="nav-item <?= $page==='admins'?'active':'' ?>">👥 Admin Kullanıcılar</a>
        <a href="?page=settings" class="nav-item <?= $page==='settings'?'active':'' ?>">⚙️ Ayarlar</a>
      </div>
    </nav>

    <div class="sidebar-footer">
      <a href="<?= B2B_URL ?>/" target="_blank" class="nav-item">🌐 Bayi Portalı</a>
      <a href="?page=logout" class="nav-item" style="color:var(--danger)">🚪 Çıkış</a>
    </div>
  </aside>

  <div class="main-content">
    <div class="topbar">
      <div class="topbar-title"><?= h($pageTitle) ?></div>
      <div class="topbar-actions">
        <?php if ($hasUpdate): ?>
          <a href="?page=update" class="btn btn-primary btn-sm">⬆ Güncelleme Mevcut</a>
        <?php endif; ?>
        <div class="dropdown">
          <div class="avatar" data-dropdown="admin-menu"><?= mb_substr($admin['name']??'A', 0, 1) ?></div>
          <div class="dropdown-menu" id="admin-menu">
            <div style="padding:12px 14px;font-size:12px;color:var(--text-muted)"><?= h($admin['name']??'') ?></div>
            <div style="padding:0 14px 8px;font-size:11px;color:var(--text-muted)"><?= h($admin['role']??'') ?></div>
            <hr class="dropdown-divider">
            <a href="?page=settings" class="dropdown-item">⚙️ Ayarlar</a>
            <hr class="dropdown-divider">
            <a href="?page=logout" class="dropdown-item" style="color:var(--danger)">🚪 Çıkış</a>
          </div>
        </div>
      </div>
    </div>

    <?php
    if ($page === 'logout') {
        adminLogout();
        header('Location: ?page=login');
        exit;
    }

    if (!empty($_SESSION['flash_admin'])) {
        $flash = $_SESSION['flash_admin'];
        unset($_SESSION['flash_admin']);
        echo "<div class='alert alert-{$flash['type']}' data-auto-close style='margin:16px 24px 0'>{$flash['msg']}</div>";
    }

    renderAdminPage($page, compact('admin', 'cfg', 'hasUpdate'));
    ?>
  </div>
</div>

<?php endif; ?>

<script src="<?= B2B_URL ?>/assets/js/main.js?v=<?= $cfg['version'] ?>"></script>
</body>
</html>
