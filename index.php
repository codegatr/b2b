<?php
/**
 * CODEGA B2B — Bayi Portalı Ana Router
 * ?page= ile yönlendirme
 */
define('B2B_ROOT', __DIR__);
define('B2B_INSTALL_CHECK', true);

// Kurulu mu?
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install/');
    exit;
}

$cfg = require __DIR__ . '/config.php';
if (!($cfg['installed'] ?? false)) {
    header('Location: install/');
    exit;
}

define('B2B_URL', rtrim($cfg['site_url'], '/'));
define('B2B_DEBUG', $cfg['debug'] ?? false);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/parasut.php';
if (file_exists(__DIR__ . '/includes/rubikpara.php')) require __DIR__ . '/includes/rubikpara.php';
if (file_exists(__DIR__ . '/includes/sms.php')) require __DIR__ . '/includes/sms.php';

b2b_session_start();

$page = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['page'] ?? 'dashboard'));

// Public sayfalar (oturum gerektirmez)
$publicPages = ['login', 'apply', 'forgot-password', 'privacy', 'terms'];

if (!in_array($page, $publicPages)) {
    requireDealer();
}

// Logout — HTML başlamadan önce işle
if ($page === 'logout') {
    dealerLogout();
    header('Location: ?page=login&loggedout=1');
    exit;
}

// Bayi sitemi adı & ayarlar
$siteName = setting('site_name', 'B2B Bayi Portalı');
$currency = setting('currency', 'TRY');
$dealer   = isDealer() ? currentDealer() : null;

// Sepet sayısı
$cartCount = 0;
if ($dealer) {
    $cartCount = (int)dbVal("SELECT COUNT(*) FROM b2b_cart WHERE dealer_id=?", [$dealer['id']]);
}

// Okunmamış bildirim
$unread = isDealer() ? unreadNotifCount('dealer') : 0;

