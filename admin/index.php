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
$latestSha    = setting('update_latest_sha', '');
$installedSha = updater()->getInstalledSha();
// Güncelleme var mı? Her iki SHA de doluysa ve farklıysa
$hasUpdate = $latestSha && $installedSha && ($latestSha !== $installedSha);

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
    'tickets'       => 'Destek Talepleri',
    'announcements' => 'Duyuru Yönetimi',
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
/* ── Admin Inline Styles (CSS dosyası güncellenmeden önce fallback) ── */
:root{
  --bg:#f4f5f7;--surface:#fff;--border:#e4e6ea;--border-2:#d0d3da;
  --text:#1a1d2e;--text-2:#4a5568;--text-muted:#9aa5b4;
  --red:#ed2939;--red-hover:#c41f2e;--red-light:rgba(237,41,57,.1);--red-border:rgba(237,41,57,.2);
  --success:#16a34a;--success-bg:#f0fdf4;--success-border:#bbf7d0;
  --warning:#d97706;--warning-bg:#fffbeb;--warning-border:#fed7aa;
  --danger:#dc2626;--danger-bg:#fef2f2;--danger-border:#fecaca;
  --info:#2563eb;--info-bg:#eff6ff;--info-border:#bfdbfe;
  --radius:8px;--radius-sm:5px;--radius-lg:12px;
  --shadow:0 1px 3px rgba(0,0,0,.08);--shadow-md:0 4px 12px rgba(0,0,0,.1);
  --sidebar-bg:#1c1c2e;--sidebar-border:#2e2e45;--sidebar-hover:#2a2a40;
  --sidebar-muted:#6b6b90;--sidebar-text:#c8c8e0;--sidebar-w:240px;
  --font:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
}
/* Logo */
.sidebar-logo .logo-mark{background:linear-gradient(135deg,#ed2939,#c41f2e)}
.admin-version{font-size:10px;color:var(--sidebar-muted);padding:4px 12px 8px}
/* Tab bar */
.tab-bar{display:flex;gap:2px;background:#fff;border:1px solid #e4e6ea;border-radius:10px;padding:4px;margin-bottom:20px;width:fit-content;flex-wrap:wrap}
.tab-item{display:flex;align-items:center;gap:6px;padding:7px 18px;border-radius:7px;font-size:13px;font-weight:500;color:#4a5568;text-decoration:none;transition:all .15s;white-space:nowrap}
.tab-item:hover{background:#f4f5f7;color:#1a1d2e;text-decoration:none}
.tab-item.active{background:#ed2939;color:#fff;box-shadow:0 1px 4px rgba(237,41,57,.3)}
.tab-item.active .tab-count{background:rgba(255,255,255,.25);color:#fff;border-color:transparent}
.tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;background:#f4f5f7;border:1px solid #e4e6ea;border-radius:99px;font-size:11px;font-weight:700;padding:0 6px;color:#9aa5b4}
.tab-count.warn{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.3);color:#b45309}
/* Alert */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;border:1px solid transparent}
.alert-success{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
.alert-warning{background:#fffbeb;border-color:#fed7aa;color:#b45309}
.alert-danger,.alert-error{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
.alert-info{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
</style>
<style>
/* Admin özel stiller */
.sidebar-logo .logo-mark{background:linear-gradient(135deg,#ed2939,#c41f2e)}
.admin-version{font-size:10px;color:var(--sidebar-muted,#6b6b90);padding:4px 12px 8px}

/* Tab bar — inline fallback (sunucu CSS güncel değilse) */
:root{
  --bg:#f4f5f7;--surface:#fff;--border:#e4e6ea;--text:#1a1d2e;--text-2:#4a5568;
  --text-muted:#9aa5b4;--red:#ed2939;--red-hover:#c41f2e;--red-light:rgba(237,41,57,.1);
  --success:#16a34a;--success-bg:#f0fdf4;--success-border:#bbf7d0;
  --warning:#d97706;--warning-bg:#fffbeb;--warning-border:#fed7aa;
  --danger:#dc2626;--danger-bg:#fef2f2;--danger-border:#fecaca;
  --info:#2563eb;--info-bg:#eff6ff;--info-border:#bfdbfe;
  --radius:8px;--radius-sm:5px;--radius-lg:12px;
  --shadow:0 1px 3px rgba(0,0,0,.08);--shadow-md:0 4px 12px rgba(0,0,0,.1);
  --sidebar-bg:#1c1c2e;--sidebar-border:#2e2e45;--sidebar-muted:#6b6b90;
  --sidebar-text:#c8c8e0;--border-2:#d0d3da;--font:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  --sidebar-w:240px;
}
.tab-bar{display:flex;gap:2px;background:#fff;border:1px solid #e4e6ea;border-radius:10px;padding:4px;margin-bottom:20px;width:fit-content}
.tab-item{display:flex;align-items:center;gap:6px;padding:7px 16px;border-radius:7px;font-size:13px;font-weight:500;color:#4a5568;text-decoration:none;transition:all .15s;white-space:nowrap}
.tab-item:hover{background:#f4f5f7;color:#1a1d2e;text-decoration:none}
.tab-item.active{background:#ed2939;color:#fff;box-shadow:0 1px 4px rgba(237,41,57,.3)}
.tab-item.active .tab-count{background:rgba(255,255,255,.25);color:#fff;border-color:transparent}
.tab-count{background:#f4f5f7;border:1px solid #e4e6ea;border-radius:99px;font-size:11px;font-weight:700;padding:1px 7px;min-width:20px;text-align:center;color:#9aa5b4}
.tab-count.warn{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.3);color:#b45309}
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
    <div class="admin-version">
  v<?= h($cfg['version']) ?>
  <?php $_sha = updater()->getInstalledSha(); if ($_sha): ?>
  <span style="opacity:.5">· <?= substr($_sha,0,7) ?></span>
  <?php endif; ?>
  <?php if ($hasUpdate): ?>
  <span style="color:#f59e0b;margin-left:4px">● Güncelleme</span>
  <?php endif; ?>
</div>

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
