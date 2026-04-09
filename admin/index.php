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
require B2B_ROOT . '/includes/migrations.php';
if (file_exists(B2B_ROOT . '/includes/sms.php')) require B2B_ROOT . '/includes/sms.php';
if (file_exists(B2B_ROOT . '/includes/rubikpara.php')) require B2B_ROOT . '/includes/rubikpara.php';

b2b_session_start();

$page = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['page'] ?? 'dashboard'));

// Public: sadece login — artık tek login noktası bayi portalı
if ($page === 'login') {
    header('Location: ' . B2B_URL . '/?page=login');
    exit;
}
requireAdmin();

// Logout — HTML başlamadan önce işle
if ($page === 'logout') {
    adminLogout();
    header('Location: ' . B2B_URL . '/?page=login&loggedout=1');
    exit;
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
@keyframes pulse-red{0%,100%{box-shadow:0 0 0 0 rgba(237,41,57,.4)}50%{box-shadow:0 0 0 6px rgba(237,41,57,0)}}
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
      <?php
        // Bekleyen sayılar - tek sorguda
        $pendingOrders  = (int)dbVal("SELECT COUNT(*) FROM b2b_orders WHERE status='bekliyor'");
        $pendingPay     = (int)dbVal("SELECT COUNT(*) FROM b2b_payments WHERE status='bekliyor'");
        $pendingApps    = (int)dbVal("SELECT COUNT(*) FROM b2b_applications WHERE status='bekliyor'");
        $lowStock       = (int)dbVal("SELECT COUNT(*) FROM b2b_products WHERE stock<=stock_critical AND is_active=1");
      ?>

      <div class="nav-section">
        <div class="nav-section-label">Genel</div>
        <a href="?page=dashboard" class="nav-item <?= $page==='dashboard'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          Dashboard
        </a>
        <a href="?page=reports" class="nav-item <?= $page==='reports'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          Raporlar
        </a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Satış</div>
        <a href="?page=orders" class="nav-item <?= $page==='orders'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
          Siparişler
          <?php if ($pendingOrders): ?><span class="nav-badge"><?= $pendingOrders ?></span><?php endif; ?>
        </a>
        <a href="?page=payments" class="nav-item <?= $page==='payments'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          Tahsilat
          <?php if ($pendingPay): ?><span class="nav-badge"><?= $pendingPay ?></span><?php endif; ?>
        </a>
        <a href="?page=ledger" class="nav-item <?= $page==='ledger'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
          Cari Hesap
        </a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Bayiler</div>
        <a href="?page=dealers" class="nav-item <?= $page==='dealers'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Bayiler
        </a>
        <a href="?page=applications" class="nav-item <?= $page==='applications'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Başvurular
          <?php if ($pendingApps): ?><span class="nav-badge"><?= $pendingApps ?></span><?php endif; ?>
        </a>
        <a href="?page=price-lists" class="nav-item <?= str_starts_with($page,'price')?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          Fiyat Listeleri
        </a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Ürünler</div>
        <a href="?page=products" class="nav-item <?= $page==='products'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
          Ürünler
        </a>
        <a href="?page=categories" class="nav-item <?= $page==='categories'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
          Kategoriler
        </a>
        <a href="?page=stock" class="nav-item <?= $page==='stock'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
          Stok
          <?php if ($lowStock): ?><span class="nav-badge warn"><?= $lowStock ?></span><?php endif; ?>
        </a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Sistem</div>
        <a href="?page=tickets" class="nav-item <?= $page==='tickets'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
          Destek Talepleri
        </a>
        <a href="?page=announcements" class="nav-item <?= $page==='announcements'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Duyurular
        </a>
        <a href="?page=parasut" class="nav-item <?= $page==='parasut'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
          Paraşüt
        </a>
        <a href="?page=update" class="nav-item <?= $page==='update'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
          Güncelleme<?php if ($hasUpdate): ?> <span class="nav-badge warn">!</span><?php endif; ?>
        </a>
        <a href="?page=settings" class="nav-item <?= $page==='settings'?'active':'' ?>">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          Ayarlar
        </a>
      </div>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="sidebar-avatar"><?= mb_substr($admin['name']??'A',0,1) ?></div>
        <div class="sidebar-user-info">
          <div class="sidebar-user-name"><?= h($admin['name']??'') ?></div>
          <div class="sidebar-user-role"><?= h($admin['role']??'') ?></div>
        </div>
        <a href="?page=logout" title="Çıkış"
           onclick="return confirm('Çıkış yapmak istediğinize emin misiniz?')"
           style="color:var(--sidebar-muted);margin-left:auto;padding:4px;display:flex">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <div class="main-content">
    <div class="topbar">
      <div class="topbar-title"><?= h($pageTitle) ?></div>
      <div class="topbar-actions" style="gap:6px">

        <?php if ($hasUpdate): ?>
        <a href="?page=update"
           style="display:flex;align-items:center;gap:5px;padding:5px 12px;background:#ed2939;color:#fff;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;animation:pulse-red 1.5s infinite">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
          Güncelleme Var
        </a>
        <?php endif; ?>

        <a href="<?= B2B_URL ?>" target="_blank" title="Bayi Portalı"
           style="display:flex;align-items:center;gap:5px;padding:5px 10px;background:var(--bg);border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-2);text-decoration:none">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          Bayi Portalı
        </a>

        <!-- Bildirimler -->
        <?php if ($unread > 0): ?>
        <a href="?page=notifications" title="Bildirimler"
           style="position:relative;display:flex;align-items:center;padding:5px 10px;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text-2);text-decoration:none">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          <span style="position:absolute;top:3px;right:3px;width:8px;height:8px;background:#ed2939;border-radius:50%;border:1px solid #fff"></span>
        </a>
        <?php endif; ?>

        <!-- Avatar + Dropdown -->
        <div class="dropdown">
          <div class="avatar" data-dropdown="admin-menu" style="cursor:pointer"><?= mb_substr($admin['name']??'A', 0, 1) ?></div>
          <div class="dropdown-menu" id="admin-menu">
            <div style="padding:12px 14px;font-size:13px;font-weight:600;color:var(--text)"><?= h($admin['name']??'') ?></div>
            <div style="padding:0 14px 10px;font-size:11px;color:var(--text-muted);text-transform:capitalize"><?= h($admin['role']??'') ?></div>
            <hr class="dropdown-divider">
            <a href="?page=admins"   class="dropdown-item">👥 Admin Kullanıcılar</a>
            <a href="?page=settings" class="dropdown-item">⚙️ Sistem Ayarları</a>
            <a href="?page=update"   class="dropdown-item">🚀 Güncelleme Merkezi</a>
            <hr class="dropdown-divider">
            <a href="?page=logout" class="dropdown-item" style="color:var(--danger)"
               onclick="return confirm('Çıkış yapmak istediğinize emin misiniz?')">
              🚪 Çıkış Yap
            </a>
          </div>
        </div>
      </div>
    </div>

    <?php
    if (!empty($_SESSION['flash_admin'])) {
        $flash = $_SESSION['flash_admin'];
        unset($_SESSION['flash_admin']);
        echo "<div class='alert alert-{$flash['type']}' data-auto-close style='margin:16px 24px 0'>{$flash['msg']}</div>";
    }

    renderAdminPage($page, compact('admin', 'cfg', 'hasUpdate'));
    ?>
  </div>
</div>

<script src="<?= B2B_URL ?>/assets/js/main.js?v=<?= $cfg['version'] ?>"></script>
</body>
</html>