// Aktif duyurular
$announceCount = 0;
$announcements = [];
try {
    db()->exec("CREATE TABLE IF NOT EXISTS `b2b_announcements` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `content` text NOT NULL,
        `type` enum('bilgi','uyari','onemli') DEFAULT 'bilgi',
        `is_active` tinyint(1) DEFAULT 1,
        `starts_at` datetime DEFAULT NULL,
        `ends_at` datetime DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (isDealer()) {
        $announcements = dbRows(
            "SELECT * FROM b2b_announcements WHERE is_active=1
             AND (starts_at IS NULL OR starts_at <= NOW())
             AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY created_at DESC LIMIT 10"
        );
        $announceCount = count($announcements);
    }
} catch (Exception $e) {}

// Dealer adı
$dealerName = '';
if ($dealer) {
    $dealerName = $dealer['type'] === 'kurumsal'
        ? $dealer['company_name']
        : trim($dealer['first_name'] . ' ' . $dealer['last_name']);
}

function renderPage(string $page, array $vars = []): void {
    extract($vars);
    // 'order' → 'orders' (tek ürün detayı orders.php içinde)
    $alias = ['order' => 'orders'];
    $page  = $alias[$page] ?? $page;
    $file  = B2B_ROOT . '/pages/' . $page . '.php';
    if (file_exists($file)) {
        require $file;
    } else {
        echo '<div class="page-body"><div class="empty-state"><div class="empty-icon">🔍</div><p>Sayfa bulunamadı: <code>' . htmlspecialchars($page) . '</code></p></div></div>';
    }
}

// ── Public standalone sayfalar — HTML başlamadan önce yükle ────
// Bu sayfalar kendi <!DOCTYPE html> çıktıları var, layout dışında
if (in_array($page, ['login','apply','forgot-password','privacy','terms'])) {
    $siteName = setting('site_name', 'B2B Bayi Portalı');
    require B2B_ROOT . '/pages/' . $page . '.php';
    exit;
}

// ── Layout başlat ──────────────────────────────────────────────
$pageTitle = match($page) {
    'dashboard'    => 'Dashboard',
    'products'     => 'Ürün Kataloğu',
    'product'      => 'Ürün Detayı',
    'cart'         => 'Sepetim',
    'checkout'     => 'Sipariş Oluştur',
    'orders'       => 'Siparişlerim',
    'order'        => 'Sipariş Detayı',
    'account'      => 'Cari Hesabım',
    'payments'     => 'Ödemelerim',
    'payment-new'  => 'Ödeme Bildirimi',
    'payment-card' => 'Kart ile Ödeme',
    'profile'      => 'Profilim',
    'notifications'=> 'Bildirimler',
    'announcements'=> 'Duyurular',
    'tickets'      => 'Destek Talepleri',
    'announcements'=> 'Duyurular',
    'apply'        => 'Bayilik Başvurusu',
    'login'        => 'Giriş Yap',
    default        => ucfirst($page),
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrfToken() ?>">
<title><?= h($pageTitle) ?> — <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= B2B_URL ?>/assets/css/main.css?v=<?= $cfg['version'] ?>">
</head>
<body>

<div class="layout">
  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-mark">B2</div>
      <div>
        <div class="logo-text"><?= h($siteName) ?></div>
        <div class="logo-sub">Bayi Portalı</div>
      </div>
    </div>

    <nav class="sidebar-nav">

      <div class="nav-section">
        <div class="nav-section-label">Ana Menü</div>
        <a href="?page=dashboard" class="nav-item <?= $page==='dashboard'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M2 2h5v5H2zm7 0h5v5H9zM2 9h5v5H2zm7 0h5v5H9z"/></svg>
          Dashboard
        </a>
        <a href="?page=products" class="nav-item <?= $page==='products'||$page==='product'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M1 2.5A1.5 1.5 0 012.5 1h3A1.5 1.5 0 017 2.5v3A1.5 1.5 0 015.5 7h-3A1.5 1.5 0 011 5.5v-3zm8 0A1.5 1.5 0 0110.5 1h3A1.5 1.5 0 0115 2.5v3A1.5 1.5 0 0113.5 7h-3A1.5 1.5 0 019 5.5v-3zm-8 8A1.5 1.5 0 012.5 9h3A1.5 1.5 0 017 10.5v3A1.5 1.5 0 015.5 15h-3A1.5 1.5 0 011 13.5v-3zm8 0A1.5 1.5 0 0110.5 9h3A1.5 1.5 0 0115 10.5v3A1.5 1.5 0 0113.5 15h-3A1.5 1.5 0 019 13.5v-3z"/></svg>
          Ürün Kataloğu
        </a>
        <a href="?page=cart" class="nav-item <?= $page==='cart'||$page==='checkout'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M0 1.5A.5.5 0 01.5 1H2a.5.5 0 01.485.379L2.89 3H14.5a.5.5 0 01.491.592l-1.5 8A.5.5 0 0113 12H4a.5.5 0 01-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 01-.5-.5zM5 12a2 2 0 100 4 2 2 0 000-4zm7 0a2 2 0 100 4 2 2 0 000-4z"/></svg>
          Sepetim
          <?php if ($cartCount > 0): ?><span class="cart-badge"><?= $cartCount ?></span><?php endif; ?>
        </a>
        <?php if ($announceCount > 0): ?>
        <a href="?page=announcements" class="nav-item <?= $page==='announcements'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M14.5 3a.5.5 0 01.5.5v9a.5.5 0 01-.5.5h-13a.5.5 0 01-.5-.5v-9a.5.5 0 01.5-.5h13zm-13-1A1.5 1.5 0 000 3.5v9A1.5 1.5 0 001.5 14h13a1.5 1.5 0 001.5-1.5v-9A1.5 1.5 0 0014.5 2h-13zM3 5.5a.5.5 0 01.5-.5h9a.5.5 0 010 1h-9a.5.5 0 01-.5-.5zM3 8a.5.5 0 01.5-.5h9a.5.5 0 010 1h-9A.5.5 0 013 8zm0 2.5a.5.5 0 01.5-.5h6a.5.5 0 010 1h-6a.5.5 0 01-.5-.5z"/></svg>
          Duyurular
          <span class="cart-badge" style="background:#f59e0b"><?= $announceCount ?></span>
        </a>
        <?php endif; ?>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Siparişler</div>
        <a href="?page=orders" class="nav-item <?= $page==='orders'||$page==='order'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M5.5 4a.5.5 0 00-.5.5v1a.5.5 0 001 0v-1a.5.5 0 00-.5-.5zM3 6a1 1 0 011-1h8a1 1 0 011 1v7a1 1 0 01-1 1H4a1 1 0 01-1-1V6zM2 2.5A.5.5 0 012.5 2h11a.5.5 0 010 1h-11a.5.5 0 01-.5-.5z"/></svg>
          Siparişlerim
        </a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Finans</div>
        <a href="?page=account" class="nav-item <?= $page==='account'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M1.5 2A1.5 1.5 0 000 3.5v2h6a.5.5 0 01.5.5c0 .526.643.994 1 .994s1-.468 1-.994a.5.5 0 01.5-.5h6v-2A1.5 1.5 0 0013.5 2h-12zm13 5h-6c0 .526-.643.994-1 .994S6.5 7.526 6.5 7h-6v5A1.5 1.5 0 002 13.5h12a1.5 1.5 0 001.5-1.5V7z"/></svg>
          Cari Hesabım
        </a>
        <a href="?page=payments" class="nav-item <?= $page==='payments'||$page==='payment-new'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a7 7 0 100 14A7 7 0 008 1zm.93 9.412l-2.29-1.594A.5.5 0 016.5 8.5v-5a.5.5 0 011 0v4.761l2.06 1.43a.5.5 0 01-.63.721z"/></svg>
          Ödemeler
        </a>
      </div>

      <div class="nav-section">
        <div class="nav-section-label">Hesap</div>
        <a href="?page=notifications" class="nav-item <?= $page==='notifications'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M8 16a2 2 0 002-2H6a2 2 0 002 2zm.995-14.901a1 1 0 10-1.99 0A5.002 5.002 0 003 6c0 1.098-.5 6-2 7h14c-1.5-1-2-5.902-2-7 0-2.42-1.72-4.44-4.005-4.901z"/></svg>
          Bildirimler
          <?php if ($unread > 0): ?><span class="badge-count"><?= $unread ?></span><?php endif; ?>
        </a>

        <a href="?page=profile" class="nav-item <?= $page==='profile'?'active':'' ?>">
          <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path d="M8 8a3 3 0 100-6 3 3 0 000 6zm2-3a2 2 0 11-4 0 2 2 0 014 0zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4z"/></svg>
          Profilim
        </a>
      </div>
    </nav>

    <div class="sidebar-footer">
      <a href="?page=logout" class="nav-item" style="color:var(--danger)">
        <svg class="nav-icon" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 01-.5.5h-8a.5.5 0 01-.5-.5v-9a.5.5 0 01.5-.5h8a.5.5 0 01.5.5v2a.5.5 0 001 0v-2A1.5 1.5 0 009.5 2h-8A1.5 1.5 0 000 3.5v9A1.5 1.5 0 001.5 14h8a1.5 1.5 0 001.5-1.5v-2a.5.5 0 00-1 0v2z"/><path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 000-.708l-3-3a.5.5 0 00-.708.708L14.293 7.5H5.5a.5.5 0 000 1h8.793l-2.147 2.146a.5.5 0 00.708.708l3-3z"/></svg>
        Çıkış Yap
      </a>
    </div>
  </aside>

  <!-- ── Ana İçerik ── -->
  <div class="main-content">
    <!-- Topbar -->
    <div class="topbar">
      <button id="sidebar-toggle" class="btn btn-ghost btn-icon" style="display:none">☰</button>
      <div class="topbar-title"><?= h($pageTitle) ?></div>
      <div class="topbar-actions">
        <a href="?page=cart" class="topbar-btn">
          🛒<?php if ($cartCount > 0): ?><span class="notif-dot"></span><?php endif; ?>
        </a>
        <a href="?page=notifications" class="topbar-btn">
          🔔<?php if ($unread > 0 || $announceCount > 0): ?><span class="notif-dot"></span><?php endif; ?>
        </a>
        <div class="dropdown">
          <div class="avatar" data-dropdown="user-menu"><?= mb_substr($dealerName, 0, 1) ?></div>
          <div class="dropdown-menu" id="user-menu">
            <div style="padding:12px 14px;font-size:12px;color:var(--text-muted)"><?= h($dealerName) ?></div>
            <hr class="dropdown-divider">
            <a href="?page=profile" class="dropdown-item">👤 Profilim</a>
            <a href="?page=account" class="dropdown-item">💰 Cari Hesabım</a>
            <hr class="dropdown-divider">
            <a href="?page=logout" class="dropdown-item" style="color:var(--danger)">🚪 Çıkış</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Sayfa içeriği -->
    <?php
    // Flash mesaj
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo "<div class='alert alert-{$flash['type']}' data-auto-close style='margin:16px 24px 0'>{$flash['msg']}</div>";
    }

    echo '<div class="page-body">';
    renderPage($page, compact('dealer', 'cartCount', 'currency', 'siteName', 'announcements', 'announceCount'));
    echo '</div>';
    ?>
  </div>
</div>


<script src="<?= B2B_URL ?>/assets/js/main.js?v=<?= $cfg['version'] ?>"></script>
</body>
</html>
